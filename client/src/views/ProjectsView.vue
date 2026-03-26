<template>
  <PageContainer>

    <!-- ── Page header ───────────────────────────────────────────────────── -->
    <PageHeader title="Проекты" :subtitle="headerSubtitle">
      <template #actions>
        <ButtonGroup>
          <v-btn prepend-icon="mdi-plus" color="primary" variant="flat" :loading="creating" @click="createProject">
            Новый проект
          </v-btn>
        </ButtonGroup>
      </template>
    </PageHeader>

    <!-- ── Table card ────────────────────────────────────────────────────── -->
    <SectionCard>
      <!-- Search + filter controls inside the card, above the table -->
      <TableToolbar>
        <template #search>
          <v-text-field
            v-model="searchQuery"
            prepend-inner-icon="mdi-magnify"
            placeholder="Поиск по номеру дела или адресу…"
            variant="outlined"
            density="compact"
            clearable
            hide-details
          />
        </template>
        <template #filters>
          <v-select
            v-model="statusFilter"
            :items="statusFilterOptions"
            item-title="label"
            item-value="value"
            variant="outlined"
            density="compact"
            hide-details
            clearable
            placeholder="Все статусы"
            style="max-width: 200px"
          />
        </template>
      </TableToolbar>

      <v-data-table
        :headers="headers"
        :items="filteredProjects"
        :loading="loading"
        :hover="true"
        :row-props="rowProps"
        item-value="id"
        :items-per-page="25"
      >
        <!-- Case number: primary anchor + navigation loading state ───────── -->
        <template #item.number="{ item }">
          <span v-if="navigatingId === item.id" class="pj-case-navigating">
            <v-progress-circular size="14" width="2" indeterminate color="primary" class="mr-1" />
            {{ item.number || '—' }}
          </span>
          <a
            v-else
            class="pj-case-link"
            href="#"
            :title="`Открыть проект ${item.number || '—'}`"
            @click.prevent="goToEditor(item)"
          >{{ item.number || '—' }}</a>
        </template>

        <!-- Address ─────────────────────────────────────────────────────── -->
        <template #item.address="{ item }">
          <span class="pj-address" :title="item.address || ''">
            {{ item.address || '—' }}
          </span>
        </template>

        <!-- Revision status badge ───────────────────────────────────────── -->
        <template #item.latest_revision_status="{ item }">
          <span class="pj-badge" :class="`pj-badge--${item.latest_revision_status || 'none'}`">
            {{ getRevisionStatusLabel(item.latest_revision_status) }}
          </span>
        </template>

        <!-- Revision count badge ────────────────────────────────────────── -->
        <template #item.revisions_count="{ item }">
          <span
            class="pj-badge pj-badge--count"
            :class="{ 'pj-badge--count-zero': !(item.revisions_count > 0) }"
          >{{ item.revisions_count || 0 }}</span>
        </template>

        <!-- Expert ──────────────────────────────────────────────────────── -->
        <template #item.expert_name="{ item }">
          <span class="pj-expert">{{ item.expert_name || '—' }}</span>
        </template>

        <!-- Dates ───────────────────────────────────────────────────────── -->
        <template #item.latest_revision_at="{ item }">
          <span class="pj-date">{{ formatDateOnly(item.latest_revision_at) }}</span>
        </template>
        <template #item.updated_at="{ item }">
          <span class="pj-date">{{ formatDateOnly(item.updated_at) }}</span>
        </template>

        <!-- Actions: horizontal, standard icons ─────────────────────────── -->
        <template #item.actions="{ item }">
          <div class="pj-actions">
            <v-btn variant="text" icon size="small" @click.stop="editProject(item)">
              <v-icon size="18">mdi-pencil</v-icon>
            </v-btn>
            <v-btn variant="text" icon size="small" color="error" @click.stop="deleteProject(item)">
              <v-icon size="18">mdi-delete</v-icon>
            </v-btn>
          </div>
        </template>

        <!-- Empty / no-results state ────────────────────────────────────── -->
        <template #no-data>
          <template v-if="!loading">
            <EmptyState
              v-if="projects.length === 0"
              icon="mdi-folder-multiple-outline"
              title="Проектов пока нет"
              description="Создайте первый проект, чтобы начать работу"
            >
              <template #actions>
                <v-btn prepend-icon="mdi-plus" color="primary" variant="flat" :loading="creating" @click="createProject">
                  Создать проект
                </v-btn>
              </template>
            </EmptyState>
            <EmptyState
              v-else
              icon="mdi-magnify-remove-outline"
              title="Ничего не найдено"
              description="Попробуйте изменить запрос или сбросить фильтры"
            >
              <template #actions>
                <v-btn variant="outlined" @click="resetFilters">Сбросить фильтры</v-btn>
              </template>
            </EmptyState>
          </template>
        </template>
      </v-data-table>
    </SectionCard>

    <!-- Snackbar ─────────────────────────────────────────────────────────── -->
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3000" location="bottom right">
      {{ snackbar.message }}
    </v-snackbar>

    <!-- Delete confirmation dialog ───────────────────────────────────────── -->
    <v-dialog v-model="deleteConfirmDialog" max-width="480">
      <v-card>
        <v-card-title>Архивирование проекта</v-card-title>
        <v-card-text>
          <p class="text-body-2 mb-4">
            В проекте
            <strong>{{ deleteTarget?.revisions_count || 0 }}&nbsp;{{ pluralize(deleteTarget?.revisions_count || 0, ['ревизия', 'ревизии', 'ревизий']) }}</strong>.
            Это действие нельзя отменить. Для подтверждения введите <code class="pj-confirm-code">УДАЛИТЬ</code>.
          </p>
          <v-text-field
            v-model="deleteConfirmText"
            label="Подтверждение"
            placeholder="УДАЛИТЬ"
            :disabled="deleting"
            autofocus
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="deleting" @click="closeDeleteConfirmDialog">Отмена</v-btn>
          <v-btn
            color="error"
            variant="flat"
            :loading="deleting"
            :disabled="deleteConfirmText !== DELETE_CONFIRM_PHRASE"
            @click="confirmDeleteWithRevisions"
          >Архивировать</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

  </PageContainer>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/axios'
import { consumeProjectsFlashMessage } from '@/router/projectAccess'
import ButtonGroup from '@/components/layout/ButtonGroup.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import TableToolbar from '@/components/layout/TableToolbar.vue'
import EmptyState from '@/components/layout/EmptyState.vue'

const router = useRouter()

const projects = ref<any[]>([])
const loading = ref(false)
const creating = ref(false)
const deleting = ref(false)
const navigatingId = ref<number | null>(null)
const deleteConfirmDialog = ref(false)
const deleteConfirmText = ref('')
const deleteTarget = ref<any | null>(null)
const DELETE_CONFIRM_PHRASE = 'УДАЛИТЬ'

const searchQuery = ref('')
const statusFilter = ref<string | null>(null)

const snackbar = ref({ show: false, message: '', color: 'warning' })

const showNotification = (message: string, color = 'warning') => {
  snackbar.value = { show: true, message, color }
}

// ── Header subtitle with live count ────────────────────────────────────────
const headerSubtitle = computed(() => {
  if (loading.value) return undefined
  const n = filteredProjects.value.length
  return `${n} ${pluralize(n, ['проект', 'проекта', 'проектов'])}`
})

// ── Table columns ───────────────────────────────────────────────────────────
const headers = [
  { title: '№ дела',   key: 'number',                width: '120px' },
  { title: 'Адрес',    key: 'address',               minWidth: '180px' },
  { title: 'Статус',   key: 'latest_revision_status', width: '148px' },
  { title: 'Рев.',     key: 'revisions_count',        width: '58px',  align: 'center' as const },
  { title: 'Ревизия',  key: 'latest_revision_at',     width: '108px' },
  { title: 'Изменено', key: 'updated_at',             width: '108px' },
  { title: 'Эксперт',  key: 'expert_name',           minWidth: '140px' },
  { title: '',         key: 'actions', sortable: false, width: '76px', align: 'end' as const },
]

// ── Status filter options ───────────────────────────────────────────────────
const statusFilterOptions = [
  { label: 'Опубликована',  value: 'published' },
  { label: 'Зафиксирована', value: 'locked' },
  { label: 'Устарела',      value: 'stale' },
  { label: 'Нет ревизий',   value: '__none__' },
]

// ── Client-side filtering ───────────────────────────────────────────────────
const filteredProjects = computed(() => {
  let list = projects.value

  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase()
    list = list.filter(
      p => (p.number || '').toLowerCase().includes(q) || (p.address || '').toLowerCase().includes(q),
    )
  }

  if (statusFilter.value != null) {
    if (statusFilter.value === '__none__') {
      list = list.filter(p => !p.latest_revision_status)
    } else {
      list = list.filter(p => p.latest_revision_status === statusFilter.value)
    }
  }

  return list
})

const resetFilters = () => {
  searchQuery.value = ''
  statusFilter.value = null
}

// ── Utilities ───────────────────────────────────────────────────────────────
const pluralize = (n: number, forms: [string, string, string]) => {
  const abs = Math.abs(n) % 100
  const n1 = abs % 10
  if (abs > 10 && abs < 20) return forms[2]
  if (n1 > 1 && n1 < 5) return forms[1]
  if (n1 === 1) return forms[0]
  return forms[2]
}

const formatDateOnly = (value?: string | null) => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  const d = String(date.getDate()).padStart(2, '0')
  const m = String(date.getMonth() + 1).padStart(2, '0')
  return `${d}.${m}.${date.getFullYear()}`
}

const getRevisionStatusLabel = (status?: string | null) => {
  if (!status) return 'Нет'
  if (status === 'published') return 'Опубликована'
  if (status === 'locked')    return 'Зафиксирована'
  if (status === 'stale')     return 'Устарела'
  return status
}

const rowProps = ({ item }: { item: any }) => ({
  onClick: () => goToEditor(item),
  style: { cursor: navigatingId.value ? 'wait' : 'pointer' },
})

// ── Data fetching ───────────────────────────────────────────────────────────
const fetchProjects = async () => {
  loading.value = true
  try {
    projects.value = (await api.get('/api/projects')).data
  } catch (e) {
    console.error('Ошибка загрузки проектов:', e)
  } finally {
    loading.value = false
  }
}

const showQueuedNotification = () => {
  const flash = consumeProjectsFlashMessage()
  if (flash) showNotification(flash.message, flash.color)
}

// ── Actions ─────────────────────────────────────────────────────────────────
const createProject = async () => {
  if (creating.value) return
  creating.value = true
  try {
    const response = await api.post('/api/projects', {})
    router.push(`/projects/${response.data.id}/edit`)
  } catch (e) {
    console.error('Ошибка создания проекта:', e)
    alert('Не удалось создать проект')
  } finally {
    creating.value = false
  }
}

const goToEditor = (item: any) => {
  if (navigatingId.value) return
  navigatingId.value = item.id
  router.push(`/projects/${item.id}/edit`)
}

const editProject = (item: any) => {
  if (navigatingId.value) return
  navigatingId.value = item.id
  router.push(`/projects/${item.id}/edit`)
}

const deleteProject = async (item: any) => {
  if (Number(item?.revisions_count || 0) > 0) {
    deleteTarget.value = item
    deleteConfirmText.value = ''
    deleteConfirmDialog.value = true
    return
  }
  await deleteProjectRequest(item)
}

const deleteProjectRequest = async (item: any, confirmDelete?: string) => {
  if (deleting.value) return
  deleting.value = true
  try {
    await api.delete(`/api/projects/${item.id}`, {
      data: confirmDelete ? { confirm_delete: confirmDelete } : undefined,
    })
    await fetchProjects()
  } catch (e) {
    console.error('Ошибка удаления:', e)
    alert('Не удалось архивировать проект')
  } finally {
    deleting.value = false
  }
}

const closeDeleteConfirmDialog = () => {
  if (deleting.value) return
  deleteConfirmDialog.value = false
  deleteConfirmText.value = ''
  deleteTarget.value = null
}

const confirmDeleteWithRevisions = async () => {
  if (!deleteTarget.value || deleteConfirmText.value !== DELETE_CONFIRM_PHRASE) return
  await deleteProjectRequest(deleteTarget.value, DELETE_CONFIRM_PHRASE)
  closeDeleteConfirmDialog()
}

onMounted(async () => {
  await fetchProjects()
  showQueuedNotification()
})
</script>

<style scoped>
/* ── Case number: primary anchor ─────────────────────────────────────────── */
.pj-case-link {
  font-weight: 700;
  color: rgb(var(--v-theme-primary));
  text-decoration: none;
  white-space: nowrap;
  border-bottom: 1.5px solid transparent;
  padding-bottom: 1px;
  transition: color 0.12s, border-color 0.12s;
}

.pj-case-link:hover {
  border-bottom-color: rgb(var(--v-theme-primary));
}

.pj-case-link:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
  border-radius: 2px;
}

/* Case number while navigating */
.pj-case-navigating {
  display: inline-flex;
  align-items: center;
  font-weight: 700;
  color: rgb(var(--v-theme-on-surface-variant));
  white-space: nowrap;
}

/* ── Address cell ────────────────────────────────────────────────────────── */
.pj-address {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  font-weight: 500;
  line-height: 1.45;
  max-width: 340px;
}

/* ── Expert cell ─────────────────────────────────────────────────────────── */
.pj-expert {
  display: block;
  color: rgb(var(--v-theme-on-surface-variant));
  font-size: 0.8125rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 180px;
}

/* ── Date cells ──────────────────────────────────────────────────────────── */
.pj-date {
  color: rgb(var(--v-theme-on-surface-variant));
  font-size: 0.8125rem;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

/* ── Unified badge system ────────────────────────────────────────────────── */
.pj-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 22px;
  padding: 0 8px;
  border-radius: 6px;
  font-size: 0.6875rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  white-space: nowrap;
}

.pj-badge--published {
  background: rgba(var(--v-theme-success), 0.14);
  color: rgb(var(--v-theme-success));
}

.pj-badge--locked {
  background: rgba(var(--v-theme-primary), 0.14);
  color: rgb(var(--v-theme-primary));
}

.pj-badge--stale {
  background: rgba(var(--v-theme-warning), 0.16);
  color: rgb(var(--v-theme-warning));
}

.pj-badge--none {
  background: rgba(var(--v-theme-on-surface-variant), 0.09);
  color: rgb(var(--v-theme-on-surface-variant));
}

.pj-badge--count {
  min-width: 30px;
  background: rgba(var(--v-theme-primary), 0.13);
  color: rgb(var(--v-theme-primary));
  font-weight: 700;
  font-size: 0.75rem;
  font-variant-numeric: tabular-nums;
}

.pj-badge--count-zero {
  background: rgba(var(--v-theme-on-surface-variant), 0.09);
  color: rgb(var(--v-theme-on-surface-variant));
}

/* ── Actions cell: horizontal ────────────────────────────────────────────── */
.pj-actions {
  display: flex;
  align-items: center;
  gap: 0;
  justify-content: flex-end;
}

/* ── Delete dialog inline code ───────────────────────────────────────────── */
.pj-confirm-code {
  font-family: ui-monospace, 'Cascadia Code', monospace;
  font-size: 0.8125rem;
  padding: 1px 5px;
  border-radius: 4px;
  background: rgba(var(--v-theme-on-surface-variant), 0.1);
  color: rgb(var(--v-theme-on-surface));
}
</style>
