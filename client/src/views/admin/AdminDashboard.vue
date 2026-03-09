<template>
  <div class="admin-dashboard">
    <!-- Welcome Section -->
    <div class="dashboard-header">
      <div>
        <h2 class="text-h5 font-weight-medium mb-1">Рабочий стол</h2>
        <p class="text-body-2 text-medium-emphasis">
          Управление материалами и правилами распознавания
        </p>
      </div>
    </div>

    <!-- Quick Stats -->
    <v-row class="mb-6">
      <v-col cols="12" sm="6" md="3">
        <v-card class="stat-card" variant="outlined" @click="goTo('/admin/problems')">
          <v-card-text class="d-flex align-center">
            <div class="stat-icon stat-icon--warning">
              <v-icon icon="mdi-alert-circle-outline" size="24" />
            </div>
            <div class="ml-4">
              <div class="text-h5 font-weight-bold">{{ stats.problemCases }}</div>
              <div class="text-body-2 text-medium-emphasis">Проблемные случаи</div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" sm="6" md="3">
        <v-card class="stat-card" variant="outlined" @click="goTo('/admin/materials?status=pending')">
          <v-card-text class="d-flex align-center">
            <div class="stat-icon stat-icon--info">
              <v-icon icon="mdi-clock-outline" size="24" />
            </div>
            <div class="ml-4">
              <div class="text-h5 font-weight-bold">{{ stats.pendingReview }}</div>
              <div class="text-body-2 text-medium-emphasis">Ожидают проверки</div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" sm="6" md="3">
        <v-card class="stat-card" variant="outlined" @click="goTo('/admin/rules')">
          <v-card-text class="d-flex align-center">
            <div class="stat-icon stat-icon--success">
              <v-icon icon="mdi-check-circle-outline" size="24" />
            </div>
            <div class="ml-4">
              <div class="text-h5 font-weight-bold">{{ stats.activeRules }}</div>
              <div class="text-body-2 text-medium-emphasis">Активных правил</div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" sm="6" md="3">
        <v-card class="stat-card" variant="outlined">
          <v-card-text class="d-flex align-center">
            <div class="stat-icon stat-icon--primary">
              <v-icon icon="mdi-package-variant-closed" size="24" />
            </div>
            <div class="ml-4">
              <div class="text-h5 font-weight-bold">{{ stats.totalMaterials }}</div>
              <div class="text-body-2 text-medium-emphasis">Всего материалов</div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- Quick Actions -->
    <h3 class="text-subtitle-1 font-weight-medium mb-3">Быстрые действия</h3>
    <v-row class="mb-6">
      <v-col cols="12" sm="6" md="4">
        <v-card 
          class="action-card" 
          variant="outlined"
          @click="goTo('/admin/problems')"
        >
          <v-card-text>
            <div class="action-card-icon">
              <v-icon icon="mdi-alert-circle-outline" size="32" color="warning" />
            </div>
            <h4 class="text-subtitle-1 font-weight-medium mt-3 mb-1">
              Проблемные случаи
            </h4>
            <p class="text-body-2 text-medium-emphasis mb-3">
              Просмотр и исправление ошибок распознавания
            </p>
            <v-chip 
              v-if="stats.problemCases > 0"
              color="warning" 
              size="small"
              variant="tonal"
            >
              {{ stats.problemCases }} требуют внимания
            </v-chip>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" sm="6" md="4">
        <v-card 
          class="action-card" 
          variant="outlined"
          @click="goTo('/admin/materials?status=pending')"
        >
          <v-card-text>
            <div class="action-card-icon">
              <v-icon icon="mdi-eye-check-outline" size="32" color="info" />
            </div>
            <h4 class="text-subtitle-1 font-weight-medium mt-3 mb-1">
              Проверить материалы
            </h4>
            <p class="text-body-2 text-medium-emphasis mb-3">
              Проверка автоматически распознанных параметров
            </p>
            <v-chip 
              v-if="stats.pendingReview > 0"
              color="info" 
              size="small"
              variant="tonal"
            >
              {{ stats.pendingReview }} на проверке
            </v-chip>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" sm="6" md="4">
        <v-card 
          class="action-card" 
          variant="outlined"
          @click="openAddMaterial"
        >
          <v-card-text>
            <div class="action-card-icon">
              <v-icon icon="mdi-plus-circle-outline" size="32" color="success" />
            </div>
            <h4 class="text-subtitle-1 font-weight-medium mt-3 mb-1">
              Добавить материал
            </h4>
            <p class="text-body-2 text-medium-emphasis mb-3">
              Ручное добавление нового материала в систему
            </p>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" sm="6" md="4">
        <v-card
          class="action-card"
          variant="outlined"
          @click="goTo('/admin/ideas')"
        >
          <v-card-text>
            <div class="action-card-icon">
              <v-icon icon="mdi-lightbulb-on-outline" size="32" color="warning" />
            </div>
            <h4 class="text-subtitle-1 font-weight-medium mt-3 mb-1">
              Модерация идей
            </h4>
            <p class="text-body-2 text-medium-emphasis mb-3">
              Управление статусами, идеями и комментариями пользователей
            </p>
            <v-chip
              color="warning"
              size="small"
              variant="tonal"
            >
              {{ stats.ideasToModerate }} новых идей
            </v-chip>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- Recent Problems -->
    <div class="d-flex align-center mb-3">
      <h3 class="text-subtitle-1 font-weight-medium">Последние ошибки</h3>
      <v-spacer />
      <v-btn 
        variant="text" 
        size="small" 
        color="primary"
        @click="goTo('/admin/problems')"
      >
        Смотреть все
        <v-icon icon="mdi-arrow-right" size="16" class="ml-1" />
      </v-btn>
    </div>

    <v-card variant="outlined">
      <v-data-table
        :headers="recentProblemsHeaders"
        :items="recentProblems"
        :loading="loading"
        density="comfortable"
        :items-per-page="5"
        hide-default-footer
        class="recent-problems-table"
      >
        <template #item.raw_text="{ item }">
          <div class="text-truncate" style="max-width: 300px">
            {{ item.raw_text }}
          </div>
        </template>

        <template #item.parse_error_reason="{ item }">
          <v-chip size="small" color="warning" variant="tonal">
            {{ translateErrorReason(item.parse_error_reason) }}
          </v-chip>
        </template>

        <template #item.occurrences="{ item }">
          <span class="text-medium-emphasis">{{ item.occurrences }}×</span>
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <v-btn
              size="small"
              variant="tonal"
              color="primary"
              @click="openProblemInspector(item)"
            >
              Исправить
            </v-btn>
          </div>
        </template>

        <template #no-data>
          <div class="text-center py-6 text-medium-emphasis">
            <v-icon icon="mdi-check-circle-outline" size="48" color="success" class="mb-2" />
            <div>Нет проблемных случаев</div>
          </div>
        </template>
      </v-data-table>
    </v-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, inject, type Component } from 'vue'
import { useRouter } from 'vue-router'
import { adminMaterialDimensionsApi } from '@/api/materialDimensions'
import { ideasApi } from '@/api/ideas'

const router = useRouter()

// Inspector injection
const adminInspector = inject<{
  open: (options: { title: string; component: Component; props?: Record<string, any> }) => void
  close: () => void
}>('adminInspector')

const loading = ref(false)

const stats = ref({
  problemCases: 0,
  pendingReview: 0,
  activeRules: 0,
  totalMaterials: 0,
  ideasToModerate: 0,
})

const recentProblems = ref<any[]>([])

const recentProblemsHeaders = [
  { title: 'Исходный текст', key: 'raw_text', sortable: false },
  { title: 'Причина', key: 'parse_error_reason', sortable: false, width: 160 },
  { title: 'Повторений', key: 'occurrences', sortable: false, width: 100 },
  { title: '', key: 'actions', sortable: false, width: 120 }
]

function goTo(path: string) {
  router.push(path)
}

function openAddMaterial() {
  // Will open inspector with add material form
  console.log('Open add material')
}

function openProblemInspector(item: any) {
  router.push({ path: '/admin/problems', query: { id: item.id } })
}

function translateErrorReason(reason: string): string {
  const translations: Record<string, string> = {
    'no_match': 'Не распознано',
    'ambiguous': 'Неоднозначно',
    'invalid_format': 'Неверный формат',
    'missing_dimension': 'Нет размеров'
  }
  return translations[reason] || reason || 'Неизвестно'
}

async function loadStats() {
  try {
    // Load problem cases count
    const failuresResponse = await adminMaterialDimensionsApi.listFailures({ 
      per_page: 1, 
      status: 'unresolved' 
    })
    stats.value.problemCases = failuresResponse.meta.total

    // Load rules count
    const rulesResponse = await adminMaterialDimensionsApi.listRules({ 
      per_page: 1,
      is_active: true
    })
    stats.value.activeRules = rulesResponse.meta.total

    const ideasResponse = await ideasApi.list({
      status: 'NEW',
      per_page: 1,
    })
    stats.value.ideasToModerate = ideasResponse.meta.total
  } catch (error) {
    console.error('Failed to load stats:', error)
  }
}

async function loadRecentProblems() {
  loading.value = true
  try {
    const response = await adminMaterialDimensionsApi.listFailures({
      per_page: 5,
      status: 'unresolved'
    })
    recentProblems.value = response.data
  } catch (error) {
    console.error('Failed to load recent problems:', error)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadStats()
  loadRecentProblems()
})
</script>

<style scoped>
.admin-dashboard {
  max-width: 1400px;
}

.dashboard-header {
  margin-bottom: 24px;
}

.stat-card {
  cursor: pointer;
  transition: all 0.2s ease;
}

.stat-card:hover {
  border-color: rgb(var(--v-theme-primary));
  box-shadow: 0 2px 8px rgba(var(--v-theme-primary), 0.16);
}

.stat-icon {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.stat-icon--warning {
  background: rgba(var(--v-theme-warning), 0.14);
  color: rgb(var(--v-theme-warning));
}

.stat-icon--info {
  background: rgba(var(--v-theme-info), 0.14);
  color: rgb(var(--v-theme-info));
}

.stat-icon--success {
  background: rgba(var(--v-theme-success), 0.14);
  color: rgb(var(--v-theme-success));
}

.stat-icon--primary {
  background: rgba(var(--v-theme-primary), 0.14);
  color: rgb(var(--v-theme-primary));
}

.action-card {
  height: 100%;
  cursor: pointer;
  transition: all 0.2s ease;
}

.action-card:hover {
  border-color: rgb(var(--v-theme-primary));
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(var(--v-theme-on-surface), 0.14);
}

.action-card-icon {
  width: 56px;
  height: 56px;
  border-radius: 12px;
  background: rgba(var(--v-theme-on-surface), 0.08);
  display: flex;
  align-items: center;
  justify-content: center;
}

.recent-problems-table {
  border: none;
}

.gap-1 {
  gap: 4px;
}
</style>
