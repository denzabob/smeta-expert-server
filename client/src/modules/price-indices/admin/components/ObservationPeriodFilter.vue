<template>
  <SectionCard variant="outlined">
    <div class="d-flex flex-wrap align-center ga-3">
      <div class="text-title-medium font-weight-medium mr-auto">Период наблюдений</div>
      <v-text-field :model-value="from" type="month" label="С" density="compact" variant="outlined" hide-details
        :min="min" :max="to || max" class="period-field" @update:model-value="$emit('update:from', $event)" />
      <v-text-field :model-value="to" type="month" label="По" density="compact" variant="outlined" hide-details
        :min="from || min" :max="max" class="period-field" @update:model-value="$emit('update:to', $event)" />
      <v-btn color="primary" variant="tonal" :disabled="!from || !to || from > to" :loading="loading" @click="$emit('apply')">Применить</v-btn>
    </div>
  </SectionCard>
</template>
<script setup lang="ts">
import SectionCard from '@/components/layout/SectionCard.vue'
defineProps<{ from: string; to: string; min: string; max: string; loading: boolean }>()
defineEmits<{ 'update:from': [value: string]; 'update:to': [value: string]; apply: [] }>()
</script>
<style scoped>.period-field { max-width: 180px; min-width: 160px; }</style>
