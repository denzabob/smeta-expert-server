<template>
  <v-card variant="outlined" class="mt-5">
    <v-card-title>Профили задач</v-card-title>
    <v-card-text>
      <v-alert v-if="loadError" type="error" variant="tonal" class="mb-3">{{ loadError }}</v-alert>
      <div class="admin-llm-profile__tabs" role="tablist" aria-label="Профиль Expert">
        <button
          v-for="option in profileOptions"
          :key="option.value"
          type="button"
          class="admin-llm-profile__tab"
          :class="{ 'admin-llm-profile__tab--active': profileTask === option.value, 'admin-llm-profile__tab--legacy': option.value === 'expert_chat' }"
          role="tab"
          :aria-selected="profileTask === option.value"
          @click="selectProfile(option.value)"
        >{{ option.title }}</button>
      </div>

      <v-card variant="tonal" :loading="loading" class="admin-llm-profile__summary">
        <v-card-title class="d-flex align-center flex-wrap ga-2">
          <span>Эксперт — {{ profileTitle }}</span>
          <v-chip v-if="profile" size="small" :color="profileStatus === 'Включён' ? 'success' : 'warning'">{{ profileStatus }}</v-chip>
          <v-spacer />
          <v-btn variant="tonal" color="primary" size="small" @click="editing = !editing">{{ editing ? 'Свернуть' : 'Редактировать' }}</v-btn>
        </v-card-title>
        <v-card-text v-if="profile">
          <div class="admin-llm-profile__summary-grid">
            <div>
              <div class="admin-llm-profile__summary-label">Основная модель</div>
              <strong class="admin-llm-profile__model-name">{{ modelDisplayName(profile.effective.model) }}</strong>
              <span class="admin-llm-profile__model-meta">{{ profile.effective.provider }} · {{ profile.effective.model }}</span>
            </div>
            <div>
              <div class="admin-llm-profile__summary-label">Резерв</div>
              <strong class="admin-llm-profile__model-name">{{ fallbackSummary.title }}</strong>
              <span v-if="fallbackSummary.detail" class="admin-llm-profile__model-meta">{{ fallbackSummary.detail }}</span>
            </div>
            <div>
              <div class="admin-llm-profile__summary-label">Параметры ответа</div>
              <strong class="admin-llm-profile__model-name">{{ reasoningLabel(profile?.configured?.reasoning_effort) }}</strong>
              <span class="admin-llm-profile__model-meta">{{ profile?.configured?.max_output_tokens ? `до ${profile.configured.max_output_tokens.toLocaleString('ru-RU')} токенов` : 'Длина по умолчанию' }} · T {{ profile?.configured?.temperature ?? 'по умолчанию' }}</span>
            </div>
          </div>
          <div class="text-caption mt-3">Источник: {{ profile.source }} · Ключ провайдера: {{ profile.provider_key_source }} · Каталог: {{ catalogLabel(profile.catalog_status) }}</div>
          <div class="admin-llm-profile__capability-title">Возможности основной модели</div>
          <div class="d-flex flex-wrap ga-1">
            <v-chip v-for="capability in capabilityLabels" :key="capability.key" size="small" :color="profile.capabilities[capability.key] ? 'success' : 'default'" variant="tonal">
              {{ capability.label }} {{ capabilityMark(profile.capabilities, capability.key, profile.effective.provider, profile.catalog_status, profile.model_in_catalog) }}
            </v-chip>
          </div>
          <div v-if="fallbackSummary.isProfile" class="admin-llm-profile__fallback-status" :class="{ 'admin-llm-profile__fallback-status--warning': fallbackCompatibility && !fallbackCompatibility.compatible }">
            <template v-if="fallbackCompatibility?.missing.length">Резервная модель не поддерживает: {{ fallbackCompatibility.missing.join(', ') }}</template>
            <template v-else-if="fallbackCompatibility?.unknown">Совместимость резерва неизвестна: проверьте каталог.</template>
            <template v-else-if="fallbackCompatibility?.compatible">Резерв: совместим ✓</template>
            <template v-else>Совместимость резерва будет проверена после загрузки модели.</template>
          </div>
        </v-card-text>
      </v-card>

      <v-card variant="outlined" class="mt-4">
        <v-card-title>Автоматический режим</v-card-title>
        <v-card-text>
          <div class="admin-llm-profile__policy-line"><span class="admin-llm-profile__policy-enabled">Включён</span><span>·</span><span>По умолчанию: Быстро</span></div>
          <div class="text-body-2">Простые запросы и извлечение фактов → Быстро. Сложный анализ, сравнение и полный охват материалов → Глубокий.</div>
          <details class="admin-llm-profile__policy-details">
            <summary>Подробнее</summary>
            <div>Режим выбирается по структуре контекста запроса и не меняет явный выбор «Быстро» или «Глубокий».</div>
          </details>
        </v-card-text>
      </v-card>

      <div v-if="editing" class="admin-llm-profile__form mt-4">
        <v-alert v-if="saveError" type="error" variant="tonal" class="mb-3">{{ saveError }}</v-alert>
        <section class="admin-llm-profile__section">
          <h3>Основная модель</h3>
          <v-row>
            <v-col cols="12" md="4"><v-select v-model="draft.provider" :items="providers" item-title="title" item-value="value" label="Провайдер" variant="outlined" /></v-col>
            <v-col cols="12" md="5"><v-text-field v-model="draft.model" label="Основная модель" variant="outlined" hint="Технический ID модели можно оставить, даже если её нет в каталоге" persistent-hint /></v-col>
            <v-col cols="12" md="3"><v-switch v-model="draft.enabled" color="primary" label="Профиль включён" hide-details /></v-col>
          </v-row>
        </section>

        <section class="admin-llm-profile__section">
          <h3>Резервирование</h3>
          <div class="admin-llm-profile__fallback-options" role="radiogroup" aria-label="Стратегия резервирования">
            <label v-for="option in fallbackStrategyOptions" :key="option.value" class="admin-llm-profile__radio" :class="{ 'admin-llm-profile__radio--active': fallbackStrategy === option.value }">
              <input v-model="fallbackStrategy" type="radio" name="expert-fallback-strategy" :value="option.value" />
              <span>{{ option.title }}</span>
            </label>
          </div>
          <v-row v-if="fallbackStrategy === 'profile'" class="mt-2">
            <v-col cols="12" md="6"><v-select v-model="draft.fallback_provider" :items="providers" item-title="title" item-value="value" label="Провайдер резерва" variant="outlined" /></v-col>
            <v-col cols="12" md="6"><v-text-field v-model="draft.fallback_model" label="Резервная модель" variant="outlined" hint="Технический ID резервной модели" persistent-hint /></v-col>
          </v-row>
        </section>

        <section class="admin-llm-profile__section">
          <h3>Параметры ответа</h3>
          <v-row>
            <v-col cols="12" md="4"><v-select v-model="draft.reasoning_effort" :items="reasoningOptions" label="Усилие рассуждения" variant="outlined" clearable hint="Чем выше значение, тем больше вычислений модель может использовать для сложного анализа." persistent-hint /></v-col>
            <v-col cols="12" md="4"><v-text-field v-model.number="draft.max_output_tokens" label="Макс. длина ответа" type="number" variant="outlined" /></v-col>
            <v-col cols="12" md="4"><v-text-field v-model.number="draft.temperature" label="Температура" type="number" min="0" max="2" step="0.1" variant="outlined" /></v-col>
          </v-row>
        </section>

        <v-alert v-if="preview && !preview.provider_configured" type="warning" variant="tonal" class="mb-3">Провайдер недоступен: API key не настроен.</v-alert>
        <v-alert v-if="preview && draft.provider === 'routerai' && catalogStatus !== 'unavailable' && !preview.model_in_catalog" type="warning" variant="tonal" class="mb-3">Модель не найдена в текущем каталоге. Сохранённый ID останется без изменений.</v-alert>
        <div v-if="preview" class="admin-llm-profile__preview-block mb-3">
          <div class="text-subtitle-2 mb-1">Возможности основной модели</div>
          <div class="d-flex flex-wrap ga-1">
            <v-chip v-for="capability in capabilityLabels" :key="capability.key" size="small" :color="preview.capabilities[capability.key] ? 'success' : 'default'" variant="tonal">
              {{ capability.label }} {{ capabilityMark(preview.capabilities, capability.key, draft.provider, catalogStatus, preview.model_in_catalog) }}
            </v-chip>
          </div>
        </div>
        <div v-if="fallbackStrategy === 'profile' && fallbackPreview" class="admin-llm-profile__preview-block mb-3">
          <div class="text-subtitle-2 mb-1">Возможности резервной модели</div>
          <div class="d-flex flex-wrap ga-1">
            <v-chip v-for="capability in capabilityLabels" :key="`fallback-${capability.key}`" size="small" :color="fallbackPreview.capabilities[capability.key] ? 'success' : 'default'" variant="tonal">
              {{ capability.label }} {{ capabilityMark(fallbackPreview.capabilities, capability.key, draft.fallback_provider, catalogStatus, fallbackPreview.model_in_catalog) }}
            </v-chip>
          </div>
        </div>
        <v-alert v-if="preview && capabilityMark(preview.capabilities, 'image_input', draft.provider, catalogStatus, preview.model_in_catalog) === '—'" type="warning" variant="tonal" density="compact" class="mb-2">Эта модель не сможет анализировать изображения.</v-alert>
        <v-alert v-if="preview && capabilityMark(preview.capabilities, 'pdf_ocr', draft.provider, catalogStatus, preview.model_in_catalog) === '—'" type="warning" variant="tonal" density="compact" class="mb-2">Для сканированных PDF выбранный профиль не поддерживает OCR. Текстовые PDF обрабатываются после локального извлечения текста.</v-alert>
        <v-alert v-if="preview && capabilityMark(preview.capabilities, 'streaming', draft.provider, catalogStatus, preview.model_in_catalog) === '—'" type="warning" variant="tonal" density="compact" class="mb-2">Потоковая выдача не поддерживается. Expert получит полный ответ через синхронный запрос.</v-alert>
        <v-alert v-if="preview && draft.provider === 'routerai' && (catalogStatus !== 'fresh' || !preview.model_in_catalog)" type="info" variant="tonal" density="compact" class="mb-2">? — возможность неизвестна: каталог недоступен или модель отсутствует в нём.</v-alert>

        <div v-if="catalogVisible" class="mt-4">
          <div class="d-flex align-center flex-wrap ga-2 mb-2">
            <div class="text-subtitle-1">Каталог RouterAI</div>
            <v-chip size="small" :color="catalogStatus === 'fresh' ? 'success' : 'warning'">{{ catalogLabel(catalogStatus) }}</v-chip>
            <v-spacer />
            <v-btn size="small" variant="tonal" :loading="refreshing" @click="refreshCatalog">Обновить каталог</v-btn>
          </div>
          <v-alert v-if="catalogError" type="warning" variant="tonal" density="compact" class="mb-2">{{ catalogError }}</v-alert>
          <v-row dense>
            <v-col cols="12" md="8"><v-text-field v-model="search" label="Поиск модели" variant="outlined" density="compact" clearable prepend-inner-icon="mdi-magnify" /></v-col>
            <v-col cols="12" md="4"><v-select v-model="sort" :items="sortOptions" item-title="title" item-value="value" label="Сортировка по стоимости" variant="outlined" density="compact" /></v-col>
          </v-row>
          <div class="d-flex flex-wrap ga-2 mb-2">
            <v-checkbox-btn v-for="filter in filterOptions" :key="filter.value" v-model="filters" :value="filter.value" :label="filter.title" density="compact" />
          </div>
          <v-progress-linear v-if="catalogLoading" indeterminate color="primary" />
          <v-list v-else class="border rounded" max-height="340" style="overflow-y: auto">
              <v-list-item v-for="model in models" :key="model.id" :active="draft.model === model.id">
                <v-list-item-title>{{ model.display_name || model.id }}</v-list-item-title>
                <v-list-item-subtitle>{{ model.id }} · контекст {{ model.context_length ?? '—' }}</v-list-item-subtitle>
              <div class="d-flex flex-wrap ga-1 mt-1">
                <v-chip v-for="capability in capabilityLabels.filter(item => model.capabilities[item.key])" :key="capability.key" size="x-small" variant="tonal">{{ capability.label }}</v-chip>
              </div>
              <div v-if="model.pricing" class="text-caption text-medium-emphasis mt-2">
                <div class="d-flex flex-wrap ga-x-3 ga-y-1">
                  <span v-for="item in pricingItems(model)" :key="item.key"><strong>{{ item.label }}:</strong> {{ item.value }}</span>
                </div>
                <div v-if="typicalRequestCost(model) !== null" class="mt-1">
                  Оценка типового запроса 20K вход + 2K выход: <strong>≈ {{ formatRub(typicalRequestCost(model)!) }} ₽</strong>
                  </div>
                </div>
                <div class="admin-llm-profile__catalog-actions">
                  <v-btn size="x-small" variant="tonal" color="primary" @click.stop="selectCatalogModel(model, 'primary')">Выбрать основной</v-btn>
                  <v-btn v-if="fallbackStrategy === 'profile'" size="x-small" variant="text" @click.stop="selectCatalogModel(model, 'fallback')">Выбрать резервной</v-btn>
                </div>
              </v-list-item>
            <v-list-item v-if="!models.length" title="Модели не найдены" />
          </v-list>
          <v-btn v-if="models.length < catalogTotal" class="mt-2" size="small" variant="text" :loading="catalogLoading" @click="loadCatalog(true)">Показать ещё</v-btn>
        </div>

        <div class="d-flex align-center flex-wrap ga-2 mt-4">
          <v-btn variant="text" :disabled="saving" @click="cancelEditing">Отмена</v-btn>
          <v-btn color="primary" :loading="saving" :disabled="!dirty || !draft.model || !preview?.provider_configured" @click="saveProfile">Сохранить</v-btn>
          <v-chip v-if="dirty" color="warning" size="small">Есть несохранённые изменения</v-chip>
        </div>

        <v-divider class="my-4" />
        <div class="text-subtitle-1 mb-2">Проверки выбранного провайдера и модели</div>
        <div class="d-flex flex-wrap ga-2">
          <v-btn v-for="test in smokeKinds" :key="test.value" size="small" variant="tonal" :loading="testingKind === test.value" :disabled="!!testingKind || !draft.model || !preview?.provider_configured" @click="runSmoke(test.value)">{{ test.title }}</v-btn>
        </div>
        <div class="text-caption mt-2">PDF/OCR проверка может вызвать небольшие расходы у провайдера. Проверки используют встроенные материалы и не сохраняют сообщения Expert.</div>
        <v-alert v-for="test in smokeKinds.filter(item => smokeResults[item.value])" :key="test.value" :type="smokeResults[test.value]?.status === 'PASS' ? 'success' : 'error'" variant="tonal" density="compact" class="mt-2">
          {{ test.title }}: {{ smokeResults[test.value]?.status }} · {{ smokeResults[test.value]?.checked_at }} · {{ smokeResults[test.value]?.latency_ms }} ms
          <span v-if="smokeResults[test.value]?.ttft_ms != null"> · TTFT {{ smokeResults[test.value]?.ttft_ms }} ms</span>
          <span v-if="smokeResults[test.value]?.error_code"> · {{ smokeResults[test.value]?.error_code }}</span>
        </v-alert>
      </div>
    </v-card-text>
    <v-snackbar v-model="saveNoticeOpen" :timeout="2600">{{ saveNotice }}</v-snackbar>
  </v-card>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import api from '@/api/axios'

type Capabilities = Record<string, boolean>
interface ProfileResponse {
  configured: { provider: string; model: string; enabled: boolean; fallback_policy: 'none' | 'global'; fallback_enabled?: boolean; fallback_provider?: string | null; fallback_model?: string | null; reasoning_effort?: 'low' | 'medium' | 'high' | null; max_output_tokens?: number | null; temperature?: number | null } | null
  effective: { provider: string; model: string }
  source: string
  provider_key_source: string
  capabilities: Capabilities
  catalog_status: string
  model_in_catalog?: boolean | null
  fallback_policy: 'none' | 'global'
}
interface ModelRecord {
  id: string
  display_name: string | null
  context_length: number | null
  pricing: Record<string, string | number> | null
  pricing_units: Record<string, string | number> | null
  capabilities: Capabilities
}
interface SmokeResult { status: 'PASS' | 'FAIL'; checked_at: string; latency_ms: number; ttft_ms: number | null; error_code: string | null }

defineProps<{ providers: Array<{ value: string; title: string }> }>()
const capabilityLabels = [
  { key: 'text_input', label: 'Текст' }, { key: 'streaming', label: 'Streaming' },
  { key: 'image_input', label: 'Изображения' }, { key: 'file_input', label: 'Файлы' },
  { key: 'pdf_ocr', label: 'Скан PDF OCR' }, { key: 'reasoning', label: 'Reasoning' },
  { key: 'tools', label: 'Tools' }, { key: 'structured_output', label: 'Structured output' },
]
type FallbackStrategy = 'none' | 'profile' | 'global'
type ProfileTask = 'expert_chat' | 'expert_fast' | 'expert_deep'
const fallbackStrategyOptions: Array<{ title: string; value: FallbackStrategy }> = [
  { title: 'Без резерва', value: 'none' }, { title: 'Резерв профиля', value: 'profile' }, { title: 'Глобальная цепочка', value: 'global' },
]
const reasoningOptions = [{ title: 'Низкое', value: 'low' }, { title: 'Среднее', value: 'medium' }, { title: 'Высокое', value: 'high' }]
const profileOptions: Array<{ title: string; value: ProfileTask }> = [
  { title: 'Fast', value: 'expert_fast' },
  { title: 'Deep', value: 'expert_deep' },
  { title: 'Legacy', value: 'expert_chat' },
]
const filterOptions = [
  { title: 'Совместимые с Expert', value: 'compatible' }, { title: 'Vision', value: 'vision' },
  { title: 'Streaming', value: 'streaming' }, { title: 'Reasoning', value: 'reasoning' },
  { title: 'Tools', value: 'tools' }, { title: 'Structured output', value: 'structured_output' },
]
const smokeKinds = [
  { title: 'Текст', value: 'text' }, { title: 'Streaming', value: 'streaming' },
  { title: 'Vision', value: 'vision' }, { title: 'PDF/OCR', value: 'pdf_ocr' },
]
const sortOptions = [
  { title: 'Порядок каталога', value: 'catalog' },
  { title: 'Дешевле — типовой запрос', value: 'typical_cost' },
  { title: 'Дешевле — вход', value: 'input_cost' },
  { title: 'Дешевле — выход', value: 'output_cost' },
]
const loading = ref(false)
const loadError = ref('')
const editing = ref(false)
const profileTask = ref<ProfileTask>('expert_fast')
const profile = ref<ProfileResponse | null>(null)
const draft = reactive({ provider: '', model: '', enabled: true, fallback_policy: 'none' as 'none' | 'global', fallback_enabled: false, fallback_provider: '', fallback_model: '', reasoning_effort: null as 'low' | 'medium' | 'high' | null, max_output_tokens: null as number | null, temperature: null as number | null })
const profileTitle = computed(() => profileTask.value === 'expert_chat' ? 'Legacy' : profileTask.value === 'expert_fast' ? 'Fast' : 'Deep')
const profileStatus = computed(() => profile.value?.configured ? (profile.value.configured.enabled ? 'Включён' : 'Выключен') : 'Не настроен')
const fallbackStrategy = computed<FallbackStrategy>({
  get: () => draft.fallback_enabled ? 'profile' : draft.fallback_policy === 'global' ? 'global' : 'none',
  set: (value) => {
    draft.fallback_enabled = value === 'profile'
    draft.fallback_policy = value === 'global' ? 'global' : 'none'
  },
})
const fallbackSummary = computed(() => {
  const configured = profile.value?.configured
  if (configured?.fallback_enabled && configured.fallback_provider && configured.fallback_model) {
    return { title: modelDisplayName(configured.fallback_model), detail: `${configured.fallback_provider} · ${configured.fallback_model}`, isProfile: true }
  }
  if (profile.value?.fallback_policy === 'global' || configured?.fallback_policy === 'global') {
    return { title: 'Глобальная цепочка', detail: 'Наследуется из общих настроек', isProfile: false }
  }
  return { title: 'Без резерва', detail: '', isProfile: false }
})
const catalogVisible = computed(() => draft.provider === 'routerai' || (fallbackStrategy.value === 'profile' && draft.fallback_provider === 'routerai'))
const savedDraft = ref('')
const dirty = computed(() => JSON.stringify(draft) !== savedDraft.value)
const preview = ref<{ capabilities: Capabilities; model_in_catalog: boolean | null; provider_configured: boolean } | null>(null)
const fallbackPreview = ref<{ capabilities: Capabilities; model_in_catalog: boolean | null; provider_configured: boolean } | null>(null)
const fallbackCompatibility = computed(() => {
  if (!preview.value || !fallbackPreview.value || fallbackStrategy.value !== 'profile') return null
  const required = capabilityLabels.filter((capability) => preview.value?.capabilities[capability.key])
  const missing = required.filter((capability) => capabilityMark(fallbackPreview.value!.capabilities, capability.key, draft.fallback_provider, catalogStatus.value, fallbackPreview.value!.model_in_catalog) === '—').map((capability) => capability.label)
  const unknown = required.some((capability) => capabilityMark(fallbackPreview.value!.capabilities, capability.key, draft.fallback_provider, catalogStatus.value, fallbackPreview.value!.model_in_catalog) === '?')
  return { compatible: missing.length === 0 && !unknown, missing, unknown }
})
const saving = ref(false)
const saveError = ref('')
const saveNoticeOpen = ref(false)
const saveNotice = ref('')
const search = ref('')
const sort = ref<'catalog' | 'typical_cost' | 'input_cost' | 'output_cost'>('catalog')
const filters = ref<string[]>(['compatible'])
const models = ref<ModelRecord[]>([])
const catalogTotal = ref(0)
const catalogPage = ref(1)
const catalogStatus = ref('unavailable')
const catalogError = ref('')
const catalogLoading = ref(false)
const refreshing = ref(false)
const testingKind = ref<string | null>(null)
const smokeResults = reactive<Record<string, SmokeResult>>({})
let searchTimer: ReturnType<typeof setTimeout> | undefined
let previewSequence = 0
let catalogSequence = 0

function catalogLabel(status: string): string {
  return ({ fresh: 'Актуален', stale: 'Кэш, RouterAI недоступен', unavailable: 'Недоступен', unsupported: 'Нет каталога' } as Record<string, string>)[status] || status
}
function capabilityMark(capabilities: Capabilities, key: string, provider: string, status: string, inCatalog: boolean | null | undefined): string {
  if (capabilities[key]) return '✓'
  return provider === 'routerai' && (status !== 'fresh' || inCatalog === false) ? '?' : '—'
}
function selectProfile(task: ProfileTask): void {
  if (profileTask.value !== task) profileTask.value = task
}
function modelDisplayName(modelId: string): string {
  return models.value.find((model) => model.id === modelId)?.display_name || modelId
}
function reasoningLabel(value: 'low' | 'medium' | 'high' | null | undefined): string {
  return value === 'low' ? 'Низкое усилие' : value === 'medium' ? 'Среднее усилие' : value === 'high' ? 'Высокое усилие' : 'По умолчанию'
}
const pricingNames: Record<string, string> = {
  prompt: 'Вход', input: 'Вход', completion: 'Выход', output: 'Выход',
  input_cache_read: 'Кэш read', cache_read: 'Кэш read',
  input_cache_write: 'Кэш write', cache_write: 'Кэш write',
  web_search: 'Web search', request: 'Запрос',
}
function numericPricing(model: ModelRecord, key: string): number | null {
  const raw = model.pricing?.[key]
  if (raw === undefined || raw === null || raw === '') return null
  const value = Number(raw)
  return Number.isFinite(value) && value >= 0 ? value : null
}
function formatRub(value: number): string {
  const abs = Math.abs(value)
  const maximumFractionDigits = abs >= 100 ? 2 : abs >= 1 ? 2 : 4
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits }).format(value)
}
function pricingItems(model: ModelRecord): Array<{ key: string; label: string; value: string }> {
  return Object.keys(model.pricing || {}).flatMap((key) => {
    const price = numericPricing(model, key)
    if (price === null) return []
    const unit = String(model.pricing_units?.[key] ?? '').toLowerCase()
    const label = pricingNames[key] || key
    if (unit === 'token') return [{ key, label, value: `${formatRub(price * 1_000_000)} ₽ / 1M токенов` }]
    if (unit === 'request') return [{ key, label, value: `${formatRub(price)} ₽ / запрос` }]
    return [{ key, label, value: unit ? `${formatRub(price)} ₽ / ${unit}` : `${formatRub(price)} ₽ (единица не указана)` }]
  })
}
function typicalRequestCost(model: ModelRecord): number | null {
  const inputKey = model.pricing?.prompt != null ? 'prompt' : (model.pricing?.input != null ? 'input' : null)
  const outputKey = model.pricing?.completion != null ? 'completion' : (model.pricing?.output != null ? 'output' : null)
  if (!inputKey || !outputKey || model.pricing_units?.[inputKey]?.toString().toLowerCase() !== 'token' || model.pricing_units?.[outputKey]?.toString().toLowerCase() !== 'token') return null
  const input = numericPricing(model, inputKey)
  const output = numericPricing(model, outputKey)
  return input === null || output === null ? null : input * 20_000 + output * 2_000
}
async function loadProfile(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    const url = profileTask.value === 'expert_chat' ? '/api/admin/llm-profiles/expert-chat' : `/api/admin/llm-profiles/expert-chat?task=${encodeURIComponent(profileTask.value)}`
    const { data } = await api.get<ProfileResponse>(url)
    profile.value = data
    const configured = data.configured ?? { provider: data.effective.provider, model: data.effective.model, enabled: profileTask.value !== 'expert_deep', fallback_policy: data.fallback_policy ?? 'none', fallback_enabled: false, fallback_provider: '', fallback_model: '', reasoning_effort: null, max_output_tokens: null, temperature: null }
    Object.assign(draft, { ...configured, fallback_provider: configured.fallback_provider ?? '', fallback_model: configured.fallback_model ?? '', reasoning_effort: configured.reasoning_effort ?? null, max_output_tokens: configured.max_output_tokens ?? null, temperature: configured.temperature ?? null })
    savedDraft.value = JSON.stringify(draft)
    preview.value = null
    fallbackPreview.value = null
  } catch {
    loadError.value = 'Не удалось загрузить профиль Expert.'
  } finally {
    loading.value = false
  }
}
async function loadPreview(): Promise<void> {
  const sequence = ++previewSequence
  if (!draft.provider || !draft.model) { preview.value = null; fallbackPreview.value = null; return }
  preview.value = null
  fallbackPreview.value = null
  try {
    const { data } = await api.get('/api/admin/llm-profiles/expert-chat/preview', { params: { provider: draft.provider, model: draft.model } })
    if (sequence === previewSequence) preview.value = data
  } catch {
    if (sequence === previewSequence) preview.value = null
  }
  if (sequence !== previewSequence || fallbackStrategy.value !== 'profile' || !draft.fallback_provider || !draft.fallback_model) return
  try {
    const { data } = await api.get('/api/admin/llm-profiles/expert-chat/preview', { params: { provider: draft.fallback_provider, model: draft.fallback_model } })
    if (sequence === previewSequence) fallbackPreview.value = data
  } catch {
    if (sequence === previewSequence) fallbackPreview.value = null
  }
}
async function loadCatalog(more = false): Promise<void> {
  const sequence = ++catalogSequence
  catalogLoading.value = true
  catalogError.value = ''
  const page = more ? catalogPage.value + 1 : 1
  try {
    const { data } = await api.get('/api/admin/llm-model-catalog/routerai', { params: { q: search.value || undefined, filter: filters.value, sort: sort.value, page } })
    if (sequence !== catalogSequence) return
    models.value = more ? [...models.value, ...data.models] : data.models
    catalogPage.value = page
    catalogTotal.value = data.total
    catalogStatus.value = data.status
    if (data.error_code) catalogError.value = data.status === 'stale' ? 'RouterAI недоступен. Показан последний сохранённый каталог.' : 'Не удалось загрузить каталог RouterAI.'
  } catch {
    if (sequence === catalogSequence) catalogError.value = 'Не удалось загрузить каталог RouterAI.'
  } finally {
    if (sequence === catalogSequence) catalogLoading.value = false
  }
}
async function refreshCatalog(): Promise<void> {
  refreshing.value = true
  try {
    await api.post('/api/admin/llm-model-catalog/routerai/refresh')
    await loadCatalog()
    await loadPreview()
  } catch {
    catalogError.value = 'Не удалось обновить каталог RouterAI.'
  } finally {
    refreshing.value = false
  }
}
function selectCatalogModel(model: ModelRecord, target: 'primary' | 'fallback'): void {
  if (target === 'primary') {
    draft.provider = 'routerai'
    draft.model = model.id
    return
  }
  draft.fallback_enabled = true
  draft.fallback_policy = 'none'
  draft.fallback_provider = 'routerai'
  draft.fallback_model = model.id
}
function cancelEditing(): void {
  try { Object.assign(draft, JSON.parse(savedDraft.value)) } catch { /* Keep the current draft if the snapshot is unavailable. */ }
  saveError.value = ''
  editing.value = false
  void loadPreview()
}
async function saveProfile(): Promise<void> {
  saving.value = true
  saveError.value = ''
  try {
    const url = profileTask.value === 'expert_chat' ? '/api/admin/llm-profiles/expert-chat' : `/api/admin/llm-profiles/expert-chat?task=${encodeURIComponent(profileTask.value)}`
    const payload = {
      provider: draft.provider,
      model: draft.model,
      enabled: draft.enabled,
      fallback_policy: fallbackStrategy.value === 'global' ? 'global' : 'none',
      fallback_enabled: fallbackStrategy.value === 'profile',
      fallback_provider: fallbackStrategy.value === 'profile' ? draft.fallback_provider || null : null,
      fallback_model: fallbackStrategy.value === 'profile' ? draft.fallback_model || null : null,
      reasoning_effort: draft.reasoning_effort,
      max_output_tokens: draft.max_output_tokens,
      temperature: draft.temperature,
    }
    const { data } = await api.put<ProfileResponse>(url, payload)
    profile.value = data
    Object.assign(draft, { ...payload, fallback_provider: payload.fallback_provider ?? '', fallback_model: payload.fallback_model ?? '' })
    savedDraft.value = JSON.stringify(draft)
    saveNotice.value = `Профиль ${profileTitle.value} сохранён`
    saveNoticeOpen.value = true
  } catch {
    saveError.value = 'Не удалось сохранить профиль. Проверьте провайдера и Model ID.'
  } finally {
    saving.value = false
  }
}
async function runSmoke(kind: string): Promise<void> {
  testingKind.value = kind
  delete smokeResults[kind]
  try {
    const { data } = await api.post<SmokeResult>('/api/admin/llm-profiles/expert-chat/smoke', { provider: draft.provider, model: draft.model, kind })
    smokeResults[kind] = data
  } catch {
    smokeResults[kind] = { status: 'FAIL', checked_at: new Date().toISOString(), latency_ms: 0, ttft_ms: null, error_code: 'provider_connection_failed' }
  } finally {
    testingKind.value = null
  }
}
watch(() => [draft.provider, draft.model, fallbackStrategy.value, draft.fallback_provider, draft.fallback_model], () => {
  void loadPreview()
  for (const key of Object.keys(smokeResults)) delete smokeResults[key]
})
watch(() => [search.value, sort.value, ...filters.value, draft.provider, fallbackStrategy.value, draft.fallback_provider], () => {
  if (searchTimer) clearTimeout(searchTimer)
  if (catalogVisible.value) searchTimer = setTimeout(() => { void loadCatalog() }, 250)
})
watch(profileTask, async () => { editing.value = false; await loadProfile(); if (catalogVisible.value) await loadCatalog() })
onMounted(async () => { await loadProfile(); if (catalogVisible.value) await loadCatalog() })
onBeforeUnmount(() => { if (searchTimer) clearTimeout(searchTimer) })
</script>

<style scoped>
.admin-llm-profile__tabs { display: flex; align-items: center; gap: 4px; margin: 0 0 16px; border-bottom: 1px solid rgba(var(--v-theme-outline), .18); }
.admin-llm-profile__tab { min-height: 36px; padding: 0 14px; border: 0; border-bottom: 2px solid transparent; color: rgba(var(--v-theme-on-surface-variant), .78); background: transparent; cursor: pointer; font: inherit; font-size: .82rem; font-weight: 700; }
.admin-llm-profile__tab:hover, .admin-llm-profile__tab:focus-visible { color: rgb(var(--v-theme-primary)); }
.admin-llm-profile__tab--active { border-bottom-color: rgb(var(--v-theme-primary)); color: rgb(var(--v-theme-primary)); }
.admin-llm-profile__tab--legacy { margin-left: auto; opacity: .68; }
.admin-llm-profile__summary-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; }
.admin-llm-profile__summary-label { color: rgba(var(--v-theme-on-surface-variant), .72); font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.admin-llm-profile__model-name, .admin-llm-profile__model-meta { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.admin-llm-profile__model-name { margin-top: 3px; font-size: 1rem; }
.admin-llm-profile__model-meta { color: rgba(var(--v-theme-on-surface-variant), .72); font-size: .75rem; }
.admin-llm-profile__capability-title { margin: 16px 0 6px; color: rgba(var(--v-theme-on-surface), .82); font-size: .78rem; font-weight: 700; }
.admin-llm-profile__fallback-status { margin-top: 12px; color: rgb(var(--v-theme-success)); font-size: .78rem; font-weight: 700; }
.admin-llm-profile__fallback-status--warning { color: rgb(var(--v-theme-error)); }
.admin-llm-profile__policy-line { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; font-size: .83rem; font-weight: 700; }
.admin-llm-profile__policy-enabled { color: rgb(var(--v-theme-success)); }
.admin-llm-profile__policy-details { margin-top: 10px; color: rgba(var(--v-theme-on-surface-variant), .78); font-size: .78rem; }
.admin-llm-profile__policy-details summary { width: fit-content; cursor: pointer; color: rgb(var(--v-theme-primary)); font-weight: 700; }
.admin-llm-profile__policy-details div { margin-top: 6px; }
.admin-llm-profile__section { margin-bottom: 16px; padding: 14px 16px 4px; border: 1px solid rgba(var(--v-theme-outline), .2); border-radius: var(--md-sys-shape-corner-medium); background: rgba(var(--v-theme-surface-container-low), .48); }
.admin-llm-profile__section h3 { margin: 0 0 12px; font-size: .95rem; }
.admin-llm-profile__fallback-options { display: flex; flex-wrap: wrap; gap: 8px; }
.admin-llm-profile__radio { display: inline-flex; align-items: center; gap: 7px; min-height: 36px; padding: 6px 10px; border: 1px solid rgba(var(--v-theme-outline), .28); border-radius: 999px; color: rgba(var(--v-theme-on-surface), .82); cursor: pointer; font-size: .8rem; }
.admin-llm-profile__radio--active { border-color: rgba(var(--v-theme-primary), .5); color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .08); }
.admin-llm-profile__radio input { accent-color: rgb(var(--v-theme-primary)); }
.admin-llm-profile__preview-block { padding: 10px 12px; border-radius: var(--md-sys-shape-corner-small); background: rgba(var(--v-theme-surface-container-low), .7); }
.admin-llm-profile__catalog-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
@media (max-width: 900px) { .admin-llm-profile__summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 600px) { .admin-llm-profile__tab { padding-inline: 10px; } .admin-llm-profile__tab--legacy { margin-left: 0; } .admin-llm-profile__summary-grid { grid-template-columns: 1fr; gap: 14px; } .admin-llm-profile__section { padding-inline: 12px; } }
</style>
