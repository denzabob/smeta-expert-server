#!/usr/bin/env node

/**
 * Скрипт для парсинга страницы товара с использованием Puppeteer
 * Обходит защиту Cloudflare и извлекает данные
 * 
 * Использование: node scrape-page.js <URL>
 */

const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');

puppeteer.use(StealthPlugin());

async function scrapePage(url) {
  let browser;
  try {
    // Запускаем браузер
    browser = await puppeteer.launch({
      headless: 'new',
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--single-process',
        '--disable-web-resources',
      ],
      timeout: 30000,
    });

    const page = await browser.newPage();
    
    // Устанавливаем User-Agent
    await page.setUserAgent(
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    );

    // Устанавливаем viewport
    await page.setViewport({
      width: 1920,
      height: 1080,
      deviceScaleFactor: 1,
    });

    // Добавляем заголовки для более человечного вида запроса
    await page.setExtraHTTPHeaders({
      'Accept-Language': 'ru-RU,ru;q=0.9,en;q=0.8',
      'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
      'Accept-Encoding': 'gzip, deflate, br',
      'Connection': 'keep-alive',
      'Upgrade-Insecure-Requests': '1',
    });

    // Переходим на страницу с таймаутом
    try {
      await page.goto(url, {
        waitUntil: ['domcontentloaded', 'networkidle0'],
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
    error: 'URL не указан. Использование: node scrape-page.js <URL>',
    timestamp: new Date().toISOString(),
  }));
  process.exit(1);
}

scrapePage(url);
