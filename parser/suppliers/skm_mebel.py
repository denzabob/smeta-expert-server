# parser/suppliers/skm_mebel.py

import re
import sys
import hashlib
from datetime import datetime
from pathlib import Path
from typing import List, Optional
from playwright.sync_api import sync_playwright, Page
from PIL import Image
import time

# Поддержка запуска как модуля и как скрипта
try:
    from ..base_adapter import SupplierAdapter, MaterialData
except ImportError:
    sys.path.insert(0, str(Path(__file__).parent.parent.parent))
    from parser.base_adapter import SupplierAdapter, MaterialData


class SkmMebelAdapter(SupplierAdapter):
    """Адаптер для парсинга SKM-Mebel."""
    
    def __init__(self, config: dict):
        super().__init__(config)
        self._playwright = None
        self._browser = None
        self._page = None
        self._context = None
        
        # Metrics for diagnostics
        self._requests_blocked = 0
        self._requests_allowed = 0
        self._urls_parsed = 0
        self._success_count = 0
        self._goto_times = []  # for percentiles
        self._parse_times = []
        self._errors_by_code = {}  # {'TIMEOUT': 3, 'NOT_PRODUCT': 5, ...}
        self._parse_slowest = []  # [(parse_ms, url), ...]
    
    def _handle_route(self, route):
        """Block heavy resources (images, fonts, media, trackers). NOT stylesheets."""
        url = route.request.url.lower()
        
        # Block by URL patterns
        blocked_patterns = [
            '.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg', '.ico',
            '.woff', '.woff2', '.ttf', '.eot', '.otf',
            '.mp4', '.webm', '.ogg', '.mp3', '.wav',
            'google-analytics', 'googletagmanager', 'yandex', 'metrika',
            'facebook', 'vk.com/rtrg', 'mc.yandex', 'top-fwz1',
            '/tracker', '/analytics', '/pixel', 'jivosite', 'carrotquest',
            'counters', 'beacon', 'collect',
        ]
        
        for pattern in blocked_patterns:
            if pattern in url:
                self._requests_blocked += 1
                route.abort()
                return
        
        # Block by resource_type: image, font, media
        # NOT stylesheet - it can break DOM structure detection
        resource_type = route.request.resource_type
        if resource_type in ('image', 'font', 'media'):
            self._requests_blocked += 1
            route.abort()
            return
        
        self._requests_allowed += 1
        route.continue_()
    
    def setup(self):
        """Инициализирует браузер перед парсингом."""
        self._playwright = sync_playwright().start()
        self._browser = self._playwright.chromium.launch(
            headless=True,
            args=[
                '--disable-dev-shm-usage',
                '--no-sandbox',
                '--disable-gpu',
            ]
        )
        
        # Create context - route will be set on context level
        self._context = self._browser.new_context(
            viewport={"width": 1280, "height": 720},  # Smaller viewport
            java_script_enabled=True,
            ignore_https_errors=True,
        )
        
        # Block resources on CONTEXT level (applies to all pages)
        self._context.route("**/*", self._handle_route)
        
        self._page = self._context.new_page()
    
    def teardown(self):
        """Закрывает браузер после завершения парсинга."""
        # Final metrics
        total_requests = self._requests_blocked + self._requests_allowed
        block_ratio = (self._requests_blocked / total_requests * 100) if total_requests > 0 else 0
        
        # Calculate percentiles
        def percentile(data, p):
            if not data:
                return 0
            sorted_data = sorted(data)
            k = (len(sorted_data) - 1) * p / 100
            f = int(k)
            c = f + 1 if f + 1 < len(sorted_data) else f
            return sorted_data[f] + (sorted_data[c] - sorted_data[f]) * (k - f)
        
        print(f"[METRICS FINAL] URLs: parsed={self._urls_parsed} success={self._success_count}", file=sys.stderr, flush=True)
        print(f"[METRICS FINAL] Errors by code: {self._errors_by_code}", file=sys.stderr, flush=True)
        print(f"[METRICS FINAL] Requests: blocked={self._requests_blocked} allowed={self._requests_allowed} block_ratio={block_ratio:.1f}%", 
              file=sys.stderr, flush=True)
        if self._goto_times:
            print(f"[METRICS FINAL] goto_ms: median={percentile(self._goto_times, 50):.0f} p95={percentile(self._goto_times, 95):.0f}", 
                  file=sys.stderr, flush=True)
        if self._parse_times:
            print(f"[METRICS FINAL] parse_ms: median={percentile(self._parse_times, 50):.0f} p95={percentile(self._parse_times, 95):.0f}", 
                  file=sys.stderr, flush=True)
        if self._parse_slowest:
            top_slowest = sorted(self._parse_slowest, key=lambda x: x[0], reverse=True)[:10]
            for idx, (ms, url) in enumerate(top_slowest, 1):
                print(f"[METRICS FINAL] parse_slowest_{idx}: {ms:.0f}ms {url}", file=sys.stderr, flush=True)
        
        if self._page:
            self._page.close()
        if hasattr(self, '_context') and self._context:
            self._context.close()
        if self._browser:
            self._browser.close()
        if self._playwright:
            self._playwright.stop()
    
    def _record_error(self, error_code: str):
        """Record error by code for metrics."""
        self._errors_by_code[error_code] = self._errors_by_code.get(error_code, 0) + 1
    
    def parse_product_page(self, url: str, take_screenshot: bool = True, page: Optional[Page] = None) -> MaterialData:
        """
        Извлекает данные с одной страницы товара SKM-Mebel.
        With timing metrics for diagnostics.
        """
        from datetime import datetime
        import time as time_module
        
        if not page:
            if not self._page:
                raise RuntimeError("Browser not initialized. Call setup() first.")
            page = self._page
        parsed_at = datetime.utcnow()
        self._urls_parsed += 1
        
        # === TIMING: page.goto ===
        t_start = time_module.perf_counter()
        try:
            page.goto(url, timeout=10000, wait_until='domcontentloaded')
        except Exception as e:
            self._record_error('GOTO_TIMEOUT')
            raise RuntimeError(f"Failed to load page {url}: {e}")
        t_goto = (time_module.perf_counter() - t_start) * 1000  # ms
        self._goto_times.append(t_goto)
        
        # === TIMING: parse start ===
        t_parse_start = time_module.perf_counter()

        # === FAST VALIDATION: Is this a product page? ===
        # Two-stage check: fast path (instant) + fallback (1500ms)
        product_indicators = [
            '[itemprop="name"]',
            '.catalog-detail',
            '.product-detail',
            'h1.catalog-detail__title',
        ]
        
        is_product_page = False
        
        # Stage 1: Instant check (no waiting)
        for indicator in product_indicators:
            try:
                if page.query_selector(indicator):
                    is_product_page = True
                    break
            except:
                pass
        
        # Stage 2: Fallback - wait up to 1500ms for JS hydration
        if not is_product_page:
            try:
                page.wait_for_selector(product_indicators[0], state="attached", timeout=1500)
                is_product_page = True
            except:
                self._record_error('NOT_PRODUCT_PAGE')
                raise RuntimeError(f"Not a product page (no product indicators): {url}")

        # === 1. Название (FALLBACK SELECTORS) — NO WAITING ===
        t_name_start = time_module.perf_counter()
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
                el = page.query_selector(selector)
                if el:
                    if selector.startswith('meta'):
                        name = el.get_attribute("content")
                    else:
                        name = el.inner_text().strip()
                    if name:
                        break
            except:
                pass
        
        if not name:
            raise RuntimeError(f"Product name not found on {url}")
        t_name = (time_module.perf_counter() - t_name_start) * 1000

        # === 2. Артикул (FALLBACK SELECTORS) ===
        t_article_start = time_module.perf_counter()
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
                el = page.query_selector(selector)
                if el:
                    article = el.get_attribute('data-article') or el.inner_text().strip()
                    if article:
                        break
            except:
                pass
        
        # Fallback: extract from URL or generate hash
        if not article:
            # Try to extract from URL
            import re
            match = re.search(r'/(\d+)/?$', url)
            if match:
                article = f"SKM-{match.group(1)}"
            else:
                # Generate from URL hash
                article = f"SKM-{hashlib.md5(url.encode()).hexdigest()[:8].upper()}"
            print(f"[PARSE] Generated article from URL: {article}", file=sys.stderr, flush=True)
        t_article = (time_module.perf_counter() - t_article_start) * 1000

        # === 3. Цена (может не распарситься) ===
        t_price_start = time_module.perf_counter()
        price = None
        price_parsed_successfully = False
        try:
            price = self._extract_price(page)
            price_parsed_successfully = price is not None and price >= 0
        except (ValueError, Exception) as e:
            print(f"[PARSE] Не удалось извлечь цену: {e}", file=sys.stderr, flush=True)
            price = None
            price_parsed_successfully = False
        t_price = (time_module.perf_counter() - t_price_start) * 1000

        # === 4. Статус наличия ===
        t_avail_start = time_module.perf_counter()
        availability_status = self.extract_availability_status(page)
        t_avail = (time_module.perf_counter() - t_avail_start) * 1000

        # === 5. Тип и единица (из конфига или по умолчанию) ===
        material_type = self._determine_material_type_from_url(url)
        unit_mapping = self.config.get('material_unit_mapping', {})
        unit = unit_mapping.get(material_type, self.config.get('default_unit', 'м²'))

        # === 6. Скриншот ===
        screenshot_path = None
        if take_screenshot:
            screenshot_path = self._take_screenshot(page, url)

        # === TIMING END ===
        t_parse = (time_module.perf_counter() - t_parse_start) * 1000  # ms
        self._parse_times.append(t_parse)
        self._success_count += 1
        self._parse_slowest.append((t_parse, url))

        print(
            f"[PARSE_TIMING] {url[:80]} | name:{t_name:.0f}ms article:{t_article:.0f}ms "
            f"price:{t_price:.0f}ms availability:{t_avail:.0f}ms total:{t_parse:.0f}ms",
            file=sys.stderr,
            flush=True,
        )
        
        # Log metrics every 10 URLs
        if self._urls_parsed % 10 == 0:
            success_rate = (self._success_count / self._urls_parsed * 100) if self._urls_parsed > 0 else 0
            print(f"[METRICS] URLs:{self._urls_parsed} success:{self._success_count} ({success_rate:.0f}%) | goto:{t_goto:.0f}ms parse:{t_parse:.0f}ms", 
                  file=sys.stderr, flush=True)

        return MaterialData(
            article=article,
            name=name,
            price_per_unit=price,
            type=material_type,
            unit=unit,
            availability_status=availability_status,
            source_url=url,
            screenshot_path=screenshot_path,
            parsed_at=parsed_at,
            price_parsed_successfully=price_parsed_successfully
        )
    
    def parse_category(self, category_url: str) -> List[str]:
        """
        Извлекает список URL товаров из категории.
        
        Args:
            category_url: URL категории
            
        Returns:
            List[str]: Список URL товаров
        """
        # TODO: Реализовать парсинг категорий с учетом пагинации
        # Пока возвращаем пустой список
        return []
    
    def extract_availability_status(self, page: Page) -> str:
        """
        Определяет статус наличия товара.
        
        Args:
            page: Объект страницы Playwright
            
        Returns:
            str: Статус наличия
        """
        button_selector = self.selectors.get(
            'add_to_cart_button',
            'button.btn-default.to-cart, .catalog-detail__buy button'
        )
        add_to_cart_btn = page.query_selector(button_selector)
        
        if add_to_cart_btn:
            btn_text = add_to_cart_btn.inner_text().strip().lower()
            
            if "корзину" in btn_text or "купить" in btn_text:
                return "in_stock"
            elif "заказ" in btn_text:
                return "on_order"
        
        return "on_order"
    
    def _extract_price(self, page: Page) -> Optional[float]:
        """
        Извлекает цену со страницы.
        
        Returns:
            Optional[float]: Цена или None если не удалось извлечь
        """
        price = None
        
        # Сначала пробуем meta-тег
        price_meta_selector = self.selectors.get('price_meta', 'meta[itemprop="price"]')
        price_meta = page.query_selector(price_meta_selector)
        
        if price_meta and (price_content := price_meta.get_attribute("content")):
            try:
                price = float(price_content)
            except ValueError:
                pass
        
        # Fallback: парсим из видимого текста
        if price is None:
            price_text_selector = self.selectors.get('price_text', '.catalog-detail__price, .price')
            price_el = page.query_selector(price_text_selector)
            
            if price_el:
                text = price_el.inner_text()
                match = re.search(r"[\d\s]+", text.replace('\xa0', ' ').replace(' ', ''))
                if match:
                    try:
                        price = float(match.group())
                    except ValueError:
                        pass
        
        # Возвращаем None вместо исключения — API обработает это корректно
        return price
    
    def _take_screenshot(self, page: Page, url: str) -> str:
        """Создаёт скриншот страницы и возвращает путь к файлу."""
        url_hash = hashlib.md5(url.encode()).hexdigest()[:12]
        date_str = datetime.now().strftime("%Y-%m-%d")
        timestamp = datetime.now().strftime("%H%M%S")
        filename_base = f"{self.supplier_name}_{url_hash}_{timestamp}"

        # Путь: storage/app/public/screenshots/skm_mebel/
        project_root = Path(__file__).parent.parent.parent
        dir_path = project_root / "storage" / "app" / "public" / "screenshots" / self.supplier_name / date_str
        dir_path.mkdir(parents=True, exist_ok=True)

        temp_png = dir_path / f"{filename_base}.png"
        webp_path = dir_path / f"{filename_base}.webp"

        try:
            page.screenshot(path=str(temp_png), full_page=False, timeout=10000)
        except Exception as e:
            raise RuntimeError(f"Failed to take screenshot: {e}")

        try:
            with Image.open(temp_png) as img:
                img.save(webp_path, "WEBP", quality=85, method=6)
            temp_png.unlink(missing_ok=True)
        except Exception as e:
            temp_png.unlink(missing_ok=True)
            raise RuntimeError(f"Failed to convert screenshot to WebP: {e}")

        # Возвращаем путь относительно public/storage
        return f"screenshots/{self.supplier_name}/{date_str}/{filename_base}.webp"
    # ===== Методы для сбора URL (collect_urls) =====
    
    def _goto_page(self, url: str, timeout: int):
        """Переходит на страницу в браузере."""
        if not self._page:
            raise RuntimeError("Browser not initialized. Call setup() first.")
        try:
            self._page.goto(url, timeout=timeout * 1000, wait_until='domcontentloaded')
        except Exception as e:
            raise RuntimeError(f"Failed to load page {url}: {e}")
    
    def _collect_elements(self, selector: str):
        """Собирает элементы по CSS-селектору."""
        if not self._page:
            raise RuntimeError("Browser not initialized. Call setup() first.")
        try:
            return self._page.query_selector_all(selector)
        except Exception as e:
            print(f"[COLLECT] Error collecting elements {selector}: {e}", file=sys.stderr, flush=True)
            return []
    
    def _find_element(self, selector: str):
        """Находит первый элемент по селектору."""
        if not self._page:
            raise RuntimeError("Browser not initialized. Call setup() first.")
        try:
            return self._page.query_selector(selector)
        except Exception as e:
            print(f"[COLLECT] Error finding element {selector}: {e}", file=sys.stderr, flush=True)
            return None
    
    def _scroll_to_bottom(self):
        """Скроллит страницу к конца."""
        if not self._page:
            raise RuntimeError("Browser not initialized. Call setup() first.")
        try:
            self._page.evaluate("window.scrollTo(0, document.body.scrollHeight)")
        except Exception as e:
            print(f"[COLLECT] Error scrolling: {e}", file=sys.stderr, flush=True)

    def _determine_material_type_from_url(self, url: str) -> str:
        """
        Определяет тип материала по URL товара.
        
        Args:
            url: URL товара
            
        Returns:
            str: Тип материала из mapping или default_type
        """
        url_lower = url.lower()
        material_types = self.config.get('material_types', [])
        material_type_mapping = self.config.get('material_type_mapping', {})
        
        # Ищем ключевое слово материала в URL
        for material in material_types:
            if material.lower() in url_lower:
                # Если есть mapping, используем его, иначе используем сам материал
                return material_type_mapping.get(material.lower(), self.config.get('default_type', 'plate'))
        
        # По умолчанию plate
        return self.config.get('default_type', 'plate')