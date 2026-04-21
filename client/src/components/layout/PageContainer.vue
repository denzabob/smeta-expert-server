<template>
  <v-container
    fluid
    class="saas-page-container md3-page-container"
    :class="{ 'saas-page-container--no-padding': noPadding }"
  >
    <div class="saas-page-container__inner md3-page-container__inner" :style="{ maxWidth }">
      <slot />
    </div>
  </v-container>
</template>

<script setup lang="ts">
withDefaults(
  defineProps<{
    maxWidth?: string
    noPadding?: boolean
  }>(),
  {
    maxWidth: '1360px',
    noPadding: false
  }
)
</script>
