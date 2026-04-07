# Chrome Web Store Compliance Refactor

## 1. What Was Risky Before

### Automatic content script injection across all URLs

```json
"content_scripts": [
  {
    "matches": ["<all_urls>"],
    "js": ["content/content.js"],
    "css": ["content/content.css"],
    "run_at": "document_idle"
  }
]
```

This caused the extension to **automatically inject JavaScript and CSS into every page the user visits** — regardless of whether the user interacted with the extension. From a Chrome Web Store reviewer's perspective, this looks like passive site-wide monitoring, which contradicts the declared single-purpose ("collect product data on user demand from a supplier page").

Chrome's review guidelines require that broad page access must be justified by the extension's stated purpose. Auto-injecting on `<all_urls>` without user action is a common rejection trigger.

### Broad host permissions

```json
"host_permissions": ["https://*/*", "http://localhost/*", "http://127.0.0.1/*"]
```

`https://*/*` grants the extension persistent access to read and interact with every HTTPS page the user visits. Combined with auto-injection, this made the extension look like it was doing sitewide surveillance — even though it was not.

---

## 2. What Was Changed

### A. `manifest.json`

| Before | After |
|--------|-------|
| `"content_scripts": [{ "matches": ["<all_urls>"], ... }]` | *(removed entirely)* |
| `"host_permissions": ["https://*/*", ...]` | `"host_permissions": ["https://app.prismcore.ru/*", "http://localhost/*", "http://127.0.0.1/*"]` |
| version `1.0.0` | version `1.0.1` |

The `content_scripts` block is gone. The only remaining host permissions are:
- `https://app.prismcore.ru/*` — backend API calls (fetch/XHR from service worker)
- `http://localhost/*` and `http://127.0.0.1/*` — development environment only

### B. `popup/popup.js`

Added `ensureContentScript()` — a single, reusable helper that:
1. Checks if the current page is restricted (chrome://, Web Store, etc.) and returns a user-friendly error immediately if so
2. Tries `PING` to the content script — if it already responded, returns early (no re-injection)
3. If no response, calls `chrome.scripting.executeScript` + `insertCSS` using the **activeTab** grant that was given when the user opened the popup
4. Retries `PING` to verify the injection succeeded
5. Throws a typed error (`{ code: 'RESTRICTED_PAGE' | 'INJECT_FAILED' | 'PING_FAILED' }`) so the popup can show precise failure messages

Before this change, `loadPageInfo()` contained a raw 5-attempt PING retry loop without injection. `startFieldCapture()` had a custom PING + manual inject block. Both are now replaced by a single `ensureContentScript()` call.

Also added: missing `escapeHtml()` utility (was referenced but not defined).

### C. `background/service-worker.js`

The context menu `onClicked` handler now:
1. Injects `content/content.js` and `content/content.css` using `chrome.scripting` **before** trying to send a message
2. Suppresses errors from pages that don't allow scripting
3. Wraps `tabs.sendMessage` in `.catch()` to prevent unhandled rejections on restricted pages

### D. `content/content.js`

Renamed the idempotent guard:

```js
// Before:
if (window.__prizmContentScriptLoaded) return;
window.__prizmContentScriptLoaded = true;

// After:
if (window.__PRISM_CONTENT_READY__) return;
window.__PRISM_CONTENT_READY__ = true;
```

The guard name now matches the documented API contract (`__PRISM_CONTENT_READY__`). The behavior is identical: if the script has already run in this browser context, it returns immediately without re-registering event listeners or recreating DOM elements. After page navigation, `window` is reset and the script can be injected fresh.

---

## 3. Remaining Permissions and Justification

| Permission | Reason |
|---|---|
| `activeTab` | Grants temporary access to the **current tab only** when the user explicitly clicks the extension icon or context menu. Required to call `chrome.scripting.executeScript` without persistent host permissions on supplier URLs. |
| `scripting` | Required to programmatically inject `content/content.js` into the active tab via `chrome.scripting.executeScript`. |
| `storage` | Stores authentication token, API base URL, user cache, and per-domain success counters. All data stays local. |
| `https://app.prismcore.ru/*` | The Prism backend API origin. All product data is sent here after explicit user confirmation. The service worker's fetch calls require a host permission for non-activeTab origins. |
| `http://localhost/*` and `http://127.0.0.1/*` | Development environment only. These let developers point the extension at a local Prism server. They do **not** grant access to inject scripts into localhost pages — that is covered by activeTab. |

**No supplier site host permissions are declared.** Supplier page scripting works entirely through `activeTab`, which is granted only when the user invokes the extension on that specific tab.

---

## 4. How Injection Now Works

### Trigger: user opens popup

```
User clicks extension icon
  └─ chrome grants activeTab for the current tab
       └─ popup.js: init()
            └─ loadPageInfo()
                 ├─ isRestrictedPage(url) check
                 │    └─ if chrome://, Web Store, etc. → show error, stop
                 └─ ensureContentScript()
                      ├─ PING content script (already injected?)
                      │    └─ if pong received → return (already ready)
                      ├─ chrome.scripting.executeScript({ files: ['content/content.js'] })
                      ├─ chrome.scripting.insertCSS({ files: ['content/content.css'] })
                      ├─ wait 150ms for initialization
                      └─ verify PING again → proceed
```

### Trigger: user clicks a field capture button

```
User clicks "Capture" button for a field
  └─ popup.js: startFieldCapture(field)
       └─ ensureContentScript()   ← same helper, same logic
            └─ (script already injected from loadPageInfo — PING succeeds, no re-injection)
       └─ sendToContent('START_CAPTURE', { field })
       └─ popup closes (user must interact with the page)
```

### Trigger: user right-clicks and selects "Призма: захватить элемент"

```
User right-click → context menu item clicked
  └─ service-worker.js: contextMenus.onClicked
       ├─ chrome.scripting.executeScript (inject content.js)
       ├─ chrome.scripting.insertCSS (inject content.css)
       ├─ wait 150ms
       └─ tabs.sendMessage(START_CAPTURE)
```

### Idempotency on re-open

If the user closes and reopens the popup on the **same tab** without navigating:
- `ensureContentScript()` sends PING → content script replies → returns immediately
- No re-injection, no duplicate listeners, no CSS stacking

After a **page navigation**:
- `window.__PRISM_CONTENT_READY__` is reset (new `window` object)
- PING fails → script is re-injected cleanly

---

## 5. Alignment with Chrome Web Store Policy

| Policy requirement | Current implementation |
|---|---|
| Extension has a single purpose | ✅ Declared purpose = collect product data from supplier pages |
| Host access matches declared purpose | ✅ No persistent host access to supplier URLs; scripting uses activeTab only |
| Sensitive data accessed only when necessary | ✅ Page DOM is only read after explicit user action (popup open or context menu) |
| No background passive monitoring | ✅ No content scripts auto-injected, no background tab scanning |
| No remote hosted code | ✅ All JS is bundled in the extension package; backend returns JSON, never executable code |
| Minimum required permissions | ✅ Only `activeTab`, `storage`, `scripting` + one specific API host |
| User can see what data is sent | ✅ Popup shows all captured fields before the "Add material" confirmation step |

The architectural shift from "always-on injection" to "user-invoked injection" removes the #1 moderation risk while preserving the full UX. The popup now explains exactly what it's doing at every step (connecting, reading data, validating, submitting).
