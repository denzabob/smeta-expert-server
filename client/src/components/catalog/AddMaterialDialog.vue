<template>
  <v-dialog v-model="dialog" max-width="750" persistent>
    <v-card>
      <v-card-title class="d-flex align-center">
        <v-icon class="mr-2">mdi-plus-circle</v-icon>
        Добавить материал
        <v-spacer />
        <v-btn icon="mdi-close" variant="text" @click="close" />
      </v-card-title>

      <v-card-text class="pb-0">
        <v-stepper v-model="step" :items="stepItems" flat hide-actions>
          <!-- Step 1: URL + Type + Domain Check -->
          <template #item.1>
            <div class="pa-4">
              <v-alert type="info" variant="tonal" density="compact" class="mb-4">
                Вставьте ссылку на материал — система проверит наличие правил парсинга и заполнит данные автоматически.
              </v-alert>

              <v-text-field
                v-model="form.source_url"
                label="URL источника *"
                placeholder="https://example.com/product/..."
                prepend-inner-icon="mdi-link"
                :rules="[rules.required, rules.url]"
                :error-messages="urlError"
                :loading="domainStatus === 'checking'"
                autofocus
                @blur="onUrlBlur"
                @keyup.enter="doParse"
              />

              <!-- Domain check result -->
              <v-fade-transition>
                <v-alert
                  v-if="domainStatus === 'supported'"
                  type="success"
                  variant="tonal"
                  density="compact"
                  class="mb-3"
                >
                  <div class="d-flex align-center">
                    <v-icon class="mr-2">mdi-check-circle</v-icon>
                    <div>
                      <div class="font-weight-medium">Домен поддерживается</div>
                      <div class="text-caption">
                        Источник: {{ domainSourceLabel }}.
                        Доступные поля: {{ domainFieldsLabel }}.
                        <span v-if="detectedType">Определён тип: <strong>{{ typeLabel(detectedType) }}</strong></span>
                      </div>
                    </div>
                  </div>
                </v-alert>
                <v-alert
                  v-else-if="domainStatus === 'unsupported'"
                  type="warning"
                  variant="tonal"
                  density="compact"
                  class="mb-3"
                >
                  <div class="font-weight-medium mb-1">Для данного домена нет правил парсинга</div>
                  <div class="text-body-2">
                    Система попытается извлечь данные из метаданных страницы (schema.org, microdata).
                    Для более точного извлечения воспользуйтесь
                    <strong>Chrome-плагином</strong>, который сохранит CSS-селекторы для этого сайта.
                  </div>
                  <div class="text-body-2 mt-1">
                    Также вы можете заполнить данные <strong>вручную</strong> — при этом trust score будет минимальным.
                  </div>
                </v-alert>
              </v-fade-transition>

              <v-fade-transition>
                <v-alert
                  v-if="parseNotice"
                  :type="parseNotice.type"
                  variant="tonal"
                  density="compact"
                  class="mb-3"
                >
                  <div class="font-weight-medium mb-1">{{ parseNotice.title }}</div>
                  <div class="text-body-2">{{ parseNotice.message }}</div>
                  <div v-if="parseNotice.details" class="text-caption mt-1">{{ parseNotice.details }}</div>
                </v-alert>
              </v-fade-transition>

              <v-row dense>
                <v-col cols="12" sm="6">
                  <v-select
                    v-model="form.type"
                    :items="typeOptions"
                    item-title="label"
                    item-value="value"
                    label="Тип материала *"
                    :rules="[rules.required]"
                    :hint="typeAutoHint"
                    :persistent-hint="!!typeAutoHint"
                  />
                </v-col>
                <v-col cols="12" sm="6">
                  <v-select
                    v-model="form.observation_region_id"
                    :items="regionOptions"
                    item-title="label"
                    item-value="value"
                    label="Регион цены"
                    clearable
                  />
                </v-col>
              </v-row>

              <!-- Manual mode toggle -->
              <v-checkbox
                v-if="domainStatus === 'unsupported'"
                v-model="manualMode"
                label="Заполнить данные вручную (без парсинга)"
                density="compact"
                class="mt-2"
                hide-details
              />

              <!-- Duplicate candidates -->
              <v-alert v-if="duplicates.length > 0" type="warning" variant="tonal" class="mt-4">
                <div class="font-weight-medium mb-2">Найдены похожие материалы:</div>
                <v-list density="compact" class="bg-transparent">
                  <v-list-item
                    v-for="dup in duplicates"
                    :key="dup.material.id"
                    :title="dup.material.name"
                    :subtitle="dupReason(dup.reason)"
                  >
                    <template #append>
                      <v-btn
                        size="small"
                        color="primary"
                        variant="tonal"
                        @click="useExisting(dup.material)"
                      >
                        Использовать
                      </v-btn>
                    </template>
                  </v-list-item>
                </v-list>
              </v-alert>
            </div>
          </template>

          <!-- Step 2: Data Fields (type-aware) -->
          <template #item.2>
            <div class="pa-4">
              <v-alert v-if="parseConfidence > 0 && parseConfidence < 50" type="warning" variant="tonal" density="compact" class="mb-4">
                Данные получены с низкой точностью ({{ parseConfidence }}%). Проверьте и исправьте.
              </v-alert>
              <v-alert v-if="manualMode" type="info" variant="tonal" density="compact" class="mb-4">
                <v-icon start size="small">mdi-alert-circle</v-icon>
                Ручной ввод данных. Trust score будет минимальным.
              </v-alert>

              <!-- Type indicator -->
              <v-chip
                :color="typeColor(form.type)"
                variant="tonal"
                size="small"
                class="mb-3"
              >
                {{ typeLabel(form.type) }}
              </v-chip>

              <!-- Common fields -->
              <v-row dense>
                <v-col cols="12">
                  <v-text-field v-model="form.name" label="Название *" :rules="[rules.required]" />
                </v-col>
                <v-col cols="12" sm="6">
                  <v-text-field v-model="form.article" label="Артикул" />
                </v-col>
                <v-col cols="12" sm="3">
                  <v-select
                    v-model="form.unit"
                    :items="unitOptions"
                    label="Ед. изм. *"
                    :rules="[rules.required]"
                  />
                </v-col>
                <v-col cols="12" sm="3">
                  <v-text-field
                    v-model.number="form.price_per_unit"
                    label="Цена *"
                    type="number"
                    :rules="[rules.required, rules.positive]"
                    suffix="₽"
                  />
                </v-col>
              </v-row>

              <!-- Dimensions: Plate/Facade -->
              <template v-if="form.type === 'plate' || form.type === 'facade'">
                <div class="text-subtitle-2 font-weight-bold mb-2 mt-2">
                  <v-icon size="small" class="mr-1">mdi-ruler</v-icon>
                  Размеры
                </div>
                <v-row dense>
                  <v-col cols="4">
                    <v-text-field
                      v-model.number="form.thickness"
                      label="Толщина (мм)"
                      type="number"
                      hide-details
                    />
                  </v-col>
                  <v-col cols="4">
                    <v-text-field
                      v-model.number="form.length_mm"
                      label="Длина (мм)"
                      type="number"
                      hide-details
                    />
                  </v-col>
                  <v-col cols="4">
                    <v-text-field
                      v-model.number="form.width_mm"
                      label="Ширина (мм)"
                      type="number"
                      hide-details
                    />
                  </v-col>
                </v-row>
              </template>

              <!-- Dimensions: Edge -->
              <template v-else-if="form.type === 'edge'">
                <div class="text-subtitle-2 font-weight-bold mb-2 mt-2">
                  <v-icon size="small" class="mr-1">mdi-ruler</v-icon>
                  Размеры кромки
                </div>
                <v-row dense>
                  <v-col cols="6">
                    <v-text-field
                      v-model.number="form.length_mm"
                      label="Ширина кромки (мм)"
                      type="number"
                      hint="Хранится в поле length_mm"
                      persistent-hint
                    />
                  </v-col>
                  <v-col cols="6">
                    <v-text-field
                      v-model.number="form.width_mm"
                      label="Толщина кромки (мм)"
                      type="number"
                      hint="Хранится в поле width_mm"
                      persistent-hint
                    />
                  </v-col>
                </v-row>
              </template>

              <!-- Hardware: no dimensions -->
              <v-alert
                v-else-if="form.type === 'hardware'"
                type="info"
                variant="tonal"
                density="compact"
                class="mt-2"
              >
                Для фурнитуры размеры не требуются
              </v-alert>

              <!-- Additional fields (non-hardware) -->
              <v-expansion-panels v-if="form.type !== 'hardware'" variant="accordion" class="mt-3">
                <v-expansion-panel title="Дополнительные поля">
                  <v-expansion-panel-text>
                    <v-row dense>
                      <v-col cols="12" sm="4">
                        <v-text-field
                          v-model.number="form.waste_factor"
                          label="Коэф. расхода"
                          type="number"
                          step="0.01"
                        />
                      </v-col>
                      <v-col cols="12" sm="4">
                        <v-text-field v-model="form.material_tag" label="Тег" />
                      </v-col>
                      <v-col cols="12" sm="4">
                        <v-select
                          v-model="form.visibility"
                          :items="visibilityOptions"
                          item-title="label"
                          item-value="value"
                          label="Видимость"
                        />
                      </v-col>
                    </v-row>
                  </v-expansion-panel-text>
                </v-expansion-panel>
              </v-expansion-panels>

              <!-- Hardware: just tag + visibility inline -->
              <v-row v-if="form.type === 'hardware'" dense class="mt-2">
                <v-col cols="6">
                  <v-text-field v-model="form.material_tag" label="Тег" />
                </v-col>
                <v-col cols="6">
                  <v-select
                    v-model="form.visibility"
                    :items="visibilityOptions"
                    item-title="label"
                    item-value="value"
                    label="Видимость"
                  />
                </v-col>
              </v-row>

              <!-- Source URL (readonly) -->
              <v-text-field
                v-model="form.source_url"
                label="URL источника *"
                :disabled="!manualMode"
                :rules="[rules.required, rules.url]"
                class="mt-3"
                prepend-inner-icon="mdi-link"
              />
            </div>
          </template>
        </v-stepper>
      </v-card-text>

      <v-divider />

      <!-- Footer actions — single set of navigation buttons -->
      <v-card-actions class="pa-4">
        <v-btn v-if="step > 1" variant="text" @click="step--">
          <v-icon start>mdi-arrow-left</v-icon> Назад
        </v-btn>
        <v-spacer />
        <v-btn variant="text" @click="close">Отмена</v-btn>

        <!-- Step 1 actions -->
        <template v-if="step === 1">
          <!-- Parse button (auto mode) -->
          <v-btn
            v-if="!manualMode"
            color="primary"
            :loading="parsing"
            :disabled="!canParse"
            @click="doParse"
          >
            <v-icon start>mdi-magnify</v-icon>
            Найти данные
          </v-btn>
          <!-- Manual mode: go to step 2 -->
          <v-btn
            v-if="manualMode"
            color="primary"
            :disabled="!form.source_url || !form.type"
            @click="goToManualStep2"
          >
            Далее
            <v-icon end>mdi-arrow-right</v-icon>
          </v-btn>
          <!-- After parse success with duplicates: next button -->
          <v-btn
            v-if="!parsing && parseResult && !manualMode"
            color="primary"
            @click="step = 2"
          >
            Далее
            <v-icon end>mdi-arrow-right</v-icon>
          </v-btn>
        </template>

        <!-- Step 2: Save -->
        <v-btn
          v-if="step === 2"
          color="primary"
          :loading="saving"
          :disabled="!isFormValid"
          @click="save"
        >
          <v-icon start>mdi-content-save</v-icon>
          Сохранить
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue'
import { useMaterialCatalogStore } from '@/stores/materialCatalog'
import type { MaterialType, MaterialUnit, StoreMaterialPayload, CatalogMaterial } from '@/api/materialCatalog'

const props = defineProps<{
  modelValue: boolean
  initialFlow?: 'url' | 'manual'
  defaultRegionId?: number | null
  regions?: Array<{ id: number; region_name: string }>
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', val: boolean): void
  (e: 'created', material: any): void
  (e: 'use-existing', material: CatalogMaterial): void
}>()

const store = useMaterialCatalogStore()

const dialog = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const step = ref(1)
const saving = ref(false)
const urlError = ref('')
const parseResult = ref<any>(null)
const parseConfidence = ref(0)
const duplicates = ref<any[]>([])
const manualMode = ref(false)
const detectedType = ref<MaterialType | null>(null)
const parseNotice = ref<null | {
  type: 'info' | 'warning' | 'error' | 'success'
  title: string
  message: string
  details?: string
}>(null)

// Domain check state
const domainStatus = ref<'unchecked' | 'checking' | 'supported' | 'unsupported'>('unchecked')
const domainFields = ref<string[]>([])
const domainSource = ref<string | null>(null)
let lastCheckedDomain = ''

const form = reactive<StoreMaterialPayload & { observation_region_id?: number | null }>({
  name: '',
  article: '',
  type: 'plate',
  unit: 'м²',
  price_per_unit: 0,
  source_url: '',
  data_origin: 'manual',
  visibility: 'private',
  observation_region_id: props.defaultRegionId ?? null,
  observation_source_type: 'manual',
  parse_session_id: null,
  thickness: null,
  waste_factor: null,
  length_mm: null,
  width_mm: null,
  material_tag: null,
})

const parsing = computed(() => store.parsing)

const rules = {
  required: (v: any) => !!v || v === 0 || 'Обязательное поле',
  url: (v: string) => !v || /^https?:\/\/.+/.test(v) || 'Некорректный URL',
  positive: (v: number) => v >= 0 || 'Должно быть >= 0',
}

const typeOptions = [
  { label: 'Плита', value: 'plate' },
  { label: 'Кромка', value: 'edge' },
  { label: 'Фасад', value: 'facade' },
  { label: 'Фурнитура', value: 'hardware' },
]

const unitOptions = ['м²', 'м.п.', 'шт']

const visibilityOptions = [
  { label: 'Только мне', value: 'private' },
  { label: 'Публичный', value: 'public' },
]

const regionOptions = computed(() =>
  (props.regions || []).map(r => ({ label: r.region_name, value: r.id }))
)

const stepItems = [
  { title: 'Ссылка', value: 1 },
  { title: 'Данные', value: 2 },
]

const typeUnitMap: Record<string, string> = {
  plate: 'м²',
  edge: 'м.п.',
  hardware: 'шт',
  facade: 'м²',
}

const canParse = computed(() =>
  !!form.source_url && !!form.type && /^https?:\/\/.+/.test(form.source_url)
)

const isFormValid = computed(() =>
  !!form.name
  && !!form.type
  && !!form.unit
  && form.price_per_unit >= 0
  && !!form.source_url
  && /^https?:\/\/.+/.test(form.source_url)
)

const typeAutoHint = computed(() => {
  if (detectedType.value && detectedType.value !== form.type) {
    return `Система определила тип: ${typeLabel(detectedType.value)}`
  }
  return ''
})

const domainSourceLabel = computed(() => {
  const map: Record<string, string> = {
    chrome_ext: 'Chrome-плагин',
    system: 'Системный парсер',
    parser_config: 'Конфигурация парсера',
  }
  return map[domainSource.value || ''] || domainSource.value || 'неизвестно'
})

const domainFieldsLabel = computed(() => {
  const map: Record<string, string> = {
    title: 'название',
    price: 'цена',
    article: 'артикул',
    name: 'название',
    price_meta: 'цена (мета)',
    price_text: 'цена (текст)',
  }
  return domainFields.value.map(f => map[f] || f).join(', ') || '—'
})

// === Helpers ===

function typeLabel(t: string): string {
  const map: Record<string, string> = { plate: 'Плита', edge: 'Кромка', facade: 'Фасад', hardware: 'Фурнитура' }
  return map[t] || t
}

function typeColor(t: string): string {
  const map: Record<string, string> = { plate: 'blue', edge: 'orange', facade: 'purple', hardware: 'teal' }
  return map[t] || 'grey'
}

function dupReason(reason: string): string {
  const map: Record<string, string> = {
    exact_url: 'Совпадение URL',
    article_type: 'Совпадение артикула',
    name_similarity: 'Похожее название',
  }
  return map[reason] || reason
}

function extractDomain(url: string): string {
  try {
    return new URL(url).hostname.replace(/^www\./, '')
  } catch {
    return ''
  }
}

// === Domain Check ===

async function onUrlBlur() {
  if (!form.source_url || !/^https?:\/\/.+/.test(form.source_url)) return

  const domain = extractDomain(form.source_url)
  if (!domain || domain === lastCheckedDomain) return
  lastCheckedDomain = domain

  domainStatus.value = 'checking'
  manualMode.value = false

  try {
    const result = await store.checkDomainSupport(form.source_url)
    domainStatus.value = result.supported ? 'supported' : 'unsupported'
    domainFields.value = result.selector_fields || []
    domainSource.value = result.source
    detectedType.value = result.detected_type || null

    // Auto-apply detected type
    if (result.detected_type) {
      form.type = result.detected_type
      const autoUnit = typeUnitMap[result.detected_type]
      if (autoUnit) {
        form.unit = autoUnit as MaterialUnit
      }
    }
  } catch {
    domainStatus.value = 'unsupported'
  }
}

// === Parse ===

async function doParse() {
  if (!form.source_url || !form.type) return
  urlError.value = ''
  duplicates.value = []
  parseNotice.value = null

  try {
    const result = await store.parseUrl(form.source_url, form.type as MaterialType, form.observation_region_id)
    parseResult.value = result

    if (result.data) {
      if (result.data.name) form.name = result.data.name
      if (result.data.article) form.article = result.data.article
      if (result.data.price_per_unit) form.price_per_unit = result.data.price_per_unit
      if (result.data.unit) form.unit = result.data.unit
      form.data_origin = 'url_parse'
      form.observation_source_type = 'web'
      form.parse_session_id = result.parse_session_id
      parseConfidence.value = result.confidence
    }

    const parseStatus = result.parse_status || 'ok'
    const parseSourceMap: Record<string, string> = {
      selectors: 'селекторы домена',
      generic: 'универсальный парсер',
    }
    const sourceLabel = parseSourceMap[result.parse_source || ''] || (result.parse_source || 'парсер')
    const missing = (result.diagnostics?.missing_fields || []).join(', ')

    if (parseStatus === 'no_fields') {
      parseNotice.value = {
        type: 'warning',
        title: 'Данные не извлечены',
        message: result.message || 'Парсер не смог найти поля на странице.',
        details: `Источник: ${sourceLabel}.${missing ? ` Не найдены поля: ${missing}.` : ''}`,
      }
      return
    }

    if (parseStatus === 'partial') {
      parseNotice.value = {
        type: 'info',
        title: 'Извлечение частично успешно',
        message: result.message || 'Часть полей не найдена, проверьте значения перед сохранением.',
        details: `Источник: ${sourceLabel}.${missing ? ` Не найдены поля: ${missing}.` : ''}`,
      }
    } else if (parseStatus === 'blocked' || parseStatus === 'error') {
      parseNotice.value = {
        type: 'error',
        title: 'Не удалось получить данные',
        message: result.message || 'Ошибка парсинга страницы.',
        details: `Источник: ${sourceLabel}.`,
      }
      return
    } else {
      parseNotice.value = {
        type: 'success',
        title: 'Данные получены',
        message: result.message || 'Поля успешно извлечены.',
        details: `Источник: ${sourceLabel}.`,
      }
    }

    if (result.duplicates && result.duplicates.length > 0) {
      duplicates.value = result.duplicates
    } else {
      step.value = 2
    }
  } catch (e: any) {
    urlError.value = e.response?.data?.message || 'Ошибка парсинга'
  }
}

function goToManualStep2() {
  form.data_origin = 'manual'
  form.observation_source_type = 'manual'
  parseConfidence.value = 0
  const manualUnit = typeUnitMap[form.type]
  if (manualUnit) {
    form.unit = manualUnit as MaterialUnit
  }
  step.value = 2
}

function useExisting(material: CatalogMaterial) {
  emit('use-existing', material)
  close()
}

// === Save ===

async function save() {
  if (!isFormValid.value) return
  saving.value = true
  try {
    const material = await store.createMaterial(form as StoreMaterialPayload)
    emit('created', material)
    close()
  } catch {
    // Error handled by store
  } finally {
    saving.value = false
  }
}

function close() {
  dialog.value = false
  step.value = 1
  parseResult.value = null
  duplicates.value = []
  parseConfidence.value = 0
  domainStatus.value = 'unchecked'
  domainFields.value = []
  domainSource.value = null
  detectedType.value = null
  parseNotice.value = null
  manualMode.value = false
  lastCheckedDomain = ''
  Object.assign(form, {
    name: '', article: '', type: 'plate' as MaterialType, unit: 'м²' as MaterialUnit,
    price_per_unit: 0, source_url: '', data_origin: 'manual',
    visibility: 'private', observation_region_id: props.defaultRegionId ?? null,
    observation_source_type: 'manual', parse_session_id: null,
    thickness: null, waste_factor: null, length_mm: null, width_mm: null,
    material_tag: null,
  })
}

// === Watchers ===

// Auto-set unit when type changes
watch(() => form.type, (newType) => {
  if (newType && typeUnitMap[newType]) {
    form.unit = typeUnitMap[newType] as MaterialUnit
  }
  if (newType === 'hardware') {
    form.thickness = null
    form.length_mm = null
    form.width_mm = null
    form.waste_factor = null
  }
  if (newType === 'edge') {
    form.thickness = null
  }
})

watch(() => props.defaultRegionId, (v) => {
  form.observation_region_id = v ?? null
})

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    const manual = props.initialFlow === 'manual'
    manualMode.value = manual
    step.value = manual ? 2 : 1
    if (manual) {
      form.data_origin = 'manual'
      form.observation_source_type = 'manual'
      parseResult.value = null
      parseConfidence.value = 0
      duplicates.value = []
      domainStatus.value = 'unchecked'
      domainFields.value = []
      domainSource.value = null
      detectedType.value = null
      parseNotice.value = null
      lastCheckedDomain = ''
    }
  }
)
</script>
