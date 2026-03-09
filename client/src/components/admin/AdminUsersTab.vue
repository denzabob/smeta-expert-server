<template>
  <v-card variant="outlined" :loading="loading">
    <v-card-title class="d-flex align-center">
      <v-icon class="mr-2">mdi-account-group</v-icon>
      Пользователи (активность LLM)
      <v-spacer />
      <v-select
        v-model="period"
        :items="periodOptions"
        density="compact"
        variant="outlined"
        hide-details
        style="max-width: 160px"
        @update:model-value="loadUsers"
      />
    </v-card-title>

    <v-card-text>
      <v-text-field
        v-model="search"
        prepend-inner-icon="mdi-magnify"
        label="Поиск по имени"
        variant="outlined"
        density="compact"
        hide-details
        class="mb-4"
        style="max-width: 320px"
      />

      <v-data-table
        :headers="headers"
        :items="filteredUsers"
        :loading="loading"
        density="comfortable"
        class="elevation-0"
      >
        <template #item.user_name="{ item }">
          <div class="d-flex align-center">
            <v-avatar size="32" class="mr-2" color="primary">
              <span class="text-white text-caption">{{ initials(item.user_name) }}</span>
            </v-avatar>
            <div class="font-weight-medium">{{ item.user_name }}</div>
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

        <template #item.total_cost="{ item }">
          ${{ item.total_cost.toFixed(4) }}
        </template>

        <template #item.last_used_at="{ item }">
          <span class="text-medium-emphasis">{{ formatDate(item.last_used_at) }}</span>
        </template>
      </v-data-table>

      <v-alert type="info" variant="tonal" class="mt-4" density="compact">
        Раздел показывает статистику использования LLM по пользователям. Управление аккаунтами в этой версии API не реализовано.
      </v-alert>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
import { computed, ref, onMounted } from 'vue'
import api from '@/api/axios'

interface UserLlmStats {
  user_id: number
  user_name: string
  total_requests: number
  successful_requests: number
  success_rate: number
  total_cost: number
  total_tokens: number
  avg_latency_ms: number
  last_used_at: string | null
}

const loading = ref(false)
const search = ref('')
const period = ref('30d')
const users = ref<UserLlmStats[]>([])

const periodOptions = [
  { title: '7 дней', value: '7d' },
  { title: '30 дней', value: '30d' },
  { title: '90 дней', value: '90d' },
]

const headers = [
  { title: 'Пользователь', key: 'user_name', sortable: false },
  { title: 'Запросов', key: 'total_requests', sortable: true, width: 110 },
  { title: 'Успешность', key: 'success_rate', sortable: true, width: 120 },
  { title: 'Токены', key: 'total_tokens', sortable: true, width: 120 },
  { title: 'Средняя задержка', key: 'avg_latency_ms', sortable: true, width: 150 },
  { title: 'Стоимость', key: 'total_cost', sortable: true, width: 110 },
  { title: 'Последнее использование', key: 'last_used_at', sortable: true, width: 180 },
]

const filteredUsers = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return users.value
  return users.value.filter(u => u.user_name.toLowerCase().includes(q))
})

function initials(name: string): string {
  return name
    .split(' ')
    .map(n => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  return new Date(date).toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

async function loadUsers() {
  loading.value = true
  try {
    const response = await api.get('/api/admin/llm-stats/users', {
      params: { period: period.value },
    })
    users.value = response.data?.users || []
  } catch (error) {
    console.error('Failed to load users stats:', error)
    users.value = []
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadUsers()
})
</script>
