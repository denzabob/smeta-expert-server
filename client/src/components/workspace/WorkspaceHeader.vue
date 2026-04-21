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
  padding: 16px 20px;
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.035), transparent 140px),
    rgba(var(--v-theme-surface-container-low), 0.96);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  border-radius: var(--md-sys-shape-corner-extra-large);
  gap: 18px;
  flex-wrap: wrap;
  min-height: 72px;
}

.workspace-header__left {
  display: flex;
  align-items: center;
  gap: 18px;
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
  font-size: clamp(1.1rem, 1.4vw, 1.32rem);
  font-weight: 800;
  letter-spacing: -0.01em;
  white-space: nowrap;
  margin: 0;
  color: rgb(var(--v-theme-on-surface));
}

.workspace-header__stats {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.workspace-header__actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  justify-content: flex-end;
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

@media (max-width: 760px) {
  .workspace-header {
    padding: 14px 16px;
    min-height: auto;
  }

  .workspace-header__left,
  .workspace-header__title,
  .workspace-header__actions {
    width: 100%;
  }

  .workspace-header__title {
    min-width: 0;
  }

  .workspace-header__project-name {
    white-space: normal;
    line-height: 1.2;
  }

  .workspace-header__actions {
    justify-content: stretch;
  }

  .workspace-header__actions :deep(.v-btn) {
    flex: 1 1 calc(50% - 4px);
    min-width: 0;
  }
}
</style>
