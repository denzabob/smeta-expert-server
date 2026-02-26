<template>
  <v-dialog :model-value="modelValue" @update:model-value="$emit('update:modelValue', $event)"
    max-width="950" scrollable persistent>
    <v-card>
      <v-card-title class="d-flex align-center">
        <span>{{ dialogTitle }}</span>
        <v-spacer />
        <v-btn icon variant="text" @click="close"><v-icon>mdi-close</v-icon></v-btn>
      </v-card-title>

      <!-- Wizard steps for create mode -->
      <v-tabs v-if="wizardMode" v-model="activeStep" color="primary" class="px-4">
        <v-tab :value="0" :disabled="false">
          <v-icon start>mdi-door</v-icon>
          Фасад
        </v-tab>
        <v-tab :value="1" :disabled="!createdFacadeId">
          <v-icon start>mdi-currency-rub</v-icon>
          Котировки
        </v-tab>
      </v-tabs>

      <v-card-text>
        <v-alert v-if="wizardMode" density="compact" type="info" variant="tonal" class="mb-3">
          <span v-if="activeStep === 0">Шаг 1 из 2: заполните карточку изделия и сохраните.</span>
          <span v-else>Шаг 2 из 2: добавьте котировку поставщика и завершите создание.</span>
        </v-alert>

        <!-- Step 0 / Edit: Facade identity fields -->
        <div v-show="activeStep === 0">
          <v-form ref="formRef" v-model="formValid">
            <!-- Name -->
            <v-row dense>
              <v-col cols="12" md="8">
                <v-text-field v-model="form.name" label="Название" :disabled="autoName"
                  :hint="autoName ? 'Генерируется автоматически' : ''" persistent-hint />
              </v-col>
              <v-col cols="12" md="4" class="d-flex align-start pt-1">
                <v-switch v-model="autoName" label="Авто-имя" hide-details density="compact" color="primary" />
              </v-col>
            </v-row>

            <v-divider class="my-3" />

            <!-- Identity fields (required) -->
            <div class="text-subtitle-2 mb-2">Обязательные характеристики</div>
            <v-row dense>
              <v-col cols="6" md="3">
                <v-select v-model="form.facade_class" :items="classItems" item-title="label" item-value="value"
                  label="Класс фасада *" :rules="[rules.required]"
                  hint="STANDARD, PREMIUM и т.д." persistent-hint />
              </v-col>
              <v-col cols="6" md="3">
                <v-select v-model="form.facade_base_type" :items="baseItems" item-title="label" item-value="value"
                  label="Основа *" :rules="[rules.required]" />
              </v-col>
              <v-col cols="6" md="2">
                <v-text-field v-model.number="form.facade_thickness_mm" label="Толщина мм *" type="number"
                  :rules="[rules.required, rules.positive]" />
              </v-col>
              <v-col cols="6" md="2">
                <v-select v-model="form.facade_covering" :items="coveringItems" item-title="label" item-value="value"
                  label="Покрытие *" :rules="[rules.required]" />
              </v-col>
              <v-col cols="6" md="2">
                <v-select v-model="form.facade_cover_type" :items="coverTypeItems" item-title="label" item-value="value"
                  label="Вид покрытия" clearable />
              </v-col>
            </v-row>

            <!-- Optional fields -->
            <div class="text-subtitle-2 mb-2 mt-3">Дополнительно</div>
            <v-row dense>
              <v-col cols="6" md="4">
                <v-text-field v-model="form.facade_collection" label="Коллекция" />
              </v-col>
              <v-col cols="6" md="4">
                <v-text-field v-model="form.facade_decor_label" label="Декор" />
              </v-col>
              <v-col cols="6" md="2">
                <v-text-field v-model="form.facade_price_group_label" label="Ценовая группа" />
              </v-col>
              <v-col cols="6" md="2">
                <v-text-field v-model="form.facade_article_optional" label="Артикул" />
              </v-col>
            </v-row>
          </v-form>
        </div>

        <!-- Step 1 / Edit quotes: Quotes list + Add Quote -->
        <div v-show="activeStep === 1 || (!wizardMode && !isNew)">
          <template v-if="currentFacadeId">
            <v-divider v-if="!wizardMode" class="my-4" />

            <!-- Wizard prompt for first quote -->
            <v-alert v-if="wizardMode && quotes.length === 0" type="info" variant="tonal" class="mb-3">
              Фасад создан. Добавьте первую котировку поставщика.
            </v-alert>

            <div class="d-flex align-center mb-2">
              <div class="text-subtitle-1 font-weight-medium">Котировки ({{ quotes.length }})</div>
              <v-spacer />
              <v-btn size="small" variant="tonal" prepend-icon="mdi-plus" @click="openAddQuote">
                Добавить котировку
              </v-btn>
            </div>

            <div class="quotes-table-scroll" @wheel.prevent="onQuotesTableWheel">
              <v-data-table :headers="quoteHeaders" :items="quotes" density="compact" class="elevation-0 quotes-table"
                :items-per-page="10" item-key="id">
                <template #item.price_per_m2="{ item }">
                  <span class="font-weight-medium">{{ formatPrice(item.price_per_m2) }} ₽</span>
                </template>
                <template #item.captured_at="{ item }">
                  {{ item.captured_at ? new Date(item.captured_at).toLocaleDateString('ru-RU') : '—' }}
                </template>
                <template #item.source="{ item }">
                  <v-chip v-if="item.source_type" size="x-small" :color="sourceTypeColor(item.source_type)" class="mr-1">
                    {{ sourceTypeLabel(item.source_type) }}
                  </v-chip>
                  <a v-if="item.source_url" :href="item.source_url" target="_blank" class="text-primary">
                    {{ item.original_filename || 'Ссылка' }}
                  </a>
                  <span v-else-if="item.original_filename">{{ item.original_filename }}</span>
                  <span v-else class="text-grey">—</span>
                </template>
                <template #item.actions="{ item }">
                  <v-btn icon size="x-small" variant="text" title="Редактировать" @click="openEditQuote(item)">
                    <v-icon color="primary">mdi-pencil</v-icon>
                  </v-btn>
                  <v-btn icon size="x-small" variant="text" title="Удалить" @click="confirmDeleteQuote(item)">
                    <v-icon color="error">mdi-delete</v-icon>
                  </v-btn>
                </template>
              </v-data-table>
            </div>
          </template>
        </div>
      </v-card-text>

      <v-card-actions>
        <v-btn v-if="!isNew && activeStep === 0" color="error" variant="text" prepend-icon="mdi-delete"
          @click="confirmDeleteFacade">
          Удалить фасад
        </v-btn>
        <v-spacer />
        <v-btn @click="close">{{ wizardMode && activeStep === 1 ? 'Готово' : 'Отмена' }}</v-btn>
        <!-- Step 0: Save facade -->
        <v-btn v-if="activeStep === 0" color="primary" :loading="saving" :disabled="!formValid" @click="save">
          {{ isNew ? 'Создать фасад' : 'Сохранить' }}
        </v-btn>
      </v-card-actions>
    </v-card>

    <!-- ============ Delete Facade Confirmation ============ -->
    <v-dialog v-model="showDeleteFacade" max-width="420">
      <v-card>
        <v-card-title>Удалить фасад?</v-card-title>
        <v-card-text>
          Фасад «{{ props.facade?.name }}» будет удалён или деактивирован (если есть ссылки).
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="showDeleteFacade = false">Отмена</v-btn>
          <v-btn color="error" :loading="saving" @click="doDeleteFacade">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- ============ Add Quote Dialog ============ -->
    <v-dialog v-model="showAddQuote" max-width="720" persistent>
      <v-card>
        <v-card-title>Добавить котировку</v-card-title>
        <v-card-text>
          <v-alert density="compact" variant="tonal" type="info" class="mb-3">
            Обязательные поля: поставщик, источник прайса, цена.
          </v-alert>

          <!-- 1. Supplier -->
          <v-autocomplete v-model="newQuote.supplier_id" :items="supplierItems" item-title="name" item-value="id"
            label="Поставщик *" :rules="[rules.required]" :error-messages="quoteFieldErrors.supplier_id" />

          <!-- 2. Source mode selector -->
          <div v-if="newQuote.supplier_id" class="mt-2">
            <div class="d-flex align-center justify-space-between mb-2">
              <div class="text-subtitle-2">Источник прайса</div>
              <v-btn-toggle v-model="quoteMode" mandatory density="compact" color="primary">
                <v-btn value="quick" size="small">Быстро</v-btn>
                <v-btn value="advanced" size="small">Расширенно</v-btn>
              </v-btn-toggle>
            </div>

            <div class="source-mode-toggle-scroll">
              <v-btn-toggle v-model="sourceMode" mandatory color="primary" density="compact" class="mb-3 source-mode-toggle">
              <template v-if="quoteMode === 'quick'">
                <v-btn value="existing" size="small">
                  <v-icon start size="small">mdi-format-list-bulleted</v-icon>
                  Существующая версия
                </v-btn>
                <v-btn value="document" size="small">
                  <v-icon start size="small">mdi-file-document</v-icon>
                  Документ прайса
                </v-btn>
              </template>
              <template v-else>
                <v-btn value="file" size="small">
                  <v-icon start size="small">mdi-file-upload</v-icon>
                  Новый файл
                </v-btn>
                <v-btn value="url" size="small">
                  <v-icon start size="small">mdi-link</v-icon>
                  URL
                </v-btn>
                <v-btn value="manual" size="small">
                  <v-icon start size="small">mdi-pencil</v-icon>
                  Вручную
                </v-btn>
              </template>
              </v-btn-toggle>
            </div>

            <!-- 2doc. Document (DMS — uploaded PDF/XLSX, no parsing) -->
            <template v-if="sourceMode === 'document'">
              <v-autocomplete v-model="newQuote.price_list_version_id" :items="documentItems"
                item-title="label" item-value="id"
                label="Документ прайса *" :rules="[rules.required]"
                :loading="loadingVersions" no-data-text="Нет загруженных документов прайсов"
                :error-messages="quoteFieldErrors.price_list_version_id">
                <template #item="{ item, props: itemProps }">
                  <v-list-item v-bind="itemProps">
                    <template #append>
                      <v-chip size="x-small" :color="item.raw.statusColor" class="ml-2">
                        {{ item.raw.statusLabel }}
                      </v-chip>
                      <v-chip v-if="item.raw.sourceType" size="x-small" class="ml-1"
                        :color="sourceTypeColor(item.raw.sourceType)">
                        {{ sourceTypeLabel(item.raw.sourceType) }}
                      </v-chip>
                    </template>
                  </v-list-item>
                </template>
              </v-autocomplete>
            </template>

            <!-- 2a. Existing version -->
            <template v-if="sourceMode === 'existing'">
              <v-autocomplete v-model="newQuote.price_list_version_id" :items="versionItems"
                item-title="label" item-value="id"
                label="Версия прайса *" :rules="[rules.required]"
                :loading="loadingVersions" no-data-text="Нет доступных версий прайсов"
                :error-messages="quoteFieldErrors.price_list_version_id">
                <template #item="{ item, props: itemProps }">
                  <v-list-item v-bind="itemProps">
                    <template #append>
                      <v-chip size="x-small" :color="item.raw.statusColor" class="ml-2">
                        {{ item.raw.statusLabel }}
                      </v-chip>
                      <v-chip v-if="item.raw.sourceType" size="x-small" class="ml-1"
                        :color="sourceTypeColor(item.raw.sourceType)">
                        {{ sourceTypeLabel(item.raw.sourceType) }}
                      </v-chip>
                    </template>
                  </v-list-item>
                </template>
              </v-autocomplete>
            </template>

            <!-- 2b. New file upload -->
            <template v-if="sourceMode === 'file'">
              <v-autocomplete v-model="selectedPriceListId" :items="materialPriceListItems"
                item-title="label" item-value="id"
                label="Прайс-лист *" :rules="[rules.required]"
                :loading="loadingVersions" no-data-text="Нет прайс-листов у поставщика"
                :error-messages="quoteFieldErrors.selected_price_list_id" />
              <v-file-input v-model="uploadFile" label="Файл прайса *"
                accept=".xlsx,.xls,.csv,.pdf,.ods" :rules="[rules.required]"
                prepend-icon="mdi-paperclip" show-size
                :error-messages="quoteFieldErrors.upload_file" class="mt-2" />
              <v-text-field v-model="newVersionEffectiveDate" label="Дата актуальности"
                type="date" class="mt-2" />
            </template>

            <!-- 2c. URL -->
            <template v-if="sourceMode === 'url'">
              <v-autocomplete v-model="selectedPriceListId" :items="materialPriceListItems"
                item-title="label" item-value="id"
                label="Прайс-лист *" :rules="[rules.required]"
                :loading="loadingVersions" :error-messages="quoteFieldErrors.selected_price_list_id" />
              <v-text-field v-model="newVersionUrl" label="URL прайса *"
                :rules="[rules.required, rules.url]" placeholder="https://..."
                prepend-icon="mdi-link" class="mt-2" :error-messages="quoteFieldErrors.source_url" />
              <v-text-field v-model="newVersionEffectiveDate" label="Дата актуальности"
                type="date" class="mt-2" />
            </template>

            <!-- 2d. Manual -->
            <template v-if="sourceMode === 'manual'">
              <v-autocomplete v-model="selectedPriceListId" :items="materialPriceListItems"
                item-title="label" item-value="id"
                label="Прайс-лист *" :rules="[rules.required]"
                :loading="loadingVersions" :error-messages="quoteFieldErrors.selected_price_list_id" />
              <v-text-field v-model="newVersionManualLabel" label="Метка (опционально)"
                class="mt-2" />
            </template>
          </div>

          <v-divider class="my-3" />

          <!-- 3. Price details -->
          <div class="text-subtitle-2 mb-2">Цена</div>
          <v-row dense>
            <v-col cols="6">
              <v-text-field v-model.number="newQuote.source_price" label="Цена *" type="number"
                :rules="[rules.required, rules.positive]" :error-messages="quoteFieldErrors.source_price" />
            </v-col>
            <v-col cols="3">
              <v-select v-model="newQuote.source_unit" :items="unitOptions" label="Ед. изм." />
            </v-col>
            <v-col cols="3">
              <v-text-field v-model.number="newQuote.conversion_factor" label="Конверсия"
                type="number" />
            </v-col>
          </v-row>

          <v-expansion-panels variant="accordion" class="mt-2">
            <v-expansion-panel>
              <v-expansion-panel-title>
                Дополнительные поля (необязательно)
              </v-expansion-panel-title>
              <v-expansion-panel-text>
                <v-text-field v-model="newQuote.article" label="Артикул/SKU в прайсе" class="mt-1" />
                <v-text-field v-model="newQuote.category" label="Категория в прайсе поставщика" />
                <v-text-field v-model="newQuote.description" label="Описание из прайса" />
              </v-expansion-panel-text>
            </v-expansion-panel>
          </v-expansion-panels>

          <!-- Error message -->
          <v-alert v-if="quoteError" type="error" variant="tonal" class="mt-2" closable @click:close="quoteError = ''">
            {{ quoteError }}
          </v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="showAddQuote = false">Отмена</v-btn>
          <v-btn color="primary" :loading="savingQuote" @click="saveQuote"
            :disabled="!canSaveQuote">Добавить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- ============ Edit Quote Dialog ============ -->
    <v-dialog v-model="showEditQuote" max-width="500" persistent>
      <v-card>
        <v-card-title>Редактировать котировку</v-card-title>
        <v-card-text>
          <v-row dense>
            <v-col cols="6">
              <v-text-field v-model.number="editQuoteData.source_price" label="Цена *" type="number"
                :rules="[rules.required, rules.positive]" />
            </v-col>
            <v-col cols="3">
              <v-select v-model="editQuoteData.source_unit" :items="unitOptions" label="Ед. изм." />
            </v-col>
            <v-col cols="3">
              <v-text-field v-model.number="editQuoteData.conversion_factor" label="Конверсия" type="number" />
            </v-col>
          </v-row>
          <v-text-field v-model="editQuoteData.article" label="Артикул/SKU" class="mt-1" />
          <v-text-field v-model="editQuoteData.category" label="Категория" />
          <v-text-field v-model="editQuoteData.description" label="Описание" />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="showEditQuote = false">Отмена</v-btn>
          <v-btn color="primary" :loading="savingQuote" :disabled="!editQuoteData.source_price"
            @click="doUpdateQuote">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- ============ Delete Quote Confirmation ============ -->
    <v-dialog v-model="showDeleteQuote" max-width="420">
      <v-card>
        <v-card-title>Удалить котировку?</v-card-title>
        <v-card-text>
          Котировка будет удалена безвозвратно.
          <div v-if="deletingQuote" class="mt-2 text-medium-emphasis">
            {{ deletingQuote.supplier_name }} — {{ formatPrice(deletingQuote.price_per_m2) }} ₽/м²
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="showDeleteQuote = false">Отмена</v-btn>
          <v-btn color="error" :loading="savingQuote" @click="doDeleteQuote">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useFinishedProductsStore } from '@/stores/finishedProducts'
import { finishedProductsApi, type FinishedProduct as Facade, type FinishedProductQuote as FacadeQuote } from '@/api/finishedProducts'
import { priceListsApi } from '@/api/priceLists'
import api from '@/api/axios'

const props = defineProps<{
  modelValue: boolean
  facade: Facade | null
  filterOptions: any
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', val: boolean): void
  (e: 'saved'): void
}>()

const store = useFinishedProductsStore()

const formRef = ref<any>(null)
const formValid = ref(false)
const saving = ref(false)
const savingQuote = ref(false)
const autoName = ref(true)
const quotes = ref<FacadeQuote[]>([])
const showAddQuote = ref(false)
const quoteError = ref('')
const quoteFieldErrors = ref<Record<string, string[]>>({
  supplier_id: [],
  price_list_version_id: [],
  selected_price_list_id: [],
  source_price: [],
  source_url: [],
  upload_file: [],
})

// Edit/Delete quote state
const showEditQuote = ref(false)
const showDeleteQuote = ref(false)
const showDeleteFacade = ref(false)
const editingQuoteId = ref<number | null>(null)
const deletingQuote = ref<FacadeQuote | null>(null)
const editQuoteData = ref({
  source_price: null as number | null,
  source_unit: 'м²',
  conversion_factor: 1.0,
  article: '' as string | null,
  category: '' as string | null,
  description: '' as string | null,
})

// Wizard state
const activeStep = ref(0)
const createdFacadeId = ref<number | null>(null)

const isNew = computed(() => !props.facade)
const wizardMode = computed(() => isNew.value || !!createdFacadeId.value)
const currentFacadeId = computed(() => props.facade?.id ?? createdFacadeId.value)

const dialogTitle = computed(() => {
  if (wizardMode.value && activeStep.value === 1) return 'Шаг 2 из 2: Котировки'
  return isNew.value && !createdFacadeId.value ? 'Новый фасад' : 'Редактировать фасад'
})

// Form
const defaultForm = () => ({
  name: '',
  facade_class: 'STANDARD',
  facade_base_type: 'mdf',
  facade_thickness_mm: 16,
  facade_covering: 'pvc_film',
  facade_cover_type: null as string | null,
  facade_collection: '',
  facade_decor_label: '',
  facade_price_group_label: '',
  facade_article_optional: '',
})

const form = ref(defaultForm())

// Quote form
const newQuote = ref({
  supplier_id: null as number | null,
  price_list_version_id: null as number | null,
  source_price: null as number | null,
  source_unit: 'м²',
  conversion_factor: 1.0,
  article: '',
  category: '',
  description: '',
})

// Source mode
const quoteMode = ref<'quick' | 'advanced'>('quick')
const sourceMode = ref<'document' | 'existing' | 'file' | 'url' | 'manual'>('existing')
const selectedPriceListId = ref<number | null>(null)
const uploadFile = ref<File | File[] | null>(null)
const newVersionUrl = ref('')
const newVersionEffectiveDate = ref('')
const newVersionManualLabel = ref('')
const loadingVersions = ref(false)

// Validation
const rules = {
  required: (v: any) => !!v || v === 0 || 'Обязательное поле',
  positive: (v: any) => (v !== null && v > 0) || 'Должно быть > 0',
  url: (v: string) => !v || /^https?:\/\//.test(v) || 'Введите корректный URL',
}

// Options
const classItems = computed(() => props.filterOptions?.facade_classes ?? [])
const baseItems = computed(() => props.filterOptions?.base_materials ?? [])
const coveringItems = computed(() => props.filterOptions?.finish_types ?? [])
const coverTypeItems = computed(() => props.filterOptions?.finish_variants ?? [])

const unitOptions = ['м²', 'шт', 'пог.м', 'лист']

// Suppliers and versions for quote creation
const supplierItems = ref<any[]>([])
const versionItems = ref<any[]>([])
const materialPriceListItems = ref<any[]>([])
const documentItems = ref<any[]>([])

const canSaveQuote = computed(() => {
  if (!newQuote.value.supplier_id || !newQuote.value.source_price) return false
  if (sourceMode.value === 'document' && !newQuote.value.price_list_version_id) return false
  if (sourceMode.value === 'existing' && !newQuote.value.price_list_version_id) return false
  if (sourceMode.value === 'file' && (!selectedPriceListId.value || !uploadFile.value)) return false
  if (sourceMode.value === 'url' && (!selectedPriceListId.value || !newVersionUrl.value)) return false
  if (sourceMode.value === 'manual' && !selectedPriceListId.value) return false
  return true
})

function clearQuoteFieldErrors() {
  quoteFieldErrors.value = {
    supplier_id: [],
    price_list_version_id: [],
    selected_price_list_id: [],
    source_price: [],
    source_url: [],
    upload_file: [],
  }
}

function validateQuoteFields() {
  clearQuoteFieldErrors()
  let valid = true

  if (!newQuote.value.supplier_id) {
    quoteFieldErrors.value.supplier_id = ['Выберите поставщика']
    valid = false
  }

  if (!newQuote.value.source_price || newQuote.value.source_price <= 0) {
    quoteFieldErrors.value.source_price = ['Укажите корректную цену']
    valid = false
  }

  if (sourceMode.value === 'document' || sourceMode.value === 'existing') {
    if (!newQuote.value.price_list_version_id) {
      quoteFieldErrors.value.price_list_version_id = ['Выберите версию прайса']
      valid = false
    }
  }

  if (sourceMode.value === 'file' || sourceMode.value === 'url' || sourceMode.value === 'manual') {
    if (!selectedPriceListId.value) {
      quoteFieldErrors.value.selected_price_list_id = ['Выберите прайс-лист']
      valid = false
    }
  }

  if (sourceMode.value === 'file' && !uploadFile.value) {
    quoteFieldErrors.value.upload_file = ['Добавьте файл прайса']
    valid = false
  }

  if (sourceMode.value === 'url') {
    const url = String(newVersionUrl.value || '').trim()
    if (!url) {
      quoteFieldErrors.value.source_url = ['Укажите URL']
      valid = false
    } else if (!/^https?:\/\//.test(url)) {
      quoteFieldErrors.value.source_url = ['Введите корректный URL (http/https)']
      valid = false
    }
  }

  return valid
}

const quoteHeaders = [
  { title: 'Поставщик', key: 'supplier_name', width: '140px' },
  { title: 'Цена м²', key: 'price_per_m2', width: '90px' },
  { title: 'Ед.', key: 'source_unit', width: '50px' },
  { title: 'Дата', key: 'captured_at', width: '80px' },
  { title: 'Прайс', key: 'price_list_name', width: '110px' },
  { title: 'Источник', key: 'source', width: '130px' },
  { title: 'SKU', key: 'article', width: '90px' },
  { title: 'Кат.', key: 'category', width: '90px' },
  { title: '', key: 'actions', width: '70px', sortable: false },
]

// Watch for dialog open/close
watch(() => props.modelValue, async (val) => {
  if (val) {
    createdFacadeId.value = null
    activeStep.value = 0

    if (props.facade) {
      // Edit mode
      form.value = {
        name: props.facade.name ?? '',
        facade_class: props.facade.facade_class ?? 'STANDARD',
        facade_base_type: props.facade.facade_base_type ?? 'mdf',
        facade_thickness_mm: props.facade.facade_thickness_mm ?? 16,
        facade_covering: props.facade.facade_covering ?? 'pvc_film',
        facade_cover_type: props.facade.facade_cover_type ?? null,
        facade_collection: props.facade.facade_collection ?? '',
        facade_decor_label: props.facade.facade_decor_label ?? '',
        facade_price_group_label: props.facade.facade_price_group_label ?? '',
        facade_article_optional: props.facade.facade_article_optional ?? '',
      }
      autoName.value = false
      await loadQuotes()
    } else {
      form.value = defaultForm()
      autoName.value = true
      quotes.value = []
    }
    loadSuppliers()
  }
})

// Watch supplier change → load versions
watch(() => newQuote.value.supplier_id, async (supplierId) => {
  quoteFieldErrors.value.supplier_id = []
  quoteFieldErrors.value.price_list_version_id = []
  versionItems.value = []
  materialPriceListItems.value = []
  documentItems.value = []
  newQuote.value.price_list_version_id = null
  selectedPriceListId.value = null

  if (!supplierId) return
  await Promise.all([loadSupplierVersions(supplierId), loadSupplierDocuments(supplierId)])
})

watch(quoteMode, (mode) => {
  if (mode === 'quick' && !['existing', 'document'].includes(sourceMode.value)) {
    sourceMode.value = 'existing'
  }
  if (mode === 'advanced' && !['file', 'url', 'manual'].includes(sourceMode.value)) {
    sourceMode.value = 'file'
  }
})

watch(sourceMode, () => {
  quoteFieldErrors.value.price_list_version_id = []
  quoteFieldErrors.value.selected_price_list_id = []
  quoteFieldErrors.value.source_url = []
  quoteFieldErrors.value.upload_file = []
})

watch(() => newQuote.value.price_list_version_id, () => {
  quoteFieldErrors.value.price_list_version_id = []
})

watch(() => newQuote.value.source_price, () => {
  quoteFieldErrors.value.source_price = []
})

watch(selectedPriceListId, () => {
  quoteFieldErrors.value.selected_price_list_id = []
})

watch(uploadFile, () => {
  quoteFieldErrors.value.upload_file = []
})

watch(newVersionUrl, () => {
  quoteFieldErrors.value.source_url = []
})

async function loadQuotes() {
  if (!currentFacadeId.value) return
  try {
    await store.fetchQuotes(currentFacadeId.value)
    quotes.value = store.currentQuotes
  } catch {
    try {
      const resp = await finishedProductsApi.getQuotes(currentFacadeId.value)
      quotes.value = resp.data.quotes ?? []
    } catch { /* ignore */ }
  }
}

async function loadSuppliers() {
  try {
    const { data } = await api.get('/api/suppliers', { params: { per_page: 200 } })
    supplierItems.value = Array.isArray(data) ? data : data.data ?? []
  } catch { /* */ }
}

async function loadSupplierVersions(supplierId: number) {
  loadingVersions.value = true
  try {
    // Load material price lists for this supplier
    const { data } = await api.get(`/api/suppliers/${supplierId}/price-lists`, {
      params: { type: 'materials' }
    })
    const lists = Array.isArray(data) ? data : data.data ?? []

    // Build price list dropdown items
    materialPriceListItems.value = lists.map((pl: any) => ({
      id: pl.id,
      label: `${pl.name}${pl.active_version ? ' (активна)' : ''}`,
    }))

    // Build version items for "existing" mode
    const versions: any[] = []
    for (const pl of lists) {
      try {
        const vResp = await api.get(`/api/price-lists/${pl.id}/versions`)
        const vData = vResp.data.data ?? vResp.data ?? []
        for (const v of vData) {
          const statusMap: Record<string, { label: string; color: string }> = {
            active: { label: 'Активна', color: 'success' },
            inactive: { label: 'Неактивна', color: 'grey' },
            archived: { label: 'Архив', color: 'warning' },
          }
          const st = statusMap[v.status] ?? { label: v.status, color: 'grey' }
          versions.push({
            id: v.id,
            label: `${pl.name} — v${v.version_number} (${v.captured_at ? new Date(v.captured_at).toLocaleDateString('ru-RU') : '?'})${v.original_filename ? ' — ' + v.original_filename : ''}`,
            statusColor: st.color,
            statusLabel: st.label,
            sourceType: v.source_type ?? null,
          })
        }
      } catch { /* ignore */ }
    }
    versionItems.value = versions
  } catch {
    versionItems.value = []
    materialPriceListItems.value = []
  } finally {
    loadingVersions.value = false
  }
}

async function loadSupplierDocuments(supplierId: number) {
  try {
    const res = await priceListsApi.getPriceDocuments(supplierId, { purpose: 'finished_products' })
    const docs = res.data ?? []
    documentItems.value = docs.map((d: any) => {
      const statusMap: Record<string, { label: string; color: string }> = {
        active: { label: 'Активна', color: 'success' },
        inactive: { label: 'Неактивна', color: 'grey' },
        archived: { label: 'Архив', color: 'warning' },
      }
      const st = statusMap[d.status] ?? { label: d.status, color: 'grey' }
      const datePart = d.captured_at ? new Date(d.captured_at).toLocaleDateString('ru-RU') : '?'
      const namePart = d.original_filename || d.source_url || 'Документ'
      return {
        id: d.version_id,
        label: `${d.price_list_name} — v${d.version_number} (${datePart}) — ${namePart}`,
        statusColor: st.color,
        statusLabel: st.label,
        sourceType: d.source_type ?? null,
      }
    })
  } catch {
    documentItems.value = []
  }
}

function sourceTypeColor(t: string) {
  return t === 'file' ? 'blue' : t === 'url' ? 'teal' : 'grey'
}

function sourceTypeLabel(t: string) {
  return t === 'file' ? 'Файл' : t === 'url' ? 'URL' : t === 'manual' ? 'Ручной' : t
}

function formatPrice(val: number | null) {
  if (!val) return '—'
  return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val)
}

function close() {
  if (wizardMode.value && createdFacadeId.value) {
    // User created a facade and possibly added quotes — emit saved
    emit('saved')
  }
  emit('update:modelValue', false)
}

async function save() {
  saving.value = true
  try {
    const payload: any = { ...form.value }
    if (autoName.value) {
      payload.auto_name = true
      delete payload.name
    }

    if (isNew.value && !createdFacadeId.value) {
      // Create mode → save and switch to quotes step
      const created = await store.createFacade(payload)
      if (created) {
        createdFacadeId.value = created.id
        activeStep.value = 1
        // Don't emit saved yet — user may want to add quotes
      }
    } else {
      // Edit mode
      const facadeId = currentFacadeId.value!
      await store.updateFacade(facadeId, payload)
      emit('saved')
      emit('update:modelValue', false)
    }
  } catch (e) {
    console.error('Save failed', e)
  } finally {
    saving.value = false
  }
}

function openAddQuote() {
  newQuote.value = {
    supplier_id: null,
    price_list_version_id: null,
    source_price: null,
    source_unit: 'м²',
    conversion_factor: 1.0,
    article: '',
    category: '',
    description: '',
  }
  quoteMode.value = 'quick'
  sourceMode.value = 'existing'
  selectedPriceListId.value = null
  uploadFile.value = null
  newVersionUrl.value = ''
  newVersionEffectiveDate.value = ''
  newVersionManualLabel.value = ''
  quoteError.value = ''
  clearQuoteFieldErrors()
  showAddQuote.value = true
}

async function saveQuote() {
  if (!currentFacadeId.value) return

  savingQuote.value = true
  quoteError.value = ''
  if (!validateQuoteFields()) {
    savingQuote.value = false
    return
  }

  try {
    let versionId = newQuote.value.price_list_version_id

    // 'document' mode uses the version directly (same as 'existing')
    // For other non-existing modes, create a new version
    if (sourceMode.value !== 'existing' && sourceMode.value !== 'document') {
      if (!selectedPriceListId.value) return

      const versionPayload: any = {
        price_list_id: selectedPriceListId.value,
        source_type: sourceMode.value === 'file' ? 'file' : sourceMode.value === 'url' ? 'url' : 'manual',
      }

      if (sourceMode.value === 'file' && uploadFile.value) {
        versionPayload.file = Array.isArray(uploadFile.value) ? uploadFile.value[0] : uploadFile.value
      }
      if (sourceMode.value === 'url') {
        versionPayload.source_url = newVersionUrl.value
      }
      if (newVersionEffectiveDate.value) {
        versionPayload.effective_date = newVersionEffectiveDate.value
      }
      if (sourceMode.value === 'manual' && newVersionManualLabel.value) {
        versionPayload.manual_label = newVersionManualLabel.value
      }

      const createdVersion = await priceListsApi.createVersion(versionPayload)
      versionId = createdVersion.id
    }

    if (!versionId) {
      quoteError.value = 'Не удалось определить версию прайса'
      return
    }

    // Create the quote
    await store.createQuote({
      material_id: currentFacadeId.value,
      supplier_id: newQuote.value.supplier_id!,
      price_list_version_id: versionId,
      source_price: newQuote.value.source_price!,
      source_unit: newQuote.value.source_unit || undefined,
      conversion_factor: newQuote.value.conversion_factor || undefined,
      article: newQuote.value.article || undefined,
      category: newQuote.value.category || undefined,
      description: newQuote.value.description || undefined,
    })

    showAddQuote.value = false
    clearQuoteFieldErrors()
    await loadQuotes()

    // Refresh versions list for this supplier (new version may have been created)
    if (sourceMode.value !== 'existing' && newQuote.value.supplier_id) {
      await loadSupplierVersions(newQuote.value.supplier_id)
    }
  } catch (e: any) {
    quoteError.value = e.response?.data?.message ?? e.message ?? 'Ошибка добавления котировки'
  } finally {
    savingQuote.value = false
  }
}

function openEditQuote(quote: FacadeQuote) {
  editingQuoteId.value = quote.id
  editQuoteData.value = {
    source_price: quote.source_price ?? quote.price_per_m2,
    source_unit: quote.source_unit ?? 'м²',
    conversion_factor: quote.conversion_factor ?? 1.0,
    article: quote.article ?? '',
    category: quote.category ?? '',
    description: quote.description ?? '',
  }
  showEditQuote.value = true
}

async function doUpdateQuote() {
  if (!editingQuoteId.value || !editQuoteData.value.source_price) return
  savingQuote.value = true
  try {
    await store.updateQuote(editingQuoteId.value, {
      source_price: editQuoteData.value.source_price,
      source_unit: editQuoteData.value.source_unit || undefined,
      conversion_factor: editQuoteData.value.conversion_factor || undefined,
      article: editQuoteData.value.article || null,
      category: editQuoteData.value.category || null,
      description: editQuoteData.value.description || null,
    })
    showEditQuote.value = false
    await loadQuotes()
  } catch (e: any) {
    quoteError.value = e.response?.data?.message ?? e.message ?? 'Ошибка обновления'
  } finally {
    savingQuote.value = false
  }
}

function confirmDeleteQuote(quote: FacadeQuote) {
  deletingQuote.value = quote
  showDeleteQuote.value = true
}

async function doDeleteQuote() {
  if (!deletingQuote.value) return
  savingQuote.value = true
  try {
    await store.deleteQuote(deletingQuote.value.id)
    showDeleteQuote.value = false
    deletingQuote.value = null
    await loadQuotes()
  } catch (e: any) {
    quoteError.value = e.response?.data?.message ?? e.message ?? 'Ошибка удаления котировки'
  } finally {
    savingQuote.value = false
  }
}

function confirmDeleteFacade() {
  showDeleteFacade.value = true
}

async function doDeleteFacade() {
  if (!currentFacadeId.value) return
  saving.value = true
  try {
    await store.deleteFacade(currentFacadeId.value)
    showDeleteFacade.value = false
    emit('saved')
    emit('update:modelValue', false)
  } catch (e: any) {
    quoteError.value = e.response?.data?.message ?? e.message ?? 'Ошибка удаления фасада'
  } finally {
    saving.value = false
  }
}

function onQuotesTableWheel(event: WheelEvent) {
  const container = event.currentTarget as HTMLElement | null
  if (!container) return

  const delta = Math.abs(event.deltaY) > Math.abs(event.deltaX) ? event.deltaY : event.deltaX
  container.scrollLeft += delta
}
</script>

<style scoped>
.quotes-table-scroll {
  overflow-x: auto;
}

.source-mode-toggle-scroll {
  overflow-x: auto;
  margin-bottom: 8px;
}

.source-mode-toggle {
  display: inline-flex;
  flex-wrap: nowrap;
}

:deep(.quotes-table .v-table__wrapper) {
  min-width: 100%;
}

:deep(.quotes-table .v-table__wrapper table) {
  min-width: 980px;
}

:deep(.quotes-table th),
:deep(.quotes-table td) {
  white-space: nowrap;
}

.quotes-table-scroll {
  scrollbar-width: thin;
}

@media (max-width: 760px) {
  .source-mode-toggle-scroll {
    padding-bottom: 4px;
  }
}
</style>
