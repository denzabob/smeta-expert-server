<template>
  <PageContainer max-width="1040px">
    <PageHeader
      title="Индексы"
      subtitle="Расчёт изменения стоимости по официальным статистическим показателям."
    />

    <SectionCard title="Расчёт по индексам" variant="outlined">
      <div class="d-flex flex-column align-start ga-4 py-4">
        <p class="text-body-1 text-medium-emphasis mb-0">
          Выберите товар и период, чтобы получить коэффициент изменения стоимости и проверить каждое использованное статистическое значение.
        </p>
        <v-btn color="primary" prepend-icon="mdi-calculator-variant-outline" :to="{ name: 'price-indices-new-calculation' }">
          Новый расчёт
        </v-btn>
      </div>
    </SectionCard>
  </PageContainer>
</template>

<script setup lang="ts">
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
</script>
