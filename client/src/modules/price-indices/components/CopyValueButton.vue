<template>
  <v-tooltip :text="copied ? 'Скопировано' : tooltip">
    <template #activator="{ props: activatorProps }">
      <v-btn
        v-bind="activatorProps"
        :icon="copied ? 'mdi-check' : 'mdi-content-copy'"
        size="x-small"
        variant="text"
        :aria-label="copied ? 'Скопировано' : tooltip"
        @click="copy"
      />
    </template>
  </v-tooltip>
</template>

<script setup lang="ts">
import { ref } from 'vue'

const props = withDefaults(defineProps<{ value: string; tooltip?: string }>(), {
  tooltip: 'Копировать значение',
})
const emit = defineEmits<{ copied: [] }>()
const copied = ref(false)

async function copy() {
  await navigator.clipboard.writeText(props.value)
  copied.value = true
  emit('copied')
  window.setTimeout(() => { copied.value = false }, 1500)
}
</script>
