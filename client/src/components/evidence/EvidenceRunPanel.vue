<template>
  <div class="evidence-run-panel">
      <!-- Error banner -->
      <v-alert
        v-if="evidence.error.value"
        type="error"
        variant="tonal"
        density="compact"
        closable
        class="mb-3"
        @click:close="evidence.error.value = null"
      >
        {{ evidence.error.value }}
      </v-alert>

      <!-- Top toolbar: create run + run selector -->
      <div class="evidence-run-panel__toolbar">
        <v-btn
          size="small"
          color="primary"
          prepend-icon="mdi-plus"
          :loading="evidence.actionLoading.value"
          :disabled="evidence.actionLoading.value"
          @click="evidence.createRun()"
        >
          Новый запуск
        </v-btn>

        <v-select
          v-if="evidence.runs.value.length > 0"
          :model-value="evidence.selectedRun.value?.id ?? null"
          :items="runSelectItems"
          item-title="title"
          item-value="value"
          label="Запуск"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width: 360px"
          @update:model-value="onRunSelected"
        />

        <!-- Run-level actions -->
        <template v-if="evidence.selectedRun.value">
          <v-btn
            size="small"
            variant="outlined"
            prepend-icon="mdi-refresh"
            :loading="evidence.loading.value"
            @click="evidence.selectRun(evidence.selectedRun.value!.id)"
          >
            Обновить
          </v-btn>

          <v-btn
            v-if="evidence.canFinalize.value"
            size="small"
            color="success"
            prepend-icon="mdi-check-bold"
            :loading="evidence.actionLoading.value"
            @click="confirmFinalize"
          >
            Финализировать
          </v-btn>

          <v-btn
            v-if="evidence.isFinalized.value"
            size="small"
            color="secondary"
            prepend-icon="mdi-file-pdf-box"
            :loading="evidence.pdfDownloading.value"
            :disabled="!evidence.pdfAvailable.value"
            @click="evidence.downloadPdf()"
          >
            Скачать PDF
          </v-btn>
        </template>
      </div>

      <!-- PDF unavailable note -->
      <v-alert
        v-if="!evidence.pdfAvailable.value && evidence.isFinalized.value"
        type="info"
        variant="tonal"
        density="compact"
        class="mt-3"
      >
        Генерация PDF недоступна на данном сервере. Остальные функции обоснований работают в штатном режиме.
      </v-alert>

      <!-- Loading skeleton -->
      <v-skeleton-loader v-if="evidence.loading.value && !evidence.selectedRun.value" type="table" />

      <!-- Empty state: no runs at all -->
      <v-alert
        v-else-if="evidence.runs.value.length === 0 && !evidence.loading.value"
        type="info"
        variant="tonal"
        density="compact"
        class="mt-3"
      >
        Запусков обоснований пока нет. Нажмите «Новый запуск» для создания.
      </v-alert>

      <!-- Selected run details -->
      <template v-if="evidence.selectedRun.value">
        <!-- Run status header -->
        <v-card variant="flat" class="mt-3 border">
          <v-card-title class="d-flex align-center flex-wrap ga-2">
            <span>Запуск #{{ evidence.selectedRun.value.id }}</span>
            <v-chip
              size="small"
              :color="runStatusColor(evidence.selectedRun.value.status)"
              variant="tonal"
            >
              <v-progress-circular
                v-if="evidence.selectedRun.value.status === 'pending' || evidence.selectedRun.value.status === 'in_progress'"
                indeterminate
                size="12"
                width="2"
                class="mr-1"
              />
              {{ runStatusLabel(evidence.selectedRun.value.status) }}
            </v-chip>
            <v-chip v-if="evidence.selectedRun.value.finalized_at" size="small" variant="outlined" color="success">
              Финализирован: {{ formatDate(evidence.selectedRun.value.finalized_at) }}
            </v-chip>
          </v-card-title>

          <v-card-text class="pt-0">
            <!-- Coverage summary -->
            <EvidenceCoverageSummary
              :total="evidence.coverage.value.total"
              :resolved="evidence.coverage.value.resolved"
              :skipped="evidence.coverage.value.skipped"
              :failed="evidence.coverage.value.failed"
              :pending="evidence.coverage.value.pending"
            />

            <!-- Chrome extension hint for pending items -->
            <v-alert
              v-if="evidence.coverage.value.pending > 0"
              variant="tonal"
              density="compact"
              type="info"
              class="mt-3"
              icon="mdi-google-chrome"
            >
              <strong>Chrome-расширение:</strong> откройте страницу поставщика и используйте расширение
              Smeta Expert для автоматического захвата скриншота и цены.
              После захвата обоснование появится в системе и позицию можно будет подтвердить.
            </v-alert>

            <!-- Items table -->
            <div class="mt-3">
              <EvidenceItemsTable
                :items="evidence.selectedRunItems.value"
                :disabled="evidence.actionLoading.value || isRunTerminal"
                @resolve="openResolveDialog"
                @skip="openSkipDialog"
              />
            </div>
          </v-card-text>
        </v-card>
      </template>

    <!-- Resolution dialog -->
    <EvidenceResolutionDialog
      v-model="dialogOpen"
      :item="dialogItem"
      :mode="dialogMode"
      :loading="evidence.actionLoading.value"
      :error-message="evidence.error.value"
      @resolve="handleResolve"
      @skip="handleSkip"
    />

    <!-- Finalize confirmation -->
    <v-dialog v-model="finalizeConfirm" max-width="400">
      <v-card>
        <v-card-title>Финализация запуска</v-card-title>
        <v-card-text>
          Вы уверены, что хотите финализировать запуск #{{ evidence.selectedRun.value?.id }}?
          После финализации изменение позиций будет невозможно.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="finalizeConfirm = false">Отмена</v-btn>
          <v-btn color="success" :loading="evidence.actionLoading.value" @click="doFinalize">
            Финализировать
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, toRef } from 'vue'
import type { EvidenceItem } from '@/api/evidenceRun'
import {
  useEvidenceRun,
  RUN_STATUS_LABELS,
  RUN_STATUS_COLORS,
} from '@/composables/useEvidenceRun'
import EvidenceCoverageSummary from './EvidenceCoverageSummary.vue'
import EvidenceItemsTable from './EvidenceItemsTable.vue'
import EvidenceResolutionDialog from './EvidenceResolutionDialog.vue'

const props = defineProps<{
  projectId: number
}>()

const evidence = useEvidenceRun(toRef(props, 'projectId'))

// Fetch runs on mount
watch(
  () => props.projectId,
  (id) => {
    if (id) evidence.fetchRuns()
  },
  { immediate: true },
)

// Run selector items
const runSelectItems = computed(() =>
  evidence.runs.value.map((r) => ({
    title: `#${r.id} — ${runStatusLabel(r.status)} (${r.total_items} поз.)`,
    value: r.id,
  })),
)

const isRunTerminal = computed(() => {
  const s = evidence.selectedRun.value?.status
  return s === 'finalized' || s === 'failed'
})

function onRunSelected(id: number | null) {
  if (id) evidence.selectRun(id)
}

// Status helpers
function runStatusLabel(status: string): string {
  return RUN_STATUS_LABELS[status as keyof typeof RUN_STATUS_LABELS] ?? status
}

function runStatusColor(status: string): string {
  return RUN_STATUS_COLORS[status as keyof typeof RUN_STATUS_COLORS] ?? 'grey'
}

function formatDate(iso: string): string {
  try {
    return new Date(iso).toLocaleString('ru-RU', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return iso
  }
}

// Dialog state
const dialogOpen = ref(false)
const dialogMode = ref<'resolve' | 'skip'>('resolve')
const dialogItem = ref<EvidenceItem | null>(null)

function openResolveDialog(item: EvidenceItem) {
  dialogItem.value = item
  dialogMode.value = 'resolve'
  evidence.error.value = null
  dialogOpen.value = true
}

function openSkipDialog(item: EvidenceItem) {
  dialogItem.value = item
  dialogMode.value = 'skip'
  evidence.error.value = null
  dialogOpen.value = true
}

async function handleResolve(itemId: number, evidenceRecordId: number) {
  await evidence.resolveItem(itemId, evidenceRecordId)
  if (!evidence.error.value) {
    dialogOpen.value = false
  }
}

async function handleSkip(itemId: number, reason: string) {
  await evidence.skipItem(itemId, reason)
  if (!evidence.error.value) {
    dialogOpen.value = false
  }
}

// Finalize confirm
const finalizeConfirm = ref(false)

function confirmFinalize() {
  finalizeConfirm.value = true
}

async function doFinalize() {
  await evidence.finalizeRun()
  finalizeConfirm.value = false
}
</script>

<style scoped>
.evidence-run-panel__toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
</style>
