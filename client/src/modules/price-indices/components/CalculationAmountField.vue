<template>
  <SectionCard
    title="3. Исходная стоимость"
    subtitle="Необязательно. Без стоимости будет рассчитан только коэффициент изменения."
    variant="outlined"
  >
    <v-text-field
      :model-value="modelValue"
      label="Исходная стоимость"
      placeholder="Например, 663 940,00"
      inputmode="decimal"
      suffix="₽"
      :error-messages="error"
      @update:model-value="$emit('update:modelValue', String($event ?? ''))"
    />
    <div class="text-caption text-medium-emphasis mt-1">
      Допустимы пробелы, запятая или точка как десятичный разделитель. Расчёт суммы выполняется только backend.
    </div>
  </SectionCard>
</template>

<script setup lang="ts">
import SectionCard from '@/components/layout/SectionCard.vue'

defineProps<{ modelValue: string; error: string }>()
defineEmits<{ 'update:modelValue': [value: string] }>()
</script>
