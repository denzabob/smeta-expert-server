<template>
  <v-tooltip :text="tooltipText" location="top">
    <template #activator="{ props }">
      <v-chip
        v-bind="props"
        :color="badgeColor"
        :variant="variant"
        size="small"
        :prepend-icon="badgeIcon"
        class="trust-badge"
      >
        <span class="font-weight-medium">{{ score }}</span>
        <span v-if="showLabel" class="ml-1 text-caption">{{ levelLabel }}</span>
      </v-chip>
    </template>
  </v-tooltip>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { TrustLevel } from '@/api/materialCatalog'

const props = withDefaults(defineProps<{
  score: number
  level: TrustLevel
  showLabel?: boolean
  variant?: 'flat' | 'tonal' | 'outlined' | 'elevated' | 'text' | 'plain'
}>(), {
  showLabel: true,
  variant: 'tonal',
})

const badgeColor = computed(() => {
  switch (props.level) {
    case 'verified': return 'success'
    case 'partial': return 'warning'
    case 'unverified': return 'grey'
    default: return 'grey'
  }
})

const badgeIcon = computed(() => {
  switch (props.level) {
    case 'verified': return 'mdi-shield-check'
    case 'partial': return 'mdi-shield-half-full'
    case 'unverified': return 'mdi-shield-outline'
    default: return 'mdi-shield-outline'
  }
})

const levelLabel = computed(() => {
  switch (props.level) {
    case 'verified': return 'Проверен'
    case 'partial': return 'Частично'
    case 'unverified': return 'Не проверен'
    default: return ''
  }
})

const tooltipText = computed(() => {
  return `Trust Score: ${props.score}/100 — ${levelLabel.value}`
})
</script>

<style scoped>
.trust-badge {
  cursor: default;
}
</style>
