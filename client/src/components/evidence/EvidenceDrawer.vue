<template>
  <v-dialog v-model="dialog" max-width="720" scrollable>
    <v-card>
      <!-- Header -->
      <v-card-title class="d-flex align-center pa-4 pb-3">
        <v-btn
          v-if="selectedRecord"
          icon="mdi-arrow-left"
          variant="text"
          size="small"
          class="mr-2"
          @click="selectedRecord = null; detailError = null"
        />
        <v-icon v-else size="small" class="mr-2" color="primary">mdi-file-document-check-outline</v-icon>
        <span class="text-subtitle-1 font-weight-medium">
          {{ selectedRecord ? 'Обоснование #' + selectedRecord.evidence_record_id : (title || 'Обоснования') }}
        </span>
        <v-spacer />
        <span v-if="!selectedRecord && !loading" class="text-caption text-medium-emphasis mr-3">
          Всего: {{ meta.total }}
        </span>
        <v-btn icon="mdi-close" variant="text" size="small" @click="dialog = false" />
      </v-card-title>

      <v-divider />

      <!-- Body -->
      <v-card-text class="pa-0" style="min-height: 280px; max-height: 65vh; overflow-y: auto">
        <!-- ── LIST VIEW ── -->
        <template v-if="!selectedRecord">
          <div v-if="loading" class="d-flex justify-center align-center py-10">
            <v-progress-circular indeterminate color="primary" />
          </div>

          <v-list v-else-if="records.length" lines="two" class="py-0">
            <template v-for="(rec, idx) in records" :key="rec.id">
              <v-list-item
                class="px-4"
                style="cursor: pointer"
                @click="openDetail(rec.id)"
              >
                <template #prepend>
                  <v-chip
                    size="x-small"
                    :color="statusColor(rec.verification_status)"
                    variant="tonal"
                    class="mr-3 flex-shrink-0"
                    style="min-width: 90px; justify-content: center"
                  >
                    {{ statusLabel(rec.verification_status) }}
                  </v-chip>
                </template>

                <template #title>
                  <span class="font-weight-medium">
                    {{ formatPrice(rec.observed_price, rec.currency) }}
                  </span>
                  <span class="text-caption text-medium-emphasis ml-2">
                    {{ sourceTypeLabel(rec.source_type) }}
                  </span>
                </template>

                <template #subtitle>
                  <span class="text-caption">{{ formatDate(rec.created_at) }}</span>
                  <span v-if="rec.assets_count" class="text-caption ml-3">
                    <v-icon size="x-small" class="mr-1">mdi-paperclip</v-icon>{{ rec.assets_count }}
                  </span>
                </template>

                <template #append>
                  <div class="d-flex align-center ga-1">
                    <v-btn
                      icon="mdi-link-off"
                      size="x-small"
                      variant="text"
                      color="error"
                      title="Удалить связь"
                      :loading="detachLoading[rec.id]"
                      @click.stop="confirmDetach(rec.id)"
                    />
                    <v-icon size="small" color="grey-lighten-1">mdi-chevron-right</v-icon>
                  </div>
                </template>
              </v-list-item>
              <v-divider v-if="idx < records.length - 1" />
            </template>
          </v-list>

          <div v-else class="text-center text-medium-emphasis py-10">
            <v-icon size="48" color="grey-lighten-2" class="mb-3">mdi-file-document-outline</v-icon>
            <div class="text-body-2 mb-1">Обоснований не найдено</div>
            <div class="text-caption">Используйте кнопку «Добавить обоснование» чтобы добавить первую запись</div>
          </div>
        </template>

        <!-- ── DETAIL VIEW ── -->
        <template v-else>
          <div v-if="detailLoading" class="d-flex justify-center align-center py-10">
            <v-progress-circular indeterminate color="primary" />
          </div>

          <div v-else-if="detailError" class="pa-6 text-center">
            <v-icon size="36" color="error" class="mb-2">mdi-alert-circle-outline</v-icon>
            <div class="text-body-2 text-error mb-3">{{ detailError }}</div>
            <v-btn size="small" variant="tonal" @click="selectedRecord = null">Назад к списку</v-btn>
          </div>

          <div v-else class="pa-4">
            <!-- Status chip + action buttons -->
            <div class="d-flex align-center ga-2 mb-4 flex-wrap">
              <v-chip
                :color="statusColor(selectedRecord.verification_status)"
                variant="tonal"
                size="small"
              >
                {{ statusLabel(selectedRecord.verification_status) }}
              </v-chip>
              <v-btn
                v-if="canTransition(selectedRecord.verification_status, 'manual_verified')"
                size="x-small"
                color="success"
                variant="tonal"
                :loading="statusLoading"
                @click="changeStatus('manual_verified')"
              >
                Подтвердить
              </v-btn>
              <v-btn
                v-if="canTransition(selectedRecord.verification_status, 'rejected')"
                size="x-small"
                color="error"
                variant="tonal"
                :loading="statusLoading"
                @click="changeStatus('rejected')"
              >
                Отклонить
              </v-btn>
            </div>

            <!-- Core fields table -->
            <v-table density="compact" class="mb-4 rounded border">
              <tbody>
                <tr>
                  <td class="text-medium-emphasis text-caption" style="width: 160px">Цена</td>
                  <td class="font-weight-medium">{{ formatPrice(selectedRecord.observed_price, selectedRecord.currency) }}</td>
                </tr>
                <tr>
                  <td class="text-medium-emphasis text-caption">Тип источника</td>
                  <td>{{ sourceTypeLabel(selectedRecord.source_type) }}</td>
                </tr>
                <tr>
                  <td class="text-medium-emphasis text-caption">Компонент стоимости</td>
                  <td>{{ selectedRecord.cost_component || '—' }}</td>
                </tr>
                <tr>
                  <td class="text-medium-emphasis text-caption">Метод сбора</td>
                  <td>{{ captureMethodLabel(selectedRecord.capture_method) }}</td>
                </tr>
                <tr v-if="selectedRecord.source_url">
                  <td class="text-medium-emphasis text-caption">Ссылка</td>
                  <td>
                    <a
                      :href="selectedRecord.source_url"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="text-decoration-none text-primary text-caption"
                    >
                      {{ selectedRecord.source_url }}
                    </a>
                  </td>
                </tr>
                <tr v-if="selectedRecord.metadata_json?.['justification_text']">
                  <td class="text-medium-emphasis text-caption">Примечание</td>
                  <td class="text-caption">{{ selectedRecord.metadata_json?.['justification_text'] }}</td>
                </tr>
                <tr>
                  <td class="text-medium-emphasis text-caption">Создано</td>
                  <td class="text-caption">{{ formatDate(selectedRecord.created_at) }}</td>
                </tr>
              </tbody>
            </v-table>

            <!-- Assets -->
            <div v-if="selectedRecord.assets.length">
              <div class="text-caption text-medium-emphasis mb-2 font-weight-medium">
                Вложения ({{ selectedRecord.assets.length }})
              </div>
              <v-list density="compact" class="rounded border pa-0">
                <template v-for="(asset, i) in selectedRecord.assets" :key="asset.asset_id">
                  <v-list-item class="px-3">
                    <template #prepend>
                      <v-icon size="small" color="primary" class="mr-2">mdi-paperclip</v-icon>
                    </template>
                    <template #title>
                      <span class="text-body-2">{{ asset.original_filename || 'Файл' }}</span>
                    </template>
                    <template #subtitle>
                      <span class="text-caption">
                        {{ asset.mime_type || '' }}
                        <span v-if="asset.file_size" class="ml-2">{{ formatBytes(asset.file_size) }}</span>
                      </span>
                    </template>
                    <template #append>
                      <v-btn
                        v-if="asset.download_url"
                        :href="asset.download_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        icon="mdi-download"
                        size="x-small"
                        variant="text"
                        color="primary"
                        title="Скачать"
                      />
                    </template>
                  </v-list-item>
                  <v-divider v-if="i < selectedRecord.assets.length - 1" />
                </template>
              </v-list>
            </div>

            <div v-else class="text-caption text-medium-emphasis mt-2">
              Вложений нет
            </div>
          </div>
        </template>
      </v-card-text>

      <!-- In-dialog snackbar -->
      <v-snackbar
        v-model="snack.show"
        :color="snack.color"
        :timeout="3000"
        location="bottom"
        variant="tonal"
        density="compact"
        style="position: absolute; bottom: 8px; left: 8px; right: 8px; width: auto"
      >
        {{ snack.message }}
        <template #actions>
          <v-btn size="x-small" variant="text" @click="snack.show = false">×</v-btn>
        </template>
      </v-snackbar>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { evidenceRunApi, type EvidenceListItem, type EvidenceRecordDetail } from '@/api/evidenceRun'

const props = defineProps<{
  modelValue: boolean
  linkableType: string
  linkableId: number
  title?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  detached: []
}>() 

const dialog = ref(props.modelValue)

watch(() => props.modelValue, (val) => {
  dialog.value = val
  if (val) fetchList()
})

watch(dialog, (val) => {
  emit('update:modelValue', val)
  if (!val) {
    selectedRecord.value = null
  }
})

// ── State ──
const loading = ref(false)
const detailLoading = ref(false)
const detailError = ref<string | null>(null)
const statusLoading = ref(false)
const records = ref<EvidenceListItem[]>([])
const meta = ref({ total: 0, current_page: 1, last_page: 1, per_page: 15 })
const selectedRecord = ref<EvidenceRecordDetail | null>(null)
/** evidence_record_id → evidence_link_id for this drawer's linkable target */
const linkMap = ref<Map<number, number>>(new Map())
/** per-record loading state for detach action */
const detachLoading = ref<Record<number, boolean>>({})
const snack = ref({ show: false, message: '', color: 'success' })

function toast(message: string, color: 'success' | 'error' | 'info' = 'success') {
  snack.value = { show: true, message, color }
}
const detachLoading = ref<Record<number, boolean>>({})
const snack = ref({ show: false, message: '', color: 'success' })

function toast(message: string, color: 'success' | 'error' | 'info' = 'success') {
  snack.value = { show: true, message, color }
}

// ── Data fetching ──
async function fetchList() {
  loading.value = true
  records.value = []
  selectedRecord.value = null
  linkMap.value = new Map()
  try {
    const [listRes, linksRes] = await Promise.all([
      evidenceRunApi.listRecords({
        linkable_type: props.linkableType,
        linkable_id: props.linkableId,
        per_page: 50,
      }),
      evidenceRunApi.listLinks(
        props.linkableType as 'operation_price' | 'price_list_version',
        props.linkableId,
      ).catch(() => ({ data: [] })),
    ])
    records.value = listRes.data
    meta.value = listRes.meta
    // Build recordId → linkId map
    const map = new Map<number, number>()
    for (const link of linksRes.data) {
      map.set(link.evidence_record_id, link.evidence_link_id)
    }
    linkMap.value = map
  } catch {
    // silent — empty state shown
  } finally {
  detailError.value = null
  selectedRecord.value = null
  // Set a placeholder so the detail panel is shown while loading
  selectedRecord.value = { evidence_record_id: id } as EvidenceRecordDetail
  try {
    const res = await evidenceRunApi.getRecord(id)
    selectedRecord.value = res.data
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    detailError.value = e.response?.data?.message || 'Ошибка загрузки обоснования'
    selectedRecord.value = { evidence_record_id: id } as EvidenceRecordDetai
  // Set a placeholder so the detail panel is shown while loading
  selectedRecord.value = { evidence_record_id: id } as EvidenceRecordDetail
  try {
    const res = await evidenceRunApi.getRecord(id)
    selectedRecord.value = res.data
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    detailError.value = e.response?.data?.message || 'Ошибка загрузки обоснования'
    selectedRecord.value = { evidence_record_id: id } as EvidenceRecordDetail
  } finally {
    detailLoading.value = false
  }
}

async function confirmDetach(recordId: number) {
  if (!confirm('Удалить связь с этим обоснованием? Сама запись не удаляется.')) return
  const linkId = linkMap.value.get(recordId)
  if (!linkId) return
  detachLoading.value[recordId] = true
  try {
    await evidenceRunApi.detachLink(
    toast('Связь удалена')
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    toast(e.response?.data?.message || 'Ошибка при удалении связи', 'error')inkableId,
      linkId,
    )
    // Remove from local list
    records.value = records.value.filter(r => r.id !== recordId)
    meta.value.total = Math.max(0, meta.value.total - 1)
    linkMap.value.delete(recordId)
    emit('detached')
    toast('Связь удалена')
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string } } }
    toast(e.response?.data?.message || 'Ошибка при удалении связи', 'error')
  } finally {
    delete detachLoading.value[recordId]
  }
}

async function changeStatus(newStatus: string) {
  if (!selectedRecord.value) return
    toast('Статус обновлён')
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
    const fieldErr = e.response?.data?.errors?.['verification_status']?.[0]
    toast(fieldErr || e.response?.data?.message || 'Ошибка при обновлении статуса', 'error')
    const res = await evidenceRunApi.updateVerificationStatus(
      selectedRecord.value.evidence_record_id,
      newStatus,
    )
    const updated = res.data.verification_status
    selectedRecord.value = { ...selectedRecord.value, verification_status: updated }
    // Sync in list
    const rec = records.value.find(r => r.id === selectedRecord.value!.evidence_record_id)
    if (rec) rec.verification_status = updated
    toast('Статус обновлён')
  } catch (err: unknown) {
    const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
    const fieldErr = e.response?.data?.errors?.['verification_status']?.[0]
    toast(fieldErr || e.response?.data?.message || 'Ошибка при обновлении статуса', 'error')
  } finally {
    statusLoading.value = false
  }
}

/** Whether a transition from `from` to `to` is allowed. */
function canTransition(from: string | null, to: string): boolean {
  const allowed: Record<string, string[]> = {
    pending:       ['manual_verified', 'rejected'],
    stale:         ['manual_verified', 'rejected'],
    auto_verified: ['manual_verified', 'rejected'],
  }
  return allowed[from ?? '']?.includes(to) ?? false
}

// ── Formatting helpers ──
function formatPrice(price: string | null, currency: string | null): string {
  if (!price) return '—'
  const num = parseFloat(price)
  if (isNaN(num)) return price
  return new Intl.NumberFormat('ru-RU', {
    style: 'decimal',
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(num) + ' ' + (currency || 'RUB')
}

function formatDate(dt: string | null): string {
  if (!dt) return '—'
  try {
    return new Date(dt).toLocaleDateString('ru-RU', {
      day: '2-digit', month: '2-digit', year: 'numeric',
    })
  } catch {
    return dt
  }
}

function formatBytes(bytes: number | null): string {
  if (!bytes) return ''
  if (bytes < 1024) return `${bytes} Б`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} КБ`
  return `${(bytes / 1024 / 1024).toFixed(1)} МБ`
}

function statusColor(status: string | null): string {
  switch (status) {
    case 'manual_verified': return 'success'
    case 'auto_verified':   return 'info'
    case 'rejected':        return 'error'
    case 'stale':           return 'warning'
    case 'pending':
    default:                return 'grey'
  }
}

function statusLabel(status: string | null): string {
  switch (status) {
    case 'manual_verified': return 'Подтверждено'
    case 'auto_verified':   return 'Авт. провер.'
    case 'rejected':        return 'Отклонено'
    case 'stale':           return 'Устарело'
    case 'pending':         return 'Ожидание'
    default:                return status || '—'
  }
}

function sourceTypeLabel(type: string | null): string {
  switch (type) {
    case 'supplier_website': return 'Сайт поставщика'
    case 'manual_input':     return 'Ручной ввод'
    case 'internal_calc':    return 'Расчёт'
    case 'document':         return 'Документ'
    case 'chrome_capture':   return 'Chrome'
    default:                 return type || '—'
  }
}

function captureMethodLabel(method: string | null): string {
  switch (method) {
    case 'manual_entry':    return 'Ручной ввод'
    case 'file_upload':     return 'Загрузка файла'
    case 'chrome_extension': return 'Chrome Extension'
    case 'auto_scrape':     return 'Авто-сбор'
    case 'api_import':      return 'API'
    default:                return method || '—'
  }
}
</script>
