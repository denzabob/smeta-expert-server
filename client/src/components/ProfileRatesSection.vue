<template>
  <div class="profile-rates-section">
    <!-- Заголовок -->
    <div class="section-header mb-4">
      <div class="d-flex align-center justify-space-between">
        <h3 class="text-h6">Ставки нормо-часов по профилям</h3>
        <v-btn
          size="small"
          color="primary"
          prepend-icon="mdi-refresh"
          :loading="loading"
          @click="loadProfileRates"
        >
          Обновить
        </v-btn>
      </div>
      <p class="text-caption text-grey mt-2">
        Управление ставками нормо-часов для различных профилей должностей в проекте
      </p>
    </div>

    <!-- Пустое состояние -->
    <v-alert
      v-if="!loading && rates.length === 0"
      type="info"
      variant="tonal"
      class="mb-4"
    >
      <v-alert-title>Нет добавленных ставок</v-alert-title>
      Начните с расчета ставки для первого профиля должности
    </v-alert>

    <!-- Таблица ставок -->
    <v-data-table
      v-if="!loading && rates.length > 0"
      :items="rates"
      :headers="tableHeaders"
      density="comfortable"
      class="mb-4"
      item-value="id"
    >
      <!-- Профиль -->
      <template v-slot:item.profile_name="{ item }">
        <div>
          <strong>{{ item.profile_name }}</strong>
          <v-chip
            v-if="item.region_name"
            size="x-small"
            variant="outlined"
            class="ml-2"
          >
            {{ item.region_name }}
          </v-chip>
        </div>
      </template>

      <!-- Ставка -->
      <template v-slot:item.rate_fixed="{ item }">
        <div class="font-weight-bold">{{ formatPrice(item.rate_fixed) }} ₽/ч</div>
        <div class="text-caption text-grey">
          Метод: {{ getMethodLabel(item.calculation_method) }}
        </div>
      </template>

      <!-- Дата -->
      <template v-slot:item.fixed_at="{ item }">
        <div class="text-caption">
          {{ formatDate(item.fixed_at) }}
        </div>
      </template>

      <!-- Статус блокировки -->
      <template v-slot:item.is_locked="{ item }">
        <div v-if="item.is_locked" class="d-flex align-center gap-1">
          <v-icon size="small" color="warning">mdi-lock</v-icon>
          <span class="text-caption font-weight-bold">Заблокирована</span>
        </div>
        <div v-else class="text-caption text-grey">—</div>
      </template>

      <!-- Действия -->
      <template v-slot:item.actions="{ item }">
        <div class="d-flex gap-1 align-center">
          <!-- Просмотр обоснования -->
          <v-tooltip text="Показать обоснование расчета">
            <template v-slot:activator="{ props }">
              <v-btn
                v-bind="props"
                icon
                size="small"
                variant="text"
                @click="showJustification(item)"
              >
                <v-icon size="small">mdi-information-outline</v-icon>
              </v-btn>
            </template>
          </v-tooltip>

          <!-- Пересчитать -->
          <v-tooltip text="Пересчитать ставку">
            <template v-slot:activator="{ props }">
              <v-btn
                v-bind="props"
                icon
                size="small"
                variant="text"
                color="primary"
                :disabled="item.is_locked || recalculatingId === item.id"
                :loading="recalculatingId === item.id"
                @click="recalculateRate(item)"
              >
                <v-icon size="small">mdi-sync</v-icon>
              </v-btn>
            </template>
          </v-tooltip>

          <!-- Заблокировать/разблокировать -->
          <v-tooltip :text="item.is_locked ? 'Разблокировать' : 'Заблокировать'">
            <template v-slot:activator="{ props }">
              <v-btn
                v-bind="props"
                icon
                size="small"
                variant="text"
                :color="item.is_locked ? 'warning' : 'default'"
                @click="toggleLock(item)"
              >
                <v-icon size="small">{{ item.is_locked ? 'mdi-lock-open' : 'mdi-lock' }}</v-icon>
              </v-btn>
            </template>
          </v-tooltip>

          <!-- Удалить -->
          <v-tooltip text="Удалить ставку">
            <template v-slot:activator="{ props }">
              <v-btn
                v-bind="props"
                icon
                size="small"
                variant="text"
                color="error"
                :disabled="item.is_locked"
                @click="deleteRate(item)"
              >
                <v-icon size="small">mdi-delete</v-icon>
              </v-btn>
            </template>
          </v-tooltip>
        </div>
      </template>
    </v-data-table>

    <!-- Диалог обоснования -->
    <v-dialog v-model="justificationDialog" max-width="800">
      <v-card>
        <v-card-title class="d-flex align-center justify-space-between">
          <span>Обоснование расчета: {{ selectedRate?.profile_name }}</span>
          <v-btn icon size="small" variant="text" @click="justificationDialog = false">
            <v-icon>mdi-close</v-icon>
          </v-btn>
        </v-card-title>

        <v-card-text class="mt-4">
          <!-- Таблица источников -->
          <div v-if="selectedRate?.sources_snapshot && selectedRate.sources_snapshot.length > 0" class="mb-6">
            <h4 class="text-subtitle-2 mb-3">Использованные источники ({{ selectedRate.sources_snapshot.length }} шт):</h4>
            <v-table class="border-table">
              <thead>
                <tr>
                  <th>Источник</th>
                  <th style="text-align: right;">Ставка</th>
                  <th>Дата</th>
                  <th>Регион</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(source, idx) in selectedRate.sources_snapshot" :key="idx">
                  <td>
                    <div class="font-weight-medium">{{ source.source }}</div>
                    <div v-if="source.link" class="text-caption">
                      <v-btn
                        :href="source.link"
                        target="_blank"
                        size="x-small"
                        variant="text"
                        color="primary"
                      >
                        Ссылка
                      </v-btn>
                    </div>
                  </td>
                  <td style="text-align: right;">
                    <strong>{{ formatPrice(source.rate_per_hour) }} ₽/ч</strong>
                  </td>
                  <td class="text-caption">{{ formatDate(source.source_date) }}</td>
                  <td class="text-caption">{{ source.region_name || '—' }}</td>
                </tr>
              </tbody>
            </v-table>
          </div>

          <!-- Текст обоснования -->
          <div class="mb-6">
            <h4 class="text-subtitle-2 mb-3">Расчет:</h4>
            <v-card variant="outlined" class="pa-4">
              <div style="white-space: pre-wrap; font-family: monospace; font-size: 12px; line-height: 1.6;">
                {{ selectedRate?.justification_snapshot }}
              </div>
            </v-card>
          </div>

          <!-- Метаинформация -->
          <div class="text-caption text-grey">
            <div>Дата расчета: <strong>{{ formatDate(selectedRate?.fixed_at) }}</strong></div>
            <div>Метод: <strong>{{ getMethodLabel(selectedRate?.calculation_method) }}</strong></div>
            <div v-if="selectedRate?.is_locked">
              Статус: <strong style="color: #f57c00;">Заблокирована</strong>
              <span v-if="selectedRate.lock_reason"> — {{ selectedRate.lock_reason }}</span>
            </div>
          </div>
        </v-card-text>

        <v-card-actions>
          <v-spacer />
          <v-btn @click="justificationDialog = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Диалог блокировки -->
    <v-dialog v-model="lockDialog" max-width="600">
      <v-card>
        <v-card-title>
          {{ lockingRate?.is_locked ? 'Разблокировать ставку' : 'Заблокировать ставку' }}
        </v-card-title>

        <v-card-text class="mt-4">
          <p v-if="!lockingRate?.is_locked" class="mb-4">
            Заблокированная ставка не может быть изменена или удалена.
          </p>

          <v-text-field
            v-if="!lockingRate?.is_locked"
            v-model="lockReason"
            label="Причина блокировки (опционально)"
            placeholder="Например: Согласовано с клиентом"
            maxlength="500"
            counter
          />
        </v-card-text>

        <v-card-actions>
          <v-spacer />
          <v-btn @click="lockDialog = false">Отмена</v-btn>
          <v-btn
            color="primary"
            @click="confirmLock"
            :loading="lockingInProgress"
          >
            {{ lockingRate?.is_locked ? 'Разблокировать' : 'Заблокировать' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import api from '@/api/axios'

// === Интерфейсы ===
interface ProfileRate {
  id: number
  profile_id: number
  profile_name: string
  region_id: number | null
  region_name: string | null
  rate_fixed: number
  fixed_at: string
  calculation_method: 'median' | 'average'
  is_locked: boolean
  lock_reason: string | null
  justification_snapshot: string
  sources_snapshot: any[]
}

interface SourceItem {
  source: string
  rate_per_hour: number
  source_date: string
  region_name?: string
  link?: string
}

// === Props ===
const props = defineProps<{
  projectId: number
}>()

// === State ===
const rates = ref<ProfileRate[]>([])
const loading = ref(false)
const recalculatingId = ref<number | null>(null)
const lockingInProgress = ref(false)

// Диалоги
const justificationDialog = ref(false)
const lockDialog = ref(false)
const selectedRate = ref<ProfileRate | null>(null)
const lockingRate = ref<ProfileRate | null>(null)
const lockReason = ref('')

// === Таблица ===
const tableHeaders = [
  { title: 'Профиль должности', key: 'profile_name', width: '250px' },
  { title: 'Ставка', key: 'rate_fixed', width: '150px' },
  { title: 'Дата расчета', key: 'fixed_at', width: '150px' },
  { title: 'Статус', key: 'is_locked', width: '100px' },
  { title: '', key: 'actions', width: '120px', sortable: false },
]

// === Методы ===

const loadProfileRates = async () => {
  loading.value = true
  try {
    const response = await api.get(`/projects/${props.projectId}/profile-rates`)
    rates.value = response.data.data || []
    console.log('📊 Profile rates loaded from endpoint:', rates.value)
    if (rates.value.length > 0) {
      console.log('📋 First rate structure:', JSON.stringify(rates.value[0], null, 2))
    }
  } catch (error) {
    console.error('Ошибка загрузки ставок:', error)
  } finally {
    loading.value = false
  }
}

const showJustification = (rate: ProfileRate) => {
  selectedRate.value = rate
  justificationDialog.value = true
}

const recalculateRate = async (rate: ProfileRate) => {
  recalculatingId.value = rate.id
  try {
    const response = await api.post(
      `/projects/${props.projectId}/profile-rates/${rate.profile_id}/recalculate`,
      { method: rate.calculation_method }
    )

    // Обновить ставку в списке
    const index = rates.value.findIndex(r => r.id === rate.id)
    if (index >= 0) {
      rates.value[index] = response.data.data
    }
  } catch (error: any) {
    if (error.response?.status === 409) {
      // Ставка заблокирована
      console.warn('Ставка заблокирована:', error.response.data.lock_reason)
    } else {
      console.error('Ошибка пересчета:', error)
    }
  } finally {
    recalculatingId.value = null
  }
}

const toggleLock = (rate: ProfileRate) => {
  lockingRate.value = rate
  lockReason.value = rate.lock_reason || ''
  lockDialog.value = true
}

const confirmLock = async () => {
  if (!lockingRate.value) return

  lockingInProgress.value = true
  try {
    const response = await api.patch(
      `/projects/${props.projectId}/profile-rates/${lockingRate.value.id}`,
      {
        is_locked: !lockingRate.value.is_locked,
        lock_reason: lockReason.value || null,
      }
    )

    // Обновить ставку в списке
    const index = rates.value.findIndex(r => r.id === lockingRate.value!.id)
    if (index >= 0) {
      rates.value[index] = response.data.data
    }

    lockDialog.value = false
  } catch (error) {
    console.error('Ошибка обновления ставки:', error)
  } finally {
    lockingInProgress.value = false
  }
}

const deleteRate = async (rate: ProfileRate) => {
  if (!confirm(`Удалить ставку для "${rate.profile_name}"?`)) return

  try {
    await api.delete(`/projects/${props.projectId}/profile-rates/${rate.id}`)
    rates.value = rates.value.filter(r => r.id !== rate.id)
  } catch (error) {
    console.error('Ошибка удаления:', error)
  }
}

// === Форматеры ===

const formatPrice = (value: number) => {
  return new Intl.NumberFormat('ru-RU', {
    style: 'decimal',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value)
}

const formatDate = (date: string | null | undefined) => {
  if (!date) return '—'
  return new Date(date).toLocaleDateString('ru-RU', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

const getMethodLabel = (method: string | undefined) => {
  return method === 'median' ? 'Медиана' : 'Среднее'
}

// === Lifecycle ===

onMounted(() => {
  loadProfileRates()
})
</script>

<style scoped>
.profile-rates-section {
  padding: 16px 0;
}

.section-header {
  border-bottom: 1px solid rgba(0, 0, 0, 0.12);
  padding-bottom: 16px;
}

.border-table {
  width: 100%;
  border-collapse: collapse;
}

.border-table thead tr {
  background-color: rgba(0, 0, 0, 0.04);
}

.border-table th {
  padding: 10px;
  text-align: left;
  font-weight: 500;
  border-bottom: 1px solid rgba(0, 0, 0, 0.12);
}

.border-table td {
  padding: 10px;
  border-bottom: 1px solid rgba(0, 0, 0, 0.08);
}

.border-table tbody tr:hover {
  background-color: rgba(0, 0, 0, 0.02);
}
</style>
