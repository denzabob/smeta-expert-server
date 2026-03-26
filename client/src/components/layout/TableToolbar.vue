<template>
  <div class="ds-table-toolbar">
    <div v-if="$slots.search" class="ds-table-toolbar__search">
      <slot name="search" />
    </div>
    <div v-if="$slots.filters" class="ds-table-toolbar__filters">
      <slot name="filters" />
    </div>
    <div class="ds-table-toolbar__spacer" />
    <div v-if="$slots.actions" class="ds-table-toolbar__actions">
      <slot name="actions" />
    </div>
  </div>
</template>
