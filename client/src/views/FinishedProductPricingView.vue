<template>
  <PageContainer>
    <PageHeader
      title="Цены и доказательства фасада"
      subtitle="Источники цены, способ расчёта и подтверждающие материалы для выбранного фасада"
    >
      <template #actions>
        <v-btn
          variant="text"
          prepend-icon="mdi-arrow-left"
          class="text-none"
          @click="goBack"
        >
          К каталогу
        </v-btn>
      </template>
    </PageHeader>

    <SectionCard class="finished-product-pricing-page">
      <FinishedProductPricingModule :specification-id="specificationId" />
    </SectionCard>
  </PageContainer>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import FinishedProductPricingModule from '@/components/products/FinishedProductPricingModule.vue'

const route = useRoute()
const router = useRouter()

const specificationId = computed(() => Number(route.params.id))

function goBack() {
  router.push({ name: 'products' })
}
</script>

<style scoped>
.finished-product-pricing-page {
  background: color-mix(in srgb, var(--md-sys-color-surface-container-low) 96%, transparent);
}
</style>
