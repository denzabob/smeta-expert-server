<template>
  <PageContainer>
    <PageHeader
      title="Готовые изделия"
      subtitle="Новый каталог фасадных спецификаций и агрегированной pricing-сводки"
    />

    <FinishedProductSpecificationsModule :show-header="true" />
  </PageContainer>
</template>

<script setup lang="ts">
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import FinishedProductSpecificationsModule from '@/components/products/FinishedProductSpecificationsModule.vue'
</script>
