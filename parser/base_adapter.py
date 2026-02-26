# parser/base_adapter.py

from abc import ABC, abstractmethod
from typing import Dict, List, Optional
from dataclasses import dataclass, field
from datetime import datetime
from urllib.parse import urljoin, urlparse, parse_qs, urlencode
import hashlib
import sys
import time


@dataclass
class MaterialData:
    """Структура данных материала, возвращаемая адаптером."""
    article: str
    name: str
    price_per_unit: Optional[float]  # None если цена не распарсилась
    type: str  # 'plate', 'edge', 'fitting'
    unit: str  # 'м²', 'м.п.', 'шт'
    availability_status: str  # 'in_stock', 'on_order', etc.
    source_url: str
    screenshot_path: Optional[str] = None
    material_tag: Optional[str] = None  # 'ldsp', 'mdf', 'pvc'
    thickness: Optional[float] = None
    supplier_id: Optional[int] = None
    currency: Optional[str] = None
    parsed_at: Optional[datetime] = field(default_factory=datetime.utcnow)
    price_parsed_successfully: bool = True  # False если цена не извлеклась
    
    def to_dict(self) -> dict:
        """Преобразует в словарь для отправки в API."""
        result = {
            'article': self.article,
            'name': self.name,
            'type': self.type,
            'unit': self.unit,
            'availability_status': self.availability_status,
            'source_url': self.source_url,
            'screenshot_path': self.screenshot_path,
            'origin': 'parser',
            'parsed_at': self.parsed_at.isoformat() if self.parsed_at else None,
        }
        
        # price_per_unit передаём только если цена успешно распарсилась
        if self.price_parsed_successfully and self.price_per_unit is not None:
            result['price_per_unit'] = self.price_per_unit
        else:
            result['price_per_unit'] = None  # Явно указываем что цена не извлеклась
        
        # Опциональные поля
        if self.supplier_id is not None:
            result['supplier_id'] = self.supplier_id
        if self.currency:
            result['currency'] = self.currency
            
        return result


@dataclass
class ParseResult:
    """Результат работы адаптера."""
    success: bool
    materials: List[MaterialData]
    errors: List[str]
    pages_processed: int
    items_count: int


class SupplierAdapter(ABC):
    """
    Абстрактный базовый класс для адаптеров поставщиков.
    Каждый поставщик должен реализовать этот интерфейс.
    """
    
    def __init__(self, config: dict):
        """
        Инициализация адаптера.
        
        Args:
            config: Конфигурация поставщика из JSON файла
        """
        self.config = config
        self.supplier_name = config.get('name', 'unknown')
        self.base_url = config.get('base_url', '')
        self.selectors = config.get('selectors', {})
        self.delays = config.get('delays', {})
        self.use_proxy = config.get('use_proxy', False)
        self.log_callback = None
        self.filter_stats = {
            'checked': 0,
            'passed': 0,
            'excluded': 0,
            'no_keywords': 0,
            'matched_keyword': 0,
            'no_match': 0,
        }
        self.filter_debug_log_every = 200
        self.filter_debug_sample_limit = 10
        self._filter_debug_samples = 0
        self._last_filter_reason = None
        self._last_filter_keyword = None

    def _emit_log(self, level: str, message: str, details: Optional[dict] = None) -> None:
        callback = getattr(self, 'log_callback', None)
        if callable(callback):
            callback(level, message, details)
    
    def setup(self):
        """Инициализация ресурсов адаптера (браузер, сессии и т.д.). Вызывается перед началом парсинга."""
        pass
    
    def teardown(self):
        """Освобождение ресурсов адаптера. Вызывается после завершения парсинга."""
        pass

    def create_page(self):
        """Создать новую страницу для параллельного парсинга (если поддерживается)."""
        context = getattr(self, '_context', None)
        if context:
            return context.new_page()
        return None
    
    def collect_urls(self) -> List[str]:
        """
        Динамически собирает URL товаров из каталога поставщика.
        Вызывается только в фазе Discovery (отдельная команда/джоба).
        
        Реализует универсальный обход на основе конфигурации:
        - BFS по категориям (если есть иерархия)
        - Пагинация по товарам
        - Фильтрация по ключевым словам
        - Поддержка infinite scroll
        
        Returns:
            List[str]: Список URL товаров
        """
        if not self.config.get('collect_urls', False):
            return []
        
        url_config = self.config.get('url_collection', {})
        if not url_config:
            return []
        
        catalog_url = self.config.get('catalog_base_url', '')
        if not catalog_url:
            print(f"[COLLECT] ERROR: catalog_base_url не установлен в конфиге", file=sys.stderr, flush=True)
            return []
        
        product_urls = set()
        max_urls = url_config.get('max_urls', 500)
        max_depth = url_config.get('max_depth', 2)
        filter_keywords = url_config.get('filter_keywords', [])
        exclude_keywords = url_config.get('exclude_keywords', [])
        request_delay = url_config.get('request_delay', 2.0)
        timeout = url_config.get('timeout', 30)
        infinite_scroll = url_config.get('infinite_scroll', False)
        max_time_seconds = url_config.get('max_collect_time_seconds')
        soft_exit_seconds = url_config.get('soft_exit_seconds', 10)

        start_time = time.time()
        page_fingerprints = {}
        page_fingerprint_repeats = {}
        zero_unique_streak = {}

        if not hasattr(self, 'page_duplicates_dropped'):
            self.page_duplicates_dropped = 0
        if not hasattr(self, 'global_duplicates_dropped'):
            self.global_duplicates_dropped = 0

        def _normalize_for_fingerprint(url: str) -> str:
            try:
                parsed = urlparse(url)
                params_to_remove = {'utm_source', 'utm_medium', 'utm_campaign', 'utm_term',
                                   'utm_content', 'fbclid', 'gclid', 'yclid', '_ga'}
                if parsed.query:
                    params = parse_qs(parsed.query, keep_blank_values=True)
                    filtered_params = {k: v for k, v in params.items()
                                       if k.lower() not in params_to_remove}
                    new_query = urlencode(filtered_params, doseq=True) if filtered_params else ''
                else:
                    new_query = ''
                path = parsed.path.rstrip('/') if parsed.path != '/' else '/'
                normalized = f"{parsed.scheme}://{parsed.netloc}{path}"
                if new_query:
                    normalized += f"?{new_query}"
                return normalized
            except Exception:
                return url

        def _category_key(url: str) -> str:
            try:
                parsed = urlparse(url)
                params = parse_qs(parsed.query, keep_blank_values=True)
                pagination_param = url_config.get('pagination_param')
                if pagination_param and pagination_param in params:
                    params.pop(pagination_param, None)
                new_query = urlencode(params, doseq=True) if params else ''
                base = f"{parsed.scheme}://{parsed.netloc}{parsed.path}".rstrip('/')
                return f"{base}?{new_query}" if new_query else base
            except Exception:
                return url
        
        queue = [(catalog_url, 0)]  # (url, depth)
        visited_pages = set()
        
        print(f"[COLLECT] Начинаю сбор URL с {catalog_url}", file=sys.stderr, flush=True)
        print(
            f"[COLLECT] URL фильтры: filter_keywords={filter_keywords or []} exclude_keywords={exclude_keywords or []}",
            file=sys.stderr,
            flush=True,
        )
        self._emit_log('info', 'Collect URLs started', {
            'catalog_url': catalog_url,
            'filter_keywords': filter_keywords or [],
            'exclude_keywords': exclude_keywords or [],
            'max_urls': max_urls,
            'max_depth': max_depth,
            'timeout': timeout,
            'infinite_scroll': bool(infinite_scroll),
        })
        
        try:
            while queue and len(product_urls) < max_urls:
                current_url, current_depth = queue.pop(0)
                
                if current_url in visited_pages or current_depth > max_depth:
                    continue
                
                visited_pages.add(current_url)
                
                try:
                    if max_time_seconds is not None:
                        elapsed = time.time() - start_time
                        if elapsed >= max_time_seconds:
                            print(f"[COLLECT] TIME_LIMIT_REACHED before request ({int(elapsed)}s >= {max_time_seconds}s)", file=sys.stderr, flush=True)
                            self.collect_stop_reason = 'TIME_LIMIT_REACHED'
                            break
                        if max_time_seconds - elapsed <= soft_exit_seconds:
                            print(f"[COLLECT] SOFT_EXIT: remaining {max_time_seconds - elapsed:.1f}s", file=sys.stderr, flush=True)
                            self.collect_stop_reason = 'SOFT_EXIT_TIME_LIMIT'
                            break

                    # Переходим на страницу
                    print(f"[COLLECT] Загружаю (depth={current_depth}): {current_url}", file=sys.stderr, flush=True)
                    self._goto_page(current_url, timeout)
                    time.sleep(request_delay)
                    
                    # Даём странице время загрузиться и JavaScript выполниться
                    try:
                        self._page.wait_for_selector(url_config.get('product_selector'), timeout=5000)
                    except:
                        print(f"[COLLECT] Product selector not found in time, continuing anyway", file=sys.stderr, flush=True)
                    
                    # Собираем товары с этой страницы
                    product_selector = url_config.get('product_selector')
                    print(f"[COLLECT] Looking for products with selector: {product_selector}", file=sys.stderr, flush=True)
                    products_found_on_page = 0
                    if product_selector:
                        page_products = self._collect_elements(product_selector)
                        products_found_on_page = len(page_products)
                        print(f"[COLLECT] Found {products_found_on_page} products", file=sys.stderr, flush=True)
                        
                        # Если страница пустая - прекращаем пагинацию
                        if products_found_on_page == 0:
                            print(f"[COLLECT] Страница пустая, пропускаем дальнейшую пагинацию", file=sys.stderr, flush=True)
                            # Удаляем из очереди все остальные страницы этой категории
                            pagination_param = url_config.get('pagination_param')
                            if pagination_param:
                                base_url_without_params = current_url.split('?')[0]
                                queue = [(url, depth) for url, depth in queue if base_url_without_params not in url]
                        
                        page_urls = []
                        page_filter_passed = 0
                        page_filter_no_keywords = 0
                        page_filter_excluded = 0
                        page_filter_no_match = 0
                        for product_element in page_products:
                            try:
                                href = product_element.get_attribute('href')
                                if href:
                                    abs_url = urljoin(self.config.get('base_url', current_url), href)
                                    if self._filter_url(abs_url, filter_keywords):
                                        page_urls.append(_normalize_for_fingerprint(abs_url))
                                        if self._last_filter_reason == 'no_keywords':
                                            page_filter_no_keywords += 1
                                        else:
                                            page_filter_passed += 1
                                    else:
                                        if self._last_filter_reason == 'excluded':
                                            page_filter_excluded += 1
                                        else:
                                            page_filter_no_match += 1
                            except Exception as e:
                                print(f"[COLLECT] Ошибка извлечения href: {e}", file=sys.stderr, flush=True)
                        if products_found_on_page > 0:
                            print(
                                f"[COLLECT] Фильтрация на странице: passed={page_filter_passed + page_filter_no_keywords} "
                                f"(matched={page_filter_passed}, no_keywords={page_filter_no_keywords}), "
                                f"excluded={page_filter_excluded}, no_match={page_filter_no_match}",
                                file=sys.stderr,
                                flush=True,
                            )
                            self._emit_log('info', 'Filter summary for page', {
                                'page_url': current_url,
                                'products_found': products_found_on_page,
                                'passed_total': page_filter_passed + page_filter_no_keywords,
                                'passed_matched': page_filter_passed,
                                'passed_no_keywords': page_filter_no_keywords,
                                'excluded': page_filter_excluded,
                                'no_match': page_filter_no_match,
                            })

                        # Dedup within page and against global set
                        page_urls_unique = list(dict.fromkeys(page_urls))
                        new_urls = [u for u in page_urls_unique if u not in product_urls]
                        page_dupes = len(page_urls) - len(page_urls_unique)
                        global_dupes = len(page_urls_unique) - len(new_urls)

                        if page_dupes > 0:
                            self.page_duplicates_dropped += page_dupes
                            print(f"[COLLECT] Page duplicates dropped: {page_dupes}", file=sys.stderr, flush=True)

                        if global_dupes > 0:
                            self.global_duplicates_dropped += global_dupes

                        for abs_url in new_urls:
                            product_urls.add(abs_url)
                            if len(product_urls) >= max_urls:
                                break

                        # Pagination loop detection by fingerprint
                        category_key = _category_key(current_url)
                        fingerprint_source = sorted(page_urls_unique)
                        payload = "\n".join(fingerprint_source[:200])
                        fingerprint = hashlib.sha1(payload.encode("utf-8")).hexdigest()
                        page_fingerprints.setdefault(category_key, set())

                        if fingerprint in page_fingerprints[category_key]:
                            repeat_key = (category_key, fingerprint)
                            page_fingerprint_repeats[repeat_key] = page_fingerprint_repeats.get(repeat_key, 0) + 1
                            if page_fingerprint_repeats[repeat_key] >= 3:
                                print(f"[COLLECT] PAGINATION_LOOP_DETECTED for {category_key}", file=sys.stderr, flush=True)
                                self.collect_stop_reason = 'PAGINATION_LOOP_DETECTED'
                                # Remove queued pages of same category
                                queue = [(url, depth) for url, depth in queue if _category_key(url) != category_key]
                                continue
                        else:
                            page_fingerprints[category_key].add(fingerprint)

                        # Stop if no new uniques twice in a row
                        unique_added = len(new_urls)
                        zero_unique_streak.setdefault(category_key, 0)
                        if unique_added == 0:
                            zero_unique_streak[category_key] += 1
                            if zero_unique_streak[category_key] >= 2:
                                print(f"[COLLECT] Stop category: 2x0 unique подряд (page={current_url})", file=sys.stderr, flush=True)
                                self.collect_stop_reason = 'NO_NEW_UNIQUE_URLS'
                                queue = [(url, depth) for url, depth in queue if _category_key(url) != category_key]
                        else:
                            zero_unique_streak[category_key] = 0
                    
                    # Собираем подкатегории (если есть и depth < max_depth)
                    if current_depth < max_depth:
                        subcategory_selector = url_config.get('subcategory_selector')
                        if subcategory_selector:
                            subcategories = self._collect_elements(subcategory_selector)
                            allowed_categories = self.config.get('allowed_categories', [])
                            
                            for subcat_element in subcategories:
                                try:
                                    href = subcat_element.get_attribute('href')
                                    if href:
                                        abs_url = urljoin(self.config.get('base_url', current_url), href)
                                        
                                        # Фильтруем по списку разрешенных категорий
                                        if allowed_categories:
                                            # Извлекаем имя категории из URL: /category/category_name/
                                            match = abs_url.split('/category/')
                                            if len(match) > 1:
                                                category_name = match[1].split('/')[0]
                                                if category_name not in allowed_categories:
                                                    print(f"[COLLECT] Пропускаю категорию (не в allowed_categories): {category_name}", file=sys.stderr, flush=True)
                                                    continue
                                            else:
                                                # Если это не категория, пропускаем
                                                continue
                                        
                                        if abs_url not in visited_pages:
                                            queue.append((abs_url, current_depth + 1))
                                except Exception as e:
                                    print(f"[COLLECT] Ошибка извлечения подкатегории: {e}", file=sys.stderr, flush=True)
                    
                    # Обработка infinite scroll
                    if infinite_scroll and len(product_urls) < max_urls:
                        print(f"[COLLECT] Обработка infinite scroll...", file=sys.stderr, flush=True)
                        product_urls.update(self._scroll_and_collect(product_selector, filter_keywords, max_urls - len(product_urls)))
                    
                    # URL-based пагинация (PAGEN_1=2, PAGEN_1=3 и т.д.)
                    pagination_param = url_config.get('pagination_param')
                    pagination_max_pages = url_config.get('pagination_max_pages', 10)
                    if pagination_param and len(product_urls) < max_urls:
                        # Проверяем, какая текущая страница
                        if pagination_param in current_url:
                            # Уже не первая страница, пропускаем
                            pass
                        else:
                            # Первая страница - добавляем в очередь страницы 2, 3, 4...
                            print(f"[COLLECT] Добавляю страницы пагинации (param={pagination_param}, max={pagination_max_pages})", file=sys.stderr, flush=True)
                            for page_num in range(2, pagination_max_pages + 1):
                                if len(product_urls) >= max_urls:
                                    break
                                separator = '&' if '?' in current_url else '?'
                                next_page_url = f"{current_url}{separator}{pagination_param}={page_num}"
                                if next_page_url not in visited_pages:
                                    queue.append((next_page_url, current_depth))
                    
                    # Переход на следующую страницу (пагинация через кнопку)
                    next_selector = url_config.get('pagination_next_selector')
                    if next_selector and len(product_urls) < max_urls and not pagination_param:
                        try:
                            next_button = self._find_element(next_selector)
                            if next_button:
                                next_href = next_button.get_attribute('href')
                                if next_href:
                                    next_url = urljoin(self.config.get('base_url', current_url), next_href)
                                    if next_url not in visited_pages:
                                        queue.append((next_url, current_depth))
                        except Exception as e:
                            print(f"[COLLECT] Пагинация не найдена: {e}", file=sys.stderr, flush=True)
                
                except Exception as e:
                    print(f"[COLLECT] Ошибка загрузки {current_url}: {e}", file=sys.stderr, flush=True)
        
        except Exception as e:
            print(f"[COLLECT] Критическая ошибка сбора: {e}", file=sys.stderr, flush=True)
            import traceback
            traceback.print_exc(file=sys.stderr)
        
        print(f"[COLLECT] Собрано {len(product_urls)} URL товаров", file=sys.stderr, flush=True)
        self.product_urls = list(product_urls)
        return self.product_urls
    
    def get_collected_urls(self) -> List[str]:
        """
        Возвращает собранные URL товаров.
        
        Returns:
            List[str]: Список URL товаров
        """
        return getattr(self, 'product_urls', [])
    
    def _goto_page(self, url: str, timeout: int):
        """Переходит на страницу. Должна быть переопределена в конкретных адаптерах."""
        raise NotImplementedError("_goto_page должна быть переопределена в конкретном адаптере")
    
    def _collect_elements(self, selector: str):
        """Собирает элементы по селектору. Должна быть переопределена в конкретных адаптерах."""
        raise NotImplementedError("_collect_elements должна быть переопределена в конкретном адаптере")
    
    def _find_element(self, selector: str):
        """Находит первый элемент по селектору. Должна быть переопределена в конкретных адаптерах."""
        raise NotImplementedError("_find_element должна быть переопределена в конкретном адаптере")
    
    def _filter_url(self, url: str, keywords: List[str]) -> bool:
        """
        Фильтрует URL по ключевым словам.
        
        Args:
            url: URL для проверки
            keywords: Список ключевых слов (case-insensitive)
            
        Returns:
            bool: True если URL проходит фильтр
        """
        url_lower = url.lower()

        self.filter_stats['checked'] += 1
        self._last_filter_reason = None
        self._last_filter_keyword = None

        # Сначала проверяем exclude_keywords
        exclude_keywords = self.config.get('url_collection', {}).get('exclude_keywords', [])
        if exclude_keywords:
            for keyword in exclude_keywords:
                if keyword.lower() in url_lower:
                    self.filter_stats['excluded'] += 1
                    self._last_filter_reason = 'excluded'
                    self._last_filter_keyword = keyword
                    self._maybe_log_filter_sample(url, passed=False)
                    return False

        # Если нет keywords, пропускаем все
        if not keywords:
            self.filter_stats['passed'] += 1
            self.filter_stats['no_keywords'] += 1
            self._last_filter_reason = 'no_keywords'
            self._maybe_log_filter_sample(url, passed=True)
            return True

        # Проверяем наличие хотя бы одного keyword
        for keyword in keywords:
            if keyword.lower() in url_lower:
                self.filter_stats['passed'] += 1
                self.filter_stats['matched_keyword'] += 1
                self._last_filter_reason = 'matched'
                self._last_filter_keyword = keyword
                self._maybe_log_filter_sample(url, passed=True)
                return True

        self.filter_stats['no_match'] += 1
        self._last_filter_reason = 'no_match'
        self._maybe_log_filter_sample(url, passed=False)
        return False

    def _maybe_log_filter_sample(self, url: str, passed: bool) -> None:
        """Логирует примеры работы фильтра и периодические сводки."""
        reason = self._last_filter_reason or 'unknown'
        keyword = self._last_filter_keyword or ''

        if self._filter_debug_samples < self.filter_debug_sample_limit:
            print(
                f"[COLLECT] FILTER sample: passed={passed} reason={reason} keyword={keyword} url={url}",
                file=sys.stderr,
                flush=True,
            )
            self._emit_log('info', 'Filter sample', {
                'passed': passed,
                'reason': reason,
                'keyword': keyword,
                'url': url,
            })
            self._filter_debug_samples += 1
            return

        if self.filter_stats['checked'] % self.filter_debug_log_every == 0:
            print(
                "[COLLECT] FILTER summary: "
                f"checked={self.filter_stats['checked']} passed={self.filter_stats['passed']} "
                f"excluded={self.filter_stats['excluded']} no_keywords={self.filter_stats['no_keywords']} "
                f"matched={self.filter_stats['matched_keyword']} no_match={self.filter_stats['no_match']}",
                file=sys.stderr,
                flush=True,
            )
            self._emit_log('info', 'Filter summary', {
                'checked': self.filter_stats['checked'],
                'passed': self.filter_stats['passed'],
                'excluded': self.filter_stats['excluded'],
                'no_keywords': self.filter_stats['no_keywords'],
                'matched_keyword': self.filter_stats['matched_keyword'],
                'no_match': self.filter_stats['no_match'],
            })
    
    def _scroll_and_collect(self, product_selector: str, filter_keywords: List[str], limit: int) -> set:
        """
        Для infinite scroll: скроллит страницу и собирает новые товары.
        
        Args:
            product_selector: CSS-селектор товаров
            filter_keywords: Ключевые слова для фильтра
            limit: Максимум товаров для сбора
            
        Returns:
            set: Собранные URL товаров
        """
        products = set()
        scroll_pause_time = 2.0
        max_scrolls = 10
        
        try:
            for _ in range(max_scrolls):
                if len(products) >= limit:
                    break
                
                # Скроллим вниз
                self._scroll_to_bottom()
                time.sleep(scroll_pause_time)
                
                # Собираем товары
                elements = self._collect_elements(product_selector)
                for elem in elements:
                    if len(products) >= limit:
                        break
                    try:
                        href = elem.get_attribute('href')
                        if href:
                            abs_url = urljoin(self.config.get('base_url'), href)
                            if self._filter_url(abs_url, filter_keywords):
                                products.add(abs_url)
                    except:
                        pass
        except Exception as e:
            print(f"[COLLECT] Ошибка infinite scroll: {e}", file=sys.stderr, flush=True)
        
        return products
    
    def _scroll_to_bottom(self):
        """Скроллит страницу к конца. Должна быть переопределена в конкретных адаптерах."""
        raise NotImplementedError("_scroll_to_bottom должна быть переопределена в конкретном адаптере")
        
    @abstractmethod
    def parse_product_page(self, url: str, take_screenshot: bool = True) -> MaterialData:
        """
        Извлекает данные с одной страницы товара.
        
        Args:
            url: URL страницы товара
            take_screenshot: Нужно ли делать скриншот
            
        Returns:
            MaterialData: Данные о материале
            
        Raises:
            ValueError: Если не удалось извлечь обязательные данные
        """
        pass
    
    @abstractmethod
    def parse_category(self, category_url: str) -> List[str]:
        """
        Извлекает список URL товаров из категории (с учетом пагинации).
        
        Args:
            category_url: URL категории
            
        Returns:
            List[str]: Список URL товаров
        """
        pass
    
    def parse_urls_list(self, urls: List[str]) -> ParseResult:
        """
        Парсит список URL товаров.
        Базовая реализация, может быть переопределена.
        
        Args:
            urls: Список URL для парсинга
            
        Returns:
            ParseResult: Результат парсинга
        """
        materials = []
        errors = []
        
        for url in urls:
            try:
                material = self.parse_product_page(url)
                materials.append(material)
            except Exception as e:
                errors.append(f"{url}: {str(e)}")
        
        return ParseResult(
            success=len(errors) == 0,
            materials=materials,
            errors=errors,
            pages_processed=len(urls),
            items_count=len(materials)
        )
    
    @abstractmethod
    def extract_availability_status(self, page) -> str:
        """
        Определяет статус наличия товара.
        
        Args:
            page: Объект страницы Playwright
            
        Returns:
            str: Статус наличия ('in_stock', 'on_order', etc.)
        """
        pass
    
    def validate_material_data(self, data: MaterialData) -> bool:
        """
        Валидирует данные материала.
        Может быть переопределена для специфичной логики.
        
        Args:
            data: Данные материала
            
        Returns:
            bool: True если данные валидны
        """
        if not data.article or not data.name:
            return False
        if data.price_per_unit <= 0:
            return False
        if data.type not in ['plate', 'edge', 'fitting']:
            return False
        return True
    
    def get_screenshot_path(self, url: str) -> str:
        """
        Генерирует путь для сохранения скриншота.
        Может быть переопределена.
        
        Args:
            url: URL страницы
            
        Returns:
            str: Относительный путь к скриншоту
        """
        import hashlib
        from datetime import datetime
        
        url_hash = hashlib.md5(url.encode()).hexdigest()[:12]
        date_str = datetime.now().strftime("%Y-%m-%d")
        filename = f"{self.supplier_name}_{url_hash}.webp"
        
        return f"screenshots/{self.supplier_name}/{date_str}/{filename}"
