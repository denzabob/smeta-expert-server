#!/usr/bin/env node

/**
 * Скрипт для парсинга страницы товара с использованием Playwright
 * Обходит защиту Cloudflare и извлекает данные
 * 
 * Использование: node scrape-page-pw.js <URL>
 */

import { chromium } from 'playwright';
import { writeFileSync, unlinkSync } from 'fs';
import { tmpdir } from 'os';
import { join } from 'path';

async function scrapePage(url) {
  let browser;
  try {
    // Запускаем браузер Chromium
    browser = await chromium.launch({
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
      ],
    });

    const context = await browser.newContext({
      userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    });

    const page = await context.newPage();

    // Блокируем ресурсы которые не нужны для парсинга (аналогично SkmMebelAdapter)
    await page.route('**/*', (route) => {
      const type = route.request().resourceType();
      if (['image', 'media', 'font'].includes(type)) {
        return route.abort();
      }
      const reqUrl = route.request().url();
      const trackers = [
        'mc.yandex.ru', 'metrika', 'google-analytics', 'googletagmanager',
        'facebook.net', 'amplitude', 'hotjar', 'bitrix24',
      ];
      if (trackers.some(t => reqUrl.includes(t))) {
        return route.abort();
      }
      return route.continue();
    });

    // Переходим на страницу
    try {
      await page.goto(url, {
        waitUntil: 'domcontentloaded',
        timeout: 25000,
      });
    } catch (navigationError) {
      // Если timeout — содержимое может быть уже загружено
    }

    await page.waitForTimeout(1500);

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
