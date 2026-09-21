export type ExpertActivityStatus = 'started' | 'completed' | 'skipped' | 'failed'
export type ExpertTimelineTerminal = 'completed' | 'cancelled' | 'interrupted' | 'failed'

export interface ExpertRunActivity {
  runId: string
  seq: number
  activityId: string
  code: string
  status: ExpertActivityStatus
  category: string
  detail?: string
}

export interface ExpertReasoningSummaryEvent {
  runId: string
  seq: number
  text: string
  final: boolean
}

export interface ExpertTimelineRun {
  runId: string
  significant?: boolean
  activities: ExpertRunActivity[]
  lastActivitySeq: number
  lastReasoningSeq: number
  reasoningSummary?: string
  reasoningFinal?: boolean
  terminal?: ExpertTimelineTerminal
}

export interface ExpertTimelinePresentation {
  label: string
  icon: string
}

const MAX_RUNS_PER_ASSISTANT = 3
const MAX_ACTIVITIES_PER_RUN = 24
const MAX_REASONING_SUMMARY_CHARS = 4000
export const EXPERT_SLOW_FIRST_TOKEN_MS = 800
export const EXPERT_SIGNIFICANT_WAIT_MS = 1600

const significantActivityPrefixes = [
  'materials.resolve.',
  'material.text_extract.',
  'material.image_prepare.',
  'pdf.',
  'tool.',
  'web.',
]

const activityLabels: Record<string, string> = {
  'request.accepted': 'Запрос принят',
  'materials.resolve.started': 'Подготавливаю материалы',
  'materials.resolve.completed': 'Материалы подготовлены',
  'material.text_extract.started': 'Извлекаю текст из документа',
  'material.text_extract.completed': 'Текст извлечён из документа',
  'material.image_prepare.started': 'Подготавливаю изображение',
  'material.image_prepare.completed': 'Изображение подготовлено',
  'pdf.local_extract.started': 'Проверяю текстовый слой PDF',
  'pdf.local_extract.completed': 'Текстовый слой PDF проверен',
  'pdf.local_extract.unavailable': 'Локальное извлечение недоступно',
  'pdf.ocr_cache.hit': 'Использован кеш документа',
  'pdf.ocr_cache.miss': 'Подготавливаю документ',
  'pdf.ocr.started': 'Обрабатываю документ',
  'pdf.ocr.completed': 'Документ обработан',
  'pdf.text_cache.hit': 'Использован кеш разбора документа',
  'pdf.text_cache.miss': 'Подготавливаю большой документ',
  'pdf.text.started': 'Обрабатываю большой документ',
  'pdf.text.completed': 'Большой документ обработан',
  'context.build.completed': 'Контекст подготовлен',
  'model.request.started': 'Запрос отправлен модели',
  'model.first_token': 'Формируется ответ',
  'model.completed': 'Ответ сформирован',
  'response.persisted': 'Ответ сохранён',
  'generation.cancelled': 'Генерация остановлена',
  'generation.interrupted': 'Генерация прервана',
}

export function createExpertTimelineRun(runId: string): ExpertTimelineRun {
  return { runId, activities: [], lastActivitySeq: 0, lastReasoningSeq: 0 }
}

export function addExpertTimelineRun(runs: ExpertTimelineRun[], runId: string): ExpertTimelineRun[] {
  if (runs.some((run) => run.runId === runId)) return runs
  const next = [...runs, createExpertTimelineRun(runId)]
  while (next.length > MAX_RUNS_PER_ASSISTANT) {
    const oldestTerminal = next.findIndex((run) => run.terminal !== undefined)
    next.splice(oldestTerminal >= 0 ? oldestTerminal : 0, 1)
  }
  return next
}

export function applyExpertTimelineActivity(runs: ExpertTimelineRun[], activity: ExpertRunActivity): ExpertTimelineRun[] {
  const runIndex = runs.findIndex((run) => run.runId === activity.runId)
  if (runIndex < 0) return runs
  const run = runs[runIndex]
  if (!run || run.terminal !== undefined || activity.seq <= run.lastActivitySeq) return runs

  const activities = [...run.activities]
  const activityIndex = activities.findIndex((item) => item.activityId === activity.activityId)
  if (activityIndex >= 0) {
    const previous = activities[activityIndex]
    if (previous && previous.status !== 'started' && activity.status === 'started') {
      return replaceRun(runs, runIndex, { ...run, lastActivitySeq: activity.seq })
    }
    activities[activityIndex] = { ...previous, ...activity, detail: activity.detail ?? previous?.detail }
  } else {
    activities.push(activity)
    if (activities.length > MAX_ACTIVITIES_PER_RUN) activities.splice(0, activities.length - MAX_ACTIVITIES_PER_RUN)
  }

  return replaceRun(runs, runIndex, { ...run, activities, lastActivitySeq: activity.seq })
}

export function appendExpertReasoningSummary(runs: ExpertTimelineRun[], event: ExpertReasoningSummaryEvent): ExpertTimelineRun[] {
  const runIndex = runs.findIndex((run) => run.runId === event.runId)
  if (runIndex < 0) return runs
  const run = runs[runIndex]
  if (!run || run.terminal !== undefined || event.seq <= run.lastReasoningSeq || event.text.trim() === '') return runs
  const reasoningSummary = `${run.reasoningSummary ?? ''}${event.text}`.slice(0, MAX_REASONING_SUMMARY_CHARS)

  return replaceRun(runs, runIndex, {
    ...run,
    reasoningSummary,
    reasoningFinal: event.final || run.reasoningFinal,
    lastReasoningSeq: event.seq,
  })
}

export function finishExpertTimelineRun(runs: ExpertTimelineRun[], runId: string, terminal: ExpertTimelineTerminal): ExpertTimelineRun[] {
  const runIndex = runs.findIndex((run) => run.runId === runId)
  if (runIndex < 0) return runs
  const run = runs[runIndex]
  if (!run || run.terminal !== undefined) return runs
  const terminalStatus: ExpertActivityStatus = terminal === 'interrupted' || terminal === 'failed' ? 'failed' : 'skipped'

  return replaceRun(runs, runIndex, {
    ...run,
    activities: run.activities.map((activity) => activity.status === 'started' ? { ...activity, status: terminalStatus } : activity),
    terminal,
  })
}

export function moveExpertTimelineRuns(
  timelines: Record<string, ExpertTimelineRun[]>,
  fromAssistantId: string,
  toAssistantId: string,
): Record<string, ExpertTimelineRun[]> {
  if (!fromAssistantId || !toAssistantId || fromAssistantId === toAssistantId || !timelines[fromAssistantId]) return timelines
  const moved = timelines[fromAssistantId] ?? []
  const remaining = { ...timelines }
  delete remaining[fromAssistantId]
  return { ...remaining, [toAssistantId]: [...(remaining[toAssistantId] ?? []), ...moved] }
}

export function removeExpertTimelineRuns(timelines: Record<string, ExpertTimelineRun[]>, assistantId: string): Record<string, ExpertTimelineRun[]> {
  if (!timelines[assistantId]) return timelines
  const { [assistantId]: removed, ...remaining } = timelines
  void removed
  return remaining
}

export function currentExpertTimelineActivity(run: ExpertTimelineRun): ExpertRunActivity | undefined {
  return [...run.activities].reverse().find((activity) => activity.status === 'started') ?? run.activities[run.activities.length - 1]
}

export function isSignificantExpertTimelineActivity(activity: ExpertRunActivity): boolean {
  return significantActivityPrefixes.some((prefix) => activity.code.startsWith(prefix))
}

export function hasSignificantExpertTimelineActivity(run: ExpertTimelineRun): boolean {
  return run.significant === true || run.activities.some((activity) =>
    activity.code.startsWith('pdf.ocr.') || activity.code.startsWith('pdf.text.') || activity.code.startsWith('pdf.text_cache.') || activity.code.startsWith('tool.') || activity.code.startsWith('web.'))
}

export function markExpertTimelineSignificant(runs: ExpertTimelineRun[], runId: string): ExpertTimelineRun[] {
  return runs.map((run) => run.runId === runId && run.terminal === undefined ? { ...run, significant: true } : run)
}

export function presentExpertTimelineActivity(activity: ExpertRunActivity): ExpertTimelinePresentation {
  if (activity.code === 'generation.cancelled') return { label: activityLabels[activity.code] ?? 'Генерация остановлена', icon: 'mdi-stop-circle-outline' }
  if (activity.code === 'generation.interrupted') return { label: activityLabels[activity.code] ?? 'Генерация прервана', icon: 'mdi-alert-circle-outline' }
  if (activity.status === 'started') return { label: activityLabels[activity.code] ?? 'Выполняется операция', icon: 'mdi-progress-clock' }
  if (activity.status === 'failed') return { label: activityLabels[activity.code] ?? 'Операция завершилась с ошибкой', icon: 'mdi-alert-circle-outline' }
  if (activity.status === 'skipped') return { label: activityLabels[activity.code] ?? 'Операция не выполнена', icon: 'mdi-minus-circle-outline' }
  return { label: activityLabels[activity.code] ?? 'Операция выполнена', icon: 'mdi-check-circle-outline' }
}

export function expertTimelineAria(runId: string, expanded: boolean, section: 'activity' | 'reasoning' = 'activity') {
  const suffix = runId.replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 80) || 'run'
  const controlsId = `expert-timeline-${section}-${suffix}`
  return { expanded, controlsId, ariaExpanded: expanded, ariaControls: controlsId }
}

function replaceRun(runs: ExpertTimelineRun[], index: number, nextRun: ExpertTimelineRun): ExpertTimelineRun[] {
  return runs.map((run, currentIndex) => currentIndex === index ? nextRun : run)
}
