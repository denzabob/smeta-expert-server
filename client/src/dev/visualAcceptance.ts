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

const demoProjectDetails = {
  id: 101,
  number: 'SE-2026-001',
  address: 'г. Екатеринбург, ул. Производственная, 12, офис 402',
  expert_name: 'Иван Петров',
  region_id: 1,
  use_area_calc_mode: false,
  normohour_rate: 1250,
  waste_coefficient: 1.12,
  repair_coefficient: 1.05,
  waste_plate_coefficient: 1.08,
  waste_edge_coefficient: 1.04,
  waste_operations_coefficient: 1.1,
  apply_waste_to_plate: true,
  apply_waste_to_edge: true,
  apply_waste_to_operations: false,
  show_waste_plate_description: true,
  show_waste_edge_description: false,
  show_waste_operations_description: false,
  text_blocks: [
    { id: 1, title: 'Основание расчета', text: 'Демо-блок для визуальной приемки.', enabled: true },
  ],
  profileRates: [],
  created_at: '2026-04-18T10:00:00.000000Z',
  updated_at: '2026-04-22T10:00:00.000000Z',
}

const demoProjectPositions = [
  {
    id: 1001,
    project_id: 101,
    detail_type_id: 801,
    material_id: 1,
    edge_material_id: 3,
    custom_name: 'Полка верхняя',
    width: 720,
    length: 320,
    quantity: 4,
    edge_scheme: '=',
    unit_price: 940,
    total_price: 866,
  },
  {
    id: 1002,
    project_id: 101,
    detail_type_id: 802,
    material_id: 2,
    edge_material_id: 4,
    custom_name: 'Фасад ящика',
    width: 596,
    length: 180,
    quantity: 3,
    edge_scheme: 'O',
    unit_price: 14500,
    total_price: 4667,
  },
]

const demoProjectFittings = [
  { id: 1101, project_id: 101, name: 'Петля с доводчиком', quantity: 8, unit: 'шт.', unit_price: 180, total_price: 1440 },
  { id: 1102, project_id: 101, name: 'Направляющая скрытого монтажа', quantity: 3, unit: 'компл.', unit_price: 1450, total_price: 4350 },
]

const demoProjectExpenses = [
  { id: 1201, project_id: 101, name: 'Доставка материалов', amount: 2500, description: 'Демо-расход для проверки секции' },
]

const demoProjectLaborWorks = [
  {
    id: 1301,
    project_id: 101,
    labor_profile_id: 701,
    labor_profile_name: 'Столяр-сборщик',
    title: 'Сборка корпуса и регулировка фасадов',
    basis: 'Работы по проекту',
    hours: 6,
    hours_source: 'manual',
    rate_per_hour: 1250,
    cost_total: 7500,
    sort_order: 1,
  },
]

const demoProjectRevision = {
  id: 1401,
  project_id: 101,
  number: 3,
  status: 'draft',
  snapshot_hash: 'ui-demo-revision',
  created_at: '2026-04-22T10:00:00.000000Z',
}

const demoEvidenceRunItems = [
  {
    id: 1501,
    uuid: 'ui-evidence-item-1',
    evidence_run_id: 1500,
    cost_component: 'plate',
    label: 'ЛДСП Egger W980 ST2 16 мм',
    status: 'resolved',
    resolution_type: 'auto',
    subject_type: 'material',
    subject_id: 1,
    evidence_record_id: 731,
    source_url: 'https://example.test/materials/egger-w980',
    effective_value: '920',
    currency: 'RUB',
    diagnostics_json: null,
    created_at: '2026-04-22T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
  },
  {
    id: 1502,
    uuid: 'ui-evidence-item-2',
    evidence_run_id: 1500,
    cost_component: 'fitting',
    label: 'Петля с доводчиком',
    status: 'pending',
    resolution_type: null,
    subject_type: 'fitting',
    subject_id: 1101,
    evidence_record_id: null,
    source_url: 'https://example.test/fittings/hinge',
    effective_value: null,
    currency: 'RUB',
    diagnostics_json: null,
    created_at: '2026-04-22T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
  },
]

const demoEvidenceRun = {
  id: 1500,
  uuid: 'ui-evidence-run-1',
  project_id: 101,
  initiated_by: 1,
  status: 'ready',
  total_items: demoEvidenceRunItems.length,
  completed_items: 1,
  failed_items: 0,
  metadata_json: null,
  snapshot_json: null,
  started_at: '2026-04-22T10:00:00.000000Z',
  finalized_at: null,
  created_at: '2026-04-22T10:00:00.000000Z',
  updated_at: '2026-04-22T10:00:00.000000Z',
  items: demoEvidenceRunItems,
}

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

const demoMaterialDimensionRules = [
  {
    id: 9101,
    name: 'Размеры ЛДСП из названия',
    description: 'Распознает длину, ширину и толщину из карточек плитных материалов.',
    is_active: true,
    priority: 100,
    material_type: 'plate',
    source: 'catalog',
    rule_type: 'regex',
    config: {
      pattern: '(\\d{3,4})x(\\d{3,4})x(\\d{1,2})',
      captures: { length_mm: 1, width_mm: 2, thickness_mm: 3 },
    },
    example_input: 'ЛДСП 2800x2070x16 Egger',
    expected_result: { length_mm: 2800, width_mm: 2070, thickness_mm: 16 },
    confidence: 0.92,
    created_by_user_id: 1,
    updated_by_user_id: 1,
    created_at: '2026-04-19T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
  },
  {
    id: 9102,
    name: 'Толщина кромки ПВХ',
    description: 'Извлекает толщину кромки из короткого описания.',
    is_active: false,
    priority: 80,
    material_type: 'edge',
    source: null,
    rule_type: 'regex',
    config: {
      pattern: 'ПВХ\\s+(\\d+(?:\\.\\d+)?)\\s*мм',
      captures: { thickness_mm: 1 },
    },
    example_input: 'Кромка ПВХ 2 мм белая',
    expected_result: { thickness_mm: 2 },
    confidence: 0.86,
    created_by_user_id: 1,
    updated_by_user_id: 1,
    created_at: '2026-04-18T10:00:00.000000Z',
    updated_at: '2026-04-21T10:00:00.000000Z',
  },
]

const demoMaterialTypePatterns = [
  {
    id: 9201,
    name: 'plate_ldsp_pattern',
    description: 'Определяет плитные материалы по ЛДСП/КДСП в названии.',
    is_active: true,
    priority: 100,
    material_type: 'plate',
    source: null,
    rule_type: 'regex',
    target_field: 'title',
    pattern: '(?:ЛДСП|КДСП)',
    flags: 'i',
    use_normalized_text: true,
    example_input: 'ЛДСП Egger W980 16 мм',
    expected_material_type: 'plate',
    created_by_user_id: 1,
    updated_by_user_id: 1,
    created_at: '2026-04-19T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
  },
  {
    id: 9202,
    name: 'edge_pvc_pattern',
    description: 'Определяет кромку ПВХ по названию.',
    is_active: true,
    priority: 90,
    material_type: 'edge',
    source: null,
    rule_type: 'regex',
    target_field: 'title_or_url',
    pattern: '(?:кромка|edge).*ПВХ',
    flags: 'i',
    use_normalized_text: true,
    example_input: 'Кромка ПВХ белая 2 мм',
    expected_material_type: 'edge',
    created_by_user_id: 1,
    updated_by_user_id: 1,
    created_at: '2026-04-20T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
  },
]

const demoMaterialDimensionFailures = [
  {
    id: 9301,
    fingerprint: 'ui-demo-failure-1',
    raw_text: 'ЛДСП белый шелк 16',
    normalized_text: 'лдсп белый шелк 16',
    material_type: 'plate',
    source: 'catalog',
    parse_error_reason: 'missing_dimension',
    occurrences: 7,
    first_seen_at: '2026-04-20T10:00:00.000000Z',
    last_seen_at: '2026-04-22T10:00:00.000000Z',
    resolved_length_mm: null,
    resolved_width_mm: null,
    resolved_thickness_mm: null,
    resolution_note: null,
    resolved_by_user_id: null,
    resolved_at: null,
    last_result: null,
    created_at: '2026-04-20T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
  },
  {
    id: 9302,
    fingerprint: 'ui-demo-failure-2',
    raw_text: 'Кромка графитовая тонкая',
    normalized_text: 'кромка графитовая тонкая',
    material_type: 'edge',
    source: 'import',
    parse_error_reason: 'no_match',
    occurrences: 3,
    first_seen_at: '2026-04-21T10:00:00.000000Z',
    last_seen_at: '2026-04-22T10:00:00.000000Z',
    resolved_length_mm: null,
    resolved_width_mm: null,
    resolved_thickness_mm: null,
    resolution_note: null,
    resolved_by_user_id: null,
    resolved_at: null,
    last_result: null,
    created_at: '2026-04-21T10:00:00.000000Z',
    updated_at: '2026-04-22T10:00:00.000000Z',
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

const demoParserSystemStatus = {
  scheduler_running: true,
  active_sessions: 1,
  total_sessions_24h: 14,
  failed_sessions_24h: 2,
  health_score: 88,
  last_check: '2026-04-23T09:30:00.000000Z',
}

const demoParserSuppliers = [
  {
    supplier: 'skm_mebel',
    active: true,
    health_score: 91,
    last_sync: '2026-04-23T08:45:00.000000Z',
    current_pid: 44812,
    sessions_count_24h: 8,
    success_rate_24h: 94,
  },
  {
    supplier: 'mebel_market',
    active: true,
    health_score: 67,
    last_sync: '2026-04-22T18:10:00.000000Z',
    current_pid: null,
    sessions_count_24h: 4,
    success_rate_24h: 82,
  },
  {
    supplier: 'archive_template',
    active: false,
    health_score: 0,
    last_sync: null,
    current_pid: null,
    sessions_count_24h: 0,
    success_rate_24h: 0,
  },
]

const demoParserSessions = [
  {
    id: 1201,
    supplier: 'skm_mebel',
    status: 'running',
    config: { collect_urls: false },
    started_at: '2026-04-23T08:30:00.000000Z',
    completed_at: null,
    pid: 44812,
    last_heartbeat_at: '2026-04-23T09:30:00.000000Z',
    error_message: null,
    exit_code: null,
    total_urls: 180,
    processed_count: 96,
    success_count: 91,
    error_count: 5,
    screenshots_taken: 14,
    created_at: '2026-04-23T08:25:00.000000Z',
    updated_at: '2026-04-23T09:30:00.000000Z',
  },
  {
    id: 1200,
    supplier: 'mebel_market',
    status: 'completed',
    config: { collect_urls: true },
    started_at: '2026-04-22T14:00:00.000000Z',
    completed_at: '2026-04-22T14:46:00.000000Z',
    pid: null,
    last_heartbeat_at: '2026-04-22T14:45:00.000000Z',
    error_message: null,
    exit_code: 0,
    total_urls: 72,
    processed_count: 72,
    success_count: 68,
    error_count: 4,
    screenshots_taken: 9,
    created_at: '2026-04-22T13:58:00.000000Z',
    updated_at: '2026-04-22T14:46:00.000000Z',
  },
  {
    id: 1199,
    supplier: 'skm_mebel',
    status: 'failed',
    config: { collect_urls: false },
    started_at: '2026-04-21T11:10:00.000000Z',
    completed_at: '2026-04-21T11:18:00.000000Z',
    pid: null,
    last_heartbeat_at: '2026-04-21T11:17:00.000000Z',
    error_message: 'Demo timeout while waiting for catalog response',
    exit_code: 1,
    total_urls: 64,
    processed_count: 19,
    success_count: 16,
    error_count: 3,
    screenshots_taken: 2,
    created_at: '2026-04-21T11:08:00.000000Z',
    updated_at: '2026-04-21T11:18:00.000000Z',
  },
]

const demoParserChartData = [
  { date: '2026-04-17', processed: 42, errors: 2 },
  { date: '2026-04-18', processed: 66, errors: 4 },
  { date: '2026-04-19', processed: 58, errors: 1 },
  { date: '2026-04-20', processed: 82, errors: 5 },
  { date: '2026-04-21', processed: 71, errors: 6 },
  { date: '2026-04-22', processed: 96, errors: 4 },
  { date: '2026-04-23', processed: 54, errors: 2 },
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

const demoAdminChatMessages = [
  {
    id: 8401,
    conversation_id: 8101,
    sender_id: 201,
    sender_role: 'customer',
    type: 'text',
    body: 'Добрый день. Не вижу обновленные материалы после импорта прайса.',
    meta_json: null,
    is_mine: false,
    sender_display_name: 'Мария Кузнецова',
    created_at: '2026-04-23T08:10:00.000000Z',
    attachments: [],
  },
  {
    id: 8402,
    conversation_id: 8101,
    sender_id: 1,
    sender_role: 'admin',
    type: 'text',
    body: 'Здравствуйте. Проверяю импорт и связь с каталогом материалов.',
    meta_json: null,
    is_mine: true,
    sender_display_name: 'UI Acceptance',
    created_at: '2026-04-23T08:14:00.000000Z',
    attachments: [],
  },
  {
    id: 8403,
    conversation_id: 8101,
    sender_id: 201,
    sender_role: 'customer',
    type: 'text',
    body: 'Прикладываю скриншот строки, где цена не подтянулась.',
    meta_json: null,
    is_mine: false,
    sender_display_name: 'Мария Кузнецова',
    created_at: '2026-04-23T08:18:00.000000Z',
    attachments: [
      {
        id: 8501,
        original_name: 'price-row.png',
        mime_type: 'image/png',
        size: 184320,
        width: 1280,
        height: 720,
        url: '/storage/demo-chat/price-row.png',
      },
    ],
  },
]

const demoAdminChatConversations = [
  {
    id: 8101,
    status: 'open',
    subject: 'Проблема с импортом прайса',
    assigned_admin_id: null,
    assigned_admin: null,
    creator: { id: 201, name: 'Мария Кузнецова', email: 'maria@example.test' },
    last_message_at: '2026-04-23T08:18:00.000000Z',
    unread_count: 2,
    created_at: '2026-04-23T08:10:00.000000Z',
  },
  {
    id: 8102,
    status: 'pending',
    subject: 'Вопрос по расчету фасадов',
    assigned_admin_id: 1,
    assigned_admin: { id: 1, name: 'UI Acceptance' },
    creator: { id: 202, name: 'Алексей Морозов', email: 'alex@example.test' },
    last_message_at: '2026-04-22T16:40:00.000000Z',
    unread_count: 0,
    created_at: '2026-04-22T16:12:00.000000Z',
  },
  {
    id: 8103,
    status: 'closed',
    subject: 'Доступ восстановлен',
    assigned_admin_id: 1,
    assigned_admin: { id: 1, name: 'UI Acceptance' },
    creator: { id: 203, name: 'Ольга Павлова', email: 'olga@example.test' },
    last_message_at: '2026-04-20T12:05:00.000000Z',
    unread_count: 0,
    created_at: '2026-04-20T11:50:00.000000Z',
  },
]

const demoAdminChatDetails = demoAdminChatConversations.map((conversation) => ({
  ...conversation,
  participants: [
    {
      id: conversation.id + 100,
      user_id: conversation.creator?.id ?? 0,
      role: 'customer',
      joined_at: conversation.created_at,
      user: conversation.creator ? { id: conversation.creator.id, name: conversation.creator.name } : null,
    },
    {
      id: conversation.id + 200,
      user_id: 1,
      role: 'admin',
      joined_at: conversation.created_at,
      user: { id: 1, name: 'UI Acceptance' },
    },
  ],
  messages:
    conversation.id === 8101
      ? demoAdminChatMessages
      : [
          {
            id: conversation.id + 500,
            conversation_id: conversation.id,
            sender_id: conversation.creator?.id ?? null,
            sender_role: 'customer',
            type: 'text',
            body: conversation.subject,
            meta_json: null,
            is_mine: false,
            sender_display_name: conversation.creator?.name ?? null,
            created_at: conversation.last_message_at ?? conversation.created_at,
            attachments: [],
          },
        ],
}))

const demoSupportChatMessages = [
  {
    id: 9101,
    conversation_id: 9100,
    sender_id: 1,
    sender_role: 'customer',
    type: 'text',
    body: 'Здравствуйте. После обновления проекта не могу понять, почему в итоговой смете не изменился расход на кромку. Подскажите, где это лучше проверить в интерфейсе?',
    meta_json: null,
    is_mine: true,
    sender_display_name: 'UI Acceptance',
    created_at: '2026-04-23T09:10:00.000000Z',
    attachments: [],
  },
  {
    id: 9102,
    conversation_id: 9100,
    sender_id: 44,
    sender_role: 'admin',
    type: 'text',
    body: 'Добрый день. Обычно сначала стоит открыть проект и проверить блок материалов или операций, в зависимости от того, где менялся источник цены. Если хотите, можем пройти по шагам вместе.',
    meta_json: null,
    is_mine: false,
    sender_display_name: 'Поддержка Prisma',
    created_at: '2026-04-23T09:12:00.000000Z',
    attachments: [],
  },
  {
    id: 9103,
    conversation_id: 9100,
    sender_id: 44,
    sender_role: 'admin',
    type: 'file',
    body: 'Ниже приложили короткую памятку по проверке расчета.',
    meta_json: null,
    is_mine: false,
    sender_display_name: 'Поддержка Prisma',
    created_at: '2026-04-23T09:14:00.000000Z',
    attachments: [
      {
        id: 91031,
        original_name: 'checklist-project-pricing.pdf',
        mime_type: 'application/pdf',
        size: 248832,
        width: null,
        height: null,
        url: '/storage/demo-chat/checklist-project-pricing.pdf',
      },
    ],
  },
  {
    id: 9104,
    conversation_id: 9100,
    sender_id: 1,
    sender_role: 'customer',
    type: 'text',
    body: 'Спасибо. Еще один момент: длинное сообщение для визуальной приемки должно оставаться читабельным и на мобильной ширине, без горизонтального скролла, без залипания меток времени и без ощущения, что bubble собран из случайных отступов.',
    meta_json: null,
    is_mine: true,
    sender_display_name: 'UI Acceptance',
    created_at: '2026-04-23T09:17:00.000000Z',
    attachments: [],
  },
]

const demoSupportChatConversation = {
  id: 9100,
  status: 'open',
  subject: 'Помощь по расчету проекта',
  assigned_admin_id: 44,
  last_message_at: '2026-04-23T09:17:00.000000Z',
  unread_count: 2,
  participants: [
    { id: 1, user_id: 1, role: 'customer', user: { id: 1, name: 'UI Acceptance' } },
    { id: 2, user_id: 44, role: 'admin', user: { id: 44, name: 'Поддержка Prisma' } },
  ],
  messages: demoSupportChatMessages,
  created_at: '2026-04-23T09:10:00.000000Z',
}

const demoSupportChatEmptyConversation = {
  ...demoSupportChatConversation,
  id: 9105,
  unread_count: 0,
  messages: [],
  last_message_at: null,
  subject: 'Новый вопрос',
}

const demoSupportChatClosedConversation = {
  ...demoSupportChatConversation,
  id: 9106,
  status: 'closed',
  unread_count: 0,
  subject: 'Диалог закрыт',
}

function normalizeApiPath(url: string): string {
  return url.replace(/^https?:\/\/[^/]+/i, '').split('?')[0] || '/'
}

const demoPriceIndicesSeries = [
  {
    public_id: '01900000-0000-7000-8000-000000000001',
    classifier_item: {
      public_id: '01900000-0000-7000-8000-000000000101',
      classifier_code: 'okpd2_based',
      item_code: '31.02.10.140',
      item_name: 'Наборы кухонной мебели',
      provider_code_kind: 'numeric',
    },
    indicator: { code: 'producer_price_index', name: 'Индекс цен производителей' },
    territory: { code: 'RU', name: 'Российская Федерация' },
    frequency: 'monthly',
    comparison_basis: 'previous_month',
    unit: 'percent',
    period: { from: '2021-01', to: '2026-06', observations_count: 66 },
    active_import: {
      public_id: '01900000-0000-7000-8000-000000000901',
      importer_code: 'rosstat_producer_price_indices',
      importer_version: '2.3B-4',
      published_at: '2026-06-30T10:00:00.000Z',
    },
  },
  {
    public_id: '01900000-0000-7000-8000-000000000002',
    classifier_item: {
      public_id: '01900000-0000-7000-8000-000000000102',
      classifier_code: 'okpd2_based',
      item_code: '05.10.10.101.АГ',
      item_name: 'Уголь каменный локальной группировки Росстата',
      provider_code_kind: 'rosstat_local_ag',
    },
    indicator: { code: 'producer_price_index', name: 'Индекс цен производителей' },
    territory: { code: 'RU', name: 'Российская Федерация' },
    frequency: 'monthly',
    comparison_basis: 'previous_month',
    unit: 'percent',
    period: { from: '2024-01', to: '2026-06', observations_count: 30 },
    active_import: {
      public_id: '01900000-0000-7000-8000-000000000901',
      importer_code: 'rosstat_producer_price_indices',
      importer_version: '2.3B-4',
      published_at: '2026-06-30T10:00:00.000Z',
    },
  },
]

function demoPriceIndicesCalculation(requestData: unknown) {
  let payload: Record<string, unknown> = {}
  try {
    payload = typeof requestData === 'string' ? JSON.parse(requestData) : (requestData as Record<string, unknown>)
  } catch {
    payload = {}
  }
  const selected = demoPriceIndicesSeries.find((item) => item.public_id === payload.series_public_id) ?? demoPriceIndicesSeries[0]!
  const start = typeof payload.start_period === 'string' ? payload.start_period : '2024-01'
  const end = typeof payload.end_period === 'string' ? payload.end_period : '2026-06'
  const base = typeof payload.base_amount === 'string' ? payload.base_amount : null
  const samePeriod = start === end
  const isAg = selected.classifier_item.provider_code_kind === 'rosstat_local_ag'
  const factorCount = samePeriod ? 0 : start === '2024-01' && end === '2024-02' ? 1 : start === '2021-01' ? 65 : 29
  const coefficient = samePeriod ? '1.000000000000' : isAg ? '1.010000000000' : '1.123456789012'
  const coefficientRaw = samePeriod ? '1.00000000000000000000' : isAg ? '1.01000000000000000000' : '1.12345678901234567890'
  const chain = samePeriod ? [] : [
    {
      period: start === '2024-01' ? '2024-02' : '2021-02',
      index: isAg ? '101.0000000000' : '100.1900000000',
      factor: isAg ? '1.01000000000000000000' : '1.00190000000000000000',
      running_coefficient: isAg ? '1.010000000000' : '1.001900000000',
      source: { sheet: '16', row: 20374, column: 'D', cell: 'D20374', raw_value: isAg ? '101.0 1)' : '100.19', footnote_marker: isAg ? '1)' : null },
    },
    ...(factorCount > 1 ? [{
      period: end,
      index: '99.8700000000',
      factor: '0.99870000000000000000',
      running_coefficient: coefficient,
      source: { sheet: '16', row: 20402, column: 'D', cell: 'D20402', raw_value: '99.87', footnote_marker: null },
    }] : []),
  ]
  return {
    data: {
      series: selected,
      period: { start, end, interval_semantics: '(start,end]', factors_count: factorCount },
      coefficient_raw: coefficientRaw,
      coefficient,
      amount: base ? { base, adjusted_raw: samePeriod ? base : '745999.16639458999999999999', adjusted: samePeriod ? base : '745999.17' } : null,
      chain,
      provenance: {
        dataset: { public_id: '01900000-0000-7000-8000-000000000801', code: 'rosstat_producer_prices', name: 'Индексы цен производителей по товарам и товарным группам' },
        import: { public_id: selected.active_import.public_id, importer_code: selected.active_import.importer_code, importer_version: selected.active_import.importer_version, published_at: selected.active_import.published_at },
        source_file: { public_id: '01900000-0000-7000-8000-000000000701', original_filename: 'Proizvoditeli_Ind_tov_06-2026.xlsx', sha256: 'f233b55e5d45ef092dd48a3be6574791aa8480104336ffb85f2a423cafb6b691' },
        series: { public_id: selected.public_id },
      },
    },
  }
}

function getSupportChatScenario(url: string): 'default' | 'empty' | 'closed' {
  try {
    const pageSearch = typeof window !== 'undefined' ? window.location.search : ''
    const scenario =
      new URLSearchParams(pageSearch).get('chatScenario') ??
      new URL(url, window.location.origin).searchParams.get('chatScenario')
    if (scenario === 'empty' || scenario === 'closed') return scenario
  } catch {
    // dev-only helper
  }
  return 'default'
}

export function getDevVisualApiMock(method?: string, url?: string, requestData?: unknown): unknown | undefined {
  const methodName = method?.toLowerCase()

  if (!isDevVisualAuthEnabled || !methodName || !url) {
    return undefined
  }

  const path = normalizeApiPath(url)
  const supportChatScenario = getSupportChatScenario(url)

  if (methodName !== 'get') {
    if (methodName === 'post' && path === '/api/indices/calculate') return demoPriceIndicesCalculation(requestData)
    if (/^\/api\/support-chat\/conversations\/\d+\/read$/.test(path)) return {}
    if (/^\/api\/support-chat\/conversations\/\d+\/typing$/.test(path)) return {}
    if (/^\/api\/support-chat\/conversations\/\d+\/messages$/.test(path)) {
      const conversationId = Number(path.match(/^\/api\/support-chat\/conversations\/(\d+)\/messages$/)?.[1] ?? 0)
      return {
        message: {
          id: 9199,
          conversation_id: conversationId,
          sender_id: 1,
          sender_role: 'customer',
          type: 'text',
          body: 'Демо-сообщение для визуальной приемки.',
          meta_json: null,
          is_mine: true,
          sender_display_name: 'UI Acceptance',
          created_at: '2026-04-23T09:40:00.000000Z',
          attachments: [],
        },
      }
    }
    if (/^\/api\/admin\/chat\/conversations\/\d+\/read$/.test(path)) return {}
    if (/^\/api\/admin\/chat\/conversations\/\d+\/typing$/.test(path)) return {}
    if (/^\/api\/admin\/chat\/conversations\/\d+\/assign$/.test(path)) {
      return { assigned_admin_id: 1, assigned_admin: { id: 1, name: 'UI Acceptance' } }
    }
    if (/^\/api\/admin\/chat\/conversations\/\d+\/messages$/.test(path)) {
      const conversationId = Number(path.match(/^\/api\/admin\/chat\/conversations\/(\d+)\/messages$/)?.[1] ?? 0)
      return {
        message: {
          id: 8999,
          conversation_id: conversationId,
          sender_id: 1,
          sender_role: 'admin',
          type: 'text',
          body: 'Демо-ответ для визуальной приемки.',
          meta_json: null,
          is_mine: true,
          sender_display_name: 'UI Acceptance',
          created_at: '2026-04-23T09:40:00.000000Z',
          attachments: [],
        },
      }
    }
    return undefined
  }

  if (path === '/api/me') return devVisualUser
  if (path === '/api/indices/capabilities') {
    return { data: { application: 'price_indices', enabled: true, access: true, admin_only: true, stage: 'skeleton' } }
  }
  if (path === '/api/indices/series') {
    const params = new URL(url, window.location.origin).searchParams
    const code = (params.get('item_code_prefix') ?? params.get('item_code') ?? '').toLocaleUpperCase('ru-RU')
    const name = (params.get('item_name') ?? '').toLocaleLowerCase('ru-RU')
    const filtered = demoPriceIndicesSeries.filter((item) => {
      return (!code || item.classifier_item.item_code.startsWith(code))
        && (!name || item.classifier_item.item_name.toLocaleLowerCase('ru-RU').includes(name))
    })
    return {
      data: filtered,
      meta: { current_page: 1, from: filtered.length ? 1 : null, last_page: 1, path, per_page: 25, to: filtered.length || null, total: filtered.length },
    }
  }
  if (/^\/api\/indices\/series\/[0-9a-f-]+$/i.test(path)) {
    const publicId = path.split('/').pop()
    const selected = demoPriceIndicesSeries.find((item) => item.public_id === publicId)
    return selected ? { data: selected } : undefined
  }
  if (path === '/api/support-chat/conversation') {
    const conversation =
      supportChatScenario === 'empty'
        ? demoSupportChatEmptyConversation
        : supportChatScenario === 'closed'
          ? demoSupportChatClosedConversation
          : demoSupportChatConversation
    return { conversation }
  }
  if (/^\/api\/support-chat\/conversations\/\d+\/messages$/.test(path)) {
    if (supportChatScenario === 'empty') return { messages: [] }
    if (supportChatScenario === 'closed') return { messages: demoSupportChatMessages.slice(0, 3) }
    return { messages: demoSupportChatMessages }
  }
  if (/^\/api\/support-chat\/conversations\/\d+\/typing-status$/.test(path)) return { admin_typing: false }
  if (path === '/api/admin/chat/conversations') {
    const searchParams = new URL(url, window.location.origin).searchParams
    const status = searchParams.get('status')
    const search = (searchParams.get('search') ?? '').toLowerCase()
    const unassigned = searchParams.get('unassigned')
    const filtered = demoAdminChatConversations.filter((conversation) => {
      const statusMatch = !status || conversation.status === status
      const assignmentMatch = !unassigned || conversation.assigned_admin_id === null
      const searchMatch =
        !search ||
        conversation.creator?.name.toLowerCase().includes(search) ||
        conversation.creator?.email.toLowerCase().includes(search) ||
        conversation.subject?.toLowerCase().includes(search)
      return statusMatch && assignmentMatch && searchMatch
    })
    return {
      conversations: filtered,
      pagination: { total: filtered.length, per_page: 20, current_page: 1, last_page: 1 },
    }
  }
  if (/^\/api\/admin\/chat\/conversations\/\d+$/.test(path)) {
    const id = Number(path.match(/^\/api\/admin\/chat\/conversations\/(\d+)$/)?.[1] ?? 0)
    return { conversation: demoAdminChatDetails.find((conversation) => conversation.id === id) ?? demoAdminChatDetails[0] }
  }
  if (/^\/api\/admin\/chat\/conversations\/\d+\/messages$/.test(path)) return { messages: [] }
  if (/^\/api\/admin\/chat\/conversations\/\d+\/typing-status$/.test(path)) return { user_typing: false }
  if (path === '/api/projects') return demoProjects
  if (/^\/api\/projects\/\d+$/.test(path)) return demoProjectDetails
  if (/^\/api\/projects\/\d+\/positions$/.test(path)) return demoProjectPositions
  if (/^\/api\/projects\/\d+\/fittings$/.test(path)) return demoProjectFittings
  if (/^\/api\/projects\/\d+\/expenses$/.test(path)) return demoProjectExpenses
  if (/^\/api\/projects\/\d+\/labor-works$/.test(path)) return { data: demoProjectLaborWorks }
  if (/^\/api\/projects\/\d+\/labor-sources$/.test(path)) return { data: demoLaborSources.slice(0, 2) }
  if (/^\/api\/projects\/\d+\/labor-cost$/.test(path)) {
    return {
      project_id: 101,
      calculated_at: '2026-04-23T09:00:00.000000Z',
      region: { id: 1, name: 'Свердловская область' },
      profiles: [
        {
          labor_profile_id: 701,
          labor_profile_name: 'Столяр-сборщик',
          sources: { used_count: 2, skipped_count: 0, used_sources: [], skipped_sources: [] },
          normalized_rates: [1180, 1320],
          aggregation: { strategy: 'project_sources', method: 'median', base_rate: 1250 },
          model: {
            insurance_rate: 0.3,
            insurance_amount: 375,
            loaded_rate: 1625,
            load_factor: 1.15,
            cost_rate: 1868.75,
            profitability_rate: 0.18,
            profit_amount: 336.38,
            final_rate: 2205.13,
            rounding_scale: 50,
          },
          calculation_breakdown: {
            base_rate: 1250,
            insurance_rate: 0.3,
            insurance_amount: 375,
            loaded_rate: 1625,
            load_factor: 1.15,
            cost_rate: 1868.75,
            profitability_rate: 0.18,
            profit_amount: 336.38,
            final_rate: 2200,
            salary_range_strategy: 'avg',
            aggregation_method: 'median',
            rounding_scale: 50,
          },
          warnings: [],
        },
      ],
      warnings: [],
    }
  }
  if (/^\/api\/projects\/\d+\/revisions\/latest$/.test(path)) return { success: true, revision: demoProjectRevision }
  if (/^\/api\/projects\/\d+\/revisions$/.test(path)) {
    return {
      success: true,
      revisions: [demoProjectRevision],
      pagination: { current_page: 1, last_page: 1, total: 1, per_page: 10 },
    }
  }
  if (/^\/api\/projects\/\d+\/evidence-runs$/.test(path)) return { success: true, data: [demoEvidenceRun] }
  if (/^\/api\/projects\/\d+\/evidence-runs\/\d+$/.test(path)) return { success: true, data: demoEvidenceRun }
  if (path === '/api/system/parser/status') return demoParserSystemStatus
  if (path === '/api/parsing/suppliers/health') return { suppliers: demoParserSuppliers }
  if (path === '/api/parsing/sessions') {
    const searchParams = new URL(url, window.location.origin).searchParams
    const supplier = searchParams.get('supplier')
    const status = searchParams.get('status')
    const filtered = demoParserSessions.filter((session) => {
      return (!supplier || session.supplier === supplier) && (!status || session.status === status)
    })

    return {
      data: filtered,
      meta: { current_page: 1, last_page: 1, per_page: 20, total: filtered.length },
    }
  }
  if (path === '/api/parsing/analytics/chart') return demoParserChartData
  if (/^\/api\/parsing\/suppliers\/[^/]+\/collect-profiles$/.test(path)) {
    return {
      profiles: [
        {
          id: 301,
          supplier_name: path.split('/')[4],
          name: 'Демо-профиль сбора',
          config_override: { category: 'materials' },
          is_default: true,
        },
      ],
    }
  }
  if (path === '/api/price-imports') return { imports: demoImports }
  if (/^\/api\/price-imports\/\d+\/items$/.test(path)) return { items: demoImportItems }
  if (path === '/api/materials/catalog') {
    return {
      data: demoCatalogMaterials,
      meta: { current_page: 1, last_page: 1, per_page: 25, total: demoCatalogMaterials.length, mode: 'my', region_id: 1 },
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
  if (path === '/api/admin/material-dimension-rules') {
    const searchParams = new URL(url, window.location.origin).searchParams
    const isActive = searchParams.get('is_active')
    const status = searchParams.get('status')
    const materialType = searchParams.get('material_type')
    const filtered = demoMaterialDimensionRules.filter((rule) => {
      const activeMatch =
        isActive === null && status === null
          ? true
          : isActive !== null
            ? String(rule.is_active) === isActive
            : status === 'active'
              ? rule.is_active
              : status === 'inactive'
                ? !rule.is_active
                : true
      return activeMatch && (!materialType || rule.material_type === materialType)
    })
    return { data: filtered, meta: { current_page: 1, last_page: 1, per_page: 25, total: filtered.length } }
  }
  if (path === '/api/admin/material-type-patterns') {
    const searchParams = new URL(url, window.location.origin).searchParams
    const status = searchParams.get('status')
    const materialType = searchParams.get('material_type')
    const filtered = demoMaterialTypePatterns.filter((pattern) => {
      const activeMatch = status === 'active' ? pattern.is_active : status === 'inactive' ? !pattern.is_active : true
      return activeMatch && (!materialType || pattern.material_type === materialType)
    })
    return { data: filtered, meta: { current_page: 1, last_page: 1, per_page: 25, total: filtered.length } }
  }
  if (path === '/api/admin/material-dimension-failures') {
    const searchParams = new URL(url, window.location.origin).searchParams
    const status = searchParams.get('status')
    const filtered = status === 'resolved' ? [] : demoMaterialDimensionFailures
    return { data: filtered, meta: { current_page: 1, last_page: 1, per_page: 5, total: filtered.length } }
  }
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
  if (path === '/api/units') return ['шт.', 'м²', 'м.п.', 'п.м.', 'компл.']
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
