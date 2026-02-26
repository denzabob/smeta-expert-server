#!/usr/bin/env node

/**
 * Скрипт для парсинга страницы товара с использованием Playwright и CSS-селекторов.
 * 
 * Использование: node scrape-with-selectors.js <URL> <SELECTORS_JSON>
 * 
 * SELECTORS_JSON — JSON-строка с CSS-селекторами:
 *   { "title": "h1.product-title", "price": ".price-value", "article": ".sku" }
 * 
 * Результат: JSON с извлечёнными данными
 */

import { chromium } from 'playwright';

async function scrapePage(url, selectors) {
  let browser;
  try {
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

    // Block unnecessary resources for speed
    await page.route('**/*', (route) => {
      const type = route.request().resourceType();
      if (['image', 'font', 'media', 'stylesheet'].includes(type)) {
        route.abort();
      } else {
        route.continue();
      }
    });

    // Navigate
    try {
      await page.goto(url, {
        waitUntil: 'domcontentloaded',
        timeout: 30000,
      });
    } catch (navigationError) {
      console.error('Navigation warning:', navigationError.message);
    }

    // Wait for content to settle
    await page.waitForTimeout(2000);

    // Extract data using selectors
    const extracted = {};
    for (const [field, selector] of Object.entries(selectors)) {
      try {
        const element = await page.$(selector);
        if (element) {
          // For meta tags, get content attribute
          const tagName = await element.evaluate(el => el.tagName.toLowerCase());
          if (tagName === 'meta') {
            extracted[field] = await element.getAttribute('content');
          } else if (tagName === 'input') {
            extracted[field] = await element.inputValue();
          } else {
            extracted[field] = await element.innerText();
          }

          // Clean whitespace
          if (extracted[field]) {
            extracted[field] = extracted[field].trim().replace(/\s+/g, ' ');
          }
        } else {
          extracted[field] = null;
        }
      } catch (err) {
        extracted[field] = null;
      }
    }

    // Also try schema.org fallback for any missing fields
    const schemaData = await page.evaluate(() => {
      const scripts = document.querySelectorAll('script[type="application/ld+json"]');
      for (const script of scripts) {
        try {
          const data = JSON.parse(script.textContent);
          const product = data['@type'] === 'Product' ? data
            : (Array.isArray(data['@graph']) ? data['@graph'].find(i => i['@type'] === 'Product') : null);
          if (product) {
            const offer = product.offers || (Array.isArray(product.offers) ? product.offers[0] : null);
            return {
              name: product.name || null,
              sku: product.sku || null,
              price: offer?.price || offer?.lowPrice || null,
            };
          }
        } catch (e) { /* ignore */ }
      }
      return null;
    });

    // Fill missing fields from schema.org
    if (schemaData) {
      if (!extracted.title && schemaData.name) extracted.title = schemaData.name;
      if (!extracted.article && schemaData.sku) extracted.article = schemaData.sku;
      if (!extracted.price && schemaData.price) extracted.price = String(schemaData.price);
    }

    // Get page title as ultimate fallback
    if (!extracted.title) {
      extracted.title = await page.title();
    }

    await browser.close();

    console.log(JSON.stringify({
      success: true,
      data: extracted,
      url: url,
      timestamp: new Date().toISOString(),
    }));

    process.exit(0);
  } catch (error) {
    if (browser) {
      try { await browser.close(); } catch (e) { /* ignore */ }
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

// Parse args
const url = process.argv[2];
const selectorsJson = process.argv[3];

if (!url) {
  console.error(JSON.stringify({
    success: false,
    error: 'Usage: node scrape-with-selectors.js <URL> <SELECTORS_JSON>',
  }));
  process.exit(1);
}

let selectors = {};
if (selectorsJson) {
  try {
    selectors = JSON.parse(selectorsJson);
  } catch (e) {
    console.error(JSON.stringify({
      success: false,
      error: 'Invalid SELECTORS_JSON: ' + e.message,
    }));
    process.exit(1);
  }
}

scrapePage(url, selectors);
