<template>
  <div class="evidence-coverage-summary">
    <div class="evidence-coverage-summary__bar">
      <v-progress-linear
        :model-value="percentage"
        :color="barColor"
        height="8"
        rounded
        striped
      />
    </div>
    <div class="evidence-coverage-summary__chips">
      <v-chip size="small" variant="tonal" color="primary">
        Всего: {{ total }}
      </v-chip>
      <v-chip size="small" variant="tonal" color="success" prepend-icon="mdi-check-circle">
        Подтверждено: {{ resolved }}
      </v-chip>
      <v-chip v-if="skipped > 0" size="small" variant="tonal" color="warning" prepend-icon="mdi-skip-next">
        Пропущено: {{ skipped }}
      </v-chip>
      <v-chip v-if="failed > 0" size="small" variant="tonal" color="error" prepend-icon="mdi-alert-circle">
        Ошибки: {{ failed }}
      </v-chip>
      <v-chip v-if="pending > 0" size="small" variant="tonal" color="grey" prepend-icon="mdi-clock-outline">
        Ожидание: {{ pending }}
      </v-chip>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  total: number
  resolved: number
  skipped: number
  failed: number
  pending: number
}>()

const percentage = computed(() => {
  if (props.total === 0) return 0
  return Math.round(((props.resolved + props.skipped) / props.total) * 100)
})

const barColor = computed(() => {
  if (props.failed > 0) return 'warning'
  if (props.resolved === props.total && props.total > 0) return 'success'
  return 'primary'
})
</script>

<style scoped>
.evidence-coverage-summary {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.evidence-coverage-summary__bar {
  width: 100%;
}

.evidence-coverage-summary__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
</style>
