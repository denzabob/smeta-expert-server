<template>
  <PageContainer max-width="1040px">
    <PageHeader
      title="Источники данных"
      subtitle="Наборы официальных статистических данных, используемые приложением „ПРИЗМА Индексы“."
    />

    <SectionCard variant="outlined">
      <div class="d-flex align-start ga-3">
        <v-icon icon="mdi-information-outline" color="primary" />
        <div>
          <p class="text-body-1 mb-2">
            Управление наборами статистических данных будет реализовано на следующем этапе.
          </p>
          <p class="text-body-2 text-medium-emphasis mb-0">Источники ещё не подключены.</p>
        </div>
      </div>
    </SectionCard>
  </PageContainer>
</template>

<script setup lang="ts">
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
</script>
