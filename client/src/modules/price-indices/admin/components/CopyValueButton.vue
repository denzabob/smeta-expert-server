<template>
  <SharedCopyValueButton :value="value" :tooltip="tooltip" @copied="$emit('copied')" />
</template>

<script setup lang="ts">
import SharedCopyValueButton from '../../components/CopyValueButton.vue'

defineProps<{ value: string; tooltip?: string }>()
defineEmits<{ copied: [] }>()
</script>
