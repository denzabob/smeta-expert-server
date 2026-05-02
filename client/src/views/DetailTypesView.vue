<template>
  <PageContainer class="soft-page detail-types-view">
    <PageHeader
      title="Шаблоны деталей"
      subtitle="Храните типовые параметры мебельных деталей: назначение, кромки, отверстия и операции для быстрого заполнения позиции."
    >
      <template #actions>
        <ButtonGroup>
          <v-btn
            color="primary"
            prepend-icon="mdi-plus"
            variant="flat"
            @click="openCreateDialog()"
          >
            Создать шаблон
          </v-btn>

          <v-menu>
            <template #activator="{ props }">
              <v-btn variant="outlined" prepend-icon="mdi-shape-plus" v-bind="props">
                Быстрый шаблон
              </v-btn>
            </template>
            <v-list density="compact">
              <v-list-item
                v-for="tpl in quickTemplates"
                :key="tpl.name"
                @click="openCreateDialog(tpl)"
              >
                <v-list-item-title>{{ tpl.name }}</v-list-item-title>
                <v-list-item-subtitle>{{ getEdgeLabel(tpl.edge_processing) }}</v-list-item-subtitle>
              </v-list-item>
            </v-list>
          </v-menu>
        </ButtonGroup>
      </template>
    </PageHeader>

    <SectionCard class="detail-types-explain" variant="flat">
      <div class="detail-types-explain__layout">
        <div class="detail-types-explain__icon">
          <v-icon icon="mdi-sofa-outline" size="24" />
        </div>
        <div class="detail-types-explain__body">
          <div class="detail-types-explain__title">Что такое шаблон детали</div>
          <div class="detail-types-explain__text">
            Шаблон хранит типовые признаки детали: назначение, кромки, отверстия и операции.
            Это не фактическая позиция сметы, а заготовка, которая помогает быстрее заполнить позицию в проекте.
          </div>
          <div class="detail-types-explain__examples">
            <span>Полка: две длинные стороны в кромке.</span>
            <span>Дно тумбы: две длинные стороны + отверстия Ø5 мм.</span>
            <span>Фасад: обработка кромки по периметру.</span>
          </div>
        </div>
      </div>
    </SectionCard>

    <SectionCard class="soft-content-card soft-data-card detail-types-view__card" variant="outlined">
      <template #title>Библиотека шаблонов</template>
      <template #subtitle>
        Системные шаблоны можно взять за основу, а свои шаблоны настроить под привычные детали и операции.
      </template>

      <div class="detail-types-view__content md3-section-stack">
        <AppDataTableShell class="detail-types-table-shell">
          <TableToolbar>
            <template #search>
              <v-text-field
                v-model="searchQuery"
                label="Поиск по названию"
                prepend-inner-icon="mdi-magnify"
                clearable
                variant="outlined"
                density="compact"
                hide-details
                class="detail-types-view__search ds-table-toolbar__search"
              />
            </template>

            <template #filters>
              <v-select
                v-model="edgeFilter"
                :items="edgeFilterOptions"
                item-title="title"
                item-value="value"
                label="Кромки"
                variant="outlined"
                density="compact"
                hide-details
                class="detail-types-view__filter"
              />
              <v-select
                v-model="originFilter"
                :items="originFilterOptions"
                item-title="title"
                item-value="value"
                label="Тип шаблона"
                variant="outlined"
                density="compact"
                hide-details
                class="detail-types-view__filter"
              />
              <v-select
                v-model="usageFilter"
                :items="usageFilterOptions"
                item-title="title"
                item-value="value"
                label="В позициях"
                variant="outlined"
                density="compact"
                hide-details
                class="detail-types-view__filter"
              />
            </template>
          </TableToolbar>

          <v-alert
            v-if="selectedDetailTypeIds.length > 0"
            density="compact"
            variant="tonal"
            type="info"
            class="detail-types-view__bulk"
          >
            Выбрано шаблонов: {{ selectedDetailTypeIds.length }}
            <template #append>
              <div class="detail-types-view__bulk-actions">
                <v-select
                  v-model="bulkAction"
                  :items="bulkActionOptions"
                  item-title="title"
                  item-value="value"
                  label="Действие"
                  density="compact"
                  variant="outlined"
                  hide-details
                  class="detail-types-view__bulk-field"
                />
                <v-select
                  v-if="bulkAction === 'set_edge'"
                  v-model="bulkEdgeScheme"
                  :items="edgeOptions"
                  item-title="title"
                  item-value="value"
                  label="Кромки"
                  density="compact"
                  variant="outlined"
                  hide-details
                  class="detail-types-view__bulk-field detail-types-view__bulk-field--wide"
                />
                <v-btn
                  color="primary"
                  variant="flat"
                  size="small"
                  :disabled="!bulkActionReady"
                  @click="applyBulkAction"
                >
                  Применить
                </v-btn>
              </div>
            </template>
          </v-alert>

          <v-data-table
            v-model="selectedDetailTypeIds"
            :headers="headers"
            :items="filteredDetailTypes"
            :loading="loading"
            density="comfortable"
            hover
            class="soft-data-table detail-types-view__table"
            item-value="id"
            show-select
          >
            <template #item.template="{ item }">
              <div class="template-cell">
                <div class="template-cell__name">{{ item.name }}</div>
                <div class="template-cell__meta">
                  <StatusChip
                    size="x-small"
                    variant="tonal"
                    :status="item.origin === 'system' ? 'disabled' : 'active'"
                    :label="item.origin === 'system' ? 'Системный шаблон' : 'Мой шаблон'"
                  />
                </div>
              </div>
            </template>

            <template #item.edge_processing="{ item }">
              <div class="edge-cell">
                <EdgePreview :scheme="item.edge_processing" size="small" />
                <div>
                  <div class="edge-cell__label">{{ getEdgeLabel(item.edge_processing) }}</div>
                  <div class="edge-cell__summary">{{ getEdgeSummary(item.edge_processing) }}</div>
                </div>
              </div>
            </template>

            <template #item.operations="{ item }">
              <div class="operations-cell">
                <StatusChip
                  size="x-small"
                  :status="normalizeComponents(item).length > 0 ? 'active' : 'none'"
                  :label="getOperationsCountLabel(item)"
                />
                <div v-if="normalizeComponents(item).length > 0" class="operations-cell__list">
                  <span
                    v-for="summary in getOperationSummaries(item, 2)"
                    :key="summary"
                  >
                    {{ summary }}
                  </span>
                  <span v-if="normalizeComponents(item).length > 2">
                    + ещё {{ normalizeComponents(item).length - 2 }}
                  </span>
                </div>
                <div v-else class="operations-cell__empty">
                  Операции не заданы
                </div>
              </div>
            </template>

            <template #item.usage="{ item }">
              <StatusChip
                size="small"
                :color="Number(item.positions_count || 0) > 0 ? 'primary' : 'grey'"
                variant="tonal"
              >
                {{ getUsageLabel(item) }}
              </StatusChip>
            </template>

            <template #item.actions="{ item }">
              <AppRowActions
                class="detail-types-view__row-actions"
                dense
                :actions="rowActions(item)"
                @action="(action) => handleRowAction(action, item)"
              />
            </template>

            <template #no-data>
              <AppStateBlock
                :title="detailTypes.length === 0 ? 'Шаблоны деталей ещё не добавлены' : 'Шаблоны не найдены'"
                :description="detailTypes.length === 0
                  ? 'Создайте первый шаблон, например полку, боковину, фасад или дно тумбы.'
                  : 'Измените поиск или фильтры, чтобы увидеть другие шаблоны.'"
                icon="mdi-shape-outline"
                density="compact"
              >
                <template v-if="detailTypes.length === 0" #actions>
                  <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="openCreateDialog()">
                    Создать шаблон детали
                  </v-btn>
                </template>
              </AppStateBlock>
            </template>
          </v-data-table>
        </AppDataTableShell>

        <div class="detail-types-mobile-list">
          <AppStateBlock
            v-if="!loading && filteredDetailTypes.length === 0"
            :title="detailTypes.length === 0 ? 'Шаблоны деталей ещё не добавлены' : 'Шаблоны не найдены'"
            :description="detailTypes.length === 0
              ? 'Создайте первый шаблон, например полку, боковину, фасад или дно тумбы.'
              : 'Измените поиск или фильтры, чтобы увидеть другие шаблоны.'"
            icon="mdi-shape-outline"
            density="compact"
          >
            <template v-if="detailTypes.length === 0" #actions>
              <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="openCreateDialog()">
                Создать шаблон детали
              </v-btn>
            </template>
          </AppStateBlock>

          <div
            v-for="item in filteredDetailTypes"
            v-else
            :key="item.id"
            class="template-mobile-card"
          >
            <div class="template-mobile-card__header">
              <div class="template-mobile-card__title">{{ item.name }}</div>
              <StatusChip
                size="x-small"
                variant="tonal"
                :status="item.origin === 'system' ? 'disabled' : 'active'"
                :label="item.origin === 'system' ? 'Системный' : 'Мой'"
              />
            </div>

            <div class="template-mobile-card__row">
              <EdgePreview :scheme="item.edge_processing" size="small" />
              <div>
                <div class="template-mobile-card__label">Кромки</div>
                <div class="template-mobile-card__value">{{ getEdgeLabel(item.edge_processing) }}</div>
              </div>
            </div>

            <div class="template-mobile-card__row">
              <v-icon icon="mdi-wrench-outline" size="20" />
              <div>
                <div class="template-mobile-card__label">Типовые операции</div>
                <div class="template-mobile-card__value">
                  {{ getMobileOperationsLabel(item) }}
                </div>
              </div>
            </div>

            <div class="template-mobile-card__footer">
              <StatusChip size="x-small" variant="tonal">
                {{ getUsageLabel(item) }}
              </StatusChip>
              <AppRowActions
                dense
                :actions="rowActions(item)"
                @action="(action) => handleRowAction(action, item)"
              />
            </div>
          </div>
        </div>
      </div>
    </SectionCard>

    <v-dialog v-model="dialog" max-width="900" persistent>
      <v-card class="soft-content-card soft-dialog-card detail-types-view__dialog-card">
        <v-card-title class="detail-types-view__dialog-header">
          <div>
            <div class="detail-types-view__dialog-title">
              {{ editing ? 'Редактировать шаблон детали' : 'Создать шаблон детали' }}
            </div>
            <div class="detail-types-view__dialog-subtitle">
              Опишите типовую деталь. Сам шаблон не является позицией сметы, но помогает быстрее заполнить её в проекте.
            </div>
          </div>
          <StatusChip
            v-if="editing && currentTemplate"
            :status="currentTemplate.origin === 'system' ? 'disabled' : 'active'"
            :label="currentTemplate.origin === 'system' ? 'Системный шаблон' : 'Мой шаблон'"
          />
        </v-card-title>

        <v-card-text>
          <v-form ref="formRef" class="detail-template-form" @submit.prevent="save" @keydown.ctrl.enter.prevent="save">
            <AppFormSection
              title="Смысл детали"
              description="Назовите шаблон так, как сметчик привык видеть эту типовую деталь в проекте."
              compact
            >
              <v-text-field
                v-model="form.name"
                label="Название и назначение"
                placeholder="Например: Полка дна тумбы"
                :rules="[v => !!v || 'Название обязательно']"
                variant="outlined"
                density="compact"
                autofocus
              />
              <div class="detail-template-form__hint">
                Например: полка дна тумбы — две длинные стороны с кромкой, 4 отверстия Ø5 мм.
              </div>
            </AppFormSection>

            <AppFormSection
              title="Кромки по умолчанию"
              description="Кромки сохраняются как типовой признак шаблона и помогают быстрее выбрать схему обработки для позиции."
              compact
            >
              <div class="edge-choice-grid" role="radiogroup" aria-label="Кромление по умолчанию">
                <button
                  v-for="option in edgeOptions"
                  :key="option.value"
                  type="button"
                  class="edge-choice"
                  :class="{ 'edge-choice--active': form.edge_processing === option.value }"
                  :aria-pressed="form.edge_processing === option.value"
                  @click="form.edge_processing = option.value"
                >
                  <EdgePreview :scheme="option.value" />
                  <span class="edge-choice__body">
                    <span class="edge-choice__title">{{ option.title }}</span>
                    <span class="edge-choice__summary">{{ option.summary }}</span>
                  </span>
                </button>
              </div>
            </AppFormSection>

            <AppFormSection
              title="Типовые отверстия и операции"
              description="Добавьте рекомендуемые работы для этой детали: сверление, присадку, вырезы или обработку. Автоматическое применение зависит от настроек расчёта проекта."
              compact
            >
              <template #actions>
                <v-btn
                  size="small"
                  variant="outlined"
                  prepend-icon="mdi-plus"
                  @click="addComponentRow"
                >
                  Добавить операцию
                </v-btn>
              </template>

              <AppStateBlock
                v-if="!form.components?.length"
                class="detail-types-view__empty"
                icon="mdi-wrench-outline"
                title="Типовые операции не заданы"
                description="Можно оставить пустым, если для шаблона достаточно названия и кромок по умолчанию."
                density="compact"
              />

              <div v-else class="operation-rows">
                <div
                  v-for="(comp, index) in form.components"
                  :key="index"
                  class="operation-row"
                >
                  <v-autocomplete
                    v-model="comp.id"
                    :items="allOperations"
                    item-title="name"
                    item-value="id"
                    label="Операция"
                    variant="outlined"
                    density="compact"
                    hide-details="auto"
                    class="operation-row__operation"
                  >
                    <template #item="{ props, item }">
                      <v-list-item v-bind="props">
                        <v-list-item-subtitle>
                          {{ item.raw.category || 'Без категории' }} · {{ item.raw.unit || 'ед.' }}
                        </v-list-item-subtitle>
                      </v-list-item>
                    </template>
                    <template #selection="{ item }">
                      {{ formatOperationName(item.raw.name) }}
                    </template>
                  </v-autocomplete>

                  <v-text-field
                    v-model.number="comp.quantity"
                    type="number"
                    label="Количество"
                    :min="0.01"
                    :step="0.01"
                    variant="outlined"
                    density="compact"
                    hide-details="auto"
                    class="operation-row__quantity"
                  />

                  <div class="operation-row__unit">
                    {{ getOperationUnit(comp.id) || 'ед.' }}
                  </div>

                  <v-tooltip text="Удалить операцию" location="top">
                    <template #activator="{ props }">
                      <v-btn
                        v-bind="props"
                        icon="mdi-delete"
                        size="small"
                        variant="text"
                        color="error"
                        aria-label="Удалить операцию"
                        @click="removeComponent(index)"
                      />
                    </template>
                  </v-tooltip>
                </div>
              </div>
            </AppFormSection>
          </v-form>
        </v-card-text>

        <AppActionFooter class="detail-types-view__dialog-actions">
          <v-btn variant="text" @click="closeDialog">Отмена</v-btn>
          <v-btn color="primary" variant="flat" type="submit" @click="save">
            Сохранить шаблон
          </v-btn>
        </AppActionFooter>
      </v-card>
    </v-dialog>
  </PageContainer>
</template>

<script setup lang="ts">
import { ref, onMounted, nextTick, computed, defineComponent, h } from 'vue'
import type { PropType } from 'vue'
import api from '@/api/axios'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import ButtonGroup from '@/components/layout/ButtonGroup.vue'
import AppActionFooter from '@/components/layout/AppActionFooter.vue'
import AppDataTableShell from '@/components/layout/AppDataTableShell.vue'
import AppFormSection from '@/components/layout/AppFormSection.vue'
import AppRowActions, { type AppRowAction } from '@/components/layout/AppRowActions.vue'
import AppStateBlock from '@/components/layout/AppStateBlock.vue'
import StatusChip from '@/components/layout/StatusChip.vue'
import TableToolbar from '@/components/layout/TableToolbar.vue'

type EdgeValue = 'none' | 'O' | '=' | '||' | 'L' | 'П' | 'long_one' | 'short_one'
type OriginFilter = 'all' | 'system' | 'user'
type UsageFilter = 'all' | 'used' | 'unused'
type BulkAction = 'set_edge' | 'delete'

interface TemplateComponent {
  type: 'operation'
  id: number | null
  quantity: number
}

interface DetailTemplate {
  id: number
  name: string
  edge_processing: EdgeValue | string
  components?: TemplateComponent[] | string | null
  positions_count?: number
  origin?: string
  user_id?: number | null
}

interface OperationRow {
  id: number
  name: string
  category?: string
  unit?: string
}

const EdgePreview = defineComponent({
  name: 'EdgePreview',
  props: {
    scheme: {
      type: String as PropType<string | null | undefined>,
      default: 'none',
    },
    size: {
      type: String as PropType<'default' | 'small'>,
      default: 'default',
    },
  },
  setup(props) {
    const isActive = (side: 'top' | 'right' | 'bottom' | 'left') => {
      const scheme = props.scheme
      if (!scheme || scheme === 'none') return false
      if (scheme === 'O') return true
      if (scheme === '=') return side === 'top' || side === 'bottom'
      if (scheme === '||') return side === 'left' || side === 'right'
      if (scheme === 'long_one') return side === 'bottom'
      if (scheme === 'short_one') return side === 'right'
      if (scheme === 'L') return side === 'top' || side === 'left'
      if (scheme === 'П') return side === 'top' || side === 'left' || side === 'right'
      return false
    }

    return () => h('span', {
      class: ['edge-preview', `edge-preview--${props.size}`],
      'aria-hidden': 'true',
    }, [
      h('span', { class: ['edge-preview__side', 'edge-preview__side--top', { 'edge-preview__side--active': isActive('top') }] }),
      h('span', { class: ['edge-preview__side', 'edge-preview__side--right', { 'edge-preview__side--active': isActive('right') }] }),
      h('span', { class: ['edge-preview__side', 'edge-preview__side--bottom', { 'edge-preview__side--active': isActive('bottom') }] }),
      h('span', { class: ['edge-preview__side', 'edge-preview__side--left', { 'edge-preview__side--active': isActive('left') }] }),
    ])
  },
})

const dialog = ref(false)
const editing = ref(false)
const currentTemplate = ref<DetailTemplate | null>(null)

const searchQuery = ref('')
const edgeFilter = ref<'all' | string>('all')
const originFilter = ref<OriginFilter>('all')
const usageFilter = ref<UsageFilter>('all')

const loading = ref(false)
const bulkProcessing = ref(false)
const copyingId = ref<number | null>(null)

const detailTypes = ref<DetailTemplate[]>([])
const allOperations = ref<OperationRow[]>([])
const selectedDetailTypeIds = ref<number[]>([])
const bulkAction = ref<BulkAction | null>(null)
const bulkEdgeScheme = ref<EdgeValue>('none')

const formRef = ref()

const form = ref<{
  id: number | null
  name: string
  edge_processing: EdgeValue
  components: TemplateComponent[]
}>({
  id: null,
  name: '',
  edge_processing: 'none',
  components: [],
})

const edgeOptions: Array<{ value: EdgeValue; title: string; summary: string }> = [
  { value: 'none', title: 'Без кромления', summary: 'Кромка не применяется' },
  { value: '=', title: 'Две длинные стороны', summary: 'Кромка применяется к двум противоположным длинным торцам' },
  { value: '||', title: 'Две короткие стороны', summary: 'Кромка применяется к двум противоположным коротким торцам' },
  { value: 'long_one', title: 'Одна длинная сторона', summary: 'Кромка применяется к одному длинному торцу' },
  { value: 'short_one', title: 'Одна короткая сторона', summary: 'Кромка применяется к одному короткому торцу' },
  { value: 'O', title: 'По периметру', summary: 'Кромка применяется ко всем четырём сторонам детали' },
  { value: 'L', title: 'Г-образно', summary: 'Кромка применяется к двум соседним сторонам' },
  { value: 'П', title: 'П-образно', summary: 'Кромка применяется к трём сторонам детали' },
]

const edgeFilterOptions = [
  { value: 'all', title: 'Все схемы' },
  ...edgeOptions.map((opt) => ({ value: opt.value, title: opt.title })),
]

const originFilterOptions = [
  { value: 'all', title: 'Все шаблоны' },
  { value: 'system', title: 'Системные' },
  { value: 'user', title: 'Мои' },
]

const usageFilterOptions = [
  { value: 'all', title: 'Все состояния' },
  { value: 'used', title: 'Есть позиции' },
  { value: 'unused', title: 'Не используется' },
]

const quickTemplates: Array<{ name: string; edge_processing: EdgeValue }> = [
  { name: 'Полка дна тумбы', edge_processing: '=' },
  { name: 'Боковина', edge_processing: '||' },
  { name: 'Дно тумбы', edge_processing: '=' },
  { name: 'Фасадная деталь', edge_processing: 'O' },
]

const headers = [
  { title: 'Шаблон', key: 'template', minWidth: 230 },
  { title: 'Кромки', key: 'edge_processing', minWidth: 280 },
  { title: 'Типовые операции', key: 'operations', minWidth: 260 },
  { title: 'В позициях', key: 'usage', align: 'center' as const, minWidth: 150 },
  { title: 'Действия', key: 'actions', align: 'end' as const, sortable: false, width: 144 },
]

const bulkActionOptions = [
  { value: 'set_edge', title: 'Изменить кромление' },
  { value: 'delete', title: 'Удалить выбранные' },
]

const filteredDetailTypes = computed(() => {
  const term = searchQuery.value.trim().toLowerCase()
  return detailTypes.value.filter((item) => {
    const bySearch = !term || String(item.name || '').toLowerCase().includes(term)
    const byEdge = edgeFilter.value === 'all' || item.edge_processing === edgeFilter.value
    const count = Number(item.positions_count || 0)
    const byOrigin = originFilter.value === 'all'
      || (originFilter.value === 'system' && item.origin === 'system')
      || (originFilter.value === 'user' && item.origin !== 'system')
    const byUsage = usageFilter.value === 'all'
      || (usageFilter.value === 'used' && count > 0)
      || (usageFilter.value === 'unused' && count === 0)
    return bySearch && byEdge && byOrigin && byUsage
  })
})

const selectedItems = computed(() =>
  detailTypes.value.filter((item) => selectedDetailTypeIds.value.includes(item.id))
)

const selectedEditableItems = computed(() => selectedItems.value.filter((item) => isEditable(item)))

const bulkActionReady = computed(() => {
  if (bulkProcessing.value || selectedEditableItems.value.length === 0 || !bulkAction.value) return false
  if (bulkAction.value === 'set_edge') return !!bulkEdgeScheme.value
  return true
})

const fetchAll = async () => {
  loading.value = true
  try {
    const [typesRes, opsRes] = await Promise.all([
      api.get('/api/detail-types'),
      api.get('/api/operations'),
    ])
    detailTypes.value = typesRes.data
    allOperations.value = opsRes.data
  } catch (e) {
    console.error(e)
    alert('Не удалось загрузить шаблоны деталей')
  } finally {
    loading.value = false
  }
}

function normalizeComponents(item: DetailTemplate | { components?: TemplateComponent[] | string | null }): TemplateComponent[] {
  const raw = item.components
  if (!raw) return []

  if (Array.isArray(raw)) {
    return raw
      .map((comp) => ({
        type: 'operation' as const,
        id: comp.id == null ? null : Number(comp.id),
        quantity: Number(comp.quantity) || 1,
      }))
      .filter((comp) => comp.type === 'operation')
  }

  if (typeof raw === 'string') {
    try {
      const parsed = JSON.parse(raw)
      return Array.isArray(parsed) ? normalizeComponents({ components: parsed }) : []
    } catch {
      return []
    }
  }

  return []
}

const resolveId = (idOrComp: unknown) => {
  if (typeof idOrComp === 'number') return idOrComp
  if (!idOrComp || typeof idOrComp !== 'object') return null
  const comp = idOrComp as Record<string, unknown>
  return Number(comp.id ?? comp.operation_id ?? comp.op_id ?? null) || null
}

const getOperationName = (idOrComp: unknown) => {
  const id = resolveId(idOrComp)
  if (id == null) return 'Операция'
  const op = allOperations.value.find(o => o.id === id)
  return op?.name ? formatOperationName(op.name) : `Операция #${id}`
}

const getOperationUnit = (idOrComp: unknown) => {
  const id = resolveId(idOrComp)
  if (id == null) return ''
  const op = allOperations.value.find(o => o.id === id)
  return op?.unit || ''
}

function formatOperationName(name: string | null | undefined): string {
  if (!name) return 'Операция'
  return String(name).replace(/диаметром\s*(\d+(?:[,.]\d+)?)\s*мм/iu, 'Ø$1 мм')
}

const getEdgeLabel = (value: string) => edgeOptions.find(o => o.value === value)?.title || 'Пользовательская схема'
const getEdgeSummary = (value: string) => edgeOptions.find(o => o.value === value)?.summary || 'Схема кромления задана нестандартным значением'

const isEditable = (item: DetailTemplate) => item?.origin !== 'system'

function formatPositionsCount(count: number): string {
  const abs = Math.abs(count)
  const mod10 = abs % 10
  const mod100 = abs % 100
  if (mod10 === 1 && mod100 !== 11) return `${count} позиция`
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return `${count} позиции`
  return `${count} позиций`
}

function getUsageLabel(item: DetailTemplate): string {
  const count = Number(item.positions_count || 0)
  return count > 0 ? formatPositionsCount(count) : 'Не используется'
}

function getOperationsCountLabel(item: DetailTemplate): string {
  const count = normalizeComponents(item).length
  if (count === 0) return 'Нет операций'
  return `${count} ${count === 1 ? 'операция' : count < 5 ? 'операции' : 'операций'}`
}

function getOperationSummaries(item: DetailTemplate, limit: number): string[] {
  return normalizeComponents(item).slice(0, limit).map((comp) => {
    const unit = getOperationUnit(comp.id)
    return `${getOperationName(comp.id)}: ${comp.quantity}${unit ? ` ${unit}` : ''}`
  })
}

function getMobileOperationsLabel(item: DetailTemplate): string {
  const components = normalizeComponents(item)
  if (components.length === 0) return 'Операции не заданы'
  const first = getOperationSummaries(item, 1)[0]
  return components.length > 1 ? `${first}; + ещё ${components.length - 1}` : first
}

function rowActions(item: DetailTemplate): AppRowAction[] {
  return [
    {
      key: 'copy',
      label: item.origin === 'system' ? 'Скопировать как мой шаблон' : 'Копировать шаблон',
      icon: 'mdi-content-copy',
      loading: copyingId.value === item.id,
    },
    {
      key: 'edit',
      label: isEditable(item) ? 'Редактировать шаблон' : 'Системный шаблон нельзя редактировать',
      icon: 'mdi-pencil',
      disabled: !isEditable(item),
    },
    {
      key: 'delete',
      label: isEditable(item) ? 'Удалить шаблон' : 'Системный шаблон нельзя удалить',
      icon: 'mdi-delete',
      color: 'error',
      disabled: !isEditable(item),
    },
  ]
}

function handleRowAction(action: string, item: DetailTemplate) {
  if (action === 'copy') {
    copyTemplate(item)
    return
  }
  if (action === 'edit') {
    edit(item)
    return
  }
  if (action === 'delete') {
    remove(item)
  }
}

const openCreateDialog = (template?: { name: string; edge_processing: EdgeValue }) => {
  editing.value = false
  currentTemplate.value = null
  form.value = {
    id: null,
    name: template?.name || '',
    edge_processing: template?.edge_processing || 'none',
    components: [],
  }
  dialog.value = true
  nextTick(() => formRef.value?.resetValidation())
}

const edit = (item: DetailTemplate) => {
  if (!isEditable(item)) return
  editing.value = true
  currentTemplate.value = item
  form.value = {
    id: item.id,
    name: item.name,
    edge_processing: (item.edge_processing || 'none') as EdgeValue,
    components: normalizeComponents(item).map((c) => ({ ...c })),
  }
  dialog.value = true
  nextTick(() => formRef.value?.resetValidation())
}

const closeDialog = () => {
  dialog.value = false
  currentTemplate.value = null
  formRef.value?.reset()
}

const addComponentRow = () => {
  form.value.components.push({
    type: 'operation',
    id: null,
    quantity: 1,
  })
}

const removeComponent = (index: number) => {
  form.value.components.splice(index, 1)
}

const buildPayload = () => ({
  name: form.value.name,
  edge_processing: form.value.edge_processing,
  components: form.value.components.map((comp) => ({
    type: 'operation',
    id: Number(comp.id),
    quantity: Number(comp.quantity),
  })),
})

const save = async () => {
  const { valid } = await formRef.value.validate()
  if (!valid) return

  const invalidComponent = form.value.components.find((comp) => !comp.id || !(Number(comp.quantity) > 0))
  if (invalidComponent) {
    alert('Для каждой типовой операции выберите операцию и укажите количество больше нуля')
    return
  }

  try {
    const payload = buildPayload()
    if (editing.value) {
      await api.put(`/api/detail-types/${form.value.id}`, payload)
    } else {
      await api.post('/api/detail-types', payload)
    }
    dialog.value = false
    await fetchAll()
  } catch (e) {
    console.error(e)
    alert('Не удалось сохранить шаблон')
  }
}

const copyTemplate = async (item: DetailTemplate) => {
  copyingId.value = item.id
  try {
    await api.post('/api/detail-types', {
      name: `${item.name} - копия`,
      edge_processing: item.edge_processing || 'none',
      components: normalizeComponents(item).map((comp) => ({
        type: 'operation',
        id: Number(comp.id),
        quantity: Number(comp.quantity) || 1,
      })).filter((comp) => comp.id),
    })
    await fetchAll()
  } catch (e) {
    console.error(e)
    alert('Не удалось скопировать шаблон')
  } finally {
    copyingId.value = null
  }
}

const remove = async (item: DetailTemplate) => {
  if (!isEditable(item)) return
  const usage = Number(item.positions_count || 0)
  const message = usage > 0
    ? `Шаблон используется в ${formatPositionsCount(usage)}. Удалить?`
    : 'Удалить шаблон детали?'
  if (!confirm(message)) return

  try {
    await api.delete(`/api/detail-types/${item.id}`)
    await fetchAll()
  } catch (e) {
    alert('Ошибка при удалении шаблона')
  }
}

const applyBulkAction = async () => {
  if (!bulkActionReady.value) return

  const items = selectedEditableItems.value
  if (items.length === 0) return

  const confirmed = confirm(
    bulkAction.value === 'delete'
      ? `Удалить ${items.length} выбранных пользовательских шаблонов?`
      : `Изменить кромление для ${items.length} выбранных пользовательских шаблонов?`
  )
  if (!confirmed) return

  bulkProcessing.value = true
  let done = 0
  try {
    for (const item of items) {
      if (bulkAction.value === 'set_edge') {
        await api.put(`/api/detail-types/${item.id}`, {
          name: item.name,
          edge_processing: bulkEdgeScheme.value,
          components: normalizeComponents(item),
        })
      } else if (bulkAction.value === 'delete') {
        await api.delete(`/api/detail-types/${item.id}`)
      }
      done += 1
    }

    selectedDetailTypeIds.value = []
    bulkAction.value = null
    await fetchAll()
    alert(`Готово: ${done}`)
  } catch (e) {
    console.error(e)
    alert('Массовая операция завершилась с ошибкой')
    await fetchAll()
  } finally {
    bulkProcessing.value = false
  }
}

onMounted(fetchAll)
</script>

<style scoped>
.detail-types-view__content {
  gap: var(--ds-space-14);
}

.detail-types-explain {
  background:
    linear-gradient(135deg, rgba(var(--v-theme-primary), 0.10), transparent 58%),
    rgba(var(--v-theme-surface-container-low), 0.92);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.58);
}

.detail-types-explain__layout {
  display: flex;
  align-items: flex-start;
  gap: var(--ds-space-14);
}

.detail-types-explain__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: var(--md-sys-shape-corner-large);
  color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.12);
  flex: 0 0 auto;
}

.detail-types-explain__body {
  display: grid;
  gap: var(--ds-space-8);
  min-width: 0;
}

.detail-types-explain__title {
  color: var(--ds-text-primary);
  font-size: 1rem;
  font-weight: 800;
  line-height: 1.3;
}

.detail-types-explain__text {
  color: var(--ds-text-secondary);
  font-size: 0.9rem;
  line-height: 1.55;
}

.detail-types-explain__examples {
  display: flex;
  flex-wrap: wrap;
  gap: var(--ds-space-8);
}

.detail-types-explain__examples span {
  padding: 5px 10px;
  border-radius: var(--md-sys-shape-corner-full);
  color: rgb(var(--v-theme-on-secondary-container));
  background: rgba(var(--v-theme-secondary-container), 0.72);
  font-size: 0.78rem;
  font-weight: 650;
  line-height: 1.35;
}

.detail-types-view__search {
  min-width: 260px;
}

.detail-types-view__filter {
  min-width: 176px;
  max-width: 230px;
}

.detail-types-view__bulk {
  border-radius: var(--ds-radius-12);
  border: 1px solid rgba(var(--v-theme-primary), 0.16);
}

.detail-types-view__bulk :deep(.v-alert__content) {
  display: flex;
  align-items: center;
  font-weight: 700;
}

.detail-types-view__bulk-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--ds-space-8);
  flex-wrap: wrap;
}

.detail-types-view__bulk-field {
  min-width: 170px;
}

.detail-types-view__bulk-field--wide {
  min-width: 220px;
}

.detail-types-view__table {
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-12);
  overflow: hidden;
}

.detail-types-view__table :deep(.v-data-table__td),
.detail-types-view__table :deep(.v-data-table__th) {
  vertical-align: middle;
}

.detail-types-view__table :deep(tbody tr:hover) {
  background: var(--ds-surface-hover);
}

.template-cell {
  display: grid;
  gap: var(--ds-space-6);
  min-width: 0;
}

.template-cell__name {
  color: var(--ds-text-primary);
  font-weight: 800;
  line-height: 1.35;
}

.template-cell__meta {
  display: flex;
  align-items: center;
  gap: var(--ds-space-6);
  flex-wrap: wrap;
}

.edge-cell {
  display: flex;
  align-items: center;
  gap: var(--ds-space-10);
  min-width: 0;
}

.edge-cell__label {
  color: var(--ds-text-primary);
  font-weight: 750;
  line-height: 1.35;
}

.edge-cell__summary {
  color: var(--ds-text-secondary);
  font-size: 0.78rem;
  line-height: 1.35;
}

.operations-cell {
  display: grid;
  gap: var(--ds-space-6);
  justify-items: start;
  min-width: 0;
}

.operations-cell :deep(.v-chip) {
  width: max-content;
  max-width: 100%;
}

.operations-cell__list {
  display: grid;
  gap: 2px;
  color: var(--ds-text-secondary);
  font-size: 0.78rem;
  line-height: 1.35;
}

.operations-cell__empty {
  color: var(--ds-text-secondary);
  font-size: 0.78rem;
}

.detail-types-view__row-actions {
  min-width: 112px;
}

.detail-types-mobile-list {
  display: none;
}

.template-mobile-card {
  display: grid;
  gap: var(--ds-space-8);
  padding: var(--ds-space-12);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  border-radius: var(--md-sys-shape-corner-large);
  background: rgba(var(--v-theme-surface-container-low), 0.88);
}

.template-mobile-card__header,
.template-mobile-card__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--ds-space-8);
}

.template-mobile-card__footer :deep(.v-chip) {
  width: max-content;
  max-width: 100%;
}

.template-mobile-card__title {
  color: var(--ds-text-primary);
  font-weight: 800;
  line-height: 1.35;
  min-width: 0;
}

.template-mobile-card__row {
  display: flex;
  align-items: center;
  gap: var(--ds-space-10);
  min-width: 0;
  color: var(--ds-text-secondary);
}

.template-mobile-card__label {
  color: var(--ds-text-secondary);
  font-size: 0.72rem;
  font-weight: 700;
  line-height: 1.25;
  text-transform: uppercase;
}

.template-mobile-card__value {
  color: var(--ds-text-primary);
  font-size: 0.86rem;
  font-weight: 650;
  line-height: 1.35;
}

.detail-types-view__dialog-card {
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.035), transparent 160px),
    var(--ds-surface-card);
}

.detail-types-view__dialog-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--ds-space-16);
  padding: var(--ds-space-20) var(--ds-space-20) var(--ds-space-14);
  border-bottom: 1px solid var(--ds-divider);
}

.detail-types-view__dialog-title {
  color: var(--ds-text-primary);
  font-size: 20px;
  font-weight: 800;
  line-height: 1.25;
}

.detail-types-view__dialog-subtitle {
  margin-top: var(--ds-space-4);
  color: var(--ds-text-secondary);
  font-size: 13px;
  font-weight: 500;
  line-height: 1.45;
}

.detail-template-form {
  display: grid;
  gap: var(--ds-space-14);
}

.detail-template-form__hint {
  color: var(--ds-text-secondary);
  font-size: 0.82rem;
  line-height: 1.45;
}

.detail-template-form :deep(.app-form-section__actions) {
  display: flex;
  justify-content: flex-end;
}

.edge-choice-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--ds-space-10);
}

.edge-choice {
  appearance: none;
  display: flex;
  align-items: center;
  gap: var(--ds-space-10);
  width: 100%;
  min-height: 86px;
  padding: var(--ds-space-12);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.78);
  border-radius: var(--md-sys-shape-corner-large);
  background: rgba(var(--v-theme-surface-container-low), 0.72);
  color: var(--ds-text-primary);
  cursor: pointer;
  text-align: left;
  transition: background-color 0.15s ease, border-color 0.15s ease;
}

.edge-choice:hover {
  background: rgba(var(--v-theme-secondary-container), 0.46);
}

.edge-choice--active {
  border-color: rgba(var(--v-theme-primary), 0.56);
  background: rgba(var(--v-theme-primary), 0.10);
}

.edge-choice__body {
  display: grid;
  gap: 3px;
  min-width: 0;
}

.edge-choice__title {
  font-weight: 800;
  line-height: 1.3;
}

.edge-choice__summary {
  color: var(--ds-text-secondary);
  font-size: 0.78rem;
  line-height: 1.35;
}

.detail-types-view__empty {
  min-height: 126px;
  border: 1px dashed var(--ds-border-color);
  border-radius: var(--ds-radius-12);
  background: color-mix(in srgb, var(--md-sys-color-surface-container-low) 74%, transparent);
}

.operation-rows {
  display: grid;
  gap: var(--ds-space-8);
}

.operation-row {
  display: grid;
  grid-template-columns: minmax(220px, 1fr) 132px minmax(48px, auto) 40px;
  align-items: center;
  gap: var(--ds-space-8);
  padding: var(--ds-space-8);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.64);
  border-radius: var(--md-sys-shape-corner-medium);
  background: rgba(var(--v-theme-surface-container-lowest), 0.72);
}

.operation-row__unit {
  color: var(--ds-text-secondary);
  font-size: 0.82rem;
  font-weight: 700;
  white-space: nowrap;
}

.detail-types-view__dialog-actions {
  padding: var(--ds-space-14) var(--ds-space-20) var(--ds-space-20);
  border-top: 1px solid var(--ds-divider);
}

:deep(.edge-preview) {
  position: relative;
  display: inline-block;
  width: 54px;
  height: 36px;
  border: 1px solid rgba(var(--v-theme-outline), 0.42);
  border-radius: var(--md-sys-shape-corner-small);
  background: rgba(var(--v-theme-surface), 0.72);
  flex: 0 0 auto;
}

:deep(.edge-preview--small) {
  width: 44px;
  height: 30px;
}

:deep(.edge-preview__side) {
  position: absolute;
  display: block;
  background: rgba(var(--v-theme-outline), 0.34);
  border-radius: var(--md-sys-shape-corner-full);
}

:deep(.edge-preview__side--active) {
  background: rgb(var(--v-theme-primary));
}

:deep(.edge-preview__side--top),
:deep(.edge-preview__side--bottom) {
  left: 6px;
  right: 6px;
  height: 4px;
}

:deep(.edge-preview__side--top) {
  top: 4px;
}

:deep(.edge-preview__side--bottom) {
  bottom: 4px;
}

:deep(.edge-preview__side--left),
:deep(.edge-preview__side--right) {
  top: 6px;
  bottom: 6px;
  width: 4px;
}

:deep(.edge-preview__side--left) {
  left: 4px;
}

:deep(.edge-preview__side--right) {
  right: 4px;
}

@media (max-width: 960px) {
  .edge-choice-grid {
    grid-template-columns: 1fr;
  }

  .operation-row {
    grid-template-columns: minmax(0, 1fr) 120px 44px 36px;
  }
}

@media (max-width: 720px) {
  .detail-types-view :deep(.saas-page-header__actions),
  .detail-types-view :deep(.saas-button-group) {
    max-width: 100%;
    flex-wrap: wrap;
  }

  .detail-types-explain__layout,
  .detail-types-view__bulk-actions,
  .detail-types-view__dialog-header,
  .operation-row {
    align-items: stretch;
    grid-template-columns: 1fr;
    flex-direction: column;
  }

  .detail-template-form :deep(.app-form-section__header) {
    align-items: stretch;
    flex-direction: column;
  }

  .detail-template-form :deep(.app-form-section__actions .v-btn) {
    width: 100%;
  }

  .detail-types-explain__examples,
  .template-mobile-card__footer {
    align-items: flex-start;
    flex-direction: column;
  }

  .template-mobile-card__footer :deep(.app-row-actions) {
    align-self: flex-end;
  }

  .detail-types-view__search,
  .detail-types-view__filter,
  .detail-types-view__bulk-field,
  .detail-types-view__bulk-field--wide {
    width: 100%;
    max-width: none;
  }

  .detail-types-table-shell {
    display: block;
  }

  .detail-types-view__table {
    display: none;
  }

  .detail-types-mobile-list {
    display: grid;
    gap: var(--ds-space-10);
  }

  .operation-row__unit {
    min-height: 20px;
  }
}
</style>
