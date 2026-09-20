<template>
  <v-card variant="outlined" class="mt-5">
    <v-card-title>Профили задач</v-card-title>
    <v-card-text>
      <v-alert v-if="loadError" type="error" variant="tonal" class="mb-3">{{ loadError }}</v-alert>
      <v-card variant="tonal" :loading="loading">
        <v-card-title class="d-flex align-center flex-wrap ga-2">
          Эксперт — Чат
          <v-chip v-if="profile" size="small" color="info">{{ profile.source }}</v-chip>
          <v-spacer />
          <v-btn variant="tonal" color="primary" size="small" @click="editing = !editing">{{ editing ? 'Закрыть' : 'Настроить' }}</v-btn>
        </v-card-title>
        <v-card-text v-if="profile">
          <div>{{ profile.effective.provider }} / <strong>{{ profile.effective.model }}</strong></div>
          <div class="text-caption">Ключ провайдера: {{ profile.provider_key_source }} · Каталог: {{ catalogLabel(profile.catalog_status) }}</div>
          <div class="d-flex flex-wrap ga-1 mt-2">
            <v-chip v-for="capability in capabilityLabels" :key="capability.key" size="small" :color="profile.capabilities[capability.key] ? 'success' : 'default'" variant="tonal">
              {{ capability.label }} {{ profile.capabilities[capability.key] ? '✓' : '—' }}
            </v-chip>
          </div>
        </v-card-text>
      </v-card>

      <div v-if="editing" class="mt-4">
        <v-alert v-if="saveError" type="error" variant="tonal" class="mb-3">{{ saveError }}</v-alert>
        <v-row>
          <v-col cols="12" md="4">
            <v-select v-model="draft.provider" :items="providers" item-title="title" item-value="value" label="Провайдер" variant="outlined" />
          </v-col>
          <v-col cols="12" md="8">
            <v-text-field v-model="draft.model" label="Model ID" variant="outlined" hint="Сохранённую модель можно оставить, даже если её нет в каталоге" persistent-hint />
          </v-col>
          <v-col cols="12" md="4">
            <v-switch v-model="draft.enabled" color="primary" label="Профиль включён" hide-details />
          </v-col>
          <v-col cols="12" md="8">
            <v-select v-model="draft.fallback_policy" :items="fallbackOptions" label="Fallback" variant="outlined" density="compact" />
          </v-col>
        </v-row>

        <v-alert v-if="preview && !preview.provider_configured" type="warning" variant="tonal" class="mb-3">Провайдер недоступен: API key не настроен.</v-alert>
        <v-alert v-if="preview && draft.provider === 'routerai' && catalogStatus !== 'unavailable' && !preview.model_in_catalog" type="warning" variant="tonal" class="mb-3">Модель не найдена в текущем каталоге. Сохранённый ID останется без изменений.</v-alert>
        <div v-if="preview" class="d-flex flex-wrap ga-1 mb-3">
          <v-chip v-for="capability in capabilityLabels" :key="capability.key" size="small" :color="preview.capabilities[capability.key] ? 'success' : 'default'" variant="tonal">
            {{ capability.label }} {{ preview.capabilities[capability.key] ? '✓' : '—' }}
          </v-chip>
        </div>
        <v-alert v-if="preview && !preview.capabilities.image_input" type="warning" variant="tonal" density="compact" class="mb-2">Эта модель не сможет анализировать изображения.</v-alert>
        <v-alert v-if="preview && !preview.capabilities.pdf_ocr" type="warning" variant="tonal" density="compact" class="mb-2">Для сканированных PDF выбранный профиль не поддерживает OCR. Текстовые PDF обрабатываются после локального извлечения текста.</v-alert>
        <v-alert v-if="preview && !preview.capabilities.streaming" type="warning" variant="tonal" density="compact" class="mb-2">Потоковая выдача не поддерживается. Expert получит полный ответ через синхронный запрос.</v-alert>

        <div v-if="draft.provider === 'routerai'" class="mt-4">
          <div class="d-flex align-center flex-wrap ga-2 mb-2">
            <div class="text-subtitle-1">Каталог RouterAI</div>
            <v-chip size="small" :color="catalogStatus === 'fresh' ? 'success' : 'warning'">{{ catalogLabel(catalogStatus) }}</v-chip>
            <v-spacer />
            <v-btn size="small" variant="tonal" :loading="refreshing" @click="refreshCatalog">Обновить каталог</v-btn>
          </div>
          <v-alert v-if="catalogError" type="warning" variant="tonal" density="compact" class="mb-2">{{ catalogError }}</v-alert>
          <v-row dense>
            <v-col cols="12" md="8">
              <v-text-field v-model="search" label="Поиск модели" variant="outlined" density="compact" clearable prepend-inner-icon="mdi-magnify" />
            </v-col>
            <v-col cols="12" md="4">
              <v-select v-model="sort" :items="sortOptions" item-title="title" item-value="value" label="Сортировка по стоимости" variant="outlined" density="compact" />
            </v-col>
          </v-row>
          <div class="d-flex flex-wrap ga-2 mb-2">
            <v-checkbox-btn v-for="filter in filterOptions" :key="filter.value" v-model="filters" :value="filter.value" :label="filter.title" density="compact" />
          </div>
          <v-progress-linear v-if="catalogLoading" indeterminate color="primary" />
          <v-list v-else class="border rounded" max-height="340" style="overflow-y: auto">
            <v-list-item v-for="model in models" :key="model.id" :active="draft.model === model.id" @click="draft.model = model.id">
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
            </v-list-item>
            <v-list-item v-if="!models.length" title="Модели не найдены" />
          </v-list>
          <v-btn v-if="models.length < catalogTotal" class="mt-2" size="small" variant="text" :loading="catalogLoading" @click="loadCatalog(true)">Показать ещё</v-btn>
        </div>

        <div class="d-flex align-center flex-wrap ga-2 mt-4">
          <v-btn color="primary" :loading="saving" :disabled="!draft.model || !preview?.provider_configured" @click="saveProfile">Сохранить профиль</v-btn>
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
  </v-card>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import api from '@/api/axios'

type Capabilities = Record<string, boolean>
interface ProfileResponse {
  configured: { provider: string; model: string; enabled: boolean; fallback_policy: 'none' | 'global' } | null
  effective: { provider: string; model: string }
  source: string
  provider_key_source: string
  capabilities: Capabilities
  catalog_status: string
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
const fallbackOptions = [{ title: 'Без fallback', value: 'none' }, { title: 'Глобальная цепочка', value: 'global' }]
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
  { title: 'Дешевле: типовой запрос', value: 'typical_cost' },
  { title: 'Дешевле: вход', value: 'input_cost' },
  { title: 'Дешевле: выход', value: 'output_cost' },
]
const loading = ref(false)
const loadError = ref('')
const editing = ref(false)
const profile = ref<ProfileResponse | null>(null)
const draft = reactive({ provider: '', model: '', enabled: true, fallback_policy: 'none' as 'none' | 'global' })
const savedDraft = ref('')
const dirty = computed(() => JSON.stringify(draft) !== savedDraft.value)
const preview = ref<{ capabilities: Capabilities; model_in_catalog: boolean | null; provider_configured: boolean } | null>(null)
const saving = ref(false)
const saveError = ref('')
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
const pricingNames: Record<string, string> = {
  prompt: 'Вход',
  input: 'Вход',
  completion: 'Выход',
  output: 'Выход',
  input_cache_read: 'Кэш read',
  cache_read: 'Кэш read',
  input_cache_write: 'Кэш write',
  cache_write: 'Кэш write',
  web_search: 'Web search',
  request: 'Запрос',
}

function numericPricing(model: ModelRecord, key: string): number | null {
  const raw = model.pricing?.[key]
  const value = typeof raw === 'number' ? raw : Number(raw)
  return Number.isFinite(value) ? value : null
}

function formatRub(value: number): string {
  const abs = Math.abs(value)
  const maximumFractionDigits = abs >= 100 ? 0 : abs >= 10 ? 1 : abs >= 1 ? 2 : 4
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits }).format(value)
}

function pricingItems(model: ModelRecord): Array<{ key: string; label: string; value: string }> {
  return Object.keys(model.pricing || {}).flatMap((key) => {
    const raw = numericPricing(model, key)
    if (raw === null) return []
    const unit = String(model.pricing_units?.[key] ?? '').toLowerCase()
    const label = pricingNames[key] || key
    if (unit === 'token') {
      return [{ key, label, value: `${formatRub(raw * 1_000_000)} ₽ / 1M токенов` }]
    }
    if (unit === 'request') {
      return [{ key, label, value: `${formatRub(raw)} ₽ / запрос` }]
    }
    return [{ key, label, value: unit ? `${formatRub(raw)} ₽ / ${unit}` : `${formatRub(raw)} ₽` }]
  }).slice(0, 6)
}

function typicalRequestCost(model: ModelRecord): number | null {
  const inputKey = model.pricing?.prompt != null ? 'prompt' : (model.pricing?.input != null ? 'input' : null)
  const outputKey = model.pricing?.completion != null ? 'completion' : (model.pricing?.output != null ? 'output' : null)
  if (!inputKey || !outputKey) return null
  if (String(model.pricing_units?.[inputKey] ?? '').toLowerCase() !== 'token') return null
  if (String(model.pricing_units?.[outputKey] ?? '').toLowerCase() !== 'token') return null
  const input = numericPricing(model, inputKey)
  const output = numericPricing(model, outputKey)
  return input === null || output === null ? null : input * 20_000 + output * 2_000
}
async function loadProfile(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    const { data } = await api.get<ProfileResponse>('/api/admin/llm-profiles/expert-chat')
    profile.value = data
    Object.assign(draft, data.configured || { provider: data.effective.provider, model: data.effective.model, enabled: true, fallback_policy: 'none' })
    savedDraft.value = JSON.stringify(draft)
  } catch {
    loadError.value = 'Не удалось загрузить профиль Expert.'
  } finally {
    loading.value = false
  }
}
async function loadPreview(): Promise<void> {
  const sequence = ++previewSequence
  if (!draft.provider || !draft.model) { preview.value = null; return }
  try {
    const { data } = await api.get('/api/admin/llm-profiles/expert-chat/preview', { params: { provider: draft.provider, model: draft.model } })
    if (sequence === previewSequence) preview.value = data
  } catch {
    if (sequence === previewSequence) preview.value = null
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
async function saveProfile(): Promise<void> {
  saving.value = true
  saveError.value = ''
  try {
    const { data } = await api.put<ProfileResponse>('/api/admin/llm-profiles/expert-chat', { ...draft })
    profile.value = data
    savedDraft.value = JSON.stringify(draft)
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
watch(() => [draft.provider, draft.model], () => {
  void loadPreview()
  for (const key of Object.keys(smokeResults)) delete smokeResults[key]
})
watch(() => [search.value, sort.value, ...filters.value, draft.provider], () => {
  if (searchTimer) clearTimeout(searchTimer)
  if (draft.provider === 'routerai') searchTimer = setTimeout(() => { void loadCatalog() }, 250)
})
onMounted(async () => { await loadProfile(); if (draft.provider === 'routerai') await loadCatalog() })
onBeforeUnmount(() => { if (searchTimer) clearTimeout(searchTimer) })
</script>
