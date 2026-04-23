<template>
  <PageContainer>
    <PageHeader
      title="Рабочий стол"
      subtitle="Управление материалами и правилами распознавания"
    />

    <!-- Quick Stats -->
    <v-row class="mb-6">
      <v-col cols="12" sm="6" md="3">
        <SectionCard class="stat-card" variant="outlined" @click="goTo('/admin/problems')">
          <div class="d-flex align-center">
            <div class="stat-icon stat-icon--warning">
              <v-icon icon="mdi-alert-circle-outline" size="24" />
            </div>
            <div class="ml-4">
              <div class="text-h5 font-weight-bold">{{ stats.problemCases }}</div>
              <div class="text-body-2 text-medium-emphasis">Проблемные случаи</div>
            </div>
          </div>
        </SectionCard>
      </v-col>

      <v-col cols="12" sm="6" md="3">
        <SectionCard class="stat-card" variant="outlined" @click="goTo('/admin/materials?status=pending')">
          <div class="d-flex align-center">
            <div class="stat-icon stat-icon--info">
              <v-icon icon="mdi-clock-outline" size="24" />
            </div>
            <div class="ml-4">
              <div class="text-h5 font-weight-bold">{{ stats.pendingReview }}</div>
              <div class="text-body-2 text-medium-emphasis">Ожидают проверки</div>
            </div>
          </div>
        </SectionCard>
      </v-col>

      <v-col cols="12" sm="6" md="3">
        <SectionCard class="stat-card" variant="outlined" @click="goTo('/admin/rules')">
          <div class="d-flex align-center">
            <div class="stat-icon stat-icon--success">
              <v-icon icon="mdi-check-circle-outline" size="24" />
            </div>
            <div class="ml-4">
              <div class="text-h5 font-weight-bold">{{ stats.activeRules }}</div>
              <div class="text-body-2 text-medium-emphasis">Активных правил</div>
            </div>
          </div>
        </SectionCard>
      </v-col>

      <v-col cols="12" sm="6" md="3">
        <SectionCard class="stat-card" variant="outlined">
          <div class="d-flex align-center">
            <div class="stat-icon stat-icon--primary">
              <v-icon icon="mdi-package-variant-closed" size="24" />
            </div>
            <div class="ml-4">
              <div class="text-h5 font-weight-bold">{{ stats.totalMaterials }}</div>
              <div class="text-body-2 text-medium-emphasis">Всего материалов</div>
            </div>
          </div>
        </SectionCard>
      </v-col>
    </v-row>

    <!-- Quick Actions -->
    <div class="admin-dashboard-section mb-6">
      <h2 class="admin-dashboard-section__title">Быстрые действия</h2>
      <v-row>
        <v-col cols="12" sm="6" md="4">
          <SectionCard
            class="action-card"
            variant="outlined"
            @click="goTo('/admin/problems')"
          >
            <div class="action-card-icon">
              <v-icon icon="mdi-alert-circle-outline" size="32" color="warning" />
            </div>
            <h4 class="text-subtitle-1 font-weight-medium mt-3 mb-1">
              Проблемные случаи
            </h4>
            <p class="text-body-2 text-medium-emphasis mb-3">
              Просмотр и исправление ошибок распознавания
            </p>
            <StatusChip
              v-if="stats.problemCases > 0"
              color="warning" 
              size="small"
              variant="tonal"
            >
              {{ stats.problemCases }} требуют внимания
            </StatusChip>
          </SectionCard>
        </v-col>

        <v-col cols="12" sm="6" md="4">
          <SectionCard
            class="action-card"
            variant="outlined"
            @click="goTo('/admin/materials?status=pending')"
          >
            <div class="action-card-icon">
              <v-icon icon="mdi-eye-check-outline" size="32" color="info" />
            </div>
            <h4 class="text-subtitle-1 font-weight-medium mt-3 mb-1">
              Проверить материалы
            </h4>
            <p class="text-body-2 text-medium-emphasis mb-3">
              Проверка автоматически распознанных параметров
            </p>
            <StatusChip
              v-if="stats.pendingReview > 0"
              color="info" 
              size="small"
              variant="tonal"
            >
              {{ stats.pendingReview }} на проверке
            </StatusChip>
          </SectionCard>
        </v-col>

        <v-col cols="12" sm="6" md="4">
          <SectionCard
            class="action-card"
            variant="outlined"
            @click="openAddMaterial"
          >
            <div class="action-card-icon">
              <v-icon icon="mdi-plus-circle-outline" size="32" color="success" />
            </div>
            <h4 class="text-subtitle-1 font-weight-medium mt-3 mb-1">
              Добавить материал
            </h4>
            <p class="text-body-2 text-medium-emphasis mb-3">
              Ручное добавление нового материала в систему
            </p>
          </SectionCard>
        </v-col>

        <v-col cols="12" sm="6" md="4">
          <SectionCard
            class="action-card"
            variant="outlined"
            @click="goTo('/admin/ideas')"
          >
            <div class="action-card-icon">
              <v-icon icon="mdi-lightbulb-on-outline" size="32" color="warning" />
            </div>
            <h4 class="text-subtitle-1 font-weight-medium mt-3 mb-1">
              Модерация идей
            </h4>
            <p class="text-body-2 text-medium-emphasis mb-3">
              Управление статусами, идеями и комментариями пользователей
            </p>
            <StatusChip
              color="warning"
              size="small"
              variant="tonal"
            >
              {{ stats.ideasToModerate }} новых идей
            </StatusChip>
          </SectionCard>
        </v-col>
      </v-row>
    </div>

    <!-- Recent Problems -->
    <SectionCard title="Последние ошибки" variant="outlined" :loading="loading">
      <template #header-actions>
        <v-btn
          variant="text"
          size="small"
          color="primary"
          append-icon="mdi-arrow-right"
          @click="goTo('/admin/problems')"
        >
          Смотреть все
        </v-btn>
      </template>

      <AppDataTableShell>
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
          <StatusChip size="small" color="warning" variant="tonal">
            {{ translateErrorReason(item.parse_error_reason) }}
          </StatusChip>
        </template>

        <template #item.occurrences="{ item }">
          <span class="text-medium-emphasis">{{ item.occurrences }}×</span>
        </template>

        <template #item.actions="{ item }">
          <AppRowActions>
            <v-btn
              size="small"
              variant="tonal"
              color="primary"
              @click="openProblemInspector(item)"
            >
              Исправить
            </v-btn>
          </AppRowActions>
        </template>

        <template #no-data>
          <AppStateBlock
            icon="mdi-check-circle-outline"
            title="Нет проблемных случаев"
            description="Новые ошибки распознавания появятся здесь."
            tone="success"
            density="compact"
          />
        </template>
      </v-data-table>
      </AppDataTableShell>
    </SectionCard>
  </PageContainer>
</template>

<script setup lang="ts">
import { ref, onMounted, inject, type Component } from 'vue'
import { useRouter } from 'vue-router'
import { adminMaterialDimensionsApi } from '@/api/materialDimensions'
import { ideasApi } from '@/api/ideas'
import AppDataTableShell from '@/components/layout/AppDataTableShell.vue'
import AppRowActions from '@/components/layout/AppRowActions.vue'
import AppStateBlock from '@/components/layout/AppStateBlock.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import StatusChip from '@/components/layout/StatusChip.vue'

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
.stat-card {
  cursor: pointer;
  transition: all 0.2s ease;
}

.stat-card:hover {
  border-color: rgba(var(--v-theme-primary), 0.42);
  background: rgba(var(--v-theme-primary), 0.04);
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
  border-color: rgba(var(--v-theme-primary), 0.42);
  background: rgba(var(--v-theme-primary), 0.04);
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

.admin-dashboard-section__title {
  margin: 0 0 var(--ds-space-12);
  color: var(--ds-text-primary);
  font-size: 1rem;
  font-weight: 700;
  line-height: 1.35;
}

.recent-problems-table {
  border: none;
}
</style>
