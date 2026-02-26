/**
 * Prizm Chrome Extension — API Client
 * Handles all communication with the Prizm server.
 */
class PrizmAPI {
  constructor() {
    this.defaultBaseUrl = 'https://prismcore.ru/api';
    this.baseUrl = '';
    this.token = null;
    this._ready = this._init();
  }

  async _init() {
    const data = await chrome.storage.local.get(['apiBaseUrl', 'authToken']);
    this.baseUrl = data.apiBaseUrl || this.defaultBaseUrl;
    this.token = data.authToken || null;
  }

  async ready() {
    await this._ready;
  }

  /**
   * Save API configuration.
   */
  async configure(baseUrl, token) {
    this.baseUrl = (baseUrl || this.defaultBaseUrl).replace(/\/+$/, '');
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
}

// Export as singleton
const prizmApi = new PrizmAPI();
