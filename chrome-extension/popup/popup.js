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
  let userInfo = null;

  // ============================================================
  // DOM refs
  // ============================================================
  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => document.querySelectorAll(sel);

  // Sections
  const sectionAuth = $('#section-auth');
  const sectionMain = $('#section-main');

  // Auth
  const inputApiUrl = $('#input-api-url');
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
  const settingsServerUrl = $('#settings-server-url');
  const btnDisconnect = $('#btn-disconnect');
  const btnOpenPrismSite = $('#btn-open-prism-site');
  const DEFAULT_API_URL = 'https://prismcore.ru/api';

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

  function truncate(str, len = 60) {
    if (!str) return '';
    return str.length > len ? str.substring(0, len) + '…' : str;
  }

  // ============================================================
  // Initialization
  // ============================================================

  async function init() {
    // Очищаем бейдж при открытии попапа
    chrome.action.setBadgeText({ text: '' });

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
      // Pre-fill API URL from config
      inputApiUrl.value = config.baseUrl || DEFAULT_API_URL;
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

    // Settings
    settingsUserName.textContent = me.user?.name || '—';
    settingsUserEmail.textContent = me.user?.email || '—';
    settingsRegion.textContent = me.region_id ? `ID: ${me.region_id}` : 'Не задан';
  }

  async function loadPageInfo() {
    // Show loading state immediately
    pageDomain.innerHTML = '<span class="loading-dots">Загрузка</span>';
    pageUrl.textContent = '';
    if (pageLoadingStatus) pageLoadingStatus.classList.remove('hidden');

    // Wait for content script to be ready (page may still be loading)
    let contentReady = false;
    let retries = 0;
    while (!contentReady && retries < 5) {
      try {
        const pong = await sendToContent('PING', {}, 1500);
        contentReady = !!pong?.pong;
      } catch {
        retries++;
        if (retries < 5) {
          pageDomain.innerHTML = `<span class="loading-dots">Страница загружается</span>`;
          await new Promise(r => setTimeout(r, 800));
        }
      }
    }

    if (!contentReady) {
      pageDomain.textContent = 'Страница не отвечает';
      pageUrl.textContent = currentTab?.url || '—';
      if (pageLoadingStatus) pageLoadingStatus.classList.add('hidden');
      templateStatus.innerHTML = '<span class="no-template">Перезагрузите страницу</span>';
      return;
    }

    try {
      pageInfo = await sendToContent('GET_PAGE_INFO');
      pageDomain.textContent = pageInfo.domain;
      pageUrl.textContent = truncate(pageInfo.url, 50);
      pageUrl.title = pageInfo.url;
      if (pageLoadingStatus) pageLoadingStatus.classList.add('hidden');

      // Check for existing template
      let templateFound = false;
      try {
        const result = await sendToBackground('FIND_TEMPLATE', { url: pageInfo.url });
        if (result.has_template) {
          currentTemplate = result.template;
          templateFound = true;
          templateStatus.innerHTML = '<span class="has-template">✓ Шаблон найден: ' +
            truncate(currentTemplate.name, 30) + '</span>';
          btnApplyTemplate.disabled = false;
          templateName.value = currentTemplate.name;
        } else {
          templateStatus.innerHTML = '<span class="no-template">Нет шаблона для этого сайта</span>';
          btnApplyTemplate.disabled = true;
        }
      } catch {
        templateStatus.innerHTML = '<span class="no-template">Нет шаблона</span>';
      }

      // Load existing captured data + restore schema mapping
      const captured = await sendToContent('GET_CAPTURED_DATA');
      if (captured?.capturedData) {
        capturedFields = captured.capturedData;
        for (const [field, info] of Object.entries(capturedFields)) {
          updateFieldUI(field, info.value);
        }
        // Auto-parse dimensions from restored title (also detects material type)
        if (capturedFields.title?.value) {
          autoParseDimensions(capturedFields.title.value);
        } else {
          // No title yet — detect type from URL only
          refreshMaterialType();
        }
        updateActionButtons();
      } else {
        // No captured data — detect type from URL
        refreshMaterialType();
      }
      // Restore schema mapping if was previously set
      if (captured?.schemaMapping) {
        lastSchemaMapping = captured.schemaMapping;
      }

      // Detect Schema.org data (with loading indicator)
      schemaBanner.classList.remove('hidden');
      schemaBanner.innerHTML = `
        <div class="schema-banner__header">
          <span class="schema-banner__icon">🔍</span>
          <span class="schema-banner__title schema-searching">Schema.org поиск<span class="dots-anim"></span></span>
        </div>`;

      try {
        schemaData = await sendToContent('DETECT_SCHEMA', {}, 5000);
        // Restore the banner HTML after detection
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
        // Re-bind DOM refs that were replaced
        rebindSchemaRefs();

        if (schemaData?.found) {
          showSchemaBanner(schemaData);

          // If template has schema_mapping, pre-select dropdowns
          const savedMapping = currentTemplate?.extraction_rules?.schema_mapping;
          if (savedMapping?.mapping) {
            const schemaIdx = savedMapping.schemaIndex || 0;
            if (schemaRefs.select) schemaRefs.select.value = String(schemaIdx);
            renderSchemaFields(schemaIdx);
            // Pre-select dropdown values from saved mapping
            for (const [captureField, schemaPath] of Object.entries(savedMapping.mapping)) {
              const sel = schemaRefs.container.querySelector(`.schema-map-select[data-path="${schemaPath}"]`);
              if (sel) sel.value = captureField;
            }
            templateStatus.innerHTML = '<span class="has-template">✓ Schema.org шаблон: ' +
              truncate(currentTemplate.name, 25) + '</span>';
          }
        } else {
          // No schema found
          schemaBanner.classList.add('hidden');
        }
      } catch {
        // Schema detection failed or timed out
        schemaBanner.classList.add('hidden');
      }

      // Auto-apply template if fields are empty and template exists
      const hasData = Object.keys(capturedFields).some(f => capturedFields[f]?.value);
      if (templateFound && !hasData) {
        try {
          templateStatus.innerHTML = '<span class="has-template">⏳ Автоприменение шаблона…</span>';
          await handleApplyTemplate();
          templateStatus.innerHTML = '<span class="has-template">✓ Шаблон применён: ' +
            truncate(currentTemplate.name, 25) + '</span>';
        } catch { /* auto-apply is best-effort */ }
      }

      // Load templates list
      await loadTemplatesList();

      // Update settings
      const config = await sendToBackground('GET_CONFIG');
      settingsServerUrl.textContent = config.baseUrl || '—';

    } catch (err) {
      pageDomain.textContent = 'Не удалось получить данные';
      pageUrl.textContent = currentTab?.url || '—';
      if (pageLoadingStatus) pageLoadingStatus.classList.add('hidden');
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

    // Tabs
    $$('.tab').forEach(tab => {
      tab.addEventListener('click', () => switchTab(tab.dataset.tab));
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
    btnClear.addEventListener('click', handleClear);
    btnSaveTemplate.addEventListener('click', handleSaveTemplate);
    btnApplyTemplate.addEventListener('click', handleApplyTemplate);
    btnDisconnect.addEventListener('click', handleDisconnect);
    btnOpenPrismSite?.addEventListener('click', (e) => {
      e.preventDefault();
      chrome.tabs.create({ url: 'https://prismcore.ru' });
    });

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
      if (message.action === 'FIELD_CAPTURED') {
        const { field, value, selector, xpath } = message.data;
        capturedFields[field] = { value, selector, xpath };
        lastSchemaMapping = null; // Manual capture overrides schema mapping
        updateFieldUI(field, value);

        // Auto-parse dimensions from title
        if (field === 'title' && value) {
          autoParseDimensions(value);
        }

        updateActionButtons();
      }
    });
  }

  // ============================================================
  // Material type detection
  // ============================================================

  const SHEET_MATERIAL_PATTERNS = /\b(ЛДСП|МДФ|ХДФ|ОСБ|ЛМДФ|OSB|ДВПО|ДСП|ДВП|ЛХДФ|ЛОСБ|HPL|CPL|ФСФ|ФК)\b/i;

  /** Current detected material type: 'plate' | 'edge' | 'hardware' */
  let detectedMaterialType = 'hardware';

  const TYPE_LABELS = {
    plate: 'Плита',
    edge: 'Кромка',
    hardware: 'Фурнитура',
  };

  /**
   * Detect material type from name and current page URL.
   * @returns {'plate'|'edge'|'hardware'}
   */
  function detectMaterialType(name) {
    const url = pageInfo?.url || currentTab?.url || '';
    if (name && /кромка/i.test(name)) return 'edge';
    if (url && /kromka/i.test(url)) return 'edge';
    if (name && SHEET_MATERIAL_PATTERNS.test(name)) return 'plate';
    return 'hardware';
  }

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
      indicator.innerHTML = `<span style="color:${colors[type]}">${icons[type]} Тип: <strong>${TYPE_LABELS[type]}</strong></span>`;
      indicator.style.display = '';
    }
  }

  /**
   * Re-detect material type and update UI accordingly.
   * Called after title changes or on initial load.
   */
  function refreshMaterialType() {
    const name = capturedFields.title?.value || '';
    const newType = detectMaterialType(name);
    if (newType !== detectedMaterialType) {
      detectedMaterialType = newType;
      updateDimensionFieldsVisibility(newType);
      // Clear dimension fields that are no longer relevant
      if (newType === 'hardware') {
        ['thickness', 'length', 'width'].forEach(f => {
          if (capturedFields[f]?.auto) {
            delete capturedFields[f];
            updateFieldUI(f, null);
          }
        });
      } else if (newType === 'edge') {
        if (capturedFields.thickness?.auto) {
          delete capturedFields.thickness;
          updateFieldUI('thickness', null);
        }
      }
    } else {
      // Still refresh labels in case we didn't before
      updateDimensionFieldsVisibility(newType);
    }
  }

  /**
   * Auto-parse dimensions (length, width, thickness) from product name.
   * Parsing strategy depends on detected material type.
   * Only fills fields that are not already manually captured.
   */
  function autoParseDimensions(name) {
    if (!name) return;

    // Re-detect type first
    const type = detectMaterialType(name);
    detectedMaterialType = type;
    updateDimensionFieldsVisibility(type);

    const dims = {};

    if (type === 'edge') {
      // Edge: parse WxT (edge_width x edge_thickness)
      const m = name.match(/(\d{1,3})\s*[xхXХ×*]\s*(\d{1,2}(?:[.,]\d+)?)/);
      if (m) {
        const w = parseInt(m[1]);
        const t = parseFloat(m[2].replace(',', '.'));
        if (w >= 10 && w <= 100 && t > 0 && t <= 10) {
          dims.length = String(w);   // edge width → length (DB convention)
          dims.width = String(t);    // edge thickness → width (DB convention)
        }
      }
    } else if (type === 'plate') {
      // Plate: standard LxWxT parsing
      const tripleMatch = name.match(/(\d{3,5})\s*[xхXХ×*]\s*(\d{3,5})\s*[xхXХ×*]\s*(\d{1,3}(?:[.,]\d+)?)/);
      if (tripleMatch) {
        dims.length = tripleMatch[1];
        dims.width = tripleMatch[2];
        dims.thickness = tripleMatch[3].replace(',', '.');
      } else {
        const sizeMatch = name.match(/(\d{3,5})\s*[xхXХ×*]\s*(\d{3,5})/);
        if (sizeMatch) {
          dims.length = sizeMatch[1];
          dims.width = sizeMatch[2];
        }
        if (!dims.thickness) {
          const thickMatch = name.match(/(?:^|\s|[,;])(\d{1,3}(?:[.,]\d+)?)\s*мм\b/i);
          if (thickMatch) {
            const t = parseFloat(thickMatch[1].replace(',', '.'));
            if (t >= 2 && t <= 50) {
              dims.thickness = String(t);
            }
          }
        }
      }
    }
    // Hardware: no dimensions to parse

    // Fill dimension fields only if not already manually set
    for (const [field, value] of Object.entries(dims)) {
      if (!capturedFields[field]?.value) {
        capturedFields[field] = { value: String(value), selector: null, auto: true };
        updateFieldUI(field, String(value));
      }
    }
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
  }

  // ============================================================
  // Tab switching
  // ============================================================

  function switchTab(tabName) {
    $$('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tabName));
    $$('.tab-content').forEach(tc => tc.classList.toggle('active', tc.id === `tab-${tabName}`));
  }

  // ============================================================
  // Auth
  // ============================================================

  async function handleConnect() {
    const baseUrl = (inputApiUrl.value.trim() || DEFAULT_API_URL).replace(/\/+$/, '');
    const token = inputToken.value.trim();

    if (!baseUrl || !token) {
      authError.textContent = 'Заполните адрес сервера и токен';
      authError.classList.remove('hidden');
      return;
    }

    btnConnect.disabled = true;
    btnConnect.innerHTML = '<span class="spinner"></span> Подключение...';
    authError.classList.add('hidden');

    try {
      await sendToBackground('CONFIGURE', { baseUrl, token });
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
    await sendToBackground('CONFIGURE', { baseUrl: DEFAULT_API_URL, token: '' });
    await chrome.storage.local.remove('cachedUser');
    userInfo = null;
    showAuthUI();
  }

  // ============================================================
  // Capture
  // ============================================================

  async function startFieldCapture(field) {
    // 1. Проверяем, жив ли content script
    let contentReady = false;
    try {
      const pong = await sendToContent('PING');
      contentReady = !!pong?.pong;
    } catch {
      contentReady = false;
    }

    // 2. Если content script не загружен — инжектим программно
    if (!contentReady) {
      try {
        await chrome.scripting.executeScript({
          target: { tabId: currentTab.id },
          files: ['content/content.js'],
        });
        await chrome.scripting.insertCSS({
          target: { tabId: currentTab.id },
          files: ['content/content.css'],
        });
        // Ждём инициализацию скрипта
        await new Promise(r => setTimeout(r, 250));
      } catch (err) {
        showResult(captureResult,
          'Не удалось подключиться к странице. Обновите страницу и попробуйте снова.',
          'error'
        );
        return;
      }
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

    // Source badge + lock logic for dimension fields
    const dimFields = ['thickness', 'length', 'width'];
    if (dimFields.includes(field) && badgeEl) {
      const info = capturedFields[field];
      if (info?.value) {
        const source = getFieldSource(field);
        badgeEl.classList.remove('hidden', 'badge-auto', 'badge-capture', 'badge-manual');
        fieldEl?.classList.remove('source-locked');

        if (source === 'auto') {
          badgeEl.textContent = '🔒 авто';
          badgeEl.classList.add('badge-auto');
          fieldEl?.classList.add('source-locked');
        } else if (source === 'capture') {
          badgeEl.textContent = '🔒 захват';
          badgeEl.classList.add('badge-capture');
          fieldEl?.classList.add('source-locked');
        } else if (source === 'schema') {
          badgeEl.textContent = '🔒 schema';
          badgeEl.classList.add('badge-auto');
          fieldEl?.classList.add('source-locked');
        } else if (source === 'manual') {
          badgeEl.textContent = '✏️ вручную';
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
    if (info.auto) return 'auto';
    if (info.schema) return 'schema';
    if (info.selector) return 'capture';
    return 'manual'; // fallback
  }

  function updateActionButtons() {
    const hasTitle = !!capturedFields.title?.value;
    const hasPrice = !!capturedFields.price?.value;
    const hasAny = Object.keys(capturedFields).length > 0;

    btnValidate.disabled = !hasAny;
    btnAddMaterial.disabled = !(hasTitle && hasPrice);

    // Enable template save if at least one selector captured
    const hasSelectors = Object.values(capturedFields).some(f => f.selector);
    btnSaveTemplate.disabled = !hasSelectors;
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
    // Re-detect material type (from URL only, since title is cleared)
    refreshMaterialType();
    updateActionButtons();
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

      if (result.preview) {
        const mType = result.preview.material_type || detectedMaterialType;
        const mTypeLabel = result.preview.material_type_label || TYPE_LABELS[mType] || mType;

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

        html += `<div class="preview-row"><span class="label">Доверие:</span><span class="value">${result.trust?.trust_level || '—'}</span></div>`;
      }

      if (result.errors?.length) {
        result.errors.forEach(e => {
          html += `<div class="preview-error">⚠ ${e}</div>`;
        });
      }

      previewContent.innerHTML = html;

    } catch (err) {
      showResult(captureResult, 'Ошибка валидации: ' + err.message, 'error');
    } finally {
      btnValidate.disabled = false;
      btnValidate.textContent = 'Проверить';
    }
  }

  // ============================================================
  // Add Material
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
    btnAddMaterial.innerHTML = '<span class="spinner"></span> Добавление...';

    try {
      const result = await sendToBackground('EXTRACT', {
        url: pageInfo.url,
        extracted,
        data_sources,
        template_id: currentTemplate?.id || null,
        region_id: userInfo?.region_id || null,
      });

      if (result.success) {
        const msg = result.is_new
          ? `✓ Материал создан (ID: ${result.material?.id})\n📋 Доступен в разделе «Материалы» → «Каталог материалов»`
          : `✓ Материал обновлён (дедуп: ${result.dedup_match})`;
        showResult(captureResult, msg, 'success');
      } else {
        showResult(captureResult, '✗ ' + (result.message || 'Ошибка'), 'error');
      }
    } catch (err) {
      showResult(captureResult, 'Ошибка: ' + err.message, 'error');
    } finally {
      btnAddMaterial.disabled = false;
      btnAddMaterial.textContent = 'Добавить в Призму';
      updateActionButtons();
    }
  }

  // ============================================================
  // Templates
  // ============================================================

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

      const result = await sendToBackground('SAVE_TEMPLATE', payload);

      currentTemplate = result.template;
      showResult(templateSaveResult, result.message || 'Шаблон сохранён', 'success');
      templateStatus.innerHTML = '<span class="has-template">✓ Шаблон: ' + truncate(name, 30) + '</span>';
      btnApplyTemplate.disabled = false;

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

      if (templates.length === 0) {
        templatesList.innerHTML = '<p class="hint">Нет шаблонов для ' + pageInfo.domain + '</p>';
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

    } catch (err) {
      templatesList.innerHTML = '<p class="hint">Ошибка загрузки: ' + err.message + '</p>';
    }
  }

  async function handleApplyTemplate() {
    // Check if template has schema_mapping (stored in extraction_rules)
    const schemaMapping = currentTemplate?.extraction_rules?.schema_mapping;

    if (!schemaMapping && !currentTemplate?.selectors) {
      showResult(applyResult, 'У шаблона нет селекторов', 'error');
      return;
    }

    btnApplyTemplate.disabled = true;
    btnApplyTemplate.innerHTML = '<span class="spinner"></span>';

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
            capturedFields[field] = info;
            updateFieldUI(field, info.value);
          }
          // Auto-parse dimensions from title after schema apply
          if (capturedFields.title?.value) {
            autoParseDimensions(capturedFields.title.value);
          }
          updateActionButtons();
          showResult(applyResult, `✓ Schema.org шаблон применён: ${result.fieldCount} полей`, 'success');
        } else {
          // Schema not found on page — try CSS selectors as fallback
          if (currentTemplate?.selectors && Object.keys(currentTemplate.selectors).length > 0) {
            result = await sendToContent('APPLY_TEMPLATE', {
              selectors: currentTemplate.selectors,
            });
            applyTemplateResult(result, true);
          } else {
            showResult(applyResult, result.error || 'Schema.org данные не найдены на странице', 'error');
          }
        }
      } else {
        // CSS selector-based template
        result = await sendToContent('APPLY_TEMPLATE', {
          selectors: currentTemplate.selectors,
        });
        applyTemplateResult(result, false);
      }
    } catch (err) {
      showResult(applyResult, 'Ошибка: ' + err.message, 'error');
    } finally {
      btnApplyTemplate.disabled = false;
      btnApplyTemplate.textContent = 'Применить шаблон';
    }
  }

  function applyTemplateResult(result, isSchemaFallback) {
    if (result.errors?.length > 0) {
      const prefix = isSchemaFallback ? '⚠ Schema не найдена, используются CSS-селекторы.\n' : '';
      showResult(applyResult,
        prefix + '⚠ Некоторые селекторы не сработали:\n' + result.errors.join('\n'),
        'error'
      );
      if (result.errors.some(e => e.includes('не нашёл'))) {
        applyResult.innerHTML += '<br><small>Сайт изменился? Создайте новую версию шаблона на вкладке "Захват".</small>';
      }
    } else {
      const msg = isSchemaFallback ? '✓ Шаблон применён (CSS-селекторы, Schema не найдена)' : '✓ Шаблон применён';
      showResult(applyResult, msg, 'success');
    }

    if (result.fields) {
      for (const [field, info] of Object.entries(result.fields)) {
        if (info.found && info.value) {
          capturedFields[field] = { value: info.value, selector: info.selector };
          updateFieldUI(field, info.value);
        }
      }
      // Auto-parse dimensions from title after template apply
      if (capturedFields.title?.value) {
        autoParseDimensions(capturedFields.title.value);
      }
    }

    updateActionButtons();
  }

  async function applyTemplateById(id) {
    try {
      const result = await sendToBackground('FIND_TEMPLATE', { url: pageInfo.url });
      // Find by id in list
      const listResult = await sendToBackground('LIST_TEMPLATES', { domain: pageInfo.domain });
      const template = (listResult.templates || []).find(t => t.id === id);
      if (template) {
        currentTemplate = template;
        await handleApplyTemplate();
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
        // Auto-parse dimensions from title after schema apply
        if (capturedFields.title?.value) {
          autoParseDimensions(capturedFields.title.value);
        }
        updateActionButtons();
        showResult(captureResult, `✓ Заполнено из Schema.org: ${result.fieldCount} полей`, 'success');
      } else {
        showResult(captureResult, result.error || 'Не удалось извлечь данные', 'error');
      }
    } catch (err) {
      showResult(captureResult, 'Ошибка: ' + err.message, 'error');
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
