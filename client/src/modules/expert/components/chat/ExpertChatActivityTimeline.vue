<template>
  <section v-if="runs.length" class="expert-activity-timeline" aria-label="Ход обработки">
    <section v-for="run in runs" :key="run.runId" class="expert-activity-timeline__run">
      <div v-if="currentActivity(run)?.status === 'started' && !run.terminal" class="expert-activity-timeline__current" role="status" aria-live="polite">
        <v-progress-circular indeterminate size="14" width="2" />
        <span>{{ currentPresentation(run).label }}</span>
        <small v-if="currentActivity(run)?.detail">— {{ currentActivity(run)?.detail }}</small>
      </div>

      <button
        class="expert-activity-timeline__toggle"
        type="button"
        :aria-controls="activityAria(run).ariaControls"
        :aria-expanded="activityAria(run).ariaExpanded"
        @click="toggleActivity(run.runId)"
      >
        <v-icon icon="mdi-list-box-outline" size="16" />
        <span>{{ run.terminal ? 'Ход обработки' : 'Ход обработки сейчас' }}</span>
        <v-icon :icon="activityExpanded(run) ? 'mdi-chevron-up' : 'mdi-chevron-down'" size="16" />
      </button>

      <ul v-show="activityExpanded(run)" :id="activityAria(run).controlsId" class="expert-activity-timeline__items">
        <li v-for="activity in run.activities" :key="activity.activityId" :class="`expert-activity-timeline__item--${activity.status}`">
          <v-progress-circular v-if="activity.status === 'started'" indeterminate size="14" width="2" />
          <v-icon v-else :icon="presentation(activity).icon" size="15" />
          <span>{{ presentation(activity).label }}</span>
          <small v-if="activity.detail">— {{ activity.detail }}</small>
        </li>
      </ul>

      <template v-if="run.reasoningSummary">
        <button
          class="expert-activity-timeline__toggle expert-activity-timeline__toggle--summary"
          type="button"
          :aria-controls="reasoningAria(run).ariaControls"
          :aria-expanded="reasoningAria(run).ariaExpanded"
          @click="toggleReasoning(run.runId)"
        >
          <v-icon icon="mdi-text-search-variant" size="16" />
          <span>Кратко об анализе</span>
          <v-icon :icon="reasoningExpanded(run) ? 'mdi-chevron-up' : 'mdi-chevron-down'" size="16" />
        </button>
        <p v-show="reasoningExpanded(run)" :id="reasoningAria(run).controlsId" class="expert-activity-timeline__summary">{{ run.reasoningSummary }}</p>
      </template>
    </section>
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import {
  currentExpertTimelineActivity,
  expertTimelineAria,
  presentExpertTimelineActivity,
  type ExpertRunActivity,
  type ExpertTimelineRun,
} from '../../chatTimeline'

const props = defineProps<{ runs: ExpertTimelineRun[] }>()

const expandedActivities = ref<Record<string, boolean>>({})
const expandedReasoning = ref<Record<string, boolean>>({})

function currentActivity(run: ExpertTimelineRun): ExpertRunActivity | undefined {
  return currentExpertTimelineActivity(run)
}

function presentation(activity: ExpertRunActivity) {
  return presentExpertTimelineActivity(activity)
}

function currentPresentation(run: ExpertTimelineRun) {
  const activity = currentActivity(run)
  return activity ? presentation(activity) : { label: 'Выполняется операция', icon: 'mdi-progress-clock' }
}

function activityExpanded(run: ExpertTimelineRun): boolean {
  return expandedActivities.value[run.runId] ?? (!run.terminal && run.activities.some((activity) => activity.status === 'started'))
}

function reasoningExpanded(run: ExpertTimelineRun): boolean {
  return expandedReasoning.value[run.runId] ?? false
}

function activityAria(run: ExpertTimelineRun) {
  return expertTimelineAria(run.runId, activityExpanded(run))
}

function reasoningAria(run: ExpertTimelineRun) {
  return expertTimelineAria(run.runId, reasoningExpanded(run), 'reasoning')
}

function toggleActivity(runId: string) {
  const run = props.runs.find((item) => item.runId === runId)
  const defaultExpanded = run ? !run.terminal && run.activities.some((activity) => activity.status === 'started') : true
  expandedActivities.value = { ...expandedActivities.value, [runId]: !(expandedActivities.value[runId] ?? defaultExpanded) }
}

function toggleReasoning(runId: string) {
  expandedReasoning.value = { ...expandedReasoning.value, [runId]: !(expandedReasoning.value[runId] ?? false) }
}
</script>

<style scoped>
.expert-activity-timeline { display: grid; gap: 7px; margin-top: 8px; color: rgba(var(--v-theme-on-surface-variant), .86); font-size: .7rem; }
.expert-activity-timeline__run { display: grid; gap: 4px; }
.expert-activity-timeline__current { display: inline-flex; align-items: center; gap: 6px; min-height: 24px; padding: 4px 7px; border-radius: var(--md-sys-shape-corner-small); color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .08); }
.expert-activity-timeline__current small, .expert-activity-timeline__item small { overflow: hidden; color: inherit; opacity: .78; text-overflow: ellipsis; white-space: nowrap; }
.expert-activity-timeline__toggle { display: inline-flex; align-items: center; justify-content: flex-start; gap: 5px; width: fit-content; min-height: 28px; padding: 4px 6px; border: 0; border-radius: var(--md-sys-shape-corner-small); color: inherit; background: transparent; cursor: pointer; font: inherit; text-align: left; }
.expert-activity-timeline__toggle:hover { background: rgba(var(--v-theme-primary), .07); }
.expert-activity-timeline__toggle:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 2px; }
.expert-activity-timeline__toggle > :last-child { margin-left: 1px; }
.expert-activity-timeline__toggle--summary { color: rgba(var(--v-theme-on-surface), .78); }
.expert-activity-timeline__items { display: grid; gap: 4px; margin: 0; padding: 2px 6px 4px; list-style: none; }
.expert-activity-timeline__items li { display: flex; align-items: center; gap: 6px; min-height: 20px; }
.expert-activity-timeline__item--failed { color: rgb(var(--v-theme-error)); }
.expert-activity-timeline__item--skipped { opacity: .7; }
.expert-activity-timeline__summary { margin: 0; padding: 6px 8px; border-left: 2px solid rgba(var(--v-theme-primary), .6); color: rgba(var(--v-theme-on-surface), .8); background: rgba(var(--v-theme-primary), .04); line-height: 1.45; white-space: pre-wrap; }
@media (max-width: 600px) { .expert-activity-timeline__toggle { min-height: 34px; } .expert-activity-timeline__current { max-width: 100%; } }
</style>
