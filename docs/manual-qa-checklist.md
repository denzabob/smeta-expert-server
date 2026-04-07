# Manual QA Checklist — Chrome Extension

Use this checklist after every significant change to the extension and before each Chrome Web Store submission.

---

## Prerequisites

- Extension loaded in Chrome via `chrome://extensions` → "Load unpacked" from the `chrome-extension/` directory
- Valid API token configured in the extension popup
- A local or staging Prism backend running and reachable

---

## 1. No extraction before user click

**Goal:** Verify the extension does not touch any page before the user interacts with it.

- [ ] Open a new tab and navigate to any supplier product page (e.g. a furniture store)
- [ ] Do NOT click the extension icon
- [ ] Open DevTools → Console on the supplier page
- [ ] Verify: **no Prizm logs appear in the console**
- [ ] Open DevTools → Network tab and verify: **no requests to `app.prismcore.ru` from the page**
- [ ] Inspect `document.querySelectorAll('[id^="prizm"]')` — result must be **empty** (no injected DOM)
- [ ] Verify `window.__PRISM_CONTENT_READY__` === `undefined` before popup is opened

---

## 2. Popup opens and loads data correctly

**Goal:** Popup initializes quickly and shows page info without errors.

- [ ] Navigate to a supplier product page
- [ ] Click the extension icon to open the popup
- [ ] Verify: popup shows "Подключаемся к странице…" status briefly, then transitions to page domain
- [ ] Verify: domain name is shown correctly (e.g. `lemma-trade.ru`)
- [ ] Verify: page URL is shown (truncated if needed)
- [ ] Verify: template status is shown ("Нет сохраненного правила" or "Есть сохраненное правило")
- [ ] Open DevTools → Console on the **supplier page** after popup opens
- [ ] Verify: `window.__PRISM_CONTENT_READY__` === `true` (script was injected)

---

## 3. Popup on a restricted page shows correct error

**Goal:** Unsupported pages get a clear user-facing message, not a generic failure.

- [ ] Navigate to `chrome://settings`
- [ ] Open the popup
- [ ] Verify: message "Расширение не работает на служебных страницах Chrome." (or equivalent) is shown
- [ ] Verify: no attempt to inject content script (no errors in background service worker console)

- [ ] Navigate to `https://chrome.google.com/webstore`
- [ ] Open the popup
- [ ] Verify: message "Расширение не работает на странице Chrome Web Store." is shown

- [ ] Open a `about:blank` tab
- [ ] Open the popup
- [ ] Verify: appropriate restriction message is shown

---

## 4. No extraction runs before user confirms

**Goal:** Data is never sent to the server without explicit user action.

- [ ] Open popup on a product page
- [ ] Let auto-detect run (fields fill automatically)
- [ ] Verify: NO request to `/api/chrome/extract` has been made (check Network tab or backend logs)
- [ ] Only after clicking "Добавить материал" should the extract request fire

---

## 5. Auto-detect (automatic field detection) works

**Goal:** The extension finds title, price, and article automatically from common supplier pages.

- [ ] Open popup on a product page with a clear H1 title and visible price
- [ ] Wait for auto-detect to complete
- [ ] Verify: "Название" field is populated
- [ ] Verify: "Цена" field is populated with a numeric value (no currency suffix)
- [ ] Verify: "Артикул" field is populated if SKU is present on the page
- [ ] Verify: status message reflects the discovery result ("Основные поля найдены" or warning)

---

## 6. Template detection and auto-apply work

**Goal:** If a saved template exists for this domain, it is detected and applied automatically.

- [ ] Ensure a template exists for the test domain (create one if needed)
- [ ] Navigate to a product page on that domain
- [ ] Open the popup
- [ ] Verify: "Есть сохраненное правило сайта: [name]" shown in template status
- [ ] Verify: fields are filled from the template (banner "Данные заполнены по сохраненному правилу сайта" appears)

---

## 7. Manual template apply still works

**Goal:** The "Применить шаблон" button applies selectors from the saved template.

- [ ] Navigate to a matching domain product page
- [ ] Open popup, see template is detected
- [ ] Clear all captured fields (if any)
- [ ] Switch to Advanced mode → Templates tab
- [ ] Click "Применить шаблон"
- [ ] Verify: fields are populated according to the template's CSS selectors
- [ ] Verify: result message "✓ Шаблон применён" appears

---

## 8. Manual field capture (pick mode) works

**Goal:** The user can click on any element on the page to capture it as a field value.

- [ ] Open popup on a product page
- [ ] Click the capture button (◎) for "Название" field
- [ ] Verify: popup closes automatically
- [ ] Verify: a "Призма: выберите элемент" overlay bar appears at the top of the page
- [ ] Hover over elements — verify they are highlighted with a colored outline
- [ ] Click on the product title element
- [ ] Verify: overlay disappears after click
- [ ] Reopen popup
- [ ] Verify: "Название" field shows the captured value
- [ ] Verify: extension badge count updates (badge shows captured count)
- [ ] Repeat for "Цена" field

---

## 9. Validation preview works

**Goal:** The "Проверить" button calls the backend and shows a preview of what will be saved.

- [ ] Populate at least "Название" and "Цена"
- [ ] Click "Проверить"
- [ ] Verify: loading spinner appears briefly
- [ ] Verify: validation preview panel appears with:
  - Название
  - Тип (Плита / Кромка / Фурнитура)
  - Цена with currency
  - Артикул (if present)
  - Ед. изм.
  - Dimension fields if type = Плита or Кромка
- [ ] Verify: status message reflects validation result

---

## 10. Final submit (Add Material) works

**Goal:** Clicking "Добавить материал" sends data and gets a success response.

- [ ] Ensure "Название" and "Цена" are both filled
- [ ] Click "Добавить материал"
- [ ] Verify: loading state appears ("Скриншот..." → "Сохранение...")
- [ ] Verify: success message appears with result details:
  - "✓ Материал добавлен" or "✓ Материал обновлён"
  - Evidence status ("✓ Доказательство сохранено" or relevant status)
  - Screenshot status
- [ ] Verify no error state appears

---

## 11. Badge behavior works correctly

**Goal:** Badge updates correctly and clears at the right moments.

- [ ] Open popup — badge text is cleared on open
- [ ] Capture a field manually — badge shows count (e.g. "1")
- [ ] Capture a second field — badge shows "2"
- [ ] Close and reopen popup — badge is cleared again
- [ ] Capture mode active (overlay visible) — badge shows "⊙" indicator

---

## 12. No duplicate overlay after multiple popup opens

**Goal:** Opening the popup multiple times on the same tab does not create duplicate overlays or listeners.

- [ ] Open popup on a supplier page → let it initialize → close popup
- [ ] Open popup again on the same tab (without navigating)
- [ ] Check: only ONE content script is active (PING returns pong, no double initialization)
- [ ] Check: `window.__PRISM_CONTENT_READY__` is still `true` from the first injection
- [ ] Start a field capture → open page → verify only ONE overlay bar appears (not two stacked)
- [ ] Check DevTools console on the supplier page — no "Prizm content script already loaded" repeated logs

---

## 13. No duplicate listeners after repeated injections

**Goal:** The idempotent guard prevents the message listener from registering twice.

- [ ] Open popup → close → navigate to a NEW page on the same domain → open popup
- [ ] Content script should be re-injected (new window context after navigation)
- [ ] Verify: popup works correctly on the new page
- [ ] Verify: capturing a field works once, not twice (no duplicate `FIELD_CAPTURED` messages in service-worker console)

---

## 14. Extension continues to work after tab reload / navigation

**Goal:** After refreshing or navigating, the extension re-injects cleanly.

- [ ] Open popup on a product page and let it initialize
- [ ] Close popup
- [ ] Reload the page (F5 / Ctrl+R)
- [ ] Reopen popup
- [ ] Verify: popup initializes correctly (content script is re-injected)
- [ ] Verify: previously captured fields are reset (new page context)
- [ ] Verify: auto-detect runs again

- [ ] Navigate to a different product on the same site
- [ ] Reopen popup
- [ ] Verify: domain shows the new page, page URL updated
- [ ] Verify: template lookup runs for the new URL

---

## 15. Schema.org detection and apply (Advanced mode)

**Goal:** Schema.org auto-mapping works when the page contains structured data.

- [ ] Navigate to a product page known to use JSON-LD or Microdata
- [ ] Open popup → switch to Advanced mode
- [ ] Verify: "Schema.org обнаружена" banner appears
- [ ] Click "Показать" to expand schema fields
- [ ] Verify: fields table shows paths and values correctly
- [ ] Select mappings (e.g. `name` → Название, `offers.price` → Цена)
- [ ] Click "Заполнить выбранные поля"
- [ ] Verify: capture fields are populated from schema

---

## 16. Template save works from captured selectors

**Goal:** A captured set of field selectors can be saved as a reusable template.

- [ ] Manually capture "Название" and "Цена" by clicking elements on the page
- [ ] Switch to Advanced mode → Templates tab
- [ ] Fill in template name
- [ ] Click "Сохранить шаблон"
- [ ] If warning dialog appears — confirm if appropriate
- [ ] Verify: "Шаблон сохранён" success message
- [ ] Reload the page and reopen popup
- [ ] Verify: template is auto-detected and applied

---

## 17. Context menu capture works

**Goal:** Right-clicking on the page and selecting "Призма: захватить элемент" starts capture.

- [ ] Navigate to a supplier product page (do NOT open popup first)
- [ ] Right-click on the page
- [ ] Select "Призма: захватить элемент"
- [ ] Verify: capture overlay bar appears at the top of the page
- [ ] Click on any text element
- [ ] Open popup
- [ ] Verify: "Название" field shows the captured value

---

## Risk Notes

- The `activeTab` grant lasts only for the duration of the pop invocation. If the popup is closed and reopened, the content script must either remain from the previous injection (tested by PING) or be re-injected (new invocation = new activeTab grant).
- On very slow pages that have not finished loading when the popup opens, `ensureContentScript()` may fail. The popup shows "Попробуйте обновить страницу" in this case. This is expected behavior.
- Pages that use strict CSP prohibiting inline scripts do NOT affect extension content-script injection (injection uses the extension's own origin, not inline execution).
