<template>
  <v-dialog v-model="dialog" max-width="700">
    <v-card>
      <v-card-title class="d-flex align-center">
        <v-icon class="mr-2">mdi-history</v-icon>
        История цен: {{ materialName }}
        <v-spacer />
        <v-btn icon="mdi-close" variant="text" @click="dialog = false" />
      </v-card-title>

      <v-card-text>
        <v-row dense class="mb-3">
          <v-col cols="12" sm="6">
            <v-select
              v-model="filterRegionId"
              :items="regionOptions"
              item-title="label"
              item-value="value"
              label="Регион"
              clearable
              hide-details
              @update:model-value="loadData"
            />
          </v-col>
        </v-row>

        <v-data-table
          :headers="headers"
          :items="observations"
          :loading="loading"
          density="compact"
          class="elevation-1"
          item-key="id"
          no-data-text="Нет наблюдений цен"
        >
          <template #item.price_per_unit="{ item }">
            <span class="font-weight-medium">{{ formatPrice(item.price_per_unit) }}</span>
            <span class="text-caption ml-1">{{ item.currency }}</span>
          </template>

          <template #item.observed_at="{ item }">
            {{ formatDate(item.observed_at || item.created_at) }}
          </template>

          <template #item.source_type="{ item }">
            <v-chip size="x-small" :color="sourceTypeColor(item.source_type)" variant="tonal">
              {{ sourceTypeLabel(item.source_type) }}
            </v-chip>
          </template>

          <template #item.is_verified="{ item }">
            <v-icon
              :icon="item.is_verified ? 'mdi-check-circle' : 'mdi-circle-outline'"
              :color="item.is_verified ? 'success' : 'grey'"
              size="small"
            />
          </template>

          <template #item.source_url="{ item }">
            <a
              v-if="item.source_url"
              :href="item.source_url"
              target="_blank"
              class="text-primary text-decoration-none"
            >
              <v-icon size="small">mdi-open-in-new</v-icon>
              {{ truncateUrl(item.source_url) }}
            </a>
            <span v-else class="text-disabled">—</span>
          </template>
        </v-data-table>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useMaterialCatalogStore } from '@/stores/materialCatalog'
import type { PriceObservation } from '@/api/materialCatalog'

const props = defineProps<{
  modelValue: boolean
  materialId: number | null
  materialName?: string
  defaultRegionId?: number | null
  regions?: Array<{ id: number; region_name: string }>
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', val: boolean): void
}>()

const store = useMaterialCatalogStore()

const dialog = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const filterRegionId = ref<number | null>(props.defaultRegionId ?? null)
const loading = computed(() => store.loadingObservations)
const observations = computed(() => store.observations)

const headers = [
  { title: 'Цена', key: 'price_per_unit', width: '120px' },
  { title: 'Дата', key: 'observed_at', width: '120px' },
  { title: 'Источник', key: 'source_type', width: '100px' },
  { title: 'Верифицировано', key: 'is_verified', width: '80px', align: 'center' as const },
  { title: 'URL', key: 'source_url' },
]

const regionOptions = computed(() => {
  const opts: Array<{ label: string; value: number }> = []
  if (props.regions) {
    for (const r of props.regions) {
      opts.push({ label: r.region_name, value: r.id })
    }
  }
  return opts
})

async function loadData() {
  if (props.materialId) {
    await store.loadObservations(props.materialId, filterRegionId.value)
  }
}

watch(() => props.modelValue, (v) => {
  if (v && props.materialId) {
    loadData()
  }
})

function formatPrice(v: number | string): string {
  const num = typeof v === 'string' ? parseFloat(v) : v
  return num.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function formatDate(d: string | null): string {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('ru-RU')
}

function sourceTypeLabel(t: string): string {
  const map: Record<string, string> = {
    web: 'Веб',
    manual: 'Ручной',
    price_list: 'Прайс',
    chrome_ext: 'Плагин',
  }
  return map[t] || t
}

function sourceTypeColor(t: string): string {
  const map: Record<string, string> = {
    web: 'primary',
    manual: 'grey',
    price_list: 'info',
    chrome_ext: 'success',
  }
  return map[t] || 'grey'
}

function truncateUrl(url: string): string {
  try {
    const u = new URL(url)
    const path = u.pathname.length > 30 ? u.pathname.substring(0, 30) + '...' : u.pathname
    return u.hostname + path
  } catch {
    return url.substring(0, 40)
  }
}
</script>
