<template>
  <v-dialog :model-value="modelValue" @update:model-value="$emit('update:modelValue', $event)" max-width="720" persistent>
    <v-card>
      <v-card-title class="d-flex align-center">
        <span>Обоснование позиции</span>
        <v-spacer />
        <v-btn icon variant="text" size="small" @click="close">
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </v-card-title>

      <v-card-text>
        <!-- Item context -->
        <v-alert variant="tonal" density="compact" type="info" class="mb-4">
          <strong>{{ item?.label || '—' }}</strong>
          <span v-if="item?.cost_component" class="ml-2 text-caption">
            ({{ componentLabel(item.cost_component) }})
          </span>
        </v-alert>

        <v-tabs v-model="activeTab" density="compact" color="primary">
          <v-tab value="picker">Выбрать существующее</v-tab>
          <v-tab value="manual">Загрузить вручную</v-tab>
          <v-tab value="skip">Пропустить</v-tab>
        </v-tabs>

        <v-window v-model="activeTab" class="mt-3">
          <!-- ═══ Tab 1: Evidence Record Picker ═══ -->
          <v-window-item value="picker">
            <v-text-field
              v-model="searchQuery"
              label="Поиск по названию, URL или артикулу"
              variant="outlined"
              density="compact"
              prepend-inner-icon="mdi-magnify"
              clearable
              hide-details
              class="mb-3"
              @update:model-value="debouncedSearch"
            />

            <v-table density="compact" hover class="evidence-picker-table" fixed-header height="280">
              <thead>
                <tr>
                  <th style="width: 36px"></th>
                  <th>Название / URL</th>
                  <th style="width: 110px">Цена</th>
                  <th style="width: 90px">Метод</th>
                  <th style="width: 110px">Дата</th>
                  <th style="width: 36px"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="pickerLoading">
                  <td colspan="6" class="text-center py-4">
                    <v-progress-circular indeterminate size="24" />
                  </td>
                </tr>
                <tr v-else-if="pickerRecords.length === 0">
                  <td colspan="6" class="text-center py-4 text-medium-emphasis">
                    {{ searchQuery ? 'Ничего не найдено' : 'Нет доступных записей обоснований' }}
                  </td>
                </tr>
                <tr
                  v-for="rec in pickerRecords"
                  :key="rec.id"
                  :class="{ 'bg-primary-lighten-5': selectedRecordId === rec.id }"
                  style="cursor: pointer"
                  @click="selectedRecordId = rec.id"
                >
                  <td>
                    <v-radio-group v-model="selectedRecordId" hide-details class="ma-0 pa-0">
                      <v-radio :value="rec.id" density="compact" />
                    </v-radio-group>
                  </td>
                  <td>
                    <div class="text-body-2">{{ rec.extracted_name || '—' }}</div>
                    <div v-if="rec.source_domain" class="text-caption text-medium-emphasis">
                      {{ rec.source_domain }}
                    </div>
                  </td>
                  <td>
                    <template v-if="rec.observed_price">
                      {{ rec.observed_price }} {{ rec.currency || '₽' }}
                    </template>
                    <span v-else class="text-medium-emphasis">—</span>
                  </td>
                  <td>
                    <v-chip size="x-small" variant="tonal" :color="captureMethodColor(rec.capture_method)">
                      {{ captureMethodLabel(rec.capture_method) }}
                    </v-chip>
                  </td>
                  <td class="text-caption">
                    {{ formatShortDate(rec.observed_at || rec.created_at) }}
                  </td>
                  <td>
                    <v-icon v-if="rec.has_screenshot" size="small" color="success">mdi-image-check</v-icon>
                  </td>
                </tr>
              </tbody>
            </v-table>

            <!-- Pagination -->
            <div v-if="pickerMeta.last_page > 1" class="d-flex justify-center mt-2">
              <v-pagination
                v-model="pickerPage"
                :length="pickerMeta.last_page"
                :total-visible="5"
                density="compact"
                size="small"
                @update:model-value="loadRecords"
              />
            </div>

            <!-- Hidden debug fallback: raw ID input -->
            <v-expansion-panels variant="accordion" class="mt-3">
              <v-expansion-panel>
                <v-expansion-panel-title class="text-caption text-medium-emphasis py-1">
                  Ввести ID вручную (для отладки)
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                  <v-text-field
                    v-model.number="rawRecordId"
                    label="evidence_record_id"
                    type="number"
                    variant="outlined"
                    density="compact"
                    hide-details
                  />
                </v-expansion-panel-text>
              </v-expansion-panel>
            </v-expansion-panels>
          </v-window-item>

          <!-- ═══ Tab 2: Manual Upload ═══ -->
          <v-window-item value="manual">
            <v-file-input
              v-model="manualFile"
              label="Файл обоснования (скриншот, документ)"
              variant="outlined"
              density="compact"
              prepend-icon="mdi-paperclip"
              accept="image/*,.pdf,.doc,.docx"
              :rules="[v => !!v || 'Выберите файл']"
              show-size
            />

            <v-text-field
              v-model="manualPrice"
              label="Наблюдаемая цена *"
              type="number"
              variant="outlined"
              density="compact"
              :rules="[v => (v !== '' && v !== null && Number(v) >= 0) || 'Укажите цену']"
            />

            <v-text-field
              v-model="manualCurrency"
              label="Валюта"
              variant="outlined"
              density="compact"
              placeholder="RUB"
            />

            <v-text-field
              v-model="manualSourceUrl"
              label="URL источника (необязательно)"
              variant="outlined"
              density="compact"
              placeholder="https://..."
            />

            <v-text-field
              v-model="manualName"
              label="Наименование (необязательно)"
              variant="outlined"
              density="compact"
            />
          </v-window-item>

          <!-- ═══ Tab 3: Skip ═══ -->
          <v-window-item value="skip">
            <v-textarea
              v-model="skipReason"
              label="Причина пропуска (необязательно)"
              rows="3"
              variant="outlined"
              density="compact"
              counter="500"
              :maxlength="500"
            />
          </v-window-item>
        </v-window>

        <!-- Error feedback -->
        <v-alert v-if="errorMessage" type="error" variant="tonal" density="compact" class="mt-3">
          {{ errorMessage }}
        </v-alert>
      </v-card-text>

      <v-card-actions>
        <v-spacer />
        <v-btn @click="close">Отмена</v-btn>
        <v-btn
          v-if="activeTab === 'picker'"
          color="primary"
          :loading="loading"
          :disabled="!effectiveRecordId"
          @click="submitPicker"
        >
          Подтвердить
        </v-btn>
        <v-btn
          v-else-if="activeTab === 'manual'"
          color="primary"
          :loading="loading"
          :disabled="!manualFile || manualPrice === '' || manualPrice === null"
          @click="submitManual"
        >
          Загрузить и подтвердить
        </v-btn>
        <v-btn
          v-else-if="activeTab === 'skip'"
          color="warning"
          :loading="loading"
          @click="submitSkip"
        >
          Пропустить
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import type { EvidenceItem, EvidenceRecordPickerItem } from '@/api/evidenceRun'
import { evidenceRunApi } from '@/api/evidenceRun'
import { COST_COMPONENT_LABELS } from '@/composables/useEvidenceRun'

const props = defineProps<{
  modelValue: boolean
  item: EvidenceItem | null
  mode: 'resolve' | 'skip'
  loading?: boolean
  errorMessage?: string | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  resolve: [itemId: number, evidenceRecordId: number]
  skip: [itemId: number, reason: string]
  manualResolve: [itemId: number, formData: FormData]
}>()

// ── Tab state ──
const activeTab = ref<'picker' | 'manual' | 'skip'>('picker')

// ── Picker state ──
const searchQuery = ref('')
const pickerRecords = ref<EvidenceRecordPickerItem[]>([])
const pickerLoading = ref(false)
const pickerPage = ref(1)
const pickerMeta = ref({ current_page: 1, last_page: 1, per_page: 20, total: 0 })
const selectedRecordId = ref<number | null>(null)
const rawRecordId = ref<number | null>(null)

const effectiveRecordId = computed(() => selectedRecordId.value || rawRecordId.value)

// ── Manual upload state ──
const manualFile = ref<File | null>(null)
const manualPrice = ref<string | number>('')
const manualCurrency = ref('RUB')
const manualSourceUrl = ref('')
const manualName = ref('')

// ── Skip state ──
const skipReason = ref('')

// ── Debounced search ──
let searchTimer: ReturnType<typeof setTimeout> | null = null
function debouncedSearch() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    pickerPage.value = 1
    loadRecords()
  }, 350)
}

async function loadRecords() {
  pickerLoading.value = true
  try {
    const res = await evidenceRunApi.searchRecords({
      q: searchQuery.value || undefined,
      cost_component: props.item?.cost_component || undefined,
      per_page: 20,
      page: pickerPage.value,
    })
    pickerRecords.value = res.data
    pickerMeta.value = res.meta
  } catch {
    pickerRecords.value = []
  } finally {
    pickerLoading.value = false
  }
}

// ── Reset on open / close ──
watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      activeTab.value = props.mode === 'skip' ? 'skip' : 'picker'
      searchQuery.value = ''
      selectedRecordId.value = null
      rawRecordId.value = null
      manualFile.value = null
      manualPrice.value = ''
      manualCurrency.value = 'RUB'
      manualSourceUrl.value = ''
      manualName.value = ''
      skipReason.value = ''
      pickerPage.value = 1
      loadRecords()
    }
  },
)

// ── Helpers ──
function componentLabel(comp: string): string {
  return COST_COMPONENT_LABELS[comp] ?? comp
}

function captureMethodLabel(m: string | null): string {
  const map: Record<string, string> = {
    chrome_extension: 'Chrome',
    file_upload: 'Файл',
    manual_entry: 'Ручной',
    auto_scrape: 'Авто',
    api_import: 'API',
  }
  return m ? (map[m] ?? m) : '—'
}

function captureMethodColor(m: string | null): string {
  const map: Record<string, string> = {
    chrome_extension: 'blue',
    file_upload: 'purple',
    manual_entry: 'orange',
    auto_scrape: 'teal',
  }
  return m ? (map[m] ?? 'grey') : 'grey'
}

function formatShortDate(iso: string | null): string {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleDateString('ru-RU', {
      day: '2-digit',
      month: '2-digit',
      year: '2-digit',
    })
  } catch {
    return iso
  }
}

function close() {
  emit('update:modelValue', false)
}

// ── Submit handlers ──

function submitPicker() {
  if (!props.item || !effectiveRecordId.value) return
  emit('resolve', props.item.id, effectiveRecordId.value)
}

function submitManual() {
  if (!props.item || !manualFile.value || manualPrice.value === '' || manualPrice.value === null) return
  const fd = new FormData()
  fd.append('file', manualFile.value)
  fd.append('observed_price', String(manualPrice.value))
  if (manualCurrency.value) fd.append('currency', manualCurrency.value)
  if (manualSourceUrl.value) fd.append('source_url', manualSourceUrl.value)
  if (manualName.value) fd.append('extracted_name', manualName.value)
  emit('manualResolve', props.item.id, fd)
}

function submitSkip() {
  if (!props.item) return
  emit('skip', props.item.id, skipReason.value)
}
</script>

<style scoped>
.evidence-picker-table tbody tr:hover {
  background: rgba(var(--v-theme-primary), 0.04);
}
</style>
}
</script>
