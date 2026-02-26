#!/usr/bin/env node

/**
 * Скрипт для парсинга страницы товара с использованием Playwright
 * Обходит защиту Cloudflare и извлекает данные
 * 
 * Использование: node scrape-page-pw.js <URL>
 */

import { chromium } from 'playwright';

async function scrapePage(url) {
  let browser;
  try {
    // Запускаем браузер Chromium (уже установлен через Playwright)
    browser = await chromium.launch({
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
      ],
    });

    const page = await browser.newPage();

    // Переходим на страницу с таймаутом
    try {
      await page.goto(url, {
        waitUntil: 'networkidle',
        timeout: 30000,
      });
    } catch (navigationError) {
      // Если timeout - это может быть нормально, содержимое может быть загружено
      console.error('Navigation timeout or error:', navigationError.message);
    }

    // Ждем немного чтобы убедиться что все скрипты загружены
    await page.waitForTimeout(2000);

    // Получаем полный HTML страницы
    const html = await page.content();

    // Закрываем браузер
    await browser.close();

    // Выводим результат в JSON формате
    console.log(JSON.stringify({
      success: true,
      html: html,
      url: url,
      timestamp: new Date().toISOString(),
    }));

    process.exit(0);
  } catch (error) {
    if (browser) {
      await browser.close();
    }

    console.error(JSON.stringify({
      success: false,
      error: error.message,
      url: url,
      timestamp: new Date().toISOString(),
    }));

    process.exit(1);
  }
}

// Получаем URL из аргументов командной строки
const url = process.argv[2];

if (!url) {
  console.error(JSON.stringify({
    success: false,
    error: 'URL не указан. Использование: node scrape-page-pw.js <URL>',
    timestamp: new Date().toISOString(),
  }));
  process.exit(1);
}

scrapePage(url);
