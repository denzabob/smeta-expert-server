<template>
  <v-dialog :model-value="modelValue" @update:model-value="$emit('update:modelValue', $event)" max-width="640" scrollable persistent>
    <v-card>
      <v-card-title class="d-flex align-center">
        <v-icon class="mr-2" color="primary">mdi-pencil</v-icon>
        Редактирование материала
        <v-spacer />
        <v-btn icon="mdi-close" variant="text" size="small" @click="close" :disabled="saving" />
      </v-card-title>

      <v-divider />

      <v-card-text>
        <v-form ref="formRef" @submit.prevent="save">
          <!-- Name -->
          <v-text-field
            v-model="form.name"
            label="Название *"
            :rules="[rules.required]"
            counter="500"
            class="mb-2"
          />

          <!-- Article -->
          <v-text-field
            v-model="form.article"
            label="Артикул"
            counter="255"
            class="mb-2"
          />

          <!-- Type + Unit row -->
          <v-row dense class="mb-2">
            <v-col cols="6">
              <v-select
                v-model="form.type"
                :items="typeOptions"
                item-title="label"
                item-value="value"
                label="Тип *"
                :rules="[rules.required]"
              />
            </v-col>
            <v-col cols="6">
              <v-text-field
                v-model="form.unit"
                label="Ед. измерения *"
                :rules="[rules.required]"
                hint="м², м.п., шт"
                persistent-hint
              />
            </v-col>
          </v-row>

          <!-- Dimensions row (hidden for hardware) -->
          <template v-if="form.type !== 'hardware'">
            <div class="text-subtitle-2 font-weight-bold mb-2 mt-2">
              <v-icon size="small" class="mr-1">mdi-ruler</v-icon>
              {{ form.type === 'edge' ? 'Размеры кромки' : 'Размеры' }}
            </div>
            <v-row dense class="mb-2">
              <!-- Plate: Толщина / Длина / Ширина -->
              <template v-if="form.type === 'plate' || form.type === 'facade'">
                <v-col cols="4">
                  <v-text-field
                    v-model.number="form.thickness_mm"
                    label="Толщина (мм)"
                    type="number"
                    :rules="[rules.positiveOrNull]"
                    hide-details
                  />
                </v-col>
                <v-col cols="4">
                  <v-text-field
                    v-model.number="form.length_mm"
                    label="Длина (мм)"
                    type="number"
                    :rules="[rules.positiveIntOrNull]"
                    hide-details
                  />
                </v-col>
                <v-col cols="4">
                  <v-text-field
                    v-model.number="form.width_mm"
                    label="Ширина (мм)"
                    type="number"
                    :rules="[rules.positiveIntOrNull]"
                    hide-details
                  />
                </v-col>
              </template>
              <!-- Edge: Ширина кромки (→length_mm) / Толщина кромки (→width_mm) -->
              <template v-else-if="form.type === 'edge'">
                <v-col cols="6">
                  <v-text-field
                    v-model.number="form.length_mm"
                    label="Ширина кромки (мм)"
                    type="number"
                    :rules="[rules.positiveIntOrNull]"
                    hint="Хранится в поле length_mm"
                    persistent-hint
                  />
                </v-col>
                <v-col cols="6">
                  <v-text-field
                    v-model.number="form.width_mm"
                    label="Толщина кромки (мм)"
                    type="number"
                    :rules="[rules.positiveOrNull]"
                    hint="Хранится в поле width_mm"
                    persistent-hint
                  />
                </v-col>
              </template>
            </v-row>
          </template>

          <!-- Additional fields -->
          <v-row dense class="mb-2">
            <v-col :cols="form.type === 'hardware' ? 12 : 6">
              <v-text-field
                v-model="form.material_tag"
                label="Тег"
                counter="100"
              />
            </v-col>
            <v-col v-if="form.type !== 'hardware'" cols="6">
              <v-text-field
                v-model.number="form.waste_factor"
                label="Коэф. отхода"
                type="number"
                step="0.01"
                min="0"
                max="1"
                hint="0.00 – 1.00"
                persistent-hint
                :rules="[rules.wasteRange]"
              />
            </v-col>
          </v-row>

          <!-- Source URL -->
          <v-text-field
            v-model="form.source_url"
            label="URL источника"
            type="url"
            prepend-inner-icon="mdi-link"
            class="mb-2"
          />

          <!-- Visibility + Region -->
          <v-row dense>
            <v-col cols="6">
              <v-select
                v-model="form.visibility"
                :items="visibilityOptions"
                item-title="label"
                item-value="value"
                label="Видимость"
              />
            </v-col>
            <v-col cols="6">
              <v-select
                v-model="form.region_id"
                :items="regionOptions"
                item-title="label"
                item-value="value"
                label="Регион"
                clearable
              />
            </v-col>
          </v-row>
        </v-form>

        <v-alert v-if="errorMsg" type="error" density="compact" class="mt-2" closable @click:close="errorMsg = ''">
          {{ errorMsg }}
        </v-alert>
      </v-card-text>

      <v-divider />

      <v-card-actions>
        <v-btn variant="text" @click="close" :disabled="saving">Отмена</v-btn>
        <v-spacer />
        <v-btn color="primary" variant="flat" :loading="saving" @click="save">
          <v-icon class="mr-1">mdi-content-save</v-icon>
          Сохранить
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { updateCatalogMaterial } from '@/api/materialCatalog'
import type { MaterialDetail, UpdateMaterialPayload, MaterialUnit } from '@/api/materialCatalog'

const props = defineProps<{
  modelValue: boolean
  material: MaterialDetail | null
  regions: Array<{ id: number; region_name: string }>
}>()

const emit = defineEmits<{
  'update:modelValue': [val: boolean]
  saved: []
}>()

const formRef = ref()
const saving = ref(false)
const errorMsg = ref('')

const form = ref<UpdateMaterialPayload>({
  name: '',
  article: null,
  type: 'plate',
  unit: 'м²',
  source_url: null,
  visibility: 'private',
  thickness_mm: null,
  length_mm: null,
  width_mm: null,
  waste_factor: null,
  material_tag: null,
  region_id: null,
})

const typeOptions = [
  { label: 'Плита', value: 'plate' },
  { label: 'Кромка', value: 'edge' },
  { label: 'Фасад', value: 'facade' },
  { label: 'Фурнитура', value: 'hardware' },
]

const visibilityOptions = [
  { label: 'Приватный', value: 'private' },
  { label: 'Публичный', value: 'public' },
]

const regionOptions = computed(() =>
  (props.regions || []).map(r => ({ label: r.region_name, value: r.id }))
)

const typeUnitMap: Record<string, MaterialUnit> = {
  plate: 'м²',
  edge: 'м.п.',
  hardware: 'шт',
  facade: 'м²',
}

// Auto-set unit and clear irrelevant fields when type changes
watch(() => form.value.type, (newType, oldType) => {
  if (!newType || !oldType || newType === oldType) return
  // Auto-set unit
  if (newType in typeUnitMap) {
    form.value.unit = typeUnitMap[newType as keyof typeof typeUnitMap]
  }
  // Clear dimensions for hardware
  if (newType === 'hardware') {
    form.value.thickness_mm = null
    form.value.length_mm = null
    form.value.width_mm = null
    form.value.waste_factor = null
  }
  // For edge, clear thickness_mm (only length_mm and width_mm are used)
  if (newType === 'edge') {
    form.value.thickness_mm = null
  }
})

const rules = {
  required: (v: any) => !!v || 'Обязательное поле',
  positiveOrNull: (v: any) => v === null || v === '' || v === undefined || Number(v) >= 0 || 'Должно быть ≥ 0',
  positiveIntOrNull: (v: any) => v === null || v === '' || v === undefined || (Number.isInteger(Number(v)) && Number(v) >= 0) || 'Целое число ≥ 0',
  wasteRange: (v: any) => v === null || v === '' || v === undefined || (Number(v) >= 0 && Number(v) <= 1) || '0 – 1',
}

watch(() => [props.modelValue, props.material] as const, ([open, mat]) => {
  if (open && mat && typeof mat === 'object') {
    form.value = {
      name: mat.name,
      article: mat.article || null,
      type: mat.type,
      unit: mat.unit,
      source_url: mat.source_url || null,
      visibility: mat.visibility,
      thickness_mm: mat.thickness_mm ?? null,
      length_mm: mat.length_mm ?? null,
      width_mm: mat.width_mm ?? null,
      waste_factor: mat.waste_factor ?? null,
      material_tag: mat.material_tag ?? null,
      region_id: mat.region_id ?? null,
    }
    errorMsg.value = ''
  }
})

async function save() {
  const { valid } = await formRef.value.validate()
  if (!valid) return

  if (!props.material) return

  saving.value = true
  errorMsg.value = ''

  try {
    // Build payload — only send changed fields
    const payload: UpdateMaterialPayload = { ...form.value }

    // Clean empty strings to null
    if (payload.article === '') payload.article = null
    if (payload.source_url === '') payload.source_url = null
    if (payload.material_tag === '') payload.material_tag = null

    await updateCatalogMaterial(props.material.id, payload)
    emit('saved')
    close()
  } catch (e: any) {
    errorMsg.value = e.response?.data?.message || e.response?.data?.error || 'Ошибка сохранения'
  } finally {
    saving.value = false
  }
}

function close() {
  emit('update:modelValue', false)
}
</script>
