<template>
  <div class="saas-button-group" :class="{ 'saas-button-group--vertical': vertical }">
    <slot />
  </div>
</template>

<script setup lang="ts">
withDefaults(
  defineProps<{
    vertical?: boolean
  }>(),
  {
    vertical: false
  }
)
</script>
