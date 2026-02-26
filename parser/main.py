# parser/main.py
"""
DETERMINISTIC PARSER ENTRY POINT (ANTI-LOOP ARCHITECTURE)

REQUIREMENTS:
1. NO auto-start at container/worker startup
2. Started ONLY via explicit API request (POST /api/parsing/sessions)
3. Session status checks before each phase
4. collect runs ONCE per session
5. reset runs ONCE per session (after collect_done)
6. Crash/timeout → failed (no restart)

LIFECYCLE:
created → collecting → collect_done → parsing → completed
       ↘              ↘               ↘
        → failed       → failed        → failed
"""

import sys
import os
import asyncio
import logging
import argparse
import requests
import types
from pathlib import Path

# Поддержка запуска как модуля (python -m parser.main) и как скрипта (python main.py)
try:
    from .core import ParserCore, CallbackHandler, HttpHandler
    from .config import config_manager
except ImportError:
    # Прямой запуск - добавляем родительскую директорию в path
    sys.path.insert(0, str(Path(__file__).parent.parent))
    from parser.core import ParserCore, CallbackHandler, HttpHandler
    from parser.config import config_manager

# Логирование в stderr с flush
class StreamHandler(logging.StreamHandler):
    def emit(self, record):
        super().emit(record)
        self.flush()

handler = StreamHandler(sys.stderr)
handler.setFormatter(logging.Formatter("%(asctime)s [%(levelname)s] %(message)s"))

logging.basicConfig(
    level=logging.INFO,
    handlers=[handler]
)
logger = logging.getLogger(__name__)


def get_session_state(session_id: int, api_base_url: str, api_token: str = None) -> dict:
    """
    Fetch session state from Laravel API.
    Returns lifecycle info to check if collect/parse is allowed.
    """
    if not session_id:
        return None
        
    try:
        url = f"{api_base_url}/parsing/sessions/{session_id}/state"
        headers = {'Accept': 'application/json'}
        if api_token:
            headers['X-Parser-Token'] = api_token
            headers['Authorization'] = f"Bearer {api_token}"
        
        response = requests.get(url, headers=headers, timeout=10)
        if response.status_code == 200:
            data = response.json()
            return data.get('data', {})
    except Exception as e:
        print(f"[LIFECYCLE] WARNING: Could not fetch session state: {e}", file=sys.stderr, flush=True)
    
    return None


def main():
    """Основная функция для запуска парсера."""
    # BOOT logs for runtime verification
    print(f"[BOOT] argv={sys.argv}", file=sys.stderr, flush=True)
    print(f"[BOOT] main.py VERSION=2026-01-21-ANTI-LOOP-DETERMINISTIC", file=sys.stderr, flush=True)
    
    print("=" * 60, file=sys.stderr, flush=True)
    print("PARSER STARTED (DETERMINISTIC MODE)", file=sys.stderr, flush=True)
    print("=" * 60, file=sys.stderr, flush=True)
    
    # Парсим аргументы командной строки
    parser = argparse.ArgumentParser(
        description='Модульная система парсинга материалов',
        prog='python -m parser.main'
    )
    
    # Обязательный аргумент
    parser.add_argument(
        'supplier',
        nargs='?',
        help='Имя поставщика (e.g., skm_mebel)'
    )
    
    # Основные аргументы (существующие)
    parser.add_argument(
        '--url',
        type=str,
        help='Одиночный URL товара для парсинга'
    )
    parser.add_argument(
        '--file',
        type=str,
        help='Файл с URL товаров (один URL на строку)'
    )
    parser.add_argument(
        '--limit',
        type=int,
        help='Максимальное количество товаров для обработки'
    )
    parser.add_argument(
        '--offset',
        type=int,
        default=0,
        help='Смещение в списке URL (для chunked processing)'
    )
    parser.add_argument(
        '--list-suppliers',
        action='store_true',
        help='Показать список доступных поставщиков'
    )
    
    # ЭТАП 3: Режим обработки очереди
    parser.add_argument(
        '--queue',
        action='store_true',
        help='Режим обработки очереди URL из БД'
    )
    parser.add_argument(
        '--full-scan',
        action='store_true',
        help='Полный пересбор URL и запуск очереди'
    )
    parser.add_argument(
        '--collect-only',
        action='store_true',
        help='Только сбор URL (без парсинга)'
    )
    parser.add_argument(
        '--reset-only',
        action='store_true',
        help='Только reset статусов (без парсинга)'
    )
    parser.add_argument(
        '--batch-size',
        type=int,
        default=20,
        help='Размер пачки URL для обработки (по умолчанию 20)'
    )
    parser.add_argument(
        '--concurrency',
        type=int,
        default=3,
        help='Количество параллельных страниц в воркере (по умолчанию 3)'
    )
    parser.add_argument(
        '--min-request-interval',
        type=float,
        help='Минимальный интервал между запросами на домен (сек)'
    )
    parser.add_argument(
        '--hmac-secret',
        type=str,
        help='HMAC secret for collect_urls (optional; default from env PARSER_HMAC_SECRET)'
    )
    parser.add_argument(
        '--material-type',
        type=str,
        help='Фильтр по типу материала (опционально)'
    )
    parser.add_argument(
        '--reparse-days',
        type=int,
        default=7,
        help='Переобходить done URL старше N дней (по умолчанию 7)'
    )
    parser.add_argument(
        '--max-batches',
        type=int,
        help='Ограничить число батчей в режиме очереди (для chunked processing)'
    )
    
    # НОВЫЕ аргументы для интеграции с Laravel API
    parser.add_argument(
        '--session-id',
        type=int,
        help='ID сессии парсинга из Laravel (для отслеживания)'
    )
    parser.add_argument(
        '--api-callback',
        type=str,
        help='URL эндпоинта для отправки логов и статусов'
    )
    parser.add_argument(
        '--api-token',
        type=str,
        help='Токен безопасности для API callbacks'
    )
    
    args = parser.parse_args()

    if os.getenv('PARSER_DEBUG') == '1':
        assert isinstance(requests, types.ModuleType), "requests must be module, not shadowed"

    # ==================== ANTI-LOOP: NO AUTO-ENABLE FULL-SCAN ====================
    # CRITICAL: Do NOT auto-enable full-scan. Job manages lifecycle.
    # --queue = ONLY queue worker, no collect/reset
    # --collect-only = ONLY collect URLs
    # --reset-only = ONLY reset statuses
    # --full-scan = manual mode only (for CLI debugging)
    #
    # REMOVED: The old code that auto-enabled full-scan caused infinite loops:
    #   collect → reset → parse → crash → collect (again!)
    # =============================================================================
    
    print(f"[BOOT] ANTI-LOOP MODE: full_scan={bool(args.full_scan)} queue={bool(args.queue)} collect_only={bool(args.collect_only)} reset_only={bool(args.reset_only)}", file=sys.stderr, flush=True)
    
    # Warn if --queue without --max-batches (job should always set this)
    if args.queue and not args.max_batches:
        print("[BOOT] WARNING: --queue without --max-batches, will run until exhausted", file=sys.stderr, flush=True)
    
    # Обработка списка поставщиков
    if args.list_suppliers:
        suppliers = config_manager.list_suppliers()
        print("\nДоступные поставщики:")
        for supplier in suppliers:
            config = config_manager.load_supplier_config(supplier)
            status = "✓" if config.get('enabled', True) else "✗"
            print(f"  {status} {supplier} — {config.get('display_name', supplier)}")
        sys.exit(0)
    
    # Проверка обязательного аргумента
    if not args.supplier:
        print("Модульная система парсинга материалов")
        print("\nИспользование:")
        print("  python -m parser.main <supplier> --url <url>")
        print("  python -m parser.main <supplier> --file <filename>")
        print("  python -m parser.main <supplier> --file <filename> --limit <N>")
        print("  python -m parser.main <supplier> --queue")
        print("  python -m parser.main --list-suppliers")
        print("\nРежим очереди (ЭТАП 3):")
        print("  python -m parser.main <supplier> --queue --batch-size 50")
        print("  python -m parser.main <supplier> --queue --batch-size 30 --max-batches 1")
        print("  python -m parser.main <supplier> --queue --concurrency 3")
        print("  python -m parser.main <supplier> --queue --material-type ldsp")
        print("  python -m parser.main <supplier> --full-scan --concurrency 3")
        print("  python -m parser.main <supplier> --collect-only")
        print("  python -m parser.main <supplier> --reset-only")
        print("\nПримеры:")
        print("  python -m parser.main skm_mebel --url https://skm-mebel.ru/product/123")
        print("  python -m parser.main skm_mebel --file suppliers/skm_ldsp_urls.txt")
        print("  python -m parser.main skm_mebel --queue")
        sys.exit(1)
    
    supplier_name = args.supplier
    
    # Проверяем, что поставщик существует и включен
    try:
        config = config_manager.load_supplier_config(supplier_name)
        if not config.get('enabled', True):
            logger.error(f"Поставщик '{supplier_name}' отключен в конфигурации")
            sys.exit(1)
    except FileNotFoundError as e:
        logger.error(str(e))
        logger.info("Используйте --list-suppliers для просмотра доступных поставщиков")
        sys.exit(1)

    # ==================== COLLECT-ONLY MODE ====================
    if args.collect_only:
        print(f"[COLLECT_ONLY] Starting URL collection for {supplier_name}", file=sys.stderr, flush=True)
        
        # ANTI-LOOP: Check session state before collecting
        if args.session_id:
            api_base_url = os.getenv('PARSER_API_URL', 'http://web/api')
            if args.api_callback:
                try:
                    from urllib.parse import urlparse
                    parsed = urlparse(args.api_callback)
                    api_base_url = f"{parsed.scheme}://{parsed.netloc}/api"
                except Exception:
                    pass
            
            session_state = get_session_state(args.session_id, api_base_url, args.api_token)
            if session_state:
                print(f"[COLLECT_ONLY] Session state: {session_state}", file=sys.stderr, flush=True)
                
                if session_state.get('has_collect_executed', False):
                    print(f"[COLLECT_ONLY] SKIP: Collect already executed for session {args.session_id}", file=sys.stderr, flush=True)
                    print(f"[COLLECT_ONLY] lifecycle_status={session_state.get('lifecycle_status')}", file=sys.stderr, flush=True)
                    sys.exit(0)  # Success - nothing to do
                    
                if not session_state.get('can_collect', False):
                    print(f"[COLLECT_ONLY] BLOCKED: Session {args.session_id} cannot collect", file=sys.stderr, flush=True)
                    print(f"[COLLECT_ONLY] lifecycle_status={session_state.get('lifecycle_status')}", file=sys.stderr, flush=True)
                    sys.exit(1)  # Error - invalid state
        
        hmac_secret = args.hmac_secret or os.getenv('PARSER_HMAC_SECRET', 'default-hmac-secret')
        
        try:
            try:
                from .collect_urls import UrlCollector
            except ImportError:
                from parser.collect_urls import UrlCollector

            collector = UrlCollector(supplier_name, hmac_secret, args.session_id)
            result = collector.run()
            if result != 0:
                logger.error("[COLLECT_ONLY] FAILED: collect_urls returned non-zero")
                sys.exit(1)
            print(f"[COLLECT_ONLY] SUCCESS", file=sys.stderr, flush=True)
            sys.exit(0)
        except Exception as e:
            logger.error(f"[COLLECT_ONLY] ERROR: {e}", exc_info=True)
            sys.exit(1)

    # ==================== RESET-ONLY MODE ====================
    if args.reset_only:
        print(f"[RESET_ONLY] Starting reset for {supplier_name}", file=sys.stderr, flush=True)
        api_callback = args.api_callback
        api_token = args.api_token
        
        api_base_url = os.getenv('PARSER_API_URL', 'http://web/api')
        if api_callback:
            try:
                from urllib.parse import urlparse
                parsed = urlparse(api_callback)
                api_base_url = f"{parsed.scheme}://{parsed.netloc}/api"
            except Exception:
                pass

        # ANTI-LOOP: Check session state before resetting
        if args.session_id:
            session_state = get_session_state(args.session_id, api_base_url, api_token)
            if session_state:
                print(f"[RESET_ONLY] Session state: {session_state}", file=sys.stderr, flush=True)
                
                if session_state.get('has_parsing_started', False):
                    print(f"[RESET_ONLY] SKIP: Parsing already started for session {args.session_id}", file=sys.stderr, flush=True)
                    print(f"[RESET_ONLY] lifecycle_status={session_state.get('lifecycle_status')}", file=sys.stderr, flush=True)
                    sys.exit(0)  # Success - nothing to do
                
                lifecycle_status = session_state.get('lifecycle_status', '')
                if lifecycle_status not in ['collect_done', 'collecting']:
                    print(f"[RESET_ONLY] BLOCKED: Session {args.session_id} not in collect_done state", file=sys.stderr, flush=True)
                    print(f"[RESET_ONLY] lifecycle_status={lifecycle_status}", file=sys.stderr, flush=True)
                    # Allow reset only from collect_done
                    if lifecycle_status != 'collect_done':
                        sys.exit(1)

        try:
            reset_url = f"{api_base_url}/parser/urls/full-scan-reset"
            headers = {'Content-Type': 'application/json'}
            if api_token:
                headers['X-Parser-Token'] = api_token
                headers['Authorization'] = f"Bearer {api_token}"

            print(f"[RESET_ONLY] POST {reset_url}", file=sys.stderr, flush=True)
            response = requests.post(
                reset_url,
                json={'supplier_name': supplier_name},
                headers=headers,
                timeout=30,
            )
            if response.status_code != 200:
                logger.error(f"[RESET_ONLY] FAILED: HTTP {response.status_code} {response.text[:300]}")
                sys.exit(1)
            
            reset_data = response.json()
            print(f"[RESET_ONLY] SUCCESS: {reset_data}", file=sys.stderr, flush=True)
            sys.exit(0)
        except Exception as e:
            logger.error(f"[RESET_ONLY] ERROR: {e}", exc_info=True)
            sys.exit(1)
    
    # ==================== FULL-SCAN MODE (legacy, for direct CLI use) ====================
    if args.full_scan:
        print(f"[FULL_SCAN] ========== НАЧАЛО ПОЛНОГО ПЕРЕСКАНА ==========", file=sys.stderr, flush=True)
        print(f"[FULL_SCAN] supplier={supplier_name}", file=sys.stderr, flush=True)

        hmac_secret = args.hmac_secret or os.getenv('PARSER_HMAC_SECRET', 'default-hmac-secret')
        api_callback = args.api_callback
        api_token = args.api_token

        api_base_url = os.getenv('PARSER_API_URL', 'http://web/api')
        if api_callback:
            try:
                from urllib.parse import urlparse
                parsed = urlparse(api_callback)
                api_base_url = f"{parsed.scheme}://{parsed.netloc}/api"
            except Exception:
                pass

        # ==================== STEP 1: COLLECT URLs ====================
        print(f"[FULL_SCAN] STEP 1: collect_urls started", file=sys.stderr, flush=True)
        try:
            try:
                from .collect_urls import UrlCollector
            except ImportError:
                from parser.collect_urls import UrlCollector

            collector = UrlCollector(supplier_name, hmac_secret, args.session_id)
            result = collector.run()
            if result != 0:
                logger.error("[FULL_SCAN] STEP 1 FAILED: collect_urls returned non-zero")
                sys.exit(1)
            print(f"[FULL_SCAN] STEP 1: collect finished successfully", file=sys.stderr, flush=True)
        except Exception as e:
            logger.error(f"[FULL_SCAN] STEP 1 ERROR: {e}", exc_info=True)
            sys.exit(1)

        # ==================== STEP 2: RESET statuses ====================
        print(f"[FULL_SCAN] STEP 2: full-scan-reset started", file=sys.stderr, flush=True)
        pending_count = 0
        try:
            reset_url = f"{api_base_url}/parser/urls/full-scan-reset"
            headers = {'Content-Type': 'application/json'}
            if api_token:
                headers['X-Parser-Token'] = api_token
                headers['Authorization'] = f"Bearer {api_token}"

            print(f"[FULL_SCAN] STEP 2: POST {reset_url}", file=sys.stderr, flush=True)
            response = requests.post(
                reset_url,
                json={'supplier_name': supplier_name},
                headers=headers,
                timeout=30,
            )
            if response.status_code != 200:
                logger.error(f"[FULL_SCAN] STEP 2 FAILED: HTTP {response.status_code} {response.text[:300]}")
                sys.exit(1)
            
            reset_data = response.json()
            print(f"[FULL_SCAN] STEP 2: reset response: {reset_data}", file=sys.stderr, flush=True)
            
            # Проверяем pending после reset
            pending_count = reset_data.get('after', {}).get('pending', 0)
            print(f"[FULL_SCAN] STEP 2: pending_count={pending_count}", file=sys.stderr, flush=True)
            
        except Exception as e:
            logger.error(f"[FULL_SCAN] STEP 2 ERROR: {e}", exc_info=True)
            sys.exit(1)

        # ==================== STEP 3: VERIFY pending > 0 ====================
        if pending_count == 0:
            logger.error("[FULL_SCAN] FATAL: FULL_SCAN_RESET_DID_NOT_CREATE_PENDING")
            logger.error("[FULL_SCAN] После reset pending_count=0 — нечего парсить!")
            sys.exit(1)
        print(f"[FULL_SCAN] STEP 3: verified pending_count={pending_count} > 0 ✓", file=sys.stderr, flush=True)

        # ==================== STEP 4: RUN QUEUE until exhausted ====================
        print(f"[FULL_SCAN] STEP 4: run_queue_worker_async started", file=sys.stderr, flush=True)
        try:
            from .queue_worker_async import run_queue_worker_async
        except ImportError:
            from parser.queue_worker_async import run_queue_worker_async

        min_request_interval = args.min_request_interval
        if min_request_interval is None:
            min_request_interval = float(os.getenv('PARSER_REQUEST_DELAY', '0'))

        stats = asyncio.run(
            run_queue_worker_async(
                supplier_name=supplier_name,
                batch_size=args.batch_size,
                material_type=args.material_type,
                api_callback=api_callback,
                api_token=api_token,
                session_id=args.session_id,
                reparse_days=args.reparse_days,
                max_batches=args.max_batches,
                concurrency=args.concurrency,
                min_request_interval=min_request_interval,
                full_scan=True,
            )
        )

        print(f"[FULL_SCAN] ========== ЗАВЕРШЕНО ==========", file=sys.stderr, flush=True)
        print(f"[FULL_SCAN] Статистика: {stats}", file=sys.stderr, flush=True)
        sys.exit(0)

    if args.queue:
        print(f"[QUEUE] Запуск в режиме очереди для {supplier_name}", file=sys.stderr, flush=True)
        
        try:
            from .queue_worker_async import run_queue_worker_async
        except ImportError:
            from parser.queue_worker_async import run_queue_worker_async
        
        try:
            min_request_interval = args.min_request_interval
            if min_request_interval is None:
                min_request_interval = float(os.getenv('PARSER_REQUEST_DELAY', '0'))

            stats = asyncio.run(
                run_queue_worker_async(
                    supplier_name=supplier_name,
                    batch_size=args.batch_size,
                    material_type=args.material_type,
                    api_callback=args.api_callback,
                    api_token=args.api_token,
                    session_id=args.session_id,
                    reparse_days=args.reparse_days,
                    max_batches=args.max_batches,
                    concurrency=args.concurrency,
                    min_request_interval=min_request_interval,
                )
            )
            
            print(f"[QUEUE] Завершено. Статистика: {stats}", file=sys.stderr, flush=True)
            sys.exit(0)
            
        except KeyboardInterrupt:
            logger.warning("Обработка очереди прервана пользователем (Ctrl+C)")
            sys.exit(130)
        except Exception as e:
            logger.error(f"Ошибка обработки очереди: {e}", exc_info=True)
            sys.exit(1)
    
    # Стандартный режим парсинга (существующий код)
    
    # Создаём ядро парсера с параметрами API (если указаны)
    print(f"[INIT] Creating ParserCore...", file=sys.stderr, flush=True)
    parser_core = ParserCore(
        api_callback=args.api_callback,
        api_token=args.api_token,
        session_id=args.session_id
    )
    print(f"[INIT] ParserCore created successfully", file=sys.stderr, flush=True)
    
    try:
        # Получаем список URL для парсинга
        urls = []
        
        if args.url:
            urls = [args.url]
            logger.info(f"Парсинг одного товара: {args.url}")
        
        elif args.file:
            print(f"[INIT] Loading URLs from file: {args.file}", file=sys.stderr, flush=True)
            # Поиск файла относительно папки parser
            file_path = Path(__file__).parent / args.file
            if not file_path.exists():
                # Пробуем относительно корня проекта
                file_path = Path(__file__).parent.parent / args.file
            
            if not file_path.exists():
                logger.error(f"Файл не найден: {args.file}")
                sys.exit(1)
            
            # Читаем URL из файла
            all_urls = [
                line.strip() 
                for line in file_path.read_text(encoding="utf-8").splitlines() 
                if line.strip() and not line.strip().startswith('#')
            ]
            
            total_urls = len(all_urls)
            
            # Apply offset and limit for chunked processing
            start_idx = args.offset
            end_idx = start_idx + args.limit if args.limit else len(all_urls)
            urls = all_urls[start_idx:end_idx]
            
            logger.info(f"Загружено {len(urls)} URL из файла (offset={args.offset}, total={total_urls})")
        
        else:
            logger.error("Не указан источник URL (используйте --url, --file или --queue)")
            sys.exit(1)
        
        print(f"[INIT] Starting parse_urls with {len(urls)} URLs...", file=sys.stderr, flush=True)
        # Запускаем парсинг
        stats = parser_core.parse_urls(supplier_name, urls, smart_screenshot=False)
        
        # Завершаем успешно
        logger.info("Парсинг завершен успешно")
        sys.exit(0)
    
    except KeyboardInterrupt:
        logger.warning("Парсинг прерван пользователем (Ctrl+C)")
        sys.exit(130)
    
    except Exception as e:
        logger.error(f"Критическая ошибка: {e}", exc_info=True)
        sys.exit(1)


if __name__ == "__main__":
    main()
