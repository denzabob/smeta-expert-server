<template>
  <div v-if="!props.compact" class="applicability-card">
    <div class="applicability-card__header">
      <div>
        <div class="applicability-card__title">Правило применения операции</div>
        <div class="applicability-card__subtitle">
          Определяет, когда операция применяется и как считается.
        </div>
      </div>
      <div class="applicability-card__actions">
        <v-chip
          size="x-small"
          :color="props.rule?.is_enabled === false ? 'grey' : 'success'"
          variant="tonal"
        >
          {{ props.rule?.is_enabled === false ? 'Выключено' : 'Включено' }}
        </v-chip>
        <v-chip
          v-if="props.rule?.source"
          size="x-small"
          color="secondary"
          variant="outlined"
        >
          {{ props.rule.source === 'user' ? 'Ваше правило' : 'Системное правило' }}
        </v-chip>
        <v-chip
          v-if="invalidCombinationWarning"
          size="x-small"
          color="warning"
          variant="flat"
        >
          Несовместимо
        </v-chip>
        <v-chip
          v-if="invalidTariffBindingWarning"
          size="x-small"
          color="warning"
          variant="flat"
        >
          Тариф исправлен
        </v-chip>
        <v-btn
          size="small"
          color="primary"
          variant="flat"
          prepend-icon="mdi-pencil"
          :disabled="props.loading || !props.operation"
          @click="openEditor"
        >
          {{ props.triggerLabel }}
        </v-btn>
      </div>
    </div>

    <v-alert
      v-if="props.error"
      type="error"
      variant="tonal"
      density="compact"
      class="mb-4"
    >
      {{ props.error }}
    </v-alert>

    <v-alert
      v-else-if="props.rule?.source === 'system'"
      type="info"
      variant="tonal"
      density="compact"
      class="mb-4"
    >
      Показано базовое системное правило. При сохранении будет создана ваша настройка для этой операции.
    </v-alert>

    <v-alert
      v-if="invalidCombinationWarning"
      type="warning"
      variant="tonal"
      density="compact"
      class="mb-4"
    >
      {{ invalidCombinationWarning }}
    </v-alert>

    <v-alert
      v-if="invalidTariffBindingWarning"
      type="warning"
      variant="tonal"
      density="compact"
      class="mb-4"
    >
      {{ invalidTariffBindingWarning }}
    </v-alert>

    <div v-if="props.loading" class="applicability-loading">
      <v-progress-circular indeterminate size="18" width="2" />
      <span>Загрузка правила...</span>
    </div>

    <div v-else-if="!props.rule" class="applicability-empty">
      <div class="applicability-empty__title">Правило не найдено</div>
      <div class="applicability-empty__hint">
        Для этой операции правило обычно создаётся автоматически, но его всё равно можно задать вручную.
      </div>
    </div>

    <div v-else class="applicability-summary">
      <div class="applicability-summary__row">
        <span class="applicability-summary__label">Применяется к</span>
        <span class="applicability-summary__value">
          {{ appliesToLabel(ruleForDisplay.applies_to) }}
        </span>
      </div>
      <div class="applicability-summary__row">
        <span class="applicability-summary__label">Тип материала</span>
        <span class="applicability-summary__value">
          {{ materialTypeLabel(ruleForDisplay.material_type) }}
        </span>
      </div>
      <div
        v-if="ruleForDisplay.applies_to === 'material_id'"
        class="applicability-summary__row"
      >
        <span class="applicability-summary__label">Материал</span>
        <span class="applicability-summary__value">
          {{ selectedMaterialLabel }}
        </span>
      </div>
      <div v-if="isScenarioBasedAutoKind" class="applicability-summary__row">
        <span class="applicability-summary__label">Как считается</span>
        <span class="applicability-summary__value">
          {{ scenarioFormulaLabel }}
        </span>
      </div>
      <template v-else>
        <div class="applicability-summary__row">
          <span class="applicability-summary__label">Как считается</span>
          <span class="applicability-summary__value">
            {{ quantitySourceLabel(ruleForDisplay.quantity_source) }}
          </span>
        </div>
        <div class="applicability-summary__row">
          <span class="applicability-summary__label">Единица цены</span>
          <span class="applicability-summary__value">{{ normalizePricingUnit(ruleForDisplay.pricing_unit) }}</span>
        </div>
      </template>
      <div
        v-if="ruleForDisplay.quantity_config?.multiplier !== undefined && ruleForDisplay.quantity_config?.multiplier !== null"
        class="applicability-summary__row"
      >
        <span class="applicability-summary__label">Поправочный коэффициент</span>
        <span class="applicability-summary__value">{{ ruleForDisplay.quantity_config.multiplier }}</span>
      </div>
      <div v-if="!isScenarioBasedAutoKind" class="applicability-summary__row">
        <span class="applicability-summary__label">Формула</span>
        <span class="applicability-summary__value">
          {{ quantitySourceDescription(ruleForDisplay.quantity_source) }}
        </span>
      </div>
    </div>
  </div>

  <div v-else class="rule-editor-trigger">
    <v-btn
      size="small"
      color="primary"
      variant="tonal"
      prepend-icon="mdi-pencil"
      :disabled="props.loading || !props.operation"
      @click="openEditor"
    >
      {{ props.triggerLabel }}
    </v-btn>

    <div v-if="props.error" class="rule-editor-trigger__error">
      {{ props.error }}
    </div>
  </div>

  <v-dialog v-model="editorOpen" max-width="720">
      <v-card>
        <v-card-title class="rule-editor__title">Изменить правило</v-card-title>
        <v-card-text class="rule-editor__body">
          <div class="rule-editor__grid">
            <v-select
              v-model="form.applies_to"
              :items="appliesToOptions"
              item-title="label"
              item-value="value"
              label="Применяется к"
              variant="outlined"
              density="compact"
              hide-details
              @update:model-value="handleAppliesToChange"
            />

            <v-select
              v-model="form.material_type"
                :items="availableMaterialTypeOptions"
                item-title="label"
                item-value="value"
                label="Для каких позиций применяется правило"
                variant="outlined"
                density="compact"
                hide-details
                :disabled="isEdgingOperation"
                @update:model-value="handleMaterialTypeChange"
            />

            <div
              v-if="isEdgingOperation"
              class="rule-editor__field-helper rule-editor__field-helper--info"
            >
              Правило применяется к плитной позиции. Толщина берётся из привязанной кромки.
            </div>

            <div v-if="isScenarioBasedAutoKind" class="rule-editor__scenario">
              <div class="rule-editor__scenario-label">Как считается</div>
              <div class="rule-editor__scenario-value">{{ scenarioFormulaLabel }}</div>
            </div>

            <template v-else>
              <v-select
                v-model="form.quantity_source"
                :items="availableQuantitySourceOptions"
                item-title="label"
                item-value="value"
                label="Как считать объём"
                variant="outlined"
                density="compact"
                hide-details
                :disabled="isQuantitySourceStrict"
                @update:model-value="handleQuantitySourceChange"
              />

              <v-select
                v-model="form.pricing_unit"
                :items="availablePricingUnitOptions"
                item-title="label"
                item-value="value"
                label="Единица цены"
                variant="outlined"
                density="compact"
                hide-details
                :disabled="isPricingUnitStrict"
              />
            </template>

            <div v-if="isEdgingOperation" class="rule-editor__thickness-block">
              <div class="rule-editor__thickness-title">Диапазон толщины кромки</div>
              <div class="rule-editor__thickness-text">
                Обязательное условие для кромления. Толщина берётся из кромочного материала, а не из плиты.
              </div>
              <div class="rule-editor__condition-row">
                <v-text-field
                  v-model.number="form.thickness_min"
                  type="number"
                  min="0"
                  step="0.01"
                  label="Толщина от, мм *"
                  variant="outlined"
                  density="compact"
                  :error-messages="thicknessMinErrors"
                />
                <v-text-field
                  v-model.number="form.thickness_max"
                  type="number"
                  min="0"
                  step="0.01"
                  label="Толщина до, мм *"
                  variant="outlined"
                  density="compact"
                  :error-messages="thicknessMaxErrors"
                />
              </div>
            </div>

            <div class="rule-editor__readonly-info">
              <div class="rule-editor__readonly-info-label">Источник цены</div>
              <div class="rule-editor__readonly-info-title">Источник цены: текущая операция</div>
              <div class="rule-editor__readonly-info-text">
                Цена всегда берётся из цены этой операции.
              </div>
            </div>

            <v-switch
              v-model="form.is_enabled"
              color="success"
              density="compact"
              hide-details
              label="Правило включено"
            />
          </div>

          <v-expansion-panels
            v-model="advancedPanels"
            variant="accordion"
            class="rule-editor__advanced"
          >
            <v-expansion-panel>
              <v-expansion-panel-title>
                Дополнительно
              </v-expansion-panel-title>
              <v-expansion-panel-text>
                <div class="rule-editor__advanced-grid">
                  <v-select
                    v-if="form.applies_to === 'material_id'"
                    v-model="form.material_id"
                    :items="materialSelectItems"
                    item-title="label"
                    item-value="value"
                    label="Конкретный материал"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                    :loading="props.materialsLoading"
                    :disabled="form.applies_to !== 'material_id'"
                  />

                  <v-text-field
                    v-model.number="form.multiplier"
                    type="number"
                    min="0"
                    step="0.01"
                    label="Поправочный коэффициент"
                    variant="outlined"
                    density="compact"
                    hide-details
                    clearable
                  />
                  <div class="rule-editor__field-helper">
                    1 — без изменения, 1.1 — увеличить на 10%, 0.9 — уменьшить на 10%
                  </div>

                  <div v-if="!isEdgingOperation" class="rule-editor__condition-row">
                    <v-text-field
                      v-model.number="form.thickness_min"
                      type="number"
                      min="0"
                      label="Толщина от, мм"
                      variant="outlined"
                      density="compact"
                      hide-details
                      clearable
                    />
                    <v-text-field
                      v-model.number="form.thickness_max"
                      type="number"
                      min="0"
                      label="Толщина до, мм"
                      variant="outlined"
                      density="compact"
                      hide-details
                      clearable
                    />
                  </div>
                </div>
              </v-expansion-panel-text>
            </v-expansion-panel>
          </v-expansion-panels>

          <div class="rule-editor__hints">
            <div class="rule-editor__hint">
              {{ editorFormulaLabel }}
            </div>
            <div
              v-if="softValidationError"
              class="rule-editor__hint rule-editor__hint--warning"
            >
              {{ softValidationError }}
            </div>
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="props.saving" @click="editorOpen = false">
            Отмена
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            :loading="props.saving"
            :disabled="!canSave"
            @click="submit"
          >
            Сохранить правило
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'

type OperationKind = 'cutting' | 'edging' | 'drilling' | 'other'
type QuantitySource = 'position_area_m2' | 'position_quantity' | 'edge_length' | 'holes_count'
type PricingUnit = string
type MaterialType = 'plate' | 'edge' | 'facade' | 'hardware'
type AppliesTo = 'material_type' | 'material_id'

interface OperationRow {
  id: number
  name: string
  unit?: string
  operation_kind?: OperationKind
}

interface MaterialOption {
  id: number
  name: string
  article?: string | null
  type: MaterialType
}

interface OperationApplicationRuleResponse {
  id: number
  operation_id: number
  source: 'user' | 'system'
  is_editable: boolean
  mode: 'automatic'
  applies_to: AppliesTo
  material_type: MaterialType | null
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

interface RulePayload {
  applies_to: AppliesTo
  material_type: MaterialType
  material_id: number | null
  quantity_source: QuantitySource
  pricing_unit: PricingUnit
  quantity_config: { multiplier: number } | null
  conditions: { thickness: { min?: number; max?: number } } | null
  is_enabled: boolean
}

interface EditorForm {
  applies_to: AppliesTo
  material_type: MaterialType
  material_id: number | null
  quantity_source: QuantitySource
  pricing_unit: PricingUnit
  multiplier: number | null
  thickness_min: number | null
  thickness_max: number | null
  is_enabled: boolean
}

const props = withDefaults(defineProps<{
  operation: OperationRow | null
  rule: OperationApplicationRuleResponse | null
  loading: boolean
  saving: boolean
  error: string | null
  materialOptions: MaterialOption[]
  materialsLoading: boolean
  compact?: boolean
  triggerLabel?: string
}>(), {
  compact: false,
  triggerLabel: 'Изменить правило',
})

const emit = defineEmits<{
  save: [payload: RulePayload]
  requestMaterials: [materialType: MaterialType]
}>()

const editorOpen = ref(false)
const pendingSubmit = ref(false)
const advancedPanels = ref<number[]>([])

const appliesToOptions = [
  { label: 'Тип материала', value: 'material_type' },
  { label: 'Конкретный материал', value: 'material_id' },
]

const materialTypeOptions = [
  { label: 'Плитные материалы', value: 'plate' },
  { label: 'Кромочные материалы', value: 'edge' },
  { label: 'Фасады', value: 'facade' },
  { label: 'Фурнитура', value: 'hardware' },
]

const quantitySourceOptions = [
  { label: 'Площадь детали', value: 'position_area_m2' },
  { label: 'Количество деталей', value: 'position_quantity' },
  { label: 'Длина кромки', value: 'edge_length' },
  { label: 'Количество отверстий', value: 'holes_count' },
]

const pricingUnitOptions = [
  { label: 'м²', value: 'м²' },
  { label: 'шт.', value: 'шт.' },
  { label: 'м.п.', value: 'м.п.' },
  { label: 'лист', value: 'лист' },
  { label: 'рез', value: 'рез' },
  { label: 'деталь', value: 'деталь' },
]

const form = reactive<EditorForm>(buildDraft(props.operation, props.rule))

const ruleForDisplay = computed(() => props.rule ?? buildFallbackRule(props.operation))
const isEdgingOperation = computed(() => props.operation?.operation_kind === 'edging')

const materialSelectItems = computed(() => props.materialOptions.map((material) => ({
  value: material.id,
  label: material.article ? `${material.name} (${material.article})` : material.name,
})))

const availableMaterialTypeOptions = computed(() => (
  isEdgingOperation.value
    ? materialTypeOptions.filter((option) => option.value === 'plate')
    : materialTypeOptions
))

const isScenarioBasedAutoKind = computed(() => (
  props.operation?.operation_kind === 'cutting'
  || props.operation?.operation_kind === 'edging'
  || props.operation?.operation_kind === 'drilling'
))
const allowedPricingUnits = computed<PricingUnit[]>(() => allowedPricingUnitsForKind(props.operation?.operation_kind))
const allowedQuantitySources = computed<QuantitySource[]>(() => allowedQuantitySourcesForKind(props.operation?.operation_kind))
const isPricingUnitStrict = computed(() => allowedPricingUnits.value.length > 0)
const isQuantitySourceStrict = computed(() => allowedQuantitySources.value.length > 0)
const availablePricingUnitOptions = computed(() => (
  isPricingUnitStrict.value
    ? pricingUnitOptions.filter((option) => allowedPricingUnits.value.includes(normalizePricingUnit(option.value)))
    : pricingUnitOptions
))
const availableQuantitySourceOptions = computed(() => (
  isQuantitySourceStrict.value
    ? quantitySourceOptions.filter((option) => allowedQuantitySources.value.includes(option.value))
    : quantitySourceOptions
))

const selectedMaterialLabel = computed(() => {
  if (!ruleForDisplay.value?.material_id) return '—'
  const material = props.materialOptions.find((item) => item.id === ruleForDisplay.value?.material_id)
  if (!material) return `Материал #${ruleForDisplay.value.material_id}`
  return material.article ? `${material.name} (${material.article})` : material.name
})

const invalidCombinationWarning = computed(() => getInvalidCombinationWarning(
  props.operation?.operation_kind,
  ruleForDisplay.value?.quantity_source ?? null,
  ruleForDisplay.value?.pricing_unit ?? null,
))

const invalidTariffBindingWarning = computed(() => getInvalidTariffBindingWarning(
  props.operation,
  ruleForDisplay.value,
))

const scenarioFormulaLabel = computed(() => scenarioFormulaForKind(props.operation?.operation_kind))
const editorFormulaLabel = computed(() => (
  isScenarioBasedAutoKind.value
    ? scenarioFormulaLabel.value
    : quantitySourceDescription(form.quantity_source)
))

const thicknessMinErrors = computed(() => {
  if (!isEdgingOperation.value) return []
  if (form.thickness_min === null || form.thickness_min === undefined) {
    return ['Укажите минимальную толщину.']
  }

  return []
})

const thicknessMaxErrors = computed(() => {
  if (!isEdgingOperation.value) return []
  const errors: string[] = []

  if (form.thickness_max === null || form.thickness_max === undefined) {
    errors.push('Укажите максимальную толщину.')
  }

  if (
    form.thickness_min !== null
    && form.thickness_min !== undefined
    && form.thickness_max !== null
    && form.thickness_max !== undefined
    && Number(form.thickness_min) > Number(form.thickness_max)
  ) {
    errors.push('Минимальная толщина должна быть меньше или равна максимальной.')
  }

  return errors
})

const softValidationError = computed(() => {
  if (!form.applies_to) return 'Выберите область применения правила.'
  if (!form.pricing_unit?.trim()) return 'Укажите единицу тарифа.'
  if (form.applies_to === 'material_id' && !form.material_id) return 'Выберите конкретный материал.'
  if (isEdgingOperation.value && form.material_type !== 'plate') {
    return 'Для кромления правило применяется только к плитной позиции.'
  }
  if (isEdgingOperation.value && thicknessMinErrors.value.length > 0) {
    return thicknessMinErrors.value[0]
  }
  if (isEdgingOperation.value && thicknessMaxErrors.value.length > 0) {
    return thicknessMaxErrors.value[0]
  }

  const invalidCombination = getInvalidCombinationWarning(
    props.operation?.operation_kind,
    form.quantity_source,
    form.pricing_unit,
  )
  if (invalidCombination) return invalidCombination

  return ''
})

const canSave = computed(() => !props.saving && !softValidationError.value)

watch(
  () => [props.rule, props.operation] as const,
  ([rule, operation]) => {
    Object.assign(form, buildDraft(operation, rule))
  },
  { immediate: true },
)

watch(
  () => [props.saving, props.error] as const,
  ([saving, error]) => {
    if (!pendingSubmit.value || saving) return

    if (!error) {
      editorOpen.value = false
    }

    pendingSubmit.value = false
  },
)

function normalizePricingUnit(unit?: string | null): PricingUnit {
  if (!unit) return 'шт.'

  const compact = unit.toLowerCase().trim().replace(/[\s.,·]/g, '')
  const map: Record<string, PricingUnit> = {
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

function buildDraft(
  operation: OperationRow | null,
  rule: OperationApplicationRuleResponse | null,
): EditorForm {
  if (rule) {
    const draft = {
      applies_to: rule.applies_to ?? 'material_type',
      material_type: rule.material_type ?? 'plate',
      material_id: rule.material_id ?? null,
      quantity_source: rule.quantity_source,
      pricing_unit: normalizePricingUnit(rule.pricing_unit),
      multiplier: rule.quantity_config?.multiplier ?? null,
      thickness_min: rule.conditions?.thickness?.min ?? null,
      thickness_max: rule.conditions?.thickness?.max ?? null,
      is_enabled: rule.is_enabled,
    }

    return enforceScenarioBindingDraft(draft, operation?.operation_kind)
  }

  if (operation?.operation_kind === 'cutting') {
    return {
      applies_to: 'material_type',
      material_type: 'plate',
      material_id: null,
      quantity_source: 'position_area_m2',
      pricing_unit: 'м²',
      multiplier: null,
      thickness_min: null,
      thickness_max: null,
      is_enabled: true,
    }
  }

  if (operation?.operation_kind === 'edging') {
    return {
      applies_to: 'material_type',
      material_type: 'plate',
      material_id: null,
      quantity_source: 'edge_length',
      pricing_unit: 'м.п.',
      multiplier: null,
      thickness_min: null,
      thickness_max: null,
      is_enabled: true,
    }
  }

  if (operation?.operation_kind === 'drilling') {
    return {
      applies_to: 'material_type',
      material_type: 'plate',
      material_id: null,
      quantity_source: 'holes_count',
      pricing_unit: 'шт.',
      multiplier: null,
      thickness_min: null,
      thickness_max: null,
      is_enabled: true,
    }
  }

  return {
    applies_to: 'material_type',
    material_type: 'plate',
    material_id: null,
    quantity_source: 'position_quantity',
    pricing_unit: normalizePricingUnit(operation?.unit),
    multiplier: null,
    thickness_min: null,
    thickness_max: null,
    is_enabled: true,
  }
}

function buildFallbackRule(operation: OperationRow | null): OperationApplicationRuleResponse | null {
  if (!operation) return null

  const draft = buildDraft(operation, null)

  return {
    id: 0,
    operation_id: operation.id,
    source: 'user',
    is_editable: true,
    mode: 'automatic',
    applies_to: draft.applies_to,
    material_type: draft.material_type,
    material_id: draft.material_id,
    quantity_source: draft.quantity_source,
    pricing_unit: draft.pricing_unit,
    tariff_binding_type: 'operation_resolver',
    tariff_operation_id: operation.id,
    conditions: null,
    quantity_config: draft.multiplier !== null ? { multiplier: draft.multiplier } : null,
    priority: 1,
    is_enabled: draft.is_enabled,
    updated_at: null,
  }
}

function appliesToLabel(value?: AppliesTo | null): string {
  return value === 'material_id' ? 'Конкретный материал' : 'Тип материала'
}

function materialTypeLabel(value?: MaterialType | null): string {
  return materialTypeOptions.find((option) => option.value === value)?.label ?? '—'
}

function quantitySourceLabel(value: QuantitySource): string {
  return quantitySourceOptions.find((option) => option.value === value)?.label ?? value
}

function quantitySourceDescription(value: QuantitySource): string {
  if (value === 'position_area_m2') return 'Площадь детали × тариф'
  if (value === 'edge_length') return 'Длина кромки × тариф'
  if (value === 'holes_count') return 'Количество отверстий × тариф'
  return 'Количество позиции × тариф'
}

function scenarioFormulaForKind(kind?: OperationKind): string {
  if (kind === 'cutting') return 'Площадь детали × тариф'
  if (kind === 'edging') return 'Длина кромки × тариф'
  if (kind === 'drilling') return 'Количество отверстий × тариф'
  return 'Количество позиции × тариф'
}

function allowedPricingUnitsForKind(kind?: OperationKind): PricingUnit[] {
  if (kind === 'cutting') return ['м²']
  if (kind === 'edging') return ['м.п.']
  if (kind === 'drilling') return ['шт.']
  return []
}

function allowedQuantitySourcesForKind(kind?: OperationKind): QuantitySource[] {
  if (kind === 'cutting') return ['position_area_m2']
  if (kind === 'edging') return ['edge_length']
  if (kind === 'drilling') return ['holes_count']
  return []
}

function getInvalidCombinationWarning(
  kind: OperationKind | undefined,
  quantitySource: QuantitySource | null,
  pricingUnit: PricingUnit | null,
): string {
  const strictUnits = allowedPricingUnitsForKind(kind)
  const strictSources = allowedQuantitySourcesForKind(kind)
  const normalizedUnit = normalizePricingUnit(pricingUnit)

  if (strictSources.length > 0 && quantitySource && !strictSources.includes(quantitySource)) {
    return 'Текущее правило использует источник количества, несовместимый с видом операции.'
  }

  if (strictUnits.length > 0 && normalizedUnit && !strictUnits.includes(normalizedUnit)) {
    return 'Текущее правило использует единицу тарифа, несовместимую с видом операции.'
  }

  return ''
}

function getInvalidTariffBindingWarning(
  operation: OperationRow | null,
  rule: OperationApplicationRuleResponse | null,
): string {
  if (!operation || !rule) return ''

  if (rule.tariff_binding_type !== 'operation_resolver') {
    return 'В правиле найдена устаревшая настройка цены. Цена должна браться только из текущей операции.'
  }

  if (rule.tariff_operation_id === null) {
    return 'В правиле отсутствовала настройка источника цены. Она будет восстановлена для текущей операции.'
  }

  if (rule.tariff_operation_id !== operation.id) {
    return 'В правиле найдена устаревшая ссылка на другую операцию. Цена должна браться только из текущей операции.'
  }

  return ''
}

function defaultPricingUnitForQuantitySource(quantitySource: QuantitySource, operation: OperationRow | null): PricingUnit {
  if (quantitySource === 'position_area_m2') return 'м²'
  if (quantitySource === 'edge_length') return 'м.п.'
  return normalizePricingUnit(operation?.unit)
}

function enforceScenarioBindingDraft(draft: EditorForm, kind?: OperationKind): EditorForm {
  if (kind === 'cutting') {
    return {
      ...draft,
      quantity_source: 'position_area_m2',
      pricing_unit: 'м²',
    }
  }

  if (kind === 'edging') {
    return {
      ...draft,
      material_type: 'plate',
      quantity_source: 'edge_length',
      pricing_unit: 'м.п.',
    }
  }

  if (kind === 'drilling') {
    return {
      ...draft,
      quantity_source: 'holes_count',
      pricing_unit: 'шт.',
    }
  }

  return draft
}

function openEditor() {
  Object.assign(form, buildDraft(props.operation, props.rule))
  advancedPanels.value = []

  if (isQuantitySourceStrict.value && !allowedQuantitySources.value.includes(form.quantity_source)) {
    form.quantity_source = allowedQuantitySources.value[0]
  }
  if (isPricingUnitStrict.value && !allowedPricingUnits.value.includes(normalizePricingUnit(form.pricing_unit))) {
    form.pricing_unit = allowedPricingUnits.value[0]
  }

  editorOpen.value = true

  if (form.applies_to === 'material_id') {
    emit('requestMaterials', form.material_type)
  }
}

function handleAppliesToChange() {
  if (form.applies_to !== 'material_id') {
    form.material_id = null
    return
  }

  emit('requestMaterials', form.material_type)
}

function handleMaterialTypeChange() {
  form.material_id = null

  if (form.applies_to === 'material_id') {
    emit('requestMaterials', form.material_type)
  }
}

function handleQuantitySourceChange() {
  if (isPricingUnitStrict.value) {
    form.pricing_unit = allowedPricingUnits.value[0]
    return
  }

  form.pricing_unit = defaultPricingUnitForQuantitySource(form.quantity_source, props.operation)
}

function submit() {
  if (!canSave.value) return

  pendingSubmit.value = true
  const normalizedForm = enforceScenarioBindingDraft({ ...form }, props.operation?.operation_kind)

  const thickness: Record<string, number> = {}
  if (normalizedForm.thickness_min !== null && normalizedForm.thickness_min !== undefined) {
    thickness.min = Number(normalizedForm.thickness_min)
  }
  if (normalizedForm.thickness_max !== null && normalizedForm.thickness_max !== undefined) {
    thickness.max = Number(normalizedForm.thickness_max)
  }

  emit('save', {
    applies_to: normalizedForm.applies_to,
    material_type: normalizedForm.material_type,
    material_id: normalizedForm.applies_to === 'material_id' ? normalizedForm.material_id : null,
    quantity_source: normalizedForm.quantity_source,
    pricing_unit: normalizedForm.pricing_unit,
    quantity_config: normalizedForm.multiplier !== null && normalizedForm.multiplier !== undefined
      ? { multiplier: Number(normalizedForm.multiplier) }
      : null,
    conditions: Object.keys(thickness).length > 0 ? { thickness } : null,
    is_enabled: normalizedForm.is_enabled,
  })
}
</script>

<style scoped>
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

.applicability-card__actions {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  justify-content: flex-end;
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

.applicability-loading,
.applicability-empty {
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: center;
  justify-content: center;
  min-height: 120px;
  padding: 24px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.6);
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}

.applicability-empty__title {
  font-weight: 600;
}

.applicability-empty__hint {
  text-align: center;
  line-height: 1.45;
  max-width: 420px;
}

.applicability-summary {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
}

.applicability-summary__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.68);
}

.applicability-summary__label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.48);
}

.applicability-summary__value {
  text-align: right;
  font-size: 13px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.78);
}

.rule-editor__field-helper {
  font-size: 12px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.58);
}

.rule-editor__field-helper--info {
  margin-top: -4px;
}

.rule-editor__title {
  font-size: 18px;
  font-weight: 700;
}

.rule-editor__thickness-block {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px;
  border-radius: 10px;
  background: rgba(var(--v-theme-primary), 0.05);
  border: 1px solid rgba(var(--v-theme-primary), 0.18);
}

.rule-editor__thickness-title {
  font-size: 13px;
  font-weight: 700;
}

.rule-editor__thickness-text {
  font-size: 12px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.58);
}

.rule-editor__body {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.rule-editor__grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.rule-editor__scenario {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 6px;
  padding: 12px 14px;
  border-radius: 10px;
  background: rgba(var(--v-theme-primary), 0.06);
  border: 1px solid rgba(var(--v-theme-primary), 0.14);
}

.rule-editor__scenario-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.5);
}

.rule-editor__scenario-value {
  font-size: 14px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.82);
}

.rule-editor__advanced {
  background: transparent;
}

.rule-editor__advanced-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
}

.rule-editor__readonly-info {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 4px;
  padding: 12px 14px;
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.03);
  border: 1px solid rgba(0, 0, 0, 0.08);
}

.rule-editor__readonly-info-label,
.rule-editor__field-helper {
  font-size: 12px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.5);
}

.rule-editor__readonly-info-title {
  font-size: 14px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.82);
}

.rule-editor__readonly-info-text {
  font-size: 13px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.62);
}

.rule-editor__condition-row {
  grid-column: 1 / -1;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.rule-editor__hints {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.rule-editor__hint {
  font-size: 12px;
  line-height: 1.45;
  color: rgba(0, 0, 0, 0.5);
}

.rule-editor__hint--warning {
  color: rgb(var(--v-theme-warning));
  font-weight: 500;
}

.rule-editor-trigger {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.rule-editor-trigger__error {
  font-size: 12px;
  line-height: 1.4;
  color: rgb(var(--v-theme-error));
}

@media (max-width: 720px) {
  .applicability-card__header {
    flex-direction: column;
  }

  .applicability-card__actions {
    justify-content: flex-start;
  }

  .rule-editor__grid,
  .rule-editor__condition-row {
    grid-template-columns: 1fr;
  }
}
</style>
