<template>
  <v-container>
    <div class="d-flex justify-space-between align-center mb-4">
      <div>
        <h1 class="text-h5 mb-1 ideas-title">У вас есть идея?<br />Предлагайте свои идеи по улучшению Призма</h1>
        <div class="text-body-2 text-medium-emphasis">Предложения, голосование и обсуждение</div>
      </div>
      <v-btn color="primary" @click="router.push({ name: 'ideas-create' })">
        Создать идею
      </v-btn>
    </div>

    <v-row>
      <v-col cols="12" md="3">
        <v-card class="ideas-surface">
          <v-card-text>
            <div class="text-subtitle-2 mb-2">Фильтры</div>
            <v-select
              class="idea-form-field"
              v-model="status"
              :items="statusOptions"
              label="Статус"
              variant="solo-filled"
              density="comfortable"
              @update:model-value="loadIdeas"
            />

            <div class="text-subtitle-2 mb-2">Теги</div>
            <v-chip-group column>
              <v-chip
                v-for="tag in availableTags"
                :key="tag"
                :color="tagFilter === tag ? 'primary' : undefined"
                :variant="tagFilter === tag ? 'flat' : 'tonal'"
                @click="toggleTag(tag)"
              >
                {{ tag }}
              </v-chip>
            </v-chip-group>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="9">
        <v-card class="mb-3 ideas-surface">
          <v-card-text>
            <v-row>
              <v-col cols="12" md="8">
                <v-text-field
                  class="idea-form-field"
                  v-model="search"
                  label="Поиск"
                  variant="solo-filled"
                  density="comfortable"
                  clearable
                  @keydown.enter.prevent="loadIdeas"
                />
              </v-col>
              <v-col cols="12" md="4">
                <v-select
                  class="idea-form-field"
                  v-model="sort"
                  :items="sortOptions"
                  label="Сортировка"
                  variant="solo-filled"
                  density="comfortable"
                  @update:model-value="loadIdeas"
                />
              </v-col>
            </v-row>
            <div class="filters-bar mt-2">
              <v-btn variant="tonal" @click="loadIdeas" :loading="loading">Применить</v-btn>
            </div>
          </v-card-text>
        </v-card>

        <IdeaList
          :ideas="ideas"
          :loading-idea-id="loadingVoteIdeaId"
          @open="openIdea"
          @vote="onVote"
          @clear-vote="onClearVote"
        />

        <div class="d-flex justify-center mt-4" v-if="meta.last_page > 1">
          <v-pagination v-model="page" :length="meta.last_page" @update:model-value="loadIdeas" />
        </div>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
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

const meta = ref({
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 0,
})

const statusOptions = [
  { title: 'Все статусы', value: '' },
  { title: IDEA_STATUS_LABELS.NEW, value: 'NEW' },
  { title: IDEA_STATUS_LABELS.PLANNED, value: 'PLANNED' },
  { title: IDEA_STATUS_LABELS.REJECTED, value: 'REJECTED' },
  { title: IDEA_STATUS_LABELS.IMPLEMENTED, value: 'IMPLEMENTED' },
]

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
    })

    ideas.value = response.data
    meta.value = response.meta
  } catch (error) {
    console.error('Failed to load ideas:', error)
  } finally {
    loading.value = false
  }
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
  page.value = 1
  loadIdeas()
}

onMounted(() => {
  loadIdeas()
})
</script>
