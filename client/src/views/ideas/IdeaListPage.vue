<template>
  <PageContainer>
    <PageHeader
      title="Идеи"
      subtitle="Предложения команды, голосование и прозрачный список улучшений для Призмы."
    >
      <template #actions>
        <v-btn color="primary" variant="flat" prepend-icon="mdi-lightbulb-on-outline" @click="router.push({ name: 'ideas-create' })">
          Создать идею
        </v-btn>
      </template>
    </PageHeader>

    <div class="ideas-hero">
      <div class="ideas-hero__title">У вас есть идея?</div>
      <div class="ideas-hero__text">
        Предлагайте улучшения, голосуйте за полезные инициативы и отслеживайте, какие из них планируются к внедрению.
      </div>
    </div>

    <div class="ideas-layout">
      <aside class="ideas-sidebar">
        <SectionCard class="ideas-filter-card" subtitle="Быстрые срезы по статусам и тегам.">
          <template #title>Навигация</template>

          <div class="ideas-filter-section">
            <div class="ideas-filter-section__title">Статусы</div>
            <v-list nav density="compact" class="status-menu-list pa-0">
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
                  <span class="status-menu-item__count">{{ item.count }}</span>
                </template>
              </v-list-item>
            </v-list>
          </div>

          <div class="ideas-filter-section">
            <div class="ideas-filter-section__title">Теги</div>
            <div v-if="availableTags.length" class="ideas-tags">
              <v-chip
                v-for="tag in availableTags"
                :key="tag"
                :color="tagFilter === tag ? 'primary' : undefined"
                :variant="tagFilter === tag ? 'flat' : 'tonal'"
                @click="toggleTag(tag)"
              >
                {{ tag }}
              </v-chip>
            </div>
            <div v-else class="ideas-filter-empty">
              Теги появятся после загрузки идей.
            </div>
          </div>
        </SectionCard>
      </aside>

      <section class="ideas-content md3-content-stack">
        <SectionCard class="ideas-toolbar-card" subtitle="Поиск и сортировка предложений в общем MD3-паттерне.">
          <template #title>Лента идей</template>

          <div class="ideas-toolbar">
            <v-text-field
              class="ideas-toolbar__search"
              v-model="search"
              label="Поиск по идеям"
              prepend-inner-icon="mdi-magnify"
              variant="solo-filled"
              density="comfortable"
              clearable
              hide-details
              @keydown.enter.prevent="applyFilters"
            />
            <v-select
              class="ideas-toolbar__sort"
              v-model="sort"
              :items="sortOptions"
              label="Сортировка"
              variant="solo-filled"
              density="comfortable"
              hide-details
              @update:model-value="applyFilters"
            />
            <v-btn variant="tonal" color="primary" :loading="loading" @click="applyFilters">
              Применить
            </v-btn>
          </div>
        </SectionCard>

        <IdeaList
          :ideas="ideas"
          :loading-idea-id="loadingVoteIdeaId"
          @open="openIdea"
          @vote="onVote"
          @clear-vote="onClearVote"
        />

        <div class="ideas-pagination" v-if="meta.last_page > 1">
          <v-pagination v-model="page" :length="meta.last_page" @update:model-value="loadIdeas" />
        </div>
      </section>
    </div>
  </PageContainer>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import IdeaList from '@/components/ideas/IdeaList.vue'
import { IDEA_STATUS_LABELS, ideasApi, type IdeaItem, type IdeaSort, type IdeaStatus } from '@/api/ideas'

const router = useRouter()

const loading = ref(false)
const loadingVoteIdeaId = ref<number | null>(null)
const ideas = ref<IdeaItem[]>([])

const status = ref<IdeaStatus | ''>('')
const tagFilter = ref('')
const search = ref('')
const sort = ref<IdeaSort>('new')
const page = ref(1)

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

const meta = ref({
  current_page: 1,
  last_page: 1,
  per_page: 10,
  total: 0,
})

const statusMenuItems = computed<StatusMenuItem[]>(() => [
  { title: 'Все', value: '', count: statusCounts.value.all, icon: 'mdi-format-list-bulleted' },
  { title: IDEA_STATUS_LABELS.NEW, value: 'NEW', count: statusCounts.value.NEW, icon: 'mdi-lightbulb-outline' },
  { title: IDEA_STATUS_LABELS.PLANNED, value: 'PLANNED', count: statusCounts.value.PLANNED, icon: 'mdi-hard-hat' },
  { title: IDEA_STATUS_LABELS.REJECTED, value: 'REJECTED', count: statusCounts.value.REJECTED, icon: 'mdi-cancel' },
  { title: IDEA_STATUS_LABELS.IMPLEMENTED, value: 'IMPLEMENTED', count: statusCounts.value.IMPLEMENTED, icon: 'mdi-check-circle-outline' },
])

const sortOptions = [
  { title: 'Новые', value: 'new' },
  { title: 'Топ', value: 'top' },
  { title: 'Горячие', value: 'hot' },
]

const availableTags = computed(() => {
  const tags = new Set<string>()
  for (const idea of ideas.value) {
    for (const tag of idea.tags || []) {
      tags.add(tag.name)
    }
  }
  return Array.from(tags).sort()
})

async function loadIdeas() {
  loading.value = true
  try {
    const response = await ideasApi.list({
      status: status.value,
      tag: tagFilter.value || undefined,
      search: search.value || undefined,
      sort: sort.value,
      page: page.value,
      per_page: 10,
    })

    ideas.value = response.data
    meta.value = response.meta
  } catch (error) {
    console.error('Failed to load ideas:', error)
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
    console.error('Failed to load idea status counters:', error)
  }
}

function applyFilters() {
  page.value = 1
  loadIdeas()
}

function setStatusFilter(nextStatus: IdeaStatus | '') {
  if (status.value === nextStatus) {
    return
  }

  status.value = nextStatus
  applyFilters()
}

function openIdea(id: number) {
  router.push({ name: 'ideas-detail', params: { id } })
}

async function onVote(payload: { id: number; type: 'up' | 'down' }) {
  loadingVoteIdeaId.value = payload.id
  try {
    const updated = await ideasApi.vote(payload.id, payload.type)
    const index = ideas.value.findIndex((idea) => idea.id === payload.id)
    if (index >= 0) {
      ideas.value[index] = updated
    }
  } catch (error) {
    console.error('Failed to vote idea:', error)
  } finally {
    loadingVoteIdeaId.value = null
  }
}

async function onClearVote(id: number) {
  loadingVoteIdeaId.value = id
  try {
    const updated = await ideasApi.removeVote(id)
    const index = ideas.value.findIndex((idea) => idea.id === id)
    if (index >= 0) {
      ideas.value[index] = updated
    }
  } catch (error) {
    console.error('Failed to clear vote:', error)
  } finally {
    loadingVoteIdeaId.value = null
  }
}

function toggleTag(tag: string) {
  tagFilter.value = tagFilter.value === tag ? '' : tag
  applyFilters()
}

onMounted(() => {
  loadIdeas()
  loadStatusCounts()
})
</script>

<style scoped>
.ideas-hero {
  padding: 18px 20px;
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-18);
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary-container), 0.32), rgba(var(--v-theme-surface-container-low), 0.92));
}

.ideas-hero__title {
  font-size: 22px;
  font-weight: 800;
  line-height: 1.2;
  color: var(--ds-text-primary);
}

.ideas-hero__text {
  margin-top: 8px;
  max-width: 780px;
  color: var(--ds-text-secondary);
  line-height: 1.55;
}

.ideas-layout {
  display: grid;
  grid-template-columns: 320px minmax(0, 1fr);
  gap: 20px;
  align-items: start;
}

.ideas-sidebar,
.ideas-content {
  min-width: 0;
}

.ideas-filter-card,
.ideas-toolbar-card {
  background: color-mix(in srgb, var(--md-sys-color-surface-container-low) 94%, transparent);
}

.ideas-filter-section + .ideas-filter-section {
  margin-top: 18px;
}

.ideas-filter-section__title {
  margin-bottom: 10px;
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--ds-text-secondary);
}

.status-menu-list :deep(.v-list-item-title) {
  font-weight: 500;
}

.status-menu-list {
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-14);
  background: rgba(var(--v-theme-surface-container-lowest), 0.82);
}

.status-menu-item {
  min-height: 42px;
}

.status-menu-item__count {
  font-size: 12px;
  color: var(--ds-text-tertiary);
}

.status-menu-item :deep(.v-list-item__append) {
  margin-inline-start: 8px;
}

.ideas-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.ideas-filter-empty {
  font-size: 13px;
  color: var(--ds-text-tertiary);
}

.ideas-toolbar {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 220px auto;
  gap: 12px;
  align-items: center;
}

.ideas-pagination {
  display: flex;
  justify-content: center;
}

@media (max-width: 1024px) {
  .ideas-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 760px) {
  .ideas-hero {
    padding: 16px;
  }

  .ideas-toolbar {
    grid-template-columns: 1fr;
  }
}
</style>
