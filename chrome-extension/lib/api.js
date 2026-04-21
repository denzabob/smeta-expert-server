/**
 * Prizm Chrome Extension — API Client
 * Handles all communication with the Prizm server.
 */
class PrizmAPI {
  constructor() {
    this.defaultBaseUrl = 'https://app.prismcore.ru/api';
    this.baseUrl = '';
    this.token = null;
    this._ready = this._init();
  }

  async _init() {
    const data = await chrome.storage.local.get(['apiBaseUrl', 'apiBaseUrlOverride', 'authToken']);
    const overrideBaseUrl = (data.apiBaseUrlOverride || '').replace(/\/+$/, '');
    const storedBaseUrl = (data.apiBaseUrl || '').replace(/\/+$/, '');
    const effectiveStoredBaseUrl = this.isLegacyLocalUrl(storedBaseUrl) ? '' : storedBaseUrl;
    this.baseUrl = overrideBaseUrl ? this.resolveBaseUrl(overrideBaseUrl) : this.resolveBaseUrl(effectiveStoredBaseUrl);
    this.token = data.authToken || null;

    if (this.baseUrl !== storedBaseUrl && this.baseUrl === this.defaultBaseUrl) {
      await chrome.storage.local.set({ apiBaseUrl: this.baseUrl });
    }
  }

  async ready() {
    await this._ready;
  }

  isLegacyLocalUrl(url) {
    return /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?(\/api)?$/i.test(url);
  }

  resolveBaseUrl(url) {
    const normalizedUrl = (url || '').replace(/\/+$/, '');
    return normalizedUrl || this.defaultBaseUrl;
  }

  /**
   * Save API configuration.
   */
  async configure(baseUrl, token) {
    this.baseUrl = this.resolveBaseUrl(baseUrl);
    this.token = token;
    await chrome.storage.local.set({
      apiBaseUrl: this.baseUrl,
      authToken: this.token,
    });
  }

  /**
   * Make an authenticated API request.
   */
  async request(method, path, body = null) {
    await this.ready();

    if (!this.token) {
      throw new Error('Не авторизован. Подключите API-токен в настройках расширения.');
    }

    const url = `${this.baseUrl}${path}`;
    const options = {
      method,
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${this.token}`,
      },
    };

    if (body && method !== 'GET') {
      options.body = JSON.stringify(body);
    }

    const response = await fetch(url, options);

    if (response.status === 401) {
      // Token expired or invalid
      await chrome.storage.local.remove('authToken');
      this.token = null;
      throw new Error('Сессия истекла. Войдите заново.');
    }

    const data = await response.json();

    if (!response.ok) {
      const message = data.message || data.errors
        ? (typeof data.errors === 'object' ? Object.values(data.errors).flat().join('; ') : data.message)
        : `Ошибка сервера (${response.status})`;
      throw new Error(message);
    }

    return data;
  }

  // --- API Methods ---

  async getMe() {
    return this.request('GET', '/chrome/me');
  }

  async findTemplate(url) {
    return this.request('POST', '/chrome/find-template', { url });
  }

  async listTemplates(domain) {
    return this.request('GET', `/chrome/templates?domain=${encodeURIComponent(domain)}`);
  }

  async saveTemplate(templateData) {
    return this.request('POST', '/chrome/templates', templateData);
  }

  async deleteTemplate(id) {
    return this.request('DELETE', `/chrome/templates/${id}`);
  }

  async validateFields(extracted, dataSources = null, url = null) {
    const body = { extracted };
    if (dataSources) body.data_sources = dataSources;
    if (url) body.url = url;
    return this.request('POST', '/chrome/validate', body);
  }

  async extract(url, extracted, templateId = null, regionId = null, dataSources = null) {
    const body = { url, extracted };
    if (templateId) body.template_id = templateId;
    if (regionId) body.region_id = regionId;
    if (dataSources) body.data_sources = dataSources;
    return this.request('POST', '/chrome/extract', body);
  }

  // --- Revision Evidence ---

  async getRevisionItems() {
    return this.request('GET', '/chrome/revision-items');
  }

  /**
   * Submit evidence for a revision item using multipart/form-data.
   * Must be called directly from popup (not through service-worker message passing)
   * because FormData/Blob cannot be serialized via chrome.runtime.sendMessage.
   */
  async submitItemEvidence(itemId, formData) {
    await this.ready();

    if (!this.token) {
      throw new Error('Не авторизован. Подключите API-токен в настройках расширения.');
    }

    const url = `${this.baseUrl}/chrome/revision-items/${itemId}/evidence`;
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Authorization': `Bearer ${this.token}`,
      },
      body: formData,
    });

    if (response.status === 401) {
      await chrome.storage.local.remove('authToken');
      this.token = null;
      throw new Error('Сессия истекла. Войдите заново.');
    }

    const data = await response.json();

    if (!response.ok) {
      const message = data.error || data.message || `Ошибка сервера (${response.status})`;
      const err = new Error(message);
      err.status = response.status;
      throw err;
    }

    return data;
  }

  /**
   * Login with email/password and obtain a Sanctum token.
   */
  async login(email, password) {
    const url = `${this.baseUrl}/login`;
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ email, password }),
    });

    if (!response.ok) {
      const data = await response.json().catch(() => ({}));
      throw new Error(data.message || 'Ошибка авторизации');
    }

    const data = await response.json();

    // For token-based auth, we need to create a token. 
    // Try the /api/chrome/token endpoint or use session cookie approach.
    // For simplicity in extension context, we store session info.
    return data;
  }

  /**
   * Check if we're authenticated.
   */
  async isAuthenticated() {
    await this.ready();
    if (!this.token) return false;
    try {
      await this.getMe();
      return true;
    } catch {
      return false;
    }
  }

  // ── Generic Evidence Capture (Block G3) ─────────────────────────

  /**
   * GET /api/chrome/generic-items
   * List open generic evidence items for the current user.
   */
  async getGenericItems() {
    return this.request('GET', '/chrome/generic-items');
  }

  /**
   * GET /api/pricing/labor/profiles
   * Load user labor profiles for manual labor capture.
   */
  async getLaborProfiles() {
    return this.request('GET', '/pricing/labor/profiles?is_active=1&per_page=100');
  }

  /**
   * POST /api/chrome/capture-observation
   * Create a standalone evidence record (not tied to a run item).
   * Must be called directly from popup (FormData cannot be serialized).
   */
  async captureObservation(formData) {
    await this.ready();
    if (!this.token) {
      throw new Error('Не авторизован. Подключите API-токен в настройках расширения.');
    }
    const url = `${this.baseUrl}/chrome/capture-observation`;
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Authorization': `Bearer ${this.token}`,
      },
      body: formData,
    });
    if (response.status === 401) {
      await chrome.storage.local.remove('authToken');
      this.token = null;
      throw new Error('Сессия истекла. Войдите заново.');
    }
    const data = await response.json();
    if (!response.ok) {
      const message = data.error || data.message || `Ошибка сервера (${response.status})`;
      const err = new Error(message);
      err.status = response.status;
      throw err;
    }
    return data;
  }

  /**
   * POST /api/chrome/generic-items/{itemId}/capture
   * Submit evidence for a specific generic evidence item.
   * Must be called directly from popup (FormData cannot be serialized).
   */
  async captureGenericItem(itemId, formData) {
    await this.ready();
    if (!this.token) {
      throw new Error('Не авторизован. Подключите API-токен в настройках расширения.');
    }
    const url = `${this.baseUrl}/chrome/generic-items/${itemId}/capture`;
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Authorization': `Bearer ${this.token}`,
      },
      body: formData,
    });
    if (response.status === 401) {
      await chrome.storage.local.remove('authToken');
      this.token = null;
      throw new Error('Сессия истекла. Войдите заново.');
    }
    const data = await response.json();
    if (!response.ok) {
      const message = data.error || data.message || `Ошибка сервера (${response.status})`;
      const err = new Error(message);
      err.status = response.status;
      throw err;
    }
    return data;
  }

  /**
   * POST /api/chrome/extract-with-evidence
   * One-click material upsert + evidence screenshot + auto-link.
   * Uses FormData for screenshot upload — must be called directly from popup.
   */
  async extractWithEvidence(formData) {
    await this.ready();
    if (!this.token) {
      throw new Error('Не авторизован. Подключите API-токен в настройках расширения.');
    }
    const url = `${this.baseUrl}/chrome/extract-with-evidence`;
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Authorization': `Bearer ${this.token}`,
      },
      body: formData,
    });
    if (response.status === 401) {
      await chrome.storage.local.remove('authToken');
      this.token = null;
      throw new Error('Сессия истекла. Войдите заново.');
    }
    const data = await response.json();
    if (!response.ok) {
      const message = data.error || data.message || `Ошибка сервера (${response.status})`;
      const err = new Error(message);
      err.status = response.status;
      throw err;
    }
    return data;
  }

  /**
   * POST /api/chrome/labor-captures
   * Submit labor vacancy evidence with screenshot.
   * Must be called directly from popup because FormData cannot be serialized
   * through chrome.runtime.sendMessage.
   */
  async captureLabor(formData) {
    await this.ready();
    if (!this.token) {
      throw new Error('Не авторизован. Подключите API-токен в настройках расширения.');
    }

    const url = `${this.baseUrl}/chrome/labor-captures`;
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Authorization': `Bearer ${this.token}`,
      },
      body: formData,
    });

    if (response.status === 401) {
      await chrome.storage.local.remove('authToken');
      this.token = null;
      throw new Error('Сессия истекла. Войдите заново.');
    }

    const data = await response.json();
    if (!response.ok) {
      const message = data.error || data.message || (typeof data.errors === 'object'
        ? Object.values(data.errors).flat().join('; ')
        : `Ошибка сервера (${response.status})`);
      const err = new Error(message);
      err.status = response.status;
      throw err;
    }

    return data;
  }
}

// Export as singleton
const prizmApi = new PrizmAPI();
