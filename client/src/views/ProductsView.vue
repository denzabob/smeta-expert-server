<template>
  <PageContainer>
    <PageHeader
      title="Готовые изделия"
      subtitle="Каталог готовой продукции и котировок поставщиков"
    />

    <SectionCard>
      <FacadeProductsModule :show-header="true" />
    </SectionCard>
  </PageContainer>
</template>

<script setup lang="ts">
import FacadeProductsModule from '@/components/products/FacadeProductsModule.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
</script>
