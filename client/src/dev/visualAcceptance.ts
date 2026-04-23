const enabledFlag = import.meta.env.VITE_ENABLE_DEV_VISUAL_AUTH === 'true'

export const isDevVisualAuthEnabled = import.meta.env.DEV && enabledFlag

export const devVisualUser = {
  id: 1,
  name: 'UI Acceptance',
  email: 'ui-acceptance.local@example.test',
  role: 'admin',
  is_admin: true,
  registration_completed_at: '2026-01-01T00:00:00.000000Z',
  is_russia_ip: true,
}

const demoProjects = [
  {
    id: 101,
    number: 'SE-2026-001',
    address: 'г. Екатеринбург, ул. Производственная, 12, офис 402',
    expert_name: 'Иван Петров',
    latest_revision_status: 'published',
    latest_revision_at: '2026-04-18T10:20:00.000000Z',
    updated_at: '2026-04-21T15:30:00.000000Z',
    revisions_count: 3,
  },
  {
    id: 102,
    number: 'SE-2026-002',
    address: 'г. Тюмень, ул. Мебельщиков, 8',
    expert_name: 'Анна Смирнова',
    latest_revision_status: 'locked',
    latest_revision_at: '2026-04-20T08:45:00.000000Z',
    updated_at: '2026-04-22T11:10:00.000000Z',
    revisions_count: 1,
  },
  {
    id: 103,
    number: 'SE-2026-003',
    address: 'г. Челябинск, пр-т Ленина, 44',
    expert_name: null,
    latest_revision_status: null,
    latest_revision_at: null,
    updated_at: '2026-04-23T09:00:00.000000Z',
    revisions_count: 0,
  },
]

const demoImports = [
  {
    id: 31,
    type: 'manual',
    status: 'pending',
    created_at: '2026-04-22T12:15:00.000000Z',
    items_count: 3,
    linked_count: 1,
    pending_count: 2,
  },
  {
    id: 30,
    type: 'excel',
    status: 'processed',
    created_at: '2026-04-19T09:40:00.000000Z',
    items_count: 8,
    linked_count: 8,
    pending_count: 0,
  },
]

const demoImportItems = [
  {
    id: 501,
    import_id: 31,
    operation_id: null,
    name: 'Распил ЛДСП 16 мм',
    value: 180,
    unit: 'п.м.',
    parsed_operation_hint: 'Распил',
    status: 'pending',
  },
  {
    id: 502,
    import_id: 31,
    operation_id: 12,
    name: 'Кромление ПВХ 2 мм',
    value: 95,
    unit: 'п.м.',
    parsed_operation_hint: 'Кромка',
    status: 'linked',
  },
  {
    id: 503,
    import_id: 31,
    operation_id: null,
    name: 'Доставка',
    value: 1500,
    unit: 'шт.',
    parsed_operation_hint: null,
    status: 'ignored',
  },
]

const demoOperations = [
  { id: 11, name: 'Распил прямолинейный', category: 'Раскрой', unit: 'п.м.', operation_kind: 'cutting', origin: 'system', linked_prices_count: 2 },
  { id: 12, name: 'Кромление ПВХ', category: 'Кромка', unit: 'п.м.', operation_kind: 'edging', origin: 'system', linked_prices_count: 1 },
  { id: 13, name: 'Сверление отверстий', category: 'Присадка', unit: 'шт.', operation_kind: 'drilling', origin: 'user', linked_prices_count: 0 },
]

const demoMaterials = [
  { id: 1, name: 'ЛДСП Egger W980 ST2 16 мм', type: 'plate' },
  { id: 2, name: 'МДФ Kronospan 19 мм', type: 'plate' },
  { id: 3, name: 'Кромка ПВХ белая 2 мм', type: 'edge' },
  { id: 4, name: 'Кромка ПВХ графит 1 мм', type: 'edge' },
]

const demoCatalogMaterials = [
  {
    id: 201,
    name: 'ЛДСП Egger W980 ST2 16 мм',
    article: 'W980-ST2-16',
    type: 'plate',
    unit: 'м²',
    price_per_unit: 920,
    source_url: 'https://example.test/materials/egger-w980',
    visibility: 'public',
    trust_score: 82,
    trust_level: 'verified',
    data_origin: 'manual',
    user_id: 1,
    region_id: 1,
    is_active: true,
    created_at: '2026-04-20T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
    price_checked_at: '2026-04-22T10:00:00.000000Z',
    latest_price: {
      price_per_unit: 920,
      observed_at: '2026-04-22T10:00:00.000000Z',
      source_url: 'https://example.test/materials/egger-w980',
      region_id: 1,
      is_verified: true,
      currency: 'RUB',
    },
    in_library: true,
    pinned: false,
    facade_class: null,
    metadata: {},
  },
  {
    id: 202,
    name: 'Кромка ПВХ графит 2 мм',
    article: 'EDGE-GR-2',
    type: 'edge',
    unit: 'м.п.',
    price_per_unit: 34,
    source_url: null,
    visibility: 'private',
    trust_score: 46,
    trust_level: 'partial',
    data_origin: 'price_list',
    user_id: 1,
    region_id: 1,
    is_active: true,
    created_at: '2026-04-18T10:00:00.000000Z',
    updated_at: '2026-04-20T10:00:00.000000Z',
    price_checked_at: '2026-04-01T10:00:00.000000Z',
    latest_price: null,
    in_library: false,
    pinned: false,
    facade_class: null,
    metadata: {},
  },
]

const demoIdeas = [
  {
    id: 701,
    title: 'Единая панель быстрых фильтров в каталоге',
    description: 'Сделать одинаковый compact toolbar для реестров, чтобы фильтры не прыгали между экранами.',
    author_nickname: 'UI Acceptance',
    user_id: 1,
    votes_up: 12,
    votes_down: 1,
    score: 11,
    views: 48,
    comments_count: 3,
    status: 'NEW',
    tags: [{ id: 1, name: 'UI' }, { id: 2, name: 'Каталог' }],
    attachments: [],
    created_at: '2026-04-21T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
  },
  {
    id: 702,
    title: 'Подсказка по источникам цен фасада',
    description: 'Добавить короткую справку в диалог источника цены, чтобы снизить ошибки при заполнении.',
    author_nickname: 'Команда продукта',
    user_id: 2,
    votes_up: 7,
    votes_down: 0,
    score: 7,
    views: 31,
    comments_count: 1,
    status: 'PLANNED',
    tags: [{ id: 3, name: 'Готовые изделия' }],
    attachments: [],
    created_at: '2026-04-19T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
  },
]

const demoFinishedProductSpecifications = [
  {
    id: 401,
    product_type: 'facade',
    name: 'Фасад эмаль матовая',
    article: 'F-EM-MAT',
    is_active: true,
    facade_class: 'painted_mdf',
    base_type: 'mdf',
    thickness_mm: 19,
    covering: 'enamel',
    cover_type: 'matte',
    collection: 'Urban',
    decor_label: 'Белый шелк',
    price_group_label: 'A',
    notes: null,
    metadata: {},
    source_count: 3,
    aggregation_method: 'median',
    computed_price_summary: {
      computed_price_per_m2: 14500,
      method: 'median',
      source_count: 3,
      min_price: 13900,
      max_price: 15200,
      computed_at: '2026-04-22T10:00:00.000000Z',
    },
    created_at: '2026-04-19T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
  },
  {
    id: 402,
    product_type: 'facade',
    name: 'Фасад пленка ПВХ',
    article: 'F-PVC-CLASSIC',
    is_active: false,
    facade_class: 'film_mdf',
    base_type: 'mdf',
    thickness_mm: 16,
    covering: 'pvc',
    cover_type: 'glossy',
    collection: 'Classic',
    decor_label: 'Графит',
    price_group_label: 'B',
    notes: null,
    metadata: {},
    source_count: 0,
    aggregation_method: null,
    computed_price_summary: {
      computed_price_per_m2: null,
      method: null,
      source_count: 0,
      min_price: null,
      max_price: null,
      computed_at: null,
    },
    created_at: '2026-04-18T10:00:00.000000Z',
    updated_at: '2026-04-21T10:00:00.000000Z',
  },
]

const demoOperationPriceSources = [
  {
    id: 901,
    type: 'manual',
    value: 180,
    unit: 'п.м.',
    source_name: 'Демо-прайс цеха',
    document_ref: 'UI-A-01',
    created_at: '2026-04-22T10:00:00.000000Z',
    is_active: true,
  },
  {
    id: 902,
    type: 'import',
    value: 165,
    unit: 'п.м.',
    source_name: 'Импорт прайса',
    document_ref: 'UI-A-02',
    created_at: '2026-04-20T10:00:00.000000Z',
    is_active: false,
  },
]

const demoDetailTypes = [
  {
    id: 801,
    name: 'Полка',
    edge_processing: '=',
    positions_count: 12,
    origin: 'system',
    components: [{ type: 'operation', id: 11, quantity: 1 }],
  },
  {
    id: 802,
    name: 'Фасадная деталь',
    edge_processing: 'O',
    positions_count: 4,
    origin: 'user',
    components: [{ type: 'operation', id: 12, quantity: 2 }],
  },
  {
    id: 803,
    name: 'Задняя стенка',
    edge_processing: 'none',
    positions_count: 0,
    origin: 'user',
    components: [],
  },
]

const demoLaborProfiles = [
  {
    id: 701,
    user_id: 1,
    title: 'Столяр-сборщик',
    description: 'Работы по сборке корпусной мебели и регулировке фасадов.',
    is_active: true,
    sort_order: 10,
    created_at: '2026-04-20T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
  },
  {
    id: 702,
    user_id: 1,
    title: 'Оператор ЧПУ',
    description: 'Раскрой, сверление и подготовка деталей на оборудовании.',
    is_active: true,
    sort_order: 20,
    created_at: '2026-04-20T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
  },
]

const demoLaborProviders = [
  {
    id: 711,
    user_id: 1,
    title: 'hh.ru',
    domain: 'hh.ru',
    base_url: 'https://hh.ru',
    is_active: true,
    sort_order: 10,
    created_at: '2026-04-20T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
  },
  {
    id: 712,
    user_id: 1,
    title: 'Авито Работа',
    domain: 'avito.ru',
    base_url: 'https://www.avito.ru',
    is_active: true,
    sort_order: 20,
    created_at: '2026-04-20T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
  },
]

const demoLaborSources = [
  {
    id: 721,
    user_id: 1,
    region_id: 1,
    labor_profile_id: 701,
    provider_id: 711,
    evidence_record_id: 731,
    source_title: 'Столяр-сборщик на производство мебели',
    source_url: 'https://example.test/labor/stolyar',
    source_date: '2026-04-21',
    employer_name: 'Мебельный цех',
    vacancy_title: 'Столяр-сборщик',
    vacancy_description: 'Сборка корпусной мебели, регулировка фасадов, монтаж фурнитуры.',
    vacancy_excerpt: 'Сборка корпусной мебели и монтаж фурнитуры.',
    salary_raw_text: '90 000-120 000 ₽ в месяц',
    salary_value: null,
    salary_value_min: 90000,
    salary_value_max: 120000,
    salary_period: 'month',
    hours_per_month: 160,
    derived_hourly_rate: 656,
    currency: 'RUB',
    note: null,
    captured_via: 'manual',
    verification_status: 'verified',
    is_active: true,
    created_at: '2026-04-22T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
    provider: demoLaborProviders[0],
    labor_profile: demoLaborProfiles[0],
    region: { id: 1, name: 'Свердловская область', region_name: 'Свердловская область' },
    evidence_record: {
      id: 731,
      uuid: 'ui-labor-record-731',
      source_url: 'https://example.test/labor/stolyar',
      source_domain: 'example.test',
      observed_price: '105000',
      currency: 'RUB',
      observed_at: '2026-04-22T10:00:00.000000Z',
      capture_method: 'manual',
      verification_status: 'verified',
      assets: [{ id: 741, uuid: 'ui-labor-asset-741', evidence_record_id: 731, asset_type: 'screenshot', file_path: '/demo.png', original_filename: 'demo.png', mime_type: 'image/png', file_size: 120000 }],
    },
  },
  {
    id: 722,
    user_id: 1,
    region_id: 1,
    labor_profile_id: 702,
    provider_id: 712,
    evidence_record_id: null,
    source_title: 'Оператор станка ЧПУ',
    source_url: 'https://example.test/labor/cnc',
    source_date: '2026-04-19',
    employer_name: 'Производство фасадов',
    vacancy_title: 'Оператор ЧПУ',
    vacancy_description: 'Работа с картами раскроя и сверлением деталей.',
    vacancy_excerpt: 'Раскрой и сверление мебельных деталей.',
    salary_raw_text: '650 ₽ / ч',
    salary_value: 650,
    salary_value_min: null,
    salary_value_max: null,
    salary_period: 'hour',
    hours_per_month: 160,
    derived_hourly_rate: 650,
    currency: 'RUB',
    note: null,
    captured_via: 'chrome',
    verification_status: 'pending',
    is_active: true,
    created_at: '2026-04-21T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
    provider: demoLaborProviders[1],
    labor_profile: demoLaborProfiles[1],
    region: { id: 1, name: 'Свердловская область', region_name: 'Свердловская область' },
    evidence_record: null,
  },
]

const demoSettings = {
  region_id: 1,
  use_area_calc_mode: false,
  waste_coefficient: 1.12,
  repair_coefficient: 1.05,
  default_plate_material_id: 1,
  default_edge_material_id: 3,
  default_expert_name: 'UI Acceptance',
  default_number: 'SE-DEMO',
  waste_plate_coefficient: 1.08,
  waste_edge_coefficient: 1.04,
  waste_operations_coefficient: 1.1,
  apply_waste_to_plate: true,
  apply_waste_to_edge: true,
  apply_waste_to_operations: false,
  waste_plate_description: {
    title: 'Плитные материалы',
    text: 'Демонстрационное описание для визуальной приемки.',
  },
  waste_edge_description: null,
  waste_operations_description: null,
  show_waste_plate_description: true,
  show_waste_edge_description: false,
  show_waste_operations_description: false,
  text_blocks: [
    { id: 1, title: 'Основание расчета', content: 'Демо-блок для UI smoke.' },
  ],
}

function normalizeApiPath(url: string): string {
  return url.replace(/^https?:\/\/[^/]+/i, '').split('?')[0] || '/'
}

export function getDevVisualApiMock(method?: string, url?: string): unknown | undefined {
  if (!isDevVisualAuthEnabled || method?.toLowerCase() !== 'get' || !url) {
    return undefined
  }

  const path = normalizeApiPath(url)

  if (path === '/api/me') return devVisualUser
  if (path === '/api/projects') return demoProjects
  if (path === '/api/price-imports') return { imports: demoImports }
  if (/^\/api\/price-imports\/\d+\/items$/.test(path)) return { items: demoImportItems }
  if (path === '/api/materials/catalog') {
    return {
      data: demoCatalogMaterials,
      meta: { current_page: 1, last_page: 1, per_page: 25, total: demoCatalogMaterials.length, mode: 'own', region_id: 1 },
    }
  }
  if (path === '/api/ideas') {
    const status = new URL(url, window.location.origin).searchParams.get('status')
    const filtered = status ? demoIdeas.filter((idea) => idea.status === status) : demoIdeas
    return {
      data: filtered,
      meta: { current_page: 1, last_page: 1, per_page: 10, total: filtered.length },
    }
  }
  if (path === '/api/finished-product-specifications') {
    return {
      data: demoFinishedProductSpecifications,
      meta: { current_page: 1, last_page: 1, per_page: 25, total: demoFinishedProductSpecifications.length },
    }
  }
  if (path === '/api/detail-types') return demoDetailTypes
  if (/^\/api\/operations\/\d+\/price-sources$/.test(path)) return demoOperationPriceSources
  if (/^\/api\/operations\/\d+\/pricing-summary$/.test(path)) {
    const id = Number(path.match(/^\/api\/operations\/(\d+)\/pricing-summary$/)?.[1] ?? 0)
    const price = id === 13 ? null : id === 12 ? 95 : 180
    return {
      operation_id: id,
      effective_source: price
        ? { key: `manual:${id}`, type: 'manual', id: String(id), name: 'Демо-прайс цеха', price, unit: id === 13 ? 'шт.' : 'п.м.', mode: 'selected' }
        : null,
      effective_price: price,
    }
  }
  if (/^\/api\/operations\/\d+\/application-rule$/.test(path)) {
    const id = Number(path.match(/^\/api\/operations\/(\d+)\/application-rule$/)?.[1] ?? 0)
    return {
      id: 1000 + id,
      operation_id: id,
      source: 'system',
      is_editable: false,
      mode: 'automatic',
      applies_to: 'material_type',
      material_type: id === 12 ? 'edge' : 'plate',
      material_id: null,
      quantity_source: id === 12 ? 'edge_length' : id === 13 ? 'holes_count' : 'position_area_m2',
      pricing_unit: id === 13 ? 'шт.' : 'п.м.',
      tariff_binding_type: 'operation_resolver',
      tariff_operation_id: id,
      conditions: null,
      quantity_config: { multiplier: 1 },
      priority: 100,
      is_enabled: true,
      updated_at: '2026-04-22T10:00:00.000000Z',
    }
  }
  if (/^\/api\/projects\/\d+\/operations$/.test(path)) {
    return [
      { operation_id: 11, quantity: 14.5, unit: 'п.м.', amount: 2610, total_cost: 2610, is_valid: true },
      { operation_id: 12, quantity: 22, unit: 'п.м.', amount: 2090, total_cost: 2090, is_valid: true },
    ]
  }
  if (path === '/api/pricing/labor/sources') {
    return { current_page: 1, data: demoLaborSources, per_page: 100, total: demoLaborSources.length, last_page: 1 }
  }
  if (path === '/api/pricing/labor/providers') {
    return { current_page: 1, data: demoLaborProviders, per_page: 100, total: demoLaborProviders.length, last_page: 1 }
  }
  if (path === '/api/pricing/labor/profiles') {
    return { current_page: 1, data: demoLaborProfiles, per_page: 100, total: demoLaborProfiles.length, last_page: 1 }
  }
  if (path === '/api/operations') return demoOperations
  if (path === '/api/materials') return demoMaterials
  if (path === '/api/regions') {
    return {
      data: [
        { id: 1, name: 'Свердловская область', region_name: 'Свердловская область' },
        { id: 2, name: 'Тюменская область', region_name: 'Тюменская область' },
      ],
    }
  }
  if (path === '/api/user/settings') return demoSettings

  return undefined
}
