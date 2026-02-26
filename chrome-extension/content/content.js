/**
 * Prizm Chrome Extension — Content Script
 * Handles element selection/capture on supplier pages.
 */

(function () {
  'use strict';

  // Prevent double-injection
  if (window.__prizmContentScriptLoaded) return;
  window.__prizmContentScriptLoaded = true;

  // ============================================================
  // State
  // ============================================================
  let captureMode = false;
  let activeField = null; // 'title' | 'price' | 'article' | 'thickness' | 'length' | 'width'
  let hoveredElement = null;
  let capturedData = {};  // { field: { value, selector, xpath, element } }
  let capturedSchemaMapping = null; // persisted schema mapping for template saving

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
  };

  const FIELD_COLORS = {
    title: '#4F46E5',
    price: '#059669',
    article: '#D97706',
    thickness: '#7C3AED',
    length: '#0891B2',
    width: '#D946EF',
  };

  /**
   * Sheet material name patterns — used to auto-detect unit as м²
   * and to parse dimensions from the name.
   */
  const SHEET_MATERIAL_PATTERNS = /\b(ЛДСП|МДФ|ХДФ|ОСБ|ЛМДФ|OSB|ДВПО|ДСП|ДВП|ЛХДФ|ЛОСБ|HPL|CPL|ФСФ|ФК)\b/i;

  /**
   * Detect material type from name and URL.
   * Returns: 'edge' | 'plate' | 'hardware'
   */
  function detectMaterialType(name, url) {
    // Edge: name contains "Кромка" or URL contains "kromka"
    if (name && /кромка/i.test(name)) return 'edge';
    if (url && /kromka/i.test(url)) return 'edge';
    // Plate: sheet material keywords
    if (name && SHEET_MATERIAL_PATTERNS.test(name)) return 'plate';
    // Default: hardware
    return 'hardware';
  }

  /**
   * Parse edge banding dimensions from name.
   * Edge format: "19х0,4" or "19x0.4" (width_mm × thickness_mm).
   * By DB convention: edge width → length field, edge thickness → width field.
   *
   * @returns {{ length?: number, width?: number }}
   */
  function parseEdgeDimensions(name) {
    if (!name) return {};
    // Pattern: WxT where W is 10-100 (edge width in mm), T is 0.1-10 (edge thickness)
    const m = name.match(/(\d{1,3})\s*[xхXХ×*]\s*(\d{1,2}(?:[.,]\d+)?)/);
    if (m) {
      const w = parseInt(m[1]);
      const t = parseFloat(m[2].replace(',', '.'));
      if (w >= 10 && w <= 100 && t > 0 && t <= 10) {
        return { length: w, width: t }; // edge width → length, edge thickness → width
      }
    }
    return {};
  }

  /**
   * Parse dimensions (length × width, thickness) from a material name.
   * Examples:
   *   "ЛДСП Кремовый 100 ГМ 2750*1830 16 мм КР" → { length: 2750, width: 1830, thickness: 16 }
   *   "МДФ 2800х2070х16"  → { length: 2800, width: 2070, thickness: 16 }
   *   "ДВПО 2745х1700х3.2 мм" → { length: 2745, width: 1700, thickness: 3 }
   */
  function parseDimensionsFromName(name) {
    if (!name) return {};
    const dims = {};

    // Pattern 1: LxWxT (e.g. "2800х2070х16", "2750*1830*16")
    const tripleMatch = name.match(/(\d{3,5})\s*[xхXХ×*]\s*(\d{3,5})\s*[xхXХ×*]\s*(\d{1,3}(?:[.,]\d+)?)/);
    if (tripleMatch) {
      dims.length = parseInt(tripleMatch[1]);
      dims.width = parseInt(tripleMatch[2]);
      dims.thickness = parseFloat(tripleMatch[3].replace(',', '.'));
      return dims;
    }

    // Pattern 2: LxW + separate thickness (e.g. "2750*1830 16 мм")
    const sizeMatch = name.match(/(\d{3,5})\s*[xхXХ×*]\s*(\d{3,5})/);
    if (sizeMatch) {
      dims.length = parseInt(sizeMatch[1]);
      dims.width = parseInt(sizeMatch[2]);
    }

    // Pattern 3: standalone thickness (e.g. "16 мм", "16мм", "3.2 мм")
    // Must NOT be part of the LxW pattern already matched
    const thicknessPatterns = [
      // "16 мм" or "16мм" — only if 1-3 digits + optional decimal
      /(?:^|\s|[,;])(\d{1,3}(?:[.,]\d+)?)\s*мм\b/i,
      // "толщина 16" or "толщ. 16"
      /толщ\.?\s*[:=]?\s*(\d{1,3}(?:[.,]\d+)?)/i,
    ];

    if (!dims.thickness) {
      for (const pattern of thicknessPatterns) {
        const m = name.match(pattern);
        if (m) {
          const t = parseFloat(m[1].replace(',', '.'));
          // Thickness typically 3–40 mm for sheet materials
          if (t >= 2 && t <= 50) {
            dims.thickness = t;
            break;
          }
        }
      }
    }

    return dims;
  }

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
      hoveredElement.style.outline = hoveredElement.__prizmOriginalOutline || '';
      hoveredElement.style.outlineOffset = hoveredElement.__prizmOriginalOutlineOffset || '';
      delete hoveredElement.__prizmOriginalOutline;
      delete hoveredElement.__prizmOriginalOutlineOffset;
      hoveredElement = null;
    }
  }

  function highlightElement(el) {
    if (hoveredElement === el) return;

    clearHighlight();

    // Don't highlight our own UI
    if (el.closest('#prizm-capture-overlay, #prizm-capture-tooltip, .prizm-captured-marker')) return;

    hoveredElement = el;
    el.__prizmOriginalOutline = el.style.outline;
    el.__prizmOriginalOutlineOffset = el.style.outlineOffset;

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

    // Notify popup
    chrome.runtime.sendMessage({
      action: 'FIELD_CAPTURED',
      data: {
        field: activeField,
        value,
        selector,
        xpath,
      },
    });

    stopCapture();
  }

  function onKeyDown(e) {
    if (e.key === 'Escape') {
      stopCapture();
    }
  }

  /**
   * Add a visual marker on captured elements.
   */
  function addCapturedMarker(el, field) {
    // Remove previous marker for this field
    document.querySelectorAll(`.prizm-captured-marker[data-field="${field}"]`).forEach(m => m.remove());

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

    // Also give the element a subtle permanent highlight
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
        capturedData = {};
        capturedSchemaMapping = null;
        // Remove all markers
        document.querySelectorAll('.prizm-captured-marker').forEach(m => m.remove());
        sendResponse({ cleared: true });
        break;

      case 'GET_PAGE_INFO':
        sendResponse({
          url: window.location.href,
          title: document.title,
          domain: window.location.hostname.replace(/^www\./, ''),
        });
        break;

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

      default:
        sendResponse({ error: `Unknown action: ${action}` });
    }

    return true; // Async
  });

})();
