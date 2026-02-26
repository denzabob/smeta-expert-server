<template>
  <v-container fluid class="supplier-config">
    <v-row>
      <v-col cols="12">
        <div class="d-flex align-center">
          <v-btn icon="mdi-arrow-left" variant="text" @click="goBack" />
          <div class="ml-2">
            <div class="text-h5">Настройки поставщика</div>
            <div class="text-subtitle-2 text-grey-darken-1">{{ supplier }}</div>
          </div>
          <v-spacer />
          <v-chip v-if="configSource" size="small" class="mr-3" color="info" variant="tonal">
            Источник: {{ configSource === 'db' ? 'БД' : 'Файл' }}
          </v-chip>
          <v-btn color="primary" variant="elevated" :loading="saving" :disabled="!form" @click="save">
            Сохранить
          </v-btn>
        </div>
      </v-col>

      <v-col cols="12" v-if="error">
        <v-alert type="error" variant="tonal">{{ error }}</v-alert>
      </v-col>

      <v-col cols="12" md="6" v-if="form">
        <v-card elevation="2">
          <v-card-title>Основные параметры</v-card-title>
          <v-divider />
          <v-card-text>
            <v-text-field
              v-model="form.display_name"
              label="Отображаемое имя"
              variant="outlined"
              density="compact"
            />
            <v-text-field
              v-model="form.base_url"
              label="Base URL"
              variant="outlined"
              density="compact"
            />
            <v-text-field
              v-model="form.adapter_class"
              label="Adapter class"
              variant="outlined"
              density="compact"
            />
            <v-switch v-model="form.enabled" label="Включен"  color="primary" />
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6" v-if="form">
        <v-card elevation="2">
          <v-card-title>Типы и единицы</v-card-title>
          <v-divider />
          <v-card-text>
            <v-select
              v-model="form.default_type"
              :items="defaultTypeOptions"
              label="Тип по умолчанию"
              variant="outlined"
              density="compact"
            />
            <v-select
              v-model="form.default_unit"
              :items="unitOptions"
              label="Единица по умолчанию"
              variant="outlined"
              density="compact"
            />

            <div class="text-subtitle-2 mb-2 mt-4">Материал → единица</div>
            <div v-for="(pair, index) in unitMappingPairs" :key="`unit-${index}`" class="d-flex gap-2 mb-2">
              <v-text-field v-model="pair.key" label="Ключ" variant="outlined" density="compact" />
              <v-text-field v-model="pair.value" label="Значение" variant="outlined" density="compact" />
              <v-btn icon="mdi-delete" variant="text" @click="removePair(unitMappingPairs, index)" />
            </div>
            <v-btn size="small" variant="text" prepend-icon="mdi-plus" @click="addPair(unitMappingPairs)">
              Добавить
            </v-btn>

            <div class="text-subtitle-2 mb-2 mt-6">Тип материала → категория</div>
            <div v-for="(pair, index) in typeMappingPairs" :key="`type-${index}`" class="d-flex gap-2 mb-2">
              <v-text-field v-model="pair.key" label="Ключ" variant="outlined" density="compact" />
              <v-text-field v-model="pair.value" label="Значение" variant="outlined" density="compact" />
              <v-btn icon="mdi-delete" variant="text" @click="removePair(typeMappingPairs, index)" />
            </div>
            <v-btn size="small" variant="text" prepend-icon="mdi-plus" @click="addPair(typeMappingPairs)">
              Добавить
            </v-btn>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" v-if="form">
        <v-card elevation="2">
          <v-card-title>Селекторы</v-card-title>
          <v-divider />
          <v-card-text>
            <div v-for="(pair, index) in selectorPairs" :key="`sel-${index}`" class="d-flex gap-2 mb-2">
              <v-text-field v-model="pair.key" label="Ключ" variant="outlined" density="compact" />
              <v-text-field v-model="pair.value" label="Селектор" variant="outlined" density="compact" />
              <v-btn icon="mdi-delete" variant="text" @click="removePair(selectorPairs, index)" />
            </div>
            <v-btn size="small" variant="text" prepend-icon="mdi-plus" @click="addPair(selectorPairs)">
              Добавить
            </v-btn>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6" v-if="form">
        <v-card elevation="2">
          <v-card-title>Тайминги и сеть</v-card-title>
          <v-divider />
          <v-card-text>
            <v-text-field
              v-model.number="form.delays.between_requests"
              label="Пауза между запросами (сек)"
              type="number"
              step="0.1"
              variant="outlined"
              density="compact"
            />
            <v-text-field
              v-model.number="form.delays.page_load_timeout"
              label="Таймаут загрузки (мс)"
              type="number"
              step="100"
              variant="outlined"
              density="compact"
            />
            <v-text-field
              v-model.number="form.delays.page_load_retries"
              label="Повторов загрузки"
              type="number"
              step="1"
              variant="outlined"
              density="compact"
            />
            <v-text-field
              v-model.number="form.delays.element_timeout"
              label="Таймаут элемента (мс)"
              type="number"
              step="100"
              variant="outlined"
              density="compact"
            />
            <v-switch v-model="form.use_proxy" label="Использовать прокси"  color="primary" />
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6" v-if="form">
        <v-card elevation="2">
          <v-card-title>Скриншоты</v-card-title>
          <v-divider />
          <v-card-text>
            <v-text-field
              v-model.number="form.screenshot.quality"
              label="Качество"
              type="number"
              step="1"
              variant="outlined"
              density="compact"
            />
            <v-text-field
              v-model.number="form.screenshot.method"
              label="Метод"
              type="number"
              step="1"
              variant="outlined"
              density="compact"
            />
            <v-select
              v-model="form.screenshot.format"
              :items="screenshotFormats"
              label="Формат"
              variant="outlined"
              density="compact"
            />
            <v-switch v-model="form.screenshot.full_page" label="Полная страница"  color="primary" />
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6" v-if="form">
        <v-card elevation="2">
          <v-card-title>Валидация</v-card-title>
          <v-divider />
          <v-card-text>
            <v-text-field
              v-model.number="form.validation.min_price"
              label="Мин. цена"
              type="number"
              step="1"
              variant="outlined"
              density="compact"
            />
            <v-text-field
              v-model.number="form.validation.max_price"
              label="Макс. цена"
              type="number"
              step="1"
              variant="outlined"
              density="compact"
            />
            <v-combobox
              v-model="form.validation.required_fields"
              label="Обязательные поля"
              multiple
              chips
              variant="outlined"
              density="compact"
            />
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6" v-if="form">
        <v-card elevation="2">
          <v-card-title>User Agents</v-card-title>
          <v-divider />
          <v-card-text>
            <v-combobox
              v-model="form.user_agents"
              label="Список User-Agent"
              multiple
              chips
              variant="outlined"
              density="compact"
            />
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" v-if="form">
        <v-card elevation="2">
          <v-card-title>Сбор URL</v-card-title>
          <v-divider />
          <v-card-text>
            <v-switch v-model="form.collect_urls" label="Собирать URL"  color="primary" />
            <v-text-field
              v-model="form.catalog_base_url"
              label="Catalog base URL"
              variant="outlined"
              density="compact"
            />
            <v-select
              v-model="form.url_collection_frequency"
              :items="frequencyOptions"
              label="Частота сбора"
              variant="outlined"
              density="compact"
            />

            <v-row>
              <v-col cols="12" md="6">
                <v-text-field v-model="form.url_collection.subcategory_selector" label="Селектор подкатегорий" variant="outlined" density="compact" />
                <v-text-field v-model="form.url_collection.product_selector" label="Селектор товаров" variant="outlined" density="compact" />
                <v-text-field v-model="form.url_collection.pagination_next_selector" label="Селектор пагинации" variant="outlined" density="compact" />
                <v-text-field v-model="form.url_collection.pagination_param" label="Параметр пагинации" variant="outlined" density="compact" />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field v-model.number="form.url_collection.pagination_max_pages" label="Макс. страниц" type="number" step="1" variant="outlined" density="compact" />
                <v-text-field v-model.number="form.url_collection.max_depth" label="Макс. глубина" type="number" step="1" variant="outlined" density="compact" />
                <v-text-field v-model.number="form.url_collection.request_delay" label="Задержка (сек)" type="number" step="0.1" variant="outlined" density="compact" />
                <v-text-field v-model.number="form.url_collection.timeout" label="Таймаут (сек)" type="number" step="1" variant="outlined" density="compact" />
                <v-text-field v-model.number="form.url_collection.max_urls" label="Макс. URL" type="number" step="1" variant="outlined" density="compact" />
                <v-switch v-model="form.url_collection.infinite_scroll" label="Infinite scroll"  color="primary" />
              </v-col>
            </v-row>

            <v-combobox
              v-model="form.url_collection.filter_keywords"
              label="Ключевые слова (фильтр)"
              multiple
              chips
              variant="outlined"
              density="compact"
            />

            <v-combobox
              v-model="form.url_collection.exclude_keywords"
              label="Исключающие слова"
              multiple
              chips
              variant="outlined"
              density="compact"
            />

            <v-combobox
              v-model="form.allowed_categories"
              label="Разрешенные категории"
              multiple
              chips
              variant="outlined"
              density="compact"
            />
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6" v-if="form">
        <v-card elevation="2">
          <v-card-title>Материалы</v-card-title>
          <v-divider />
          <v-card-text>
            <v-combobox
              v-model="form.material_types"
              label="Типы материалов"
              multiple
              chips
              variant="outlined"
              density="compact"
            />
            <v-text-field
              v-model.number="form.url_validation_timeout"
              label="Таймаут проверки URL (сек)"
              type="number"
              step="1"
              variant="outlined"
              density="compact"
            />
            <v-text-field
              v-model.number="form.max_invalid_retries"
              label="Макс. повторов ошибочных URL"
              type="number"
              step="1"
              variant="outlined"
              density="compact"
            />
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" v-if="form">
        <v-card elevation="2">
          <v-card-title class="d-flex align-center justify-space-between">
            <span>Профили collect-urls</span>
            <v-btn size="small" variant="text" prepend-icon="mdi-plus" @click="addProfile">
              Добавить профиль
            </v-btn>
          </v-card-title>
          <v-divider />
          <v-card-text>
            <v-alert v-if="profilesError" type="error" variant="tonal" class="mb-4">
              {{ profilesError }}
            </v-alert>

            <v-row v-if="profiles.length === 0">
              <v-col cols="12" class="text-caption text-grey-darken-1">
                Профили не созданы. Добавьте профиль, чтобы запускать collect-urls с разными фильтрами.
              </v-col>
            </v-row>

            <v-row v-for="profile in profiles" :key="profile.id" class="mb-4">
              <v-col cols="12" md="4">
                <v-text-field
                  v-model="profile.name"
                  label="Название профиля"
                  variant="outlined"
                  density="compact"
                />
                <v-switch v-model="profile.is_default" label="Профиль по умолчанию"  color="primary" />
                <div class="d-flex gap-2">
                  <v-btn size="small" color="primary" variant="elevated" :loading="profile.saving" @click="saveProfile(profile)">
                    Сохранить
                  </v-btn>
                  <v-btn size="small" color="error" variant="text" :loading="profile.deleting" @click="deleteProfile(profile)">
                    Удалить
                  </v-btn>
                </div>
              </v-col>
              <v-col cols="12" md="8">
                <v-textarea
                  v-model="profile.config_override_json"
                  label="JSON override для collect-urls"
                  rows="8"
                  variant="outlined"
                  density="compact"
                  class="mono-font"
                />
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { parserApi } from '@/api/parser'

type KeyValuePair = { key: string; value: string }

const route = useRoute()
const router = useRouter()

const supplier = String(route.params.supplier || '')

const form = ref<any | null>(null)
const configSource = ref<'db' | 'file' | null>(null)
const error = ref('')
const saving = ref(false)

const profiles = ref<any[]>([])
const profilesError = ref('')

const unitMappingPairs = ref<KeyValuePair[]>([])
const typeMappingPairs = ref<KeyValuePair[]>([])
const selectorPairs = ref<KeyValuePair[]>([])

const defaultTypeOptions = ['plate', 'edge', 'hardware']
const unitOptions = ['м²', 'м.п.', 'шт']
const screenshotFormats = ['webp', 'png', 'jpeg']
const frequencyOptions = ['daily', 'weekly', 'monthly', 'yearly']

onMounted(() => {
  loadConfig()
  loadCollectProfiles()
})

async function loadConfig() {
  try {
    error.value = ''
    const response = await parserApi.getSupplierConfig(supplier)
    configSource.value = response.source ?? null
    form.value = normalizeConfig(response.config)

    unitMappingPairs.value = toPairs(form.value.material_unit_mapping)
    typeMappingPairs.value = toPairs(form.value.material_type_mapping)
    selectorPairs.value = toPairs(form.value.selectors)
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Не удалось загрузить конфигурацию'
  }
}

async function loadCollectProfiles() {
  try {
    profilesError.value = ''
    const response = await parserApi.getCollectProfiles(supplier)
    profiles.value = response.map((profile: any) => ({
      ...profile,
      saving: false,
      deleting: false,
      config_override_json: JSON.stringify(profile.config_override || {}, null, 2)
    }))
  } catch (e: any) {
    profilesError.value = e?.response?.data?.message || e?.message || 'Не удалось загрузить профили'
  }
}

function normalizeConfig(config: Record<string, any>) {
  const normalized = { ...config }

  normalized.delays = normalized.delays || {
    between_requests: 1.5,
    page_load_timeout: 20000,
    page_load_retries: 1,
    element_timeout: 15000
  }
  normalized.selectors = normalized.selectors || {}
  normalized.material_unit_mapping = normalized.material_unit_mapping || {}
  normalized.material_type_mapping = normalized.material_type_mapping || {}
  normalized.user_agents = Array.isArray(normalized.user_agents) ? normalized.user_agents : []
  normalized.validation = normalized.validation || { min_price: 0, max_price: 1000000, required_fields: [] }
  normalized.validation.required_fields = Array.isArray(normalized.validation.required_fields)
    ? normalized.validation.required_fields
    : []
  normalized.screenshot = normalized.screenshot || { quality: 85, method: 6, full_page: false, format: 'webp' }
  normalized.allowed_categories = Array.isArray(normalized.allowed_categories) ? normalized.allowed_categories : []
  normalized.material_types = Array.isArray(normalized.material_types) ? normalized.material_types : []
  normalized.url_collection = normalized.url_collection || {}
  normalized.url_collection.filter_keywords = Array.isArray(normalized.url_collection.filter_keywords)
    ? normalized.url_collection.filter_keywords
    : []
  normalized.url_collection.exclude_keywords = Array.isArray(normalized.url_collection.exclude_keywords)
    ? normalized.url_collection.exclude_keywords
    : []

  return normalized
}

function toPairs(obj: Record<string, any>): KeyValuePair[] {
  return Object.entries(obj || {}).map(([key, value]) => ({ key, value: String(value) }))
}

function fromPairs(pairs: KeyValuePair[]): Record<string, string> {
  const result: Record<string, string> = {}
  pairs.forEach(pair => {
    if (pair.key) {
      result[pair.key] = pair.value
    }
  })
  return result
}

function addPair(list: KeyValuePair[]) {
  list.push({ key: '', value: '' })
}

function removePair(list: KeyValuePair[], index: number) {
  list.splice(index, 1)
}

function addProfile() {
  profiles.value.unshift({
    id: 0,
    name: 'Новый профиль',
    config_override: {},
    config_override_json: '{\n  "url_collection": {\n    "filter_keywords": ["петля", "petlya"],\n    "exclude_keywords": ["лдсп", "ldsp"]\n  }\n}',
    is_default: false,
    saving: false,
    deleting: false
  })
}

async function saveProfile(profile: any) {
  profile.saving = true
  profilesError.value = ''
  try {
    const override = JSON.parse(profile.config_override_json || '{}')
    const payload = {
      name: profile.name,
      config_override: override,
      is_default: profile.is_default
    }

    if (!profile.id) {
      const created = await parserApi.createCollectProfile(supplier, payload)
      profile.id = created.id
    } else {
      const updated = await parserApi.updateCollectProfile(supplier, profile.id, payload)
      profile.is_default = updated.is_default
    }

    await loadCollectProfiles()
  } catch (e: any) {
    profilesError.value = e?.response?.data?.message || e?.message || 'Не удалось сохранить профиль'
  } finally {
    profile.saving = false
  }
}

async function deleteProfile(profile: any) {
  if (!profile.id) {
    profiles.value = profiles.value.filter(p => p !== profile)
    return
  }

  profile.deleting = true
  profilesError.value = ''
  try {
    await parserApi.deleteCollectProfile(supplier, profile.id)
    profiles.value = profiles.value.filter(p => p.id !== profile.id)
  } catch (e: any) {
    profilesError.value = e?.response?.data?.message || e?.message || 'Не удалось удалить профиль'
  } finally {
    profile.deleting = false
  }
}

async function save() {
  if (!form.value) return

  saving.value = true
  error.value = ''

  try {
    const payload = JSON.parse(JSON.stringify(form.value))
    payload.material_unit_mapping = fromPairs(unitMappingPairs.value)
    payload.material_type_mapping = fromPairs(typeMappingPairs.value)
    payload.selectors = fromPairs(selectorPairs.value)

    const response = await parserApi.updateSupplierConfig(supplier, payload)
    form.value = normalizeConfig(response.config)
    configSource.value = response.source ?? 'db'

    unitMappingPairs.value = toPairs(form.value.material_unit_mapping)
    typeMappingPairs.value = toPairs(form.value.material_type_mapping)
    selectorPairs.value = toPairs(form.value.selectors)
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || 'Не удалось сохранить конфигурацию'
  } finally {
    saving.value = false
  }
}

function goBack() {
  router.push('/parser')
}
</script>

<style scoped lang="scss">
.supplier-config {
  max-width: 1600px;
  margin: 0 auto;
}

.gap-2 {
  gap: 8px;
}

.mono-font {
  font-family: 'Courier New', Courier, monospace;
}
</style>

