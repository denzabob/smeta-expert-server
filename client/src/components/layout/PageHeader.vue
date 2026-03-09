<template>
  <header class="saas-page-header">
    <div class="saas-page-header__main">
      <div class="saas-page-header__title-row">
        <slot name="prefix" />
        <h1 class="saas-page-header__title">{{ title }}</h1>
        <slot name="title-extra" />
      </div>
      <p v-if="subtitle" class="saas-page-header__subtitle">{{ subtitle }}</p>
    </div>

    <div v-if="$slots.actions" class="saas-page-header__actions">
      <slot name="actions" />
    </div>
  </header>
</template>

<script setup lang="ts">
defineProps<{
  title: string
  subtitle?: string
}>()
</script>
