# Chrome Web Store — Review Readiness Checklist

Extension: **Призма — Автосбор материалов**  
Manifest version: 3  
Extension version: 1.0.2  
Date reviewed: 2026-04-07

---

## 1. Permissions Audit

| Permission | Declared | Justified | Notes |
|---|---|---|---|
| `activeTab` | ✅ | ✅ | Content script injection + `captureVisibleTab` on explicit user action |
| `storage` | ✅ | ✅ | Auth token, template cache, user prefs (`chrome.storage.local`); badge counter (`chrome.storage.session`) |
| `scripting` | ✅ | ✅ | On-demand injection of `content.js` and `content.css` via `chrome.scripting.executeScript` |
| `tabs` | ❌ not declared | N/A | Not needed; `captureVisibleTab` works with `activeTab` |
| `tabCapture` | ❌ not declared | N/A | Not needed; `captureVisibleTab` does not require this permission |
| `contextMenus` | Not in `permissions` | ✅ | Used via `chrome.contextMenus?.create()` — the API is available to extension pages without explicit declaration |
| `notifications` | ❌ not declared | N/A | Not used |
| `history` | ❌ not declared | N/A | Not accessed |
| `webRequest` | ❌ not declared | N/A | Not intercepting any network traffic |

**Host permissions:**

| Host | In manifest | Purpose |
|---|---|---|
| `https://app.prismcore.ru/*` | ✅ | Only backend the extension communicates with |
| `http://localhost/*` | ❌ removed | Dev-only; removed from production manifest |
| `http://127.0.0.1/*` | ❌ removed | Dev-only; removed from production manifest |

No broad `https://*/*` permissions. No remote code loading. No eval.

---

## 2. Injection Audit

**Content script auto-injection:** None. The `content_scripts` key does not appear in `manifest.json`.

**On-demand injection flow:**

Both the popup and the service-worker context-menu handler use the same 3-step contract before any extraction command:

1. PING the tab — if content script already responds, skip injection.
2. Inject `content/content.js` + `content/content.css` using `chrome.scripting.executeScript`.
3. Verify with a second PING before proceeding.

The content script has an idempotent bootstrap guard:
```js
if (window.__PRISM_CONTENT_READY__) return;
window.__PRISM_CONTENT_READY__ = true;
```
Re-injection on the same page is a safe no-op.

**Triggering conditions:**
- Popup open → `loadPageInfo()` → `ensureContentScript()` — triggered by user opening the popup.
- Context menu "Призма: захватить элемент" → `ensureTabContentScript()` — triggered by user right-clicking and selecting the menu item.

No injection happens in the background without user action.

---

## 3. Restricted Pages Behavior

The popup explicitly checks for restricted URLs before attempting injection:

```
chrome://*, chrome-extension://*
https://chrome.google.com/webstore/*
about:*, data:*, blob:*, javascript:*
```

On restricted pages:
- No injection is attempted.
- A clear, user-facing message is shown in the popup (`getRestrictedPageMessage(url)`).
- The context-menu handler silently skips (no UI available from context menu).

The service-worker context menu handler returns `false` from `ensureTabContentScript()` on injection failure and takes no further action.

---

## 4. Screenshot Capture Audit

**API used:** `chrome.tabs.captureVisibleTab(null, { format: 'jpeg', quality: 80 })`

**When it executes:** Only inside `handleAddMaterial()`, which runs when the user explicitly clicks the "Добавить материал" button. No background or automatic screenshot execution.

**Permission required:** `activeTab` — already declared. No additional permission needed.

**Disclosure to user:**
- The button label changes to "Снимок..." then "Сохранение..." while the operation runs.
- A status line reads "Делаем снимок страницы для доказательной базы..." before the call.
- Failure does NOT block the core material submission — the material is saved even if screenshot fails.
- The result panel shows whether the screenshot was captured or failed.

**Data destination:** The screenshot JPEG blob is appended to a `FormData` object and POSTed to `https://app.prismcore.ru/api/chrome/extract-with-evidence` as `screenshot_file`. It is not stored locally. It is not sent to any third party.

**Failure handling:** `catch` block logs `console.warn` and sets `screenshotBlob = null`. Submission continues normally without the screenshot.

**Review risk:** Low. The capture is synchronous with explicit user click, non-blocking, disclosed in UI, and sent only to the user's own account on the official server.

---

## 5. Unsupported-Page UX

When the popup is opened on a restricted or unsupported page:
- Domain field shows: "Страница не поддерживается"
- Status shows: specific message for the page type (e.g., "Расширение не работает на служебных страницах Chrome.")
- Template area shows: "Откройте карточку товара поставщика"
- No error dialog or alert boxes are shown.

When injection fails (e.g., CSP blocks scripting):
- Domain field shows: "Не удалось подключиться"
- Status shows: "Страница заблокировала доступ расширения. Попробуйте обновить страницу."
- The popup is still functional for navigation (not frozen).

---

## 6. Store Text Consistency Check

| Store claim | Actual implementation | Match? |
|---|---|---|
| "Сбор данных только по действию пользователя" | All extraction triggered via popup open or explicit button click | ✅ |
| "Нет автоматического мониторинга страниц" | No `content_scripts` auto-injection; no background page scanning | ✅ |
| "Данные передаются только на сервер Призмы" | All `fetch()` calls go to `https://app.prismcore.ru/api/*` only | ✅ |
| "Токен хранится локально" | Stored in `chrome.storage.local` as `authToken`; never in cookies | ✅ |
| "Скриншот делается только при добавлении материала" | `captureVisibleTab` called only in `handleAddMaterial()` | ✅ |

No store wording implies an extra confirmation step that the code does not implement.

---

## 7. Production Manifest Check

```json
{
  "manifest_version": 3,
  "permissions": ["activeTab", "storage", "scripting"],
  "host_permissions": ["https://app.prismcore.ru/*"],
  "content_security_policy": {
    "extension_pages": "script-src 'self'; object-src 'self'"
  }
}
```

- ✅ No `eval` or inline scripts — CSP is enforced.
- ✅ No remote code loading — all scripts are bundled in the extension package.
- ✅ No `content_scripts` with broad URL patterns.
- ✅ No `web_accessible_resources` exposing internal scripts to page JS.
- ✅ No localhost or 127.0.0.1 host permissions (removed from production build).
- ✅ No `unsafe-eval` or `unsafe-inline` in CSP.
- ✅ `background.service_worker` — correct MV3 pattern (no persistent background page).

---

## 8. Badge State

**Previous implementation risk:** Badge count was stored in `globalThis.__prizmCapturedFields` which is cleared when the service worker is suspended (MV3 behavior).

**Fixed implementation:** Badge count is now stored in `chrome.storage.session`, which persists for the browser session regardless of service-worker lifecycle. On popup open, `CLEAR_BADGE` is sent which resets both the visual badge and the session counter atomically.

---

## Known Acceptable Limitations

- Screenshots fail silently on pages that block `captureVisibleTab` (e.g., PDF viewer tabs). The material is still submitted. This is documented and by design.
- The context-menu handler cannot show user-facing errors when a target page is restricted. It silently skips — the only alternative would be opening a notification, which we intentionally avoid to reduce permission footprint.
