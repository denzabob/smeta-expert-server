# Шаблон адаптера нового поставщика

"""
Используйте этот файл как шаблон для создания адаптера нового поставщика.

Шаги:
1. Скопируйте этот файл в suppliers/имя_поставщика.py
2. Переименуйте класс TemplateAdapter → ВашПоставщикAdapter
3. Реализуйте все методы с пометкой TODO
4. Создайте конфигурацию в configs/имя_поставщика.json
5. Протестируйте: python -m parser.main имя_поставщика --url <test_url>
"""

import re
import sys
import hashlib
from datetime import datetime
from pathlib import Path
from typing import List
from playwright.sync_api import sync_playwright, Page
from PIL import Image

# Поддержка запуска как модуля и как скрипта
try:
    from ..base_adapter import SupplierAdapter, MaterialData
except ImportError:
    sys.path.insert(0, str(Path(__file__).parent.parent.parent))
    from parser.base_adapter import SupplierAdapter, MaterialData


class TemplateAdapter(SupplierAdapter):
    """
    Адаптер для парсинга [НАЗВАНИЕ ПОСТАВЩИКА].
    
    Замените это описание на реальное название и краткую информацию.
    """
    
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
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            page = browser.new_page()
            page.set_viewport_size({"width": 1920, "height": 1080})
            
            # Загружаем страницу
            timeout = self.config.get('delays', {}).get('page_load_timeout', 30000)
            page.goto(url, timeout=timeout)
            
            # Ждём появления ключевого элемента
            wait_selector = self.selectors.get('wait_for', 'body')
            page.wait_for_selector(wait_selector, timeout=15000)
            
            # TODO: Извлеките артикул
            article = self._extract_article(page)
            
            # TODO: Извлеките название
            name = self._extract_name(page)
            
            # TODO: Извлеките цену
            price = self._extract_price(page)
            
            # TODO: Определите статус наличия
            availability_status = self.extract_availability_status(page)
            
            # Получаем тип и единицу из конфига
            material_type = self._determine_material_type_from_url(url)
            unit = self.config.get('default_unit', 'м²')
            
            # Скриншот (если требуется)
            screenshot_path = None
            if take_screenshot:
                screenshot_path = self._take_screenshot(page, url)
            
            browser.close()
            
            return MaterialData(
                article=article,
                name=name,
                price_per_unit=price,
                type=material_type,
                unit=unit,
                availability_status=availability_status,
                source_url=url,
                screenshot_path=screenshot_path
            )
    
    def parse_category(self, category_url: str) -> List[str]:
        """
        Извлекает список URL товаров из категории (с учетом пагинации).
        
        Args:
            category_url: URL категории
            
        Returns:
            List[str]: Список URL товаров
        """
        # TODO: Реализуйте парсинг категорий
        # Пример:
        # 1. Загрузить страницу категории
        # 2. Найти все ссылки на товары
        # 3. Проверить наличие пагинации
        # 4. Перейти на следующую страницу
        # 5. Повторить до конца
        
        urls = []
        
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            page = browser.new_page()
            
            # TODO: Ваша логика здесь
            
            browser.close()
        
        return urls
    
    def extract_availability_status(self, page: Page) -> str:
        """
        Определяет статус наличия товара.
        
        Args:
            page: Объект страницы Playwright
            
        Returns:
            str: Статус наличия ('in_stock', 'on_order', 'out_of_stock')
        """
        # TODO: Реализуйте определение статуса
        # Примеры:
        # - Проверить текст кнопки "В корзину" vs "Под заказ"
        # - Проверить наличие класса/атрибута
        # - Проверить availability в структурированных данных
        
        button_selector = self.selectors.get('add_to_cart_button', 'button.add-to-cart')
        button = page.query_selector(button_selector)
        
        if button:
            btn_text = button.inner_text().strip().lower()
            
            if "корзину" in btn_text or "купить" in btn_text:
                return "in_stock"
            elif "заказ" in btn_text:
                return "on_order"
        
        return "out_of_stock"
    
    def _extract_article(self, page: Page) -> str:
        """Извлекает артикул товара."""
        # TODO: Реализуйте извлечение артикула
        selector = self.selectors.get('article', '.product-article')
        element = page.query_selector(selector)
        
        if not element:
            raise ValueError(f"Артикул не найден по селектору: {selector}")
        
        article = element.inner_text().strip()
        
        # Очистка от лишних символов (если нужно)
        article = re.sub(r'Артикул:|Article:', '', article, flags=re.IGNORECASE).strip()
        
        return article
    
    def _extract_name(self, page: Page) -> str:
        """Извлекает название товара."""
        # TODO: Реализуйте извлечение названия
        selector = self.selectors.get('name', 'h1.product-name')
        element = page.query_selector(selector)
        
        if element:
            return element.inner_text().strip()
        
        # Fallback: meta-тег
        meta_name = page.query_selector('meta[property="og:title"]')
        if meta_name:
            return meta_name.get_attribute("content")
        
        return "Без названия"
    
    def _extract_price(self, page: Page) -> float:
        """Извлекает цену товара."""
        # TODO: Реализуйте извлечение цены
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
            price_text_selector = self.selectors.get('price_text', '.product-price')
            price_el = page.query_selector(price_text_selector)
            
            if price_el:
                text = price_el.inner_text()
                # Извлекаем только цифры
                match = re.search(r'[\d\s]+(?:[.,]\d+)?', text.replace('\xa0', ' '))
                if match:
                    price_str = match.group().replace(' ', '').replace(',', '.')
                    try:
                        price = float(price_str)
                    except ValueError:
                        pass
        
        if price is None:
            raise ValueError("Цена не найдена")
        
        return price
    
    def _take_screenshot(self, page: Page, url: str) -> str:
        """Создаёт скриншот страницы и возвращает путь к файлу."""
        url_hash = hashlib.md5(url.encode()).hexdigest()[:12]
        date_str = datetime.now().strftime("%Y-%m-%d")
        timestamp = datetime.now().strftime("%H%M%S")
        filename_base = f"{self.supplier_name}_{url_hash}_{timestamp}"
        
        # Путь: storage/app/public/screenshots/supplier_name/
        project_root = Path(__file__).parent.parent.parent
        dir_path = project_root / "storage" / "app" / "public" / "screenshots" / self.supplier_name / date_str
        dir_path.mkdir(parents=True, exist_ok=True)
        
        temp_png = dir_path / f"{filename_base}.png"
        webp_path = dir_path / f"{filename_base}.webp"
        
        # Делаем скриншот
        page.screenshot(path=str(temp_png), full_page=False)
        
        # Конвертируем в WebP
        with Image.open(temp_png) as img:
            quality = self.config.get('screenshot', {}).get('quality', 85)
            method = self.config.get('screenshot', {}).get('method', 6)
            img.save(webp_path, "WEBP", quality=quality, method=method)
        
        # Удаляем временный PNG
        temp_png.unlink(missing_ok=True)
        
        # Возвращаем путь относительно public/storage
        return f"screenshots/{self.supplier_name}/{date_str}/{filename_base}.webp"
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