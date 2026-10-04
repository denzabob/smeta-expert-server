<template>
  <PageContainer>
    <PageHeader
      title="Диагностика Expert"
      subtitle="Техническая последовательность выполнения Expert Chat по run_id"
    />

    <v-card class="mb-4" variant="outlined">
      <v-card-text>
        <div class="d-flex flex-wrap align-center ga-2 mb-4" role="group" aria-label="Фильтр по статусу">
          <v-btn
            v-for="preset in statusPresets"
            :key="preset.value"
            size="small"
            :variant="statusPreset === preset.value ? 'flat' : 'tonal'"
            :color="statusPreset === preset.value ? 'primary' : undefined"
            @click="selectStatus(preset.value)"
          >
            {{ preset.label }}
          </v-btn>
        </div>

        <v-row dense>
          <v-col cols="12" md="4">
            <v-text-field
              v-model="filters.run_id"
              label="Run ID"
              placeholder="Вставьте UUID или его начало"
              prepend-inner-icon="mdi-magnify"
              clearable
              hide-details
              @keyup.enter="applyFilters"
            />
          </v-col>
          <v-col cols="12" sm="6" md="2">
            <v-text-field v-model="filters.error_code" label="Код ошибки" clearable hide-details @keyup.enter="applyFilters" />
          </v-col>
          <v-col cols="12" sm="6" md="2">
            <v-text-field v-model="filters.stage" label="Этап" clearable hide-details @keyup.enter="applyFilters" />
          </v-col>
          <v-col cols="12" sm="6" md="2">
            <v-text-field v-model="filters.user_id" label="ID пользователя" type="number" min="1" clearable hide-details @keyup.enter="applyFilters" />
          </v-col>
          <v-col cols="12" sm="6" md="2">
            <v-text-field v-model="filters.project_id" label="ID проекта" type="number" min="1" clearable hide-details @keyup.enter="applyFilters" />
          </v-col>
          <v-col cols="12" sm="6" md="2">
            <v-text-field v-model="filters.conversation_id" label="ID диалога" type="number" min="1" clearable hide-details @keyup.enter="applyFilters" />
          </v-col>
          <v-col cols="12" sm="6" md="2">
            <v-text-field v-model="filters.date_from" label="Дата с" type="date" hide-details />
          </v-col>
          <v-col cols="12" sm="6" md="2">
            <v-text-field v-model="filters.date_to" label="Дата по" type="date" hide-details />
          </v-col>
          <v-col cols="12" sm="6" md="2">
            <v-text-field v-model="filters.provider" label="Провайдер" clearable hide-details @keyup.enter="applyFilters" />
          </v-col>
          <v-col cols="12" sm="6" md="2">
            <v-text-field v-model="filters.model" label="Модель" clearable hide-details @keyup.enter="applyFilters" />
          </v-col>
          <v-col cols="12" sm="6" md="2">
            <v-text-field v-model="filters.requested_mode" label="Запрошенный режим" clearable hide-details @keyup.enter="applyFilters" />
          </v-col>
        </v-row>

        <div class="d-flex flex-wrap justify-end ga-2 mt-4">
          <v-btn variant="text" @click="clearFilters">Сбросить</v-btn>
          <v-btn variant="tonal" prepend-icon="mdi-refresh" :loading="loading" @click="loadRuns">Обновить</v-btn>
          <v-btn color="primary" prepend-icon="mdi-filter" :loading="loading" @click="applyFilters">Применить</v-btn>
        </div>
      </v-card-text>
    </v-card>

    <v-alert v-if="listError" type="error" variant="tonal" class="mb-4">
      {{ listError }}
    </v-alert>

    <v-card variant="outlined">
      <v-progress-linear v-if="loading" indeterminate color="primary" />
      <div class="run-table-wrap">
        <v-table density="comfortable" class="run-table">
          <thead>
            <tr>
              <th>Время</th>
              <th>Run ID</th>
              <th>Пользователь</th>
              <th>Проект</th>
              <th>Материалы</th>
              <th>Статус</th>
              <th>Этап</th>
              <th>Ошибка</th>
              <th class="text-no-wrap">Время выполнения</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="run in runs" :key="run.run_id" class="run-row" @click="openRun(run.run_id)">
              <td class="text-no-wrap">{{ formatDate(run.started_at) }}</td>
              <td>
                <div class="d-flex align-center ga-1">
                  <button class="run-link" type="button" @click.stop="openRun(run.run_id)">{{ shortRunId(run.run_id) }}</button>
                  <v-btn icon="mdi-content-copy" size="x-small" variant="text" aria-label="Скопировать Run ID" @click.stop="copyRunId(run.run_id)" />
                </div>
              </td>
              <td>{{ run.user?.name || nullLabel }}</td>
              <td>{{ run.project?.name || nullLabel }}</td>
              <td class="text-no-wrap">{{ run.selected_material_count }} / {{ run.persisted_material_count }} / {{ run.resolved_material_count }} / {{ run.active_material_count }}</td>
              <td><v-chip size="small" :color="statusColor(run)">{{ statusLabel(run) }}</v-chip></td>
              <td>{{ run.stage || nullLabel }}</td>
              <td><span v-if="run.error_code" class="text-error font-weight-medium">{{ run.error_code }}</span><span v-else>{{ nullLabel }}</span></td>
              <td class="text-no-wrap">{{ formatDuration(run.duration_ms) }}</td>
            </tr>
            <tr v-if="!loading && runs.length === 0">
              <td colspan="9" class="text-center py-10 text-medium-emphasis">
                <v-icon icon="mdi-text-search" size="32" class="mb-2" />
                <div>Запуски не найдены</div>
                <div class="text-caption">Измените фильтры или проверьте Run ID.</div>
              </td>
            </tr>
          </tbody>
        </v-table>
      </div>

      <v-divider />
      <div class="d-flex flex-wrap align-center justify-space-between pa-3 ga-2">
        <span class="text-body-2 text-medium-emphasis">Найдено: {{ totalRuns }}</span>
        <v-pagination
          v-model="page"
          :length="pageCount"
          :total-visible="5"
          density="comfortable"
          :disabled="loading || pageCount <= 1"
          @update:model-value="goToPage"
        />
      </div>
    </v-card>

    <v-dialog v-model="detailOpen" max-width="1100" scrollable>
      <v-card>
        <v-card-title class="d-flex align-center ga-2 py-4">
          <span>Запуск Expert Chat</span>
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" aria-label="Закрыть" @click="detailOpen = false" />
        </v-card-title>
        <v-divider />
        <v-card-text>
          <v-progress-linear v-if="detailLoading" indeterminate color="primary" class="mb-4" />
          <v-alert v-if="detailError" type="error" variant="tonal" class="mb-4">{{ detailError }}</v-alert>

          <template v-if="selectedRun">
            <div class="d-flex flex-wrap align-center ga-2 mb-4">
              <code class="run-id-full">{{ selectedRun.run_id }}</code>
              <v-btn size="small" variant="tonal" prepend-icon="mdi-content-copy" @click="copyRunId(selectedRun.run_id)">Копировать ID</v-btn>
              <v-chip size="small" :color="statusColor(selectedRun)">{{ statusLabel(selectedRun) }}</v-chip>
            </div>

            <div class="detail-grid mb-6">
              <div><span>Этап</span><strong>{{ selectedRun.stage || nullLabel }}</strong></div>
              <div><span>Начат</span><strong>{{ formatDate(selectedRun.started_at) }}</strong></div>
              <div><span>Первый токен</span><strong>{{ formatDate(selectedRun.first_token_at) }}</strong></div>
              <div><span>Завершён</span><strong>{{ formatDate(selectedRun.finished_at) }}</strong></div>
              <div><span>Длительность</span><strong>{{ formatDuration(selectedRun.duration_ms) }}</strong></div>
              <div><span>Пользователь</span><strong>{{ entityLabel(selectedRun.user?.name, selectedRun.user?.id) }}</strong></div>
              <div><span>Проект</span><strong>{{ entityLabel(selectedRun.project?.name, selectedRun.project?.id) }}</strong></div>
              <div><span>Диалог</span><strong>{{ entityLabel(undefined, selectedRun.conversation?.id) }}</strong></div>
              <div><span>Режим</span><strong>{{ modeLabel(selectedRun) }}</strong></div>
              <div><span>Провайдер / модель</span><strong>{{ joinedLabel(selectedRun.provider, selectedRun.model) }}</strong></div>
              <div><span>Код ошибки</span><strong :class="{ 'text-error': selectedRun.error_code }">{{ selectedRun.error_code || nullLabel }}</strong></div>
              <div><span>Повторяемая ошибка</span><strong>{{ selectedRun.error_code ? (selectedRun.retryable ? 'Да' : 'Нет') : nullLabel }}</strong></div>
            </div>

            <h3 class="text-subtitle-1 font-weight-medium mb-2">Материалы</h3>
            <div class="material-counts mb-6">
              <div><span>Выбрано</span><strong>{{ selectedRun.selected_material_count }}</strong></div>
              <div><span>Сохранено</span><strong>{{ selectedRun.persisted_material_count }}</strong></div>
              <div><span>Разрешено</span><strong>{{ selectedRun.resolved_material_count }}</strong></div>
              <div><span>Активно</span><strong>{{ selectedRun.active_material_count }}</strong></div>
            </div>

            <div class="d-flex align-center justify-space-between mb-2">
              <h3 class="text-subtitle-1 font-weight-medium">Хронология</h3>
              <span class="text-caption text-medium-emphasis">{{ events.length }} событий</span>
            </div>
            <div v-if="events.length === 0" class="empty-events">Для этого запуска событий нет.</div>
            <ol v-else class="timeline">
              <li v-for="event in events" :key="event.seq" :class="['timeline-event', { 'timeline-event--failed': isFailureEvent(event) }]">
                <div class="timeline-marker" />
                <div class="timeline-body">
                  <div class="d-flex flex-wrap align-center ga-2">
                    <span class="timeline-time">{{ formatTime(event.created_at) }}</span>
                    <span class="timeline-code">{{ event.event_code }}</span>
                    <v-chip size="x-small" :color="event.level === 'error' ? 'error' : 'default'">{{ event.level }}</v-chip>
                  </div>
                  <div class="text-caption text-medium-emphasis mt-1">#{{ event.seq }} · {{ event.stage || nullLabel }} · {{ event.status || nullLabel }}</div>
                  <details v-if="event.payload && Object.keys(event.payload).length" class="payload-disclosure">
                    <summary>Безопасные параметры</summary>
                    <pre class="safe-payload">{{ JSON.stringify(event.payload, null, 2) }}</pre>
                  </details>
                </div>
              </li>
            </ol>
          </template>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar" timeout="2200">{{ snackbarText }}</v-snackbar>
  </PageContainer>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import {
  adminExpertAiRunsApi,
  type ExpertAiRun,
  type ExpertAiRunEvent,
  type ExpertAiRunFilters,
} from '@/api/adminExpertAiRuns'

const nullLabel = '—'
const statusPresets = [
  { value: 'all', label: 'Все' },
  { value: 'failed', label: 'Ошибки' },
  { value: 'completed', label: 'Успешные' },
  { value: 'interrupted', label: 'Прерванные' },
]
const statusPreset = ref('all')
const filters = reactive<ExpertAiRunFilters>({
  run_id: '', error_code: '', stage: '', user_id: '', project_id: '', conversation_id: '',
  date_from: '', date_to: '', provider: '', model: '', requested_mode: '',
})
const runs = ref<ExpertAiRun[]>([])
const events = ref<ExpertAiRunEvent[]>([])
const selectedRun = ref<ExpertAiRun | null>(null)
const loading = ref(false)
const detailLoading = ref(false)
const detailOpen = ref(false)
const listError = ref('')
const detailError = ref('')
const page = ref(1)
const totalRuns = ref(0)
const pageCount = ref(1)
const perPage = 25
const snackbar = ref(false)
const snackbarText = ref('')

const requestFilters = computed<ExpertAiRunFilters>(() => ({
  ...filters,
  status: statusPreset.value === 'all' ? '' : statusPreset.value,
  page: page.value,
  per_page: perPage,
}))

async function loadRuns(): Promise<void> {
  loading.value = true
  listError.value = ''
  try {
    const response = await adminExpertAiRunsApi.list(requestFilters.value)
    runs.value = response.data
    totalRuns.value = response.meta.total
    pageCount.value = Math.max(1, response.meta.last_page)
    page.value = response.meta.current_page
  } catch {
    listError.value = 'Не удалось загрузить запуски. Попробуйте обновить список.'
  } finally {
    loading.value = false
  }
}

function applyFilters(): void {
  page.value = 1
  void loadRuns()
}

function selectStatus(value: string): void {
  statusPreset.value = value
  applyFilters()
}

function clearFilters(): void {
  Object.assign(filters, {
    run_id: '', error_code: '', stage: '', user_id: '', project_id: '', conversation_id: '',
    date_from: '', date_to: '', provider: '', model: '', requested_mode: '',
  })
  statusPreset.value = 'all'
  applyFilters()
}

function goToPage(nextPage: number): void {
  page.value = nextPage
  void loadRuns()
}

async function openRun(runId: string): Promise<void> {
  detailOpen.value = true
  detailLoading.value = true
  detailError.value = ''
  selectedRun.value = null
  events.value = []
  try {
    const response = await adminExpertAiRunsApi.show(runId)
    selectedRun.value = response.run
    events.value = response.events
  } catch {
    detailError.value = 'Не удалось загрузить запуск или он уже недоступен.'
  } finally {
    detailLoading.value = false
  }
}

async function copyRunId(runId: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(runId)
    snackbarText.value = 'Run ID скопирован'
  } catch {
    snackbarText.value = 'Не удалось скопировать Run ID'
  }
  snackbar.value = true
}

function shortRunId(runId: string): string {
  return `${runId.slice(0, 8)}…${runId.slice(-6)}`
}

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleString('ru-RU') : nullLabel
}

function formatTime(value: string | null): string {
  if (!value) return nullLabel
  return new Date(value).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

function formatDuration(value: number | null): string {
  if (value == null) return nullLabel
  if (value < 1000) return `${value} мс`
  return `${(value / 1000).toFixed(2)} с`
}

function statusLabel(run: ExpertAiRun): string {
  if (run.status === 'running') return run.stage === 'streaming' ? 'Поток' : 'Запускается'
  const labels: Record<string, string> = {
    completed: 'Завершён', failed: 'Ошибка', interrupted: 'Прерван', cancelled: 'Отменён',
    streaming: 'Поток', starting: 'Запускается',
  }
  return labels[run.status] || run.status
}

function statusColor(run: ExpertAiRun): string {
  if (run.status === 'running') return run.stage === 'streaming' ? 'info' : 'primary'
  const colors: Record<string, string> = {
    completed: 'success', failed: 'error', interrupted: 'warning', cancelled: 'default',
    streaming: 'info', starting: 'primary',
  }
  return colors[run.status] || 'default'
}

function entityLabel(name: string | undefined, id: number | undefined): string {
  if (!name && !id) return nullLabel
  return name && id ? `${name} · #${id}` : (name || `#${id}`)
}

function modeLabel(run: ExpertAiRun): string {
  return joinedLabel(run.requested_mode, run.resolved_mode)
}

function joinedLabel(first: string | null | undefined, second: string | null | undefined): string {
  if (first && second) return `${first} → ${second}`
  return first || second || nullLabel
}

function isFailureEvent(event: ExpertAiRunEvent): boolean {
  return event.event_code === 'run.failed' || event.level === 'error'
}

onMounted(() => { void loadRuns() })
</script>

<style scoped>
.run-table-wrap { overflow-x: auto; }
.run-table { min-width: 1050px; }
.run-row { cursor: pointer; }
.run-row:hover { background: rgba(var(--v-theme-primary), 0.04); }
.run-link { padding: 0; border: 0; background: transparent; color: rgb(var(--v-theme-primary)); font: inherit; cursor: pointer; }
.run-link:hover { text-decoration: underline; }
.run-id-full { overflow-wrap: anywhere; color: rgb(var(--v-theme-on-surface)); }
.detail-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.detail-grid > div, .material-counts > div { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.detail-grid span, .material-counts span { color: rgb(var(--v-theme-on-surface-variant)); font-size: 0.78rem; }
.detail-grid strong { overflow-wrap: anywhere; font-weight: 500; }
.material-counts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.material-counts > div { border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 8px; padding: 12px; }
.material-counts strong { font-size: 1.1rem; }
.timeline { list-style: none; margin: 0; padding: 0 0 0 8px; }
.timeline-event { position: relative; display: grid; grid-template-columns: 12px minmax(0, 1fr); gap: 12px; padding: 0 0 18px; }
.timeline-event:not(:last-child)::before { position: absolute; top: 12px; bottom: 0; left: 5px; width: 2px; background: rgba(var(--v-border-color), 0.8); content: ''; }
.timeline-marker { z-index: 1; width: 12px; height: 12px; margin-top: 3px; border: 2px solid rgb(var(--v-theme-primary)); border-radius: 50%; background: rgb(var(--v-theme-surface)); }
.timeline-event--failed .timeline-marker { border-color: rgb(var(--v-theme-error)); background: rgb(var(--v-theme-error)); }
.timeline-event--failed .timeline-code { color: rgb(var(--v-theme-error)); font-weight: 700; }
.payload-disclosure { margin-top: 8px; }
.payload-disclosure summary { width: fit-content; color: rgb(var(--v-theme-primary)); cursor: pointer; font-size: 0.85rem; }
.payload-disclosure .safe-payload { margin-top: 8px; }
.timeline-time { min-width: 92px; color: rgb(var(--v-theme-on-surface-variant)); font-variant-numeric: tabular-nums; }
.timeline-code { overflow-wrap: anywhere; font-weight: 600; }
.safe-payload { overflow: auto; max-height: 280px; margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 0.82rem; }
.empty-events { padding: 18px; color: rgb(var(--v-theme-on-surface-variant)); text-align: center; }
@media (max-width: 800px) {
  .detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
