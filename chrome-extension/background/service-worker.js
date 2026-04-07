/**
 * Prizm Chrome Extension — Background Service Worker
 * Handles messages between popup and content scripts.
 */

// Import API client
importScripts('../lib/api.js');

/**
 * Message handler for communication between popup/content scripts.
 */
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  handleMessage(message, sender)
    .then(sendResponse)
    .catch(err => sendResponse({ error: err.message }));
  return true; // Async response
});

async function handleMessage(message, sender) {
  const { action, data } = message;

  switch (action) {
    // ─── Capture feedback ───
    case 'FIELD_CAPTURED': {
      // Use chrome.storage.session for MV3-safe badge state.
      // globalThis is volatile across service-worker suspension; storage.session
      // persists for the lifetime of the browser session without that risk.
      const saved = await chrome.storage.session.get('prizmCapturedCount');
      const count = (saved.prizmCapturedCount || 0) + 1;
      await chrome.storage.session.set({ prizmCapturedCount: count });
      chrome.action.setBadgeText({ text: String(count) });
      chrome.action.setBadgeBackgroundColor({ color: '#059669' });
      return { received: true, count };
    }

    case 'CLEAR_BADGE':
      await chrome.storage.session.set({ prizmCapturedCount: 0 });
      chrome.action.setBadgeText({ text: '' });
      return { cleared: true };

    case 'GET_ME':
      return await prizmApi.getMe();

    case 'FIND_TEMPLATE':
      return await prizmApi.findTemplate(data.url);

    case 'LIST_TEMPLATES':
      return await prizmApi.listTemplates(data.domain);

    case 'SAVE_TEMPLATE':
      return await prizmApi.saveTemplate(data);

    case 'DELETE_TEMPLATE':
      return await prizmApi.deleteTemplate(data.id);

    case 'VALIDATE_FIELDS':
      return await prizmApi.validateFields(data.extracted, data.data_sources, data.url);

    case 'EXTRACT':
      return await prizmApi.extract(data.url, data.extracted, data.template_id, data.region_id, data.data_sources);

    case 'GET_REVISION_ITEMS':
      return await prizmApi.getRevisionItems();

    case 'GET_GENERIC_ITEMS':
      return await prizmApi.getGenericItems();

    case 'CHECK_AUTH':
      return { authenticated: await prizmApi.isAuthenticated() };

    case 'CONFIGURE': {
      // Validate field types before forwarding — neither field should be
      // undefined; token must be a string, baseUrl defaults to production URL
      // when not supplied.
      const token = typeof data.token === 'string' ? data.token : '';
      const baseUrl = typeof data.baseUrl === 'string' ? data.baseUrl : null;
      if (token === '' && baseUrl === null) {
        // Disconnect path — explicit clear is intentional
      } else if (token === '') {
        console.warn('[Prizm SW] CONFIGURE called with empty token — auth will fail');
      }
      await prizmApi.configure(baseUrl, token);
      return { success: true };
    }

    case 'GET_CONFIG':
      await prizmApi.ready();
      return {
        baseUrl: prizmApi.baseUrl,
        hasToken: !!prizmApi.token,
      };

    default:
      return { error: `Unknown action: ${action}` };
  }
}

/**
 * Context menu for quick capture.
 */
chrome.runtime.onInstalled.addListener(() => {
  chrome.contextMenus?.create({
    id: 'prizm-capture-element',
    title: 'Призма: захватить элемент',
    contexts: ['all'],
  });
});

/**
 * Shared injection helper — same 3-step contract used by both the context-menu
 * handler and any future service-worker-initiated injection.
 *
 * 1. PING the tab — if the content script already responds, return early.
 * 2. Inject content.js + content.css using chrome.scripting.
 * 3. Verify with a second PING before returning.
 *
 * Returns true if the content script is ready, false if the page is restricted
 * or injection failed. Never throws.
 */
async function ensureTabContentScript(tabId) {
  // Step 1: check if already injected
  try {
    const pong = await chrome.tabs.sendMessage(tabId, { action: 'PING', data: {} });
    if (pong?.pong) return true;
  } catch { /* not yet injected — proceed */ }

  // Step 2: inject
  try {
    await chrome.scripting.executeScript({ target: { tabId }, files: ['content/content.js'] });
    await chrome.scripting.insertCSS({ target: { tabId }, files: ['content/content.css'] }).catch(() => {});
    await new Promise(r => setTimeout(r, 150));
  } catch {
    return false; // restricted page (chrome://, Web Store, etc.)
  }

  // Step 3: verify
  try {
    const pong = await chrome.tabs.sendMessage(tabId, { action: 'PING', data: {} });
    return !!pong?.pong;
  } catch {
    return false;
  }
}

chrome.contextMenus?.onClicked.addListener(async (info, tab) => {
  if (info.menuItemId === 'prizm-capture-element' && tab?.id) {
    const ready = await ensureTabContentScript(tab.id);
    if (!ready) return; // restricted page — silently skip
    chrome.tabs.sendMessage(tab.id, {
      action: 'START_CAPTURE',
      data: { field: 'title' },
    }).catch(() => {}); // Tab may reject if restricted
  }
});
