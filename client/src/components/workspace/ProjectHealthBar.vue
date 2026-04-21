<template>
  <div v-if="issues.length > 0" class="health-bar">
    <div class="health-bar__toggle" @click="expanded = !expanded">
      <v-icon size="18" color="warning">mdi-alert-circle-outline</v-icon>
      <span class="health-bar__summary">
        {{ issues.length }} {{ issueWord }}
      </span>
      <v-icon size="18" class="health-bar__chevron" :class="{ 'health-bar__chevron--open': expanded }">
        mdi-chevron-down
      </v-icon>
    </div>
    <v-expand-transition>
      <div v-if="expanded" class="health-bar__list">
        <div
          v-for="(issue, idx) in issues"
          :key="idx"
          class="health-bar__item"
          :class="`health-bar__item--${issue.severity}`"
        >
          <v-icon
            size="16"
            :color="issue.severity === 'error' ? 'error' : issue.severity === 'warning' ? 'warning' : 'info'"
          >
            {{ issue.severity === 'error' ? 'mdi-close-circle' : issue.severity === 'warning' ? 'mdi-alert' : 'mdi-information' }}
          </v-icon>
          <span class="health-bar__message">{{ issue.message }}</span>
          <v-btn
            v-if="issue.action"
            size="x-small"
            variant="text"
            color="primary"
            @click="$emit('navigate', issue.action)"
          >
            {{ issue.actionLabel || 'Перейти' }}
          </v-btn>
        </div>
      </div>
    </v-expand-transition>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'

export interface HealthIssue {
  severity: 'error' | 'warning' | 'info'
  message: string
  action?: string
  actionLabel?: string
}

defineProps<{
  issues: HealthIssue[]
}>()

defineEmits<{
  navigate: [target: string]
}>()

const expanded = ref(false)

const issueWord = 'проблем'
</script>

<style scoped>
.health-bar {
  margin-top: 12px;
  border: 1px solid rgba(var(--v-theme-warning), 0.22);
  border-radius: var(--md-sys-shape-corner-extra-large);
  background:
    linear-gradient(180deg, rgba(var(--v-theme-warning), 0.08), rgba(var(--v-theme-warning), 0.03));
  overflow: hidden;
}

.health-bar__toggle {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  cursor: pointer;
  user-select: none;
}

.health-bar__toggle:hover {
  background: rgba(var(--v-theme-warning), 0.08);
}

.health-bar__summary {
  font-size: 0.84rem;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 0.86);
}

.health-bar__chevron {
  margin-left: auto;
  transition: transform 0.2s;
}

.health-bar__chevron--open {
  transform: rotate(180deg);
}

.health-bar__list {
  padding: 0 16px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.health-bar__item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border-radius: var(--md-sys-shape-corner-large);
  font-size: 0.84rem;
  border: 1px solid transparent;
}

.health-bar__item--error {
  background: rgba(var(--v-theme-error-container), 0.72);
  border-color: rgba(var(--v-theme-error), 0.18);
}

.health-bar__item--warning {
  background: rgba(var(--v-theme-warning), 0.1);
  border-color: rgba(var(--v-theme-warning), 0.18);
}

.health-bar__item--info {
  background: rgba(var(--v-theme-info), 0.08);
  border-color: rgba(var(--v-theme-info), 0.14);
}

.health-bar__message {
  flex: 1;
}

@media (max-width: 760px) {
  .health-bar__toggle {
    padding: 10px 12px;
  }

  .health-bar__list {
    padding: 0 12px 12px;
  }

  .health-bar__item {
    align-items: flex-start;
    flex-wrap: wrap;
  }
}
</style>
