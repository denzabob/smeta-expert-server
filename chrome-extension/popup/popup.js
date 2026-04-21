/**
 * Prizm Chrome Extension — Popup Script
 * Main UI logic for the extension popup.
 */
(function () {
  'use strict';

  // ============================================================
  // State
  // ============================================================
  let currentTab = null;
  let pageInfo = null;
  let currentTemplate = null;
  let capturedFields = {};
  let currentMode = 'material';
  let userInfo = null;
  let domainTemplates = [];
  let detectedMaterialUnit = 'шт';
  let isAdvancedMode = false;
  let autoFillWarnings = [];
  let templateAutoApplied = false;
  let analyzeTimer = null;
  const ANALYZE_DEBOUNCE_MS = 400;
  const MODE_STORAGE_KEY = 'prizmCaptureMode';

  const materialFormState = {
    get capturedFields() {
      return capturedFields;
    },
  };
  let laborFormState = createInitialLaborFormState();
  let laborProfiles = [];
  let laborProfilesLoaded = false;
  let laborCapturedMeta = {};
  let laborFieldMeta = createInitialLaborFieldMeta();
  let laborValidationErrors = {};
  let laborDetailsExpanded = false;
  let laborSalaryPeriodTouched = false;

  // ============================================================
  // DOM refs
  // ============================================================
  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => document.querySelectorAll(sel);

  // Sections
  const sectionAuth = $('#section-auth');
  const sectionMain = $('#section-main');

  // Auth
  const inputToken = $('#input-token');
  const btnConnect = $('#btn-connect');
  const authError = $('#auth-error');
  const authStatus = $('#auth-status');
  const statusDot = authStatus.querySelector('.status-dot');
  const statusText = $('#status-text');

  // Page info
  const pageDomain = $('#page-domain');
  const pageUrl = $('#page-url');
  const templateStatus = $('#template-status');
  const pageLoadingStatus = $('#page-loading-status');
  const simpleStatus = $('#simple-status');
  const autoTemplateBanner = $('#auto-template-banner');
  const btnAdvancedToggle = $('#btn-advanced-toggle');
  const firstRunHelper = $('#first-run-helper');
  const btnDismissOnboarding = $('#btn-dismiss-onboarding');
  const btnSuggestTemplate = $('#btn-suggest-template');
  const modeBadge = $('#mode-badge');
  const btnModeMaterial = $('#btn-mode-material');
  const btnModeLabor = $('#btn-mode-labor');
  const materialModePanel = $('#material-mode-panel');
  const laborModePanel = $('#labor-mode-panel');
  const laborProfileHint = $('#labor-profile-hint');
  const btnAddLabor = $('#btn-add-labor');
  const laborResult = $('#labor-result');
  const laborDetails = $('#labor-details');
  const btnLaborDetailsToggle = $('#btn-labor-details-toggle');
  const laborConfidence = $('#labor-confidence');
  const laborSubmitHint = $('#labor-submit-hint');

  // Capture
  const btnValidate = $('#btn-validate');
  const btnAddMaterial = $('#btn-add-material');
  const btnClear = $('#btn-clear');
  const captureResult = $('#capture-result');
  const validationPreview = $('#validation-preview');
  const previewContent = $('#preview-content');

  // Schema.org — use getter pattern because these are replaced by innerHTML
  const schemaBanner = $('#schema-banner');
  const schemaRefs = {
    details: $('#schema-details'),
    toggle: $('#btn-schema-toggle'),
    container: $('#schema-fields-container'),
    selector: $('#schema-selector'),
    select: $('#schema-select'),
    apply: $('#btn-schema-apply'),
  };
  // Aliases for backward compat with existing code
  Object.defineProperties(window, {
    __prizmSchemaDetails: { get: () => schemaRefs.details },
    __prizmSchemaToggle: { get: () => schemaRefs.toggle },
  });
  let schemaData = null;
  let lastSchemaMapping = null; // {schemaIndex, mapping} — set when user applies Schema.org mapping

  // Template
  const templateName = $('#template-name');
  const urlPattern = $('#url-pattern');
  const templateDefault = $('#template-default');
  const btnSaveTemplate = $('#btn-save-template');
  const templateSaveResult = $('#template-save-result');
  const templatesList = $('#templates-list');
  const btnApplyTemplate = $('#btn-apply-template');
  const applyResult = $('#apply-result');

  // Settings
  const settingsUserName = $('#settings-user-name');
  const settingsUserEmail = $('#settings-user-email');
  const settingsRegion = $('#settings-region');
  const btnDisconnect = $('#btn-disconnect');
  const btnOpenPrismSite = $('#btn-open-prism-site');
  const DEFAULT_API_URL = 'https://app.prismcore.ru/api';
  const ONBOARDING_KEY = 'prizm_onboarding_seen_v1';
  const SUCCESS_COUNTER_KEY = 'prizm_success_counter_by_domain_v1';
  const LABOR_FIELDS = [
    'vacancy_title',
    'employer_name',
    'provider_title',
    'provider_domain',
    'source_url',
    'source_title',
    'source_date',
    'labor_profile_id',
    'vacancy_description',
    'salary_raw_text',
    'salary_value',
    'salary_value_min',
    'salary_value_max',
    'salary_period',
    'hours_per_month',
    'derived_hourly_rate',
    'currency',
    'note',
  ];
  const laborFieldRefs = {
    vacancy_title: $('#labor-vacancy-title'),
    employer_name: $('#labor-employer-name'),
    provider_title: $('#labor-provider-title'),
    provider_domain: $('#labor-provider-domain'),
    source_url: $('#labor-source-url'),
    source_title: $('#labor-source-title'),
    source_date: $('#labor-source-date'),
    labor_profile_id: $('#labor-profile-id'),
    vacancy_description: $('#labor-vacancy-description'),
    salary_raw_text: $('#labor-salary-raw-text'),
    salary_value: $('#labor-salary-value'),
    salary_value_min: $('#labor-salary-min'),
    salary_value_max: $('#labor-salary-max'),
    salary_period: $('#labor-salary-period'),
    hours_per_month: $('#labor-hours-per-month'),
    derived_hourly_rate: $('#labor-derived-hourly-rate'),
    currency: $('#labor-currency'),
    note: $('#labor-note'),
  };
  const laborErrorRefs = {
    labor_profile_id: $('#labor-error-labor_profile_id'),
    source_url: $('#labor-error-source_url'),
    vacancy_title: $('#labor-error-vacancy_title'),
    salary_raw_text: $('#labor-error-salary_raw_text'),
    salary_period: $('#labor-error-salary_period'),
    employer_name: $('#labor-error-employer_name'),
  };
  const laborSourceRefs = {
    vacancy_title: $('#labor-source-vacancy_title'),
    source_url: $('#labor-source-source_url'),
    salary_raw_text: $('#labor-source-salary_raw_text'),
    salary_period: $('#labor-source-salary_period'),
    employer_name: $('#labor-source-employer_name'),
    source_date: $('#labor-source-source_date'),
    source_title: $('#labor-source-source_title'),
    salary_value: $('#labor-source-salary_value'),
    salary_value_min: $('#labor-source-salary_value_min'),
    salary_value_max: $('#labor-source-salary_value_max'),
    vacancy_description: $('#labor-source-vacancy_description'),
  };

  // ============================================================
  // Helpers
  // ============================================================

  function sendToBackground(action, data = {}) {
    return new Promise((resolve, reject) => {
      chrome.runtime.sendMessage({ action, data }, (response) => {
        if (chrome.runtime.lastError) {
          reject(new Error(chrome.runtime.lastError.message));
        } else if (response?.error) {
          reject(new Error(response.error));
        } else {
          resolve(response);
        }
      });
    });
  }

  function sendToContent(action, data = {}, timeoutMs = 0) {
    return new Promise((resolve, reject) => {
      if (!currentTab?.id) {
        reject(new Error('No active tab'));
        return;
      }
      let settled = false;
      let timer;
      if (timeoutMs > 0) {
        timer = setTimeout(() => {
          if (!settled) { settled = true; reject(new Error('Timeout')); }
        }, timeoutMs);
      }
      chrome.tabs.sendMessage(currentTab.id, { action, data }, (response) => {
        if (settled) return;
        settled = true;
        if (timer) clearTimeout(timer);
        if (chrome.runtime.lastError) {
          reject(new Error(chrome.runtime.lastError.message));
        } else {
          resolve(response);
        }
      });
    });
  }

  function showResult(el, message, type = 'success') {
    el.textContent = message;
    el.className = `result-message ${type}`;
    el.classList.remove('hidden');
    setTimeout(() => el.classList.add('hidden'), 5000);
  }

  function escapeHtml(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function createInitialLaborFormState() {
    return {
      vacancy_title: '',
      employer_name: '',
      provider_title: '',
      provider_domain: '',
      source_url: '',
      source_title: '',
      source_date: '',
      labor_profile_id: '',
      vacancy_description: '',
      salary_raw_text: '',
      salary_value: '',
      salary_value_min: '',
      salary_value_max: '',
      salary_period: '',
      hours_per_month: '160',
      derived_hourly_rate: '',
      currency: 'RUB',
      note: '',
    };
  }

  function createInitialLaborFieldMeta() {
    return {
      confidence: null,
      fields: {},
    };
  }

  function showLaborResult(message, type = 'success') {
    if (!laborResult) return;
    showResult(laborResult, message, type);
  }

  function clearLaborResult() {
    if (!laborResult) return;
    laborResult.className = 'result-message hidden';
    laborResult.textContent = '';
  }

  function setLaborDetailsExpanded(expanded) {
    laborDetailsExpanded = !!expanded;
    laborDetails?.classList.toggle('hidden', !laborDetailsExpanded);
    btnLaborDetailsToggle?.setAttribute('aria-expanded', laborDetailsExpanded ? 'true' : 'false');
  }

  function getLaborSourceLabel(source) {
    const map = {
      schema: 'Schema',
      offer: 'Offer',
      dom: 'DOM',
      site_fallback: 'DOM',
      parsed: 'Parsed',
      manual: 'Manual',
    };
    return map[source] || '';
  }

  function renderLaborMeta() {
    Object.entries(laborSourceRefs).forEach(([field, el]) => {
      if (!el) return;
      const source = laborFieldMeta.fields?.[field]?.source || '';
      const label = getLaborSourceLabel(source);
      el.textContent = label;
      el.className = 'labor-source-badge';
      if (!label) {
        el.classList.add('hidden');
        return;
      }
      el.classList.add(`source-${source}`);
      el.classList.remove('hidden');
    });

    if (laborConfidence) {
      const confidence = laborFieldMeta.confidence;
      if (typeof confidence === 'number') {
        laborConfidence.textContent = `Уверенность: ${Math.round(confidence * 100)}%`;
        laborConfidence.classList.remove('hidden');
      } else {
        laborConfidence.textContent = '';
        laborConfidence.classList.add('hidden');
      }
    }
  }

  function markLaborFieldManual(field) {
    if (!(field in laborFormState)) return;
    laborFieldMeta.fields[field] = {
      ...(laborFieldMeta.fields[field] || {}),
      source: 'manual',
      manualLocked: true,
    };
  }

  function isLaborFieldManualLocked(field) {
    return !!laborFieldMeta.fields?.[field]?.manualLocked;
  }

  function detectSalaryPeriodFromText(value) {
    const text = String(value || '').toLowerCase();
    if (!text) return '';

    if (
      /(\bв\s*месяц\b|\bза\s*месяц\b|руб\.?\s*\/\s*мес\b|руб\/мес\b|\bmonthly\b|\bв\s*мес\.?\b)/i.test(text)
    ) return 'month';
    if (/(\bв\s*час\b|руб\.?\s*\/\s*ч\b|руб\/ч\b|\bhour\b)/i.test(text)) return 'hour';
    if (/(\bв\s*день\b|\bза\s*день\b)/i.test(text)) return 'day';
    if (/(\bв\s*год\b|\bза\s*год\b)/i.test(text)) return 'year';

    return '';
  }

  function maybeAutoFillSalaryPeriod(options = {}) {
    if (laborSalaryPeriodTouched) return laborFormState.salary_period;
    if (laborFormState.salary_period && !options.force) return laborFormState.salary_period;

    const detected = detectSalaryPeriodFromText(laborFormState.salary_raw_text);
    if (detected) {
      laborFormState.salary_period = detected;
      const el = laborFieldRefs.salary_period;
      if (el) el.value = detected;
    }
    return laborFormState.salary_period;
  }

  function getFriendlyLaborErrorMessage(kind, err) {
    const rawMessage = String(err?.message || err || '').trim();
    if (rawMessage) {
      console.error(`[labor:${kind}]`, err);
    }

    if (kind === 'profiles') return 'Не удалось загрузить профили работ';
    if (kind === 'screenshot') return 'Не удалось сделать скриншот страницы';
    if (kind === 'page-data') return 'Не удалось получить данные страницы';
    if (kind === 'vacancy-data') return 'Ошибка получения данных вакансии';
    return 'Не удалось отправить вакансию';
  }

  function setLaborFieldError(field, message = '') {
    const group = document.querySelector(`[data-labor-field="${field}"]`);
    const errorEl = laborErrorRefs[field];
    group?.classList.toggle('is-invalid', !!message);
    if (!errorEl) return;
    errorEl.textContent = message;
    errorEl.classList.toggle('hidden', !message);
  }

  function renderLaborValidation() {
    Object.keys(laborErrorRefs).forEach((field) => {
      setLaborFieldError(field, laborValidationErrors[field] || '');
    });
  }

  function clearLaborValidation(fields = null) {
    if (!fields) {
      laborValidationErrors = {};
      renderLaborValidation();
      return;
    }
    fields.forEach((field) => {
      delete laborValidationErrors[field];
      setLaborFieldError(field, '');
    });
  }

  function applyLaborCapturedField(field, value, selector, xpath) {
    if (!field || !value) return;
    if (!(field in laborFormState)) return;

    laborFormState[field] = value;
    laborCapturedMeta[field] = { value, selector: selector || null, xpath: xpath || null };
    laborFieldMeta.fields[field] = {
      ...(laborFieldMeta.fields[field] || {}),
      source: 'manual',
      manualLocked: true,
    };
    clearLaborResult();

    if (['employer_name', 'source_title', 'source_date', 'vacancy_description'].includes(field)) {
      setLaborDetailsExpanded(true);
    }

    if (field === 'source_url' || field === 'labor_profile_id') {
      clearLaborValidation([field]);
    }
    if (field === 'vacancy_title' || field === 'salary_raw_text') {
      clearLaborValidation(['vacancy_title', 'salary_raw_text']);
    }
    if (field === 'salary_raw_text') {
      maybeAutoFillSalaryPeriod();
    }
    renderLaborFormState();
  }

  function hasHardRequiredLaborFields() {
    return !!(String(laborFormState.labor_profile_id || '').trim() && String(laborFormState.source_url || '').trim());
  }

  function getLaborSubmitHintMessage() {
    const hasProfile = !!String(laborFormState.labor_profile_id || '').trim();
    const hasSourceUrl = !!String(laborFormState.source_url || '').trim();

    if (!laborProfilesLoaded) {
      return 'Ждём загрузку профилей работ.';
    }

    if (!laborProfiles.length) {
      return 'Создайте профиль работ в системе, чтобы добавить вакансию.';
    }

    if (!hasProfile && !hasSourceUrl) {
      return 'Чтобы активировать кнопку, выберите профиль работ и укажите ссылку на источник.';
    }

    if (!hasProfile) {
      return 'Чтобы активировать кнопку, выберите профиль работ.';
    }

    if (!hasSourceUrl) {
      return 'Чтобы активировать кнопку, укажите ссылку на источник.';
    }

    return 'Кнопка активна. Перед отправкой проверьте зарплату и работодателя.';
  }

  function updateLaborSubmitState() {
    if (!btnAddLabor) return;
    const profilesAvailable = laborProfiles.length > 0;
    const profilesReady = laborProfilesLoaded ? profilesAvailable : !laborFieldRefs.labor_profile_id?.disabled;
    btnAddLabor.disabled = !hasHardRequiredLaborFields() || !profilesReady;
    if (laborSubmitHint) {
      laborSubmitHint.textContent = getLaborSubmitHintMessage();
    }
  }

  function mergeJobPostingDataIntoLaborForm(data) {
    if (currentMode !== 'labor' || !data || typeof data !== 'object') return false;

    let changed = false;
    const mergeableFields = [
      'vacancy_title',
      'vacancy_description',
      'employer_name',
      'salary_value',
      'salary_value_min',
      'salary_value_max',
      'salary_period',
      'source_date',
      'source_url',
      'currency',
      'region_name',
    ];

    for (const field of mergeableFields) {
      if (!(field in data)) continue;
      if (!(field in laborFormState)) continue;
      const incomingValue = typeof data[field] === 'string' ? data[field].trim() : String(data[field] ?? '').trim();
      if (!incomingValue) continue;

      if (field === 'salary_period' && laborSalaryPeriodTouched) continue;
      if (isLaborFieldManualLocked(field)) continue;

      const currentValue = String(laborFormState[field] ?? '').trim();
      if (currentValue) continue;

      laborFormState[field] = incomingValue;
      const source = String(data[`${field}_source`] || '').trim();
      if (source) {
        laborFieldMeta.fields[field] = {
          ...(laborFieldMeta.fields[field] || {}),
          source,
          manualLocked: false,
        };
      }
      changed = true;
    }

    if (typeof data.confidence === 'number') {
      laborFieldMeta.confidence = data.confidence;
    } else if (!Number.isNaN(Number(data.confidence))) {
      laborFieldMeta.confidence = Number(data.confidence);
    }

    if (changed) {
      clearLaborValidation(['vacancy_title', 'salary_raw_text', 'source_url', 'employer_name']);
      renderLaborFormState();
    } else {
      renderLaborMeta();
    }

    return changed;
  }

  async function requestJobPostingExtraction() {
    if (currentMode !== 'labor' || !currentTab?.id) return;

    try {
      await ensureContentScript();
      const response = await sendToContent('EXTRACT_JOB_POSTING', {}, 5000);
      if (response?.found && response.data) {
        mergeJobPostingDataIntoLaborForm(response.data);
      }
    } catch (err) {
      console.warn('JobPosting extraction skipped:', err);
      if (currentMode === 'labor') {
        showLaborResult(getFriendlyLaborErrorMessage('vacancy-data', err), 'error');
      }
    }
  }

  // ============================================================
  // Content script injection — on-demand, user-invoked only
  // ============================================================

  const RESTRICTED_URL_PATTERNS = [
    /^chrome:\/\//,
    /^chrome-extension:\/\//,
    /^https:\/\/chrome\.google\.com\/webstore/,
    /^about:/,
    /^data:/,
    /^blob:/,
    /^javascript:/,
  ];

  function isRestrictedPage(url) {
    if (!url) return true;
    return RESTRICTED_URL_PATTERNS.some(p => p.test(url));
  }

  function getRestrictedPageMessage(url) {
    if (!url) return 'URL страницы не определён. Откройте карточку товара поставщика.';
    if (/^chrome:\/\//.test(url)) return 'Расширение не работает на служебных страницах Chrome.';
    if (/^chrome-extension:\/\//.test(url)) return 'Расширение не работает на страницах расширений.';
    if (/^https:\/\/chrome\.google\.com\/webstore/.test(url)) return 'Расширение не работает на странице Chrome Web Store.';
    return 'Эта страница не поддерживается. Откройте карточку товара поставщика.';
  }

  /**
   * Ensure content script is injected into the current tab.
   * Safe to call multiple times — the content script has an idempotent guard.
   * Must only be called while the popup is open (activeTab grant is active).
   *
   * Throws an Error with .code property on failure:
   *   'RESTRICTED_PAGE' — page cannot be scripted
   *   'INJECT_FAILED'   — scripting API rejected the injection
   *   'PING_FAILED'     — script injected but did not respond
   */
  async function ensureContentScript() {
    const url = currentTab?.url || '';

    if (isRestrictedPage(url)) {
      throw Object.assign(
        new Error(getRestrictedPageMessage(url)),
        { code: 'RESTRICTED_PAGE' }
      );
    }

    // 1. Check if already injected (same session, popup reopened)
    try {
      const pong = await sendToContent('PING', {}, 1200);
      if (pong?.pong) return; // Already active
    } catch { /* not yet injected */ }

    // 2. Inject JS + CSS using activeTab grant
    try {
      await chrome.scripting.executeScript({
        target: { tabId: currentTab.id },
        files: ['content/content.js'],
      });
      await chrome.scripting.insertCSS({
        target: { tabId: currentTab.id },
        files: ['content/content.css'],
      });
      // Brief pause for the script's synchronous initialization
      await new Promise(r => setTimeout(r, 150));
    } catch (err) {
      const msg = err.message || '';
      if (/Cannot access|blocked|denied/i.test(msg)) {
        throw Object.assign(
          new Error('Страница заблокировала доступ расширения. Попробуйте обновить страницу.'),
          { code: 'INJECT_FAILED' }
        );
      }
      throw Object.assign(
        new Error('Не удалось подключиться к странице: ' + msg),
        { code: 'INJECT_FAILED' }
      );
    }

    // 3. Verify the script is responding
    try {
      const pong = await sendToContent('PING', {}, 1500);
      if (!pong?.pong) throw new Error('no pong');
    } catch {
      throw Object.assign(
        new Error('Страница не ответила после подключения. Обновите страницу и попробуйте снова.'),
        { code: 'PING_FAILED' }
      );
    }
  }

  function truncate(str, len = 60) {
    if (!str) return '';
    return str.length > len ? str.substring(0, len) + '…' : str;
  }

  function setSimpleStatus(message, tone = 'info') {
    if (!simpleStatus) return;
    simpleStatus.textContent = message;
    simpleStatus.classList.remove('status-success', 'status-warning', 'status-error');
    if (tone === 'success') simpleStatus.classList.add('status-success');
    if (tone === 'warning') simpleStatus.classList.add('status-warning');
    if (tone === 'error') simpleStatus.classList.add('status-error');
  }

  function setAutoTemplateBanner(message = '') {
    if (!autoTemplateBanner) return;
    if (!message) {
      autoTemplateBanner.classList.add('hidden');
      autoTemplateBanner.textContent = '';
      return;
    }
    autoTemplateBanner.textContent = message;
    autoTemplateBanner.classList.remove('hidden');
  }

  function getModeLabel(mode) {
    return mode === 'labor' ? 'вакансии' : 'материалы';
  }

  async function restoreCaptureMode() {
    const saved = await chrome.storage.local.get(MODE_STORAGE_KEY);
    return saved?.[MODE_STORAGE_KEY] === 'labor' ? 'labor' : 'material';
  }

  function persistCaptureMode(mode) {
    return chrome.storage.local.set({ [MODE_STORAGE_KEY]: mode });
  }

  function hydrateLaborDefaults() {
    const currentUrl = pageInfo?.url || currentTab?.url || '';
    const currentTitle = pageInfo?.title || currentTab?.title || '';
    let hostname = '';
    try {
      hostname = currentUrl ? new URL(currentUrl).hostname.replace(/^www\./, '') : '';
    } catch {
      hostname = '';
    }

    if (!laborFormState.source_url && currentUrl) laborFormState.source_url = currentUrl;
    if (!laborFormState.source_title && currentTitle) laborFormState.source_title = currentTitle;
    if (!laborFormState.provider_domain && hostname) laborFormState.provider_domain = hostname;
    if (!laborFormState.provider_title && hostname) laborFormState.provider_title = hostname;
  }

  function renderLaborFormState() {
    hydrateLaborDefaults();
    for (const field of LABOR_FIELDS) {
      const el = laborFieldRefs[field];
      if (!el) continue;
      el.value = laborFormState[field] ?? '';
    }
    setLaborDetailsExpanded(laborDetailsExpanded);
    renderLaborValidation();
    renderLaborMeta();
    updateLaborSubmitState();
  }

  function loadLaborProfilesShell() {
    const select = laborFieldRefs.labor_profile_id;
    if (!select) return;
    select.disabled = true;
    select.innerHTML = '<option value="">Загрузка профилей работ...</option>';
    if (laborProfileHint) {
      laborProfileHint.textContent = 'Загрузка профилей работ...';
    }
    updateLaborSubmitState();
  }

  function setLaborProfileSelectLoading() {
    const select = laborFieldRefs.labor_profile_id;
    if (!select) return;
    select.disabled = true;
    select.innerHTML = '<option value="">Загрузка профилей работ...</option>';
    if (laborProfileHint) {
      laborProfileHint.textContent = 'Загружаем профили работ...';
    }
    updateLaborSubmitState();
  }

  function normalizeLaborProfilesResponse(response) {
    if (Array.isArray(response?.data?.data)) return response.data.data;
    if (Array.isArray(response?.data)) return response.data;
    if (Array.isArray(response)) return response;
    return [];
  }

  function renderLaborProfilesSelect() {
    const select = laborFieldRefs.labor_profile_id;
    if (!select) return;

    if (!laborProfiles.length) {
      select.disabled = true;
      select.innerHTML = '<option value="">Нет профилей работ</option>';
      laborFormState.labor_profile_id = '';
      if (laborProfileHint) {
        laborProfileHint.textContent = 'Создайте профиль работ в системе';
      }
      updateLaborSubmitState();
      return;
    }

    select.disabled = false;
    const currentValue = String(laborFormState.labor_profile_id || '');
    select.innerHTML = [
      '<option value="">Выберите профиль работ</option>',
      ...laborProfiles.map((profile) => {
        const id = String(profile.id ?? '');
        const selected = id === currentValue ? ' selected' : '';
        return `<option value="${escapeHtml(id)}"${selected}>${escapeHtml(profile.title || `Профиль #${id}`)}</option>`;
      }),
    ].join('');

    if (laborProfileHint) {
      laborProfileHint.textContent = 'Профиль работ обязателен для отправки вакансии';
    }
    updateLaborSubmitState();
  }

  async function loadLaborProfiles() {
    if (laborProfilesLoaded) {
      renderLaborProfilesSelect();
      return;
    }

    setLaborProfileSelectLoading();
    try {
      const response = await sendToBackground('GET_LABOR_PROFILES');
      laborProfiles = normalizeLaborProfilesResponse(response);
      laborProfilesLoaded = true;
      renderLaborProfilesSelect();
    } catch (err) {
      laborProfiles = [];
      laborProfilesLoaded = false;
      console.error('Failed to load labor profiles', err);
      const select = laborFieldRefs.labor_profile_id;
      if (select) {
        select.disabled = true;
        select.innerHTML = '<option value="">Не удалось загрузить профили работ</option>';
      }
      if (laborProfileHint) {
        laborProfileHint.textContent = 'Создайте профиль работ в системе';
      }
      showLaborResult(getFriendlyLaborErrorMessage('profiles', err), 'error');
      updateLaborSubmitState();
    }
  }

  function resetLaborFormAfterSuccess() {
    const keepProfileId = laborFormState.labor_profile_id;
    const keepHours = laborFormState.hours_per_month || '160';
    const keepSourceUrl = laborFormState.source_url;
    const keepProviderTitle = laborFormState.provider_title;
    const keepProviderDomain = laborFormState.provider_domain;
    laborFormState = {
      ...createInitialLaborFormState(),
      labor_profile_id: keepProfileId,
      hours_per_month: keepHours,
      source_url: keepSourceUrl,
      provider_title: keepProviderTitle,
      provider_domain: keepProviderDomain,
    };
    laborFieldMeta = createInitialLaborFieldMeta();
    laborSalaryPeriodTouched = false;
    laborCapturedMeta = {};
    clearLaborValidation();
    renderLaborFormState();
    renderLaborProfilesSelect();
  }

  async function captureCurrentTabScreenshot() {
    try {
      const dataUrl = await chrome.tabs.captureVisibleTab(null, { format: 'jpeg', quality: 80 });
      return await (await fetch(dataUrl)).blob();
    } catch (err) {
      console.warn('Screenshot capture failed:', err);
      return null;
    }
  }

  function collectLaborPayload() {
    hydrateLaborDefaults();
    laborFormState.currency = 'RUB';
    const payload = {};
    for (const field of LABOR_FIELDS) {
      const raw = laborFormState[field];
      payload[field] = typeof raw === 'string' ? raw.trim() : raw;
    }
    return payload;
  }

  function buildLaborSelectorsPayload() {
    const payload = {};

    Object.entries(laborCapturedMeta || {}).forEach(([field, meta]) => {
      if (!meta || (!meta.selector && !meta.xpath && !meta.value)) return;
      payload[field] = {
        value: meta.value || null,
        selector: meta.selector || null,
        xpath: meta.xpath || null,
      };
    });

    return Object.keys(payload).length ? payload : null;
  }

  function buildLaborFieldSourcesPayload() {
    const payload = {};

    Object.entries(laborFieldMeta.fields || {}).forEach(([field, meta]) => {
      if (!meta?.source) return;
      payload[field] = {
        source: meta.source,
        manual_locked: !!meta.manualLocked,
      };
    });

    return Object.keys(payload).length ? payload : null;
  }

  function validateLaborPayload(payload) {
    const fieldErrors = {};
    if (!payload.labor_profile_id) {
      fieldErrors.labor_profile_id = 'Выберите профиль работ';
    }
    if (!payload.source_url) {
      fieldErrors.source_url = 'Укажите ссылку на источник';
    }
    if (!payload.vacancy_title && !payload.salary_raw_text) {
      fieldErrors.vacancy_title = 'Укажите название вакансии или текст зарплаты';
      fieldErrors.salary_raw_text = 'Укажите текст зарплаты или название вакансии';
    }
    if (!payload.employer_name) {
      fieldErrors.employer_name = 'Укажите работодателя';
    }
    if (!payload.salary_value && !payload.salary_value_min && !payload.salary_value_max && !payload.salary_raw_text) {
      fieldErrors.salary_raw_text = 'Не удалось определить зарплату';
    }

    return {
      fieldErrors,
      globalMessage: Object.keys(fieldErrors).length > 0 ? 'Заполните обязательные поля вакансии' : '',
      hasHardErrors: !!(fieldErrors.labor_profile_id || fieldErrors.source_url),
    };
  }

  function updateModeSpecificStatus() {
    if (currentMode === 'labor') {
      setAutoTemplateBanner('');
      setSimpleStatus(
        'Вакансии: укажите профиль и ссылку, затем проверьте зарплату и работодателя.',
        'warning'
      );
      return;
    }

    evaluateSimpleFlowStatus();
  }

  function renderModeUI() {
    const isLaborMode = currentMode === 'labor';
    materialModePanel?.classList.toggle('hidden', isLaborMode);
    laborModePanel?.classList.toggle('hidden', !isLaborMode);
    btnModeMaterial?.classList.toggle('is-active', !isLaborMode);
    btnModeLabor?.classList.toggle('is-active', isLaborMode);
    if (modeBadge) {
      modeBadge.textContent = `Режим: ${getModeLabel(currentMode)}`;
    }
    if (isLaborMode) {
      renderLaborFormState();
      if (laborProfilesLoaded || laborProfiles.length > 0) {
        renderLaborProfilesSelect();
      } else {
        loadLaborProfilesShell();
        if (userInfo) {
          loadLaborProfiles().catch(() => {});
        }
      }
      requestJobPostingExtraction().catch(() => {});
    } else {
      clearLaborResult();
    }
    updateModeSpecificStatus();
  }

  function setCaptureMode(mode, options = {}) {
    const nextMode = mode === 'labor' ? 'labor' : 'material';
    currentMode = nextMode;
    renderModeUI();
    if (options.persist !== false) {
      persistCaptureMode(nextMode).catch(() => {});
    }
  }

  function setAdvancedMode(enabled) {
    isAdvancedMode = !!enabled;
    sectionMain.classList.toggle('advanced-mode', isAdvancedMode);
    if (btnAdvancedToggle) {
      btnAdvancedToggle.textContent = isAdvancedMode ? 'Скрыть расширенный режим' : 'Расширенный режим';
    }
    if (!isAdvancedMode) {
      switchTab('capture');
    }
  }

  function getSourceLabel(field) {
    const info = capturedFields[field];
    if (!info?.value) {
      return { text: 'Не удалось определить автоматически', tone: 'status-check' };
    }
    if (info.template) {
      return { text: 'Использован сохраненный шаблон', tone: 'status-template' };
    }
    if (info.manual) {
      return { text: 'Введено вручную', tone: 'status-manual' };
    }
    if (info.selector && !info.auto && !info.schema) {
      return { text: 'Выбрано вручную на странице', tone: 'status-manual' };
    }
    if (info.schema) {
      return { text: 'Найдено автоматически', tone: 'status-ok' };
    }
    if (info.auto) {
      return { text: 'Найдено автоматически', tone: 'status-ok' };
    }
    return { text: 'Нужно проверить', tone: 'status-check' };
  }

  async function maybeShowOnboarding() {
    const saved = await chrome.storage.local.get(ONBOARDING_KEY);
    if (saved?.[ONBOARDING_KEY]) {
      firstRunHelper?.classList.add('hidden');
      return;
    }
    firstRunHelper?.classList.remove('hidden');
  }

  async function dismissOnboarding() {
    firstRunHelper?.classList.add('hidden');
    await chrome.storage.local.set({ [ONBOARDING_KEY]: true });
  }

  function hasAnyCapturedValue() {
    return Object.values(capturedFields).some((item) => !!item?.value);
  }

  function hasCoreFields() {
    return !!capturedFields.title?.value && !!capturedFields.price?.value;
  }

  function scheduleAnalyze() {
    if (analyzeTimer) {
      clearTimeout(analyzeTimer);
    }

    analyzeTimer = setTimeout(() => {
      analyzeTimer = null;
      analyzeFieldsOnBackend().catch(() => {});
    }, ANALYZE_DEBOUNCE_MS);
  }

  // Unit is determined by the backend (MaterialTypeDetectionService.unitForType)

  // ============================================================
  // Initialization
  // ============================================================

  async function init() {
    // Clear badge and reset the session-scoped capture counter on every popup open.
    // Using CLEAR_BADGE ensures both the visual badge AND chrome.storage.session
    // counter stay in sync (MV3-safe — globalThis is not used for badge tracking).
    await sendToBackground('CLEAR_BADGE').catch(() => {
      // Service worker may still be starting up; fall back to direct badge clear.
      chrome.action.setBadgeText({ text: '' });
    });
    setAdvancedMode(false);
    currentMode = await restoreCaptureMode();
    renderModeUI();

    // Get current tab
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    currentTab = tab;

    // Check auth status — use cached user info to avoid delay
    const config = await sendToBackground('GET_CONFIG');
    if (config.hasToken) {
      // Try cached user info first for instant UI
      const cached = await chrome.storage.local.get('cachedUser');
      if (cached.cachedUser) {
        userInfo = cached.cachedUser;
        showMainUI(cached.cachedUser);
        loadPageInfo(); // don't await — start in parallel
        // Verify token in background (silently), update cache
        sendToBackground('GET_ME').then(me => {
          userInfo = me;
          chrome.storage.local.set({ cachedUser: me });
        }).catch(() => {
          // Token expired — clear cache and show auth
          chrome.storage.local.remove('cachedUser');
          showAuthUI();
        });
      } else {
        // No cache — must verify
        try {
          const me = await sendToBackground('GET_ME');
          userInfo = me;
          chrome.storage.local.set({ cachedUser: me });
          showMainUI(me);
          await loadPageInfo();
        } catch (err) {
          showAuthUI();
        }
      }
    } else {
      showAuthUI();
    }

    setupEventListeners();
  }

  function showAuthUI() {
    sectionAuth.classList.remove('hidden');
    sectionMain.classList.add('hidden');
    statusDot.className = 'status-dot offline';
    statusText.textContent = 'Требуется токен';
  }

  function showMainUI(me) {
    sectionAuth.classList.add('hidden');
    sectionMain.classList.remove('hidden');
    statusDot.className = 'status-dot online';
    statusText.textContent = me.user?.name || 'Подключено';
    setSimpleStatus('Откройте карточку товара. Мы попробуем заполнить поля автоматически.');
    maybeShowOnboarding().catch(() => {});
    renderModeUI();

    // Settings
    settingsUserName.textContent = me.user?.name || '—';
    settingsUserEmail.textContent = me.user?.email || '—';
    settingsRegion.textContent = me.region_id ? `ID: ${me.region_id}` : 'Не задан';

    if (currentMode === 'labor') {
      loadLaborProfiles().catch(() => {});
    }
  }

  function applyDetectedFields(fields, options = {}) {
    const markTemplate = !!options.markTemplate;
    const allowOverride = !!options.allowOverride;
    for (const [field, info] of Object.entries(fields || {})) {
      if (!info?.value) continue;
      if (!allowOverride && capturedFields[field]?.value) continue;
      capturedFields[field] = {
        ...capturedFields[field],
        ...info,
        value: info.value,
        template: markTemplate || !!info.template,
      };
      updateFieldUI(field, info.value);
    }
  }

  function evaluateSimpleFlowStatus() {
    if (currentMode === 'labor') {
      updateModeSpecificStatus();
      return;
    }

    if (hasCoreFields()) {
      if (autoFillWarnings.length > 0) {
        setSimpleStatus('Поля найдены, но есть неоднозначности. Проверьте перед добавлением.', 'warning');
      } else {
        setSimpleStatus('Основные поля найдены. Нажмите "Проверить", затем "Добавить материал".', 'success');
      }
      return;
    }

    const missing = [];
    if (!capturedFields.title?.value) missing.push('название');
    if (!capturedFields.price?.value) missing.push('цена');
    if (missing.length > 0) {
      setSimpleStatus(`Не удалось определить: ${missing.join(', ')}. Выберите поля вручную на странице.`, 'warning');
      return;
    }

    setSimpleStatus('Проверьте значения и при необходимости поправьте вручную.', 'warning');
  }

  async function tryAutomaticFill() {
    try {
      const result = await sendToContent('AUTO_DETECT_FIELDS');
      if (!result) return;

      if (result.fields) {
        applyDetectedFields(result.fields, { markTemplate: false });
      }

      autoFillWarnings = Array.isArray(result.warnings) ? result.warnings : [];
      // Material type and unit are determined by the backend via analyzeFieldsOnBackend()

      if (autoFillWarnings.length > 0) {
        showResult(captureResult, autoFillWarnings.join('\n'), 'error');
      }
    } catch {
      setSimpleStatus('Не удалось выполнить автозаполнение. Можно выбрать поля вручную.', 'warning');
    }
  }

  async function loadPageInfo() {
    pageDomain.innerHTML = '<span class="loading-dots">Загрузка</span>';
    pageUrl.textContent = '';
    setSimpleStatus('Проверяем страницу и автоматически ищем поля...');
    setAutoTemplateBanner('');
    templateAutoApplied = false;
    autoFillWarnings = [];
    if (pageLoadingStatus) pageLoadingStatus.classList.remove('hidden');

    // Detect restricted pages (chrome://, extension pages, Web Store) before scripting
    const currentUrl = currentTab?.url || '';
    if (isRestrictedPage(currentUrl)) {
      pageDomain.textContent = 'Страница не поддерживается';
      pageUrl.textContent = currentUrl || '—';
      if (pageLoadingStatus) pageLoadingStatus.classList.add('hidden');
      templateStatus.innerHTML = '<span class="no-template">Откройте карточку товара поставщика</span>';
      setSimpleStatus(getRestrictedPageMessage(currentUrl), 'error');
      return;
    }

    // Inject content script on demand — activeTab grant is active because user opened the popup
    pageDomain.innerHTML = '<span class="loading-dots">Подключаемся к странице</span>';
    setSimpleStatus('Подключаемся к странице…');
    try {
      await ensureContentScript();
    } catch (err) {
      pageDomain.textContent = 'Не удалось подключиться';
      pageUrl.textContent = currentUrl || '—';
      if (pageLoadingStatus) pageLoadingStatus.classList.add('hidden');
      const hint = err.code === 'RESTRICTED_PAGE'
        ? 'Откройте карточку товара поставщика'
        : 'Попробуйте обновить страницу';
      templateStatus.innerHTML = '<span class="no-template">' + hint + '</span>';
      setSimpleStatus(err.message || 'Не удалось подключиться к странице.', 'error');
      return;
    }

    try {
      pageInfo = await sendToContent('GET_PAGE_INFO');
      pageDomain.textContent = pageInfo.domain;
      pageUrl.textContent = truncate(pageInfo.url, 50);
      pageUrl.title = pageInfo.url;
      if (pageLoadingStatus) pageLoadingStatus.classList.add('hidden');

      let templateFound = false;
      try {
        const result = await sendToBackground('FIND_TEMPLATE', { url: pageInfo.url });
        if (result.has_template) {
          currentTemplate = result.template;
          templateFound = true;
          templateStatus.innerHTML = '<span class="has-template">Есть сохраненное правило сайта: ' + truncate(currentTemplate.name, 30) + '</span>';
          btnApplyTemplate.disabled = false;
          templateName.value = currentTemplate.name;
        } else {
          templateStatus.innerHTML = '<span class="no-template">Сохраненного правила для сайта нет</span>';
          btnApplyTemplate.disabled = true;
        }
      } catch {
        templateStatus.innerHTML = '<span class="no-template">Не удалось проверить сохраненные правила сайта</span>';
      }

      const captured = await sendToContent('GET_CAPTURED_DATA');
      if (captured?.capturedData) {
        capturedFields = captured.capturedData;
        for (const [field, info] of Object.entries(capturedFields)) {
          updateFieldUI(field, info.value);
        }
      } else {
        capturedFields = {};
      }

      if (captured?.schemaMapping) {
        lastSchemaMapping = captured.schemaMapping;
      }

      if (!hasAnyCapturedValue() && templateFound) {
        templateStatus.innerHTML = '<span class="has-template">Применяем сохраненное правило сайта...</span>';
        const applied = await handleApplyTemplate({ silent: true, markTemplateSource: true });
        templateAutoApplied = !!applied;
        if (templateAutoApplied) {
          setAutoTemplateBanner('Данные заполнены по сохраненному правилу сайта. Проверьте результат перед добавлением.');
          templateStatus.innerHTML = '<span class="has-template">Данные заполнены по сохраненному правилу сайта</span>';
        }
      }

      if (!hasCoreFields()) {
        await tryAutomaticFill();
      }

      scheduleAnalyze();

      updateActionButtons();
      evaluateSimpleFlowStatus();

      if (isAdvancedMode) {
        await loadSchemaDataForAdvanced();
      } else {
        schemaBanner.classList.add('hidden');
      }

      if (currentMode === 'labor') {
        requestJobPostingExtraction().catch(() => {});
      }

      await loadTemplatesList();
      renderModeUI();
    } catch (err) {
      console.error('Failed to read page data', err);
      pageDomain.textContent = 'Не удалось получить данные';
      pageUrl.textContent = currentTab?.url || '—';
      if (pageLoadingStatus) pageLoadingStatus.classList.add('hidden');
      setSimpleStatus('Не удалось прочитать страницу. Попробуйте обновить ее и открыть расширение снова.', 'error');
      if (currentMode === 'labor') {
        showLaborResult(getFriendlyLaborErrorMessage('page-data', err), 'error');
      }
    }
  }

  /** Re-bind schema DOM refs after innerHTML replacement */
  async function loadSchemaDataForAdvanced() {
    schemaBanner.classList.remove('hidden');
    schemaBanner.innerHTML = `
      <div class="schema-banner__header">
        <span class="schema-banner__icon">🔍</span>
        <span class="schema-banner__title schema-searching">Schema.org поиск<span class="dots-anim"></span></span>
      </div>`;

    try {
      schemaData = await sendToContent('DETECT_SCHEMA', {}, 5000);
      schemaBanner.innerHTML = `
        <div class="schema-banner__header">
          <span class="schema-banner__icon">🔍</span>
          <span class="schema-banner__title">Schema.org обнаружена</span>
          <button id="btn-schema-toggle" class="btn-schema-toggle">Показать</button>
        </div>
        <div id="schema-details" class="schema-details hidden">
          <div id="schema-selector" class="schema-selector hidden">
            <label class="schema-selector-label">Схема:</label>
            <select id="schema-select" class="schema-select"></select>
          </div>
          <div id="schema-fields-container" class="schema-fields-container"></div>
          <div class="schema-actions">
            <button id="btn-schema-apply" class="btn btn-primary btn-full">Заполнить выбранные поля</button>
          </div>
        </div>`;
      rebindSchemaRefs();

      if (schemaData?.found) {
        showSchemaBanner(schemaData);
        const savedMapping = currentTemplate?.extraction_rules?.schema_mapping;
        if (savedMapping?.mapping) {
          const schemaIdx = savedMapping.schemaIndex || 0;
          if (schemaRefs.select) schemaRefs.select.value = String(schemaIdx);
          renderSchemaFields(schemaIdx);
          for (const [captureField, schemaPath] of Object.entries(savedMapping.mapping)) {
            const sel = schemaRefs.container.querySelector(`.schema-map-select[data-path="${schemaPath}"]`);
            if (sel) sel.value = captureField;
          }
        }
      } else {
        schemaBanner.classList.add('hidden');
      }
    } catch {
      schemaBanner.classList.add('hidden');
    }
  }

  /** Re-bind schema DOM refs after innerHTML replacement */
  function rebindSchemaRefs() {
    const _schemaBanner = $('#schema-banner');
    const _schemaDetails = _schemaBanner?.querySelector('#schema-details') || $('#schema-details');
    const _btnSchemaToggle = _schemaBanner?.querySelector('#btn-schema-toggle') || $('#btn-schema-toggle');
    const _schemaFieldsContainer = _schemaBanner?.querySelector('#schema-fields-container') || $('#schema-fields-container');
    const _schemaSelector = _schemaBanner?.querySelector('#schema-selector') || $('#schema-selector');
    const _schemaSelect = _schemaBanner?.querySelector('#schema-select') || $('#schema-select');
    const _btnSchemaApply = _schemaBanner?.querySelector('#btn-schema-apply') || $('#btn-schema-apply');

    // Update closure references
    Object.assign(schemaRefs, {
      details: _schemaDetails,
      toggle: _btnSchemaToggle,
      container: _schemaFieldsContainer,
      selector: _schemaSelector,
      select: _schemaSelect,
      apply: _btnSchemaApply,
    });

    // Re-bind events
    if (_btnSchemaToggle) _btnSchemaToggle.addEventListener('click', toggleSchemaDetails);
    if (_btnSchemaApply) _btnSchemaApply.addEventListener('click', handleSchemaApply);
    if (_schemaSelect) _schemaSelect.addEventListener('change', () => renderSchemaFields(parseInt(_schemaSelect.value)));
  }

  // ============================================================
  // Event Listeners
  // ============================================================

  function setupEventListeners() {
    // Auth
    btnConnect.addEventListener('click', handleConnect);
    btnModeMaterial?.addEventListener('click', () => setCaptureMode('material'));
    btnModeLabor?.addEventListener('click', () => setCaptureMode('labor'));

    // Tabs
    $$('.tab').forEach(tab => {
      tab.addEventListener('click', () => switchTab(tab.dataset.tab));
    });

    btnAdvancedToggle?.addEventListener('click', () => {
      setAdvancedMode(!isAdvancedMode);
      if (!isAdvancedMode) {
        evaluateSimpleFlowStatus();
      } else {
        loadSchemaDataForAdvanced().catch(() => {});
      }
    });

    btnDismissOnboarding?.addEventListener('click', () => {
      dismissOnboarding().catch(() => {});
    });

    btnSuggestTemplate?.addEventListener('click', () => {
      setAdvancedMode(true);
      switchTab('template');
      templateName.value = `${pageInfo?.domain || 'site'} быстрый шаблон`;
      templateName.focus();
    });

    // Capture buttons
    $$('.btn-capture').forEach(btn => {
      btn.addEventListener('click', () => startFieldCapture(btn.dataset.field));
    });

    // URL scope radio
    $$('input[name="url-scope"]').forEach(radio => {
      radio.addEventListener('change', () => {
        urlPattern.classList.toggle('hidden', radio.value === 'domain');
      });
    });

    // Actions
    btnValidate.addEventListener('click', handleValidate);
    btnAddMaterial.addEventListener('click', handleAddMaterial);
    btnAddLabor?.addEventListener('click', handleAddLabor);
    btnLaborDetailsToggle?.addEventListener('click', () => {
      setLaborDetailsExpanded(!laborDetailsExpanded);
    });
    btnClear.addEventListener('click', handleClear);
    btnSaveTemplate.addEventListener('click', handleSaveTemplate);
    btnApplyTemplate.addEventListener('click', () => handleApplyTemplate());
    btnDisconnect.addEventListener('click', handleDisconnect);
    btnOpenPrismSite?.addEventListener('click', (e) => {
      e.preventDefault();
      chrome.tabs.create({ url: 'https://prismcore.ru' });
    });

    for (const field of LABOR_FIELDS) {
      const el = laborFieldRefs[field];
      if (!el) continue;
      el.addEventListener('input', () => {
        laborFormState[field] = el.value;
        markLaborFieldManual(field);
        clearLaborResult();
        if (field === 'source_url' || field === 'labor_profile_id') {
          clearLaborValidation([field]);
        }
        if (field === 'vacancy_title' || field === 'salary_raw_text') {
          clearLaborValidation(['vacancy_title', 'salary_raw_text']);
        }
        if (field === 'employer_name') {
          clearLaborValidation(['employer_name']);
        }
        renderLaborMeta();
        updateLaborSubmitState();
      });
      el.addEventListener('change', () => {
        laborFormState[field] = el.value;
        markLaborFieldManual(field);
        if (field === 'salary_period') {
          laborSalaryPeriodTouched = true;
          clearLaborValidation(['salary_period']);
        }
        if (field === 'source_url') {
          try {
            const hostname = el.value ? new URL(el.value).hostname.replace(/^www\./, '') : '';
            if (hostname) {
              if (!laborFormState.provider_domain) laborFormState.provider_domain = hostname;
              if (!laborFormState.provider_title) laborFormState.provider_title = hostname;
            }
          } catch { /* ignore invalid URL while typing */ }
          renderLaborFormState();
        }
        renderLaborMeta();
        updateLaborSubmitState();
      });
      if (field === 'salary_raw_text') {
        el.addEventListener('blur', () => {
          maybeAutoFillSalaryPeriod();
          renderLaborFormState();
        });
      }
    }

    // Schema.org — initial binding (will be re-bound after schema detection)
    schemaRefs.toggle?.addEventListener('click', toggleSchemaDetails);
    schemaRefs.apply?.addEventListener('click', handleSchemaApply);
    schemaRefs.select?.addEventListener('change', () => renderSchemaFields(parseInt(schemaRefs.select.value)));

    // Manual input toggles for dimension fields
    $$('.btn-manual-toggle').forEach(btn => {
      btn.addEventListener('click', () => {
        const field = btn.dataset.field;
        const row = $(`#manual-row-${field}`);
        if (row) {
          row.classList.toggle('hidden');
          if (!row.classList.contains('hidden')) {
            $(`#manual-input-${field}`)?.focus();
          }
        }
      });
    });

    // Manual input confirm buttons
    $$('.btn-manual-ok').forEach(btn => {
      btn.addEventListener('click', () => applyManualInput(btn.dataset.field));
    });

    // Manual input cancel buttons
    $$('.btn-manual-cancel').forEach(btn => {
      btn.addEventListener('click', () => {
        const field = btn.dataset.field;
        const row = $(`#manual-row-${field}`);
        const input = $(`#manual-input-${field}`);
        if (row) row.classList.add('hidden');
        if (input) input.value = '';
      });
    });

    // Manual input Enter key support
    $$('.manual-input').forEach(input => {
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          applyManualInput(input.dataset.field);
        } else if (e.key === 'Escape') {
          const row = $(`#manual-row-${input.dataset.field}`);
          if (row) row.classList.add('hidden');
          input.value = '';
        }
      });
    });

    // Listen for field captures from content script
    chrome.runtime.onMessage.addListener((message) => {
      if (message.action === 'JOB_POSTING_EXTRACTED' || message.type === 'JOB_POSTING_EXTRACTED') {
        if (currentMode === 'labor' && message.data) {
          mergeJobPostingDataIntoLaborForm(message.data);
        }
        return;
      }

      if (message.action === 'FIELD_CAPTURED') {
        const { field, value, selector, xpath } = message.data;
        if (!value) return;

        if (currentMode === 'labor') {
          applyLaborCapturedField(field, value, selector, xpath);
          return;
        }

        capturedFields[field] = { value, selector, xpath };
        lastSchemaMapping = null; // Manual capture overrides schema mapping
        updateFieldUI(field, value);

        // Request type detection and dimension parsing from backend
        if (field === 'title' && value) {
          scheduleAnalyze();
        }

        updateActionButtons();
        evaluateSimpleFlowStatus();
      }
    });
  }

  // ============================================================
  // Material type detection — delegated to backend
  // ============================================================

  /** Current detected material type: 'plate' | 'edge' | 'hardware' */
  let detectedMaterialType = 'hardware';

  const TYPE_LABELS = {
    plate: 'Плита',
    edge: 'Кромка',
    hardware: 'Фурнитура',
  };

  /**
   * Update UI visibility of dimension fields based on material type.
   *  - plate: show thickness, length, width (all required)
   *  - edge: show length (= edge width) and width (= edge thickness), hide thickness
   *  - hardware: hide all dimension fields
   */
  function updateDimensionFieldsVisibility(type) {
    const thicknessField = $('.capture-field[data-field="thickness"]');
    const lengthField = $('.capture-field[data-field="length"]');
    const widthField = $('.capture-field[data-field="width"]');

    // Edge field labels
    const lengthLabel = lengthField?.querySelector('.field-label');
    const widthLabel = widthField?.querySelector('.field-label');
    const thicknessLabel = thicknessField?.querySelector('.field-label');

    // Reset required markers
    const lengthReq = lengthField?.querySelector('.field-required');
    const widthReq = widthField?.querySelector('.field-required');
    const thicknessReq = thicknessField?.querySelector('.field-required');

    if (type === 'edge') {
      // Edge: hide thickness, show length (edge width) and width (edge thickness)
      if (thicknessField) thicknessField.style.display = 'none';
      if (lengthField) lengthField.style.display = '';
      if (widthField) widthField.style.display = '';
      // Update labels for edge context
      if (lengthLabel) lengthLabel.textContent = 'Ширина кромки (мм)';
      if (widthLabel) widthLabel.textContent = 'Толщина кромки (мм)';
      // Optional for edge
      if (lengthReq) lengthReq.style.display = 'none';
      if (widthReq) widthReq.style.display = 'none';
    } else if (type === 'hardware') {
      // Hardware: hide all dimensions
      if (thicknessField) thicknessField.style.display = 'none';
      if (lengthField) lengthField.style.display = 'none';
      if (widthField) widthField.style.display = 'none';
    } else {
      // Plate: show all dimensions with default labels
      if (thicknessField) thicknessField.style.display = '';
      if (lengthField) lengthField.style.display = '';
      if (widthField) widthField.style.display = '';
      if (thicknessLabel) thicknessLabel.textContent = 'Толщина (мм)';
      if (lengthLabel) lengthLabel.textContent = 'Длина (мм)';
      if (widthLabel) widthLabel.textContent = 'Ширина (мм)';
      // Required for plate
      if (thicknessReq) thicknessReq.style.display = '';
      if (lengthReq) lengthReq.style.display = '';
      if (widthReq) widthReq.style.display = '';
    }

    // Show type indicator
    updateTypeIndicator(type);
  }

  /**
   * Show/update a material type indicator badge near the title field.
   */
  function updateTypeIndicator(type) {
    let indicator = $('#material-type-indicator');
    if (!indicator) {
      // Create indicator element after the title field
      const titleField = $('.capture-field[data-field="title"]');
      if (titleField) {
        indicator = document.createElement('div');
        indicator.id = 'material-type-indicator';
        indicator.className = 'material-type-indicator';
        titleField.parentNode.insertBefore(indicator, titleField.nextSibling);
      }
    }
    if (indicator) {
      const colors = { plate: '#4F46E5', edge: '#059669', hardware: '#D97706' };
      const icons = { plate: '📋', edge: '📏', hardware: '🔩' };
      indicator.innerHTML = `<span style="color:${colors[type]}">${icons[type]} Тип: <strong>${TYPE_LABELS[type]}</strong> · Ед. изм.: <strong>${detectedMaterialUnit}</strong></span>`;
      indicator.style.display = '';
    }
  }

  /**
   * Request material type detection and dimension parsing from the backend.
   * Calls /api/chrome/validate to get type, unit, and auto-parsed dimensions.
   * Updates UI with type indicator, dimension fields, and unit.
   */
  async function analyzeFieldsOnBackend() {
    const extracted = {};
    const data_sources = {};
    for (const [field, info] of Object.entries(capturedFields)) {
      if (info?.value) {
        extracted[field] = info.value;
        data_sources[field] = getFieldSource(field) || 'manual';
      }
    }

    if (!extracted.title) {
      // No title — reset to default
      detectedMaterialType = 'hardware';
      detectedMaterialUnit = 'шт';
      updateDimensionFieldsVisibility('hardware');
      return;
    }

    try {
      const result = await sendToBackground('VALIDATE_FIELDS', {
        extracted,
        data_sources,
        url: pageInfo?.url || currentTab?.url || null,
      });

      if (result.preview) {
        const mType = result.preview.material_type || 'hardware';
        const mUnit = result.preview.unit || 'шт';

        detectedMaterialType = mType;
        detectedMaterialUnit = mUnit;
        updateDimensionFieldsVisibility(mType);

        // Fill auto-parsed dimensions from backend (only if not manually set or captured)
        const dimFields = {
          thickness: result.preview.thickness,
          length: result.preview.length,
          width: result.preview.width,
        };
        for (const [field, value] of Object.entries(dimFields)) {
          if (value && !capturedFields[field]?.manual && !capturedFields[field]?.selector) {
            capturedFields[field] = { value: String(value), auto: true, selector: null };
            updateFieldUI(field, String(value));
          }
        }

        // Clear auto-parsed dimension fields that are no longer relevant for the new type
        if (mType === 'hardware') {
          ['thickness', 'length', 'width'].forEach(f => {
            if (capturedFields[f]?.auto) {
              delete capturedFields[f];
              updateFieldUI(f, null);
            }
          });
        } else if (mType === 'edge') {
          if (capturedFields.thickness?.auto) {
            delete capturedFields.thickness;
            updateFieldUI('thickness', null);
          }
        }
      }
    } catch {
      // Silently fail — user can still manually fill fields and use local UI
    }

    evaluateSimpleFlowStatus();
  }

  /**
   * Apply a manually entered dimension value.
   * Marks the value as manual-sourced (lower trust than auto/capture).
   */
  function applyManualInput(field) {
    const input = $(`#manual-input-${field}`);
    const row = $(`#manual-row-${field}`);
    if (!input) return;

    const raw = input.value.trim().replace(',', '.');
    const num = parseFloat(raw);

    if (!raw || isNaN(num) || num <= 0) {
      input.style.borderColor = 'var(--danger)';
      setTimeout(() => { input.style.borderColor = ''; }, 1500);
      return;
    }

    capturedFields[field] = { value: String(num), selector: null, manual: true };
    updateFieldUI(field, String(num));
    if (row) row.classList.add('hidden');
    input.value = '';
    updateActionButtons();
    evaluateSimpleFlowStatus();
  }

  // ============================================================
  // Tab switching
  // ============================================================

  function switchTab(tabName) {
    if (!isAdvancedMode && tabName !== 'capture') {
      setAdvancedMode(true);
    }
    $$('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tabName));
    $$('.tab-content').forEach(tc => tc.classList.toggle('active', tc.id === `tab-${tabName}`));
  }

  // ============================================================
  // Auth
  // ============================================================

  async function handleConnect() {
    const token = inputToken.value.trim();

    if (!token) {
      authError.textContent = 'Введите API-токен';
      authError.classList.remove('hidden');
      return;
    }

    btnConnect.disabled = true;
    btnConnect.innerHTML = '<span class="spinner"></span> Подключение...';
    authError.classList.add('hidden');

    try {
      await sendToBackground('CONFIGURE', { baseUrl: null, token });
      const me = await sendToBackground('GET_ME');
      userInfo = me;
      chrome.storage.local.set({ cachedUser: me });
      showMainUI(me);
      await loadPageInfo();
    } catch (err) {
      authError.textContent = err.message;
      authError.classList.remove('hidden');
    } finally {
      btnConnect.disabled = false;
      btnConnect.textContent = 'Подключиться';
    }
  }

  async function handleDisconnect() {
    await sendToBackground('CONFIGURE', { baseUrl: null, token: '' });
    await chrome.storage.local.remove('cachedUser');
    userInfo = null;
    showAuthUI();
  }

  // ============================================================
  // Capture
  // ============================================================

  async function startFieldCapture(field) {
    // Ensure content script is available (inject if not yet present for this tab)
    try {
      await ensureContentScript();
    } catch (err) {
      showResult(captureResult, err.message || 'Не удалось подключиться к странице.', 'error');
      return;
    }

    // 3. Запускаем режим захвата
    try {
      const resp = await sendToContent('START_CAPTURE', { field });
      if (!resp?.started) throw new Error('Content script не ответил');

      // Показываем индикатор на иконке расширения
      chrome.action.setBadgeText({ text: '⊙' });
      chrome.action.setBadgeBackgroundColor({ color: '#4F46E5' });

      // Закрываем попап — пользователь должен взаимодействовать со страницей
      window.close();
    } catch (err) {
      showResult(captureResult, 'Ошибка запуска захвата: ' + err.message, 'error');
    }
  }

  function updateFieldUI(field, value) {
    const valEl = $(`#val-${field}`);
    const fieldEl = $(`.capture-field[data-field="${field}"]`);
    const badgeEl = $(`#badge-${field}`);
    const statusEl = $(`#status-${field}`);
    const manualRow = $(`#manual-row-${field}`);
    const manualToggle = $(`.btn-manual-toggle[data-field="${field}"]`);

    if (valEl && value) {
      valEl.textContent = truncate(value, 80);
      valEl.classList.add('has-value');
      fieldEl?.classList.add('captured');
    } else if (valEl) {
      valEl.textContent = 'Не выбрано';
      valEl.classList.remove('has-value');
      fieldEl?.classList.remove('captured');
    }

    const sourceMeta = getSourceLabel(field);
    if (statusEl) {
      statusEl.textContent = sourceMeta.text;
      statusEl.className = `field-status ${sourceMeta.tone}`;
    }

    // Source badge + lock logic for dimension fields
    const dimFields = ['thickness', 'length', 'width'];
    if (dimFields.includes(field) && badgeEl) {
      const info = capturedFields[field];
      if (info?.value) {
        const source = getFieldSource(field);
        badgeEl.classList.remove('hidden', 'badge-auto', 'badge-capture', 'badge-manual');
        fieldEl?.classList.remove('source-locked');

        if (source === 'auto') {
          badgeEl.textContent = info.template ? 'Шаблон' : 'Авто';
          badgeEl.classList.add('badge-auto');
          fieldEl?.classList.add('source-locked');
        } else if (source === 'capture') {
          badgeEl.textContent = 'Выбрано';
          badgeEl.classList.add('badge-capture');
          fieldEl?.classList.add('source-locked');
        } else if (source === 'schema') {
          badgeEl.textContent = 'Авто';
          badgeEl.classList.add('badge-auto');
          fieldEl?.classList.add('source-locked');
        } else if (source === 'manual') {
          badgeEl.textContent = 'Вручную';
          badgeEl.classList.add('badge-manual');
        }

        // Hide manual row when value is set
        if (manualRow) manualRow.classList.add('hidden');
      } else {
        badgeEl.classList.add('hidden');
        fieldEl?.classList.remove('source-locked');
        // Show manual toggle when no value
        if (manualToggle) manualToggle.style.display = '';
      }
    }

    updateActionButtons();
  }

  /**
   * Determine the source of a captured field value.
   * Returns: 'auto' | 'capture' | 'schema' | 'manual' | null
   */
  function getFieldSource(field) {
    const info = capturedFields[field];
    if (!info?.value) return null;
    if (info.manual) return 'manual';
    if (info.template) return 'auto';
    if (info.auto) return 'auto';
    if (info.schema) return 'schema';
    if (info.selector) return 'capture';
    return 'manual'; // fallback
  }

  function updateActionButtons() {
    if (currentMode === 'labor') {
      return;
    }

    const hasTitle = !!capturedFields.title?.value;
    const hasPrice = !!capturedFields.price?.value;
    const hasAny = hasAnyCapturedValue();

    btnValidate.disabled = !hasAny;
    btnAddMaterial.disabled = !(hasTitle && hasPrice);

    // Enable template save if at least one selector captured
    const hasSelectors = Object.values(capturedFields).some(f => f.selector);
    btnSaveTemplate.disabled = !(hasSelectors || !!lastSchemaMapping);
    updateTemplateSuggestionVisibility().catch(() => {});
  }

  async function handleClear() {
    try {
      await sendToContent('CLEAR_CAPTURED_DATA');
    } catch { /* ignore */ }

    // Очищаем бейдж
    try {
      await sendToBackground('CLEAR_BADGE');
    } catch { /* ignore */ }

    capturedFields = {};
    ['title', 'price', 'article', 'thickness', 'length', 'width'].forEach(f => updateFieldUI(f, null));
    // Hide manual input rows
    $$('.field-manual-row').forEach(row => row.classList.add('hidden'));
    $$('.manual-input').forEach(input => { input.value = ''; });
    validationPreview.classList.add('hidden');
    captureResult.classList.add('hidden');
    autoFillWarnings = [];
    setAutoTemplateBanner('');
    // Reset material type to default since all fields cleared
    detectedMaterialType = 'hardware';
    detectedMaterialUnit = 'шт';
    updateDimensionFieldsVisibility('hardware');
    updateActionButtons();
    evaluateSimpleFlowStatus();
  }

  async function handleAddLabor() {
    clearLaborResult();
    maybeAutoFillSalaryPeriod({ force: true });
    const payload = collectLaborPayload();
    const validation = validateLaborPayload(payload);
    laborValidationErrors = validation.fieldErrors;
    renderLaborValidation();
    updateLaborSubmitState();
    if (Object.keys(validation.fieldErrors).length > 0) {
      showLaborResult(validation.globalMessage, 'error');
      setSimpleStatus('Заполните обязательные поля вакансии перед отправкой.', 'error');
      return;
    }

    btnAddLabor.disabled = true;
    btnAddLabor.innerHTML = '<span class="spinner"></span> Снимок...';
    setSimpleStatus('Делаем снимок страницы для вакансии...', 'warning');

    const screenshotBlob = await captureCurrentTabScreenshot();
    if (!screenshotBlob) {
      btnAddLabor.disabled = false;
      btnAddLabor.textContent = 'Добавить вакансию';
      showLaborResult(getFriendlyLaborErrorMessage('screenshot'), 'error');
      setSimpleStatus('Не удалось сделать снимок страницы для вакансии.', 'error');
      updateLaborSubmitState();
      return;
    }

    btnAddLabor.innerHTML = '<span class="spinner"></span> Отправка...';
    setSimpleStatus('Отправляем вакансию в систему...', 'warning');

    try {
      const formData = new FormData();
      formData.append('source_url', payload.source_url);
      formData.append('labor_profile_id', payload.labor_profile_id);
      formData.append('capture_mode', 'labor');
      formData.append('screenshot_file', screenshotBlob, 'labor-screenshot.jpg');

      const optionalFields = [
        'provider_domain',
        'provider_title',
        'source_title',
        'source_date',
        'employer_name',
        'vacancy_title',
        'vacancy_description',
        'salary_raw_text',
        'salary_value',
        'salary_value_min',
        'salary_value_max',
        'salary_period',
        'hours_per_month',
        'derived_hourly_rate',
        'currency',
        'note',
      ];

      for (const field of optionalFields) {
        if (payload[field] !== '' && payload[field] != null) {
          formData.append(field, payload[field]);
        }
      }
      formData.set('currency', 'RUB');

      if (typeof laborFieldMeta.confidence === 'number' && Number.isFinite(laborFieldMeta.confidence)) {
        formData.append('confidence', String(laborFieldMeta.confidence));
      }

      const browserContext = {
        page_title: pageInfo?.title || currentTab?.title || '',
        page_domain: pageInfo?.domain || payload.provider_domain || '',
        captured_mode: 'labor',
      };
      formData.append('browser_context_json', JSON.stringify(browserContext));

      const selectorsPayload = buildLaborSelectorsPayload();
      if (selectorsPayload) {
        formData.append('selectors_json', JSON.stringify(selectorsPayload));
      }

      const fieldSourcesPayload = buildLaborFieldSourcesPayload();
      if (fieldSourcesPayload) {
        formData.append('field_sources_json', JSON.stringify(fieldSourcesPayload));
      }

      const response = await prizmApi.captureLabor(formData);
      const sourceId = response?.labor_evidence_source?.id;
      const parts = ['Вакансия добавлена'];
      if (sourceId) parts.push(`источник #${sourceId}`);

      showLaborResult(parts.join(' · '), 'success');
      setSimpleStatus('Вакансия сохранена. Можно переходить к следующей карточке.', 'success');
      resetLaborFormAfterSuccess();
    } catch (err) {
      showLaborResult(getFriendlyLaborErrorMessage('submit', err), 'error');
      setSimpleStatus('Ошибка отправки вакансии. Проверьте данные и повторите.', 'error');
    } finally {
      btnAddLabor.textContent = 'Добавить вакансию';
      updateLaborSubmitState();
    }
  }

  // ============================================================
  // Validation
  // ============================================================

  async function handleValidate() {
    const extracted = {};
    const data_sources = {};
    for (const [field, info] of Object.entries(capturedFields)) {
      extracted[field] = info.value;
      data_sources[field] = getFieldSource(field) || 'manual';
    }

    btnValidate.disabled = true;
    btnValidate.innerHTML = '<span class="spinner"></span>';

    try {
      const result = await sendToBackground('VALIDATE_FIELDS', {
        extracted,
        data_sources,
        url: pageInfo?.url || currentTab?.url || null,
      });

      validationPreview.classList.remove('hidden');
      validationPreview.classList.toggle('has-errors', !result.valid);

      let html = '';
      const warnings = [];

      if (result.preview) {
        const mType = result.preview.material_type || detectedMaterialType;
        const mTypeLabel = result.preview.material_type_label || TYPE_LABELS[mType] || mType;

        // Update local state from backend response (single source of truth)
        detectedMaterialType = mType;
        detectedMaterialUnit = result.preview.unit || detectedMaterialUnit;
        updateDimensionFieldsVisibility(mType);

        const rows = [
          { label: 'Название', value: result.preview.title },
          { label: 'Тип', value: mTypeLabel },
          { label: 'Цена', value: result.preview.price != null ? `${result.preview.price} ${result.preview.currency}` : '—' },
          { label: 'Артикул', value: result.preview.article || '—' },
          { label: 'Ед. изм.', value: result.preview.unit || '—' },
        ];

        // Add dimension rows based on type
        if (mType === 'plate') {
          rows.push(
            { label: 'Толщина', value: result.preview.thickness ? `${result.preview.thickness} мм` : '—' },
            { label: 'Длина', value: result.preview.length ? `${result.preview.length} мм` : '—' },
            { label: 'Ширина', value: result.preview.width ? `${result.preview.width} мм` : '—' },
          );
        } else if (mType === 'edge') {
          rows.push(
            { label: 'Ширина кромки', value: result.preview.length ? `${result.preview.length} мм` : '—' },
            { label: 'Толщина кромки', value: result.preview.width ? `${result.preview.width} мм` : '—' },
          );
        }
        // Hardware: no dimension rows

        rows.forEach(row => {
          html += `<div class="preview-row"><span class="label">${row.label}:</span><span class="value">${row.value}</span></div>`;
        });

        if (isAdvancedMode) {
          html += `<div class="preview-row"><span class="label">Техническая оценка:</span><span class="value">${result.trust?.trust_level || '—'}</span></div>`;
        }

        if (!result.preview.title) warnings.push('Не удалось определить название.');
        if (result.preview.price == null) warnings.push('Не удалось определить цену.');
        if (mType !== 'hardware' && !result.preview.length && !result.preview.width) {
          warnings.push('Не удалось определить размеры автоматически.');
        }
      }

      if (result.errors?.length) {
        result.errors.forEach(e => {
          warnings.push(e);
        });
      }

      if (warnings.length > 0) {
        html += '<div class="preview-row"><span class="label">Что требует проверки:</span><span class="value">Проверьте поля ниже</span></div>';
        warnings.forEach((warning) => {
          html += `<div class="preview-error">⚠ ${warning}</div>`;
        });
        setSimpleStatus('Проверка завершена: есть поля, требующие внимания.', 'warning');
      } else {
        setSimpleStatus('Проверка завершена: можно добавлять материал.', 'success');
      }

      previewContent.innerHTML = html;

    } catch (err) {
      showResult(captureResult, 'Не удалось выполнить проверку: ' + err.message, 'error');
      setSimpleStatus('Проверка не выполнена. Проверьте подключение и попробуйте еще раз.', 'error');
    } finally {
      btnValidate.disabled = false;
      btnValidate.textContent = 'Проверить';
    }
  }

  // ============================================================
  // Add Material (one-click: material upsert + screenshot + evidence + auto-link)
  // ============================================================

  async function handleAddMaterial() {
    const extracted = {};
    const data_sources = {};
    for (const [field, info] of Object.entries(capturedFields)) {
      extracted[field] = info.value;
      data_sources[field] = getFieldSource(field) || 'manual';
    }

    if (!pageInfo?.url) {
      showResult(captureResult, 'URL страницы не определён', 'error');
      return;
    }

    btnAddMaterial.disabled = true;
    btnAddMaterial.innerHTML = '<span class="spinner"></span> Подготовка...';

    // Phase 1: capture a screenshot of the visible page area for evidence.
    // Triggered by explicit user click on "Добавить материал".
    // Non-blocking: if capture fails the material is still submitted without it.
    let screenshotBlob = null;
    setSimpleStatus('Делаем снимок страницы для доказательной базы...');
    btnAddMaterial.innerHTML = '<span class="spinner"></span> Снимок...';
    screenshotBlob = await captureCurrentTabScreenshot();

    // Phase 2: build FormData and send
    btnAddMaterial.innerHTML = '<span class="spinner"></span> Сохранение...';

    try {
      const formData = new FormData();
      formData.append('url', pageInfo.url);

      // Append extracted fields as nested keys
      for (const [key, val] of Object.entries(extracted)) {
        if (val != null) formData.append(`extracted[${key}]`, val);
      }
      for (const [key, val] of Object.entries(data_sources)) {
        if (val != null) formData.append(`data_sources[${key}]`, val);
      }

      if (currentTemplate?.id) formData.append('template_id', currentTemplate.id);
      if (userInfo?.region_id) formData.append('region_id', userInfo.region_id);
      if (screenshotBlob) formData.append('screenshot_file', screenshotBlob, 'screenshot.jpg');

      const result = await prizmApi.extractWithEvidence(formData);

      if (result.success) {
        const resultHtml = buildOneClickResultHtml(result, !screenshotBlob);
        showRichResult(captureResult, resultHtml, 'success');
        setSimpleStatus('Материал сохранен. Можно переходить к следующей карточке.', 'success');
        await bumpSuccessCounterForDomain();
      } else {
        showResult(captureResult, result.message || 'Не удалось добавить материал.', 'error');
        setSimpleStatus('Не удалось добавить материал. Проверьте обязательные поля.', 'error');
      }
    } catch (err) {
      showResult(captureResult, 'Не удалось добавить материал: ' + err.message, 'error');
      setSimpleStatus('Ошибка добавления. Проверьте данные и повторите.', 'error');
    } finally {
      btnAddMaterial.disabled = false;
      btnAddMaterial.textContent = 'Добавить материал';
      updateActionButtons();
    }
  }

  /**
   * Build structured HTML for the one-click result, covering all response axes.
   */
  function buildOneClickResultHtml(result, screenshotMissing) {
    const parts = [];

    // Material axis
    if (result.material_status === 'created') {
      parts.push('<span class="result-detail result-material">✓ Материал добавлен</span>');
    } else if (result.material_status === 'updated') {
      parts.push('<span class="result-detail result-material">✓ Материал обновлён</span>');
    }

    // Evidence axis
    if (result.evidence_status === 'created') {
      parts.push('<span class="result-detail result-evidence">✓ Доказательство сохранено</span>');
    } else if (result.evidence_status === 'duplicate') {
      parts.push('<span class="result-detail result-evidence">⚠ Доказательство (дубликат)</span>');
    } else if (result.evidence_status === 'skipped_feature_disabled') {
      parts.push('<span class="result-detail result-evidence-off">— Доказательство не создано (отключено)</span>');
    } else if (result.evidence_status === 'skipped_unmapped_type') {
      parts.push('<span class="result-detail result-evidence-off">— Доказательство не создано (тип не распознан)</span>');
    }

    // Screenshot axis — only relevant when evidence was actually attempted
    const evidenceAttempted = result.evidence_status === 'created' || result.evidence_status === 'duplicate';
    if (evidenceAttempted) {
      if (result.screenshot_status === 'captured') {
        parts.push('<span class="result-detail result-screenshot">✓ Скриншот</span>');
      } else if (result.screenshot_status === 'failed' || screenshotMissing) {
        parts.push('<span class="result-detail result-screenshot-fail">✗ Скриншот не удался</span>');
      }
    }

    // Auto-link axis
    if (result.auto_link?.linked) {
      const label = result.auto_link.item_label || ('#' + result.auto_link.item_id);
      parts.push('<span class="result-detail result-autolink">⚡ Привязано к «' + escapeHtml(label) + '»</span>');
    }

    return parts.join('');
  }

  /**
   * Show rich HTML result in the result element (extended timeout for multi-line).
   */
  function showRichResult(el, html, type = 'success') {
    el.innerHTML = html;
    el.className = `result-message result-rich ${type}`;
    el.classList.remove('hidden');
    setTimeout(() => el.classList.add('hidden'), 8000);
  }

  // ============================================================
  // Templates
  // ============================================================

  async function bumpSuccessCounterForDomain() {
    const domain = pageInfo?.domain;
    if (!domain) return;

    const saved = await chrome.storage.local.get(SUCCESS_COUNTER_KEY);
    const counters = saved?.[SUCCESS_COUNTER_KEY] || {};
    counters[domain] = (counters[domain] || 0) + 1;
    await chrome.storage.local.set({ [SUCCESS_COUNTER_KEY]: counters });
    await updateTemplateSuggestionVisibility();
  }

  async function updateTemplateSuggestionVisibility() {
    if (!btnSuggestTemplate || !pageInfo?.domain) return;
    btnSuggestTemplate.classList.add('hidden');

    if (currentTemplate || domainTemplates.length > 0) {
      return;
    }

    const hasReusableRule = Object.values(capturedFields).some((item) => item?.selector) || !!lastSchemaMapping;
    if (!hasReusableRule) {
      return;
    }

    const saved = await chrome.storage.local.get(SUCCESS_COUNTER_KEY);
    const counters = saved?.[SUCCESS_COUNTER_KEY] || {};
    const successCount = counters[pageInfo.domain] || 0;

    if (successCount >= 3) {
      btnSuggestTemplate.classList.remove('hidden');
    }
  }

  function selectorFragility(selector) {
    if (!selector) return false;
    return /:nth-child|:nth-of-type|>\s*[^>]+>\s*[^>]+>/i.test(selector);
  }

  function templateSimilarityScore(selectorsA, selectorsB) {
    const keys = ['title', 'price', 'article', 'thickness', 'length', 'width'];
    let compared = 0;
    let same = 0;
    keys.forEach((key) => {
      if (!selectorsA[key] || !selectorsB[key]) return;
      compared++;
      if (selectorsA[key] === selectorsB[key]) same++;
    });
    if (compared === 0) return 0;
    return same / compared;
  }

  function evaluateTemplatePayloadQuality(payload) {
    const blocking = [];
    const warnings = [];
    const selectors = payload.selectors || {};

    if (!payload.schema_mapping && Object.keys(selectors).length < 2) {
      blocking.push('Слишком мало захваченных полей для надежного шаблона.');
    }

    if (!payload.schema_mapping && (!selectors.title || !selectors.price)) {
      blocking.push('Для шаблона нужны хотя бы поля "Название" и "Цена".');
    }

    if (!payload.test_case?.title || !payload.test_case?.price) {
      blocking.push('Перед сохранением заполните название и цену на текущей странице.');
    }

    const fragileFields = Object.entries(selectors)
      .filter(([, selector]) => selectorFragility(selector))
      .map(([field]) => field);
    if (fragileFields.length > 0) {
      warnings.push(`Некоторые селекторы выглядят хрупкими (${fragileFields.join(', ')}).`);
    }

    warnings.push('Шаблон протестирован только на текущей странице. Проверьте еще 1-2 карточки после сохранения.');

    const similar = domainTemplates.find((tpl) => templateSimilarityScore(selectors, tpl.selectors || {}) >= 0.7);
    if (similar) {
      warnings.push(`Похожий шаблон уже существует: "${similar.name}".`);
    }

    if (payload.is_default) {
      const existingDefault = domainTemplates.find((tpl) => tpl.is_default && tpl.id !== currentTemplate?.id);
      if (existingDefault) {
        warnings.push(`На домене уже есть шаблон по умолчанию: "${existingDefault.name}".`);
      }
    }

    return { blocking, warnings };
  }

  async function handleSaveTemplate() {
    const name = templateName.value.trim();
    if (!name) {
      showResult(templateSaveResult, 'Введите имя шаблона', 'error');
      return;
    }

    const selectors = {};
    for (const [field, info] of Object.entries(capturedFields)) {
      if (info.selector) {
        selectors[field] = info.selector;
      }
    }

    if (Object.keys(selectors).length === 0 && !lastSchemaMapping) {
      showResult(templateSaveResult, 'Нет захваченных селекторов', 'error');
      return;
    }

    const urlScope = document.querySelector('input[name="url-scope"]:checked')?.value;
    let urlPatterns = null;
    if (urlScope === 'pattern') {
      const pattern = urlPattern.value.trim();
      if (pattern) {
        urlPatterns = [{ path_contains: pattern }];
      }
    }

    // Build test case from current values
    const testCase = {};
    for (const [field, info] of Object.entries(capturedFields)) {
      testCase[field] = info.value;
    }

    btnSaveTemplate.disabled = true;
    btnSaveTemplate.innerHTML = '<span class="spinner"></span>';

    try {
      const payload = {
        domain: pageInfo?.domain || '',
        name,
        selectors,
        url_patterns: urlPatterns,
        test_case: testCase,
        is_default: templateDefault.checked,
      };

      // If fields were filled via Schema.org mapping, save it for auto-apply on revisit
      if (lastSchemaMapping) {
        payload.schema_mapping = lastSchemaMapping;
      }

      const quality = evaluateTemplatePayloadQuality(payload);
      if (quality.blocking.length > 0) {
        showResult(templateSaveResult, quality.blocking.join('\n'), 'error');
        return;
      }

      if (quality.warnings.length > 0) {
        const proceed = confirm(
          `Перед сохранением обратите внимание:\n\n- ${quality.warnings.join('\n- ')}\n\nСохранить шаблон все равно?`
        );
        if (!proceed) {
          showResult(templateSaveResult, 'Сохранение шаблона отменено. Скорректируйте поля и повторите.', 'error');
          return;
        }
      }

      const result = await sendToBackground('SAVE_TEMPLATE', payload);

      currentTemplate = result.template;
      showResult(templateSaveResult, result.message || 'Шаблон сохранён', 'success');
      templateStatus.innerHTML = '<span class="has-template">Сохраненное правило сайта обновлено</span>';
      btnApplyTemplate.disabled = false;
      btnSuggestTemplate?.classList.add('hidden');

      await loadTemplatesList();
    } catch (err) {
      showResult(templateSaveResult, 'Ошибка: ' + err.message, 'error');
    } finally {
      btnSaveTemplate.disabled = false;
      btnSaveTemplate.textContent = 'Сохранить шаблон';
    }
  }

  async function loadTemplatesList() {
    if (!pageInfo?.domain) {
      templatesList.innerHTML = '<p class="hint">Откройте страницу поставщика</p>';
      return;
    }

    try {
      const result = await sendToBackground('LIST_TEMPLATES', { domain: pageInfo.domain });
      const templates = result.templates || [];
      domainTemplates = templates;

      if (templates.length === 0) {
        templatesList.innerHTML = '<p class="hint">Нет шаблонов для ' + pageInfo.domain + '</p>';
        await updateTemplateSuggestionVisibility();
        return;
      }

      templatesList.innerHTML = templates.map(t => `
        <div class="template-item" data-id="${t.id}">
          <div class="template-item-info">
            <div class="template-item-name">${t.name} ${t.is_default ? '(по умол.)' : ''}</div>
            <div class="template-item-meta">v${t.version || 1} · ${t.user_id ? 'Мой' : 'Системный'}</div>
          </div>
          <div class="template-item-actions">
            <button class="btn btn-secondary btn-use-template" data-id="${t.id}">Применить</button>
            ${t.user_id ? `<button class="btn btn-link btn-delete-template" data-id="${t.id}">✕</button>` : ''}
          </div>
        </div>
      `).join('');

      // Bind events
      templatesList.querySelectorAll('.btn-use-template').forEach(btn => {
        btn.addEventListener('click', () => applyTemplateById(parseInt(btn.dataset.id)));
      });

      templatesList.querySelectorAll('.btn-delete-template').forEach(btn => {
        btn.addEventListener('click', () => deleteTemplate(parseInt(btn.dataset.id)));
      });

      await updateTemplateSuggestionVisibility();

    } catch (err) {
      templatesList.innerHTML = '<p class="hint">Ошибка загрузки: ' + err.message + '</p>';
    }
  }

  async function handleApplyTemplate(options = {}) {
    const silent = !!options.silent;
    const markTemplateSource = !!options.markTemplateSource;
    // Check if template has schema_mapping (stored in extraction_rules)
    const schemaMapping = currentTemplate?.extraction_rules?.schema_mapping;

    if (!schemaMapping && !currentTemplate?.selectors) {
      if (!silent) showResult(applyResult, 'У сохраненного правила нет данных для применения', 'error');
      return false;
    }

    btnApplyTemplate.disabled = true;
    btnApplyTemplate.innerHTML = '<span class="spinner"></span>';
    let applied = false;

    try {
      let result;

      if (schemaMapping) {
        // Schema-based template: apply schema mapping
        result = await sendToContent('APPLY_SCHEMA_MAPPING', {
          schemaIndex: schemaMapping.schemaIndex || 0,
          mapping: schemaMapping.mapping,
        });

        if (result.applied && result.fields) {
          for (const [field, info] of Object.entries(result.fields)) {
            capturedFields[field] = { ...info, template: markTemplateSource || !!info.template, schema: true, auto: true };
            updateFieldUI(field, info.value);
          }
          // Request type detection and dimension parsing from backend
          scheduleAnalyze();
          applied = true;
          if (!silent) {
            showResult(applyResult, `Правило применено: заполнено полей ${result.fieldCount}`, 'success');
          }
        } else {
          // Schema not found on page — try CSS selectors as fallback
          if (currentTemplate?.selectors && Object.keys(currentTemplate.selectors).length > 0) {
            result = await sendToContent('APPLY_TEMPLATE', {
              selectors: currentTemplate.selectors,
            });
            applied = await applyTemplateResult(result, true, { silent, markTemplateSource });
          } else {
            if (!silent) {
              showResult(applyResult, result.error || 'Сохраненное правило сайта больше не подходит к этой странице', 'error');
            }
          }
        }
      } else {
        // CSS selector-based template
        result = await sendToContent('APPLY_TEMPLATE', {
          selectors: currentTemplate.selectors,
        });
        applied = await applyTemplateResult(result, false, { silent, markTemplateSource });
      }
    } catch (err) {
      if (!silent) {
        showResult(applyResult, 'Не удалось применить сохраненное правило: ' + err.message, 'error');
      }
    } finally {
      btnApplyTemplate.disabled = false;
      btnApplyTemplate.textContent = 'Применить шаблон';
    }

    return applied;
  }

  async function applyTemplateResult(result, isSchemaFallback, options = {}) {
    const silent = !!options.silent;
    const markTemplateSource = !!options.markTemplateSource;
    let applied = false;

    if (result.errors?.length > 0) {
      if (!silent) {
        const prefix = isSchemaFallback ? 'Schema.org данные не найдены, пробуем селекторы.\n' : '';
        showResult(applyResult,
          prefix + 'Некоторые поля не удалось заполнить по сохраненному правилу:\n' + result.errors.join('\n'),
          'error'
        );
        if (result.errors.some(e => e.includes('не наш'))) {
          applyResult.innerHTML += '<br><small>Сайт мог измениться. Создайте обновленное правило в расширенном режиме.</small>';
        }
      }
      setSimpleStatus('Сохраненное правило сайта сработало частично. Проверьте поля вручную.', 'warning');
    } else if (!silent) {
      const msg = isSchemaFallback ? '✓ Шаблон применён (CSS-селекторы, Schema не найдена)' : '✓ Шаблон применён';
      showResult(applyResult, msg, 'success');
    }

    if (result.fields) {
      for (const [field, info] of Object.entries(result.fields)) {
        if (info.found && info.value) {
          capturedFields[field] = {
            value: info.value,
            selector: info.selector,
            template: markTemplateSource || true,
            auto: true,
          };
          updateFieldUI(field, info.value);
          applied = true;
        }
      }
      // Request type detection and dimension parsing from backend
      scheduleAnalyze();
    }

    updateActionButtons();
    evaluateSimpleFlowStatus();
    return applied;
  }

  async function applyTemplateById(id) {
    try {
      const listResult = await sendToBackground('LIST_TEMPLATES', { domain: pageInfo.domain });
      const template = (listResult.templates || []).find(t => t.id === id);
      if (template) {
        currentTemplate = template;
        await handleApplyTemplate({ markTemplateSource: true });
      }
    } catch (err) {
      showResult(applyResult, 'Ошибка: ' + err.message, 'error');
    }
  }

  async function deleteTemplate(id) {
    if (!confirm('Удалить шаблон?')) return;

    try {
      await sendToBackground('DELETE_TEMPLATE', { id });
      await loadTemplatesList();
    } catch (err) {
      showResult(templateSaveResult, 'Ошибка: ' + err.message, 'error');
    }
  }

  // ============================================================
  // Schema.org — interactive field mapping
  // ============================================================

  const CAPTURE_FIELD_OPTIONS = [
    { value: '', label: '— пропустить —' },
    { value: 'title', label: '📝 Название' },
    { value: 'price', label: '💰 Цена' },
    { value: 'article', label: '🏷 Артикул' },
    { value: 'thickness', label: '📏 Толщина' },
    { value: 'length', label: '📐 Длина' },
    { value: 'width', label: '📐 Ширина' },
  ];

  // Human-readable labels for schema paths
  const PATH_LABELS = {
    '@type': 'Тип',
    'name': 'Название',
    'description': 'Описание',
    'sku': 'SKU / Артикул',
    'image': 'Изображение',
    'url': 'URL',
    'category': 'Категория',
    'brand.name': 'Бренд',
    'brand.@type': 'Тип (бренд)',
    'offers.@type': 'Тип (предложение)',
    'offers.price': 'Цена',
    'offers.priceCurrency': 'Валюта',
    'offers.availability': 'Наличие',
    'offers.url': 'URL (предложение)',
    'offers.seller.name': 'Продавец',
    'width': 'Ширина',
    'height': 'Высота',
    'depth': 'Глубина',
    'weight': 'Вес',
  };

  function getFieldLabel(path) {
    if (PATH_LABELS[path]) return PATH_LABELS[path];
    // additionalProperty[0].name → Свойство [1]: Название
    const apMatch = path.match(/additionalProperty\[(\d+)\]\.(\w+)/);
    if (apMatch) {
      const idx = parseInt(apMatch[1]) + 1;
      const sub = apMatch[2];
      if (sub === 'name') return `Свойство [${idx}]: Имя`;
      if (sub === 'value') return `Свойство [${idx}]: Значение`;
      if (sub === '@type') return `Свойство [${idx}]: Тип`;
      return `Свойство [${idx}]: ${sub}`;
    }
    return path;
  }

  /** Guess which capture field a schema path most likely maps to */
  function suggestMapping(path, value) {
    const p = path.toLowerCase();
    const v = (value || '').toLowerCase();

    if (p === 'name') return 'title';
    if (p === 'offers.price' || p === 'offers.lowprice') return 'price';
    if (p === 'sku') return 'article';
    if (p.includes('width') || p.includes('depth')) return 'width';
    if (p.includes('height')) return 'length';
    return '';
  }

  function showSchemaBanner(data) {
    schemaBanner.classList.remove('hidden');

    const count = data.schemas.length;
    if (schemaRefs.toggle) schemaRefs.toggle.textContent = 'Показать';

    // If multiple schemas — show selector
    if (count > 1) {
      if (schemaRefs.selector) schemaRefs.selector.classList.remove('hidden');
      if (schemaRefs.select) {
        schemaRefs.select.innerHTML = data.schemas.map((s, i) => {
          const src = s.source.toUpperCase();
          if (s.merged) {
            return `<option value="${i}">★ ${src} объединённая (${s.mergedCount} схем, ${s.fields.length} полей)</option>`;
          }
          return `<option value="${i}">${src} #${i + 1} (${s.fields.length} полей)</option>`;
        }).join('');
      }
    }

    renderSchemaFields(0);
  }

  function renderSchemaFields(schemaIndex) {
    if (!schemaData?.schemas?.[schemaIndex]) return;

    const schema = schemaData.schemas[schemaIndex];
    const fields = schema.fields || [];
    const source = schema.source === 'json-ld' ? 'JSON-LD' : 'MICRODATA';

    if (fields.length === 0) {
      if (schemaRefs.container) schemaRefs.container.innerHTML = '<p style="padding:8px;color:var(--text-muted);font-size:12px">Поля Schema.org не обнаружены</p>';
      return;
    }

    // Filter out @type, @context, images, and overly long values
    const displayFields = fields.filter(f => {
      if (f.path === '@context') return false;
      if (f.path.endsWith('.@type') && f.path.split('.').length > 2) return false;
      return true;
    });

    let html = `<div class="schema-badge">${source}</div>`;
    html += `<div class="schema-field-count">${displayFields.length} полей обнаружено</div>`;
    html += '<table class="schema-map-table">';
    html += '<thead><tr><th>Поле схемы</th><th>Значение</th><th>→ Захват</th></tr></thead>';
    html += '<tbody>';

    displayFields.forEach((f, i) => {
      const label = getFieldLabel(f.path);
      const valuePreview = truncate(f.value, 45);
      const isUrl = f.value.startsWith('http://') || f.value.startsWith('https://');
      const isLongText = f.value.length > 100;
      const suggested = suggestMapping(f.path, f.value);

      // Skip image URLs and very long descriptions from mapping options
      const dimmed = isUrl || isLongText || f.path === '@type';

      html += `<tr class="schema-row ${dimmed ? 'schema-row--dim' : ''}" data-idx="${i}">`;
      html += `  <td class="schema-map-path" title="${f.path}">${label}</td>`;
      html += `  <td class="schema-map-value" title="${f.value.replace(/"/g, '&quot;')}">${valuePreview}</td>`;
      html += `  <td class="schema-map-action">`;

      if (!dimmed) {
        html += `<select class="schema-map-select" data-path="${f.path}">`;
        CAPTURE_FIELD_OPTIONS.forEach(opt => {
          const sel = opt.value === suggested ? ' selected' : '';
          html += `<option value="${opt.value}"${sel}>${opt.label}</option>`;
        });
        html += `</select>`;
      }

      html += `  </td>`;
      html += `</tr>`;
    });

    html += '</tbody></table>';

    if (schemaRefs.container) schemaRefs.container.innerHTML = html;
  }

  function toggleSchemaDetails() {
    if (!schemaRefs.details) return;
    const isHidden = schemaRefs.details.classList.toggle('hidden');
    if (schemaRefs.toggle) schemaRefs.toggle.textContent = isHidden ? 'Показать' : 'Скрыть';
  }

  function getSchemaMapping() {
    const mapping = {};
    if (!schemaRefs.container) return mapping;
    schemaRefs.container.querySelectorAll('.schema-map-select').forEach(sel => {
      const captureField = sel.value;
      const schemaPath = sel.dataset.path;
      if (captureField && schemaPath) {
        mapping[captureField] = schemaPath;
      }
    });
    return mapping;
  }

  async function handleSchemaApply() {
    const mapping = getSchemaMapping();
    if (Object.keys(mapping).length === 0) {
      showResult(captureResult, 'Выберите хотя бы одно поле для заполнения', 'error');
      return;
    }

    const schemaIndex = schemaRefs.select ? parseInt(schemaRefs.select.value || '0') : 0;

    if (schemaRefs.apply) schemaRefs.apply.disabled = true;
    if (schemaRefs.apply) schemaRefs.apply.textContent = 'Заполнение...';

    try {
      const result = await sendToContent('APPLY_SCHEMA_MAPPING', {
        schemaIndex,
        mapping,
      });

      if (result.applied && result.fields) {
        // Remember schema mapping for template saving
        lastSchemaMapping = { schemaIndex, mapping };

        for (const [field, info] of Object.entries(result.fields)) {
          capturedFields[field] = { ...info, schema: true };
          updateFieldUI(field, info.value);
        }
        // Request type detection and dimension parsing from backend
        scheduleAnalyze();
        updateActionButtons();
        showResult(captureResult, `✓ Заполнено из Schema.org: ${result.fieldCount} полей`, 'success');
        setSimpleStatus('Часть полей заполнена автоматически из Schema.org. Проверьте результат.', 'warning');
      } else {
        showResult(captureResult, result.error || 'Не удалось извлечь данные', 'error');
        setSimpleStatus('Schema.org на этой странице не помогла. Можно выбрать поля вручную.', 'warning');
      }
    } catch (err) {
      showResult(captureResult, 'Ошибка: ' + err.message, 'error');
      setSimpleStatus('Не удалось применить Schema.org сопоставление.', 'error');
    } finally {
      if (schemaRefs.apply) schemaRefs.apply.disabled = false;
      if (schemaRefs.apply) schemaRefs.apply.textContent = 'Заполнить выбранные поля';
    }
  }

  // ============================================================
  // Start
  // ============================================================
  init().catch(console.error);

})();
