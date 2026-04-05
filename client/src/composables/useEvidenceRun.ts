import { ref, computed, type Ref, onUnmounted } from 'vue'
import {
  evidenceRunApi,
  type EvidenceRun,
  type EvidenceItem,
  type EvidenceRunStatus,
  type EvidenceItemStatus,
} from '@/api/evidenceRun'
import type { AxiosError } from 'axios'

// ── Status helpers ──

export const RUN_STATUS_LABELS: Record<EvidenceRunStatus, string> = {
  pending: 'Ожидание',
  in_progress: 'В работе',
  ready: 'Готов к финализации',
  finalized: 'Финализирован',
  failed: 'Ошибка',
}

export const RUN_STATUS_COLORS: Record<EvidenceRunStatus, string> = {
  pending: 'grey',
  in_progress: 'info',
  ready: 'warning',
  finalized: 'success',
  failed: 'error',
}

export const ITEM_STATUS_LABELS: Record<EvidenceItemStatus, string> = {
  pending: 'Ожидание',
  collecting: 'Сбор',
  resolved: 'Подтверждён',
  failed: 'Ошибка',
  skipped: 'Пропущен',
}

export const ITEM_STATUS_COLORS: Record<EvidenceItemStatus, string> = {
  pending: 'grey',
  collecting: 'info',
  resolved: 'success',
  failed: 'error',
  skipped: 'warning',
}

export const COST_COMPONENT_LABELS: Record<string, string> = {
  plate: 'Плита',
  edge: 'Кромка',
  facade: 'Фасад',
  fitting: 'Фурнитура',
  operation: 'Операция',
  labor_work: 'Работа',
  expense: 'Расход',
}

function isAxios404(err: unknown): boolean {
  return (err as AxiosError)?.response?.status === 404
}

// ── Composable ──

export function useEvidenceRun(projectId: Ref<number>) {
  // State
  const pdfAvailable = ref(true)
  const runs = ref<EvidenceRun[]>([])
  const selectedRun = ref<(EvidenceRun & { items: EvidenceItem[] }) | null>(null)
  const loading = ref(false)
  const actionLoading = ref(false)
  const error = ref<string | null>(null)
  const pdfDownloading = ref(false)

  // Computed
  const runsCount = computed(() => runs.value.length)

  const selectedRunItems = computed<EvidenceItem[]>(() => selectedRun.value?.items ?? [])

  const coverage = computed(() => {
    if (!selectedRun.value) {
      return { total: 0, resolved: 0, skipped: 0, failed: 0, pending: 0 }
    }
    const items = selectedRunItems.value
    return {
      total: items.length,
      resolved: items.filter((i) => i.status === 'resolved').length,
      skipped: items.filter((i) => i.status === 'skipped').length,
      failed: items.filter((i) => i.status === 'failed').length,
      pending: items.filter((i) => i.status === 'pending' || i.status === 'collecting').length,
    }
  })

  const canFinalize = computed(() => {
    if (!selectedRun.value) return false
    return selectedRun.value.status === 'ready'
  })

  const isFinalized = computed(() => selectedRun.value?.status === 'finalized')

  // Actions
  async function fetchRuns() {
    if (!projectId.value) return
    loading.value = true
    error.value = null
    try {
      const res = await evidenceRunApi.list(projectId.value)
      runs.value = res.data
    } catch {
      error.value = 'Не удалось загрузить список запусков обоснований.'
    } finally {
      loading.value = false
    }
  }

  async function createRun() {
    if (!projectId.value) return
    actionLoading.value = true
    error.value = null
    try {
      const res = await evidenceRunApi.create(projectId.value)
      runs.value.unshift(res.data)
      selectedRun.value = res.data as EvidenceRun & { items: EvidenceItem[] }
    } catch (err: unknown) {
      const axErr = err as AxiosError<{ message?: string }>
      error.value = axErr.response?.data?.message ?? 'Не удалось создать запуск.'
    } finally {
      actionLoading.value = false
    }
  }

  async function selectRun(runId: number) {
    if (!projectId.value) return
    loading.value = true
    error.value = null
    try {
      const res = await evidenceRunApi.show(projectId.value, runId)
      selectedRun.value = res.data
    } catch (err) {
      if (isAxios404(err)) {
        error.value = 'Запуск не найден.'
      } else {
        error.value = 'Не удалось загрузить данные запуска.'
      }
    } finally {
      loading.value = false
    }
  }

  async function refreshRun() {
    if (!projectId.value || !selectedRun.value) return
    loading.value = true
    error.value = null
    try {
      const res = await evidenceRunApi.refreshRun(projectId.value, selectedRun.value.id)
      selectedRun.value = res.data as EvidenceRun & { items: EvidenceItem[] }
    } catch (err: unknown) {
      const axErr = err as AxiosError<{ message?: string }>
      error.value = axErr.response?.data?.message ?? 'Не удалось обновить запуск.'
    } finally {
      loading.value = false
    }
  }

  async function resolveItem(itemId: number, evidenceRecordId: number) {
    if (!projectId.value || !selectedRun.value) return
    actionLoading.value = true
    error.value = null
    try {
      await evidenceRunApi.resolveItem(projectId.value, selectedRun.value.id, itemId, {
        evidence_record_id: evidenceRecordId,
      })
      await selectRun(selectedRun.value.id)
    } catch (err: unknown) {
      const axErr = err as AxiosError<{ message?: string }>
      error.value = axErr.response?.data?.message ?? 'Не удалось подтвердить позицию.'
    } finally {
      actionLoading.value = false
    }
  }

  async function skipItem(itemId: number, reason?: string) {
    if (!projectId.value || !selectedRun.value) return
    actionLoading.value = true
    error.value = null
    try {
      await evidenceRunApi.skipItem(projectId.value, selectedRun.value.id, itemId, {
        reason: reason || undefined,
      })
      await selectRun(selectedRun.value.id)
    } catch (err: unknown) {
      const axErr = err as AxiosError<{ message?: string }>
      error.value = axErr.response?.data?.message ?? 'Не удалось пропустить позицию.'
    } finally {
      actionLoading.value = false
    }
  }

  async function finalizeRun() {
    if (!projectId.value || !selectedRun.value) return
    actionLoading.value = true
    error.value = null
    try {
      const res = await evidenceRunApi.finalize(projectId.value, selectedRun.value.id)
      selectedRun.value = res.data as EvidenceRun & { items: EvidenceItem[] }
      // Update in the list too
      const idx = runs.value.findIndex((r) => r.id === selectedRun.value!.id)
      if (idx !== -1) runs.value[idx] = { ...runs.value[idx], ...selectedRun.value }
    } catch (err: unknown) {
      const axErr = err as AxiosError<{ message?: string }>
      error.value = axErr.response?.data?.message ?? 'Не удалось финализировать запуск.'
    } finally {
      actionLoading.value = false
    }
  }

  async function downloadPdf() {
    if (!projectId.value || !selectedRun.value) return
    pdfDownloading.value = true
    error.value = null
    try {
      const blob = await evidenceRunApi.downloadPdf(projectId.value, selectedRun.value.id)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `evidence_run_${selectedRun.value.id}.pdf`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    } catch (err: unknown) {
      if (isAxios404(err)) {
        pdfAvailable.value = false
        error.value = 'Генерация PDF недоступна на данном сервере.'
      } else {
        const axErr = err as AxiosError<{ message?: string }>
        error.value = axErr.response?.data?.message ?? 'Не удалось скачать PDF.'
      }
    } finally {
      pdfDownloading.value = false
    }
  }

  function clearSelection() {
    selectedRun.value = null
    error.value = null
  }

  // ── Manual resolve (upload proof + resolve in one step) ──

  async function manualResolveItem(itemId: number, formData: FormData) {
    if (!projectId.value || !selectedRun.value) return
    actionLoading.value = true
    error.value = null
    try {
      await evidenceRunApi.manualResolveItem(
        projectId.value,
        selectedRun.value.id,
        itemId,
        formData,
      )
      await selectRun(selectedRun.value.id)
    } catch (err: unknown) {
      const axErr = err as AxiosError<{ message?: string }>
      error.value = axErr.response?.data?.message ?? 'Не удалось загрузить обоснование.'
    } finally {
      actionLoading.value = false
    }
  }

  // ── Chrome handoff polling ──
  // After user clicks Chrome link, poll for run changes for up to 2 minutes.

  let pollingTimer: ReturnType<typeof setInterval> | null = null
  let pollingTimeout: ReturnType<typeof setTimeout> | null = null
  const isPolling = ref(false)

  function startChromePolling() {
    if (pollingTimer || !selectedRun.value) return
    isPolling.value = true

    const runId = selectedRun.value.id
    const previousPending = coverage.value.pending

    pollingTimer = setInterval(async () => {
      if (!projectId.value || !selectedRun.value || selectedRun.value.id !== runId) {
        stopChromePolling()
        return
      }
      try {
        const res = await evidenceRunApi.show(projectId.value, runId)
        selectedRun.value = res.data
        // Update in the list
        const idx = runs.value.findIndex((r) => r.id === runId)
        if (idx !== -1) runs.value[idx] = { ...runs.value[idx], ...res.data }
        // Stop if pending count changed (capture happened) or no more pending
        const newPending = res.data.items?.filter(
          (i: EvidenceItem) => i.status === 'pending' || i.status === 'collecting',
        ).length ?? 0
        if (newPending !== previousPending || newPending === 0) {
          stopChromePolling()
        }
      } catch {
        // Ignore polling errors silently
      }
    }, 5000)

    // Auto-stop after 2 minutes
    pollingTimeout = setTimeout(() => stopChromePolling(), 120_000)
  }

  function stopChromePolling() {
    if (pollingTimer) {
      clearInterval(pollingTimer)
      pollingTimer = null
    }
    if (pollingTimeout) {
      clearTimeout(pollingTimeout)
      pollingTimeout = null
    }
    isPolling.value = false
  }

  // Cleanup on unmount
  onUnmounted(() => stopChromePolling())

  return {
    // State
    pdfAvailable,
    runs,
    selectedRun,
    loading,
    actionLoading,
    error,
    pdfDownloading,
    // Computed
    runsCount,
    selectedRunItems,
    coverage,
    canFinalize,
    isFinalized,
    // Actions
    fetchRuns,
    createRun,
    selectRun,
    refreshRun,
    resolveItem,
    skipItem,
    manualResolveItem,
    finalizeRun,
    downloadPdf,
    clearSelection,
    startChromePolling,
    stopChromePolling,
    isPolling,
  }
}
