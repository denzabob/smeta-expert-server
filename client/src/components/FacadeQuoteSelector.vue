<template>
  <v-card variant="outlined" class="mt-2">
    <v-card-title class="text-subtitle-2 py-2 d-flex align-center">
      <v-icon size="small" class="mr-1">mdi-format-list-checks</v-icon>
      Котировки для расчёта (макс. 10)
      <v-spacer />
      <v-btn-toggle v-model="matchMode" density="compact" mandatory color="primary" class="mr-2">
        <v-btn value="strict" size="small">Strict</v-btn>
        <v-btn value="extended" size="small">Extended</v-btn>
      </v-btn-toggle>
      <v-btn size="small" variant="text" :loading="loadingQuotes" @click="loadQuotes">
        <v-icon>mdi-refresh</v-icon>
      </v-btn>
    </v-card-title>

    <v-divider />

    <!-- No quotes warning -->
    <v-alert v-if="!loadingQuotes && availableQuotes.length === 0" type="warning" density="compact" class="ma-2">
      Нет доступных котировок для данного фасада. Привяжите прайс-листы или добавьте котировки вручную.
    </v-alert>

    <!-- Quotes list with checkboxes -->
    <v-list v-else density="compact" class="pa-0" style="max-height: 400px; overflow-y: auto;">
      <v-list-item v-for="q in availableQuotes" :key="q.material_price_id" class="px-3">
        <template #prepend>
          <v-checkbox-btn
            :model-value="isSelected(q.material_price_id)"
            @update:model-value="toggleQuote(q.material_price_id)"
            :disabled="!isSelected(q.material_price_id) && selectedIds.length >= 10"
            density="compact"
          />
        </template>
        <v-list-item-title class="text-body-2">
          <span class="font-weight-medium">{{ formatPrice(q.price_per_m2) }} ₽/м²</span>
          <span class="text-medium-emphasis ml-2">— {{ q.supplier_name }}</span>
          <v-chip v-if="q.mismatch_flags && q.mismatch_flags.length > 0" size="x-small" color="warning" class="ml-1">
            ≠ {{ q.mismatch_flags.join(', ') }}
          </v-chip>
        </v-list-item-title>
        <v-list-item-subtitle class="text-caption">
          {{ q.price_list_name }} v{{ q.version_number }}
          <span v-if="q.captured_at"> • {{ formatDate(q.captured_at) }}</span>
          <span v-if="q.article"> • SKU: {{ q.article }}</span>
          <span v-if="q.category"> • Кат.: {{ q.category }}</span>
          <a v-if="q.source_url" :href="q.source_url" target="_blank" class="ml-1 text-primary">
            <v-icon size="x-small">mdi-open-in-new</v-icon>
          </a>
        </v-list-item-subtitle>
      </v-list-item>
    </v-list>

    <!-- Aggregation controls -->
    <v-divider v-if="selectedIds.length > 0" />
    <v-card-text v-if="selectedIds.length > 0" class="py-2">
      <v-row dense align="center">
        <v-col cols="4">
          <v-select v-model="priceMethod" :items="methodOptions" item-title="label" item-value="value"
            label="Метод расчёта" density="compact" hide-details />
        </v-col>
        <v-col cols="8" class="d-flex ga-3 align-center">
          <div>
            <span class="text-caption text-medium-emphasis">Выбрано:</span>
            <span class="font-weight-medium ml-1">{{ selectedIds.length }}</span>
          </div>
          <div v-if="aggregated">
            <span class="text-caption text-medium-emphasis">Мин:</span>
            <span class="ml-1">{{ formatPrice(aggregated.min) }}</span>
          </div>
          <div v-if="aggregated">
            <span class="text-caption text-medium-emphasis">Макс:</span>
            <span class="ml-1">{{ formatPrice(aggregated.max) }}</span>
          </div>
          <div v-if="aggregated" class="font-weight-bold text-primary">
            <span class="text-caption">Итог:</span>
            <span class="ml-1">{{ formatPrice(aggregated.result) }} ₽/м²</span>
          </div>
        </v-col>
      </v-row>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { finishedProductsApi } from '@/api/finishedProducts'
import type { SimilarFinishedProductQuote as SimilarQuote } from '@/api/finishedProducts'

const props = defineProps<{
  materialId: number | null
}>()

const emit = defineEmits<{
  (e: 'update:selectedQuotes', ids: number[]): void
  (e: 'update:priceMethod', method: string): void
  (e: 'update:aggregatedPrice', price: number | null): void
}>()

const matchMode = ref<'strict' | 'extended'>('strict')
const loadingQuotes = ref(false)
const availableQuotes = ref<SimilarQuote[]>([])
const selectedIds = ref<number[]>([])
const priceMethod = ref('single')

const methodOptions = [
  { value: 'single', label: 'Одна цена' },
  { value: 'mean', label: 'Среднее' },
  { value: 'median', label: 'Медиана' },
  { value: 'trimmed_mean', label: 'Усечённое среднее' },
]

const aggregated = computed(() => {
  const prices = selectedIds.value
    .map(id => availableQuotes.value.find(q => q.material_price_id === id))
    .filter((q): q is SimilarQuote => q !== undefined)
    .map(q => q.price_per_m2)

  if (prices.length === 0) return null

  const sorted = [...prices].sort((a, b) => a - b)
  const min = sorted[0] as number
  const max = sorted[sorted.length - 1] as number

  let result: number
  if (priceMethod.value === 'single') {
    result = sorted[0] as number
  } else if (priceMethod.value === 'mean') {
    result = sorted.reduce((a, b) => a + b, 0) / sorted.length
  } else if (priceMethod.value === 'median') {
    const mid = Math.floor(sorted.length / 2)
    result = sorted.length % 2 === 0 ? ((sorted[mid - 1] as number) + (sorted[mid] as number)) / 2 : sorted[mid] as number
  } else if (priceMethod.value === 'trimmed_mean') {
    if (sorted.length < 3) {
      const mid = Math.floor(sorted.length / 2)
      result = sorted.length % 2 === 0 ? ((sorted[mid - 1] as number) + (sorted[mid] as number)) / 2 : sorted[mid] as number
    } else {
      const trim = Math.max(1, Math.floor(sorted.length * 0.1))
      const used = sorted.slice(trim, sorted.length - trim)
      result = used.reduce((a, b) => a + b, 0) / used.length
    }
  } else {
    result = sorted[0] as number
  }

  return { min, max, result: Math.round(result * 100) / 100 }
})

// Watchers
watch(selectedIds, (ids) => {
  emit('update:selectedQuotes', ids)
  if (ids.length <= 1) priceMethod.value = 'single'
}, { deep: true })

watch(priceMethod, (m) => {
  emit('update:priceMethod', m)
})

watch(aggregated, (agg) => {
  emit('update:aggregatedPrice', agg?.result ?? null)
})

watch(() => props.materialId, () => {
  selectedIds.value = []
  loadQuotes()
})

watch(matchMode, () => loadQuotes())

// Methods
function isSelected(id: number) {
  return selectedIds.value.includes(id)
}

function toggleQuote(id: number) {
  const idx = selectedIds.value.indexOf(id)
  if (idx >= 0) {
    selectedIds.value.splice(idx, 1)
  } else if (selectedIds.value.length < 10) {
    selectedIds.value.push(id)
  }
}

async function loadQuotes() {
  if (!props.materialId) { availableQuotes.value = []; return }
  loadingQuotes.value = true
  try {
    const { data } = await finishedProductsApi.getSimilarQuotes(props.materialId, matchMode.value)
    availableQuotes.value = data.quotes ?? []
  } catch (e) {
    console.error('Failed to load quotes', e)
    availableQuotes.value = []
  } finally {
    loadingQuotes.value = false
  }
}

function formatPrice(val: number | null | undefined) {
  if (val == null) return '—'
  return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(val)
}

function formatDate(val: string | null) {
  if (!val) return ''
  return new Date(val).toLocaleDateString('ru-RU')
}

onMounted(() => {
  if (props.materialId) loadQuotes()
})
</script>
