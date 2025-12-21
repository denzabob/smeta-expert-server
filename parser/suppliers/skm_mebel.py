# parser/suppliers/skm_mebel.py

import re
import hashlib
from datetime import datetime
from pathlib import Path
from playwright.sync_api import sync_playwright
from PIL import Image


def extract_material_data(url: str):
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()
        page.set_viewport_size({"width": 1920, "height": 1080})
        page.goto(url, timeout=30000)

        # === Ждём появления ключевого элемента в DOM (не обязательно видимого) ===
        page.wait_for_selector('[itemprop="name"]', state="attached", timeout=15000)

        # === 1. Артикул — из видимого блока ===
        article_el = page.query_selector(".catalog-detail__article.js-copy-article")
        if not article_el:
            raise ValueError("Артикул не найден: отсутствует .catalog-detail__article")
        article = article_el.inner_text().strip()

        # === 2. Название — из itemprop (гарантированно есть) ===
        name_meta = page.query_selector('meta[itemprop="name"]')
        name = name_meta.get_attribute("content") if name_meta else "Без названия"

        # === 3. Цена — из itemprop или видимого блока ===
        price = None
        price_meta = page.query_selector('meta[itemprop="price"]')
        if price_safe := (price_meta and price_meta.get_attribute("content")):
            price = float(price_safe)
        else:
            # Fallback: парсим из видимого текста
            price_el = page.query_selector(".catalog-detail__price, .price")
            if price_el:
                text = price_el.inner_text()
                match = re.search(r"[\d\s]+", text.replace('\xa0', ' ').replace(' ', ''))
                if match:
                    price = float(match.group())

        if price is None:
            raise ValueError("Цена не найдена")

        # === 4. Тип и единица ===
        material_type = "plate"
        unit = "м²"

        # === 5. Скриншот ===
        screenshot_path = _take_screenshot(page, url)

        browser.close()

        return {
            "name": name,
            "article": article,
            "type": material_type,
            "unit": unit,
            "price_per_unit": price,
            "source_url": url,
            "screenshot_path": screenshot_path,
        }


def _take_screenshot(page, url: str) -> str:
    url_hash = hashlib.md5(url.encode()).hexdigest()[:12]
    date_str = datetime.now().strftime("%Y-%m-%d")
    filename_base = f"{url_hash}"

    temp_png = Path("storage/screenshots/skm_mebel") / date_str / f"{filename_base}.png"
    webp_path = Path("storage/screenshots/skm_mebel") / date_str / f"{filename_base}.webp"

    temp_png.parent.mkdir(parents=True, exist_ok=True)
    page.screenshot(path=str(temp_png))

    img = Image.open(temp_png)
    img.save(webp_path, "WEBP", quality=80, method=6)
    temp_png.unlink()

    return f"screenshots/skm_mebel/{date_str}/{filename_base}.webp"