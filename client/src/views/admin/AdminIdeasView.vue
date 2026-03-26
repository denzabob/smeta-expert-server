<template>
  <PageContainer>
    <PageHeader
      title="Модерация идей"
      subtitle="Управление статусами, идеями и комментариями пользователей"
    />

    <v-row>
      <v-col cols="12" md="3" lg="2">
        <v-card variant="outlined" class="status-menu-card">
          <v-card-title class="text-subtitle-1">Статусы</v-card-title>
          <v-card-text>
            <v-list nav variant="outlined" density="compact" class="status-menu-list pa-0">
              <v-list-item
                v-for="item in statusMenuItems"
                :key="item.value || 'all'"
                :active="status === item.value"
                class="status-menu-item"
                @click="setStatusFilter(item.value)"
              >
                <template #prepend>
                  <v-icon :icon="item.icon" size="18" />
                </template>

                <v-list-item-title>{{ item.title }}</v-list-item-title>

                <template #append>
                  <span class="text-caption text-medium-emphasis">{{ item.count }}</span>
                </template>
              </v-list-item>
            </v-list>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="9" lg="10">
        <v-card variant="outlined" class="mb-4">
          <v-card-text>
            <v-row align="center" dense>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="search"
                  prepend-inner-icon="mdi-magnify"
                  label="Поиск по идеям"
                  variant="outlined"
                  density="compact"
                  hide-details
                  clearable
                  @keydown.enter.prevent="handleFiltersChange"
                />
              </v-col>
              <v-col cols="12" sm="6" md="3">
                <v-select
                  v-model="sort"
                  :items="sortOptions"
                  label="Сортировка"
                  variant="outlined"
                  density="compact"
                  hide-details
                  @update:model-value="handleFiltersChange"
                />
              </v-col>
              <v-col cols="12" sm="6" md="3" class="d-flex justify-end">
                <v-btn variant="tonal" prepend-icon="mdi-refresh" color="primary" @click="loadIdeas">
                  Обновить
                </v-btn>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>

        <v-card variant="outlined" :loading="loading">
          <v-data-table-server
            v-model:items-per-page="perPage"
            :headers="headers"
            :items="ideas"
            :items-length="total"
            :loading="loading"
            :page="page"
            density="comfortable"
            item-value="id"
            @update:page="page = $event; loadIdeas()"
            @update:items-per-page="perPage = $event; loadIdeas()"
          >
            <template #item.title="{ item }">
              <div>
                <div class="font-weight-medium">{{ item.title }}</div>
                <div class="text-caption text-medium-emphasis">ID: {{ item.id }}</div>
              </div>
            </template>

            <template #item.description="{ item }">
              <div class="description-cell">
                <div class="text-body-2 text-truncate description-preview" :title="item.description">
                  {{ item.description }}
                </div>
                <v-btn size="x-small" variant="text" color="primary" @click.stop="openDescriptionDialog(item)">
                  Читать
                </v-btn>
              </div>
            </template>

            <template #item.author_nickname="{ item }">
              {{ item.author_nickname || 'Пользователь' }}
            </template>

            <template #item.votes="{ item }">
              <div class="d-flex align-center ga-2">
                <v-chip size="x-small" color="success" variant="tonal">+{{ item.votes_up }}</v-chip>
                <v-chip size="x-small" color="error" variant="tonal">-{{ item.votes_down }}</v-chip>
                <span class="text-caption text-medium-emphasis">score: {{ item.score }}</span>
              </div>
            </template>

            <template #item.status="{ item }">
              <v-chip size="small" variant="tonal">
                {{ formatIdeaStatus(item.status) }}
              </v-chip>
            </template>

            <template #item.created_at="{ item }">
              <span class="text-caption text-medium-emphasis">{{ formatDate(item.created_at) }}</span>
            </template>

            <template #item.actions="{ item }">
              <AdminIdeaControls
                :idea="item"
                @updated="onIdeaUpdated"
                @deleted="onIdeaDeleted"
              />
            </template>

            <template #no-data>
              <div class="text-center py-8 text-medium-emphasis">
                <v-icon icon="mdi-lightbulb-outline" size="48" class="mb-3" />
                <div class="text-body-1 mb-1">Идеи не найдены</div>
                <div class="text-body-2">Попробуйте изменить фильтры</div>
              </div>
            </template>
          </v-data-table-server>
        </v-card>
      </v-col>
    </v-row>

    <v-dialog v-model="descriptionDialog" max-width="820">
      <v-card>
        <v-card-title class="d-flex align-center">
          {{ selectedIdea?.title || 'Описание идеи' }}
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" @click="descriptionDialog = false" />
        </v-card-title>
        <v-divider />
        <v-card-text>
          <div class="text-caption text-medium-emphasis mb-3" v-if="selectedIdea">
            Автор: {{ selectedIdea.author_nickname || 'Пользователь' }} | Статус: {{ formatIdeaStatus(selectedIdea.status) }}
          </div>
          <div class="description-full">{{ selectedIdea?.description }}</div>
        </v-card-text>
      </v-card>
    </v-dialog>
  </PageContainer>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AdminIdeaControls from '@/components/admin/AdminIdeaControls.vue'
import { IDEA_STATUS_LABELS, formatIdeaStatus, ideasApi, type IdeaItem, type IdeaSort, type IdeaStatus } from '@/api/ideas'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'

const loading = ref(false)
const ideas = ref<IdeaItem[]>([])
const page = ref(1)
const perPage = ref(20)
const total = ref(0)

const search = ref('')
const status = ref<IdeaStatus | ''>('')
const sort = ref<IdeaSort>('new')
const descriptionDialog = ref(false)
const selectedIdea = ref<IdeaItem | null>(null)

type StatusMenuItem = {
  title: string
  value: IdeaStatus | ''
  count: number
  icon: string
}

const statusCounts = ref<Record<'all' | IdeaStatus, number>>({
  all: 0,
  NEW: 0,
  PLANNED: 0,
  REJECTED: 0,
  IMPLEMENTED: 0,
})

const headers = [
  { title: 'Идея', key: 'title', sortable: false, minWidth: 260 },
  { title: 'Описание', key: 'description', sortable: false, minWidth: 260 },
  { title: 'Автор', key: 'author_nickname', sortable: false, width: 180 },
  { title: 'Голоса', key: 'votes', sortable: false, width: 200 },
  { title: 'Статус', key: 'status', sortable: false, width: 160 },
  { title: 'Создано', key: 'created_at', sortable: false, width: 170 },
  { title: 'Действия', key: 'actions', sortable: false, minWidth: 380, align: 'end' as const },
]

const statusMenuItems = computed<StatusMenuItem[]>(() => [
  { title: 'Все', value: '', count: statusCounts.value.all, icon: 'mdi-format-list-bulleted' },
  { title: IDEA_STATUS_LABELS.NEW, value: 'NEW' as IdeaStatus, count: statusCounts.value.NEW, icon: 'mdi-lightbulb-outline' },
  { title: IDEA_STATUS_LABELS.PLANNED, value: 'PLANNED' as IdeaStatus, count: statusCounts.value.PLANNED, icon: 'mdi-hard-hat' },
  { title: IDEA_STATUS_LABELS.REJECTED, value: 'REJECTED' as IdeaStatus, count: statusCounts.value.REJECTED, icon: 'mdi-cancel' },
  { title: IDEA_STATUS_LABELS.IMPLEMENTED, value: 'IMPLEMENTED' as IdeaStatus, count: statusCounts.value.IMPLEMENTED, icon: 'mdi-check-circle-outline' },
])

const sortOptions = [
  { title: 'Новые', value: 'new' },
  { title: 'Топ', value: 'top' },
  { title: 'Горячие', value: 'hot' },
]

async function loadIdeas() {
  loading.value = true
  try {
    const response = await ideasApi.list({
      status: status.value,
      search: search.value || undefined,
      sort: sort.value,
      page: page.value,
      per_page: perPage.value,
    })

    ideas.value = response.data
    total.value = response.meta.total
    page.value = response.meta.current_page
  } catch (error) {
    console.error('Failed to load admin ideas:', error)
  } finally {
    loading.value = false
  }
}

async function loadStatusCounts() {
  try {
    const [all, newIdeas, plannedIdeas, rejectedIdeas, implementedIdeas] = await Promise.all([
      ideasApi.list({ per_page: 1 }),
      ideasApi.list({ status: 'NEW', per_page: 1 }),
      ideasApi.list({ status: 'PLANNED', per_page: 1 }),
      ideasApi.list({ status: 'REJECTED', per_page: 1 }),
      ideasApi.list({ status: 'IMPLEMENTED', per_page: 1 }),
    ])

    statusCounts.value = {
      all: all.meta.total,
      NEW: newIdeas.meta.total,
      PLANNED: plannedIdeas.meta.total,
      REJECTED: rejectedIdeas.meta.total,
      IMPLEMENTED: implementedIdeas.meta.total,
    }
  } catch (error) {
    console.error('Failed to load status counters:', error)
  }
}

function handleFiltersChange() {
  page.value = 1
  loadIdeas()
}

function setStatusFilter(nextStatus: IdeaStatus | '') {
  if (status.value === nextStatus) return
  status.value = nextStatus
  handleFiltersChange()
}

function openDescriptionDialog(idea: IdeaItem) {
  selectedIdea.value = idea
  descriptionDialog.value = true
}

function onIdeaUpdated(updated: IdeaItem) {
  const index = ideas.value.findIndex((idea) => idea.id === updated.id)
  if (index >= 0) {
    ideas.value[index] = {
      ...ideas.value[index],
      ...updated,
    }
  }

  loadStatusCounts()
}

function onIdeaDeleted(id: number) {
  ideas.value = ideas.value.filter((idea) => idea.id !== id)
  total.value = Math.max(0, total.value - 1)

  if (ideas.value.length === 0 && page.value > 1) {
    page.value -= 1
  }

  loadIdeas()
}

function formatDate(value: string): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return value
  }

  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

onMounted(() => {
  loadIdeas()
  loadStatusCounts()
})
</script>

<style scoped>
.status-menu-card {
  position: sticky;
  top: 84px;
}

.status-menu-list :deep(.v-list-item-title) {
  font-weight: 500;
}

.status-menu-item :deep(.v-list-item__append) {
  margin-inline-start: 8px;
}

.description-cell {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.description-preview {
  max-width: 100%;
}

.description-full {
  white-space: pre-line;
}
</style>
