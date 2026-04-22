<template>
  <PageContainer>
    <PageHeader
      title="Готовые изделия"
      subtitle="Новый каталог фасадных спецификаций и агрегированной pricing-сводки"
    />

    <SectionCard>
      <FinishedProductSpecificationsModule :show-header="true" />
    </SectionCard>
  </PageContainer>
</template>

<script setup lang="ts">
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import FinishedProductSpecificationsModule from '@/components/products/FinishedProductSpecificationsModule.vue'
</script>
