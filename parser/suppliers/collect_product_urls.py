import logging
import random
import time
from pathlib import Path
from typing import List, Set

from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeout

logger = logging.getLogger(__name__)
logging.basicConfig(level=logging.INFO, format="%(asctime)s - %(levelname)s - %(message)s")


def collect_product_urls(start_url: str, max_pages: int = None) -> List[str]:
    """
    Собирает уникальные URL карточек товаров из категории с пагинацией.

    Args:
        start_url: URL категории (например, https://www.skm-mebel.ru/category/dsp_laminirovannaya/)
        max_pages: Лимит страниц для теста (None = все страницы)

    Returns:
        Список уникальных URL товаров
    """
    urls: Set[str] = set()
    base_domain = "https://www.skm-mebel.ru"

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context(
            viewport={"width": 1920, "height": 1080},
            user_agent="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36"
        )
        page = context.new_page()

        current_page = 1
        while True:
            page_url = f"{start_url.rstrip('/')}?PAGEN_1={current_page}" if current_page > 1 else start_url
            logger.info(f"Обработка страницы {current_page}: {page_url}")

            try:
                page.goto(page_url, wait_until="networkidle", timeout=40000)
                page.wait_for_selector("div.product-card.catalog__item", timeout=15000)
            except PlaywrightTimeout:
                logger.warning(f"Таймаут загрузки страницы {current_page}. Останавливаем.")
                break

            # === Собираем ссылки на товары ===
            product_cards = page.query_selector_all("div.product-card.catalog__item")
            for card in product_cards:
                link_el = card.query_selector("a.product-card__title") or card.query_selector('a[href^="/product/"]')
                if link_el:
                    href = link_el.get_attribute("href")
                    if href and href.startswith("/product/"):
                        full_url = base_domain + href
                        if full_url not in urls:
                            urls.add(full_url)
                            logger.debug(f"Найден товар: {full_url}")

            logger.info(f"На странице {current_page} найдено {len(product_cards)} карточек, всего уникальных: {len(urls)}")

            # === Определяем последнюю страницу ===
            pagination = page.query_selector(".pagination")  # Bitrix стандартный класс
            if not pagination:
                logger.info("Пагинация не найдена — вероятно, последняя страница.")
                break

            page_links = pagination.query_selector_all("a")
            if not page_links:
                break

            # Последняя ссылка (кроме "Вперёд") — номер последней страницы
            last_page_text = page_links[-2].inner_text().strip() if len(page_links) > 1 else "1"
            try:
                max_page = int(last_page_text)
            except ValueError:
                max_page = current_page

            # Условие остановки
            if max_pages and current_page >= max_pages:
                logger.info(f"Достигнут лимит страниц ({max_pages}). Останавливаем.")
                break
            if current_page >= max_page:
                logger.info(f"Достигнута последняя страница ({max_page}).")
                break

            current_page += 1
            time.sleep(random.uniform(1.5, 3.5))  # Вежливая пауза

        browser.close()

    logger.info(f"Сбор завершён. Всего уникальных товаров: {len(urls)}")
    return sorted(list(urls))


def save_urls_to_file(urls: List[str], filename: str = "skm_ldsp_urls.txt"):
    """Сохраняет список URL в файл"""
    Path(filename).write_text("\n".join(urls), encoding="utf-8")
    logger.info(f"Ссылки сохранены в {filename}")


if __name__ == "__main__":
    # Начальная категория — ЛДСП
    START_URL = "https://www.skm-mebel.ru/category/dsp_laminirovannaya/"

    # Для теста — только первые 3 страницы
    product_urls = collect_product_urls(START_URL, max_pages=10)

    # Для полного сбора убери max_pages
    # product_urls = collect_product_urls(START_URL)

    save_urls_to_file(product_urls)
