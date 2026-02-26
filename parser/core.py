# parser/core.py

import logging
import requests
from typing import List, Optional, Dict, Any, Callable
from datetime import datetime
import importlib
import sys
from pathlib import Path
import threading
import json
import os
import time

# Поддержка запуска как модуля и как скрипта
try:
    from .base_adapter import SupplierAdapter, MaterialData
    from .config import config_manager
except ImportError:
    sys.path.insert(0, str(Path(__file__).parent.parent))
    from parser.base_adapter import SupplierAdapter, MaterialData
    from parser.config import config_manager


logger = logging.getLogger(__name__)


class MaterialBatcher:
    """
    Буферизирует материалы для batch-сохранения.
    Thread-safe для использования с параллельным парсингом.
    """
    
    BATCH_SIZE = 50  # Размер буфера материалов
    
    def __init__(self, flush_callback: Callable[[List[MaterialData]], Dict[str, Any]]):
        """
        Args:
            flush_callback: Функция для отправки batch (принимает List[MaterialData])
        """
        self._buffer: List[MaterialData] = []
        self._lock = threading.Lock()
        self._flush_callback = flush_callback
        self._stats = {
            'total_added': 0,
            'total_flushed': 0,
            'success_count': 0,
            'failed_count': 0
        }
    
    def add(self, material: MaterialData) -> Optional[Dict[str, Any]]:
        """
        Добавляет материал в буфер. Автоматически flush при достижении BATCH_SIZE.
        
        Returns:
            dict или None: Результат flush если был выполнен
        """
        with self._lock:
            self._buffer.append(material)
            self._stats['total_added'] += 1
            
            if len(self._buffer) >= self.BATCH_SIZE:
                return self._flush_locked()
        
        return None
    
    def flush(self) -> Optional[Dict[str, Any]]:
        """Принудительно отправляет все материалы из буфера."""
        with self._lock:
            return self._flush_locked()
    
    def _flush_locked(self) -> Optional[Dict[str, Any]]:
        """Внутренний flush (должен вызываться под lock)."""
        if not self._buffer:
            return None
        
        materials_to_send = self._buffer.copy()
        self._buffer.clear()
        
        result = self._flush_callback(materials_to_send)
        
        self._stats['total_flushed'] += len(materials_to_send)
        self._stats['success_count'] += result.get('success_count', 0)
        self._stats['failed_count'] += result.get('failed_count', 0)
        
        return result
    
    @property
    def stats(self) -> Dict[str, int]:
        """Возвращает статистику batching."""
        with self._lock:
            return self._stats.copy()
    
    @property
    def pending_count(self) -> int:
        """Количество материалов в буфере."""
        with self._lock:
            return len(self._buffer)


class CallbackHandler:
    """
    Управляет отправкой логов, прогресса и статусов в Laravel через HTTP API.
    Буферизирует логи для оптимизации количества HTTP запросов.
    
    Оптимизации:
    - Progress отправляется раз в 50 товаров ИЛИ раз в 30 секунд
    - Non-blocking: таймаут 500ms, fail silently
    - Логи буферизируются и отправляются пачками
    """
    
    # Константы throttling
    PROGRESS_ITEM_THRESHOLD = 5  # Отправлять progress каждые N товаров
    PROGRESS_TIME_THRESHOLD = 1  # Или каждые N секунд
    PROGRESS_TIMEOUT = 0.5  # Non-blocking timeout for progress (500ms)
    CALLBACK_MAX_RETRIES = 5
    CALLBACK_BACKOFFS = [1, 3, 10, 30, 30]
    
    def __init__(
        self,
        url: str,
        token: str,
        session_id: int,
        buffer_size: int = 10
    ):
        """
        Инициализация обработчика callback'ов.
        
        Args:
            url: URL эндпоинта Laravel для callback'ов
            token: Токен безопасности
            session_id: ID сессии парсинга
            buffer_size: Размер буфера перед отправкой
        """
        self.url = url
        self.token = token
        self.session_id = session_id
        self.buffer_size = buffer_size
        
        self.buffer: List[Dict[str, Any]] = []
        self.lock = threading.Lock()
        
        # Progress throttling state
        self._last_progress_time = 0.0
        self._last_progress_count = 0
        self._event_seq = 0
        self._callback_disabled = False
        self._callback_fatal_logged = False
    
    def add_log(
        self,
        level: str,
        message: str,
        details: Optional[Dict[str, Any]] = None
    ) -> Optional[Dict[str, Any]]:
        """
        Добавляет лог в буфер. Автоматически отправляет при переполнении.
        
        Args:
            level: Уровень логирования (debug, info, warning, error, critical)
            message: Текст сообщения
            details: Дополнительные данные (опционально)
            
        Returns:
            dict: Ответ от API (если был отправлен)
        """
        with self.lock:
            self.buffer.append({
                'level': level,
                'message': message,
                'details': details or {}
            })
            
            # Отправляем если буфер переполнен или критическая ошибка
            if len(self.buffer) >= self.buffer_size or level in ('error', 'critical'):
                return self.flush()
        
        return None
    
    def send_progress(
        self,
        processed: int,
        total: int,
        force: bool = False
    ) -> Optional[Dict[str, Any]]:
        """
        Отправляет обновление прогресса с throttling (NON-BLOCKING).
        
        Throttling: отправляет только если:
        - force=True (принудительно)
        - Прошло >= 50 товаров с последней отправки
        - Прошло >= 30 секунд с последней отправки
        
        Non-blocking: timeout 500ms, fail silently
        
        Args:
            processed: Количество обработанных товаров
            total: Общее количество товаров
            force: Принудительно отправить (игнорировать throttling)
            
        Returns:
            dict: Ответ от API или None если throttled/failed
        """
        current_time = time.time()
        items_since_last = processed - self._last_progress_count
        time_since_last = current_time - self._last_progress_time
        
        # Проверяем нужно ли отправлять
        should_send = force or \
            items_since_last >= self.PROGRESS_ITEM_THRESHOLD or \
            time_since_last >= self.PROGRESS_TIME_THRESHOLD or \
            processed == 0 or \
            processed == total  # Всегда отправляем первый и последний
        
        if not should_send:
            return None
        
        # Обновляем state
        self._last_progress_time = current_time
        self._last_progress_count = processed
        
        percent = (processed / total * 100) if total > 0 else 0
        
        # Non-blocking send with short timeout
        return self._send_nonblocking({
            'type': 'progress',
            'payload': {
                'processed': processed,
                'total': total,
                'percent': round(percent, 2)
            }
        })
    
    def send_total_urls(self, total_urls: int) -> Optional[Dict[str, Any]]:
        """
        Отправляет общее количество собранных URL в Laravel.
        Используется после сбора URL для обновления прогресс-бара.
        
        Args:
            total_urls: Общее количество собранных URL
            
        Returns:
            dict: Ответ от API
        """
        return self._send({
            'type': 'total_urls',
            'payload': {
                'total': total_urls
            }
        })
    
    def mark_url_failed(self, url: str, error: str, error_code: str = 'PARSE_ERROR') -> Optional[Dict[str, Any]]:
        """
        Помечает URL как невалидный/failed в БД.
        Non-blocking — не должен тормозить парсинг.
        
        Args:
            url: URL который не удалось распарсить
            error: Текст ошибки
            error_code: Код ошибки (INVALID_PAGE, SELECTOR_NOT_FOUND, etc)
            
        Returns:
            dict: Ответ от API
        """
        return self._send_nonblocking({
            'type': 'mark_url_failed',
            'payload': {
                'url': url,
                'error': error[:500],  # Обрезаем длинные сообщения
                'error_code': error_code
            }
        })
    
    def send_finish(
        self,
        status: str,
        summary: Optional[Dict[str, Any]] = None
    ) -> Optional[Dict[str, Any]]:
        """
        Отправляет информацию о завершении парсинга.
        
        Args:
            status: Статус завершения (completed, failed, stopped)
            summary: Краткая информация о результатах
            
        Returns:
            dict: Ответ от API
        """
        # Сначала отправляем оставшиеся логи
        self.flush()
        
        return self._send({
            'type': 'finish',
            'payload': {
                'status': status,
                'summary': summary or {}
            }
        })
    
    def flush(self) -> Optional[Dict[str, Any]]:
        """
        Отправляет все буферизированные логи на сервер.
        
        Returns:
            dict: Ответ от API
        """
        with self.lock:
            if not self.buffer:
                return {'success': True, 'command': None}
            
            # Формируем payload с логами
            payload = {
                'type': 'log',
                'payload': self.buffer.copy()
            }
            self.buffer.clear()
            
            return self._send(payload)
    
    def _send_nonblocking(self, data: Dict[str, Any]) -> Optional[Dict[str, Any]]:
        """
        Non-blocking send with short timeout (500ms).
        Fails silently — parsing should not be blocked by callback failures.
        
        Args:
            data: Данные для отправки (type, payload)
            
        Returns:
            dict: Ответ от API или None при ошибке/timeout
        """
        return self._send(data, nonblocking=True, timeout=self.PROGRESS_TIMEOUT)
    
    def _send(self, data: Dict[str, Any], nonblocking: bool = False, timeout: float = 10) -> Optional[Dict[str, Any]]:
        """
        Отправляет HTTP POST запрос на сервер Laravel.
        
        Args:
            data: Данные для отправки (type, payload)
            
        Returns:
            dict: Ответ от API или None при ошибке
        """
        if self._callback_disabled:
            return {'success': False, 'command': None}

        body = {
            'session_id': self.session_id,
            'token': self.token,
            'timestamp': int(time.time()),
            'event_id': self._next_event_id(data.get('type', 'event')),
            **data
        }

        headers = {
            'Content-Type': 'application/json',
            'X-Parser-Token': self.token,
            'Authorization': f"Bearer {self.token}",
        }

        for attempt, backoff in enumerate(self.CALLBACK_BACKOFFS, 1):
            try:
                if not nonblocking:
                    print(f"[CALLBACK] Отправляю {data.get('type')}...", file=sys.stderr, flush=True)
                    sys.stderr.flush()

                response = requests.post(
                    self.url,
                    json=body,
                    timeout=timeout,
                    headers=headers,
                )

                if response.status_code in (401, 422):
                    if not self._callback_fatal_logged:
                        print(f"[CALLBACK_FATAL] {response.status_code}: {response.text[:200]}", file=sys.stderr, flush=True)
                        self._callback_fatal_logged = True
                    self._callback_disabled = True
                    return {'success': False, 'command': None}

                if response.status_code >= 500:
                    if attempt < self.CALLBACK_MAX_RETRIES:
                        time.sleep(backoff)
                        continue
                    self._callback_disabled = True
                    return {'success': False, 'command': None}

                if response.status_code == 200:
                    return response.json()

                if not nonblocking:
                    print(f"[CALLBACK] API ошибка: {response.status_code} - {response.text[:200]}", file=sys.stderr, flush=True)
                    sys.stderr.flush()
                return {'success': False, 'command': None}

            except requests.exceptions.Timeout as e:
                if attempt < self.CALLBACK_MAX_RETRIES:
                    time.sleep(backoff)
                    continue
                self._callback_disabled = True
                return {'success': False, 'command': None}
            except requests.exceptions.ConnectionError as e:
                if attempt < self.CALLBACK_MAX_RETRIES:
                    time.sleep(backoff)
                    continue
                self._callback_disabled = True
                return {'success': False, 'command': None}
            except Exception as e:
                if attempt < self.CALLBACK_MAX_RETRIES:
                    time.sleep(backoff)
                    continue
                self._callback_disabled = True
                return {'success': False, 'command': None}

        return {'success': False, 'command': None}

    def _next_event_id(self, event_type: str) -> str:
        self._event_seq += 1
        return f"{self.session_id}:{event_type}:{self._event_seq}"


class HttpHandler(logging.Handler):
    """
    Обработчик логов Python, отправляющий логи через CallbackHandler.
    Интегрируется в стандартный Python logging.
    """
    
    def __init__(self, callback_handler: CallbackHandler):
        """
        Инициализация обработчика.
        
        Args:
            callback_handler: Экземпляр CallbackHandler
        """
        super().__init__()
        self.callback_handler = callback_handler
    
    def emit(self, record: logging.LogRecord):
        """
        Вызывается при каждом логируемом событии.
        
        Args:
            record: LogRecord с информацией о событии
        """
        try:
            level = record.levelname.lower()
            message = record.getMessage()
            
            # Используем дополнительные параметры если они есть
            details = {}
            if hasattr(record, 'details'):
                details = record.details
            
            self.callback_handler.add_log(level, message, details)
        except Exception:
            # Не должны прерывать основное логирование
            self.handleError(record)


class ParserCore:
    """
    Ядро системы парсинга.
    Управляет сессиями, логированием, валидацией и хранением данных.
    Поддерживает интеграцию с Laravel через HTTP callbacks.
    """
    
    def __init__(
        self,
        api_url: str = "http://host.docker.internal:8000/api/parser",
        api_callback: Optional[str] = None,
        api_token: Optional[str] = None,
        session_id: Optional[int] = None
    ):
        """
        Инициализация ядра парсера.
        
        Args:
            api_url: Базовый URL API для отправки данных материалов
            api_callback: URL эндпоинта Laravel для callback'ов
            api_token: Токен безопасности для callback'ов
            session_id: ID сессии парсинга из Laravel
        """
        self.api_url = api_url
        self.session_id = session_id
        self.should_stop = False
        self.callback_handler: Optional[CallbackHandler] = None
        self.api_token = api_token  # Store token for internal API calls
        self.request_delay = float(os.getenv("PARSER_REQUEST_DELAY", "0"))
        self.log_buffer_size = int(os.getenv("PARSER_LOG_BUFFER_SIZE", "1"))
        
        # Инициализируем обработчик callback'ов если указаны параметры
        if api_callback and api_token and session_id:
            self.callback_handler = CallbackHandler(
                url=api_callback,
                token=api_token,
                session_id=session_id,
                buffer_size=self.log_buffer_size,
            )
            # Добавляем HTTP handler к логгеру
            http_handler = HttpHandler(self.callback_handler)
            logger.addHandler(http_handler)
    
    def get_adapter(self, supplier_name: str) -> SupplierAdapter:
        """
        Создаёт и возвращает адаптер для указанного поставщика.
        
        Args:
            supplier_name: Имя поставщика (например, 'skm_mebel')
            
        Returns:
            SupplierAdapter: Экземпляр адаптера
            
        Raises:
            ImportError: Если не удалось импортировать адаптер
            ValueError: Если конфигурация невалидна
        """
        # Загружаем конфигурацию
        config = config_manager.load_supplier_config(supplier_name)
        
        # Получаем класс адаптера
        adapter_class_path = config['adapter_class']  # например, "suppliers.skm_mebel.SkmMebelAdapter"
        
        # Разделяем на модуль и класс
        module_path, class_name = adapter_class_path.rsplit('.', 1)
        
        # Импортируем модуль с относительным путём
        module = importlib.import_module('.' + module_path, package='parser')
        
        # Получаем класс
        adapter_class = getattr(module, class_name)
        
        # Создаём экземпляр
        adapter = adapter_class(config)
        
        return adapter
    
    def process_material(self, material: MaterialData, check_existing: bool = True) -> bool:
        """
        Обрабатывает и отправляет данные материала в API.
        
        Args:
            material: Данные материала
            check_existing: Проверять ли существующие данные перед отправкой
            
        Returns:
            bool: True если успешно сохранено
        """
        try:
            # Отправляем в API
            response = requests.post(
                f"{self.api_url}/materials",
                json=material.to_dict(),
                headers={"Content-Type": "application/json", "Accept": "application/json"},
                timeout=30
            )
            
            if response.status_code in (200, 201):
                print(
                    f"[MATERIAL] ✓ Сохранено: {material.article} — {material.price_per_unit} ₽ [{material.availability_status}]",
                    file=sys.stderr, flush=True
                )
                sys.stderr.flush()
                return True
            else:
                print(f"[MATERIAL] API ошибка {response.status_code}: {response.text}", file=sys.stderr, flush=True)
                sys.stderr.flush()
                self._log_parsing_error(material.source_url, f"API error: {response.status_code}")
                return False
                
        except Exception as e:
            print(f"[MATERIAL] Ошибка при сохранении {material.article}: {e}", file=sys.stderr, flush=True)
            sys.stderr.flush()
            self._log_parsing_error(material.source_url, str(e))
            return False
    
    def process_materials_batch(self, materials: List[MaterialData]) -> Dict[str, Any]:
        """
        Отправляет пачку материалов в API (batch endpoint).
        
        Args:
            materials: Список материалов для сохранения
            
        Returns:
            dict: {success_count, failed_count, results}
        """
        if not materials:
            return {'success_count': 0, 'failed_count': 0, 'results': []}
        
        try:
            payload = {
                'materials': [m.to_dict() for m in materials]
            }
            
            response = requests.post(
                f"{self.api_url}/materials/batch",
                json=payload,
                headers={"Content-Type": "application/json", "Accept": "application/json"},
                timeout=60  # Больше timeout для batch
            )
            
            if response.status_code in (200, 201):
                data = response.json()
                results = data.get('results', [])
                success_count = sum(1 for r in results if r.get('success'))
                failed_count = len(results) - success_count
                
                print(
                    f"[BATCH] ✓ Сохранено {success_count}/{len(materials)} материалов",
                    file=sys.stderr, flush=True
                )
                sys.stderr.flush()
                
                return {
                    'success_count': success_count,
                    'failed_count': failed_count,
                    'results': results
                }
            else:
                print(f"[BATCH] API ошибка {response.status_code}: {response.text[:200]}", file=sys.stderr, flush=True)
                sys.stderr.flush()
                return {
                    'success_count': 0,
                    'failed_count': len(materials),
                    'results': []
                }
                
        except Exception as e:
            print(f"[BATCH] Ошибка при batch сохранении: {e}", file=sys.stderr, flush=True)
            sys.stderr.flush()
            return {
                'success_count': 0,
                'failed_count': len(materials),
                'results': []
            }
    
    def get_existing_material(self, article: str) -> Optional[dict]:
        """
        Получает существующий материал из БД по артикулу.
        
        Args:
            article: Артикул материала
            
        Returns:
            dict или None: Данные материала или None если не найден
        """
        try:
            response = requests.get(
                f"{self.api_url}/materials/{article}",
                headers={"Accept": "application/json"},
                timeout=10
            )
            
            if response.status_code == 200:
                return response.json()
            
            return None
            
        except Exception as e:
            print(f"[MATERIAL] Не удалось получить существующий материал {article}: {e}", file=sys.stderr, flush=True)
            sys.stderr.flush()
            return None
    
    def get_urls_from_api(self, supplier_name: str, material_type: Optional[str] = None) -> List[str]:
        """
        Получает актуальные URL из БД через Laravel API.
        
        Args:
            supplier_name: Имя поставщика
            material_type: Тип материала (опционально)
            
        Returns:
            List[str]: Список URL для парсинга
        """
        try:
            # Use internal Docker network URL (same as callback URL)
            api_url = f"http://web/api/parsing/get-urls/{supplier_name}"
            
            params = {
                'only_valid': 'true'
            }
            
            if material_type:
                params['material_type'] = material_type
            
            print(f"[GET_URLS] Fetching URLs from API: {api_url}", file=sys.stderr, flush=True)
            sys.stderr.flush()
            
            headers = {"Accept": "application/json"}
            if self.api_token:
                headers["X-Parser-Token"] = self.api_token
            
            response = requests.get(
                api_url,
                params=params,
                headers=headers,
                timeout=10
            )
            
            if response.status_code == 200:
                data = response.json()
                
                if data.get('success'):
                    # API возвращает URLs grouped by material_type
                    urls_by_type = data.get('urls', {})
                    all_urls = []
                    
                    for mat_type, urls in urls_by_type.items():
                        all_urls.extend(urls)
                    
                    print(f"[GET_URLS] ✓ Loaded {len(all_urls)} URLs from API", file=sys.stderr, flush=True)
                    sys.stderr.flush()
                    
                    return all_urls
                else:
                    print(f"[GET_URLS] API returned success=false", file=sys.stderr, flush=True)
                    sys.stderr.flush()
                    return []
            else:
                print(f"[GET_URLS] API error: {response.status_code}", file=sys.stderr, flush=True)
                sys.stderr.flush()
                return []
                
        except Exception as e:
            print(f"[GET_URLS] ERROR fetching URLs from API: {e}", file=sys.stderr, flush=True)
            sys.stderr.flush()
            import traceback
            traceback.print_exc(file=sys.stderr)
            return []
    
    def parse_urls(
        self,
        supplier_name: str,
        urls: Optional[List[str]] = None,
        smart_screenshot: bool = True,
        concurrency: int = 1  # Reserved for future async implementation
    ) -> dict:
        """
        Парсит список URL с использованием адаптера поставщика.
        
        Оптимизации MVP:
        - Batch сохранение: буфер 50 материалов → один POST
        - Throttled progress: раз в 50 товаров или 60 сек
        - Параллельность: TODO (Playwright sync не thread-safe)
        
        Args:
            supplier_name: Имя поставщика
            urls: Список URL для парсинга
            smart_screenshot: Не используется (скриншоты отключены)
            concurrency: Зарезервировано для будущей async реализации
            
        Returns:
            dict: Статистика парсинга
        """
        print(f"[PARSE_URLS] Starting with {len(urls) if urls else 0} URLs", file=sys.stderr, flush=True)
        sys.stderr.flush()
        
        # Создаём сессию парсинга
        self._start_session(supplier_name)
        
        # Получаем адаптер
        print(f"[PARSE_URLS] Getting adapter for {supplier_name}...", file=sys.stderr, flush=True)
        sys.stderr.flush()
        adapter = self.get_adapter(supplier_name)
        
        # Инициализируем адаптер
        try:
            adapter.setup()
        except Exception as e:
            print(f"[PARSE_URLS] adapter.setup() FAILED: {e}", file=sys.stderr, flush=True)
            sys.stderr.flush()
            return {
                'total': 0,
                'success': 0,
                'errors': 1,
                'screenshots_taken': 0,
                'screenshots_reused': 0
            }
        
        # Получаем URL для парсинга
        config = adapter.config
        use_db_urls = config.get('url_collection_frequency') is not None
        urls_provided = bool(urls)
        
        # Режимы источника URL не смешиваются:
        # - Если URLs переданы (например, из --file/--offset/--limit), не дергаем API/БД
        # - Если URLs не переданы и включен DB-режим, берем из API/БД
        # - Автосбор URL внутри parsing запрещён
        if not urls_provided and use_db_urls:
            print(f"[PARSE_URLS] Fetching URLs from DB...", file=sys.stderr, flush=True)
            sys.stderr.flush()
            urls = self.get_urls_from_api(supplier_name)
        
        if not urls:
            print(f"[PARSE_URLS] ERROR: No URLs to parse!", file=sys.stderr, flush=True)
            sys.stderr.flush()
            adapter.teardown()
            return {
                'total': 0,
                'success': 0,
                'errors': 1,
                'screenshots_taken': 0,
                'screenshots_reused': 0
            }
        
        # Отправляем total_urls
        if self.callback_handler:
            self.callback_handler.send_total_urls(len(urls))
        
        # Инициализируем batch сохранение
        material_batcher = MaterialBatcher(self.process_materials_batch)
        
        stats = {
            'total': len(urls),
            'success': 0,
            'errors': 0,
            'processed': 0,
            'screenshots_taken': 0,
            'screenshots_reused': 0
        }
        
        try:
            for i, url in enumerate(urls, 1):
                # Проверяем stop
                if self.should_stop:
                    print("[PARSE_URLS] Парсинг остановлен по команде сервера", file=sys.stderr, flush=True)
                    sys.stderr.flush()
                    break
                
                # Log progress каждые 10 товаров (показываем processed+1 = текущий)
                if stats['processed'] % 10 == 0:
                    print(f"[PARSE_URLS] [{stats['processed']+1}/{len(urls)}] Processing {url[:60]}...", file=sys.stderr, flush=True)
                    sys.stderr.flush()

                if self.request_delay > 0:
                    time.sleep(self.request_delay)
                
                try:
                    material = adapter.parse_product_page(url, take_screenshot=False)
                    
                    # Валидация
                    if not adapter.validate_material_data(material):
                        stats['errors'] += 1
                        stats['processed'] += 1
                        self._log_parsing_error(url, "Validation failed")
                        # Mark as failed in DB
                        if self.callback_handler:
                            self.callback_handler.mark_url_failed(url, "Validation failed", "VALIDATION_ERROR")
                        continue
                    
                    # Добавляем в batch (автоматический flush при 50)
                    material_batcher.add(material)
                    stats['success'] += 1
                    stats['processed'] += 1
                        
                except Exception as e:
                    error_msg = str(e)
                    print(f"[PARSE_URLS] Ошибка {url}: {error_msg}", file=sys.stderr, flush=True)
                    sys.stderr.flush()
                    stats['errors'] += 1
                    stats['processed'] += 1
                    self._log_parsing_error(url, error_msg)
                    
                    # Determine error code and mark URL as failed in DB
                    if self.callback_handler:
                        if "Not a product page" in error_msg:
                            error_code = "INVALID_PAGE"
                        elif "Product name not found" in error_msg:
                            error_code = "NAME_NOT_FOUND"
                        elif "timeout" in error_msg.lower() or "Timeout" in error_msg:
                            error_code = "TIMEOUT"
                        elif "Failed to load page" in error_msg:
                            error_code = "PAGE_LOAD_ERROR"
                        else:
                            error_code = "PARSE_ERROR"
                        
                        self.callback_handler.mark_url_failed(url, error_msg, error_code)
                
                # Progress AFTER processing (correct count)
                if self.callback_handler:
                    response = self.callback_handler.send_progress(
                        processed=stats['processed'],
                        total=len(urls)
                    )
                    if response and response.get('command') == 'stop':
                        print(f"[PARSE_URLS] Parser stopped by server", file=sys.stderr, flush=True)
                        self.should_stop = True
                        break
            
            # Финальный flush оставшихся материалов
            remaining = material_batcher.pending_count
            if remaining > 0:
                print(f"[PARSE_URLS] Final flush of {remaining} materials...", file=sys.stderr, flush=True)
                sys.stderr.flush()
                material_batcher.flush()
            
        finally:
            adapter.teardown()
        
        # Финальный progress (force=True)
        if self.callback_handler:
            self.callback_handler.send_progress(
                processed=stats['processed'],
                total=len(urls),
                force=True
            )
        
        # Завершаем сессию
        self._finish_session(stats)
        
        # Отправляем финальный статус
        if self.callback_handler:
            final_status = 'stopped' if self.should_stop else 'completed'
            batcher_stats = material_batcher.stats
            self.callback_handler.send_finish(
                status=final_status,
                summary={
                    'total_processed': stats['processed'],
                    'total_urls': stats['total'],
                    'successful': stats['success'],
                    'errors': stats['errors'],
                    'batch_success': batcher_stats['success_count'],
                    'batch_failed': batcher_stats['failed_count'],
                    'screenshots_taken': stats['screenshots_taken']
                }
            )
        
        print(
            f"[PARSE_URLS] Complete. Success: {stats['success']}, "
            f"Errors: {stats['errors']}, "
            f"Batch saved: {material_batcher.stats['success_count']}", 
            file=sys.stderr, flush=True
        )
        sys.stderr.flush()
        
        return stats
    
    def _start_session(self, supplier_name: str):
        """Создаёт сессию парсинга в БД."""
        print(f"[_start_session] Start", file=sys.stderr, flush=True)
        sys.stderr.flush()
        # TODO: Реализовать создание сессии через API
        print(f"[_start_session] session_id is {self.session_id}", file=sys.stderr, flush=True)
        sys.stderr.flush()
        self.session_id = None
        print(f"[_start_session] End", file=sys.stderr, flush=True)
        sys.stderr.flush()
    
    def _finish_session(self, stats: dict):
        """Завершает сессию парсинга."""
        # TODO: Реализовать обновление сессии через API
        print("[SESSION] Сессия парсинга завершена", file=sys.stderr, flush=True)
        sys.stderr.flush()
    
    def _log_parsing_error(self, url: str, message: str):
        """Логирует ошибку парсинга."""
        # TODO: Реализовать логирование в БД через API
        print(f"[ERROR] {url}: {message}", file=sys.stderr, flush=True)
        sys.stderr.flush()
