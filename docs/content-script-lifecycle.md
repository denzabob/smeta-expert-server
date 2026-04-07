# Content Script Lifecycle

## Overview

`content/content.js` is **not** auto-injected at browser startup. It is injected on demand, only after an explicit user action (opening the popup or using the context menu). This document describes how that injection works, how cleanup is handled, and what the expected state transitions are.

---

## Injection model

Both the **popup** and the **service worker context-menu handler** use the same 3-step contract before any extraction command is sent to the content script:

```
1. PING the tab
   └─ if pong received → script already running, skip injection
2. inject content/content.js + content/content.css
   └─ chrome.scripting.executeScript / insertCSS
   └─ restricted pages (chrome://, Web Store) return false/throw here
3. Retry PING to confirm the script is responding
   └─ if no pong → surface error to the user
```

**Popup** (`popup/popup.js → ensureContentScript`)  
Called at the top of `loadPageInfo()` and `startFieldCapture()`. Surfaces typed errors (`RESTRICTED_PAGE`, `INJECT_FAILED`, `PING_FAILED`) with precise user-facing messages.

**Service worker context menu** (`background/service-worker.js → ensureTabContentScript`)  
Same 3-step contract extracted into a standalone async helper. Returns `true`/`false` — no error surfacing needed since context-menu UX cannot display error messages.

---

## Idempotent bootstrap guard

The content script opens with:

```js
if (window.__PRISM_CONTENT_READY__) return;
window.__PRISM_CONTENT_READY__ = true;
```

- On the **same page**, if the script is injected a second time (popup reopened, context menu triggered again), the `return` executes immediately. No listener is re-registered, no DOM element is re-created.
- After **page navigation**, the browser creates a new `window` object. `__PRISM_CONTENT_READY__` is absent, so the next injection initializes cleanly.
- The guard uses `window` (not a closure variable) so it survives across multiple injections of the same bundle.

---

## Style mutation tracking

The extension mutates inline styles of page elements in two places:

| Mutation | When | Lifecycle |
|----------|------|-----------|
| Solid colored outline `3px solid color` | Hover during capture mode | Transient — removed by `clearHighlight()` |
| Dashed outline `2px dashed color` | After field is captured (`addCapturedMarker`) | Persistent until `destroyUI()` or `restoreAllStyles()` |

### Hover highlight (transient)

`highlightElement(el)` saves the element's **current** inline style (which may already be the extension's own dashed outline) into `el.__prizmHoverOrig`. `clearHighlight()` reads this snapshot and restores it, then deletes the property.

This means hover-then-restore on a captured element correctly leaves the dashed outline in place, not the page's original style.

### Capture-marker outline (persistent)

`addCapturedMarker(el, field)` calls `saveCaptureStyle(el)` before mutating the style. `saveCaptureStyle` records the **pre-extension original** in `styledElements Map<HTMLElement, { outline, outlineOffset }>`. This is idempotent: if the same element appears in two fields' captures, the original is only saved once (from the first capture).

`restoreAllStyles()` iterates the Map, restores every element, and clears the Map.

---

## Cleanup and reset

### `destroyUI()` — visual-only reset (data preserved)

Restores the page to the visual state it was in before the extension touched it:

1. `stopCapture()` if active — removes event listeners, clears hover highlight
2. `overlay.remove()` + `tooltip.remove()` — safe even if not in DOM
3. Removes all `.prizm-captured-marker` elements from DOM
4. `restoreAllStyles()` — restores all elements in the `styledElements` registry

**Does NOT touch `capturedData` or `capturedSchemaMapping`.**

### `CLEAR_CAPTURED_DATA` message

Calls `destroyUI()` first (visual cleanup), then clears `capturedData = {}` and `capturedSchemaMapping = null`.

The page is left visually clean: no outlines, no markers, no overlays.

### `RESET_EXTENSION_UI` message

Calls `destroyUI()` only. Captured data is preserved. Useful for popup to recover from visual drift without losing the user's work.

### `STOP_CAPTURE` message

Calls `stopCapture()` only. Clears hover highlight, removes overlay and tooltip, removes event listeners. Captured markers and styles from previous captures are preserved.

---

## Message API

All messages are safe to send repeatedly. The content script handles each idempotently.

| Action | Effect | Clears data? | Safe to repeat? |
|--------|--------|-------------|----------------|
| `PING` | Returns `{ pong: true }` | No | ✅ |
| `GET_PAGE_INFO` | Returns URL / domain / title | No | ✅ |
| `GET_CAPTURED_DATA` | Returns current capturedData + schemaMapping | No | ✅ |
| `AUTO_DETECT_FIELDS` | Runs autodetect, merges into capturedData | No | ✅ |
| `APPLY_TEMPLATE` | Applies CSS selectors, merges into capturedData | No | ✅ |
| `APPLY_SCHEMA_MAPPING` | Applies schema mapping, merges into capturedData | No | ✅ |
| `DETECT_SCHEMA` | Extracts schema.org data from DOM | No | ✅ |
| `START_CAPTURE` | Starts capture mode for one field | No | ✅ (restarts) |
| `STOP_CAPTURE` | Stops capture mode | No | ✅ |
| `CLEAR_CAPTURED_DATA` | Full visual cleanup + data clear | **Yes** | ✅ |
| `RESET_EXTENSION_UI` | Full visual cleanup, data preserved | No | ✅ |
| `SET_SCHEMA_MAPPING` | Stores schema mapping for template saving | No | ✅ |

---

## State machine

```
[Injected]
  │
  ├─ PING                    → { pong: true }
  ├─ GET_PAGE_INFO           → page info
  ├─ AUTO_DETECT_FIELDS      → fills capturedData
  ├─ APPLY_TEMPLATE          → merges into capturedData
  ├─ DETECT_SCHEMA           → schema structure (no state change)
  ├─ APPLY_SCHEMA_MAPPING    → merges into capturedData
  │
  └─ START_CAPTURE(field)
       │
       └─ [Capture active]
            │
            ├─ user clicks element → FIELD_CAPTURED sent, stopCapture()
            ├─ user presses Escape → stopCapture()
            ├─ user clicks Cancel  → stopCapture()
            └─ STOP_CAPTURE msg   → stopCapture()
            
                    ↓
              [Injected, markers visible]
                    │
                    ├─ RESET_EXTENSION_UI → destroyUI() → [Injected, no markers]
                    └─ CLEAR_CAPTURED_DATA → destroyUI() + data reset → [Injected, clean]
```

---

## Repeated popup open behavior

| Scenario | Expected behavior |
|----------|------------------|
| Popup opened twice, same page, no navigation | Second `ensureContentScript` gets `pong` → no re-injection → same DOM state |
| Popup opened after context menu capture | `ensureContentScript` PINGs → pong received → proceeds with `GET_PAGE_INFO` etc. |
| Popup opened after page re-navigation | `window.__PRISM_CONTENT_READY__` absent → fresh injection, clean state |
| Capture started then popup closed | `FIELD_CAPTURED` sendMessage `.catch()` suppresses error → content state correct |
| `CLEAR_CAPTURED_DATA` sent mid-capture | `destroyUI()` calls `stopCapture()` first → no stale listeners or overlays |

---

## Edge cases handled

- **Element removed from DOM after capture** — `restoreAllStyles()` wraps each style restore in `try/catch`; DOM-removed elements are silently skipped.
- **Overlay/tooltip `.remove()` when not in DOM** — safe no-op per the DOM spec.
- **`removeEventListener` when listener was never added** — safe no-op per the DOM spec.
- **`saveCaptureStyle` called twice on same element** — idempotent; original is only saved on first call.
- **Hover over a captured element** — `highlightElement` saves current style (the dashed outline) into `el.__prizmHoverOrig`; `clearHighlight` restores the dashed outline, not the page's original — correct behavior.
