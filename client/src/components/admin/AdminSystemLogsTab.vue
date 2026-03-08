<template>
  <v-card>
    <v-card-title class="d-flex align-center flex-wrap ga-2">
      <v-icon class="mr-1">mdi-text-box-search</v-icon>
      System Logs
      <v-spacer />
      <v-btn
        color="primary"
        variant="tonal"
        size="small"
        prepend-icon="mdi-refresh"
        :loading="loading"
        @click="loadLogs"
      >
        Refresh
      </v-btn>
      <v-btn
        color="primary"
        variant="outlined"
        size="small"
        prepend-icon="mdi-download"
        :disabled="!selectedFile"
        @click="downloadLog"
      >
        Download
      </v-btn>
      <v-btn
        color="secondary"
        variant="text"
        size="small"
        prepend-icon="mdi-open-in-new"
        :disabled="!selectedFile"
        @click="openFullLog"
      >
        Open full log
      </v-btn>
    </v-card-title>

    <v-card-text>
      <v-row>
        <v-col cols="12" md="3">
          <v-select
            v-model="activeType"
            :items="typeItems"
            label="Log source"
            variant="outlined"
            density="comfortable"
            @update:model-value="onTypeChange"
          />
        </v-col>
        <v-col cols="12" md="4">
          <v-select
            v-model="selectedFile"
            :items="fileItems"
            item-title="title"
            item-value="value"
            label="Log file"
            variant="outlined"
            density="comfortable"
            :disabled="fileItems.length === 0"
            @update:model-value="loadLogs"
          />
        </v-col>
        <v-col cols="6" md="2">
          <v-select
            v-model="linesLimit"
            :items="lineItems"
            label="Lines"
            variant="outlined"
            density="comfortable"
            @update:model-value="loadLogs"
          />
        </v-col>
        <v-col cols="6" md="3">
          <v-select
            v-model="levelFilter"
            :items="levelItems"
            label="Level filter"
            variant="outlined"
            density="comfortable"
            @update:model-value="loadLogs"
          />
        </v-col>
      </v-row>

      <v-alert
        v-if="errorMessage"
        type="warning"
        variant="tonal"
        class="mb-4"
      >
        {{ errorMessage }}
      </v-alert>

      <div class="d-flex flex-wrap ga-2 mb-3">
        <v-chip size="small" variant="outlined">
          File size: {{ formatBytes(currentState?.file_size ?? 0) }}
        </v-chip>
        <v-chip size="small" variant="outlined">
          Updated: {{ formatDateTime(currentState?.updated_at) }}
        </v-chip>
        <v-chip size="small" variant="outlined">
          Last entry: {{ formatDateTime(currentState?.last_entry_at) }}
        </v-chip>
        <v-chip size="small" variant="outlined">
          Entries: {{ entries.length }}
        </v-chip>
      </div>

      <v-btn-toggle v-model="viewMode" mandatory density="comfortable" class="mb-3">
        <v-btn value="structured" prepend-icon="mdi-table">Structured</v-btn>
        <v-btn value="raw" prepend-icon="mdi-code-braces">Raw</v-btn>
      </v-btn-toggle>

      <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-3" />

      <div v-if="viewMode === 'structured'" class="log-scroll">
        <div v-if="entries.length === 0" class="text-body-2 text-medium-emphasis pa-4">
          No log entries found for selected filters.
        </div>

        <div
          v-for="(entry, index) in entries"
          :key="`${entry.timestamp || 'no-ts'}-${index}`"
          class="log-entry"
          :class="`log-entry--${normalizeLevel(entry.level).toLowerCase()}`"
        >
          <div class="log-entry__header">
            <span class="log-entry__time">{{ entry.timestamp || 'no timestamp' }}</span>
            <v-chip size="x-small" :color="levelColor(entry.level)" label>
              {{ normalizeLevel(entry.level) }}
            </v-chip>
          </div>

          <pre class="log-entry__message">{{ entry.message }}</pre>
          <pre v-if="entry.stack_trace" class="log-entry__stack">{{ entry.stack_trace }}</pre>
        </div>
      </div>

      <pre v-else class="raw-scroll">{{ currentState?.raw_text || '' }}</pre>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  systemLogsApi,
  type SystemLogEntry,
  type SystemLogsResponse,
  type SystemLogType,
} from '@/api/systemLogs'

type ViewMode = 'structured' | 'raw'
type LevelFilter = 'all' | 'error' | 'warning' | 'info'

const activeType = ref<SystemLogType>('laravel')
const selectedFile = ref('')
const linesLimit = ref(500)
const levelFilter = ref<LevelFilter>('all')
const viewMode = ref<ViewMode>('structured')

const loading = ref(false)
const errorMessage = ref('')
const state = ref<SystemLogsResponse | null>(null)

const typeItems = [
  { title: 'Laravel Logs', value: 'laravel' },
  { title: 'Frontend Logs', value: 'frontend' },
]

const lineItems = [500, 1000]

const levelItems = [
  { title: 'All', value: 'all' },
  { title: 'ERROR', value: 'error' },
  { title: 'WARNING', value: 'warning' },
  { title: 'INFO', value: 'info' },
]

const currentState = computed(() => state.value)

const entries = computed<SystemLogEntry[]>(() => {
  return currentState.value?.entries || []
})

const fileItems = computed(() => {
  return (currentState.value?.available_files || []).map((file) => ({
    title: file.exists ? file.name : `${file.name} (not found)`,
    value: file.name,
  }))
})

function normalizeLevel(level: string): string {
  const normalized = String(level || '').toUpperCase()
  if (normalized.includes('ERROR') || normalized.includes('CRITICAL')) return 'ERROR'
  if (normalized.includes('WARN')) return 'WARNING'
  if (normalized.includes('INFO') || normalized.includes('NOTICE') || normalized.includes('DEBUG')) return 'INFO'
  return normalized || 'INFO'
}

function levelColor(level: string): string {
  const normalized = normalizeLevel(level)
  if (normalized === 'ERROR') return 'error'
  if (normalized === 'WARNING') return 'warning'
  return 'info'
}

function formatDateTime(value: string | null | undefined): string {
  if (!value) return 'n/a'

  const dt = new Date(value)
  if (Number.isNaN(dt.getTime())) {
    return value
  }

  return dt.toLocaleString('ru-RU')
}

function formatBytes(bytes: number): string {
  if (!bytes || bytes <= 0) return '0 B'
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`
}

function buildDownloadUrl(): string {
  return systemLogsApi.downloadUrl({
    type: activeType.value,
    file: selectedFile.value || undefined,
  })
}

function buildInlineUrl(): string {
  return systemLogsApi.downloadUrl({
    type: activeType.value,
    file: selectedFile.value || undefined,
    inline: true,
  })
}

function downloadLog(): void {
  if (!selectedFile.value) return

  window.open(buildDownloadUrl(), '_blank', 'noopener')
}

function openFullLog(): void {
  if (!selectedFile.value) return

  window.open(buildInlineUrl(), '_blank', 'noopener')
}

function onTypeChange(): void {
  selectedFile.value = ''
  loadLogs()
}

async function loadLogs(): Promise<void> {
  loading.value = true
  errorMessage.value = ''

  try {
    const data = await systemLogsApi.getLogs({
      type: activeType.value,
      file: selectedFile.value || undefined,
      lines: linesLimit.value,
      level: levelFilter.value === 'all' ? undefined : levelFilter.value,
    })

    state.value = data

    if (!selectedFile.value && data.file) {
      selectedFile.value = data.file
    }

    if (data.error) {
      errorMessage.value = data.error
    }
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message || 'Failed to load logs.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadLogs()
})
</script>

<style scoped>
.log-scroll,
.raw-scroll {
  max-height: 68vh;
  overflow: auto;
  border: 1px solid rgba(var(--v-border-color), 0.35);
  border-radius: 8px;
  padding: 12px;
  background: rgba(var(--v-theme-surface-variant), 0.2);
}

.log-entry {
  border: 1px solid rgba(var(--v-border-color), 0.35);
  border-left-width: 4px;
  border-radius: 8px;
  background: rgba(var(--v-theme-surface), 1);
  padding: 10px;
  margin-bottom: 10px;
}

.log-entry:last-child {
  margin-bottom: 0;
}

.log-entry--error {
  border-left-color: rgb(var(--v-theme-error));
}

.log-entry--warning {
  border-left-color: rgb(var(--v-theme-warning));
}

.log-entry--info {
  border-left-color: rgb(var(--v-theme-info));
}

.log-entry__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 8px;
}

.log-entry__time {
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.7);
}

.log-entry__message,
.log-entry__stack,
.raw-scroll {
  margin: 0;
  font-size: 12px;
  line-height: 1.5;
  white-space: pre-wrap;
  word-break: break-word;
  font-family: 'Consolas', 'Courier New', monospace;
}

.log-entry__stack {
  margin-top: 8px;
  max-height: 260px;
  overflow: auto;
  border-top: 1px dashed rgba(var(--v-border-color), 0.45);
  padding-top: 8px;
}
</style>
