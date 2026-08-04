<template>
  <PageContainer max-width="1040px">
    <PageHeader
      title="Журнал импорта"
      subtitle="История загрузки и обработки источников статистических данных."
    />

    <SectionCard variant="outlined">
      <div class="d-flex align-start ga-3">
        <v-icon icon="mdi-information-outline" color="primary" />
        <p class="text-body-1 mb-0">
          История загрузки и обработки источников пока отсутствует.
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
