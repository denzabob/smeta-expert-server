# parser/queue_worker.py

"""
ЭТАП 3: Режим обработки очереди URL.

Воркер получает пачки URL из Laravel API, парсит их и отправляет результаты обратно.
"""

import sys
import time
import uuid
import logging
import threading
import queue
from concurrent.futures import ThreadPoolExecutor, as_completed
from urllib.parse import urlparse
from typing import List, Dict, Any, Optional
from datetime import datetime
from dataclasses import dataclass
from pathlib import Path

import requests

# Поддержка запуска как модуля и как скрипта
try:
    from .base_adapter import MaterialData, SupplierAdapter
    from .config import config_manager
    from .core import ParserCore, CallbackHandler
except ImportError:
    sys.path.insert(0, str(Path(__file__).parent.parent))
    from parser.base_adapter import MaterialData, SupplierAdapter
    from parser.config import config_manager
    from parser.core import ParserCore, CallbackHandler


logger = logging.getLogger(__name__)


# Коды ошибок (совпадают с Laravel)
class ErrorCodes:
    NAV_TIMEOUT = 'NAV_TIMEOUT'
    SELECTOR_NOT_FOUND = 'SELECTOR_NOT_FOUND'
    PRICE_PARSE_FAILED = 'PRICE_PARSE_FAILED'
    HTTP_403 = 'HTTP_403'
    HTTP_404 = 'HTTP_404'
    NETWORK_ERROR = 'NETWORK_ERROR'
    WORKER_TIMEOUT = 'WORKER_TIMEOUT'
    UNKNOWN = 'UNKNOWN'


@dataclass
class UrlTask:
    """Задача на парсинг одного URL."""
    supplier_url_id: int
    url: str
    supplier_name: str
    material_type: Optional[str]


@dataclass
class UrlResult:
    """Результат парсинга одного URL."""
    supplier_url_id: int
    status: str  # 'done', 'failed', 'blocked'
    error_code: Optional[str] = None
    error_message: Optional[str] = None
    parsed_at: Optional[datetime] = None
    material_data: Optional[MaterialData] = None


class QueueWorker:
    """
    Воркер для обработки очереди URL.
    
    Цикл работы:
    1. Claim пачку URL из Laravel
    2. Парсинг каждого URL
    3. Сохранение материалов (batch)
    4. Report результатов в Laravel
    5. Повторить
    """
    
    def __init__(
        self,
        supplier_name: str,
        api_base_url: str = "http://host.docker.internal:8000/api",
        api_callback: Optional[str] = None,
        api_token: Optional[str] = None,
        session_id: Optional[int] = None,
        batch_size: int = 20,
        material_type: Optional[str] = None,
        reparse_days: int = 7,
        max_batches: Optional[int] = None,
        concurrency: int = 3,
        min_request_interval: float = 0.5,
    ):
        self.supplier_name = supplier_name
        # Если api_callback передан, используем его host как источник base_url
        if api_callback and api_base_url == "http://host.docker.internal:8000/api":
            try:
                from urllib.parse import urlparse
                parsed = urlparse(api_callback)
                self.api_base_url = f"{parsed.scheme}://{parsed.netloc}/api"
            except Exception:
                self.api_base_url = api_base_url.rstrip('/')
        else:
            self.api_base_url = api_base_url.rstrip('/')
        self.api_callback = api_callback
        self.api_token = api_token
        self.session_id = session_id
        self.batch_size = batch_size
        self.material_type = material_type
        self.reparse_days = reparse_days
        self.max_batches = max_batches
        self.current_batch_total: Optional[int] = None
        self.concurrency = max(1, int(concurrency))
        self.min_request_interval = max(0.0, float(min_request_interval))
        
        # Уникальный ID воркера
        self.worker_id = f"{supplier_name}_{uuid.uuid4().hex[:8]}"
        
        # Статистика
        self.stats = {
            'total_processed': 0,
            'successful': 0,
            'failed': 0,
            'blocked': 0,
            'batches_processed': 0,
        }
        
        # Адаптер и ядро
        self.adapter: Optional[SupplierAdapter] = None
        self.core: Optional[ParserCore] = None
        self.callback_handler: Optional[CallbackHandler] = None

        # Batch-level progress
        self.batch_processed = 0

        # Concurrency helpers
        self.pages = []
        self._page_pool: Optional[queue.Queue] = None
        self._stats_lock = threading.Lock()

        # Rate limiting (per domain)
        self._rate_lock = threading.Lock()
        self._last_request_at: Dict[str, float] = {}
        
        # Флаг остановки
        self.should_stop = False
    
    def setup(self):
        """Инициализация воркера."""
        print(f"[QUEUE] Инициализация воркера {self.worker_id}", file=sys.stderr, flush=True)
        
        # Создаём ядро парсера
        self.core = ParserCore(
            api_url=f"{self.api_base_url}/parser",
            api_callback=self.api_callback,
            api_token=self.api_token,
            session_id=self.session_id,
        )
        
        # Создаём адаптер
        self.adapter = self.core.get_adapter(self.supplier_name)
        self.adapter.setup()

        # Подготовить страницы для параллельного парсинга
        self.pages = []
        for _ in range(self.concurrency):
            page = self.adapter.create_page()
            if page:
                self.pages.append(page)

        if not self.pages:
            # Fallback на единственную страницу адаптера
            self.pages = [getattr(self.adapter, '_page', None)]
            self.concurrency = 1

        self.pages = [p for p in self.pages if p]
        if not self.pages:
            raise RuntimeError("No Playwright pages available for parsing")

        self._page_pool = queue.Queue()
        for page in self.pages:
            self._page_pool.put(page)
        
        # Callback handler (если есть)
        if self.api_callback and self.api_token and self.session_id:
            self.callback_handler = CallbackHandler(
                url=self.api_callback,
                token=self.api_token,
                session_id=self.session_id,
            )
        
        print(f"[QUEUE] Воркер {self.worker_id} готов", file=sys.stderr, flush=True)
    
    def teardown(self):
        """Освобождение ресурсов."""
        if self.pages and self.adapter:
            base_page = getattr(self.adapter, '_page', None)
            for page in self.pages:
                if page and page is not base_page:
                    try:
                        page.close()
                    except Exception:
                        pass
        if self.adapter:
            self.adapter.teardown()
        print(f"[QUEUE] Воркер {self.worker_id} остановлен", file=sys.stderr, flush=True)
    
    def run(self):
        """Основной цикл обработки очереди."""
        print(f"[QUEUE] Запуск обработки очереди для {self.supplier_name}", file=sys.stderr, flush=True)
        
        try:
            self.setup()
            
            empty_batches = 0
            max_empty_batches = 3  # Сколько пустых пачек до выхода
            
            while not self.should_stop:
                # 1. Claim пачку URL
                tasks = self.claim_batch()
                
                if not tasks:
                    empty_batches += 1
                    print(f"[QUEUE] Пустая пачка #{empty_batches}", file=sys.stderr, flush=True)
                    
                    if empty_batches >= max_empty_batches:
                        print(f"[QUEUE] {max_empty_batches} пустых пачек подряд, завершаем", file=sys.stderr, flush=True)
                        break
                    
                    time.sleep(5)  # Подождать перед следующей попыткой
                    continue
                
                empty_batches = 0
                print(f"[QUEUE] Получено {len(tasks)} URL для парсинга", file=sys.stderr, flush=True)
                
                # Зафиксировать total для текущего батча (для chunk-режима)
                if self.max_batches:
                    self.current_batch_total = len(tasks)
                    self.batch_processed = 0
                    if self.callback_handler:
                        self.callback_handler.send_total_urls(self.current_batch_total)

                # 2. Парсинг пачки
                results = self.process_batch(tasks)
                
                # 3. Сохранение материалов (batch)
                self.save_materials_batch(results)
                
                # 4. Report результатов
                self.report_results(results)
                
                # 5. Обновить статистику
                self.stats['batches_processed'] += 1
                
                # 6. Callback прогресса
                self.send_progress()

                # Ограничение количества батчей (chunked режим)
                if self.max_batches and self.stats['batches_processed'] >= self.max_batches:
                    break
            
            # Финальный callback
            self.send_finish('completed')
            
        except Exception as e:
            print(f"[QUEUE] Критическая ошибка: {e}", file=sys.stderr, flush=True)
            import traceback
            traceback.print_exc(file=sys.stderr)
            self.send_finish('failed')
            raise
        finally:
            self.teardown()
        
        return self.stats

    def _rate_limit(self, url: str) -> None:
        """Ограничить RPS на домен."""
        if self.min_request_interval <= 0:
            return

        domain = urlparse(url).netloc or 'default'
        now = time.perf_counter()

        with self._rate_lock:
            last = self._last_request_at.get(domain, 0.0)
            wait_for = self.min_request_interval - (now - last)
            if wait_for > 0:
                time.sleep(wait_for)
            self._last_request_at[domain] = time.perf_counter()

    def _update_stats(self, result: UrlResult) -> None:
        with self._stats_lock:
            self.stats['total_processed'] += 1
            if self.current_batch_total is not None:
                self.batch_processed += 1

            if result.status == 'done':
                self.stats['successful'] += 1
            elif result.status == 'failed':
                self.stats['failed'] += 1
            elif result.status == 'blocked':
                self.stats['blocked'] += 1
    
    def claim_batch(self) -> List[UrlTask]:
        """Запросить пачку URL из Laravel."""
        try:
            response = requests.post(
                f"{self.api_base_url}/parser/urls/claim",
                json={
                    'supplier_name': self.supplier_name,
                    'material_type': self.material_type,
                    'batch_size': self.batch_size,
                    'worker_id': self.worker_id,
                    'reparse_days': self.reparse_days,
                },
                headers=self._get_headers(),
                timeout=30,
            )
            
            if response.status_code != 200:
                print(f"[QUEUE] Ошибка claim: {response.status_code} - {response.text[:200]}", file=sys.stderr, flush=True)
                return []
            
            data = response.json()
            
            if not data.get('success') or not data.get('urls'):
                return []
            
            return [
                UrlTask(
                    supplier_url_id=item['supplier_url_id'],
                    url=item['url'],
                    supplier_name=item['supplier_name'],
                    material_type=item.get('material_type'),
                )
                for item in data['urls']
            ]
            
        except Exception as e:
            print(f"[QUEUE] Ошибка при claim: {e}", file=sys.stderr, flush=True)
            return []
    
    def process_batch(self, tasks: List[UrlTask]) -> List[UrlResult]:
        """Парсинг пачки URL."""
        results = []

        if self.concurrency <= 1:
            for task in tasks:
                self._rate_limit(task.url)
                result = self.process_single_url(task)
                results.append(result)
                self._update_stats(result)
            return results

        if not self._page_pool:
            self._page_pool = queue.Queue()
            for page in self.pages:
                self._page_pool.put(page)

        def worker(task: UrlTask) -> UrlResult:
            page = None
            try:
                page = self._page_pool.get()
                self._rate_limit(task.url)
                return self.process_single_url(task, page=page)
            finally:
                if page is not None:
                    self._page_pool.put(page)

        with ThreadPoolExecutor(max_workers=self.concurrency) as executor:
            future_map = {executor.submit(worker, task): task for task in tasks}
            for future in as_completed(future_map):
                result = future.result()
                results.append(result)
                self._update_stats(result)

        return results
    
    def process_single_url(self, task: UrlTask, page=None) -> UrlResult:
        """Парсинг одного URL."""
        print(f"[QUEUE] Парсинг: {task.url}", file=sys.stderr, flush=True)
        
        try:
            # Парсим страницу
            if page is None:
                material = self.adapter.parse_product_page(task.url)
            else:
                material = self.adapter.parse_product_page(task.url, page=page)
            
            # Проверяем результат
            if material is None:
                return UrlResult(
                    supplier_url_id=task.supplier_url_id,
                    status='failed',
                    error_code=ErrorCodes.UNKNOWN,
                    error_message='parse_product_page returned None',
                    parsed_at=datetime.utcnow(),
                )
            
            # Проверяем цену
            if not material.price_parsed_successfully:
                return UrlResult(
                    supplier_url_id=task.supplier_url_id,
                    status='failed',
                    error_code=ErrorCodes.PRICE_PARSE_FAILED,
                    error_message='Price not parsed',
                    parsed_at=datetime.utcnow(),
                    material_data=material,  # Всё равно сохраняем (без цены)
                )
            
            print(f"[QUEUE] ✓ {material.article}: {material.price_per_unit} ₽", file=sys.stderr, flush=True)
            
            return UrlResult(
                supplier_url_id=task.supplier_url_id,
                status='done',
                parsed_at=datetime.utcnow(),
                material_data=material,
            )
            
        except Exception as e:
            error_code, error_message = self._classify_error(e)
            
            print(f"[QUEUE] ✗ Ошибка: {error_code} - {error_message}", file=sys.stderr, flush=True)
            
            # Определяем, блокировать ли URL
            status = 'blocked' if error_code in (ErrorCodes.HTTP_403, ErrorCodes.HTTP_404) else 'failed'
            
            return UrlResult(
                supplier_url_id=task.supplier_url_id,
                status=status,
                error_code=error_code,
                error_message=str(e)[:2000],
                parsed_at=datetime.utcnow(),
            )
    
    def save_materials_batch(self, results: List[UrlResult]):
        """Сохранение материалов пачкой."""
        # Собираем успешные материалы
        materials_to_save = []
        for result in results:
            if result.material_data:
                materials_to_save.append(result.material_data.to_dict())
        
        if not materials_to_save:
            return
        
        print(f"[QUEUE] Сохранение {len(materials_to_save)} материалов...", file=sys.stderr, flush=True)
        
        try:
            response = requests.post(
                f"{self.api_base_url}/parser/materials/batch",
                json={'materials': materials_to_save},
                headers={'Content-Type': 'application/json'},
                timeout=60,
            )
            
            if response.status_code == 200:
                data = response.json()
                summary = data.get('summary', {})
                print(f"[QUEUE] Сохранено: {summary.get('success', 0)}, ошибок: {summary.get('failed', 0)}", file=sys.stderr, flush=True)
            else:
                print(f"[QUEUE] Ошибка сохранения batch: {response.status_code}", file=sys.stderr, flush=True)
                
        except Exception as e:
            print(f"[QUEUE] Ошибка при сохранении batch: {e}", file=sys.stderr, flush=True)
    
    def report_results(self, results: List[UrlResult]):
        """Отправка результатов в Laravel."""
        report_data = []
        
        for result in results:
            report_data.append({
                'supplier_url_id': result.supplier_url_id,
                'status': result.status,
                'error_code': result.error_code,
                'error_message': result.error_message,
                'parsed_at': result.parsed_at.isoformat() if result.parsed_at else None,
            })
        
        try:
            response = requests.post(
                f"{self.api_base_url}/parser/urls/report",
                json={'results': report_data},
                headers=self._get_headers(),
                timeout=30,
            )
            
            if response.status_code != 200:
                print(f"[QUEUE] Ошибка report: {response.status_code}", file=sys.stderr, flush=True)
                
        except Exception as e:
            print(f"[QUEUE] Ошибка при report: {e}", file=sys.stderr, flush=True)
    
    def send_progress(self):
        """Отправка прогресса через callback."""
        if not self.callback_handler:
            return

        total = self.current_batch_total or self.stats['total_processed']
        processed = self.batch_processed if self.current_batch_total is not None else self.stats['total_processed']
        processed = min(processed, total)

        self.callback_handler.send_progress(
            processed=processed,
            total=total,
        )
    
    def send_finish(self, status: str):
        """Отправка финального статуса."""
        if not self.callback_handler:
            return

        summary = dict(self.stats)
        summary['batch_size'] = self.batch_size
        summary['batch_total'] = self.current_batch_total
        summary['retried'] = self.stats.get('failed', 0)
        summary['errors'] = self.stats.get('failed', 0) + self.stats.get('blocked', 0)

        self.callback_handler.send_finish(
            status=status,
            summary=summary,
        )
    
    def _get_headers(self) -> dict:
        """Получить заголовки для API запросов."""
        headers = {'Content-Type': 'application/json'}
        if self.api_token:
            headers['X-Parser-Token'] = self.api_token
        return headers
    
    def _classify_error(self, error: Exception) -> tuple:
        """Классифицировать ошибку по коду."""
        error_str = str(error).lower()
        
        if 'timeout' in error_str or 'timed out' in error_str:
            return ErrorCodes.NAV_TIMEOUT, 'Navigation timeout'
        elif '403' in error_str or 'forbidden' in error_str:
            return ErrorCodes.HTTP_403, 'HTTP 403 Forbidden'
        elif '404' in error_str or 'not found' in error_str:
            return ErrorCodes.HTTP_404, 'HTTP 404 Not Found'
        elif 'selector' in error_str or 'not found' in error_str:
            return ErrorCodes.SELECTOR_NOT_FOUND, 'Selector not found'
        elif 'price' in error_str:
            return ErrorCodes.PRICE_PARSE_FAILED, 'Price parse failed'
        elif 'network' in error_str or 'connection' in error_str:
            return ErrorCodes.NETWORK_ERROR, 'Network error'
        else:
            return ErrorCodes.UNKNOWN, str(error)[:200]


def run_queue_worker(
    supplier_name: str,
    batch_size: int = 20,
    material_type: Optional[str] = None,
    api_callback: Optional[str] = None,
    api_token: Optional[str] = None,
    session_id: Optional[int] = None,
    reparse_days: int = 7,
    max_batches: Optional[int] = None,
    concurrency: int = 3,
    min_request_interval: float = 0.5,
) -> dict:
    """Запуск воркера очереди."""
    worker = QueueWorker(
        supplier_name=supplier_name,
        batch_size=batch_size,
        material_type=material_type,
        api_callback=api_callback,
        api_token=api_token,
        session_id=session_id,
        reparse_days=reparse_days,
        max_batches=max_batches,
        concurrency=concurrency,
        min_request_interval=min_request_interval,
    )
    
    return worker.run()
