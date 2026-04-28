<template>
  <div class="billing-usage-row">
    <div class="billing-usage-row__main">
      <div class="billing-usage-row__title">
        <span>{{ label }}</span>
        <span class="billing-usage-row__value">{{ valueLabel }}</span>
      </div>
      <div v-if="description" class="billing-usage-row__description">
        {{ description }}
      </div>
      <div class="billing-usage-row__meta">
        {{ metaLabel }}
      </div>
    </div>

    <v-progress-linear
      class="billing-usage-row__progress"
      :class="{ 'billing-usage-row__progress--unlimited': unlimited }"
      :model-value="progressValue"
      :color="progressColor"
      bg-color="surface-variant"
      height="6"
      rounded
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    label: string
    used: number
    limit: number | null
    unit: string
    description?: string
  }>(),
  {
    description: undefined,
  },
)

const unlimited = computed(() => props.limit === null || Number(props.limit) <= 0)
const safeUsed = computed(() => Math.max(0, Number(props.used || 0)))
const safeLimit = computed(() => unlimited.value ? null : Math.max(0, Number(props.limit)))

const progressValue = computed(() => {
  if (unlimited.value) return 100
  if (!safeLimit.value) return 0

  return Math.min(100, Math.round((safeUsed.value / safeLimit.value) * 100))
})

const progressColor = computed(() => {
  if (unlimited.value) return 'grey'
  if (progressValue.value >= 90) return 'warning'

  return 'primary'
})

const valueLabel = computed(() => {
  if (unlimited.value) {
    return `${formatNumber(safeUsed.value)} ${props.unit}`
  }

  return `${formatNumber(safeUsed.value)} из ${formatNumber(safeLimit.value ?? 0)}`
})

const metaLabel = computed(() => {
  if (unlimited.value) {
    return `Использовано: ${formatNumber(safeUsed.value)} ${props.unit} · Лимит: без ограничений`
  }

  const left = Math.max(0, Number(safeLimit.value ?? 0) - safeUsed.value)
  return `Использовано: ${formatNumber(safeUsed.value)} из ${formatNumber(safeLimit.value ?? 0)} · Осталось: ${formatNumber(left)} ${props.unit}`
})

function formatNumber(value: number) {
  return new Intl.NumberFormat('ru-RU').format(value)
}
</script>

<style scoped>
.billing-usage-row {
  display: grid;
  gap: 8px;
  padding: 11px 0;
}

.billing-usage-row + .billing-usage-row {
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

.billing-usage-row__main {
  display: grid;
  gap: 6px;
  min-width: 0;
}

.billing-usage-row__title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  min-width: 0;
}

.billing-usage-row__title {
  font-weight: 650;
}

.billing-usage-row__value {
  color: rgb(var(--v-theme-on-surface));
  font-weight: 700;
  white-space: nowrap;
}

.billing-usage-row__description,
.billing-usage-row__meta {
  color: rgb(var(--v-theme-on-surface-variant));
  font-size: 0.82rem;
  line-height: 1.35;
}

.billing-usage-row__progress {
  width: 100%;
  opacity: 0.95;
}

.billing-usage-row__progress--unlimited {
  opacity: 0.34;
}

@media (max-width: 600px) {
  .billing-usage-row__title {
    align-items: flex-start;
    flex-direction: column;
    gap: 4px;
  }

  .billing-usage-row__value {
    white-space: normal;
  }
}
</style>
