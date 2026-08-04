<template>
  <PageContainer max-width="1040px">
    <PageHeader
      title="Индексы"
      subtitle="Расчёт изменения стоимости по официальным статистическим показателям."
    />

    <SectionCard variant="outlined">
      <div class="d-flex flex-column align-start ga-4 py-4">
        <v-chip color="primary" variant="tonal" prepend-icon="mdi-flask-outline">
          Закрытое тестирование
        </v-chip>
        <p class="text-body-1 text-medium-emphasis mb-0">
          Подготавливаются источники статистических данных и расчётное ядро.
        </p>
      </div>
    </SectionCard>
  </PageContainer>
</template>

<script setup lang="ts">
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
</script>
