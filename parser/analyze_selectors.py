#!/usr/bin/env python3
"""
analyze_selectors.py - Анализирует структуру селекторов на сайте skm-mebel.ru
"""

import sys
import json
from pathlib import Path
from playwright.sync_api import sync_playwright

# Добавляем путь к парсеру
parser_path = Path(__file__).parent
sys.path.insert(0, str(parser_path))

from config import config_manager

def test_selector(page, selector, label):
    """Тестирует селектор и выводит результаты."""
    try:
        elements = page.query_selector_all(selector)
        print(f"[ANALYZE] ✓ '{selector}' -> Found {len(elements)} elements")
        if elements and len(elements) > 0:
            # Выводим информацию о первом элементе
            el = elements[0]
            if 'a' in selector.lower() or el.evaluate("el => el.tagName") == "A":
                href = el.get_attribute('href')
                text = el.text_content().strip()[:30]
                print(f"[ANALYZE]   First: href='{href}', text='{text}'")
        return len(elements)
    except Exception as e:
        print(f"[ANALYZE] ✗ '{selector}' -> Error: {e}")
        return 0

def main():
    """Анализирует селекторы на сайте."""
    
    # Загружаем конфиг
    config = config_manager.load_supplier_config('skm_mebel')
    
    # Инициализируем браузер
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, args=['--disable-dev-shm-usage', '--no-sandbox'])
        page = browser.new_page()
        
        # Переходим на страницу
        url = config.get('catalog_base_url', '')
        print(f"[ANALYZE] Opening {url}")
        page.goto(url, wait_until='domcontentloaded')
        
        # Даём странице время загрузиться
        import time
        time.sleep(3)
        
        # Текущий селектор
        current_selector = config.get('url_collection', {}).get('product_selector', '')
        print(f"\n[ANALYZE] Current selector: {current_selector}")
        current_count = test_selector(page, current_selector, "Current")
        
        # Предлагаемые альтернативы
        print(f"\n[ANALYZE] Testing alternative selectors:")
        selectors_to_test = [
            'a.image-list__link[href*="/product/"]',
            'a.image-list__link',
            '.image-list a[href*="/product/"]',
            '.image-list-wrapper a[href*="/product/"]',
            'a[href*="/product/"]',
            'li a[href*="/product/"]',
            '.js-image-block a[href*="/product/"]',
        ]
        
        best_selector = None
        best_count = 0
        
        for selector in selectors_to_test:
            count = test_selector(page, selector, "")
            if count > best_count:
                best_count = count
                best_selector = selector
        
        print(f"\n[ANALYZE] RESULTS:")
        print(f"[ANALYZE] - Current selector returns: {current_count} elements")
        print(f"[ANALYZE] - Best alternative: '{best_selector}' -> {best_count} elements")
        
        if best_count > current_count:
            print(f"\n[ANALYZE] RECOMMENDATION: Update product_selector to: {best_selector}")
            print(f"[ANALYZE] This will increase from {current_count} to {best_count} products")
        
        # Проверяем пагинацию
        print(f"\n[ANALYZE] Checking pagination selectors:")
        test_selector(page, '.pagination__next[href]', "Next page")
        test_selector(page, 'a.pagination__next', "Alt next page")
        test_selector(page, 'li.pagination__item a[href*="PAGEN"]', "Page number")
        
        # Проверяем подкатегории
        print(f"\n[ANALYZE] Checking subcategory selectors:")
        test_selector(page, '.catalog-menu a[href*="/category/"]', "Current subcategory")
        test_selector(page, '.side-menu a[href*="/category/"]', "Alt side menu")
        
        browser.close()
        
        print(f"\n[ANALYZE] Analysis complete!")

if __name__ == '__main__':
    main()
