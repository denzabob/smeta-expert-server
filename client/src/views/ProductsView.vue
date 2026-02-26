<template>
  <v-container fluid class="pa-0">
    <v-sheet class="pa-4" color="surface">
      <div class="d-flex flex-wrap align-center ga-3">
        <div>
          <div class="text-h5 font-weight-medium">Готовые изделия</div>
          <div class="text-medium-emphasis">Каталог готовой продукции и котировок поставщиков</div>
        </div>
      </div>
    </v-sheet>

    <v-sheet class="pa-4">
      <FacadeProductsModule :show-header="false" />
    </v-sheet>
  </v-container>
</template>

<script setup lang="ts">
import FacadeProductsModule from '@/components/products/FacadeProductsModule.vue'
</script>
