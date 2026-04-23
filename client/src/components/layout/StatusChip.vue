<template>
  <v-chip
    :color="resolvedColor"
    :size="size"
    :variant="variant"
    :prepend-icon="icon"
    :title="title"
  >
    <slot>{{ resolvedLabel }}</slot>
  </v-chip>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    status?: string | null
    label?: string | null
    color?: string | null
    icon?: string
    title?: string
    size?: 'x-small' | 'small' | 'default' | 'large' | 'x-large'
    variant?: 'flat' | 'text' | 'elevated' | 'tonal' | 'outlined' | 'plain'
  }>(),
  {
    status: null,
    label: null,
    color: null,
    icon: undefined,
    title: undefined,
    size: 'small',
    variant: 'tonal',
  },
)

const statusColorMap: Record<string, string> = {
  active: 'success',
  enabled: 'success',
  success: 'success',
  completed: 'success',
  published: 'success',
  manual_verified: 'success',
  warning: 'warning',
  stale: 'warning',
  pending: 'warning',
  processing: 'info',
  running: 'info',
  auto_verified: 'info',
  failed: 'error',
  error: 'error',
  rejected: 'error',
  disabled: 'grey',
  inactive: 'grey',
  none: 'grey',
}

const resolvedColor = computed(() => {
  if (props.color) return props.color
  const key = String(props.status ?? '').toLowerCase()
  return statusColorMap[key] ?? 'grey'
})

const resolvedLabel = computed(() => props.label ?? props.status ?? '—')
</script>
