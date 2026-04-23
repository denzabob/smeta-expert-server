<template>
  <v-tabs
    :model-value="modelValue"
    :density="density"
    :color="color"
    class="app-tabs"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <slot>
      <v-tab
        v-for="item in items"
        :key="item.value"
        :value="item.value"
        :prepend-icon="item.icon"
        :disabled="item.disabled"
      >
        {{ item.label }}
      </v-tab>
    </slot>
  </v-tabs>
</template>

<script setup lang="ts">
export interface AppTabItem {
  value: string
  label: string
  icon?: string
  disabled?: boolean
}

withDefaults(
  defineProps<{
    modelValue: string
    items?: AppTabItem[]
    density?: 'default' | 'comfortable' | 'compact'
    color?: string
  }>(),
  {
    items: () => [],
    density: 'compact',
    color: 'primary',
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: unknown]
}>()
</script>

<style scoped>
.app-tabs {
  max-width: 100%;
}
</style>
