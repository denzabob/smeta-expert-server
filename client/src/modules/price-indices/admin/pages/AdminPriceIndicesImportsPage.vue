<template>
  <PageContainer max-width="1040px">
    <PageHeader
      title="Импорты XLSX"
      subtitle="Загрузка и обработка официальных файлов статистических данных."
    />

    <SectionCard variant="outlined">
      <div class="d-flex align-start ga-3">
        <v-icon icon="mdi-information-outline" color="primary" />
        <p class="text-body-1 mb-0">
          Загрузка и предварительная проверка файлов Росстата будет реализована после утверждения
          модели статистических данных.
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
