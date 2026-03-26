<template>
  <header class="workspace-header">
    <div class="workspace-header__left">
      <div class="workspace-header__title">
        <h1 class="workspace-header__project-name">{{ title }}</h1>
        <slot name="title-extra" />
      </div>
      <div class="workspace-header__stats">
        <template v-if="loading">
          <div class="shimmer-chip" />
          <div class="shimmer-chip shimmer-chip--wide" />
          <div class="shimmer-chip shimmer-chip--medium" />
        </template>
        <template v-else>
          <v-chip size="small" variant="tonal" color="primary" prepend-icon="mdi-format-list-numbered">
            {{ positionsCount }} поз.
          </v-chip>
          <v-chip
            v-if="totalSum != null"
            size="small"
            variant="tonal"
            color="success"
            prepend-icon="mdi-currency-rub"
          >
            {{ formatSum(totalSum) }} ₽
          </v-chip>
          <v-chip
            v-if="warningsCount > 0"
            size="small"
            variant="tonal"
            color="warning"
            prepend-icon="mdi-alert-outline"
          >
            {{ warningsCount }} {{ warningsLabel }}
          </v-chip>
          <v-chip
            v-if="latestRevision"
            size="small"
            :color="latestRevision.status === 'published' ? 'success' : 'info'"
            variant="outlined"
            prepend-icon="mdi-check-circle"
          >
            Ревизия #{{ latestRevision.number }}
          </v-chip>
        </template>
      </div>
    </div>
    <div class="workspace-header__actions">
      <slot name="actions" />
    </div>
  </header>
</template>

<script setup lang="ts">
defineProps<{
  title: string
  positionsCount: number
  totalSum?: number | null
  warningsCount?: number
  latestRevision?: { number: number; status: string } | null
  loading?: boolean
}>()

const warningsLabel = 'проблем'

const formatSum = (n: number) => {
  return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)
}
</script>

<style scoped>
.workspace-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 20px;
  background: rgb(var(--v-theme-surface));
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  gap: 16px;
  flex-wrap: wrap;
  min-height: 56px;
}

.workspace-header__left {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  min-width: 0;
  flex: 1;
}

.workspace-header__title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.workspace-header__project-name {
  font-size: 1.1rem;
  font-weight: 600;
  white-space: nowrap;
  margin: 0;
}

.workspace-header__stats {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.workspace-header__actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

/* Shimmer placeholder chips */
.shimmer-chip {
  width: 72px;
  height: 26px;
  border-radius: 13px;
  background: linear-gradient(90deg,
    rgba(var(--v-theme-on-surface), 0.06) 25%,
    rgba(var(--v-theme-on-surface), 0.12) 50%,
    rgba(var(--v-theme-on-surface), 0.06) 75%
  );
  background-size: 200% 100%;
  animation: shimmer 1.5s ease-in-out infinite;
}
.shimmer-chip--wide { width: 120px; }
.shimmer-chip--medium { width: 96px; }

@keyframes shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
</style>
