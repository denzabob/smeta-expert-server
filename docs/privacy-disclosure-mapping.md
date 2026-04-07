# Privacy Disclosure Mapping

Extension: **Призма — Автосбор материалов**  
Version: 1.0.2  
Date: 2026-04-07

This document maps every category of data the extension collects or transmits to: the code location where it is collected, the purpose, storage behavior, and whether it leaves the device.

---

## Data Categories

### 1. API Authentication Token

| Attribute | Value |
|---|---|
| **What** | A personal API token issued by Prizm to the authenticated user |
| **Where collected** | User types it manually into `#input-token` in the popup UI |
| **Code location** | `popup/popup.js` → `handleConnect()` → `sendToBackground('CONFIGURE', { baseUrl: null, token })` → `lib/api.js` → `configure()` → `chrome.storage.local.set({ authToken })` |
| **Why needed** | Authenticate all API requests to `https://app.prismcore.ru/api` using `Authorization: Bearer <token>` |
| **Stored locally** | ✅ Yes — `chrome.storage.local` key `authToken` |
| **Sent off-device** | ✅ Yes — as `Authorization` header in every API request to the user's own Prizm account |
| **Sent to third parties** | ❌ No |
| **User action that triggers collection** | Explicit: user types token and clicks "Подключиться" |
| **Deletion** | Cleared from storage on "Отключиться" via `CONFIGURE { token: '' }` and `chrome.storage.local.remove('cachedUser')` |

---

### 2. Current Page URL

| Attribute | Value |
|---|---|
| **What** | The full URL of the active browser tab when the popup is open |
| **Where collected** | `popup/popup.js` → `loadPageInfo()` → `sendToContent('GET_PAGE_INFO')` → `content/content.js` returns `{ url: window.location.href }` |
| **Code location** | `content/content.js` → `GET_PAGE_INFO` message handler |
| **Why needed** | Template lookup (match saved extraction rules for this domain); displayed in popup for user reference; included in evidence submission for audit trail |
| **Stored locally** | ❌ Not persisted — held in memory for the popup session only |
| **Sent off-device** | ✅ Yes — included in POST body of `FIND_TEMPLATE`, `EXTRACT`, and `EXTRACT_WITH_EVIDENCE` API calls |
| **Sent to third parties** | ❌ No |
| **User action that triggers collection** | Implicit: occurs when user opens the popup on any page |
| **Notes** | The extension only accesses the URL of the currently active tab. It does not scan URLs of other tabs or history. |

---

### 3. Page Text Content (Extracted Fields)

| Attribute | Value |
|---|---|
| **What** | Selected text from DOM nodes: product title, price, article code, unit, dimensions, weight, manufacturer, description |
| **Where collected** | `content/content.js` — via autodetect text scanning, CSS-selector-based template extraction, Schema.org JSON-LD parsing, or user click selection in capture mode |
| **Code location** | `content/content.js` → `AUTO_DETECT_FIELDS`, `APPLY_TEMPLATE`, `APPLY_SCHEMA_MAPPING`, `onElementClick` handlers |
| **Why needed** | Core product function: these values form the structured material record submitted to the user's Prizm workspace |
| **Stored locally** | ❌ Not persisted — held in content script memory for current page session and displayed in popup |
| **Sent off-device** | ✅ Yes — sent to `https://app.prismcore.ru/api/chrome/extract` and `/extract-with-evidence` on submission |
| **Sent to third parties** | ❌ No |
| **User action that triggers collection** | Explicit: popup open triggers autodetect (non-destructive read); field capture requires user click on element; submission requires user to click "Добавить материал" |
| **Notes** | The extension reads only the text of DOM elements on the page the user is currently viewing. It does not read clipboard, form fields with passwords, or other browser data. |

---

### 4. Page Screenshot (Visible Viewport)

| Attribute | Value |
|---|---|
| **What** | A JPEG image of the visible portion of the current browser tab at submission time |
| **Where collected** | `popup/popup.js` → `handleAddMaterial()` → `chrome.tabs.captureVisibleTab(null, { format: 'jpeg', quality: 80 })` |
| **Code location** | `popup/popup.js` lines ~1293–1300 |
| **Why needed** | Evidence artifact: the screenshot serves as visual proof of price/availability at the time of data collection, for use in procurement audits |
| **Stored locally** | ❌ Not persisted — converted to a `Blob` in memory and immediately appended to `FormData` |
| **Sent off-device** | ✅ Yes — appended as `screenshot_file` in the POST to `https://app.prismcore.ru/api/chrome/extract-with-evidence` |
| **Sent to third parties** | ❌ No |
| **User action that triggers collection** | Explicit: only when the user clicks "Добавить материал". No background or passive screenshot capture. |
| **Permission used** | `activeTab` — grants access to the tab only while the popup is open |
| **Failure behavior** | If `captureVisibleTab` fails, submission continues without the screenshot. The result panel shows the outcome. Core workflow is not blocked. |
| **Disclosure in UI** | Status line shows "Делаем снимок страницы для доказательной базы..." during capture; button label shows "Снимок..." |

---

### 5. Cached User Profile

| Attribute | Value |
|---|---|
| **What** | The response from `GET /api/chrome/me`: `{ user: { name, email }, region_id }` |
| **Where collected** | `popup/popup.js` → `sendToBackground('GET_ME')` → `lib/api.js` → API response |
| **Code location** | `popup/popup.js` → `handleConnect()` and `init()` |
| **Why needed** | Avoid API round-trip on every popup open; display user name in popup header; include `region_id` in material submissions |
| **Stored locally** | ✅ Yes — `chrome.storage.local` key `cachedUser` |
| **Sent off-device** | ❌ No — this is data received from the server, not data sent to it |
| **User action that triggers collection** | Implicit: occurs on successful token connection or on popup open if token is already stored |
| **Deletion** | Cleared on "Отключиться" via `chrome.storage.local.remove('cachedUser')` |

---

### 6. Extraction Templates

| Attribute | Value |
|---|---|
| **What** | Named CSS-selector rules mapping page elements to product fields; scoped to a URL domain pattern |
| **Where collected** | Created by the user in the "Шаблоны" tab of the popup; also downloaded from the server via `LIST_TEMPLATES` |
| **Code location** | `popup/popup.js` → `handleSaveTemplate()` → `sendToBackground('SAVE_TEMPLATE', data)` → `lib/api.js` → POST `/chrome/templates` |
| **Why needed** | Persistent extraction rules that auto-apply on known supplier pages, reducing manual field selection |
| **Stored locally** | ❌ Templates are stored server-side; not stored in `chrome.storage` |
| **Sent off-device** | ✅ Yes — to `https://app.prismcore.ru/api/chrome/templates` |
| **Sent to third parties** | ❌ No |
| **User action that triggers collection** | Explicit: user fills in template name and clicks "Сохранить шаблон" |

---

### 7. Badge Field Count

| Attribute | Value |
|---|---|
| **What** | Integer count of fields captured via context menu while the popup is closed |
| **Where collected** | `background/service-worker.js` → `FIELD_CAPTURED` handler |
| **Code location** | `background/service-worker.js` → `chrome.storage.session.get/set({ prizmCapturedCount })` |
| **Why needed** | Display a badge on the extension icon to indicate pending captured fields |
| **Stored locally** | ✅ Yes — `chrome.storage.session` key `prizmCapturedCount` (session-scoped; cleared when browser closes) |
| **Sent off-device** | ❌ No |
| **User action that triggers collection** | Triggered when user selects "Призма: захватить элемент" from the right-click context menu |
| **Deletion** | Reset to 0 on every popup open via `CLEAR_BADGE` message |

---

### 8. Domain Success Counter

| Attribute | Value |
|---|---|
| **What** | Per-domain count of how many times the user successfully added a material from that domain |
| **Where collected** | `popup/popup.js` → `bumpSuccessCounterForDomain()` |
| **Code location** | `popup/popup.js` → `chrome.storage.local.get/set(SUCCESS_COUNTER_KEY)` |
| **Why needed** | Determines when to show the "Suggest template" banner (heuristic: 3+ successes on a domain without a saved template) |
| **Stored locally** | ✅ Yes — `chrome.storage.local` key `prizm_success_counter_by_domain_v1` |
| **Sent off-device** | ❌ No |
| **User action that triggers collection** | Implicit: incremented automatically after each successful "Добавить материал" |
| **Notes** | Only the integer count and domain hostname are stored. No URLs or page content. |

---

### 9. Onboarding State

| Attribute | Value |
|---|---|
| **What** | Boolean flag indicating whether the first-run onboarding banner has been dismissed |
| **Where collected** | `popup/popup.js` → `dismissOnboarding()` |
| **Code location** | `chrome.storage.local.set({ prizm_onboarding_seen_v1: true })` |
| **Why needed** | Prevent re-showing onboarding to returning users |
| **Stored locally** | ✅ Yes — `chrome.storage.local` key `prizm_onboarding_seen_v1` |
| **Sent off-device** | ❌ No |
| **User action that triggers collection** | Explicit: user clicks "Понятно" to dismiss the onboarding banner |

---

## Data NOT Collected

The extension explicitly does not collect:

- Browsing history or URLs of non-active tabs
- Form field values (passwords, credit card numbers, etc.)
- Clipboard contents
- Cookies or session data from visited sites
- Geolocation
- User keystrokes or mouse movements outside of the explicit capture-mode click
- Data from pages the user has not actively opened the extension on

---

## Off-Device Data Flow Summary

All network traffic from the extension goes exclusively to `https://app.prismcore.ru/api`.

| Endpoint | Method | Data sent | When |
|---|---|---|---|
| `/chrome/me` | GET | Bearer token (header only) | On connect; on popup open if token cached |
| `/chrome/find-template` | POST | `{ url }` | On popup open |
| `/chrome/templates` | GET | `?domain=...` | On popup open (advanced mode) |
| `/chrome/templates` | POST | Template name, URL pattern, CSS selectors | On user "Сохранить шаблон" |
| `/chrome/templates/{id}` | DELETE | — | On user "Удалить шаблон" |
| `/chrome/validate` | POST | Extracted field values, URL | On user "Проверить" |
| `/chrome/extract` | POST | Extracted fields, URL, template ID, region ID | On user "Добавить материал" (no screenshot branch) |
| `/chrome/extract-with-evidence` | POST | Extracted fields, URL, template ID, region ID, screenshot JPEG | On user "Добавить материал" |
| `/chrome/revision-items` | GET | — | On evidence revision tab open |
| `/chrome/generic-items` | GET | — | On evidence generic tab open |

No data is sent to analytics platforms, CDNs, or any third-party servers.
