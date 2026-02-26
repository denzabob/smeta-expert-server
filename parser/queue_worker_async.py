# parser/queue_worker_async.py

"""
Async queue worker for URL parsing using Playwright async API.
"""

import asyncio
import sys
import time
import uuid
import logging
from dataclasses import dataclass
from datetime import datetime
from typing import Any, Dict, List, Optional
from urllib.parse import urlparse

import aiohttp
from playwright.async_api import async_playwright, Page, TimeoutError as PlaywrightTimeoutError

# Поддержка запуска как модуля и как скрипта
try:
    from .base_adapter import MaterialData
    from .config import config_manager
except ImportError:
    from parser.base_adapter import MaterialData
    from parser.config import config_manager

logger = logging.getLogger(__name__)


class ErrorCodes:
    NAV_TIMEOUT = 'NAV_TIMEOUT'
    SELECTOR_NOT_FOUND = 'SELECTOR_NOT_FOUND'
    PRICE_PARSE_FAILED = 'PRICE_PARSE_FAILED'
    HTTP_403 = 'HTTP_403'
    HTTP_404 = 'HTTP_404'
    NETWORK_ERROR = 'NETWORK_ERROR'
    UNKNOWN = 'UNKNOWN'
    INTERNAL_RUNTIME_ERROR = 'INTERNAL_RUNTIME_ERROR'


@dataclass
class UrlTask:
    supplier_url_id: int
    url: str
    supplier_name: str
    material_type: Optional[str]


@dataclass
class UrlResult:
    supplier_url_id: int
    status: str  # done/failed/blocked
    error_code: Optional[str] = None
    error_message: Optional[str] = None
    parsed_at: Optional[datetime] = None
    material_data: Optional[MaterialData] = None


class InternalRuntimeError(Exception):
    pass


class AsyncQueueWorker:
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
        min_request_interval: float = 0.0,
        domain_limit: Optional[int] = None,
        full_scan: bool = False,
    ):
        self.supplier_name = supplier_name
        self.api_token = api_token
        self.api_callback = api_callback
        self.session_id = session_id
        self.batch_size = batch_size
        self.material_type = material_type
        self.reparse_days = reparse_days
        self.max_batches = max_batches
        self.concurrency = max(1, min(5, int(concurrency)))
        self.min_request_interval = max(0.0, float(min_request_interval))
        self.domain_limit = domain_limit or self.concurrency
        self.full_scan = full_scan

        # API base URL from callback if available
        if api_callback and api_base_url == "http://host.docker.internal:8000/api":
            parsed = urlparse(api_callback)
            self.api_base_url = f"{parsed.scheme}://{parsed.netloc}/api"
        else:
            self.api_base_url = api_base_url.rstrip('/')

        # Async state
        self.playwright = None
        self.browser = None
        self.context = None
        self.pages: List[Page] = []

        self.worker_id = f"{supplier_name}_{uuid.uuid4().hex[:8]}"

        self.stats = {
            'total_processed': 0,
            'successful': 0,
            'failed': 0,
            'blocked': 0,
            'batches_processed': 0,
        }

        self.current_batch_total: Optional[int] = None
        self.batch_processed: int = 0
        self.batch_timeout_count: int = 0

        # Metrics
        self.goto_times: List[float] = []
        self.parse_times: List[float] = []
        self.requests_blocked = 0
        self.requests_allowed = 0
        self.failed_by_code: Dict[str, int] = {}
        self.internal_errors_count = 0
        self.save_failed = False

        # Async helpers
        self.results_lock = asyncio.Lock()
        self.flush_lock = asyncio.Lock()
        self.buffer_lock = asyncio.Lock()
        self.results_buffer: List[Dict[str, Any]] = []
        self.flush_tasks: List[asyncio.Task] = []
        self.fail_fast = asyncio.Event()
        self.internal_error_message: Optional[str] = None
        self.callback_disabled = False
        self.callback_fatal_logged = False
        self._event_seq = 0

        # Progress throttling
        self._last_progress_ts = 0.0
        self._last_progress_count = 0
        self._progress_interval_sec = 20
        self._dynamic_delay = 0.0

        # Rate limiting
        self.rate_lock = asyncio.Lock()
        self.last_request_at: Dict[str, float] = {}
        self.domain_semaphores: Dict[str, asyncio.Semaphore] = {}

        # Config
        self.config = config_manager.load_supplier_config(supplier_name)
        self.selectors = self.config.get('selectors', {})
        delays = self.config.get('delays', {})
        self.nav_timeout_ms = int(delays.get('page_load_timeout', 15000))
        self.nav_retries = int(delays.get('page_load_retries', 1))

    async def setup(self) -> None:
        self.playwright = await async_playwright().start()
        self.browser = await self.playwright.chromium.launch(
            headless=True,
            args=[
                '--disable-dev-shm-usage',
                '--no-sandbox',
                '--disable-gpu',
            ],
        )
        self.context = await self.browser.new_context(
            viewport={"width": 1280, "height": 720},
            java_script_enabled=True,
            ignore_https_errors=True,
        )

        await self.context.route("**/*", self._handle_route)

        self.pages = [await self.context.new_page() for _ in range(self.concurrency)]

    async def teardown(self) -> None:
        try:
            for page in self.pages:
                await page.close()
        except Exception:
            pass

        if self.context:
            await self.context.close()
        if self.browser:
            await self.browser.close()
        if self.playwright:
            await self.playwright.stop()

    async def _handle_route(self, route, request):
        url = request.url
        blocked_patterns = [
            'google-analytics', 'googletagmanager', 'doubleclick', 'facebook',
            'vk.com/rtrg', 'mc.yandex', 'top-fwz1', '/tracker', '/analytics',
            '/pixel', 'jivosite', 'carrotquest', 'counters', 'beacon', 'collect',
        ]

        for pattern in blocked_patterns:
            if pattern in url:
                self.requests_blocked += 1
                await route.abort()
                return

        resource_type = request.resource_type
        if resource_type in ('image', 'font', 'media'):
            self.requests_blocked += 1
            await route.abort()
            return

        self.requests_allowed += 1
        await route.continue_()

    async def _rate_limit(self, url: str) -> None:
        effective_interval = self.min_request_interval + self._dynamic_delay
        if effective_interval <= 0:
            return

        domain = urlparse(url).netloc or 'default'
        now = time.perf_counter()

        async with self.rate_lock:
            last = self.last_request_at.get(domain, 0.0)
            wait_for = effective_interval - (now - last)
            if wait_for > 0:
                await asyncio.sleep(wait_for)
            self.last_request_at[domain] = time.perf_counter()

    def _get_domain_semaphore(self, url: str) -> asyncio.Semaphore:
        domain = urlparse(url).netloc or 'default'
        if domain not in self.domain_semaphores:
            self.domain_semaphores[domain] = asyncio.Semaphore(self.domain_limit)
        return self.domain_semaphores[domain]

    async def claim_batch(self, session: aiohttp.ClientSession) -> List[UrlTask]:
        payload = {
            'supplier_name': self.supplier_name,
            'material_type': self.material_type,
            'batch_size': self.batch_size,
            'worker_id': self.worker_id,
            'reparse_days': self.reparse_days,
        }
        async with session.post(
            f"{self.api_base_url}/parser/urls/claim",
            json=payload,
            headers=self._get_headers(),
            timeout=30,
        ) as resp:
            if resp.status != 200:
                text = await resp.text()
                print(f"[QUEUE] Ошибка claim: {resp.status} - {text[:200]}", file=sys.stderr, flush=True)
                return []
            data = await resp.json()

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

    async def report_results(self, session: aiohttp.ClientSession, results: List[UrlResult]) -> None:
        report_data = []
        for result in results:
            report_data.append({
                'supplier_url_id': result.supplier_url_id,
                'status': result.status,
                'error_code': result.error_code,
                'error_message': result.error_message,
                'parsed_at': result.parsed_at.isoformat() if result.parsed_at else None,
            })

        async with session.post(
            f"{self.api_base_url}/parser/urls/report",
            json={'results': report_data},
            headers=self._get_headers(),
            timeout=30,
        ) as resp:
            if resp.status != 200:
                text = await resp.text()
                print(f"[QUEUE] Ошибка report: {resp.status} - {text[:200]}", file=sys.stderr, flush=True)

    async def release_locks(self, session: aiohttp.ClientSession) -> None:
        try:
            await session.post(
                f"{self.api_base_url}/parser/urls/release",
                json={
                    'worker_id': self.worker_id,
                    'supplier_name': self.supplier_name,
                },
                headers=self._get_headers(),
                timeout=10,
            )
        except Exception as e:
            print(f"[QUEUE] Ошибка release: {e}", file=sys.stderr, flush=True)

    async def send_callback(self, session: aiohttp.ClientSession, payload: Dict[str, Any]) -> None:
        if self.callback_disabled:
            return
        if not self.api_callback or not self.session_id:
            return
        data = dict(payload)
        data['session_id'] = self.session_id
        data['token'] = self.api_token or ''
        data['timestamp'] = int(time.time())
        data['event_id'] = self._next_event_id(data.get('type', 'event'))

        headers = self._get_headers()
        if self.api_token:
            headers['Authorization'] = f"Bearer {self.api_token}"

        backoffs = [1, 3, 10, 30, 30]
        for attempt, delay in enumerate(backoffs, 1):
            try:
                async with session.post(
                    self.api_callback,
                    json=data,
                    headers=headers,
                    timeout=10,
                ) as resp:
                    body = await resp.text()
                    if resp.status in (401, 422):
                        if not self.callback_fatal_logged:
                            print(f"[CALLBACK_FATAL] {resp.status}: {body[:200]}", file=sys.stderr, flush=True)
                            self.callback_fatal_logged = True
                        self.callback_disabled = True
                        return
                    if resp.status >= 500:
                        if attempt < len(backoffs):
                            await asyncio.sleep(delay)
                            continue
                        self.callback_disabled = True
                        return
                    if resp.status >= 400:
                        print(f"[CALLBACK] Error {resp.status}: {body[:200]}", file=sys.stderr, flush=True)
                        return
                    return
            except asyncio.TimeoutError:
                if attempt < len(backoffs):
                    await asyncio.sleep(delay)
                    continue
                self.callback_disabled = True
                return
            except Exception as e:
                if attempt < len(backoffs):
                    await asyncio.sleep(delay)
                    continue
                self.callback_disabled = True
                return

    async def send_progress(self, session: aiohttp.ClientSession, force: bool = False) -> None:
        if not self.api_callback or not self.session_id:
            return
        total = self.current_batch_total or self.stats['total_processed']
        processed = self.batch_processed if self.current_batch_total is not None else self.stats['total_processed']
        processed = min(processed, total)

        now = time.time()
        if not force and (now - self._last_progress_ts) < self._progress_interval_sec:
            return
        self._last_progress_count = processed
        self._last_progress_ts = now

        await self.send_callback(session, {
            'type': 'progress',
            'payload': {
                'processed': processed,
                'total': total,
            },
        })

    async def send_total_urls(self, session: aiohttp.ClientSession, total: int) -> None:
        if not self.api_callback or not self.session_id:
            return
        await self.send_callback(session, {
            'type': 'total_urls',
            'payload': {'total': total},
        })

    async def send_finish(self, session: aiohttp.ClientSession, status: str, summary: Dict[str, Any]) -> None:
        if not self.api_callback or not self.session_id:
            return
        await self.send_callback(session, {
            'type': 'finish',
            'payload': {
                'status': status,
                'summary': summary,
            },
        })

    def _next_event_id(self, event_type: str) -> str:
        self._event_seq += 1
        return f"{self.session_id}:{event_type}:{self._event_seq}"

    def _get_headers(self) -> dict:
        headers = {'Content-Type': 'application/json'}
        if self.api_token:
            headers['X-Parser-Token'] = self.api_token
        return headers

    def _classify_error(self, error: Exception) -> (str, str):
        error_str = str(error).lower()

        if 'timeout' in error_str or 'timed out' in error_str:
            return ErrorCodes.NAV_TIMEOUT, 'Navigation timeout'
        if '403' in error_str or 'forbidden' in error_str:
            return ErrorCodes.HTTP_403, 'HTTP 403 Forbidden'
        if '404' in error_str or 'not found' in error_str:
            return ErrorCodes.HTTP_404, 'HTTP 404 Not Found'
        if 'selector' in error_str or 'not found' in error_str:
            return ErrorCodes.SELECTOR_NOT_FOUND, 'Selector not found'
        if 'price' in error_str:
            return ErrorCodes.PRICE_PARSE_FAILED, 'Price parse failed'
        if 'network' in error_str or 'connection' in error_str:
            return ErrorCodes.NETWORK_ERROR, 'Network error'
        return ErrorCodes.UNKNOWN, str(error)[:200]

    def _is_internal_runtime_error(self, error: Exception) -> bool:
        error_str = str(error).lower()
        markers = [
            'cannot switch to a different thread',
            'greenlet',
            'event loop is closed',
            'thread affinity',
        ]
        return any(m in error_str for m in markers)

    async def _extract_price(self, page: Page) -> Optional[float]:
        price = None
        price_meta_selector = self.selectors.get('price_meta', 'meta[itemprop="price"]')
        price_meta = await page.query_selector(price_meta_selector)

        if price_meta:
            price_content = await price_meta.get_attribute("content")
            if price_content:
                try:
                    price = float(price_content)
                except ValueError:
                    pass

        if price is None:
            price_text_selector = self.selectors.get('price_text', '.catalog-detail__price, .price')
            price_el = await page.query_selector(price_text_selector)
            if price_el:
                text = await price_el.inner_text()
                import re
                match = re.search(r"[\d\s]+", text.replace('\xa0', ' ').replace(' ', ''))
                if match:
                    try:
                        price = float(match.group())
                    except ValueError:
                        pass

        return price

    async def _extract_availability(self, page: Page) -> str:
        button_selector = self.selectors.get(
            'add_to_cart_button',
            'button.btn-default.to-cart, .catalog-detail__buy button'
        )
        add_to_cart_btn = await page.query_selector(button_selector)
        if add_to_cart_btn:
            btn_text = (await add_to_cart_btn.inner_text()).strip().lower()
            if "корзину" in btn_text or "купить" in btn_text:
                return "in_stock"
            if "заказ" in btn_text:
                return "on_order"
        return "on_order"

    def _determine_material_type_from_url(self, url: str) -> str:
        url_lower = url.lower()
        if 'kromka' in url_lower or 'edge' in url_lower:
            return 'edge'
        return self.config.get('default_type', 'plate')

    async def parse_product_page(self, page: Page, url: str) -> MaterialData:
        t_start = time.perf_counter()

        sem = self._get_domain_semaphore(url)
        async with sem:
            response = None
            last_error = None
            for attempt in range(self.nav_retries + 1):
                try:
                    response = await page.goto(
                        url,
                        wait_until='domcontentloaded',
                        timeout=self.nav_timeout_ms,
                    )
                    last_error = None
                    break
                except PlaywrightTimeoutError as e:
                    last_error = e
                    if attempt < self.nav_retries:
                        print(
                            f"[QUEUE] Retry goto (timeout) {attempt + 1}/{self.nav_retries} for {url}",
                            file=sys.stderr,
                            flush=True,
                        )
                        try:
                            await page.wait_for_timeout(200)
                        except Exception:
                            pass
                        continue
                except Exception as e:
                    last_error = e
                    break

            if last_error is not None and response is None:
                raise last_error

            if response is not None:
                status = response.status
                if status in (403, 404):
                    raise RuntimeError(f"HTTP {status}")

        t_goto = (time.perf_counter() - t_start) * 1000
        self.goto_times.append(t_goto)

        t_parse_start = time.perf_counter()

        product_indicators = [
            '[itemprop="name"]',
            '.catalog-detail',
            '.product-detail',
            'h1.catalog-detail__title',
        ]

        is_product_page = False
        for indicator in product_indicators:
            try:
                if await page.query_selector(indicator):
                    is_product_page = True
                    break
            except Exception:
                pass

        if not is_product_page:
            try:
                await page.wait_for_selector(product_indicators[0], state="attached", timeout=1500)
                is_product_page = True
            except Exception:
                raise RuntimeError(f"Not a product page (no product indicators): {url}")

        # Name
        t_name_start = time.perf_counter()
        name = None
        name_selectors = [
            'meta[itemprop="name"]',
            '[itemprop="name"]',
            'h1',
            '.catalog-detail__title',
            'meta[property="og:title"]',
        ]
        for selector in name_selectors:
            try:
                el = await page.query_selector(selector)
                if el:
                    if selector.startswith('meta'):
                        name = await el.get_attribute("content")
                    else:
                        name = (await el.inner_text()).strip()
                    if name:
                        break
            except Exception:
                pass
        if not name:
            raise RuntimeError(f"Product name not found on {url}")
        t_name = (time.perf_counter() - t_name_start) * 1000

        # Article
        t_article_start = time.perf_counter()
        article = None
        article_selectors = [
            '.catalog-detail__article.js-copy-article',
            '.catalog-detail__article',
            '[data-article]',
            '.article',
            '.sku',
        ]
        for selector in article_selectors:
            try:
                el = await page.query_selector(selector)
                if el:
                    article = await el.get_attribute('data-article') or (await el.inner_text()).strip()
                    if article:
                        break
            except Exception:
                pass

        if not article:
            import hashlib
            import re
            match = re.search(r'/(\d+)/?$', url)
            if match:
                article = f"SKM-{match.group(1)}"
            else:
                article = f"SKM-{hashlib.md5(url.encode()).hexdigest()[:8].upper()}"
            print(f"[PARSE] Generated article from URL: {article}", file=sys.stderr, flush=True)
        t_article = (time.perf_counter() - t_article_start) * 1000

        # Price
        t_price_start = time.perf_counter()
        price = None
        price_parsed_successfully = False
        try:
            price = await self._extract_price(page)
            price_parsed_successfully = price is not None and price >= 0
        except Exception as e:
            print(f"[PARSE] Не удалось извлечь цену: {e}", file=sys.stderr, flush=True)
            price = None
            price_parsed_successfully = False
        t_price = (time.perf_counter() - t_price_start) * 1000

        # Availability
        t_avail_start = time.perf_counter()
        availability_status = await self._extract_availability(page)
        t_avail = (time.perf_counter() - t_avail_start) * 1000

        material_type = self._determine_material_type_from_url(url)
        unit_mapping = self.config.get('material_unit_mapping', {})
        unit = unit_mapping.get(material_type, self.config.get('default_unit', 'м²'))

        t_parse = (time.perf_counter() - t_parse_start) * 1000
        self.parse_times.append(t_parse)

        print(
            f"[PARSE_TIMING] {url[:80]} | name:{t_name:.0f}ms article:{t_article:.0f}ms "
            f"price:{t_price:.0f}ms availability:{t_avail:.0f}ms total:{t_parse:.0f}ms",
            file=sys.stderr,
            flush=True,
        )

        return MaterialData(
            article=article,
            name=name,
            price_per_unit=price,
            type=material_type,
            unit=unit,
            availability_status=availability_status,
            source_url=url,
            parsed_at=datetime.utcnow(),
            price_parsed_successfully=price_parsed_successfully,
        )

    async def _maybe_flush(self, session: aiohttp.ClientSession, force: bool = False) -> None:
        if self.fail_fast.is_set():
            return
        batch = None
        async with self.buffer_lock:
            if force and self.results_buffer:
                batch = self.results_buffer[:]
                self.results_buffer = []
            elif len(self.results_buffer) >= 50:
                batch = self.results_buffer[:50]
                self.results_buffer = self.results_buffer[50:]

        if not batch:
            return

        async def do_flush(items: List[Dict[str, Any]]):
            async with self.flush_lock:
                materials = [item['material'].to_dict() for item in items]
                payload = {
                    'session_id': self.session_id,
                    'supplier': self.supplier_name,
                    'materials': materials,
                }
                async with session.post(
                    f"{self.api_base_url}/parser/materials/batch",
                    json=payload,
                    headers=self._get_headers(),
                    timeout=30,
                ) as resp:
                    if resp.status != 200:
                        text = await resp.text()
                        preview = materials[:2]
                        print(
                            f"[QUEUE] Batch save error {resp.status} on {self.api_base_url}/parser/materials/batch | "
                            f"batch_size={len(materials)} body={text[:500]} preview={str(preview)[:500]}",
                            file=sys.stderr,
                            flush=True,
                        )

                        failed_results = [
                            UrlResult(
                                supplier_url_id=item['supplier_url_id'],
                                status='failed',
                                error_code='SAVE_ERROR',
                                error_message=f"Batch save failed: {resp.status}",
                                parsed_at=item['parsed_at'],
                            )
                            for item in items
                        ]
                        await self.report_results(session, failed_results)
                        self.save_failed = True
                        self.fail_fast.set()
                        return

                    done_results = [
                        UrlResult(
                            supplier_url_id=item['supplier_url_id'],
                            status='done',
                            parsed_at=item['parsed_at'],
                        )
                        for item in items
                    ]
                    await self.report_results(session, done_results)

                    # Update success stats only after successful save
                    self.stats['successful'] += len(items)

        task = asyncio.create_task(do_flush(batch))
        self.flush_tasks.append(task)

    async def run(self) -> dict:
        print(f"[QUEUE] Запуск обработки очереди для {self.supplier_name}", file=sys.stderr, flush=True)
        start_time = time.perf_counter()

        async with aiohttp.ClientSession() as session:
            await self.setup()

            empty_batches = 0
            max_empty_batches = 3
            first_claim = True  # Для full-scan проверки

            try:
                while not self.fail_fast.is_set():
                    tasks = await self.claim_batch(session)
                    if not tasks:
                        # FULL-SCAN: первый claim пустой = ошибка протокола
                        if first_claim and self.full_scan:
                            print(f"[QUEUE] FATAL: FULL_SCAN_RESET_DID_NOT_CREATE_PENDING", file=sys.stderr, flush=True)
                            print(f"[QUEUE] Первый claim вернул 0 URL после reset — нечего парсить!", file=sys.stderr, flush=True)
                            self.fail_fast.set()
                            self.internal_error_message = "FULL_SCAN_RESET_DID_NOT_CREATE_PENDING"
                            break
                        
                        empty_batches += 1
                        print(f"[QUEUE] Пустая пачка #{empty_batches}", file=sys.stderr, flush=True)
                        if empty_batches >= max_empty_batches:
                            break
                        await asyncio.sleep(5)
                        continue

                    first_claim = False  # Первый claim успешен
                    empty_batches = 0
                    self.current_batch_total = len(tasks)
                    self.batch_processed = 0
                    self.batch_timeout_count = 0
                    await self.send_total_urls(session, self.current_batch_total)

                    queue_tasks: asyncio.Queue = asyncio.Queue()
                    for task in tasks:
                        await queue_tasks.put(task)

                    results: List[UrlResult] = []

                    async def worker(page: Page):
                        while not self.fail_fast.is_set():
                            try:
                                task = queue_tasks.get_nowait()
                            except asyncio.QueueEmpty:
                                break

                            try:
                                await self._rate_limit(task.url)
                                result = await self.process_single_url(page, task)
                                async with self.results_lock:
                                    results.append(result)
                                await self._after_result(session, result)
                            except InternalRuntimeError as e:
                                self.internal_errors_count += 1
                                self.internal_error_message = str(e)
                                self.fail_fast.set()
                            finally:
                                queue_tasks.task_done()

                    workers = [asyncio.create_task(worker(page)) for page in self.pages]
                    await queue_tasks.join()
                    for w in workers:
                        w.cancel()
                    await asyncio.gather(*workers, return_exceptions=True)

                    if self.fail_fast.is_set():
                        break

                    await self._maybe_flush(session, force=True)
                    if self.flush_tasks:
                        await asyncio.gather(*self.flush_tasks, return_exceptions=True)
                    await self.send_progress(session, force=True)
                    self._adjust_rate_limit_after_batch()
                    self.stats['batches_processed'] += 1

                    if self.max_batches and self.stats['batches_processed'] >= self.max_batches:
                        break

                if self.fail_fast.is_set():
                    await self.release_locks(session)
                if empty_batches >= max_empty_batches and not self.fail_fast.is_set() and not self.save_failed:
                    status = 'failed' if getattr(self, 'full_scan', False) else 'no_work'
                else:
                    status = 'completed' if not (self.fail_fast.is_set() or self.save_failed) else 'failed'
                summary = self._build_summary(start_time)
                summary['internal_error'] = self.fail_fast.is_set()
                await self.send_finish(session, status, summary)

                return self.stats

            finally:
                await self.teardown()

    async def _after_result(self, session: aiohttp.ClientSession, result: UrlResult) -> None:
        # Update stats
        self.stats['total_processed'] += 1
        self.batch_processed += 1
        if result.status == 'failed':
            self.stats['failed'] += 1
        elif result.status == 'blocked':
            self.stats['blocked'] += 1

        if result.error_code:
            self.failed_by_code[result.error_code] = self.failed_by_code.get(result.error_code, 0) + 1
            if result.error_code == ErrorCodes.NAV_TIMEOUT:
                self.batch_timeout_count += 1

        if result.material_data:
            async with self.buffer_lock:
                self.results_buffer.append({
                    'material': result.material_data,
                    'supplier_url_id': result.supplier_url_id,
                    'parsed_at': result.parsed_at,
                })
        else:
            await self.report_results(session, [result])

        if self.fail_fast.is_set():
            return

        await self._maybe_flush(session)
        await self.send_progress(session)

    def _adjust_rate_limit_after_batch(self) -> None:
        if not self.current_batch_total:
            return

        timeout_rate = self.batch_timeout_count / max(1, self.current_batch_total)

        if timeout_rate > 0.20:
            self._dynamic_delay = min(self._dynamic_delay + 0.3, 2.0)
            print(
                f"[RATE_LIMIT] High timeout_rate={timeout_rate:.0%}; "
                f"increasing dynamic delay to {self._dynamic_delay:.1f}s",
                file=sys.stderr,
                flush=True,
            )
        elif timeout_rate < 0.05 and self._dynamic_delay > 0:
            self._dynamic_delay = max(self._dynamic_delay - 0.2, 0.0)
            print(
                f"[RATE_LIMIT] Low timeout_rate={timeout_rate:.0%}; "
                f"decreasing dynamic delay to {self._dynamic_delay:.1f}s",
                file=sys.stderr,
                flush=True,
            )

    def _build_summary(self, start_time: float) -> Dict[str, Any]:
        def percentile(data: List[float], p: float) -> float:
            if not data:
                return 0.0
            sorted_data = sorted(data)
            k = (len(sorted_data) - 1) * p / 100
            f = int(k)
            c = f + 1 if f + 1 < len(sorted_data) else f
            return sorted_data[f] + (sorted_data[c] - sorted_data[f]) * (k - f)

        wall_time_ms = (time.perf_counter() - start_time) * 1000
        throughput = (self.stats['total_processed'] / (wall_time_ms / 60000)) if wall_time_ms > 0 else 0

        summary = {
            'concurrency': self.concurrency,
            'batch_size': self.batch_size,
            'claimed_count': self.current_batch_total,
            'success': self.stats['successful'],
            'failed_by_code': self.failed_by_code,
            'retried_count': self.stats['failed'],
            'goto_ms_p50': percentile(self.goto_times, 50),
            'goto_ms_p95': percentile(self.goto_times, 95),
            'parse_ms_p50': percentile(self.parse_times, 50),
            'parse_ms_p95': percentile(self.parse_times, 95),
            'wall_time_ms': wall_time_ms,
            'throughput_urls_per_min': throughput,
            'requests_blocked': self.requests_blocked,
            'requests_allowed': self.requests_allowed,
            'block_ratio': (self.requests_blocked / max(1, (self.requests_blocked + self.requests_allowed))) * 100,
            'internal_errors_count': self.internal_errors_count,
            'errors': self.stats['failed'] + self.stats['blocked'],
            'batch_total': self.current_batch_total,
        }

        print(
            f"[METRICS FINAL] concurrency={summary['concurrency']} batch_size={summary['batch_size']} claimed={summary['claimed_count']}",
            file=sys.stderr,
            flush=True,
        )
        print(
            f"[METRICS FINAL] success={summary['success']} failed_by_code={summary['failed_by_code']} retried={summary['retried_count']}",
            file=sys.stderr,
            flush=True,
        )
        print(
            f"[METRICS FINAL] goto_ms p50={summary['goto_ms_p50']:.0f} p95={summary['goto_ms_p95']:.0f} parse_ms p50={summary['parse_ms_p50']:.0f} p95={summary['parse_ms_p95']:.0f}",
            file=sys.stderr,
            flush=True,
        )
        print(
            f"[METRICS FINAL] wall_time_ms={summary['wall_time_ms']:.0f} throughput_urls_per_min={summary['throughput_urls_per_min']:.1f}",
            file=sys.stderr,
            flush=True,
        )
        print(
            f"[METRICS FINAL] requests_blocked={summary['requests_blocked']} allowed={summary['requests_allowed']} block_ratio={summary['block_ratio']:.1f}%",
            file=sys.stderr,
            flush=True,
        )
        print(
            f"[METRICS FINAL] internal_errors_count={summary['internal_errors_count']}",
            file=sys.stderr,
            flush=True,
        )

        return summary

    async def process_single_url(self, page: Page, task: UrlTask) -> UrlResult:
        print(f"[QUEUE] Парсинг: {task.url}", file=sys.stderr, flush=True)

        try:
            material = await self.parse_product_page(page, task.url)

            if material is None:
                return UrlResult(
                    supplier_url_id=task.supplier_url_id,
                    status='failed',
                    error_code=ErrorCodes.UNKNOWN,
                    error_message='parse_product_page returned None',
                    parsed_at=datetime.utcnow(),
                )

            if not material.price_parsed_successfully:
                return UrlResult(
                    supplier_url_id=task.supplier_url_id,
                    status='failed',
                    error_code=ErrorCodes.PRICE_PARSE_FAILED,
                    error_message='Price not parsed',
                    parsed_at=datetime.utcnow(),
                    material_data=material,
                )

            print(f"[QUEUE] ✓ {material.article}: {material.price_per_unit} ₽", file=sys.stderr, flush=True)

            return UrlResult(
                supplier_url_id=task.supplier_url_id,
                status='done',
                parsed_at=datetime.utcnow(),
                material_data=material,
            )

        except Exception as e:
            if self._is_internal_runtime_error(e):
                raise InternalRuntimeError(str(e))

            error_code, error_message = self._classify_error(e)
            print(f"[QUEUE] ✗ Ошибка: {error_code} - {error_message}", file=sys.stderr, flush=True)

            status = 'blocked' if error_code in (ErrorCodes.HTTP_403, ErrorCodes.HTTP_404) else 'failed'

            return UrlResult(
                supplier_url_id=task.supplier_url_id,
                status=status,
                error_code=error_code,
                error_message=error_message,
                parsed_at=datetime.utcnow(),
            )


async def run_queue_worker_async(
    supplier_name: str,
    batch_size: int = 20,
    material_type: Optional[str] = None,
    api_callback: Optional[str] = None,
    api_token: Optional[str] = None,
    session_id: Optional[int] = None,
    reparse_days: int = 7,
    max_batches: Optional[int] = None,
    concurrency: int = 3,
    min_request_interval: float = 0.0,
    full_scan: bool = False,
) -> dict:
    worker = AsyncQueueWorker(
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
        full_scan=full_scan,
    )
    return await worker.run()
