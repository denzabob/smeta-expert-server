<template>
  <PageContainer max-width="1040px">
    <PageHeader title="Мои расчёты" />
    <SectionCard variant="outlined">
      <div class="d-flex flex-column align-center text-center ga-4 py-10 px-4">
        <v-icon icon="mdi-history" color="primary" size="48" />
        <p class="text-body-1 text-medium-emphasis mb-0">Сохранённых расчётов пока нет.</p>
      </div>
    </SectionCard>
  </PageContainer>
</template>

<script setup lang="ts">
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
</script>
