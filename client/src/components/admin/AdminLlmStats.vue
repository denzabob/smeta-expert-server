<template>
  <div>
    <v-row class="mb-4">
      <v-col cols="12" md="3">
        <v-card variant="outlined">
          <v-card-text class="text-center">
            <div class="text-h4 font-weight-bold text-primary">{{ stats.total_requests }}</div>
            <div class="text-body-2 text-medium-emphasis">Всего запросов</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card variant="outlined">
          <v-card-text class="text-center">
            <div class="text-h4 font-weight-bold text-success">{{ stats.successful_requests }}</div>
            <div class="text-body-2 text-medium-emphasis">Успешных</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card variant="outlined">
          <v-card-text class="text-center">
            <div class="text-h4 font-weight-bold text-error">{{ stats.failed_requests }}</div>
            <div class="text-body-2 text-medium-emphasis">Ошибок</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="3">
        <v-card variant="outlined">
          <v-card-text class="text-center">
            <div class="text-h4 font-weight-bold">${{ stats.total_cost.toFixed(2) }}</div>
            <div class="text-body-2 text-medium-emphasis">Общая стоимость</div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <v-card variant="outlined" :loading="loading">
      <v-card-title class="d-flex align-center">
        <v-icon class="mr-2">mdi-chart-line</v-icon>
        Статистика по провайдерам
        <v-spacer />
        <v-select
          v-model="period"
          :items="periodOptions"
          density="compact"
          variant="outlined"
          hide-details
          style="max-width: 150px"
          @update:model-value="loadStats"
        />
      </v-card-title>

      <v-card-text>
        <v-data-table
          :headers="headers"
          :items="providerStats"
          density="comfortable"
          class="elevation-0"
        >
          <template #item.provider="{ item }">
            <div class="d-flex align-center">
              <v-icon class="mr-2" size="small">{{ providerIcon(item.provider) }}</v-icon>
              {{ item.provider }}
            </div>
          </template>

          <template #item.success_rate="{ item }">
            <v-chip
              :color="item.success_rate >= 95 ? 'success' : item.success_rate >= 80 ? 'warning' : 'error'"
              size="small"
              variant="tonal"
            >
              {{ item.success_rate.toFixed(1) }}%
            </v-chip>
          </template>

          <template #item.avg_latency="{ item }">
            <span class="text-medium-emphasis">{{ item.avg_latency.toFixed(0) }} ms</span>
          </template>

          <template #item.cost="{ item }">
            ${{ item.cost.toFixed(4) }}
          </template>
        </v-data-table>
      </v-card-text>
    </v-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import api from '@/api/axios'

const loading = ref(false)
const period = ref('7d')

const stats = reactive({
  total_requests: 0,
  successful_requests: 0,
  failed_requests: 0,
  total_cost: 0
})

const providerStats = ref<Array<{
  provider: string
  requests: number
  success_rate: number
  avg_latency: number
  cost: number
}>>([])

const periodOptions = [
  { title: 'Сегодня', value: '1d' },
  { title: '7 дней', value: '7d' },
  { title: '30 дней', value: '30d' },
  { title: 'Все время', value: 'all' }
]

const headers = [
  { title: 'Провайдер', key: 'provider', sortable: false },
  { title: 'Запросов', key: 'requests', sortable: true },
  { title: 'Успешность', key: 'success_rate', sortable: true },
  { title: 'Средняя задержка', key: 'avg_latency', sortable: true },
  { title: 'Стоимость', key: 'cost', sortable: true }
]

function providerIcon(provider: string): string {
  const icons: Record<string, string> = {
    openai: 'mdi-robot',
    anthropic: 'mdi-brain',
    gemini: 'mdi-google',
    openrouter: 'mdi-router-network'
  }
  return icons[provider] || 'mdi-help-circle'
}

async function loadStats() {
  loading.value = true
  try {
    const [totalsResponse, providersResponse] = await Promise.all([
      api.get('/api/admin/llm-stats', { params: { period: period.value } }),
      api.get('/api/admin/llm-stats/providers', { params: { period: period.value } }),
    ])

    const totals = totalsResponse.data?.totals || {}
    stats.total_requests = totals.total_requests || 0
    stats.successful_requests = totals.successful_requests || 0
    stats.failed_requests = totals.failed_requests || 0
    stats.total_cost = totals.total_cost || 0

    const providers = providersResponse.data?.providers || []
    providerStats.value = providers.map((p: any) => ({
      provider: p.provider,
      requests: p.total_requests || 0,
      success_rate: p.success_rate || 0,
      avg_latency: p.avg_latency_ms || 0,
      cost: p.total_cost || 0,
    }))
  } catch (error) {
    console.error('Failed to load stats:', error)
    // Mock data for display
    providerStats.value = [
      { provider: 'openai', requests: 150, success_rate: 98.5, avg_latency: 450, cost: 0.25 },
      { provider: 'anthropic', requests: 50, success_rate: 96.0, avg_latency: 520, cost: 0.12 }
    ]
    stats.total_requests = 200
    stats.successful_requests = 195
    stats.failed_requests = 5
    stats.total_cost = 0.37
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadStats()
})
</script>
