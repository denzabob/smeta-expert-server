<template>
  <div class="expert-activity-current" role="status" aria-live="polite">
    <v-icon icon="mdi-loading" size="16" class="expert-activity-current__spinner" />
    <span class="expert-activity-current__label">{{ label }}</span>
    <span v-if="elapsedSeconds >= 4" class="expert-activity-current__elapsed">{{ elapsedSeconds }} с</span>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import type { ExpertRunActivity, ExpertTimelineRun } from '../../chatTimeline'

const props = withDefaults(defineProps<{ runs: ExpertTimelineRun[]; showSlowWaiting?: boolean }>(), { showSlowWaiting: false })
const run = computed(() => [...props.runs].reverse().find((item) => !item.terminal))
const current = computed<ExpertRunActivity | undefined>(() => {
  const open = run.value?.activities.filter((item) => item.status === 'started') ?? []
  return [...open].reverse().find((item) => item.code.startsWith('pdf.ocr.'))
    ?? [...open].reverse().find((item) => !item.code.startsWith('model.'))
    ?? open[open.length - 1]
    ?? run.value?.activities[run.value.activities.length - 1]
})
const label = computed(() => {
  const activity = current.value
  if (!activity) return 'Подготавливаю запрос…'
  if (activity.code.startsWith('pdf.ocr.')) return activity.detail ? `Распознаю ${activity.detail}…` : 'Распознаю документ…'
  if (activity.code.startsWith('pdf.local_extract.')) return 'Проверяю PDF…'
  if (activity.code.startsWith('materials.') || activity.code.startsWith('material.')) return 'Подготавливаю материалы…'
  if (activity.code.startsWith('context.')) return 'Анализирую материалы…'
  if (activity.code === 'model.request.started') return 'Ожидаю ответ модели…'
  if (activity.code.startsWith('model.')) return 'Формирую ответ…'
  return 'Подготавливаю запрос…'
})
const elapsedSeconds = ref(0)
let startedAt = Date.now()
const timer = setInterval(() => { elapsedSeconds.value = Math.floor((Date.now() - startedAt) / 1000) }, 1000)
watch(label, () => { startedAt = Date.now(); elapsedSeconds.value = 0 })
onBeforeUnmount(() => clearInterval(timer))
</script>

<style scoped>
.expert-activity-current { display: inline-flex; align-items: center; gap: 7px; min-height: 28px; max-width: 100%; margin-top: 8px; padding: 4px 7px; border-radius: var(--md-sys-shape-corner-small); color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .08); font-size: .75rem; }
.expert-activity-current__spinner { flex: 0 0 auto; animation: expert-activity-spin 1.4s linear infinite; }
.expert-activity-current__label { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; background: linear-gradient(100deg, currentColor 35%, rgba(var(--v-theme-primary), .38) 50%, currentColor 65%); background-size: 250% 100%; background-clip: text; -webkit-text-fill-color: transparent; animation: expert-activity-shimmer 2.2s linear infinite; }
.expert-activity-current__elapsed { flex: 0 0 auto; color: rgba(var(--v-theme-on-surface-variant), .8); font-variant-numeric: tabular-nums; white-space: nowrap; }
@keyframes expert-activity-spin { to { transform: rotate(360deg); } }
@keyframes expert-activity-shimmer { to { background-position: -250% 0; } }
@media (prefers-reduced-motion: reduce) { .expert-activity-current__spinner, .expert-activity-current__label { animation: none; } .expert-activity-current__label { background: none; -webkit-text-fill-color: currentColor; } }
</style>
