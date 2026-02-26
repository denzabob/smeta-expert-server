<template>
  <div>
    <!-- 0. Общие -->
    <div v-if="activeSection === 0" class="section-content">
      <div class="section-title">Общие</div>
      <div class="section-hint">Базовые настройки, подставляемые в новые проекты</div>

      <div class="settings-list">
        <div class="setting-row">
          <div class="setting-info">
            <div class="setting-label">ФИО эксперта</div>
            <div class="setting-description">Подставляется в поле «Эксперт» при создании проекта</div>
          </div>
          <div class="setting-control" style="min-width: 300px;">
            <v-text-field
              v-model="form.default_expert_name"
              placeholder="Иванов Иван Иванович"
              hide-details
              :density="dense ? 'compact' : 'default'"
            />
          </div>
        </div>

        <div class="setting-row">
          <div class="setting-info">
            <div class="setting-label">№ дела по умолчанию</div>
            <div class="setting-description">Префикс или шаблон номера дела для новых проектов</div>
          </div>
          <div class="setting-control" style="min-width: 300px;">
            <v-text-field
              v-model="form.default_number"
              placeholder="Д-2026/"
              hide-details
              :density="dense ? 'compact' : 'default'"
            />
          </div>
        </div>

        <div class="setting-row">
          <div class="setting-info">
            <div class="setting-label">Регион</div>
            <div class="setting-description">Используется для расчёта ставок по профилям нормируемых работ</div>
          </div>
          <div class="setting-control" style="min-width: 300px;">
            <v-autocomplete
              v-model="form.region_id"
              :items="regions"
              item-title="name"
              item-value="id"
              clearable
              placeholder="Выберите регион"
              hide-details
              :density="dense ? 'compact' : 'default'"
              :menu-props="{ maxHeight: 300 }"
            />
          </div>
        </div>

        <div class="setting-row">
          <div class="setting-info">
            <div class="setting-label">Режим расчёта</div>
            <div class="setting-description">Влияет на таблицу материалов и итоговую стоимость</div>
          </div>
          <div class="setting-control">
            <div class="d-flex align-center gap-2">
              <span class="text-body-2" :style="{ color: !form.use_area_calc_mode ? '#1976d2' : '#999' }">По листам</span>
              <v-switch
                color="primary"
                v-model="form.use_area_calc_mode"
                hide-details
                density="compact"
              />
              <span class="text-body-2" :style="{ color: form.use_area_calc_mode ? '#1976d2' : '#999' }">По площади</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 1. Базовые коэффициенты -->
    <div v-else-if="activeSection === 1" class="section-content">
      <div class="section-title">Базовые коэффициенты</div>
      <div class="section-hint">Глобальные множители для новых проектов</div>

      <div class="settings-list">
        <div class="setting-row">
          <div class="setting-info">
            <div class="setting-label">Коэффициент отходов (общий)</div>
            <div class="setting-description">Множитель количества материала. 1.00 = без изменения</div>
          </div>
          <div class="setting-control" style="max-width: 140px;">
            <v-text-field
              v-model.number="form.waste_coefficient"
              type="number"
              step="0.01"
              min="0"
              hide-details
              :density="dense ? 'compact' : 'default'"
            />
          </div>
        </div>

        <div class="setting-row">
          <div class="setting-info">
            <div class="setting-label">Коэффициент ремонтопригодности</div>
            <div class="setting-description">Учитывает износ и необходимость замены. 1.00 = без изменения</div>
          </div>
          <div class="setting-control" style="max-width: 140px;">
            <v-text-field
              v-model.number="form.repair_coefficient"
              type="number"
              step="0.01"
              min="0"
              hide-details
              :density="dense ? 'compact' : 'default'"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- 2. Материалы по умолчанию -->
    <div v-else-if="activeSection === 2" class="section-content">
      <div class="section-title">Материалы по умолчанию</div>
      <div class="section-hint">Будут подставляться при добавлении новых позиций</div>

      <div class="settings-list">
        <div class="setting-row">
          <div class="setting-info">
            <div class="setting-label">Листовой материал</div>
            <div class="setting-description">Материал типа «плита» по умолчанию для новых позиций</div>
          </div>
          <div class="setting-control" style="min-width: 300px;">
            <v-autocomplete
              v-model="form.default_plate_material_id"
              :items="plateMaterials"
              item-title="name"
              item-value="id"
              clearable
              placeholder="Не выбран"
              hide-details
              :density="dense ? 'compact' : 'default'"
            />
          </div>
        </div>

        <div class="setting-row">
          <div class="setting-info">
            <div class="setting-label">Кромочный материал</div>
            <div class="setting-description">Материал типа «кромка» по умолчанию для новых позиций</div>
          </div>
          <div class="setting-control" style="min-width: 300px;">
            <v-autocomplete
              v-model="form.default_edge_material_id"
              :items="edgeMaterials"
              item-title="name"
              item-value="id"
              clearable
              placeholder="Не выбран"
              hide-details
              :density="dense ? 'compact' : 'default'"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- 3. Коэффициенты по типам -->
    <div v-else-if="activeSection === 3" class="section-content">
      <div class="section-title">Коэффициенты по типам</div>
      <div class="section-hint">Отдельные настройки для плитных, кромки и операций</div>

      <v-row class="waste-type-grid" dense>
        <v-col cols="12" md="4">
          <v-card variant="outlined" class="waste-type-card">
            <v-card-title class="text-subtitle-2">Плитные материалы</v-card-title>
            <v-card-text class="pt-1">
              <div class="text-caption text-medium-emphasis mb-2">Множитель для листовых материалов</div>
              <v-text-field
                v-model.number="form.waste_plate_coefficient"
                type="number"
                step="0.01"
                min="1"
                density="compact"
                hide-details
                placeholder="1.00"
                label="Коэффициент"
                class="mb-2"
              />
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="text-caption">Применять</span>
                <v-switch color="primary" v-model="form.apply_waste_to_plate" hide-details density="compact" />
              </div>
              <div class="d-flex align-center justify-space-between">
                <span class="text-caption">Описание в отчёте</span>
                <div class="d-flex align-center gap-2">
                  <v-switch
                    v-model="form.show_waste_plate_description"
                    :disabled="!plateDesc.title && !plateDesc.text"
                    hide-details
                    density="compact"
                   color="primary" />
                  <v-btn size="x-small" variant="outlined" @click="openDescriptionDialog('plate')">Редактировать</v-btn>
                </div>
              </div>
            </v-card-text>
          </v-card>
        </v-col>

        <v-col cols="12" md="4">
          <v-card variant="outlined" class="waste-type-card">
            <v-card-title class="text-subtitle-2">Кромочные материалы</v-card-title>
            <v-card-text class="pt-1">
              <div class="text-caption text-medium-emphasis mb-2">Множитель для кромочных материалов</div>
              <v-text-field
                v-model.number="form.waste_edge_coefficient"
                type="number"
                step="0.01"
                min="1"
                density="compact"
                hide-details
                placeholder="1.00"
                label="Коэффициент"
                class="mb-2"
              />
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="text-caption">Применять</span>
                <v-switch color="primary" v-model="form.apply_waste_to_edge" hide-details density="compact" />
              </div>
              <div class="d-flex align-center justify-space-between">
                <span class="text-caption">Описание в отчёте</span>
                <div class="d-flex align-center gap-2">
                  <v-switch
                    v-model="form.show_waste_edge_description"
                    :disabled="!edgeDesc.title && !edgeDesc.text"
                    hide-details
                    density="compact"
                   color="primary" />
                  <v-btn size="x-small" variant="outlined" @click="openDescriptionDialog('edge')">Редактировать</v-btn>
                </div>
              </div>
            </v-card-text>
          </v-card>
        </v-col>

        <v-col cols="12" md="4">
          <v-card variant="outlined" class="waste-type-card">
            <v-card-title class="text-subtitle-2">Операции</v-card-title>
            <v-card-text class="pt-1">
              <div class="text-caption text-medium-emphasis mb-2">Множитель для операций</div>
              <v-text-field
                v-model.number="form.waste_operations_coefficient"
                type="number"
                step="0.01"
                min="1"
                density="compact"
                hide-details
                placeholder="1.00"
                label="Коэффициент"
                class="mb-2"
              />
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="text-caption">Применять</span>
                <v-switch color="primary" v-model="form.apply_waste_to_operations" hide-details density="compact" />
              </div>
              <div class="d-flex align-center justify-space-between">
                <span class="text-caption">Описание в отчёте</span>
                <div class="d-flex align-center gap-2">
                  <v-switch
                    v-model="form.show_waste_operations_description"
                    :disabled="!opsDesc.title && !opsDesc.text"
                    hide-details
                    density="compact"
                   color="primary" />
                  <v-btn size="x-small" variant="outlined" @click="openDescriptionDialog('operations')">Редактировать</v-btn>
                </div>
              </div>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>

      <!-- Description dialog -->
      <v-dialog v-model="showDescDialog" max-width="500">
        <v-card :title="descDialogTitle">
          <v-card-text>
            <v-text-field v-model="editingDesc.title" label="Заголовок" class="mb-4" />
            <RichTextEditor v-model="editingDesc.text" label="Текст описания" />
          </v-card-text>
          <v-card-actions>
            <v-spacer></v-spacer>
            <v-btn variant="text" @click="showDescDialog = false">Закрыть</v-btn>
            <v-btn color="primary" variant="flat" @click="saveDescription">Сохранить</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>
    </div>

    <!-- 4. Справочные блоки -->
    <div v-else-if="activeSection === 4" class="section-content">
      <div class="section-title">Справочные блоки</div>
      <div class="section-hint">Дополнительные текстовые блоки в конце PDF-отчёта</div>

      <div class="settings-list">
        <div class="setting-row">
          <div class="setting-info" style="flex: 1;">
            <div class="d-flex align-center justify-space-between">
              <div class="setting-label">Блоки ({{ form.text_blocks?.length || 0 }}/10)</div>
              <v-btn size="small" variant="flat" color="primary" @click="addTextBlock" :disabled="(form.text_blocks?.length || 0) >= 10">
                Добавить блок
              </v-btn>
            </div>
          </div>
        </div>
      </div>

      <div v-if="!form.text_blocks || form.text_blocks.length === 0" class="text-body-2 text-medium-emphasis mt-3 ml-1">
        Блоков пока нет.
      </div>

      <div v-else class="d-flex flex-column mt-2" style="gap: 12px;">
        <div
          v-for="(block, idx) in form.text_blocks"
          :key="idx"
          class="text-block-item"
        >
          <div class="d-flex align-center justify-space-between mb-2">
            <div class="d-flex align-center gap-2">
              <span class="text-caption font-weight-bold">Блок {{ idx + 1 }}</span>
              <v-switch color="primary" v-model="block.enabled" hide-details density="compact" />
            </div>
            <div class="d-flex align-center gap-1">
              <v-select
                :model-value="idx + 1"
                :items="positionItems(form.text_blocks!.length)"
                label="Позиция"
                density="compact"
                hide-details
                style="max-width: 120px;"
                @update:model-value="(v) => moveTextBlockTo(idx, Number(v) - 1)"
              />
              <v-btn icon size="x-small" variant="text" @click="moveTextBlockUp(idx)" :disabled="idx === 0">
                <v-icon>mdi-arrow-up</v-icon>
              </v-btn>
              <v-btn icon size="x-small" variant="text" @click="moveTextBlockDown(idx)" :disabled="idx === (form.text_blocks!.length - 1)">
                <v-icon>mdi-arrow-down</v-icon>
              </v-btn>
              <v-btn icon size="x-small" variant="text" color="error" @click="removeTextBlock(idx)">
                <v-icon>mdi-delete</v-icon>
              </v-btn>
            </div>
          </div>
          <v-text-field
            v-model="block.title"
            label="Заголовок"
            counter="100"
            maxlength="100"
            :disabled="block.enabled === false"
            density="compact"
            class="mb-2"
          />
          <RichTextEditor
            v-model="block.text"
            label="Текст"
            :disabled="block.enabled === false"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export interface CoefficientDescription {
  title: string
  text: string
}

export interface TextBlock {
  title: string
  text: string
  enabled?: boolean
}

export interface ProjectDefaultsData {
  region_id: number | null
  use_area_calc_mode: boolean
  waste_coefficient: number
  repair_coefficient: number
  default_plate_material_id: number | null
  default_edge_material_id: number | null
  default_expert_name: string
  default_number: string
  waste_plate_coefficient: number | null
  waste_edge_coefficient: number | null
  waste_operations_coefficient: number | null
  apply_waste_to_plate: boolean
  apply_waste_to_edge: boolean
  apply_waste_to_operations: boolean
  waste_plate_description: CoefficientDescription | null
  waste_edge_description: CoefficientDescription | null
  waste_operations_description: CoefficientDescription | null
  show_waste_plate_description: boolean
  show_waste_edge_description: boolean
  show_waste_operations_description: boolean
  text_blocks: TextBlock[]
}

export interface Material {
  id: number
  name: string
  type: 'plate' | 'edge'
}

export interface Region {
  id: number
  name: string
}

/** Белый список полей, которые являются дефолтами проекта */
export const PROJECT_DEFAULTS_FIELDS: (keyof ProjectDefaultsData)[] = [
  'region_id',
  'use_area_calc_mode',
  'waste_coefficient',
  'repair_coefficient',
  'default_plate_material_id',
  'default_edge_material_id',
  'default_expert_name',
  'default_number',
  'waste_plate_coefficient',
  'waste_edge_coefficient',
  'waste_operations_coefficient',
  'apply_waste_to_plate',
  'apply_waste_to_edge',
  'apply_waste_to_operations',
  'waste_plate_description',
  'waste_edge_description',
  'waste_operations_description',
  'show_waste_plate_description',
  'show_waste_edge_description',
  'show_waste_operations_description',
  'text_blocks',
]
</script>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import RichTextEditor from '@/components/notifications/RichTextEditor.vue'

const props = defineProps<{
  activeSection: number
  form: ProjectDefaultsData
  regions: Region[]
  materials: Material[]
  /** Compact mode (for drawer) */
  dense?: boolean
  searchQuery?: string
}>()

const emit = defineEmits<{
  'update:form': [data: ProjectDefaultsData]
}>()

const form = computed({
  get: () => props.form,
  set: (val) => emit('update:form', val)
})

const plateMaterials = computed(() => props.materials.filter(m => m.type === 'plate'))
const edgeMaterials = computed(() => props.materials.filter(m => m.type === 'edge'))

// Description reactive refs
const normalizeDesc = (value: any): CoefficientDescription => {
  if (value && typeof value === 'object') {
    return { title: String(value.title ?? ''), text: String(value.text ?? '') }
  }
  return { title: '', text: '' }
}

const plateDesc = ref<CoefficientDescription>({ title: '', text: '' })
const edgeDesc = ref<CoefficientDescription>({ title: '', text: '' })
const opsDesc = ref<CoefficientDescription>({ title: '', text: '' })

watch(() => form.value.waste_plate_description, (v) => { plateDesc.value = normalizeDesc(v) }, { immediate: true })
watch(() => form.value.waste_edge_description, (v) => { edgeDesc.value = normalizeDesc(v) }, { immediate: true })
watch(() => form.value.waste_operations_description, (v) => { opsDesc.value = normalizeDesc(v) }, { immediate: true })

// Description dialog
const showDescDialog = ref(false)
const editingDescType = ref<'plate' | 'edge' | 'operations'>('plate')
const editingDesc = ref<CoefficientDescription>({ title: '', text: '' })

const descDialogTitles: Record<string, string> = {
  plate: 'Описание плитных материалов',
  edge: 'Описание кромочных материалов',
  operations: 'Описание операций',
}

const descDialogTitle = computed(() => descDialogTitles[editingDescType.value] || '')

const openDescriptionDialog = (type: 'plate' | 'edge' | 'operations') => {
  editingDescType.value = type
  const current = type === 'plate' ? plateDesc.value
    : type === 'edge' ? edgeDesc.value
    : opsDesc.value
  editingDesc.value = { ...current }
  showDescDialog.value = true
}

const saveDescription = () => {
  const title = editingDesc.value.title.trim()
  const text = editingDesc.value.text.trim()
  const descOrNull = title || text ? { title, text } : null

  if (editingDescType.value === 'plate') {
    form.value.waste_plate_description = descOrNull
    form.value.show_waste_plate_description = !!descOrNull
  } else if (editingDescType.value === 'edge') {
    form.value.waste_edge_description = descOrNull
    form.value.show_waste_edge_description = !!descOrNull
  } else {
    form.value.waste_operations_description = descOrNull
    form.value.show_waste_operations_description = !!descOrNull
  }

  showDescDialog.value = false
}

// Text blocks
const addTextBlock = () => {
  if (!form.value.text_blocks) {
    form.value.text_blocks = []
  }
  if (form.value.text_blocks.length >= 10) return
  form.value.text_blocks.push({ title: '', text: '', enabled: true })
}

const removeTextBlock = (index: number) => {
  form.value.text_blocks?.splice(index, 1)
}

const moveTextBlockUp = (index: number) => {
  if (!form.value.text_blocks || index <= 0) return
  const item = form.value.text_blocks[index]!
  form.value.text_blocks.splice(index, 1)
  form.value.text_blocks.splice(index - 1, 0, item)
}

const moveTextBlockDown = (index: number) => {
  if (!form.value.text_blocks || index >= form.value.text_blocks.length - 1) return
  const item = form.value.text_blocks[index]!
  form.value.text_blocks.splice(index, 1)
  form.value.text_blocks.splice(index + 1, 0, item)
}

const moveTextBlockTo = (fromIndex: number, toIndex: number) => {
  if (!form.value.text_blocks) return
  const total = form.value.text_blocks.length
  if (fromIndex < 0 || fromIndex >= total || toIndex < 0 || toIndex >= total || fromIndex === toIndex) return
  const item = form.value.text_blocks[fromIndex]!
  form.value.text_blocks.splice(fromIndex, 1)
  form.value.text_blocks.splice(toIndex, 0, item)
}

const positionItems = (total: number) => {
  return Array.from({ length: total }, (_, i) => ({
    title: String(i + 1),
    value: i + 1,
  }))
}
</script>

<style scoped>
.section-content {
  display: flex;
  flex-direction: column;
}

.section-title {
  font-size: 1.1rem;
  font-weight: 600;
  margin-bottom: 4px;
}

.section-hint {
  font-size: 0.875rem;
  opacity: 0.75;
  margin-bottom: 16px;
}

.settings-list {
  display: flex;
  flex-direction: column;
}

.setting-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 0;
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
  gap: 24px;
}

.setting-row:last-child {
  border-bottom: none;
}

.setting-info {
  flex: 1;
  min-width: 0;
}

.setting-label {
  font-size: 0.875rem;
  font-weight: 500;
  line-height: 1.3;
}

.setting-description {
  font-size: 0.8rem;
  opacity: 0.6;
  margin-top: 2px;
  line-height: 1.3;
}

.setting-control {
  flex-shrink: 0;
}

.text-block-item {
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 8px;
  padding: 12px;
}

.waste-type-grid {
  margin-top: 2px;
}

.waste-type-card {
  height: 100%;
}

.gap-1 { gap: 4px; }
.gap-2 { gap: 8px; }
</style>

