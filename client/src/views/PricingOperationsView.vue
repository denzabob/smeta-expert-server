<template>
  <PageContainer>
    <PageHeader
      title="Цены операций"
      subtitle="Здесь вы задаёте цены операций и их обоснования"
    >
      <template #actions>
        <v-btn
          color="primary"
          variant="flat"
          prepend-icon="mdi-plus"
          @click="openCreateOperationDialog"
        >
Добавить операцию
        </v-btn>
        <v-btn
          variant="text"
          prepend-icon="mdi-arrow-left"
          :to="{ name: 'pricing' }"
        >
          К разделам
        </v-btn>
      </template>
    </PageHeader>

    <div class="preview-project-bar">
      <div class="preview-project-bar__copy">
        <div class="preview-project-bar__label">Проверить на проекте</div>
        <div class="preview-project-bar__hint">
              Используется только для примера количества и суммы в открытой операции. На расчёт не влияет.
              <div class="mt-2">
                <v-btn
                  size="small"
                  variant="text"
                  color="primary"
                  prepend-icon="mdi-file-import-outline"
                  to="/price-imports"
                >
                  Импорт цен
                </v-btn>
              </div>
        </div>
      </div>
      <v-select
        v-model="selectedProjectId"
        :items="previewProjects"
        item-title="name"
        item-value="id"
        placeholder="Выберите проект"
        variant="outlined"
        density="compact"
        hide-details
        clearable
        :loading="previewProjectsLoading"
        class="preview-project-bar__select"
      />
    </div>

    <SectionCard>
      <template #title>Операции</template>

      <div v-if="hasGlobalSummaryError" class="summary-error-banner">
        Часть цен временно недоступна. Источники можно открыть в карточке операции.
      </div>

      <div v-if="!loading && operations.length === 0" class="operations-empty-state">
        <v-icon size="40" color="grey-lighten-1">mdi-format-list-bulleted-square</v-icon>
        <div class="operations-empty-state__title">У вас пока нет операций</div>
        <div class="operations-empty-state__text">
          Добавьте первую операцию, чтобы начать расчёт.
        </div>
        <v-btn color="primary" variant="flat" prepend-icon="mdi-plus" @click="openCreateOperationDialog">
          Добавить операцию
        </v-btn>
      </div>

      <v-data-table
        v-else
        :headers="headers"
        :items="operations"
        :loading="loading"
        density="compact"
        :search="search"
        class="pricing-operations-table"
      >
        <template #top>
          <div class="table-toolbar">
            <v-text-field
              v-model="search"
              prepend-inner-icon="mdi-magnify"
              placeholder="Поиск по названию..."
              density="compact"
              variant="outlined"
              hide-details
              clearable
              style="max-width: 320px"
            />
          </div>
        </template>

        <template v-slot:[`item.origin`]="{ item }">
          <v-chip
            size="x-small"
            :color="modeColor(item)"
            variant="tonal"
          >
            {{ modeLabel(item) }}
          </v-chip>
        </template>

        <template v-slot:[`item.name`]="{ item }">
          <div class="operation-name-cell">
            <div class="operation-name-cell__title">{{ item.name }}</div>
            <div v-if="tableOperationIssueLabel(item)" class="operation-name-cell__issue">
              ⚠ {{ tableOperationIssueLabel(item) }}
            </div>
          </div>
        </template>

        <template v-slot:[`item.operation_kind`]="{ item }">
          <v-chip
            size="x-small"
            :color="operationKindColor(item.operation_kind)"
            variant="tonal"
          >
            {{ operationKindLabel(item.operation_kind) }}
          </v-chip>
        </template>

        <template v-slot:[`item.price`]="{ item }">
          <v-progress-circular
            v-if="tableSummaryLoading(item)"
            indeterminate
            size="16"
            width="2"
            color="primary"
          />
          <span v-else-if="tableSummaryError(item)" class="no-price-hint">Недоступно</span>
          <span v-else-if="tableCurrentPrice(item) !== null">
            {{ formatPrice(tableCurrentPrice(item)) }}
          </span>
          <span v-else-if="tableSummaryLoaded(item)" class="no-price-hint">Нет цены</span>
          <span v-else class="no-price-hint">—</span>
        </template>

        <template v-slot:[`item.linked_prices_count`]="{ item }">
          <v-progress-circular
            v-if="tableSummaryLoading(item)"
            indeterminate
            size="16"
            width="2"
            color="primary"
          />
          <v-chip
            v-else-if="tableSummaryLoaded(item) && tableSourcesCount(item) > 0"
            size="x-small"
            color="success"
            variant="tonal"
          >
            {{ tableSourcesCount(item) }}
          </v-chip>
          <span v-else-if="tableSummaryLoaded(item)" class="no-price-hint">—</span>
          <span v-else class="no-price-hint">—</span>
        </template>

        <template v-slot:[`item.actions`]="{ item }">
          <div class="operation-actions">
            <v-btn
              size="small"
              variant="tonal"
              color="primary"
              @click="openDrawer(item)"
            >
              Открыть
            </v-btn>
            <v-btn
              icon
              size="small"
              variant="text"
              color="primary"
              @click="openEditOperationDialog(item)"
            >
              <v-icon size="18">mdi-pencil</v-icon>
            </v-btn>
            <v-btn
              icon
              size="small"
              variant="text"
              color="error"
              @click="openDeleteDialog(item)"
            >
              <v-icon size="18">mdi-delete</v-icon>
            </v-btn>
          </div>
        </template>
      </v-data-table>
    </SectionCard>

    <!-- ───── Operation Pricing Drawer ───── -->
    <v-navigation-drawer
      v-model="drawer.open"
      location="right"
      :width="440"
      temporary
    >
      <template v-if="drawer.operation">
        <!-- Header -->
        <div class="drawer-header">
          <div class="drawer-header__main">
            <div class="drawer-title">{{ drawer.operation.name }}</div>
            <div class="drawer-subtitle">
              {{ operationKindLabel(drawer.operation.operation_kind) }} · {{ allSources.length }} источ.
            </div>
          </div>
          <div class="drawer-header__actions">
            <v-btn
              size="small"
              variant="text"
              color="primary"
              prepend-icon="mdi-pencil"
              @click="openEditOperationDialog(drawer.operation)"
            >
              Изменить
            </v-btn>
            <v-btn
              size="small"
              variant="text"
              color="error"
              prepend-icon="mdi-delete"
              @click="openDeleteDialog(drawer.operation)"
            >
{{ drawer.operation && isSystemOperation(drawer.operation) ? 'Системная операция не удаляется' : 'Удалить' }}
            </v-btn>
            <v-btn icon size="small" variant="text" @click="drawer.open = false">
              <v-icon>mdi-close</v-icon>
            </v-btn>
          </div>
        </div>

        <v-divider />

        <div class="drawer-body">
          <!-- Meta row -->
          <div class="meta-row">
            <div class="meta-cell">
              <div class="meta-label">Ед. изм.</div>
              <div class="meta-value">{{ drawer.operation.unit || '—' }}</div>
            </div>
            <div class="meta-cell">
              <div class="meta-label">Вид</div>
              <div class="meta-value">
                <v-chip
                  size="x-small"
                  :color="operationKindColor(drawer.operation.operation_kind)"
                  variant="tonal"
                >
                  {{ operationKindLabel(drawer.operation.operation_kind) }}
                </v-chip>
              </div>
            </div>
            <div class="meta-cell">
                <div class="meta-label">Режим</div>
                <div class="meta-value">
                <v-chip size="x-small" :color="modeColor(drawer.operation)" variant="tonal">
                  {{ modeLabel(drawer.operation) }}
                </v-chip>
              </div>
            </div>
            <div class="meta-cell">
              <div class="meta-label">Источников</div>
              <div class="meta-value">{{ allSources.length }}</div>
            </div>
            <div class="meta-cell">
              <div class="meta-label">В списке</div>
              <div class="meta-value">{{ drawerEvidenceCountLabel }}</div>
            </div>
          </div>

          <div class="section-kicker">Как считается</div>
          <div class="calculation-block">
            <div class="calculation-block__formula">
              <div class="calculation-block__formula-main">
                <span class="calculation-block__scenario">{{ unifiedScenarioLabel }}</span>
                <span class="calculation-block__operator">×</span>
                <template v-if="drawerSummaryLoading">
                  <span class="calculation-block__muted">Загрузка цены...</span>
                </template>
                <template v-else-if="drawerSummaryError">
                  <span class="calculation-block__muted">расчёт цены недоступен</span>
                </template>
                <template v-else-if="drawerCurrentPrice !== null && unifiedEffectiveUnitLabel">
                  <span class="calculation-block__price">
                    {{ formatPrice(drawerCurrentPrice) }} ₽ / {{ unifiedEffectiveUnitLabel }}
                  </span>
                  <span
                    v-if="unifiedSourceInlineLabel"
                    class="calculation-block__source-inline"
                  >
                    ({{ unifiedSourceInlineLabel }})
                  </span>
                </template>
              </div>
            <div class="calculation-block__formula-note">
              {{ unifiedAutomationLabel }}
            </div>

            <div v-if="drawerPriceEmptyState" class="calculation-block__empty">
              <div class="calculation-block__empty-title">{{ drawerPriceEmptyState.title }}</div>
              <div class="calculation-block__empty-text">{{ drawerPriceEmptyState.text }}</div>
            </div>
          </div>

            <div class="calculation-preview">
              <div class="calculation-preview__title">Пример расчёта</div>
              <template v-if="previewState === 'ready'">
                <div class="calculation-preview__row">
                  <span>Количество</span>
                  <strong>{{ previewQuantityLabel }}</strong>
                </div>
                <div class="calculation-preview__row">
                  <span>Сумма</span>
                  <strong>{{ formatPrice(previewAmountValue) }} ₽</strong>
                </div>
              </template>
              <div v-else class="calculation-preview__fallback">
                {{ previewHintText }}
              </div>
            </div>

            <div class="calculation-block__actions">
              <OperationRuleEditor
                compact
                trigger-label="Изменить логику"
                :operation="drawer.operation"
                :rule="applicationRule"
                :loading="applicationRuleLoading"
                :saving="applicationRuleSaving"
                :error="applicationRuleError"
                :material-options="applicationMaterials"
                :materials-loading="applicationMaterialsLoading"
                @request-materials="fetchApplicationMaterials"
                @save="saveApplicationRule"
              />
              <v-btn
                size="small"
                variant="tonal"
                color="primary"
                prepend-icon="mdi-currency-rub"
                :loading="drawer.saving"
                @click="openPriceDetails"
              >
                Изменить цену
              </v-btn>
            </div>

            <div v-if="drawerCalculationStatusText" class="calculation-block__status">
              {{ drawerCalculationStatusText }}
            </div>
          </div>

          <v-expansion-panels
            v-model="drawerDetailsPanels"
            variant="accordion"
            class="drawer-details-panels"
          >
            <v-expansion-panel>
              <v-expansion-panel-title>
                Подробнее
              </v-expansion-panel-title>
              <v-expansion-panel-text>
                <div class="drawer-details-grid">
                  <div class="detail-card">
                    <div class="detail-card__title">Правило применения</div>
                    <div class="detail-card__rows">
                      <div class="detail-card__row">
                        <span>Формула</span>
                        <strong>{{ unifiedScenarioLabel }}</strong>
                      </div>
                      <div class="detail-card__row">
                        <span>Применяется к</span>
                        <strong>{{ applicationRule ? applicationRule.applies_to === 'material_id' ? 'Конкретный материал' : 'Тип материала' : '—' }}</strong>
                      </div>
                      <div class="detail-card__row">
                        <span>Тип материала</span>
                        <strong>{{ ruleMaterialTypeLabel(applicationRule?.material_type) }}</strong>
                      </div>
                      <div v-if="applicationRule?.material_id" class="detail-card__row">
                        <span>Материал</span>
                        <strong>{{ ruleMaterialLabel }}</strong>
                      </div>
                      <div class="detail-card__row">
                        <span>Как считается</span>
                        <strong>{{ rawQuantitySourceLabel }}</strong>
                      </div>
                      <div class="detail-card__row">
                        <span>Единица цены</span>
                        <strong>{{ rawPricingUnitLabel }}</strong>
                      </div>
                    </div>
                  </div>

                  <div class="detail-card">
                    <div class="detail-card__title">Источник цены</div>
                    <div class="detail-card__rows">
                      <div class="detail-card__row">
                        <span>Активный режим</span>
                        <strong>{{ unifiedSourceInlineLabel || '—' }}</strong>
                      </div>
                      <div class="detail-card__row">
                        <span>Источник</span>
                        <strong>{{ drawerActivePriceSource?.source_name || '—' }}</strong>
                      </div>
                      <div class="detail-card__row">
                        <span>Единица</span>
                        <strong>{{ unifiedEffectiveUnitLabel || '—' }}</strong>
                      </div>
                      <div class="detail-card__row">
                        <span>Всего источников</span>
                        <strong>{{ allSources.length }}</strong>
                      </div>
                    </div>
                  </div>
                </div>

                <OperationPriceSources
                  :operation-id="drawer.operation.id"
                  :sources="allSources"
                  :active-source="drawerActivePriceSource"
                  :loading="drawerSummaryLoading"
                  :default-unit="drawer.operation.unit ?? null"
                  :refreshing="summaryRefreshing"
                  :action-info="sourceActionInfo"
                  :action-error="sourceActionError"
                  :action-pending="currentSourceActionPending"
                  :create-submitting="priceSourceCreateState.saving"
                  :create-error="priceSourceCreateState.error"
                  :create-success-token="priceSourceCreateState.successToken"
                  @create="createPriceSource"
                  @activate="activatePriceSource"
                  @delete="deletePriceSource"
                  @refresh="refetchOnReturnToScreen"
                />
              </v-expansion-panel-text>
            </v-expansion-panel>
          </v-expansion-panels>
        </div>
      </template>
    </v-navigation-drawer>

    <OperationFormDialog
      v-model="operationDialog.open"
      :mode="operationDialog.mode"
      :operation="operationDialog.operation"
      :loading="operationDialog.saving"
      :error="operationDialog.error"
      @submit="saveOperation"
    />

    <v-dialog v-model="quickCreateDialog.open" max-width="560">
      <v-card>
        <v-card-title>Добавить операцию</v-card-title>
        <v-card-text class="quick-create-dialog">
          <v-alert
            v-if="quickCreateDialog.error"
            type="error"
            variant="tonal"
            density="compact"
          >
            {{ quickCreateDialog.error }}
          </v-alert>

        <div class="quick-create-dialog__hint">
          Выберите вид операции. Операция создастся сразу с базовыми настройками и откроется в карточке.
        </div>
        <div class="quick-create-dialog__subhint">
          Клик по виду сразу создаёт операцию.
        </div>

          <div class="quick-create-dialog__options">
            <button
              v-for="option in quickCreateOptions"
              :key="option.kind"
              type="button"
              class="quick-create-option"
              :disabled="quickCreateDialog.loading"
              @click="createOperationByKind(option.kind)"
            >
              <span class="quick-create-option__bullet">
                {{ option.kind === 'other' ? '○' : '●' }}
              </span>
              <span class="quick-create-option__label">{{ option.label }}</span>
            </button>
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="quickCreateDialog.loading" @click="closeQuickCreateDialog">
            Отмена
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="deleteDialog.open" max-width="460">
      <v-card>
        <v-card-title>Удалить операцию</v-card-title>
        <v-card-text class="delete-dialog__body">
          <v-alert
            v-if="deleteDialog.error"
            type="error"
            variant="tonal"
            density="compact"
          >
            {{ deleteDialog.error }}
          </v-alert>
          <div>
            Операция <strong>{{ deleteDialog.operation?.name }}</strong> будет удалена. Это действие нельзя отменить.
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="deleteDialog.loading" @click="closeDeleteDialog">
            Отмена
          </v-btn>
          <v-btn color="error" variant="flat" :loading="deleteDialog.loading" @click="confirmDeleteOperation">
            Удалить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3000">
      {{ snackbar.message }}
    </v-snackbar>
  </PageContainer>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import type { AxiosError } from 'axios'
import api from '@/api/axios'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import OperationFormDialog from '@/components/pricing/OperationFormDialog.vue'
import OperationPriceSources from '@/components/pricing/OperationPriceSources.vue'
import OperationRuleEditor from '@/components/pricing/OperationRuleEditor.vue'

type OperationKind = 'cutting' | 'edging' | 'drilling' | 'other'

interface OperationRow {
  id: number
  name: string
  category?: string
  unit?: string
  operation_kind?: OperationKind
  min_thickness?: number | null
  max_thickness?: number | null
  description?: string | null
  origin?: string | null
  linked_prices_count?: number
  current_price?: number | null
}

interface OperationPriceSourceRow {
  id: number
  type: 'manual' | 'import' | 'external'
  value: string | number | null
  unit: string | null
  source_name: string | null
  document_ref?: string | null
  created_at: string | null
  is_active: boolean
}

interface PricingSummarySource {
  key: string
  type: string
  id: string
  name: string
  price: number | null
  unit: string | null
}

interface PricingSummaryEffectiveSource extends PricingSummarySource {
  mode: 'selected' | 'fallback'
}

interface OperationPricingSummaryResponse {
  operation_id: number
  effective_source: PricingSummaryEffectiveSource | null
  effective_price: number | null
}

interface OperationSummary {
  loaded: boolean
  priceSources: OperationPriceSourceRow[]
  activePriceSource: OperationPriceSourceRow | null
  effectivePrice: number | null
  sourcesCount: number
  effectiveSource: PricingSummaryEffectiveSource | null
}

type QuantitySource = 'position_area_m2' | 'position_quantity' | 'edge_length' | 'holes_count'
type PricingUnit = string

interface OperationApplicationRuleResponse {
  id: number
  operation_id: number
  source: 'user' | 'system'
  is_editable: boolean
  mode: 'automatic'
  applies_to: 'material_type' | 'material_id'
  material_type: 'plate' | 'edge' | 'facade' | 'hardware' | null
  material_id: number | null
  quantity_source: QuantitySource
  pricing_unit: PricingUnit
  tariff_binding_type: 'operation_resolver'
  tariff_operation_id: number | null
  conditions: {
    thickness?: {
      min?: number | null
      max?: number | null
    }
  } | null
  quantity_config?: {
    multiplier?: number | null
  } | null
  priority: number
  is_enabled: boolean
  updated_at: string | null
}

interface MaterialOption {
  id: number
  name: string
  article?: string | null
  type: 'plate' | 'edge' | 'facade' | 'hardware'
  unit?: string | null
}

interface SourceActionPending {
  operationId: number
  sourceId: number | null
  action: 'create' | 'activate' | 'delete'
}

interface ProjectOperationPreviewRow {
  operation_id?: number | null
  quantity?: number | string | null
  unit?: string | null
  amount?: number | string | null
  total_cost?: number | string | null
  cost_per_unit?: number | string | null
  is_valid?: boolean | null
  unit_mismatch?: boolean | null
}

interface PreviewProjectOption {
  id: number
  name: string
}

// ── State ────────────────────────────────────────────────────────────────────

const operations = ref<OperationRow[]>([])
const loading = ref(false)
const search = ref('')
const sourceActionInfo = ref<string | null>(null)
const operationSummaryCache = ref<Record<number, OperationSummary>>({})
const summaryLoadingById = ref<Record<number, boolean>>({})
const summaryErrorById = ref<Record<number, string | null>>({})
const prefetchStarted = ref(false)
const returnRefetchInFlight = ref(false)
const returnRefetchTimer = ref<number | null>(null)
const summaryRefreshing = ref(false)
const sourceActionPending = ref<SourceActionPending | null>(null)
const sourceActionError = ref<string | null>(null)
const applicationRule = ref<OperationApplicationRuleResponse | null>(null)
const applicationRuleLoading = ref(false)
const applicationRuleSaving = ref(false)
const applicationRuleError = ref<string | null>(null)
const applicationMaterials = ref<MaterialOption[]>([])
const applicationMaterialsLoading = ref(false)
const operationRuleEnabledById = ref<Record<number, boolean | null>>({})
const operationRuleLoadingById = ref<Record<number, boolean>>({})
const drawerDetailsPanels = ref<number[]>([])
const previewProjects = ref<PreviewProjectOption[]>([])
const previewProjectsLoading = ref(false)
const selectedProjectId = ref<number | null>(null)
const preview = ref<{
  projectId: number | null
  row: ProjectOperationPreviewRow | null
  loading: boolean
  error: string | null
}>({
  projectId: null,
  row: null,
  loading: false,
  error: null,
})
const snackbar = ref({
  show: false,
  message: '',
  color: 'success',
})
const priceSourceCreateState = ref({
  saving: false,
  error: null as string | null,
  successToken: 0,
})
const operationDialog = ref<{
  open: boolean
  mode: 'create' | 'edit'
  operation: OperationRow | null
  saving: boolean
  error: string | null
}>({
  open: false,
  mode: 'create',
  operation: null,
  saving: false,
  error: null,
})
const quickCreateDialog = ref<{
  open: boolean
  loading: boolean
  error: string | null
}>({
  open: false,
  loading: false,
  error: null,
})
const deleteDialog = ref<{
  open: boolean
  operation: OperationRow | null
  loading: boolean
  error: string | null
}>({
  open: false,
  operation: null,
  loading: false,
  error: null,
})
const drawer = ref<{
  open: boolean
  operation: OperationRow | null
  saving: boolean
}>({
  open: false,
  operation: null,
  saving: false,
})

// ── Table config ─────────────────────────────────────────────────────────────

const headers = [
  { title: 'Наименование', key: 'name' },
  { title: 'Вид', key: 'operation_kind', width: '130px', sortable: false },
  { title: 'Ед. изм.', key: 'unit', width: '90px' },
  { title: 'Режим', key: 'origin', width: '120px', sortable: false },
  { title: 'Цена', key: 'price', width: '100px', sortable: false },
  { title: 'Источников', key: 'linked_prices_count', width: '110px' },
  { title: '', key: 'actions', width: '170px', sortable: false },
]

const quickCreateOptions: Array<{ kind: OperationKind; label: string }> = [
  { kind: 'cutting', label: 'Раскрой' },
  { kind: 'edging', label: 'Кромление' },
  { kind: 'drilling', label: 'Сверление' },
  { kind: 'other', label: 'Другое' },
]

// ── Helpers ──────────────────────────────────────────────────────────────────

function hasEnabledAutoRule(operation?: OperationRow | null): boolean {
  if (!operation) return false

  const cached = operationRuleEnabledById.value[operation.id]
  if (cached !== null && cached !== undefined) return cached

  return operation.operation_kind !== 'other'
}

function modeLabel(operation?: OperationRow | null): string {
  return hasEnabledAutoRule(operation) ? 'Автоматическая' : 'Ручная'
}

function modeColor(operation?: OperationRow | null): string {
  return hasEnabledAutoRule(operation) ? 'teal' : 'primary'
}

function operationKindLabel(kind?: OperationKind | null): string {
  if (kind === 'cutting') return 'Раскрой'
  if (kind === 'edging') return 'Кромление'
  if (kind === 'drilling') return 'Сверление'
  return 'Другое'
}

function operationKindColor(kind?: OperationKind | null): string {
  if (kind === 'cutting') return 'deep-orange'
  if (kind === 'edging') return 'indigo'
  if (kind === 'drilling') return 'cyan-darken-1'
  return 'grey'
}

function formatPrice(val: string | number | null | undefined): string {
  if (val === null || val === undefined) return '—'
  const numberValue = typeof val === 'string' ? Number(val) : val
  if (!Number.isFinite(numberValue)) return '—'
  return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(numberValue)
}

function normalizeUnitLabel(unit?: string | null): string | null {
  if (!unit) return null

  const compact = unit.toLowerCase().trim().replace(/[\s.,·]/g, '')
  const map: Record<string, string> = {
    'м2': 'м²',
    'm2': 'м²',
    'м^2': 'м²',
    'м²': 'м²',
    'квм': 'м²',
    'мп': 'м.п.',
    'пм': 'м.п.',
    'погм': 'м.п.',
    'мпог': 'м.п.',
    'шт': 'шт.',
    'шт.': 'шт.',
    'рез': 'рез',
    'дет': 'деталь',
    'деталь': 'деталь',
    'лист': 'лист',
  }

  return map[compact] ?? unit.trim()
}

function formatQuantity(val: string | number | null | undefined): string {
  if (val === null || val === undefined) return '—'
  const numberValue = typeof val === 'string' ? Number(val) : val
  if (!Number.isFinite(numberValue)) return '—'
  return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(numberValue)
}

function ruleMaterialTypeLabel(type?: MaterialOption['type'] | null): string {
  if (type === 'plate') return 'Плитные материалы'
  if (type === 'edge') return 'Кромочные материалы'
  if (type === 'facade') return 'Фасады'
  if (type === 'hardware') return 'Фурнитура'
  return '—'
}

function quantitySourceHumanLabel(source?: QuantitySource | null): string {
  if (source === 'position_area_m2') return 'Площадь детали'
  if (source === 'edge_length') return 'Длина кромки'
  if (source === 'holes_count') return 'Количество отверстий'
  if (source === 'position_quantity') return 'Количество позиции'
  return '—'
}

function buildOperationSummary(
  priceSources: OperationPriceSourceRow[],
  pricingSummary: OperationPricingSummaryResponse | null,
): OperationSummary {
  const activePriceSource = priceSources.find((source) => source.is_active) ?? null

  return {
    loaded: true,
    priceSources,
    activePriceSource,
    effectivePrice: pricingSummary?.effective_price ?? null,
    sourcesCount: priceSources.length,
    effectiveSource: pricingSummary?.effective_source ?? null,
  }
}

// ── Derived ──────────────────────────────────────────────────────────────────

const drawerSummary = computed<OperationSummary | null>(() => {
  const operationId = drawer.value.operation?.id
  if (!operationId) return null
  return operationSummaryCache.value[operationId] ?? null
})

const drawerSummaryLoading = computed<boolean>(() => {
  const operationId = drawer.value.operation?.id
  return operationId ? isOperationSummaryLoading(operationId) : false
})

const drawerSummaryError = computed<string | null>(() => {
  const operationId = drawer.value.operation?.id
  return operationId ? (summaryErrorById.value[operationId] ?? null) : null
})

const drawerCurrentPrice = computed<number | null>(() => {
  if (drawerSummaryError.value) return null
  const rawValue = drawerSummary.value?.activePriceSource?.value ?? null
  if (rawValue === null || rawValue === undefined) return null
  const numberValue = typeof rawValue === 'string' ? Number(rawValue) : rawValue
  return Number.isFinite(numberValue) ? numberValue : null
})

const unifiedEffectiveUnitLabel = computed<string | null>(() => (
  normalizeUnitLabel(drawerSummary.value?.activePriceSource?.unit)
  ?? normalizeUnitLabel(drawerEffectiveSource.value?.unit)
  ?? normalizeUnitLabel(drawer.value.operation?.unit)
  ?? null
))

const drawerEvidenceCount = computed<number | null>(() => {
  return drawerSummary.value?.sourcesCount ?? 0
})

const drawerEvidenceCountLabel = computed(() => {
  if (drawerEvidenceCount.value === null) return '—'
  return String(drawerEvidenceCount.value)
})

const allSources = computed<OperationPriceSourceRow[]>(() => {
  return drawerSummary.value?.priceSources ?? []
})

const drawerActivePriceSource = computed<OperationPriceSourceRow | null>(() => (
  drawerSummary.value?.activePriceSource ?? null
))

const drawerEffectiveSource = computed<PricingSummaryEffectiveSource | null>(() => {
  if (drawerSummaryError.value) return null
  return drawerSummary.value?.effectiveSource ?? null
})
const unifiedScenarioLabel = computed<string>(() => {
  if (drawer.value.operation?.operation_kind === 'cutting') return 'Площадь детали'
  if (drawer.value.operation?.operation_kind === 'edging') return 'Длина кромки'
  if (drawer.value.operation?.operation_kind === 'drilling') return 'Количество отверстий'
  return quantitySourceHumanLabel(applicationRule.value?.quantity_source ?? null)
})
const unifiedAutomationLabel = computed<string>(() => {
  if (drawer.value.operation?.operation_kind === 'cutting' || drawer.value.operation?.operation_kind === 'edging') {
    return 'рассчитывается автоматически'
  }

  if (applicationRule.value?.is_enabled === false) {
    return 'правило выключено'
  }

  return 'используется в расчёте'
})
const unifiedSourceInlineLabel = computed<string>(() => {
  if (drawerSummaryLoading.value) return ''
  if (drawerActivePriceSource.value) return 'основная цена'
  return ''
})
const unitMismatchInDrawer = computed<boolean>(() => {
  const expected = normalizeUnitLabel(applicationRule.value?.pricing_unit)
  const actual = normalizeUnitLabel(drawerActivePriceSource.value?.unit)
  return !!expected && !!actual && expected !== actual
})
const drawerCalculationStatusText = computed<string>(() => {
  if (drawerSummaryLoading.value) return ''
  if (drawerSummaryError.value) return '⚠ Не удалось загрузить цену'
  if (unitMismatchInDrawer.value) return '⚠ Ошибка настройки операции: единица цены не совпадает с расчётом'
  if (drawerCurrentPrice.value === null) return '⚠ Ошибка настройки операции: цена не задана'
  return ''
})
const drawerPriceEmptyState = computed<null | { title: string; text: string }>(() => {
  if (drawerSummaryLoading.value || drawerSummaryError.value) return null
  if (unitMismatchInDrawer.value) {
    return {
      title: 'Ошибка настройки операции',
      text: 'Проверьте единицу цены и логику расчёта.',
    }
  }
  if (drawerCurrentPrice.value === null) {
    return {
      title: 'Цена не задана',
      text: 'Добавьте источник цены.',
    }
  }
  return null
})
const previewRow = computed<ProjectOperationPreviewRow | null>(() => preview.value.row)
const previewQuantityLabel = computed<string>(() => {
  if (!previewRow.value) return '—'
  const unit = normalizeUnitLabel(previewRow.value.unit) ?? unifiedEffectiveUnitLabel.value ?? drawer.value.operation?.unit ?? 'ед.'
  return `${formatQuantity(previewRow.value.quantity)} ${unit}`
})
const previewAmountValue = computed<number | null>(() => {
  if (!previewRow.value) return null
  const rawAmount = previewRow.value.amount ?? previewRow.value.total_cost ?? null
  if (rawAmount === null || rawAmount === undefined) return null
  const numberValue = typeof rawAmount === 'string' ? Number(rawAmount) : rawAmount
  return Number.isFinite(numberValue) ? numberValue : null
})
const previewState = computed<'loading' | 'invalid' | 'ready' | 'empty'>(() => {
  if (preview.value.loading) return 'loading'
  if (previewRow.value?.is_valid === false || previewRow.value?.unit_mismatch) return 'invalid'
  if (previewRow.value && previewAmountValue.value !== null) return 'ready'
  return 'empty'
})
const previewHintText = computed<string>(() => {
  if (previewState.value === 'loading') return 'Загрузка примера...'
  if (previewState.value === 'invalid') return '⚠ Невозможно рассчитать (ошибка настройки)'
  if (preview.value.projectId === null) return 'Выберите проект для примера'
  if (preview.value.error) return 'Нет данных для примера'
  if (!previewRow.value) return 'Нет данных для примера'
  return ''
})
function tableOperationIssueLabel(operation: OperationRow): string {
  if (tableSummaryLoading(operation)) return ''
  if (tableSummaryError(operation)) return 'Цена временно недоступна'
  if (tableSummaryLoaded(operation) && tableCurrentPrice(operation) === null) return 'Цена не задана'
  return ''
}
const ruleMaterialLabel = computed<string>(() => {
  if (!applicationRule.value?.material_id) return '—'
  const material = applicationMaterials.value.find((item) => item.id === applicationRule.value?.material_id)
  if (!material) return `Материал #${applicationRule.value.material_id}`
  return material.article ? `${material.name} (${material.article})` : material.name
})
const rawQuantitySourceLabel = computed<string>(() => quantitySourceHumanLabel(applicationRule.value?.quantity_source ?? null))
const rawPricingUnitLabel = computed<string>(() => normalizeUnitLabel(applicationRule.value?.pricing_unit) ?? '—')
const summaryErrorCount = computed<number>(() => {
  return operations.value.reduce((count, operation) => (
    summaryErrorById.value[operation.id] ? count + 1 : count
  ), 0)
})
const hasGlobalSummaryError = computed<boolean>(() => summaryErrorCount.value >= 3)
const currentSourceActionPending = computed<SourceActionPending | null>(() => {
  const pending = sourceActionPending.value
  const operationId = drawer.value.operation?.id
  if (!pending || !operationId || pending.operationId !== operationId) return null
  return pending
})

function openPriceDetails() {
  drawerDetailsPanels.value = [0]
}

const PREVIEW_PROJECT_STORAGE_KEY = 'pricing.operations.previewProjectId'

// ── API ──────────────────────────────────────────────────────────────────────

async function fetchOperations() {
  loading.value = true
  try {
    operations.value = (await api.get('/api/operations')).data
    await preloadOperationModes(operations.value)
    if (!prefetchStarted.value) {
      prefetchStarted.value = true
      void prefetchInitialOperationSummaries()
    }
  } finally {
    loading.value = false
  }
}

async function ensureOperationRuleModeLoaded(operationId: number, options?: { force?: boolean }) {
  const force = options?.force === true

  if (operationRuleLoadingById.value[operationId]) return
  if (!force && Object.prototype.hasOwnProperty.call(operationRuleEnabledById.value, operationId)) return

  operationRuleLoadingById.value = {
    ...operationRuleLoadingById.value,
    [operationId]: true,
  }

  try {
    const res = await api.get(`/api/operations/${operationId}/application-rule`)
    const rule = (res.data?.rule ?? null) as OperationApplicationRuleResponse | null
    operationRuleEnabledById.value = {
      ...operationRuleEnabledById.value,
      [operationId]: rule?.is_enabled === true,
    }
  } catch {
    operationRuleEnabledById.value = {
      ...operationRuleEnabledById.value,
      [operationId]: false,
    }
  } finally {
    operationRuleLoadingById.value = {
      ...operationRuleLoadingById.value,
      [operationId]: false,
    }
  }
}

async function preloadOperationModes(rows: OperationRow[]) {
  if (!rows.length) return
  await Promise.all(rows.map((row) => ensureOperationRuleModeLoaded(row.id)))
}

async function fetchPriceSources(operationId: number) {
  const res = await api.get(`/api/operations/${operationId}/price-sources`)
  return (Array.isArray(res.data) ? res.data : []) as OperationPriceSourceRow[]
}

async function fetchPricingSummary(operationId: number) {
  const res = await api.get(`/api/operations/${operationId}/pricing-summary`)
  return res.data as OperationPricingSummaryResponse
}

async function fetchPreviewProjects() {
  previewProjectsLoading.value = true

  try {
    const res = await api.get('/api/projects')
    const projects = Array.isArray(res.data) ? res.data : []
    previewProjects.value = projects.map((project: Record<string, unknown>) => ({
      id: Number(project.id),
      name: buildPreviewProjectLabel(project),
    })).filter((project: PreviewProjectOption) => Number.isFinite(project.id))

    const storedProjectId = readStoredPreviewProjectId()
    const hasStoredProject = storedProjectId !== null
      && previewProjects.value.some((project) => project.id === storedProjectId)

    if (hasStoredProject) {
      selectedProjectId.value = storedProjectId
    } else if (previewProjects.value.length > 0) {
      selectedProjectId.value = previewProjects.value[0].id
    } else {
      selectedProjectId.value = null
    }
  } catch {
    previewProjects.value = []
    selectedProjectId.value = null
  } finally {
    previewProjectsLoading.value = false
  }
}

async function fetchOperationImpactPreview(operationId: number) {
  const projectId = selectedProjectId.value

  preview.value = {
    projectId,
    row: null,
    loading: !!projectId,
    error: null,
  }

  if (!projectId) {
    return
  }

  try {
    const res = await api.get(`/api/projects/${projectId}/operations`)
    const rows = Array.isArray(res.data) ? res.data as ProjectOperationPreviewRow[] : []
    const matchedRow = rows.find((row) => Number(row.operation_id) === operationId) ?? null

    preview.value = {
      projectId,
      row: matchedRow,
      loading: false,
      error: matchedRow ? null : 'not_found',
    }
  } catch {
    preview.value = {
      projectId,
      row: null,
      loading: false,
      error: 'fetch_failed',
    }
  }
}

function readStoredPreviewProjectId(): number | null {
  if (typeof window === 'undefined' || !window.localStorage) return null

  const rawValue = window.localStorage.getItem(PREVIEW_PROJECT_STORAGE_KEY)
  if (!rawValue) return null

  const parsed = Number(rawValue)
  return Number.isFinite(parsed) ? parsed : null
}

function persistPreviewProjectId(projectId: number | null) {
  if (typeof window === 'undefined' || !window.localStorage) return

  if (projectId === null) {
    window.localStorage.removeItem(PREVIEW_PROJECT_STORAGE_KEY)
    return
  }

  window.localStorage.setItem(PREVIEW_PROJECT_STORAGE_KEY, String(projectId))
}

function buildPreviewProjectLabel(project: Record<string, unknown>): string {
  const number = typeof project.number === 'string' ? project.number.trim() : ''
  const address = typeof project.address === 'string' ? project.address.trim() : ''
  const expertName = typeof project.expert_name === 'string' ? project.expert_name.trim() : ''

  if (number && address) return `${number} — ${address}`
  if (number) return number
  if (address) return address
  if (expertName) return expertName
  return `Проект #${String(project.id ?? '—')}`
}

async function fetchApplicationMaterials(materialType: MaterialOption['type']) {
  applicationMaterialsLoading.value = true

  try {
    const res = await api.get('/api/materials', {
      params: {
        type: materialType,
      },
    })
    applicationMaterials.value = (res.data ?? []) as MaterialOption[]
  } catch {
    applicationMaterials.value = []
    showSnackbar('Не удалось загрузить материалы', 'error')
  } finally {
    applicationMaterialsLoading.value = false
  }
}

async function fetchApplicationRule(operation: OperationRow) {
  applicationRuleLoading.value = true
  applicationRuleError.value = null
  applicationRule.value = null
  applicationMaterials.value = []

  try {
    const res = await api.get(`/api/operations/${operation.id}/application-rule`)
    const rule = (res.data?.rule ?? null) as OperationApplicationRuleResponse | null
    applicationRule.value = rule
    operationRuleEnabledById.value = {
      ...operationRuleEnabledById.value,
      [operation.id]: rule?.is_enabled === true,
    }
    if (rule?.material_type) {
      await fetchApplicationMaterials(rule.material_type)
    } else {
      applicationMaterials.value = []
    }
  } catch {
    applicationRule.value = null
    applicationRuleError.value = 'Не удалось загрузить правило применения'
    operationRuleEnabledById.value = {
      ...operationRuleEnabledById.value,
      [operation.id]: false,
    }
  } finally {
    applicationRuleLoading.value = false
  }
}

async function loadOperationSummary(operationId: number) {
  const [priceSources, pricingSummary] = await Promise.all([
    fetchPriceSources(operationId),
    fetchPricingSummary(operationId),
  ])

  operationSummaryCache.value = {
    ...operationSummaryCache.value,
    [operationId]: buildOperationSummary(priceSources, pricingSummary),
  }
}

function getOperationSummary(operationId: number): OperationSummary | null {
  return operationSummaryCache.value[operationId] ?? null
}

function isOperationSummaryLoading(operationId: number): boolean {
  return !!summaryLoadingById.value[operationId]
}

function tableSummaryLoaded(operation: OperationRow): boolean {
  return !!getOperationSummary(operation.id)?.loaded
}

function tableSummaryLoading(operation: OperationRow): boolean {
  return isOperationSummaryLoading(operation.id)
}

function tableSummaryError(operation: OperationRow): boolean {
  return !!summaryErrorById.value[operation.id]
}

function tableCurrentPrice(operation: OperationRow): number | null {
  if (tableSummaryError(operation)) return null
  const rawValue = getOperationSummary(operation.id)?.activePriceSource?.value ?? null
  if (rawValue === null || rawValue === undefined) return null
  const numberValue = typeof rawValue === 'string' ? Number(rawValue) : rawValue
  return Number.isFinite(numberValue) ? numberValue : null
}

function tableSourcesCount(operation: OperationRow): number {
  return getOperationSummary(operation.id)?.sourcesCount ?? 0
}

async function ensureOperationSummaryLoaded(operationId: number, options?: { force?: boolean }) {
  const force = options?.force === true
  const summary = getOperationSummary(operationId)

  if (isOperationSummaryLoading(operationId)) return
  if (!force && summary?.loaded) return

  summaryLoadingById.value = {
    ...summaryLoadingById.value,
    [operationId]: true,
  }
  summaryErrorById.value = {
    ...summaryErrorById.value,
    [operationId]: null,
  }

  try {
    await loadOperationSummary(operationId)
  } catch {
    summaryErrorById.value = {
      ...summaryErrorById.value,
      [operationId]: 'Не удалось загрузить цену',
    }
  } finally {
    summaryLoadingById.value = {
      ...summaryLoadingById.value,
      [operationId]: false,
    }
  }
}

async function prefetchInitialOperationSummaries(options?: { force?: boolean }) {
  const force = options?.force === true
  const queue = operations.value
    .slice(0, 10)
    .map((operation) => operation.id)
    .filter((operationId) => {
      const summary = getOperationSummary(operationId)
      return !isOperationSummaryLoading(operationId) && (force || !summary?.loaded)
    })

  const concurrency = Math.min(3, queue.length)
  if (concurrency === 0) return

  async function worker() {
    while (queue.length > 0) {
      const operationId = queue.shift()
      if (!operationId) return
      await ensureOperationSummaryLoaded(operationId)
    }
  }

  await Promise.all(Array.from({ length: concurrency }, () => worker()))
}

// ── Actions ──────────────────────────────────────────────────────────────────

function openDrawer(operation: OperationRow) {
  drawer.value.operation = operation
  drawer.value.open = true
  drawerDetailsPanels.value = []
  sourceActionError.value = null
  sourceActionInfo.value = null
  void ensureOperationSummaryLoaded(operation.id, { force: true })
  void ensureOperationRuleModeLoaded(operation.id)
  void fetchApplicationRule(operation)
  void fetchOperationImpactPreview(operation.id)
}

function openCreateOperationDialog() {
  quickCreateDialog.value = {
    open: true,
    loading: false,
    error: null,
  }
}

function closeQuickCreateDialog() {
  quickCreateDialog.value = {
    open: false,
    loading: false,
    error: null,
  }
}

function buildQuickOperationPayload(kind: OperationKind) {
  const defaults: Record<OperationKind, { name: string; category: string; unit: string }> = {
    cutting: { name: 'Новый раскрой', category: 'cutting', unit: 'м²' },
    edging: { name: 'Новое кромление', category: 'edging', unit: 'м.п.' },
    drilling: { name: 'Новое сверление', category: 'drilling', unit: 'шт.' },
    other: { name: 'Новая операция', category: 'other', unit: 'шт.' },
  }

  const base = defaults[kind]

  return {
    name: `${base.name} ${new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}`,
    category: base.category,
    unit: base.unit,
    operation_kind: kind,
    min_thickness: null,
    max_thickness: null,
    description: null,
  }
}

async function createOperationByKind(kind: OperationKind) {
  quickCreateDialog.value.loading = true
  quickCreateDialog.value.error = null

  try {
    const response = await api.post('/api/operations', buildQuickOperationPayload(kind))
    const savedOperation = response.data as OperationRow
    await refreshOperationsAfterMutation()
    closeQuickCreateDialog()

    const freshOperation = operations.value.find((item) => item.id === savedOperation.id) ?? savedOperation
    openDrawer(freshOperation)
    showSnackbar('Операция создана')
  } catch (error) {
    quickCreateDialog.value.error = extractApiErrorMessage(error, 'Не удалось создать операцию')
  } finally {
    quickCreateDialog.value.loading = false
  }
}

function openEditOperationDialog(operation: OperationRow) {
  operationDialog.value = {
    open: true,
    mode: 'edit',
    operation,
    saving: false,
    error: null,
  }
}

function openDeleteDialog(operation: OperationRow) {
  deleteDialog.value = {
    open: true,
    operation,
    loading: false,
    error: null,
  }
}

function closeDeleteDialog() {
  deleteDialog.value = {
    open: false,
    operation: null,
    loading: false,
    error: null,
  }
}

function resetDrawerAfterDelete(operationId: number) {
  if (drawer.value.operation?.id !== operationId) return

  drawer.value.open = false
  drawerDetailsPanels.value = []
  drawer.value.operation = null
  sourceActionPending.value = null
  sourceActionError.value = null
  priceSourceCreateState.value.error = null
  applicationRule.value = null
  applicationRuleError.value = null
  applicationMaterials.value = []
  preview.value = {
    projectId: null,
    row: null,
    loading: false,
    error: null,
  }
}

function extractApiErrorMessage(
  error: unknown,
  fallback: string,
) {
  const axiosError = error as AxiosError<{ message?: string; errors?: Record<string, string[]> }>
  const firstValidationMessage = axiosError.response?.data?.errors
    ? Object.values(axiosError.response.data.errors).flat()[0]
    : null

  return firstValidationMessage
    ?? axiosError.response?.data?.message
    ?? fallback
}

async function refreshOperationsAfterMutation(options?: {
  editedOperationId?: number | null
  deletedOperationId?: number | null
}) {
  await fetchOperations()

  if (options?.deletedOperationId) {
    delete operationSummaryCache.value[options.deletedOperationId]
    delete summaryLoadingById.value[options.deletedOperationId]
    delete summaryErrorById.value[options.deletedOperationId]
    resetDrawerAfterDelete(options.deletedOperationId)
    return
  }

  const activeOperationId = drawer.value.operation?.id
  if (!activeOperationId) return

  const freshOperation = operations.value.find((item) => item.id === activeOperationId)
  if (!freshOperation) {
    resetDrawerAfterDelete(activeOperationId)
    return
  }

  drawer.value.operation = freshOperation

  if (options?.editedOperationId === activeOperationId) {
    await Promise.all([
      ensureOperationSummaryLoaded(activeOperationId, { force: true }),
      ensureOperationRuleModeLoaded(activeOperationId, { force: true }),
      fetchApplicationRule(freshOperation),
      fetchOperationImpactPreview(activeOperationId),
    ])
  }
}

async function refreshActiveDrawerState(options?: { forceRule?: boolean; forceSummary?: boolean }) {
  const operationId = drawer.value.operation?.id
  if (!operationId) return

  await fetchOperations()

  const freshOperation = operations.value.find((item) => item.id === operationId)
  if (!freshOperation) {
    resetDrawerAfterDelete(operationId)
    return
  }

  drawer.value.operation = freshOperation

  await Promise.all([
    ensureOperationRuleModeLoaded(operationId, { force: options?.forceRule ?? true }),
    ensureOperationSummaryLoaded(operationId, { force: options?.forceSummary ?? true }),
    fetchApplicationRule(freshOperation),
    fetchOperationImpactPreview(operationId),
  ])
}

async function saveOperation(payload: {
  name: string
  category: string
  unit: string
  operation_kind: OperationKind
  min_thickness: number | null
  max_thickness: number | null
  description: string | null
}) {
  const editingOperation = operationDialog.value.mode === 'edit'
    ? operationDialog.value.operation
    : null

  operationDialog.value.saving = true
  operationDialog.value.error = null

  try {
    const response = editingOperation
      ? await api.put(`/api/operations/${editingOperation.id}`, payload)
      : await api.post('/api/operations', payload)

    const savedOperation = response.data as OperationRow
    await refreshOperationsAfterMutation({
      editedOperationId: editingOperation?.id ?? null,
    })

    operationDialog.value = {
      open: false,
      mode: 'create',
      operation: null,
      saving: false,
      error: null,
    }

    const freshOperation = operations.value.find((item) => item.id === savedOperation.id) ?? savedOperation
    if (!editingOperation) {
      openDrawer(freshOperation)
      showSnackbar('Операция создана')
    } else {
      if (drawer.value.operation?.id === freshOperation.id) {
        drawer.value.operation = freshOperation
      }
      showSnackbar('Операция обновлена')
    }
  } catch (error) {
    operationDialog.value.error = extractApiErrorMessage(error, 'Не удалось сохранить операцию')
  } finally {
    operationDialog.value.saving = false
  }
}

async function confirmDeleteOperation() {
  const operation = deleteDialog.value.operation
  if (!operation) return

  deleteDialog.value.loading = true
  deleteDialog.value.error = null

  try {
    await api.delete(`/api/operations/${operation.id}`)
    closeDeleteDialog()
    await refreshOperationsAfterMutation({ deletedOperationId: operation.id })
    showSnackbar('Операция удалена')
  } catch (error) {
    deleteDialog.value.error = extractApiErrorMessage(error, 'Не удалось удалить операцию')
  } finally {
    deleteDialog.value.loading = false
  }
}

async function createPriceSource(payload: {
  type: 'manual' | 'import' | 'external'
  value: number | null
  unit: string
  source_name: string
}) {
  const operationId = drawer.value.operation?.id
  if (!operationId) return

  priceSourceCreateState.value.saving = true
  priceSourceCreateState.value.error = null
  sourceActionPending.value = {
    operationId,
    sourceId: null,
    action: 'create',
  }

  try {
    await api.post(`/api/operations/${operationId}/price-sources`, {
      type: payload.type,
      value: payload.value,
      unit: payload.unit,
      source_name: payload.source_name || null,
    })

    priceSourceCreateState.value.successToken += 1
    await refreshActiveDrawerState({ forceSummary: true })
    showSnackbar('Источник цены добавлен')
  } catch (error) {
    priceSourceCreateState.value.error = extractApiErrorMessage(error, 'Не удалось добавить источник цены')
  } finally {
    priceSourceCreateState.value.saving = false
    if (sourceActionPending.value?.action === 'create' && sourceActionPending.value.operationId === operationId) {
      sourceActionPending.value = null
    }
  }
}

function showSnackbar(message: string, color = 'success') {
  snackbar.value = {
    show: true,
    message,
    color,
  }
}

async function saveApplicationRule(payload: {
  applies_to: 'material_type' | 'material_id'
  material_type: MaterialOption['type']
  material_id: number | null
  quantity_source: QuantitySource
  pricing_unit: PricingUnit
  quantity_config: { multiplier: number } | null
  conditions: { thickness: { min?: number; max?: number } } | null
  is_enabled: boolean
}) {
  const operation = drawer.value.operation
  if (!operation) return

  applicationRuleSaving.value = true
  applicationRuleError.value = null

  try {
    const ruleIsUserEditable = applicationRule.value?.source === 'user' && applicationRule.value.is_editable
    const res = ruleIsUserEditable
      ? await api.put(`/api/operations/${operation.id}/application-rules/${applicationRule.value?.id}`, payload)
      : await api.post(`/api/operations/${operation.id}/application-rule`, payload)

    applicationRule.value = (res.data?.rule ?? null) as OperationApplicationRuleResponse | null
    operationRuleEnabledById.value = {
      ...operationRuleEnabledById.value,
      [operation.id]: applicationRule.value?.is_enabled === true,
    }
    if (applicationRule.value?.material_type) {
      await fetchApplicationMaterials(applicationRule.value.material_type)
    }
    await refreshActiveDrawerState({ forceRule: true, forceSummary: true })
    showSnackbar('Правило применения сохранено')
  } catch (error) {
    applicationRuleError.value = extractApiErrorMessage(error, 'Не удалось сохранить правило применения')
    showSnackbar(applicationRuleError.value, 'error')
  } finally {
    applicationRuleSaving.value = false
  }
}

async function activatePriceSource(source: OperationPriceSourceRow) {
  const operationId = drawer.value.operation?.id
  if (!operationId) return
  if (sourceActionPending.value) return

  sourceActionInfo.value = null
  sourceActionPending.value = {
    operationId,
    sourceId: source.id,
    action: 'activate',
  }
  sourceActionError.value = null
  try {
    await api.patch(`/api/price-sources/${source.id}/activate`)
    await Promise.all([
      ensureOperationSummaryLoaded(operationId, { force: true }),
      fetchOperationImpactPreview(operationId),
    ])
    showSnackbar('Основная цена обновлена')
  } catch {
    sourceActionError.value = 'Не удалось сделать источник основным'
  } finally {
    if (
      sourceActionPending.value?.operationId === operationId
      && sourceActionPending.value?.sourceId === source.id
      && sourceActionPending.value?.action === 'activate'
    ) {
      sourceActionPending.value = null
    }
  }
}

async function deletePriceSource(source: OperationPriceSourceRow) {
  const operationId = drawer.value.operation?.id
  if (!operationId) return
  if (sourceActionPending.value) return

  sourceActionInfo.value = null
  sourceActionPending.value = {
    operationId,
    sourceId: source.id,
    action: 'delete',
  }
  sourceActionError.value = null
  try {
    await api.delete(`/api/price-sources/${source.id}`)
    await Promise.all([
      ensureOperationSummaryLoaded(operationId, { force: true }),
      fetchOperationImpactPreview(operationId),
    ])
    showSnackbar('Источник цены удалён')
  } catch {
    sourceActionError.value = 'Не удалось удалить источник'
  } finally {
    if (
      sourceActionPending.value?.operationId === operationId
      && sourceActionPending.value?.sourceId === source.id
      && sourceActionPending.value?.action === 'delete'
    ) {
      sourceActionPending.value = null
    }
  }
}

let lastRefreshAt = 0
let refreshTimeout: ReturnType<typeof setTimeout> | null = null

function isSystemOperation(operation: { user_id?: number | null } | null | undefined): boolean {
  return !operation?.user_id
}

function operationTypeLabel(operation: { user_id?: number | null } | null | undefined): string {
  return isSystemOperation(operation) ? 'Системная' : 'Пользовательская'
}

async function refetchOnReturnToScreen() {
  const now = Date.now()

  if (now - lastRefreshAt < 30000) {
    return
  }

  lastRefreshAt = now

  if (!drawer.value.open) {
    await fetchOperations()
    return
  }
  if (returnRefetchInFlight.value) return

  returnRefetchInFlight.value = true
  summaryRefreshing.value = true

  try {
    await fetchOperations()
    await prefetchInitialOperationSummaries({ force: true })

    if (drawer.value.open && drawer.value.operation) {
      await Promise.all([
        ensureOperationSummaryLoaded(drawer.value.operation.id, { force: true }),
        fetchOperationImpactPreview(drawer.value.operation.id),
      ])
    }
  } finally {
    returnRefetchInFlight.value = false
    window.setTimeout(() => {
      summaryRefreshing.value = false
    }, 400)
  }
}

function scheduleReturnRefetch() {
  if (refreshTimeout) {
    return
  }

  refreshTimeout = setTimeout(() => {
    refreshTimeout = null
    void refetchOnReturnToScreen()
  }, 300)
  if (returnRefetchTimer.value !== null) {
    window.clearTimeout(returnRefetchTimer.value)
  }

  returnRefetchTimer.value = window.setTimeout(() => {
    returnRefetchTimer.value = null
    void refetchOnReturnToScreen()
  }, 400)
}

function handleReturnToScreen() {
  if (document.visibilityState === 'hidden') {
    return
  }
  if (document.visibilityState === 'hidden') return
  scheduleReturnRefetch()
}

watch(selectedProjectId, (projectId) => {
  persistPreviewProjectId(projectId)

  if (drawer.value.operation) {
    void fetchOperationImpactPreview(drawer.value.operation.id)
  } else {
    preview.value = {
      projectId,
      row: null,
      loading: false,
      error: null,
    }
  }
})

onMounted(async () => {
  await Promise.all([
    fetchOperations(),
    fetchPreviewProjects(),
  ])
})
onMounted(() => {
  window.addEventListener('focus', handleReturnToScreen)
  document.addEventListener('visibilitychange', handleReturnToScreen)
})
onBeforeUnmount(() => {
  if (returnRefetchTimer.value !== null) {
    window.clearTimeout(returnRefetchTimer.value)
    returnRefetchTimer.value = null
  }
  window.removeEventListener('focus', handleReturnToScreen)
  document.removeEventListener('visibilitychange', handleReturnToScreen)
})
</script>

<style scoped>
.table-toolbar {
  padding: 12px 16px 8px;
}

.operation-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 2px;
}

.operation-name-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.operation-name-cell__title {
  font-size: 13px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.82);
}

.operation-name-cell__issue {
  font-size: 11px;
  line-height: 1.35;
  color: rgb(var(--v-theme-warning));
}

.no-price-hint {
  color: rgba(0, 0, 0, 0.3);
}

.operations-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 40px 20px;
  text-align: center;
}

.operations-empty-state__title {
  font-size: 16px;
  font-weight: 700;
  color: rgba(0, 0, 0, 0.82);
}

.operations-empty-state__text {
  max-width: 360px;
  font-size: 13px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.56);
}

.summary-error-banner {
  margin: 12px 16px 0;
  padding: 10px 12px;
  border: 1px solid rgba(var(--v-theme-warning), 0.18);
  border-radius: 10px;
  background: rgba(var(--v-theme-warning), 0.06);
  color: rgba(0, 0, 0, 0.68);
  font-size: 12px;
  font-weight: 500;
  line-height: 1.4;
}

.preview-project-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
  padding: 10px 14px;
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.72);
}

.preview-project-bar__copy {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.preview-project-bar__label {
  font-size: 13px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.66);
  white-space: nowrap;
}

.preview-project-bar__hint,
.quick-create-dialog__subhint {
  font-size: 12px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.54);
}

.preview-project-bar__select {
  max-width: 360px;
  min-width: 260px;
}

/* ── Drawer ── */
.drawer-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: 16px;
  gap: 8px;
}

.drawer-header__main {
  min-width: 0;
}

.drawer-header__actions {
  display: flex;
  align-items: center;
  gap: 4px;
}

.drawer-title {
  font-size: 15px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.85);
  margin-bottom: 2px;
}

.drawer-subtitle {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.45);
}

.drawer-body {
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 0;
}

/* Meta */
.meta-row {
  display: flex;
  flex-wrap: wrap;
  gap: 24px;
  margin-bottom: 16px;
}

.meta-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.meta-label {
  font-size: 11px;
  color: rgba(0, 0, 0, 0.45);
  text-transform: uppercase;
  letter-spacing: 0.4px;
}

.meta-value {
  font-size: 13px;
  font-weight: 500;
}

.section-kicker {
  font-size: 11px;
  line-height: 1.4;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  color: rgba(0, 0, 0, 0.48);
  margin-bottom: 8px;
}

.calculation-block {
  padding: 14px 16px;
  border-radius: 12px;
  border: 1px solid rgba(var(--v-theme-primary), 0.18);
  background:
    linear-gradient(135deg, rgba(var(--v-theme-primary), 0.06), rgba(255, 255, 255, 0.9));
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.calculation-block__formula {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.calculation-block__formula-main {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.calculation-block__scenario,
.calculation-block__price {
  font-size: 18px;
  line-height: 1.35;
  font-weight: 700;
  color: rgba(0, 0, 0, 0.84);
}

.calculation-block__operator {
  font-size: 16px;
  font-weight: 700;
  color: rgba(0, 0, 0, 0.42);
}

.calculation-block__source-inline,
.calculation-block__formula-note {
  font-size: 13px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.56);
}

.calculation-block__muted {
  font-size: 16px;
  line-height: 1.4;
  color: rgba(0, 0, 0, 0.4);
}

.calculation-block__actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.calculation-block__status {
  padding-top: 2px;
  font-size: 13px;
  line-height: 1.45;
  color: rgb(var(--v-theme-warning));
}

.calculation-block__empty {
  padding: 10px 12px;
  border-radius: 10px;
  border: 1px solid rgba(var(--v-theme-warning), 0.18);
  background: rgba(var(--v-theme-warning), 0.06);
}

.calculation-block__empty-title {
  font-size: 13px;
  font-weight: 700;
  color: rgba(0, 0, 0, 0.8);
}

.calculation-block__empty-text {
  margin-top: 4px;
  font-size: 12px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.58);
}

.calculation-preview {
  padding: 10px 12px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.72);
  border: 1px solid rgba(0, 0, 0, 0.06);
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.calculation-preview__title {
  font-size: 12px;
  font-weight: 700;
  color: rgba(0, 0, 0, 0.66);
}

.calculation-preview__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  font-size: 13px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.58);
}

.calculation-preview__row strong {
  color: rgba(0, 0, 0, 0.84);
  font-weight: 700;
  text-align: right;
}

.calculation-preview__fallback {
  font-size: 13px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.5);
}

.drawer-details-panels {
  margin-top: 14px;
}

.drawer-details-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
  margin-bottom: 16px;
}

.detail-card {
  padding: 12px 14px;
  border-radius: 10px;
  border: 1px solid rgba(0, 0, 0, 0.08);
  background: rgba(0, 0, 0, 0.02);
}

.detail-card__title {
  font-size: 13px;
  font-weight: 700;
  color: rgba(0, 0, 0, 0.76);
  margin-bottom: 10px;
}

.detail-card__rows {
  display: grid;
  gap: 8px;
}

.detail-card__row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  font-size: 12px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.58);
}

.detail-card__row strong {
  text-align: right;
  color: rgba(0, 0, 0, 0.82);
  font-weight: 600;
}

.delete-dialog__body {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.quick-create-dialog {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.quick-create-dialog__hint {
  font-size: 13px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.58);
}

.quick-create-dialog__options {
  display: grid;
  gap: 10px;
}

.quick-create-option {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 14px 16px;
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.92);
  text-align: left;
  transition: border-color 0.2s ease, background 0.2s ease;
}

.quick-create-option:hover:not(:disabled) {
  border-color: rgba(var(--v-theme-primary), 0.32);
  background: rgba(var(--v-theme-primary), 0.05);
}

.quick-create-option:disabled {
  opacity: 0.6;
}

.quick-create-option__bullet {
  font-size: 18px;
  line-height: 1;
  color: rgba(var(--v-theme-primary), 0.96);
}

.quick-create-option__label {
  font-size: 14px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.82);
}

.applicability-card {
  border: 1px solid rgba(var(--v-theme-secondary), 0.18);
  background: rgba(var(--v-theme-secondary), 0.05);
  border-radius: 10px;
  padding: 16px;
}

.applicability-card__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.applicability-card__chips {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 6px;
}

.applicability-card__title {
  font-size: 14px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.82);
}

.applicability-card__subtitle {
  margin-top: 4px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.5);
}

.applicability-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
}

.applicability-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 120px;
  padding: 24px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.6);
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}

.applicability-model-surface {
  position: relative;
}

.applicability-field-hint {
  margin-top: -6px;
  font-size: 12px;
  line-height: 1.4;
  color: rgba(0, 0, 0, 0.5);
}

.applicability-condition-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

.applicability-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-top: 16px;
}

.applicability-save-hint {
  max-width: 320px;
  font-size: 12px;
  line-height: 1.4;
  color: rgba(0, 0, 0, 0.5);
}

@media (max-width: 640px) {
  .preview-project-bar {
    flex-direction: column;
    align-items: stretch;
  }

  .preview-project-bar__select {
    max-width: none;
    min-width: 0;
  }

  .calculation-block__formula-main {
    align-items: flex-start;
  }

  .detail-card__row {
    flex-direction: column;
  }

  .applicability-condition-row {
    grid-template-columns: 1fr;
  }
}

</style>
