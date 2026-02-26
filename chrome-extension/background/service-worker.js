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
      // Трекаем захваченные поля для бейджа
      if (!globalThis.__prizmCapturedFields) globalThis.__prizmCapturedFields = new Set();
      globalThis.__prizmCapturedFields.add(data.field);
      const count = globalThis.__prizmCapturedFields.size;
      chrome.action.setBadgeText({ text: String(count) });
      chrome.action.setBadgeBackgroundColor({ color: '#059669' });
      return { received: true, count };
    }

    case 'CLEAR_BADGE':
      globalThis.__prizmCapturedFields = new Set();
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

    case 'CHECK_AUTH':
      return { authenticated: await prizmApi.isAuthenticated() };

    case 'CONFIGURE':
      await prizmApi.configure(data.baseUrl, data.token);
      return { success: true };

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

chrome.contextMenus?.onClicked.addListener((info, tab) => {
  if (info.menuItemId === 'prizm-capture-element') {
    chrome.tabs.sendMessage(tab.id, {
      action: 'START_CAPTURE',
      data: { field: 'title' }, // Default to title capture
    });
  }
});
