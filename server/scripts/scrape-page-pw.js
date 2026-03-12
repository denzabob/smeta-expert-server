#!/usr/bin/env node

/**
 * Скрипт для парсинга страницы товара с использованием Playwright
 * Обходит защиту Cloudflare и извлекает данные
 * 
 * Использование: node scrape-page-pw.js <URL>
 */

import { chromium } from 'playwright';
import { writeFileSync } from 'fs';
import { tmpdir } from 'os';
import { join } from 'path';

/**
 * Random delay to mimic human navigation timing.
 */
function randomDelay(minMs, maxMs) {
  const ms = Math.floor(Math.random() * (maxMs - minMs + 1)) + minMs;
  return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Check if the page is showing a Cloudflare challenge.
 */
async function detectCloudflare(page) {
  try {
    const title = (await page.title() || '').toLowerCase();
    if (title.includes('just a moment') || title.includes('checking your browser')) {
      return true;
    }
    const challenge = await page.$('#challenge-running');
    return challenge !== null;
  } catch {
    return false;
  }
}

async function scrapePage(url) {
  let browser;
  try {
    // Запускаем браузер Chromium
    browser = await chromium.launch({
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-blink-features=AutomationControlled',
        '--disable-dev-shm-usage',
      ],
    });

    const context = await browser.newContext({
      userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      viewport: { width: 1280, height: 900 },
      locale: 'ru-RU',
      extraHTTPHeaders: {
        'Accept': 'text/html,application/xhtml+xml',
        'Accept-Language': 'ru-RU,ru;q=0.9',
        'Upgrade-Insecure-Requests': '1',
      },
    });

    await context.addInitScript(() => {
      Object.defineProperty(navigator, 'webdriver', {
        get: () => undefined,
      });
    });

    const page = await context.newPage();

    // Блокируем ресурсы которые не нужны для парсинга
    await page.route('**/*', (route) => {
      const type = route.request().resourceType();
      if (['image', 'media', 'font'].includes(type)) {
        return route.abort();
      }
      const reqUrl = route.request().url();
      const trackers = [
        'mc.yandex.ru', 'metrika', 'google-analytics', 'googletagmanager',
        'facebook.net', 'amplitude', 'hotjar', 'bitrix24',
        'jivosite', 'carrotquest', 'top-fwz1', '/tracker', '/analytics', '/pixel',
      ];
      if (trackers.some(t => reqUrl.includes(t))) {
        return route.abort();
      }
      return route.continue();
    });

    // Random delay 1-3s before navigation to mimic human behavior
    await randomDelay(1000, 3000);

    // Переходим на страницу
    try {
      await page.goto(url, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      });
    } catch (navigationError) {
      // Если timeout — содержимое может быть уже загружено
    }

    await page.waitForTimeout(1500);

    // Cloudflare detection — wait up to 20 seconds
    if (await detectCloudflare(page)) {
      console.error(JSON.stringify({ event: 'browser.cloudflare_detected', url }));
      const deadline = Date.now() + 20000;
      while (Date.now() < deadline) {
        if (!(await detectCloudflare(page))) break;
        await page.waitForTimeout(1000);
      }
    }

    // Получаем полный HTML страницы
    const html = await page.content();

    await browser.close();

    // Пишем HTML во временный файл чтобы обойти лимит pipe buffer (64KB)
    const tmpFile = join(tmpdir(), `pw_${Date.now()}_${Math.random().toString(36).slice(2)}.html`);
    writeFileSync(tmpFile, html, 'utf8');

    process.stdout.write(JSON.stringify({
      success: true,
      html_path: tmpFile,
      url: url,
      timestamp: new Date().toISOString(),
    }) + '\n');

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
