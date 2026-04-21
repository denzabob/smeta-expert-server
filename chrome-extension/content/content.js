/**
 * Prizm Chrome Extension — Content Script
 * Handles element selection/capture on supplier pages.
 */

(function () {
  'use strict';

  // Idempotent bootstrap guard — prevents re-initialization on repeated injection.
  // window.__PRISM_CONTENT_READY__ is reset on page navigation, so re-injection
  // after navigation works correctly without duplicate listeners or overlays.
  if (window.__PRISM_CONTENT_READY__) return;
  window.__PRISM_CONTENT_READY__ = true;

  // ============================================================
  // State
  // ============================================================
  let captureMode = false;
  let activeField = null; // 'title' | 'price' | 'article' | 'thickness' | 'length' | 'width'
  let hoveredElement = null;
  let capturedData = {};  // { field: { value, selector, xpath, element } }
  let capturedSchemaMapping = null; // persisted schema mapping for template saving

  // Tracks all page elements whose inline styles were permanently modified by the
  // extension (capture-marker dashed outlines). Stores pre-extension originals so
  // they can be fully restored during reset / clear operations.
  // Key: HTMLElement  Value: { outline: string, outlineOffset: string }
  const styledElements = new Map();

  // ============================================================
  // Style registry helpers
  // ============================================================

  /**
   * Save original inline style values for a page element before the extension
   * applies a permanent capture-marker style. Only saves once — subsequent calls
   * on the same element preserve the original (pre-extension) values.
   */
  function saveCaptureStyle(el) {
    if (!styledElements.has(el)) {
      styledElements.set(el, {
        outline: el.style.outline,
        outlineOffset: el.style.outlineOffset,
      });
    }
  }

  /**
   * Restore inline styles for ALL tracked elements and clear the registry.
   * Safe to call multiple times.
   */
  function restoreAllStyles() {
    for (const [el, orig] of styledElements) {
      try {
        el.style.outline = orig.outline;
        el.style.outlineOffset = orig.outlineOffset;
      } catch { /* element may have been removed from DOM */ }
    }
    styledElements.clear();
  }

  // ============================================================
  // Full UI destroy — safe to call repeatedly
  // ============================================================

  /**
   * Fully restore the page to pre-extension state:
   * - Stop capture mode (removes listeners, hover highlight)
   * - Remove overlay and tooltip from DOM
   * - Remove all field-marker elements
   * - Restore all inline styles mutated by the extension
   *
   * Does NOT touch capturedData or capturedSchemaMapping.
   * Safe to call when capture is not active.
   */
  function destroyUI() {
    if (captureMode) stopCapture();
    // Ensure overlay/tooltip are gone even if stopCapture wasn't the trigger
    overlay.remove();
    tooltip.remove();
    // Remove all field capture markers
    document.querySelectorAll('.prizm-captured-marker').forEach(m => m.remove());
    // Restore all permanent style mutations made by the extension
    restoreAllStyles();
  }

  // ============================================================
  // Overlay UI for capture mode
  // ============================================================
  const overlay = document.createElement('div');
  overlay.id = 'prizm-capture-overlay';
  overlay.innerHTML = `
    <div class="prizm-capture-bar">
      <span class="prizm-capture-label">Призма: выберите элемент</span>
      <span class="prizm-capture-field" id="prizm-field-name">—</span>
      <button class="prizm-capture-cancel" id="prizm-cancel-capture">✕ Отмена</button>
    </div>
  `;

  const tooltip = document.createElement('div');
  tooltip.id = 'prizm-capture-tooltip';
  tooltip.style.display = 'none';

  // ============================================================
  // Selector generation
  // ============================================================

  /**
   * Generate a robust CSS selector for an element.
   */
  function generateSelector(el) {
    if (el.id) {
      return `#${CSS.escape(el.id)}`;
    }

    const path = [];
    let current = el;

    while (current && current !== document.body && current !== document.documentElement) {
      let selector = current.tagName.toLowerCase();

      if (current.id) {
        selector = `#${CSS.escape(current.id)}`;
        path.unshift(selector);
        break;
      }

      // Try unique class combination
      if (current.className && typeof current.className === 'string') {
        const classes = current.className
          .trim()
          .split(/\s+/)
          .filter(c => c && !c.match(/^(hover|active|focus|visited|selected|open|show|hide|collapsed)/i))
          .slice(0, 3);

        if (classes.length > 0) {
          const classSelector = selector + '.' + classes.map(c => CSS.escape(c)).join('.');
          // Check uniqueness
          const parent = current.parentElement;
          if (parent && parent.querySelectorAll(`:scope > ${classSelector}`).length === 1) {
            selector = classSelector;
          } else if (classes.length > 0) {
            selector = classSelector;
            // Add nth-child for disambiguation
            const siblings = parent ? Array.from(parent.children) : [];
            const index = siblings.indexOf(current) + 1;
            if (index > 0 && siblings.filter(s => s.matches(classSelector)).length > 1) {
              selector += `:nth-child(${index})`;
            }
          }
        }
      }

      // nth-child fallback
      if (selector === current.tagName.toLowerCase()) {
        const parent = current.parentElement;
        if (parent) {
          const siblings = Array.from(parent.children).filter(
            s => s.tagName === current.tagName
          );
          if (siblings.length > 1) {
            const index = siblings.indexOf(current) + 1;
            selector += `:nth-of-type(${index})`;
          }
        }
      }

      path.unshift(selector);
      current = current.parentElement;
    }

    return path.join(' > ');
  }

  /**
   * Generate XPath for an element (fallback).
   */
  function generateXPath(el) {
    if (el.id) {
      return `//*[@id="${el.id}"]`;
    }

    const parts = [];
    let current = el;

    while (current && current.nodeType === Node.ELEMENT_NODE) {
      let index = 1;
      let sibling = current.previousElementSibling;

      while (sibling) {
        if (sibling.tagName === current.tagName) index++;
        sibling = sibling.previousElementSibling;
      }

      const tag = current.tagName.toLowerCase();
      parts.unshift(`${tag}[${index}]`);
      current = current.parentElement;
    }

    return '/' + parts.join('/');
  }

  /**
   * Extract clean text content from an element.
   */
  function extractText(el) {
    // For inputs/selects
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
      return el.value.trim();
    }
    if (el.tagName === 'SELECT') {
      return el.options[el.selectedIndex]?.text.trim() || '';
    }

    // Try innerText first (rendered text), fallback to textContent
    let text = el.innerText?.trim() || el.textContent?.trim() || '';

    // If element has many children with text, prefer direct text nodes
    if (el.children.length > 3) {
      const directText = Array.from(el.childNodes)
        .filter(n => n.nodeType === Node.TEXT_NODE)
        .map(n => n.textContent.trim())
        .filter(t => t)
        .join(' ');

      if (directText) text = directText;
    }

    return text;
  }

  // ============================================================
  // Highlight & Capture
  // ============================================================

  const FIELD_LABELS = {
    title: 'Название',
    price: 'Цена',
    article: 'Артикул',
    thickness: 'Толщина (мм)',
    length: 'Длина (мм)',
    width: 'Ширина (мм)',
    vacancy_title: 'Название вакансии',
    employer_name: 'Работодатель',
    salary_raw_text: 'Текст зарплаты',
    vacancy_description: 'Описание вакансии',
    source_title: 'Заголовок источника',
    source_date: 'Дата публикации',
  };

  const FIELD_COLORS = {
    title: '#4F46E5',
    price: '#059669',
    article: '#D97706',
    thickness: '#7C3AED',
    length: '#0891B2',
    width: '#D946EF',
    vacancy_title: '#4F46E5',
    employer_name: '#D97706',
    salary_raw_text: '#059669',
    vacancy_description: '#7C3AED',
    source_title: '#0891B2',
    source_date: '#D946EF',
  };

  // Material type detection and dimension parsing are handled by the backend
  // (MaterialTypeDetectionService + MaterialDimensionParser).
  // The extension only collects raw data from the page.

  function startCapture(field) {
    captureMode = true;
    activeField = field;

    document.body.appendChild(overlay);
    document.body.appendChild(tooltip);

    const fieldLabel = document.getElementById('prizm-field-name');
    if (fieldLabel) {
      fieldLabel.textContent = FIELD_LABELS[field] || field;
      fieldLabel.style.color = FIELD_COLORS[field] || '#4F46E5';
    }

    document.addEventListener('mousemove', onMouseMove, true);
    document.addEventListener('click', onElementClick, true);
    document.addEventListener('keydown', onKeyDown, true);
  }

  function stopCapture() {
    captureMode = false;
    activeField = null;

    clearHighlight();
    tooltip.style.display = 'none';

    overlay.remove();
    tooltip.remove();

    document.removeEventListener('mousemove', onMouseMove, true);
    document.removeEventListener('click', onElementClick, true);
    document.removeEventListener('keydown', onKeyDown, true);
  }

  function clearHighlight() {
    if (hoveredElement) {
      // Restore the style that was in place when we started hovering.
      // This may be the extension's own dashed outline (capture marker) or the
      // page's original style — both are correctly restored because we store
      // the snapshot taken at hover-start time, not the pre-extension original.
      const prev = hoveredElement.__prizmHoverOrig;
      if (prev) {
        hoveredElement.style.outline = prev.outline;
        hoveredElement.style.outlineOffset = prev.outlineOffset;
        delete hoveredElement.__prizmHoverOrig;
      }
      hoveredElement = null;
    }
  }

  function highlightElement(el) {
    if (hoveredElement === el) return;

    clearHighlight();

    // Don't highlight our own UI
    if (el.closest('#prizm-capture-overlay, #prizm-capture-tooltip, .prizm-captured-marker')) return;

    hoveredElement = el;
    // Snapshot whatever style the element currently has (could be a capture-marker
    // dashed outline or the page's own style) so clearHighlight can restore it exactly.
    el.__prizmHoverOrig = {
      outline: el.style.outline,
      outlineOffset: el.style.outlineOffset,
    };

    const color = FIELD_COLORS[activeField] || '#4F46E5';
    el.style.outline = `3px solid ${color}`;
    el.style.outlineOffset = '2px';

    // Show tooltip with preview
    const text = extractText(el);
    const preview = text.length > 80 ? text.substring(0, 80) + '…' : text;

    tooltip.textContent = `${FIELD_LABELS[activeField]}: "${preview}"`;
    tooltip.style.display = 'block';

    // Позиционирование: fixed — используем viewport-координаты
    const rect = el.getBoundingClientRect();
    const tipTop = rect.top - 30;
    tooltip.style.top = `${tipTop < 4 ? rect.bottom + 6 : tipTop}px`;
    tooltip.style.left = `${Math.max(4, rect.left)}px`;
  }

  // ============================================================
  // Field value normalization
  // ============================================================

  /**
   * Normalize captured value based on field type.
   * - price: strip currency suffixes (руб., RUB, рублей, ₽, р.), normalize separators
   * - thickness/length/width: extract numeric value from text
   */
  function normalizeFieldValue(field, value) {
    if (!value || typeof value !== 'string') return value || '';

    if (field === 'price') {
      return normalizePrice(value);
    }

    // Extract numeric value for dimension fields
    if (field === 'thickness' || field === 'length' || field === 'width') {
      return extractNumericValue(value);
    }

    return value.trim();
  }

  /**
   * Extract a numeric value from text (e.g. "16 мм" → "16", "2750" → "2750").
   */
  function extractNumericValue(raw) {
    if (!raw) return '';
    const s = raw.trim();
    const match = s.match(/(\d+(?:[.,]\d+)?)/);
    return match ? match[1].replace(',', '.') : s;
  }

  /**
   * Clean price string: remove currency text, normalize decimal separators.
   * "2 345,50 руб." → "2345.50"
   * "1 234.56 RUB"  → "1234.56"
   * "от 999 рублей"  → "999"
   * "12 500 ₽"       → "12500"
   */
  function normalizePrice(raw) {
    let s = raw.trim();

    // Remove leading text like "от", "от ", "цена:", "price:" etc.
    s = s.replace(/^(?:от|от\s+|цена[:\s]*|price[:\s]*)/i, '').trim();

    // Remove currency words/symbols at end or beginning
    s = s.replace(/\s*(руб\.?|рублей|рубля|р\.|RUB|₽|руб|currency\s*[:=]\s*["']?RUB["']?)\s*/gi, ' ').trim();

    // Remove trailing dots left after "руб."
    s = s.replace(/\.$/, '').trim();

    // Now extract the numeric part: digits, spaces (thousands sep), commas, dots
    const match = s.match(/(\d[\d\s.,]*\d|\d+)/);
    if (!match) return raw.trim(); // No number found — return as-is

    let num = match[1];

    // Remove thousand separators (spaces)
    num = num.replace(/\s/g, '');

    // Handle comma vs dot:
    // "2345,50" → "2345.50" (comma as decimal sep)
    // "2,345.50" → "2345.50" (comma as thousand sep)
    // "2.345,50" → "2345.50" (dot as thousand sep, comma as decimal)
    // "2345.50" → "2345.50" (already correct)
    const commaCount = (num.match(/,/g) || []).length;
    const dotCount = (num.match(/\./g) || []).length;

    if (commaCount === 1 && dotCount === 0) {
      // "2345,50" → comma is decimal separator
      num = num.replace(',', '.');
    } else if (dotCount === 1 && commaCount === 0) {
      // "2345.50" → dot is decimal separator (already correct)
      // But "2.345" could be thousand separator if no decimals follow
      // Heuristic: if exactly 3 digits after dot, it's a thousand sep
      const afterDot = num.split('.')[1];
      if (afterDot && afterDot.length === 3) {
        num = num.replace('.', ''); // thousand separator
      }
    } else if (commaCount >= 1 && dotCount >= 1) {
      // Mixed: determine which is decimal
      const lastComma = num.lastIndexOf(',');
      const lastDot = num.lastIndexOf('.');
      if (lastComma > lastDot) {
        // "2.345,50" → comma is decimal, dot is thousands
        num = num.replace(/\./g, '').replace(',', '.');
      } else {
        // "2,345.50" → dot is decimal, comma is thousands
        num = num.replace(/,/g, '');
      }
    } else if (commaCount > 1) {
      // "1,234,567" → commas are thousands
      num = num.replace(/,/g, '');
    } else if (dotCount > 1) {
      // "1.234.567" → dots are thousands
      num = num.replace(/\./g, '');
    }

    // Final validation: should be a valid number
    const parsed = parseFloat(num);
    if (isNaN(parsed)) return raw.trim();

    // Return clean number string (no trailing zeros issues)
    return parsed.toString();
  }

  // ============================================================
  // Event Handlers
  // ============================================================

  function onMouseMove(e) {
    if (!captureMode) return;
    const el = document.elementFromPoint(e.clientX, e.clientY);
    if (el) highlightElement(el);
  }

  function onElementClick(e) {
    if (!captureMode || !activeField) return;

    // Ignore clicks on our UI
    if (e.target.closest('#prizm-capture-overlay, #prizm-capture-tooltip')) {
      if (e.target.id === 'prizm-cancel-capture') {
        stopCapture();
      }
      return;
    }

    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();

    const el = hoveredElement || e.target;
    let value = extractText(el);
    const selector = generateSelector(el);
    const xpath = generateXPath(el);

    // Normalize value based on field type
    value = normalizeFieldValue(activeField, value);

    // Store captured data
    capturedData[activeField] = {
      value,
      selector,
      xpath,
      tagName: el.tagName.toLowerCase(),
    };

    // Mark the element visually
    addCapturedMarker(el, activeField);

    // Notify popup. The popup may be closed (e.g. user captured via context menu)
    // so we suppress the "Could not establish connection" error silently.
    chrome.runtime.sendMessage({
      action: 'FIELD_CAPTURED',
      data: { field: activeField, value, selector, xpath },
    }).catch(() => { /* popup closed or service worker unavailable — not an error */ });

    stopCapture();
  }

  function onKeyDown(e) {
    if (e.key === 'Escape') {
      stopCapture();
    }
  }

  /**
   * Add a visual marker on captured elements.
   * Saves the element's original inline styles to the registry before mutating them,
   * so they can be restored by destroyUI / restoreAllStyles.
   */
  function addCapturedMarker(el, field) {
    // Remove previous marker for this field
    document.querySelectorAll(`.prizm-captured-marker[data-field="${field}"]`).forEach(m => m.remove());

    // If this element was previously highlighted during a hover, clear hover first
    // so we don't snapshot the temporary solid outline as the "original".
    if (hoveredElement === el) clearHighlight();

    const marker = document.createElement('div');
    marker.className = 'prizm-captured-marker';
    marker.dataset.field = field;
    marker.textContent = FIELD_LABELS[field];
    marker.style.backgroundColor = FIELD_COLORS[field];

    const rect = el.getBoundingClientRect();
    marker.style.position = 'fixed';
    marker.style.top = `${rect.top - 18}px`;
    marker.style.left = `${rect.left}px`;
    marker.style.zIndex = '2147483646';

    document.body.appendChild(marker);

    // Record original style BEFORE applying the permanent dashed outline.
    // saveCaptureStyle is idempotent: only saves once per element.
    saveCaptureStyle(el);
    el.style.outline = `2px dashed ${FIELD_COLORS[field]}`;
    el.style.outlineOffset = '1px';
  }

  /**
   * Try to apply a template's selectors to the current page.
   * Returns extracted values or errors per field.
   */
  function applyTemplate(selectors) {
    const result = {};
    const errors = [];

    for (const [field, selectorStr] of Object.entries(selectors)) {
      if (!selectorStr) continue;

      try {
        let el = null;

        // Try CSS selector first
        if (!selectorStr.startsWith('/')) {
          el = document.querySelector(selectorStr);
        }

        // Try XPath if CSS failed or selector is XPath
        if (!el && (selectorStr.startsWith('/') || selectorStr.startsWith('.'))) {
          const xpathResult = document.evaluate(
            selectorStr,
            document,
            null,
            XPathResult.FIRST_ORDERED_NODE_TYPE,
            null
          );
          el = xpathResult.singleNodeValue;
        }

        if (el) {
          result[field] = {
            value: normalizeFieldValue(field, extractText(el)),
            selector: selectorStr,
            found: true,
          };
        } else {
          result[field] = {
            value: null,
            selector: selectorStr,
            found: false,
          };
          errors.push(`Селектор для "${FIELD_LABELS[field] || field}" не нашёл элемент: ${selectorStr}`);
        }
      } catch (err) {
        result[field] = {
          value: null,
          selector: selectorStr,
          found: false,
          error: err.message,
        };
        errors.push(`Ошибка селектора "${FIELD_LABELS[field] || field}": ${err.message}`);
      }
    }

    return { fields: result, errors };
  }

  function getMetaContent(selector, attribute = 'content') {
    const el = document.querySelector(selector);
    if (!el) return '';
    return (el.getAttribute(attribute) || '').trim();
  }

  function getFirstTextFromSelectors(selectors) {
    for (const selector of selectors) {
      try {
        const el = document.querySelector(selector);
        if (!el) continue;
        const text = extractText(el);
        if (text && text.length >= 2) {
          return { value: text, selector };
        }
      } catch {
        // ignore selector errors in heuristic mode
      }
    }
    return { value: '', selector: null };
  }

  function cleanupTitle(raw) {
    if (!raw) return '';
    const title = raw.replace(/\s+/g, ' ').trim();
    if (title.length < 3) return '';
    // Remove common browser-title suffixes: "Product - Store"
    const split = title.split(/\s+[\-|·|•]\s+/);
    return split[0] || title;
  }

  function findArticleFromDom() {
    // Fast path 1: structured microdata attributes with explicit content
    const schemaSku = getMetaContent('[itemprop="sku"][content]') || getMetaContent('[itemprop="mpn"][content]');
    if (schemaSku) {
      return { value: schemaSku, selector: '[itemprop="sku"]', warning: null };
    }

    // Fast path 2: common labelled CSS selectors — no text scanning needed
    const directSelectors = [
      '[itemprop="sku"]', '[itemprop="mpn"]',
      '[data-sku]', '[data-article]', '[data-product-code]', '[data-code]',
      '.product-sku', '.product-article', '.product-code',
      '.sku', '.article', '.artikul',
      '#product-sku', '#product-article', '#product-code',
    ];
    for (const sel of directSelectors) {
      try {
        const el = document.querySelector(sel);
        if (!el) continue;
        const text = extractText(el).trim();
        // Validate: short, no excess whitespace, looks like an article code
        if (text && text.length >= 2 && text.length <= 60 && !/\s{3,}/.test(text)) {
          return { value: text, selector: sel, warning: null };
        }
      } catch { /* ignore invalid selectors on unusual pages */ }
    }

    // Slow path: text-pattern scan, scoped to product-detail containers first.
    // Fall back to body * only if no product sections found, with a node cap.
    const candidates = [];
    let elementsToScan;

    const productContainers = document.querySelectorAll(
      '.product-details, .product-info, .product-meta, ' +
      '.product-attributes, .product-card, .card-body, ' +
      '[class*="detail"], [class*="product"]'
    );

    if (productContainers.length > 0) {
      const seen = new Set();
      elementsToScan = [];
      productContainers.forEach(root => {
        root.querySelectorAll('*').forEach(el => {
          if (!seen.has(el)) { seen.add(el); elementsToScan.push(el); }
        });
      });
    } else {
      // No product containers found — limit full-body scan to 1500 nodes
      elementsToScan = Array.from(document.querySelectorAll('body *')).slice(0, 1500);
    }

    for (const el of elementsToScan) {
      if (candidates.length >= 4) break;
      // Skip container elements: prefer leaf-ish nodes with short text
      if (el.children.length > 5) continue;
      const text = (extractText(el) || '').replace(/\s+/g, ' ').trim();
      if (!text || text.length > 120) continue;
      const match = text.match(/(?:артикул|sku|код(?:\s+товара)?)\s*[:№]?\s*([A-Za-zА-Яа-я0-9\-_./]{2,})/i);
      if (match && match[1]) {
        candidates.push(match[1]);
      }
    }

    const unique = Array.from(new Set(candidates));
    if (unique.length === 1) {
      return { value: unique[0], selector: null, warning: null };
    }
    if (unique.length > 1) {
      return { value: '', selector: null, warning: 'Найдено несколько вариантов артикула, выберите нужный вручную.' };
    }
    return { value: '', selector: null, warning: null };
  }

  function findPriceFromDom() {
    const strongMeta =
      getMetaContent('meta[property="product:price:amount"]') ||
      getMetaContent('meta[itemprop="price"]', 'content') ||
      getMetaContent('[itemprop="price"][content]');

    if (strongMeta) {
      const normalized = normalizePrice(strongMeta);
      if (normalized && !isNaN(parseFloat(normalized))) {
        return { value: normalized, selector: 'meta:price', warning: null };
      }
    }

    const selectors = [
      '[itemprop="price"]',
      '[data-price]',
      '.price',
      '.product-price',
      '.card-price',
      '[class*="price"]',
      '[id*="price"]',
    ];

    const values = [];
    selectors.forEach((selector) => {
      document.querySelectorAll(selector).forEach((el) => {
        const raw = extractText(el);
        const normalized = normalizePrice(raw);
        const num = parseFloat(normalized);
        if (!isNaN(num) && num > 0) {
          values.push(normalized);
        }
      });
    });

    const unique = Array.from(new Set(values));
    if (unique.length === 1) {
      return { value: unique[0], selector: 'dom:price', warning: null };
    }
    if (unique.length > 1) {
      return { value: '', selector: null, warning: 'Найдено несколько похожих цен, проверьте нужную.' };
    }

    return { value: '', selector: null, warning: 'Не удалось определить цену на странице.' };
  }

  function autoDetectFields() {
    const warnings = [];
    const result = {};

    const schema = extractSchemaData();
    const schemaFields = schema?.found ? (schema.schemas?.[0]?.fields || []) : [];
    const schemaByPath = Object.fromEntries(schemaFields.map((f) => [f.path, f.value]));

    const titleCandidate =
      schemaByPath.name ||
      getMetaContent('meta[property="og:title"]') ||
      getFirstTextFromSelectors(['h1[itemprop="name"]', '[itemprop="name"]', 'h1', '.product-title', '.card-title']).value ||
      document.title;
    const title = cleanupTitle(titleCandidate);
    if (title) {
      result.title = { value: title, auto: true, schema: !!schemaByPath.name, selector: null };
    } else {
      warnings.push('Не удалось определить название автоматически.');
    }

    const priceFromSchema = schemaByPath['offers.price'] || schemaByPath['offers.lowPrice'] || schemaByPath.price;
    if (priceFromSchema) {
      const normalized = normalizePrice(String(priceFromSchema));
      const asNumber = parseFloat(normalized);
      if (!isNaN(asNumber) && asNumber > 0) {
        result.price = { value: normalized, auto: true, schema: true, selector: null };
      }
    }
    if (!result.price) {
      const priceDetected = findPriceFromDom();
      if (priceDetected.value) {
        result.price = { value: priceDetected.value, auto: true, selector: priceDetected.selector };
      }
      if (priceDetected.warning) warnings.push(priceDetected.warning);
    }

    const articleFromSchema = schemaByPath.sku || schemaByPath.mpn;
    if (articleFromSchema) {
      result.article = { value: String(articleFromSchema), auto: true, schema: true, selector: null };
    } else {
      const articleDetected = findArticleFromDom();
      if (articleDetected.value) {
        result.article = { value: articleDetected.value, auto: true, selector: articleDetected.selector };
      }
      if (articleDetected.warning) warnings.push(articleDetected.warning);
    }

    // Material type detection and dimension parsing delegated to backend.
    // The extension only collects raw title/price/article from the page.

    const uniqueWarnings = Array.from(new Set(warnings));

    return {
      fields: result,
      warnings: uniqueWarnings,
      foundCount: Object.keys(result).length,
    };
  }

  // ============================================================
  // Schema.org / JSON-LD / Microdata extraction
  // ============================================================

  /**
   * Extract ALL Schema.org structured data from the page.
   * Returns a flat list of key-value fields that the user can map.
   */
  function extractSchemaData() {
    const rawSchemas = [];

    // 1. JSON-LD
    document.querySelectorAll('script[type="application/ld+json"]').forEach(script => {
      try {
        let data = JSON.parse(script.textContent);
        if (data['@graph']) data = data['@graph'];
        const items = Array.isArray(data) ? data : [data];
        items.forEach(item => {
          if (item['@type'] === 'Product' || item['@type']?.includes?.('Product')) {
            rawSchemas.push({ source: 'json-ld', fields: flattenSchema(item, '') });
          }
        });
      } catch { /* invalid JSON */ }
    });

    // 2. Microdata
    document.querySelectorAll('[itemscope][itemtype*="schema.org/Product"]').forEach(el => {
      try {
        const parsed = parseMicrodataClean(el);
        rawSchemas.push({ source: 'microdata', fields: flattenSchema(parsed, '') });
      } catch { /* malformed microdata */ }
    });

    // 3. Merge multiple Product schemas of the same source type.
    //    Some sites split Product data across several tags (e.g. one tag has
    //    only @type+sku, another has name+description+price). We merge them
    //    into one combined schema so the user sees all fields together.
    const schemas = mergeProductSchemas(rawSchemas);

    return {
      found: schemas.length > 0,
      schemas,
    };
  }

  /**
   * Merge multiple Product schemas that originate from the same source type.
   * Fields from all schemas are combined; when there are duplicates for the
   * same path, the value from the schema with MORE total fields wins
   * (heuristic: the richer schema is the "main" one).
   * The result always includes:
   *   [0] = merged/combined schema  (if there were ≥2 schemas)
   *   [1..N] = original individual schemas (for manual selection)
   * If there is only one schema, no merging is needed.
   */
  function mergeProductSchemas(rawSchemas) {
    if (rawSchemas.length <= 1) return rawSchemas;

    // Group schemas by source type (json-ld / microdata)
    const bySource = {};
    for (const s of rawSchemas) {
      (bySource[s.source] = bySource[s.source] || []).push(s);
    }

    const result = [];

    for (const [source, group] of Object.entries(bySource)) {
      if (group.length <= 1) {
        result.push(...group);
        continue;
      }

      // Sort: schema with the most fields first (richest data)
      const sorted = [...group].sort((a, b) => b.fields.length - a.fields.length);

      // Build merged field map  (path → { path, value })
      const fieldMap = new Map();
      for (const schema of sorted) {
        for (const field of schema.fields) {
          // First occurrence wins (from the richest schema)
          if (!fieldMap.has(field.path)) {
            fieldMap.set(field.path, field);
          }
        }
      }

      const mergedFields = Array.from(fieldMap.values());

      // Only prepend a merged schema if it actually adds value
      // (i.e. has more fields than the richest individual schema)
      if (mergedFields.length > sorted[0].fields.length) {
        result.push({
          source,
          merged: true,
          mergedCount: group.length,
          fields: mergedFields,
        });
      }

      // Keep individual schemas for manual inspection
      result.push(...group);
    }

    return result;
  }

  /**
   * Parse Microdata, properly scoping nested elements.
   * Only reads direct itemprop children of the current scope.
   */
  function parseMicrodataClean(scopeEl) {
    const data = {};
    // Get direct itemprop children (not inside nested itemscopes)
    const allProps = scopeEl.querySelectorAll('[itemprop]');

    allProps.forEach(el => {
      // Find the closest parent itemscope — skip if it's not our scope
      const closestScope = el.parentElement?.closest('[itemscope]');
      if (closestScope && closestScope !== scopeEl) return;

      const prop = el.getAttribute('itemprop');
      let value;

      if (el.hasAttribute('itemscope')) {
        // Nested scope (Offer, Brand, PropertyValue)
        value = parseMicrodataClean(el);
        value['@type'] = (el.getAttribute('itemtype') || '').split('/').pop();
      } else if (el.hasAttribute('content')) {
        value = el.getAttribute('content');
      } else if (el.tagName === 'META') {
        value = el.getAttribute('content');
      } else if (el.tagName === 'LINK') {
        value = el.getAttribute('href');
      } else if (el.tagName === 'IMG') {
        value = el.getAttribute('src');
      } else if (el.tagName === 'TIME') {
        value = el.getAttribute('datetime') || el.textContent.trim();
      } else {
        // Get only direct text, not from nested itemprop children
        value = getDirectText(el);
      }

      // Handle multiple values (additionalProperty)
      if (data[prop] !== undefined) {
        if (!Array.isArray(data[prop])) data[prop] = [data[prop]];
        data[prop].push(value);
      } else {
        data[prop] = value;
      }
    });

    return data;
  }

  /**
   * Get direct text content of element, excluding child itemprop elements.
   */
  function getDirectText(el) {
    // If it has no itemprop children, just return textContent
    if (!el.querySelector('[itemprop]')) {
      return el.innerText?.trim() || el.textContent?.trim() || '';
    }
    // Otherwise collect only direct text nodes and non-itemprop children
    let text = '';
    el.childNodes.forEach(node => {
      if (node.nodeType === Node.TEXT_NODE) {
        text += node.textContent;
      } else if (node.nodeType === Node.ELEMENT_NODE && !node.hasAttribute('itemprop') && !node.querySelector('[itemprop]')) {
        text += node.innerText || node.textContent || '';
      }
    });
    return text.trim().replace(/\s+/g, ' ').replace(/,$/, '').trim();
  }

  /**
   * Flatten a schema object into a list of { path, label, value } entries
   * for user-friendly display and mapping.
   */
  function flattenSchema(obj, prefix) {
    const fields = [];
    if (!obj || typeof obj !== 'object') return fields;

    for (const [key, val] of Object.entries(obj)) {
      if (key.startsWith('_') || key === '@context') continue;

      const path = prefix ? `${prefix}.${key}` : key;

      if (Array.isArray(val)) {
        val.forEach((item, i) => {
          if (item && typeof item === 'object') {
            // Nested object in array (additionalProperty[0])
            const subFields = flattenSchema(item, `${path}[${i}]`);
            fields.push(...subFields);
          } else {
            fields.push({ path: `${path}[${i}]`, value: String(item ?? '') });
          }
        });
      } else if (val && typeof val === 'object') {
        const subFields = flattenSchema(val, path);
        fields.push(...subFields);
      } else if (val != null && val !== '') {
        fields.push({ path, value: String(val) });
      }
    }

    return fields;
  }

  /**
   * Apply user-defined schema mapping to captured data.
   * mapping = { title: 'name', price: 'offers.price', article: 'sku', ... }
   */
  function applySchemaMapping(schemaFields, mapping) {
    const result = {};

    for (const [captureField, schemaPath] of Object.entries(mapping)) {
      const entry = schemaFields.find(f => f.path === schemaPath);
      if (entry && entry.value) {
        result[captureField] = {
          value: normalizeFieldValue(captureField, entry.value),
          selector: null,
          source: 'schema.org',
          schemaPath: schemaPath,
        };
      }
    }

    return result;
  }

  function hasSchemaType(node, expectedType) {
    if (!node || typeof node !== 'object') return false;
    const schemaType = node['@type'];
    if (Array.isArray(schemaType)) {
      return schemaType.some((item) => String(item || '').toLowerCase() === String(expectedType).toLowerCase());
    }
    return String(schemaType || '').toLowerCase() === String(expectedType).toLowerCase();
  }

  function collectJsonLdNodes(root, bucket, visited = new Set()) {
    if (!root || typeof root !== 'object') return;

    if (Array.isArray(root)) {
      root.forEach((item) => collectJsonLdNodes(item, bucket, visited));
      return;
    }

    if (visited.has(root)) return;
    visited.add(root);
    bucket.push(root);

    if (root['@graph']) {
      collectJsonLdNodes(root['@graph'], bucket, visited);
    }

    Object.values(root).forEach((value) => {
      if (value && typeof value === 'object') {
        collectJsonLdNodes(value, bucket, visited);
      }
    });
  }

  function getJsonLdNodes() {
    const nodes = [];
    document.querySelectorAll('script[type="application/ld+json"]').forEach((script) => {
      try {
        const parsed = JSON.parse(script.textContent);
        collectJsonLdNodes(parsed, nodes);
      } catch {
        // Ignore invalid JSON-LD blocks.
      }
    });
    return nodes;
  }

  function firstNonEmptyValue(...values) {
    for (const value of values) {
      if (Array.isArray(value)) {
        const nested = firstNonEmptyValue(...value);
        if (nested) return nested;
        continue;
      }
      if (value == null) continue;
      const stringValue = String(value).trim();
      if (stringValue) return stringValue;
    }
    return '';
  }

  function getNestedValue(source, path) {
    if (!source || !path) return undefined;
    const parts = Array.isArray(path) ? path : String(path).split('.');
    let current = source;
    for (const part of parts) {
      if (current == null) return undefined;
      if (Array.isArray(current)) {
        current = current.find((item) => item != null);
      }
      current = current?.[part];
    }
    return current;
  }

  function getNestedString(source, path) {
    const value = getNestedValue(source, path);
    return firstNonEmptyValue(value);
  }

  function normalizeSchemaNumber(value) {
    if (value == null || value === '') return '';
    if (typeof value === 'number' && Number.isFinite(value)) {
      return String(value);
    }
    const stringValue = String(value).trim();
    if (!stringValue) return '';
    const normalized = stringValue.replace(/\s+/g, '').replace(',', '.');
    const asNumber = Number(normalized);
    if (!Number.isNaN(asNumber) && Number.isFinite(asNumber)) {
      return String(asNumber);
    }
    return stringValue;
  }

  function mapSalaryUnitText(unitText) {
    const value = String(unitText || '').trim().toLowerCase();
    if (!value) return '';
    if (['month', 'monthly', 'mon', 'months', 'мес', 'месяц', 'месяц.', 'monthtext'].includes(value) || /month|мес|месяц/.test(value)) {
      return 'month';
    }
    if (['hour', 'hourly', 'hr', 'ч', 'час', 'час.'].includes(value) || /hour|час|\/ч/.test(value)) {
      return 'hour';
    }
    if (['year', 'yearly', 'annual', 'yr', 'год', 'года', 'год.'].includes(value) || /year|annual|год/.test(value)) {
      return 'year';
    }
    return '';
  }

  function detectSalaryPeriodFromText(text) {
    const value = String(text || '').trim().toLowerCase();
    if (!value) return '';
    if (/\/\s*мес\b|мес(?:яц)?\.?|месяц|в\s*месяц|за\s*месяц/.test(value)) return 'month';
    if (/\/\s*ч\b|\/ч\b|час|в\s*час|за\s*час|\bч\b/.test(value)) return 'hour';
    if (/день|в\s*день|за\s*день/.test(value)) return 'day';
    if (/год|в\s*год|за\s*год/.test(value)) return 'year';
    return '';
  }

  function normalizeWhitespace(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
  }

  function getCurrentHostname() {
    return (window.location.hostname || '').replace(/^www\./, '').toLowerCase();
  }

  function isVisibleElement(el) {
    try {
      if (!el || !(el instanceof Element)) return false;
      const style = window.getComputedStyle(el);
      if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0) {
        return false;
      }
      const rect = el.getBoundingClientRect();
      return rect.width > 0 && rect.height > 0;
    } catch {
      return false;
    }
  }

  function isNoiseText(text) {
    return /похожие вакансии|рекоменд|поделиться|footer|реклама|продвиг|legal|условия использования|политика конфиденциальности|другие объявления/i.test(String(text || ''));
  }

  function isNoiseContainer(el) {
    try {
      if (!el || !(el instanceof Element)) return false;
      const noiseSelector = [
        'aside',
        'footer',
        '[class*="sidebar"]',
        '[class*="recommend"]',
        '[class*="similar"]',
        '[class*="promo"]',
        '[class*="banner"]',
        '[class*="advert"]',
        '[data-qa*="similar"]',
        '[data-qa*="recommend"]',
      ].join(', ');
      return !!el.closest(noiseSelector);
    } catch {
      return false;
    }
  }

  function getTitleAnchorElement() {
    try {
      return document.querySelector('[data-qa="vacancy-title"], h1');
    } catch {
      return null;
    }
  }

  function isInsidePrimaryContent(el) {
    try {
      return !!el?.closest('main, article, [role="main"], [class*="content"], [class*="vacancy"], [class*="job"]');
    } catch {
      return false;
    }
  }

  function distanceToTitle(el) {
    try {
      const titleEl = getTitleAnchorElement();
      if (!titleEl || !el) return Number.POSITIVE_INFINITY;
      if (titleEl === el || titleEl.contains(el) || el.contains(titleEl)) return 0;
      const titleRect = titleEl.getBoundingClientRect();
      const elRect = el.getBoundingClientRect();
      return Math.abs(elRect.top - titleRect.bottom);
    } catch {
      return Number.POSITIVE_INFINITY;
    }
  }

  function scoreVacancyCandidate(el, text, options = {}) {
    const value = normalizeWhitespace(text);
    if (!value) return Number.NEGATIVE_INFINITY;

    let score = 0;
    if (isVisibleElement(el)) score += 4;
    if (isInsidePrimaryContent(el)) score += 5;
    if (isNoiseContainer(el)) score -= 8;
    if (isNoiseText(value)) score -= 10;

    const titleDistance = distanceToTitle(el);
    if (Number.isFinite(titleDistance)) {
      if (titleDistance <= 120) score += 6;
      else if (titleDistance <= 300) score += 3;
      else if (titleDistance > 900) score -= 2;
    }

    const length = value.length;
    if (options.kind === 'title') {
      if (length >= 8 && length <= 160) score += 6;
      else score -= 4;
      if (el?.tagName === 'H1') score += 8;
    }
    if (options.kind === 'employer') {
      if (length >= 2 && length <= 120) score += 4;
      if (/(ооо|ип|llc|inc|corp|company|компания)/i.test(value)) score += 2;
      if (/(вакансия|работодатель|зарплата|обязанности|откликнуться|похожие вакансии)/i.test(value)) score -= 10;
    }
    if (options.kind === 'salary') {
      if (/(₽|руб|\$|€|usd|eur)/i.test(value)) score += 7;
      if (/\d/.test(value)) score += 4;
      if (length <= 160) score += 2;
      if (/похожие|рекоменд|цена доставки|скидка/i.test(value)) score -= 12;
    }
    if (options.kind === 'description') {
      if (length >= 180 && length <= 5000) score += 5;
      if (el?.matches?.('[data-qa="vacancy-description"], article, main article')) score += 6;
      if (/похожие вакансии|рекоменд|откликнуться|поделиться|компания также предлагает/i.test(value)) score -= 12;
    }

    return score;
  }

  function chooseBestTextCandidate(candidates, options = {}) {
    const minScore = options.minScore ?? 1;
    const normalized = candidates
      .map((candidate) => ({
        ...candidate,
        text: normalizeWhitespace(candidate.text),
      }))
      .filter((candidate) => candidate.text)
      .map((candidate) => ({
        ...candidate,
        score: scoreVacancyCandidate(candidate.el, candidate.text, options),
      }))
      .sort((a, b) => b.score - a.score);

    if (!normalized.length) return '';
    if (normalized[0].score < minScore) return '';
    return normalized[0].text;
  }

  function collectCandidatesFromSelectors(selectors, options = {}) {
    const candidates = [];
    selectors.forEach((selector) => {
      try {
        document.querySelectorAll(selector).forEach((el) => {
          const text = normalizeWhitespace(extractText(el));
          if (!text) return;
          if (options.minLength && text.length < options.minLength) return;
          if (options.maxLength && text.length > options.maxLength) return;
          candidates.push({ el, text });
        });
      } catch {
        // Ignore invalid selectors in heuristic mode.
      }
    });
    return candidates;
  }

  function parseSalaryNumber(raw) {
    const normalized = normalizeWhitespace(raw).replace(/[^\d,.\s]/g, '').trim();
    if (!normalized) return '';
    const compact = normalized.replace(/\s+/g, '').replace(',', '.');
    const parsed = Number(compact);
    if (!Number.isNaN(parsed) && Number.isFinite(parsed)) {
      return String(parsed);
    }
    return '';
  }

  function parseSalaryFromText(text) {
    const salaryRaw = normalizeWhitespace(text);
    if (!salaryRaw) return {};

    const result = {
      salary_raw_text: salaryRaw,
      salary_period: detectSalaryPeriodFromText(salaryRaw),
    };

    const rangeDashMatch = salaryRaw.match(/(\d[\d\s]{1,20})\s*[–-]\s*(\d[\d\s]{1,20})/u);
    if (rangeDashMatch) {
      const minValue = parseSalaryNumber(rangeDashMatch[1]);
      const maxValue = parseSalaryNumber(rangeDashMatch[2]);
      if (minValue) result.salary_value_min = minValue;
      if (maxValue) result.salary_value_max = maxValue;
      return result;
    }

    const rangeWordsMatch = salaryRaw.match(/от\s+(\d[\d\s]{1,20})\s+до\s+(\d[\d\s]{1,20})/iu);
    if (rangeWordsMatch) {
      const minValue = parseSalaryNumber(rangeWordsMatch[1]);
      const maxValue = parseSalaryNumber(rangeWordsMatch[2]);
      if (minValue) result.salary_value_min = minValue;
      if (maxValue) result.salary_value_max = maxValue;
      return result;
    }

    const upperBoundMatch = salaryRaw.match(/до\s+(\d[\d\s]{1,20})/iu);
    if (upperBoundMatch) {
      const maxValue = parseSalaryNumber(upperBoundMatch[1]);
      if (maxValue) result.salary_value_max = maxValue;
      return result;
    }

    const singleValueMatch = salaryRaw.match(/(\d[\d\s]{1,20})(?:\s*(?:₽|руб|руб\.|\$|usd|eur|€))?/iu);
    if (singleValueMatch) {
      const singleValue = parseSalaryNumber(singleValueMatch[1]);
      if (singleValue) {
        result.salary_value = singleValue;
      }
    }

    return result;
  }

  function pickFirstReasonableText(selectors, options = {}) {
    const minLength = options.minLength || 2;
    const maxLength = options.maxLength || 240;
    const rejectPattern = options.rejectPattern || null;

    for (const selector of selectors) {
      try {
        const el = document.querySelector(selector);
        if (!el) continue;
        const text = normalizeWhitespace(extractText(el));
        if (!text || text.length < minLength || text.length > maxLength) continue;
        if (rejectPattern && rejectPattern.test(text)) continue;
        return text;
      } catch {
        // Ignore invalid selectors in fallback mode.
      }
    }

    return '';
  }

  function findVacancyTitleFromDom() {
    const candidates = collectCandidatesFromSelectors([
      'h1',
      '[data-qa="vacancy-title"]',
      '[class*="vacancy-title"]',
      '[class*="job-title"]',
      '[class*="title"]',
    ], { minLength: 3, maxLength: 180 });
    const selectorTitle = chooseBestTextCandidate(candidates, { kind: 'title', minScore: 3 });
    if (selectorTitle) return cleanupTitle(selectorTitle);

    const ogTitle = cleanupTitle(getMetaContent('meta[property="og:title"]'));
    if (ogTitle) return ogTitle;

    return cleanupTitle(document.title || '');
  }

  function findEmployerFromDom() {
    const directEmployer = chooseBestTextCandidate(collectCandidatesFromSelectors([
      '[data-qa="vacancy-company-name"]',
      '.vacancy-company-name',
      '.company-name',
      '[class*="company-name"]',
      '[class*="company"] [class*="name"]',
      '[class*="employer"]',
      '[class*="company"]',
    ], {
      minLength: 2,
      maxLength: 120,
    }), { kind: 'employer', minScore: 2 });
    if (directEmployer) return directEmployer;

    try {
      const titleEl = document.querySelector('[data-qa="vacancy-title"], h1');
      if (titleEl?.parentElement) {
        const nearbyCandidates = Array.from(titleEl.parentElement.querySelectorAll('*'))
          .map((el) => ({ el, text: normalizeWhitespace(extractText(el)) }))
          .filter((candidate) => candidate.text && candidate.text.length >= 2 && candidate.text.length <= 120);
        const nearbyText = chooseBestTextCandidate(nearbyCandidates, { kind: 'employer', minScore: 2 });
        if (nearbyText) return nearbyText;
      }
    } catch {
      // Ignore DOM traversal issues.
    }

    return '';
  }

  function findSalaryTextFromDom() {
    const directSalary = chooseBestTextCandidate(collectCandidatesFromSelectors([
      '[data-qa="vacancy-salary"]',
      '.vacancy-salary',
      '[class*="salary"]',
      '[class*="compensation"]',
      '[class*="income"]',
      '[class*="pay"]',
    ], {
      minLength: 3,
      maxLength: 160,
    }), { kind: 'salary', minScore: 4 });
    if (directSalary && /₽|руб|\$|€|eur|usd|\d/.test(directSalary)) {
      return directSalary;
    }

    try {
      const titleEl = getTitleAnchorElement();
      const scopeRoot = titleEl?.closest('main, article, [role="main"], [class*="content"], [class*="vacancy"], [class*="job"]') || document.body;
      const candidates = Array.from(scopeRoot.querySelectorAll('*'))
        .slice(0, 500)
        .map((el) => ({ el, text: normalizeWhitespace(extractText(el)) }))
        .filter((candidate) => candidate.text && candidate.text.length >= 3 && candidate.text.length <= 160)
        .filter((candidate) => /₽|руб|\$|€|eur|usd/.test(candidate.text));
      return chooseBestTextCandidate(candidates, { kind: 'salary', minScore: 4 }) || '';
    } catch {
      return '';
    }
  }

  function findVacancyDescriptionFromDom() {
    const directDescription = chooseBestTextCandidate(collectCandidatesFromSelectors([
      '[data-qa="vacancy-description"]',
      'article',
      'main article',
      'main section',
      '[class*="description"]',
      '[class*="vacancy-description"]',
    ], {
      minLength: 120,
      maxLength: 4000,
    }), { kind: 'description', minScore: 5 });
    if (directDescription) return directDescription;

    try {
      const candidates = Array.from(document.querySelectorAll('main, article, section'))
        .map((el) => ({ el, text: normalizeWhitespace(extractText(el)) }))
        .filter((candidate) => candidate.text && candidate.text.length >= 120 && candidate.text.length <= 4000);
      return chooseBestTextCandidate(candidates, { kind: 'description', minScore: 6 }) || '';
    } catch {
      return '';
    }
  }

  function extractSiteAwareFallbackData(existingPayload = {}) {
    const hostname = getCurrentHostname();
    const result = {};

    function fillIfMissing(field, value) {
      const normalized = normalizeWhitespace(value);
      if (!normalized) return;
      if (existingPayload[field] || result[field]) return;
      result[field] = normalized;
    }

    try {
      if (hostname.endsWith('hh.ru')) {
        fillIfMissing('vacancy_title', pickFirstReasonableText(['[data-qa="vacancy-title"]'], { minLength: 3, maxLength: 180 }));
        fillIfMissing('employer_name', pickFirstReasonableText(['[data-qa="vacancy-company-name"]'], { minLength: 2, maxLength: 120 }));
        const hhSalary = pickFirstReasonableText(['[data-qa="vacancy-salary"]'], { minLength: 3, maxLength: 160 });
        if (!existingPayload.salary_raw_text && hhSalary) {
          Object.assign(result, parseSalaryFromText(hhSalary));
        }
        fillIfMissing('vacancy_description', pickFirstReasonableText(['[data-qa="vacancy-description"]'], { minLength: 120, maxLength: 4000 }));
      }

      if (hostname.endsWith('avito.ru')) {
        fillIfMissing('vacancy_title', pickFirstReasonableText(['[itemprop="name"]', '[data-marker*="item-title"]', 'h1'], { minLength: 3, maxLength: 180 }));
        const avitoSalary = pickFirstReasonableText(['[itemprop="price"]', '[data-marker*="item-price"]', '[class*="price"]'], { minLength: 3, maxLength: 160 });
        if (!existingPayload.salary_raw_text && avitoSalary) {
          Object.assign(result, parseSalaryFromText(avitoSalary));
        }
      }
    } catch {
      // Site-aware fallbacks are best-effort only.
    }

    return result;
  }

  function extractOfferData(offerNode) {
    const offer = Array.isArray(offerNode)
      ? (offerNode.find((item) => item && typeof item === 'object') || offerNode[0])
      : offerNode;

    if (!offer || typeof offer !== 'object') return {};

    const result = {};
    const priceValue = normalizeSchemaNumber(offer.price);
    if (priceValue) {
      result.salary_value = priceValue;
      result.salary_value_source = 'offer';
    }
    const currency = firstNonEmptyValue(offer.priceCurrency);
    if (currency) {
      result.currency = currency;
      result.currency_source = 'offer';
    }
    return result;
  }

  function extractDomFallbackData() {
    const result = {};
    const title = findVacancyTitleFromDom();
    if (title) {
      result.vacancy_title = title;
      result.vacancy_title_source = 'dom';
    }

    const employer = findEmployerFromDom();
    if (employer) {
      result.employer_name = employer;
      result.employer_name_source = 'dom';
    }

    const salaryRawText = findSalaryTextFromDom();
    if (salaryRawText) {
      result.salary_raw_text = salaryRawText;
      result.salary_raw_text_source = 'dom';
      const parsed = parseSalaryFromText(salaryRawText);
      Object.assign(result, parsed);
      if (parsed.salary_value) result.salary_value_source = 'dom';
      if (parsed.salary_value_min) result.salary_value_min_source = 'dom';
      if (parsed.salary_value_max) result.salary_value_max_source = 'dom';
      if (parsed.salary_period) result.salary_period_source = 'parsed';
    }

    const description = findVacancyDescriptionFromDom();
    if (description) {
      result.vacancy_description = description;
      result.vacancy_description_source = 'dom';
    }

    return result;
  }

  function setFieldWithSource(target, field, value, source) {
    const normalizedValue = typeof value === 'string' ? value.trim() : value;
    if (normalizedValue == null || String(normalizedValue).trim() === '') return;
    if (target[field] == null || String(target[field]).trim() === '') {
      target[field] = normalizedValue;
      target[`${field}_source`] = source;
    }
  }

  function mergeByPriority(target, source) {
    if (!source || typeof source !== 'object') return target;
    Object.entries(source).forEach(([key, value]) => {
      if (key.endsWith('_source') || key === 'confidence') return;
      const normalizedValue = typeof value === 'string' ? value.trim() : value;
      if (normalizedValue == null || String(normalizedValue).trim() === '') return;
      if (target[key] == null || String(target[key]).trim() === '') {
        target[key] = normalizedValue;
        const sourceKey = `${key}_source`;
        const sourceValue = source[sourceKey];
        if (sourceValue && !target[sourceKey]) {
          target[sourceKey] = sourceValue;
        }
      }
    });
    return target;
  }

  function detectConflicts(...sources) {
    const conflicts = new Set();
    const trackedFields = ['vacancy_title', 'employer_name', 'salary_value', 'salary_value_min', 'salary_value_max', 'salary_raw_text'];
    trackedFields.forEach((field) => {
      const values = sources
        .map((source) => normalizeWhitespace(source?.[field]))
        .filter((value) => value);
      if (values.length <= 1) return;
      const unique = Array.from(new Set(values));
      if (unique.length > 1) {
        conflicts.add(field);
      }
    });
    return conflicts;
  }

  function calculateExtractionConfidence({ schemaData, offerData, domData, siteAwareData, conflicts }) {
    let confidence = 0;
    if (Object.keys(schemaData || {}).some((key) => !key.endsWith('_source'))) confidence += 0.5;
    if (Object.keys(offerData || {}).some((key) => !key.endsWith('_source'))) confidence += 0.2;
    if (domData?.vacancy_title || domData?.salary_raw_text || domData?.employer_name) confidence += 0.2;
    if (
      Object.keys(siteAwareData || {}).some((key) => !key.endsWith('_source')) &&
      !Object.keys(domData || {}).some((key) => !key.endsWith('_source'))
    ) {
      confidence -= 0.2;
    }
    if (conflicts?.size) confidence -= 0.3;
    if (confidence < 0) confidence = 0;
    if (confidence > 1) confidence = 1;
    return Number(confidence.toFixed(2));
  }

  function pickPrimarySchemaNode(nodes, type) {
    const candidates = nodes.filter((node) => hasSchemaType(node, type));
    if (!candidates.length) return null;

    const scored = candidates.map((node) => {
      let score = 0;
      if (firstNonEmptyValue(node.title, node.name)) score += 3;
      if (firstNonEmptyValue(node.description)) score += 2;
      if (getNestedValue(node, 'baseSalary')) score += 2;
      if (getNestedValue(node, 'hiringOrganization')) score += 1;
      if (getNestedValue(node, 'jobLocation')) score += 1;
      score += Object.keys(node || {}).length * 0.01;
      return { node, score };
    });

    scored.sort((a, b) => b.score - a.score);
    return scored[0].node;
  }

  function extractSalaryFromJobPosting(jobPosting, offerNode) {
    const salary = {};
    let baseSalary = jobPosting?.baseSalary;
    if (Array.isArray(baseSalary)) {
      baseSalary = baseSalary.find((item) => item != null) || baseSalary[0];
    }

    let salaryValueNode = baseSalary;
    if (baseSalary && typeof baseSalary === 'object' && baseSalary.value != null) {
      salaryValueNode = baseSalary.value;
    }

    if (salaryValueNode && typeof salaryValueNode === 'object') {
      const exactValue = normalizeSchemaNumber(salaryValueNode.value);
      const minValue = normalizeSchemaNumber(salaryValueNode.minValue);
      const maxValue = normalizeSchemaNumber(salaryValueNode.maxValue);
      if (exactValue) salary.salary_value = exactValue;
      if (minValue) salary.salary_value_min = minValue;
      if (maxValue) salary.salary_value_max = maxValue;
      salary.salary_period = mapSalaryUnitText(salaryValueNode.unitText || salaryValueNode.unitCode);
    } else {
      const exactValue = normalizeSchemaNumber(salaryValueNode);
      if (exactValue) salary.salary_value = exactValue;
    }

    const fallbackOffer = Array.isArray(offerNode)
      ? (offerNode.find((item) => item && typeof item === 'object') || offerNode[0])
      : offerNode;

    if (!salary.salary_value && fallbackOffer?.price != null) {
      const offerPrice = normalizeSchemaNumber(fallbackOffer.price);
      if (offerPrice) salary.salary_value = offerPrice;
    }

    if (!salary.currency) {
      salary.currency = firstNonEmptyValue(
        baseSalary?.currency,
        getNestedValue(baseSalary, 'value.currency'),
        fallbackOffer?.priceCurrency,
        jobPosting?.salaryCurrency
      );
    }

    return salary;
  }

  function extractJobPostingData() {
    const jsonLdNodes = getJsonLdNodes();
    const jobPosting = pickPrimarySchemaNode(jsonLdNodes, 'JobPosting');
    const offerNode = jobPosting?.offers || pickPrimarySchemaNode(jsonLdNodes, 'Offer');

    const schemaData = jobPosting ? {
      vacancy_title: firstNonEmptyValue(jobPosting.title, jobPosting.name),
      vacancy_title_source: firstNonEmptyValue(jobPosting.title, jobPosting.name) ? 'schema' : '',
      vacancy_description: firstNonEmptyValue(jobPosting.description),
      vacancy_description_source: firstNonEmptyValue(jobPosting.description) ? 'schema' : '',
      employer_name: getNestedString(jobPosting, 'hiringOrganization.name'),
      employer_name_source: getNestedString(jobPosting, 'hiringOrganization.name') ? 'schema' : '',
      source_date: firstNonEmptyValue(jobPosting.datePosted),
      source_date_source: firstNonEmptyValue(jobPosting.datePosted) ? 'schema' : '',
      source_url: firstNonEmptyValue(jobPosting.sameAs, jobPosting.url),
      source_url_source: firstNonEmptyValue(jobPosting.sameAs, jobPosting.url) ? 'schema' : '',
      region_name: firstNonEmptyValue(
        getNestedValue(jobPosting, 'jobLocation.address.addressLocality'),
        getNestedValue(jobPosting, 'jobLocation.address.addressRegion')
      ),
      region_name_source: firstNonEmptyValue(
        getNestedValue(jobPosting, 'jobLocation.address.addressLocality'),
        getNestedValue(jobPosting, 'jobLocation.address.addressRegion')
      ) ? 'schema' : '',
      ...extractSalaryFromJobPosting(jobPosting, offerNode),
    } : {};
    if (schemaData.salary_value) schemaData.salary_value_source = 'schema';
    if (schemaData.salary_value_min) schemaData.salary_value_min_source = 'schema';
    if (schemaData.salary_value_max) schemaData.salary_value_max_source = 'schema';
    if (schemaData.salary_period) schemaData.salary_period_source = 'schema';
    if (schemaData.currency) schemaData.currency_source = 'schema';

    const offerData = extractOfferData(offerNode);
    const domData = extractDomFallbackData();
    const conflicts = detectConflicts(schemaData, offerData, domData);

    const payload = {};
    mergeByPriority(payload, schemaData);
    mergeByPriority(payload, offerData);
    mergeByPriority(payload, domData);
    const siteAwareRaw = extractSiteAwareFallbackData(payload);
    const siteAwareData = {};
    Object.entries(siteAwareRaw).forEach(([key, value]) => {
      if (key.endsWith('_source')) {
        siteAwareData[key] = value;
        return;
      }
      siteAwareData[key] = value;
      if (value && !siteAwareData[`${key}_source`]) {
        siteAwareData[`${key}_source`] = 'site_fallback';
      }
    });
    mergeByPriority(payload, siteAwareData);

    if (
      (!payload.salary_value && !payload.salary_value_min && !payload.salary_value_max) &&
      payload.salary_raw_text
    ) {
      const parsedSalary = parseSalaryFromText(payload.salary_raw_text);
      if (parsedSalary.salary_value) parsedSalary.salary_value_source = 'parsed';
      if (parsedSalary.salary_value_min) parsedSalary.salary_value_min_source = 'parsed';
      if (parsedSalary.salary_value_max) parsedSalary.salary_value_max_source = 'parsed';
      if (parsedSalary.salary_period) parsedSalary.salary_period_source = 'parsed';
      mergeByPriority(payload, parsedSalary);
    }

    payload.confidence = calculateExtractionConfidence({
      schemaData,
      offerData,
      domData,
      siteAwareData,
      conflicts,
    });

    const cleaned = Object.fromEntries(
      Object.entries(payload).filter(([, value]) => value != null && String(value).trim() !== '')
    );

    return {
      found: Object.keys(cleaned).length > 0,
      data: cleaned,
    };
  }

  // ============================================================
  // Message handler from popup / background
  // ============================================================

  chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    const { action, data } = message;

    switch (action) {
      case 'START_CAPTURE':
        startCapture(data.field);
        sendResponse({ started: true });
        break;

      case 'STOP_CAPTURE':
        stopCapture();
        sendResponse({ stopped: true });
        break;

      case 'GET_CAPTURED_DATA':
        sendResponse({ capturedData, schemaMapping: capturedSchemaMapping });
        break;

      case 'SET_SCHEMA_MAPPING':
        capturedSchemaMapping = data.schemaMapping || null;
        sendResponse({ ok: true });
        break;

      case 'CLEAR_CAPTURED_DATA':
        // Full visual cleanup first (stops capture, removes markers, restores styles)
        destroyUI();
        capturedData = {};
        capturedSchemaMapping = null;
        sendResponse({ cleared: true });
        break;

      case 'RESET_EXTENSION_UI':
        // Restore page to pre-extension visual state without clearing captured data.
        // Use this to recover from partial failures or drift without losing user work.
        destroyUI();
        sendResponse({ reset: true });
        break;

      case 'GET_PAGE_INFO':
        sendResponse({
          url: window.location.href,
          title: document.title,
          domain: window.location.hostname.replace(/^www\./, ''),
        });
        break;

      case 'AUTO_DETECT_FIELDS': {
        const detected = autoDetectFields();
        for (const [field, info] of Object.entries(detected.fields || {})) {
          capturedData[field] = {
            value: info.value,
            selector: info.selector || null,
            auto: true,
            schema: !!info.schema,
          };
        }
        sendResponse(detected);
        break;
      }

      case 'APPLY_TEMPLATE':
        const result = applyTemplate(data.selectors);
        // Store as captured data
        for (const [field, info] of Object.entries(result.fields)) {
          if (info.found) {
            capturedData[field] = {
              value: info.value,
              selector: info.selector,
            };
          }
        }
        sendResponse(result);
        break;

      case 'PING':
        sendResponse({ pong: true });
        break;

      case 'DETECT_SCHEMA':
        try {
          sendResponse(extractSchemaData());
        } catch (err) {
          sendResponse({ found: false, schemas: [], error: err.message });
        }
        break;

      case 'APPLY_SCHEMA_MAPPING': {
        // data.schemaIndex — which schema to use
        // data.mapping — { title: 'name', price: 'offers.price', ... }
        const allSchemas = extractSchemaData();
        if (!allSchemas.found) {
          sendResponse({ applied: false, error: 'Schema.org данные не найдены' });
          break;
        }
        const schema = allSchemas.schemas[data.schemaIndex || 0];
        const mapped = applySchemaMapping(schema.fields, data.mapping);
        // Merge into captured data
        for (const [field, info] of Object.entries(mapped)) {
          capturedData[field] = info;
        }
        // Persist schema mapping for template saving
        capturedSchemaMapping = { schemaIndex: data.schemaIndex || 0, mapping: data.mapping };
        sendResponse({
          applied: true,
          fields: mapped,
          fieldCount: Object.keys(mapped).length,
        });
        break;
      }

      case 'EXTRACT_JOB_POSTING': {
        try {
          const extracted = extractJobPostingData();
          if (extracted.found && extracted.data) {
            chrome.runtime.sendMessage({
              action: 'JOB_POSTING_EXTRACTED',
              data: extracted.data,
            }).catch(() => {});
          }
          sendResponse(extracted);
        } catch (err) {
          sendResponse({ found: false, data: null, error: err.message });
        }
        break;
      }

      default:
        sendResponse({ error: `Unknown action: ${action}` });
    }

    return true; // Async
  });

})();
