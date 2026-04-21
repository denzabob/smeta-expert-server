<template>
  <v-card class="saas-section-card md3-section-card" :loading="loading" :variant="variant">
    <v-card-title v-if="showHeader">
      <div class="saas-section-card__header md3-section-card__header">
        <div class="saas-section-card__heading md3-section-card__heading">
          <div v-if="title || $slots.title" class="saas-section-card__title md3-section-card__title">
            <slot name="title">{{ title }}</slot>
          </div>
          <div
            v-if="subtitle || $slots.subtitle"
            class="saas-section-card__subtitle md3-section-card__subtitle"
          >
            <slot name="subtitle">{{ subtitle }}</slot>
          </div>
        </div>
        <div
          v-if="$slots['header-actions']"
          class="saas-section-card__header-actions md3-section-card__header-actions"
        >
          <slot name="header-actions" />
        </div>
      </div>
    </v-card-title>

    <v-card-text class="md3-section-card__content">
      <slot />
    </v-card-text>

    <v-card-actions v-if="$slots.actions" class="md3-section-card__actions">
      <slot name="actions" />
    </v-card-actions>
  </v-card>
</template>

<script setup lang="ts">
import { computed, useSlots } from 'vue'

const props = withDefaults(
  defineProps<{
    title?: string
    subtitle?: string
    loading?: boolean
    variant?: 'flat' | 'text' | 'elevated' | 'tonal' | 'outlined' | 'plain'
  }>(),
  {
    title: undefined,
    subtitle: undefined,
    loading: false,
    variant: 'flat'
  }
)

const slots = useSlots()

const showHeader = computed(() => {
  return Boolean(props.title || props.subtitle || slots.title || slots.subtitle || slots['header-actions'])
})
</script>
