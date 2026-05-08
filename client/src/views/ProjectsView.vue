<template>
  <PageContainer>

    <!-- ── Page header ───────────────────────────────────────────────────── -->
    <PageHeader title="Проекты" :subtitle="headerSubtitle">
      <template #actions>
        <ButtonGroup>
          <v-btn
            prepend-icon="mdi-plus"
            color="primary"
            variant="flat"
            :loading="creating"
            :disabled="createProjectDisabled"
            :title="projectLimitMessage || 'Создать проект'"
            @click="createProject"
          >
            Новый проект
          </v-btn>
        </ButtonGroup>
      </template>
    </PageHeader>

    <v-alert
      v-if="projectLimitMessage"
      type="warning"
      variant="tonal"
      density="comfortable"
      class="mb-4"
    >
      {{ projectLimitMessage }}
      <template #append>
        <v-btn size="small" variant="tonal" color="primary" @click="router.push('/settings/billing')">
          Выбрать тариф
        </v-btn>
      </template>
    </v-alert>

    <!-- ── Table card ────────────────────────────────────────────────────── -->
    <SectionCard class="projects-card" subtitle="Рабочий список проектов, статусы ревизий и быстрые действия.">
      <AppDataTableShell>
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
            class="projects-status-filter"
          />
        </template>

        <v-data-table
          :headers="headers"
          :items="filteredProjects"
          :loading="loading"
          :hover="true"
          :row-props="rowProps"
          item-value="id"
          :items-per-page="25"
          class="projects-table"
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
          <StatusChip
            :status="item.latest_revision_status || 'none'"
            :label="getRevisionStatusLabel(item.latest_revision_status)"
            :color="getRevisionStatusColor(item.latest_revision_status)"
            size="x-small"
          />
        </template>

        <!-- Revision count badge ────────────────────────────────────────── -->
        <template #item.revisions_count="{ item }">
          <StatusChip
            :label="String(item.revisions_count || 0)"
            :color="item.revisions_count > 0 ? 'primary' : 'grey'"
            size="x-small"
          />
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
          <AppRowActions
            :actions="getProjectRowActions(item)"
            @action="handleProjectRowAction(item, $event)"
          />
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
                <v-btn
                  prepend-icon="mdi-plus"
                  color="primary"
                  variant="flat"
                  :loading="creating"
                  :disabled="createProjectDisabled"
                  :title="projectLimitMessage || 'Создать проект'"
                  @click="createProject"
                >
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
      </AppDataTableShell>
    </SectionCard>

    <!-- Snackbar ─────────────────────────────────────────────────────────── -->
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3000" location="bottom right">
      {{ snackbar.message }}
    </v-snackbar>

    <!-- Project summary dialog ───────────────────────────────────────────── -->
    <v-dialog v-model="summaryDialog" max-width="880" scrollable>
      <v-card class="project-summary-dialog">
        <div class="project-summary-header">
          <div class="project-summary-title-block">
            <v-card-title class="project-summary-title">Сводка проекта</v-card-title>
            <div class="project-summary-subtitle">
              <span>{{ summaryProjectLine }}</span>
              <span v-if="summaryAddressLine">{{ summaryAddressLine }}</span>
            </div>
          </div>
          <v-btn
            icon="mdi-close"
            variant="text"
            size="small"
            aria-label="Закрыть"
            @click="summaryDialog = false"
          />
        </div>

        <v-card-text class="project-summary-body">
          <div v-if="summaryLoading" class="project-summary-loading">
            <v-progress-circular indeterminate color="primary" />
            <span>Загружаем сводку проекта…</span>
          </div>

          <v-alert
            v-else-if="summaryError"
            type="warning"
            variant="tonal"
            density="comfortable"
          >
            {{ summaryError }}
          </v-alert>

          <template v-else-if="projectSummary">
            <section class="project-summary-section project-summary-identity">
              <div class="project-summary-field">
                <span>№ дела</span>
                <strong>{{ projectSummary.project.number || '—' }}</strong>
              </div>
              <div class="project-summary-field project-summary-field--wide">
                <span>Адрес</span>
                <strong>{{ projectSummary.project.address || '—' }}</strong>
              </div>
              <div class="project-summary-field">
                <span>Эксперт</span>
                <strong>{{ projectSummary.project.expert_name || '—' }}</strong>
              </div>
              <div class="project-summary-field">
                <span>Изменено</span>
                <strong>{{ formatDateOnly(projectSummary.project.updated_at) }}</strong>
              </div>
              <div class="project-summary-field">
                <span>Последняя ревизия</span>
                <strong>{{ projectSummary.latest_revision ? `Версия ${projectSummary.latest_revision.number}` : 'Нет' }}</strong>
              </div>
              <div class="project-summary-field">
                <span>Статус</span>
                <StatusChip
                  :status="projectSummary.latest_revision?.status || 'none'"
                  :label="getRevisionStatusLabel(projectSummary.latest_revision?.status)"
                  :color="getRevisionStatusColor(projectSummary.latest_revision?.status)"
                  size="x-small"
                />
              </div>
            </section>

            <section class="project-summary-section">
              <div class="project-summary-section-title">Финансы</div>
              <div class="project-summary-money-grid">
                <div class="project-summary-money-card project-summary-money-card--primary">
                  <span>Итого по смете</span>
                  <strong>{{ formatCurrency(projectSummary.totals.grand_total) }}</strong>
                </div>
                <div class="project-summary-money-card">
                  <span>Материалы</span>
                  <strong>{{ formatCurrency(projectSummary.totals.materials_cost) }}</strong>
                </div>
                <div class="project-summary-money-card">
                  <span>Операции</span>
                  <strong>{{ formatCurrency(projectSummary.totals.operations_cost) }}</strong>
                </div>
                <div class="project-summary-money-card">
                  <span>Работы</span>
                  <strong>{{ formatCurrency(projectSummary.totals.labor_works_cost) }}</strong>
                </div>
                <div class="project-summary-money-card">
                  <span>Фурнитура</span>
                  <strong>{{ formatCurrency(projectSummary.totals.fittings_cost) }}</strong>
                </div>
                <div class="project-summary-money-card">
                  <span>Расходы</span>
                  <strong>{{ formatCurrency(projectSummary.totals.expenses_cost) }}</strong>
                </div>
              </div>
            </section>

            <section class="project-summary-section">
              <div class="project-summary-section-title">Документы</div>
              <div v-if="!projectSummary.latest_revision" class="project-summary-empty">
                Документы еще не сформированы.
              </div>
              <div v-else class="project-summary-doc-grid">
                <div class="project-summary-doc-card">
                  <div class="project-summary-doc-text">
                    <strong>Смета и расчетная часть</strong>
                    <span>Версия {{ projectSummary.latest_revision.number }} · {{ formatDateOnly(projectSummary.latest_revision.created_at) }}</span>
                  </div>
                  <div class="project-summary-doc-actions">
                    <v-btn
                      size="small"
                      variant="tonal"
                      color="primary"
                      :loading="summaryPdfLoading === 'estimate-open'"
                      @click="openSummaryPdf(projectSummary.latest_revision.pdf_url, 'estimate-open')"
                    >
                      Открыть
                    </v-btn>
                    <v-btn
                      size="small"
                      variant="text"
                      :loading="summaryPdfLoading === 'estimate-download'"
                      @click="downloadSummaryPdf(projectSummary.latest_revision.pdf_url, `smeta_${projectSummary.project.number || projectSummary.project.id}_rev_${projectSummary.latest_revision.number}.pdf`, 'estimate-download')"
                    >
                      Скачать
                    </v-btn>
                  </div>
                </div>
                <div class="project-summary-doc-card">
                  <div class="project-summary-doc-text">
                    <strong>Подтверждение цен</strong>
                    <span>
                      {{ priceEvidenceReadyLabel }}
                    </span>
                  </div>
                  <div class="project-summary-doc-actions">
                    <v-btn
                      size="small"
                      variant="tonal"
                      color="primary"
                      :disabled="!projectSummary.latest_revision.price_justification_pdf_url"
                      :loading="summaryPdfLoading === 'evidence-open'"
                      @click="openSummaryPdf(projectSummary.latest_revision.price_justification_pdf_url, 'evidence-open')"
                    >
                      Открыть
                    </v-btn>
                    <v-btn
                      size="small"
                      variant="text"
                      :disabled="!projectSummary.latest_revision.price_justification_pdf_url"
                      :loading="summaryPdfLoading === 'evidence-download'"
                      @click="downloadSummaryPdf(projectSummary.latest_revision.price_justification_pdf_url, `price_justification_${projectSummary.project.number || projectSummary.project.id}_rev_${projectSummary.latest_revision.number}.pdf`, 'evidence-download')"
                    >
                      Скачать
                    </v-btn>
                  </div>
                </div>
              </div>
            </section>

            <section class="project-summary-section">
              <div class="project-summary-section-title">Доказательства цен</div>
              <div class="project-summary-evidence-row">
                <StatusChip
                  v-if="projectSummary.evidence.missing_items === 0"
                  label="Все позиции подтверждены"
                  color="success"
                  size="x-small"
                />
                <StatusChip
                  :label="`Подтверждено: ${projectSummary.evidence.confirmed_items} / ${projectSummary.evidence.total_items}`"
                  color="success"
                  size="x-small"
                />
                <StatusChip
                  :label="`Покрытие: ${formatPercent(projectSummary.evidence.coverage_pct)}`"
                  color="primary"
                  size="x-small"
                />
                <StatusChip
                  v-if="projectSummary.evidence.missing_items > 0"
                  :label="`Без подтверждения: ${projectSummary.evidence.missing_items}`"
                  color="warning"
                  size="x-small"
                />
              </div>
              <div class="project-summary-period">
                Период фиксации цен: {{ formatDateRange(projectSummary.evidence.period_from, projectSummary.evidence.period_to) }}
              </div>
              <div
                v-if="projectSummary.evidence.missing.length > 0"
                class="project-summary-missing-list"
              >
                <div
                  v-for="item in projectSummary.evidence.missing"
                  :key="`${item.name}-${item.section}-${item.reason}`"
                  class="project-summary-missing-item"
                >
                  <div>
                    <strong>{{ item.name }}</strong>
                    <span>{{ item.section }} · {{ formatCurrency(item.price) }}{{ item.unit ? `/${item.unit}` : '' }}</span>
                  </div>
                  <span>{{ item.reason || 'нет связанного подтверждения цены' }}</span>
                </div>
              </div>
            </section>
          </template>
        </v-card-text>

        <v-card-actions class="project-summary-actions">
          <v-spacer />
          <v-btn variant="text" @click="summaryDialog = false">Закрыть</v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :disabled="!summarySourceProject"
            @click="openSummaryProject"
          >
            Открыть проект
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete confirmation dialog ───────────────────────────────────────── -->
      <v-dialog v-model="deleteConfirmDialog" max-width="480">
      <v-card class="projects-confirm-dialog">
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
import { getMyBillingPreview } from '@/api/billing'
import { consumeProjectsFlashMessage } from '@/router/projectAccess'
import { useBillingCapabilitiesStore } from '@/stores/billingCapabilities'
import AppDataTableShell from '@/components/layout/AppDataTableShell.vue'
import AppRowActions, { type AppRowAction } from '@/components/layout/AppRowActions.vue'
import ButtonGroup from '@/components/layout/ButtonGroup.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import StatusChip from '@/components/layout/StatusChip.vue'

const router = useRouter()
const billingCapabilities = useBillingCapabilitiesStore()

const projects = ref<any[]>([])
const loading = ref(false)
const creating = ref(false)
const deleting = ref(false)
const navigatingId = ref<number | null>(null)
const summaryDialog = ref(false)
const summaryLoading = ref(false)
const summaryError = ref('')
const summarySourceProject = ref<any | null>(null)
const projectSummary = ref<ProjectSummary | null>(null)
const summaryPdfLoading = ref<string | null>(null)
const deleteConfirmDialog = ref(false)
const deleteConfirmText = ref('')
const deleteTarget = ref<any | null>(null)
const DELETE_CONFIRM_PHRASE = 'УДАЛИТЬ'

const searchQuery = ref('')
const statusFilter = ref<string | null>(null)

const snackbar = ref({ show: false, message: '', color: 'warning' })
const projectLimit = ref<{ used: number, limit: number | null } | null>(null)

const showNotification = (message: string, color = 'warning') => {
  snackbar.value = { show: true, message, color }
}

// ── Header subtitle with live count ────────────────────────────────────────
const headerSubtitle = computed(() => {
  if (loading.value) return undefined
  const n = filteredProjects.value.length
  return `${n} ${pluralize(n, ['проект', 'проекта', 'проектов'])}`
})

const projectLimitMessage = computed(() => {
  if (!billingCapabilities.enforcementEnabled || !projectLimit.value || projectLimit.value.limit === null) {
    return ''
  }

  const { used, limit } = projectLimit.value
  if (used < limit) return ''

  const state = used > limit ? 'превышен' : 'достигнут'
  return `Лимит проектов ${state}. На текущем тарифе доступно проектов: ${limit}. Сейчас в аккаунте: ${used}. Чтобы создать новый проект, выберите подходящий тариф.`
})

const createProjectDisabled = computed(() => creating.value || Boolean(projectLimitMessage.value))

// ── Table columns ───────────────────────────────────────────────────────────
const headers = [
  { title: '№ дела',   key: 'number',                width: '120px' },
  { title: 'Адрес',    key: 'address',               minWidth: '180px' },
  { title: 'Статус',   key: 'latest_revision_status', width: '148px' },
  { title: 'Рев.',     key: 'revisions_count',        width: '58px',  align: 'center' as const },
  { title: 'Ревизия',  key: 'latest_revision_at',     width: '108px' },
  { title: 'Изменено', key: 'updated_at',             width: '108px' },
  { title: 'Эксперт',  key: 'expert_name',           minWidth: '140px' },
  { title: '',         key: 'actions', sortable: false, width: '112px', align: 'end' as const },
]

interface ProjectSummary {
  project: {
    id: number
    public_id?: string | null
    number?: string | null
    address?: string | null
    expert_name?: string | null
    updated_at?: string | null
    status?: string | null
  }
  latest_revision: {
    id: string
    number: number
    status?: string | null
    created_at?: string | null
    author?: string | null
    pdf_url?: string | null
    price_justification_pdf_url?: string | null
  } | null
  totals: {
    grand_total: number | null
    materials_cost: number | null
    operations_cost: number | null
    fittings_cost: number | null
    labor_works_cost: number | null
    expenses_cost: number | null
  }
  evidence: {
    total_items: number
    confirmed_items: number
    missing_items: number
    coverage_pct: number | null
    period_from?: string | null
    period_to?: string | null
    missing: Array<{
      name: string
      section: string
      price: number | null
      unit?: string | null
      reason?: string | null
    }>
  }
}

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

const formatCurrency = (value?: number | string | null) => {
  const num = Number(value)
  if (!Number.isFinite(num)) return '—'
  return `${new Intl.NumberFormat('ru-RU', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(num)} ₽`
}

const formatPercent = (value?: number | string | null) => {
  const num = Number(value)
  if (!Number.isFinite(num)) return '—'
  return `${new Intl.NumberFormat('ru-RU', {
    minimumFractionDigits: Number.isInteger(num) ? 0 : 1,
    maximumFractionDigits: 1,
  }).format(num)} %`
}

const formatDateRange = (from?: string | null, to?: string | null) => {
  const left = formatDateOnly(from)
  const right = formatDateOnly(to)
  if (left === '—' && right === '—') return '—'
  if (left === right) return left
  return `${left} — ${right}`
}

const getRevisionStatusLabel = (status?: string | null) => {
  if (!status) return 'Нет'
  if (status === 'published') return 'Опубликована'
  if (status === 'locked')    return 'Зафиксирована'
  if (status === 'stale')     return 'Устарела'
  return status
}

const getRevisionStatusColor = (status?: string | null) => {
  if (status === 'published') return 'success'
  if (status === 'locked') return 'primary'
  if (status === 'stale') return 'warning'
  return 'grey'
}

const getProjectRowActions = (item: any): AppRowAction[] => [
  {
    key: 'summary',
    label: 'Сводка проекта',
    icon: 'mdi-information-outline',
    disabled: Boolean(navigatingId.value),
  },
  {
    key: 'edit',
    label: 'Изменить проект',
    icon: 'mdi-pencil',
    disabled: Boolean(navigatingId.value && navigatingId.value !== item.id),
  },
  {
    key: 'delete',
    label: 'Архивировать проект',
    icon: 'mdi-delete',
    color: 'error',
    disabled: Boolean(navigatingId.value),
  },
]

const handleProjectRowAction = (item: any, action: string) => {
  if (action === 'summary') {
    openProjectSummary(item)
    return
  }

  if (action === 'edit') {
    editProject(item)
    return
  }

  if (action === 'delete') {
    deleteProject(item)
  }
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

const fetchProjectLimitStatus = async () => {
  try {
    await billingCapabilities.load()

    if (!billingCapabilities.enforcementEnabled) {
      projectLimit.value = null
      return
    }

    const preview = await getMyBillingPreview()
    const usage = preview.usage.find((item) => item.code === 'projects.owned')
      || preview.usage.find((item) => item.code === 'projects.active')

    projectLimit.value = usage
      ? { used: Number(usage.used || 0), limit: usage.limit === null ? null : Number(usage.limit) }
      : null
  } catch {
    projectLimit.value = null
  }
}

const showQueuedNotification = () => {
  const flash = consumeProjectsFlashMessage()
  if (flash) showNotification(flash.message, flash.color)
}

const projectEditorUrl = (item: any) => `/projects/${item.public_id || item.id}/edit`

const summaryProjectLine = computed(() => {
  const project = projectSummary.value?.project || summarySourceProject.value
  return `Проект №${project?.number || '—'}`
})

const summaryAddressLine = computed(() => (
  projectSummary.value?.project?.address || summarySourceProject.value?.address || ''
))

const priceEvidenceReadyLabel = computed(() => {
  const revision = projectSummary.value?.latest_revision
  if (!revision?.price_justification_pdf_url) return 'PDF не сформирован'
  return `Версия ${revision.number} · покрытие ${formatPercent(projectSummary.value?.evidence.coverage_pct)}`
})

const isInternalApiPdfUrl = (url: string): boolean => {
  if (url.startsWith('/api/')) return true

  try {
    const parsed = new URL(url, window.location.origin)
    return parsed.origin === window.location.origin && parsed.pathname.startsWith('/api/')
  } catch {
    return false
  }
}

const toApiRequestUrl = (url: string): string => {
  if (url.startsWith('/api/')) return url
  const parsed = new URL(url, window.location.origin)
  return `${parsed.pathname}${parsed.search}`
}

const openProjectSummary = async (item: any) => {
  summarySourceProject.value = item
  projectSummary.value = null
  summaryError.value = ''
  summaryDialog.value = true
  summaryLoading.value = true

  try {
    const res = await api.get<ProjectSummary>(`/api/projects/${item.public_id || item.id}/summary`)
    projectSummary.value = res.data
  } catch (error: any) {
    console.error('Ошибка загрузки сводки проекта:', error)
    summaryError.value = error.response?.data?.message || 'Не удалось загрузить сводку проекта'
  } finally {
    summaryLoading.value = false
  }
}

const openSummaryProject = () => {
  const project = summarySourceProject.value || projectSummary.value?.project
  if (!project) return
  summaryDialog.value = false
  goToEditor(project)
}

const openSummaryPdf = async (url?: string | null, loadingKey = 'open') => {
  if (!url || summaryPdfLoading.value) return

  if (!isInternalApiPdfUrl(url)) {
    window.open(url, '_blank', 'noopener,noreferrer')
    return
  }

  summaryPdfLoading.value = loadingKey
  const pdfWindow = window.open('about:blank', '_blank')
  try {
    const res = await api.get(toApiRequestUrl(url), { responseType: 'blob' })
    const objectUrl = URL.createObjectURL(res.data)
    if (pdfWindow) {
      pdfWindow.location.href = objectUrl
    } else {
      window.open(objectUrl, '_blank', 'noopener,noreferrer')
    }
    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60_000)
  } catch (error: any) {
    pdfWindow?.close()
    console.error('Ошибка открытия PDF:', error)
    showNotification(`Не удалось открыть PDF: ${error.response?.data?.message || error.message}`, 'warning')
  } finally {
    summaryPdfLoading.value = null
  }
}

const downloadSummaryPdf = async (url?: string | null, filename = 'document.pdf', loadingKey = 'download') => {
  if (!url || summaryPdfLoading.value) return

  if (!isInternalApiPdfUrl(url)) {
    const a = document.createElement('a')
    a.href = url
    a.download = filename.replace(/[\\/:*?"<>|]/g, '_')
    a.rel = 'noopener noreferrer'
    a.click()
    return
  }

  summaryPdfLoading.value = loadingKey
  try {
    const res = await api.get(toApiRequestUrl(url), { responseType: 'blob' })
    const objectUrl = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = objectUrl
    a.download = filename.replace(/[\\/:*?"<>|]/g, '_')
    a.click()
    URL.revokeObjectURL(objectUrl)
  } catch (error: any) {
    console.error('Ошибка скачивания PDF:', error)
    showNotification(`Не удалось скачать PDF: ${error.response?.data?.message || error.message}`, 'warning')
  } finally {
    summaryPdfLoading.value = null
  }
}

// ── Actions ─────────────────────────────────────────────────────────────────
const createProject = async () => {
  if (projectLimitMessage.value) {
    showNotification(projectLimitMessage.value, 'warning')
    return
  }

  if (creating.value) return
  creating.value = true
  try {
    const response = await api.post('/api/projects', {})
    router.push(projectEditorUrl(response.data))
  } catch (e) {
    console.error('Ошибка создания проекта:', e)
    const message = (e as any)?.response?.data?.message
    showNotification(message || 'Не удалось создать проект', 'warning')
  } finally {
    creating.value = false
  }
}

const goToEditor = (item: any) => {
  if (navigatingId.value) return
  navigatingId.value = item.id
  router.push(projectEditorUrl(item))
}

const editProject = (item: any) => {
  if (navigatingId.value) return
  navigatingId.value = item.id
  router.push(projectEditorUrl(item))
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
    await Promise.all([fetchProjects(), fetchProjectLimitStatus()])
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
  await Promise.all([fetchProjects(), fetchProjectLimitStatus()])
  showQueuedNotification()
})
</script>

<style scoped>
.projects-card :deep(.v-card-text) {
  padding-top: 10px !important;
  overflow-x: auto;
}

.projects-card :deep(.ds-table-toolbar) {
  padding-bottom: 16px;
}

.projects-status-filter {
  flex: 0 1 200px;
  max-width: 200px;
}

.projects-table {
  min-width: 860px;
}

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

/* ── Delete dialog inline code ───────────────────────────────────────────── */
.projects-confirm-dialog {
  border-radius: var(--md-sys-shape-corner-extra-large);
}

.pj-confirm-code {
  font-family: ui-monospace, 'Cascadia Code', monospace;
  font-size: 0.8125rem;
  padding: 1px 5px;
  border-radius: 999px;
  background: rgba(var(--v-theme-secondary-container), 0.92);
  color: rgb(var(--v-theme-on-surface));
}

.project-summary-dialog {
  border-radius: var(--md-sys-shape-corner-extra-large);
  background: rgb(var(--v-theme-surface));
}

.project-summary-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 16px 18px 12px;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.42);
  background: rgba(var(--v-theme-surface-container-lowest), 0.94);
}

.project-summary-title {
  padding: 0 !important;
  font-size: 1.15rem;
  font-weight: 700;
}

.project-summary-subtitle {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin-top: 4px;
  color: rgba(var(--v-theme-on-surface-variant), 0.92);
  font-size: 0.8125rem;
  line-height: 1.35;
}

.project-summary-body {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 14px 18px !important;
}

.project-summary-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  min-height: 180px;
  color: rgba(var(--v-theme-on-surface-variant), 0.92);
}

.project-summary-section {
  padding: 12px;
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.38);
  border-radius: var(--md-sys-shape-corner-large);
  background: rgba(var(--v-theme-surface-container-lowest), 0.92);
}

.project-summary-section-title {
  margin-bottom: 10px;
  font-size: 0.9rem;
  font-weight: 700;
  color: rgb(var(--v-theme-on-surface));
}

.project-summary-identity {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.project-summary-field,
.project-summary-money-card {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 3px;
}

.project-summary-field :deep(.v-chip) {
  align-self: flex-start;
  width: auto;
  max-width: max-content;
}

.project-summary-field--wide {
  grid-column: span 2;
}

.project-summary-field span,
.project-summary-money-card span,
.project-summary-doc-card span,
.project-summary-period,
.project-summary-missing-item span {
  color: rgba(var(--v-theme-on-surface-variant), 0.9);
  font-size: 0.75rem;
  line-height: 1.35;
}

.project-summary-field strong,
.project-summary-money-card strong,
.project-summary-doc-card strong,
.project-summary-missing-item strong {
  min-width: 0;
  overflow-wrap: anywhere;
  color: rgb(var(--v-theme-on-surface));
  font-size: 0.875rem;
  font-weight: 700;
}

.project-summary-money-grid,
.project-summary-doc-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}

.project-summary-money-card,
.project-summary-doc-card {
  padding: 10px;
  border-radius: var(--md-sys-shape-corner-medium);
  background: rgba(var(--v-theme-surface-container-low), 0.72);
}

.project-summary-money-card--primary {
  background: rgba(var(--v-theme-primary-container), 0.72);
}

.project-summary-money-card--primary strong {
  color: rgb(var(--v-theme-on-primary-container));
  font-size: 1.05rem;
}

.project-summary-doc-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.project-summary-doc-card {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.project-summary-doc-text {
  display: flex;
  min-width: 0;
  flex: 1 1 auto;
  flex-direction: column;
  gap: 3px;
}

.project-summary-doc-actions {
  display: flex;
  flex: 0 0 auto;
  align-items: center;
  gap: 4px;
}

.project-summary-empty {
  color: rgba(var(--v-theme-on-surface-variant), 0.92);
  font-size: 0.875rem;
}

.project-summary-evidence-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 8px;
}

.project-summary-missing-list {
  display: grid;
  gap: 6px;
  margin-top: 10px;
}

.project-summary-missing-item {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 10px;
  border-radius: var(--md-sys-shape-corner-medium);
  background: rgba(var(--v-theme-warning), 0.08);
}

.project-summary-missing-item > div {
  display: flex;
  min-width: 0;
  flex-direction: column;
}

.project-summary-actions {
  padding: 10px 18px 14px !important;
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.38);
  background: rgba(var(--v-theme-surface-container-low), 0.82);
}

@media (max-width: 600px) {
  .projects-status-filter {
    flex-basis: 100%;
    max-width: none;
  }

  .project-summary-identity,
  .project-summary-money-grid,
  .project-summary-doc-grid {
    grid-template-columns: 1fr;
  }

  .project-summary-field--wide {
    grid-column: auto;
  }

  .project-summary-doc-card,
  .project-summary-missing-item {
    flex-direction: column;
  }

  .project-summary-doc-actions {
    width: 100%;
    justify-content: flex-start;
  }
}
</style>
