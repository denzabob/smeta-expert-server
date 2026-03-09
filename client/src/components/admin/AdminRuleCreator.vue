<template>
  <div class="rule-creator">
    <v-alert v-if="formError" type="error" variant="tonal" class="mb-4" closable @click:close="formError = ''">
      {{ formError }}
    </v-alert>

    <div class="mb-4">
      <div class="section-label">Пример строки</div>
      <v-sheet border rounded class="pa-3 text-body-2 mono-text">
        {{ preset?.example_input || '—' }}
      </v-sheet>
    </div>

    <v-row dense class="mb-4">
      <v-col cols="12" md="8">
        <v-text-field
          v-model="form.name"
          label="Название правила *"
          density="compact"
          variant="outlined"
          placeholder="plate_custom_rule_01"
          hide-details
        />
      </v-col>
      <v-col cols="12" md="4">
        <v-select
          v-model="form.material_type"
          :items="materialTypeOptions"
          label="Тип материала"
          density="compact"
          variant="outlined"
          hide-details
          clearable
        />
      </v-col>
    </v-row>

    <!-- Numeric blocks section -->
    <div class="mb-4">
      <div class="d-flex align-center mb-2">
        <div class="section-label mb-0">Назначение чисел</div>
        <v-spacer />
        <v-btn
          size="small"
          variant="tonal"
          color="primary"
          prepend-icon="mdi-auto-fix"
          :loading="analyzing"
          @click="analyzeExample"
        >
          Разобрать
        </v-btn>
      </div>
      
      <v-alert v-if="numericBlocks.length === 0" type="info" variant="tonal" density="compact">
        Нажмите "Разобрать" для извлечения числовых значений
      </v-alert>

      <div v-else class="blocks-grid">
        <div
          v-for="block in numericBlocks"
          :key="block.id"
          class="block-item"
        >
          <v-chip
            :color="roleColor(block.role)"
            variant="flat"
            size="small"
            class="block-value"
          >
            {{ block.raw }}
          </v-chip>
          <v-select
            v-model="block.role"
            :items="roleOptions"
            density="compact"
            variant="outlined"
            hide-details
            class="block-role"
            @update:model-value="syncFromBlocks"
          />
        </div>
      </div>
    </div>

    <!-- Manual override -->
    <div class="mb-4">
      <div class="section-label">Размеры (мм)</div>
      <v-row dense>
        <v-col cols="4">
          <v-text-field
            v-model.number="form.expected_length_mm"
            label="Длина"
            type="number"
            density="compact"
            variant="outlined"
            hide-details
          />
        </v-col>
        <v-col cols="4">
          <v-text-field
            v-model.number="form.expected_width_mm"
            label="Ширина"
            type="number"
            density="compact"
            variant="outlined"
            hide-details
          />
        </v-col>
        <v-col cols="4">
          <v-text-field
            v-model.number="form.expected_thickness_mm"
            label="Толщина"
            type="number"
            density="compact"
            variant="outlined"
            hide-details
            step="0.1"
          />
        </v-col>
      </v-row>
    </div>

    <!-- Generated pattern preview -->
    <v-expansion-panels variant="accordion" class="mb-4">
      <v-expansion-panel>
        <v-expansion-panel-title>Технические детали</v-expansion-panel-title>
        <v-expansion-panel-text>
          <div class="mb-3">
            <div class="section-label">Regex шаблон</div>
            <v-textarea
              v-model="form.pattern"
              variant="outlined"
              density="compact"
              rows="2"
              hide-details
              class="mono-text"
            />
          </div>
          <v-row dense>
            <v-col cols="4">
              <v-text-field
                v-model="form.capture_length_mm"
                label="Capture длина"
                density="compact"
                variant="outlined"
                hide-details
                placeholder="$1"
              />
            </v-col>
            <v-col cols="4">
              <v-text-field
                v-model="form.capture_width_mm"
                label="Capture ширина"
                density="compact"
                variant="outlined"
                hide-details
                placeholder="$2"
              />
            </v-col>
            <v-col cols="4">
              <v-text-field
                v-model="form.capture_thickness_mm"
                label="Capture толщина"
                density="compact"
                variant="outlined"
                hide-details
                placeholder="$3"
              />
            </v-col>
          </v-row>
          <v-row dense class="mt-2">
            <v-col cols="6">
              <v-text-field
                v-model="form.source"
                label="Источник"
                density="compact"
                variant="outlined"
                hide-details
              />
            </v-col>
            <v-col cols="6">
              <v-text-field
                v-model.number="form.priority"
                label="Приоритет"
                type="number"
                density="compact"
                variant="outlined"
                hide-details
              />
            </v-col>
          </v-row>
        </v-expansion-panel-text>
      </v-expansion-panel>
    </v-expansion-panels>

    <div class="d-flex gap-2">
      <v-btn
        color="primary"
        variant="flat"
        :loading="saving"
        :disabled="!canSave"
        @click="saveRule"
      >
        <v-icon icon="mdi-check" class="mr-1" />
        Сохранить правило
      </v-btn>
      <v-btn variant="text" @click="$emit('cancel')">
        Отмена
      </v-btn>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { adminMaterialDimensionsApi, type MaterialDimensionMaterialType } from '@/api/materialDimensions'

interface RulePreset {
  example_input: string
  material_type?: MaterialDimensionMaterialType | null
  source?: string | null
}

interface NumericBlock {
  id: number
  raw: string
  value: number
  index: number
  role: 'length' | 'width' | 'thickness' | 'ignore'
}

const props = defineProps<{
  preset: RulePreset | null
}>()

const emit = defineEmits<{
  (e: 'saved'): void
  (e: 'cancel'): void
}>()

const saving = ref(false)
const analyzing = ref(false)
const formError = ref('')
const numericBlocks = ref<NumericBlock[]>([])

const form = ref({
  name: '',
  description: '',
  material_type: null as MaterialDimensionMaterialType | null,
  source: '',
  pattern: '',
  priority: 500,
  capture_length_mm: null as string | null,
  capture_width_mm: null as string | null,
  capture_thickness_mm: null as string | null,
  expected_length_mm: null as number | null,
  expected_width_mm: null as number | null,
  expected_thickness_mm: null as number | null,
})

const materialTypeOptions = [
  { title: 'Плита', value: 'plate' },
  { title: 'Кромка', value: 'edge' },
  { title: 'Фурнитура', value: 'hardware' },
  { title: 'Фасад', value: 'facade' },
  { title: 'Комплектующие', value: 'fitting' }
]

const roleOptions = [
  { title: 'Длина', value: 'length' },
  { title: 'Ширина', value: 'width' },
  { title: 'Толщина', value: 'thickness' },
  { title: 'Игнорировать', value: 'ignore' }
]

const canSave = computed(() => {
  return form.value.name.trim() && form.value.pattern.trim()
})

function roleColor(role: string): string {
  const colors: Record<string, string> = {
    length: 'primary',
    width: 'success',
    thickness: 'warning',
    ignore: 'grey'
  }
  return colors[role] || 'grey'
}

function extractNumericBlocks(text: string): NumericBlock[] {
  const blocks: NumericBlock[] = []
  const regex = /(\d+(?:[.,]\d+)?)/g
  let match
  let index = 0
  while ((match = regex.exec(text)) !== null) {
    const rawValue = match[1] || ''
    blocks.push({
      id: index,
      raw: rawValue,
      value: parseFloat(rawValue.replace(',', '.')) || 0,
      index: index,
      role: 'ignore'
    })
    index++
  }
  return blocks
}

function applySuggestedRoles(blocks: NumericBlock[]): NumericBlock[] {
  // Simple heuristic: large numbers for length/width, small for thickness
  const sorted = [...blocks].sort((a, b) => b.value - a.value)
  
  for (let i = 0; i < sorted.length; i++) {
    const item = sorted[i]
    if (!item) continue
    
    if (i === 0 && item.value >= 100) {
      item.role = 'length'
    } else if (i === 1 && item.value >= 100) {
      item.role = 'width'
    } else if (item.value < 100 && item.value > 0) {
      // Could be thickness
      const hasThickness = sorted.some(b => b.role === 'thickness')
      if (!hasThickness) {
        item.role = 'thickness'
      }
    }
  }
  
  // Restore original order
  return blocks.map(b => sorted.find(s => s.id === b.id) || b)
}

function analyzeExample() {
  if (!props.preset?.example_input) return
  
  analyzing.value = true
  try {
    const blocks = extractNumericBlocks(props.preset.example_input)
    numericBlocks.value = applySuggestedRoles(blocks)
    syncFromBlocks()
  } finally {
    analyzing.value = false
  }
}

function syncFromBlocks() {
  // Build pattern from blocks
  const text = props.preset?.example_input || ''
  let pattern = escapeRegex(text)
  
  const captures: { role: string; captureIndex: number }[] = []
  let captureIndex = 1
  
  for (const block of numericBlocks.value) {
    if (block.role !== 'ignore') {
      captures.push({ role: block.role, captureIndex })
      captureIndex++
    }
    // Replace the number with a capture group
    const numPattern = block.role !== 'ignore' ? '(\\d+(?:[.,]\\d+)?)' : '\\d+(?:[.,]\\d+)?'
    pattern = pattern.replace(escapeRegex(block.raw), numPattern)
  }
  
  form.value.pattern = pattern
  
  // Set capture references
  form.value.capture_length_mm = captures.find(c => c.role === 'length') ? `$${captures.find(c => c.role === 'length')!.captureIndex}` : null
  form.value.capture_width_mm = captures.find(c => c.role === 'width') ? `$${captures.find(c => c.role === 'width')!.captureIndex}` : null
  form.value.capture_thickness_mm = captures.find(c => c.role === 'thickness') ? `$${captures.find(c => c.role === 'thickness')!.captureIndex}` : null
  
  // Set expected values
  for (const block of numericBlocks.value) {
    if (block.role === 'length') form.value.expected_length_mm = block.value
    if (block.role === 'width') form.value.expected_width_mm = block.value
    if (block.role === 'thickness') form.value.expected_thickness_mm = block.value
  }
}

function escapeRegex(str: string): string {
  return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

async function saveRule() {
  if (!canSave.value) return
  
  saving.value = true
  formError.value = ''
  
  try {
    const payload = {
      name: form.value.name.trim(),
      description: form.value.description.trim() || null,
      is_active: true,
      priority: form.value.priority,
      material_type: form.value.material_type,
      source: form.value.source.trim() || null,
      rule_type: 'regex' as const,
      config: {
        pattern: form.value.pattern,
        flags: 'u',
        use_normalized_text: true,
        capture_length_mm: form.value.capture_length_mm,
        capture_width_mm: form.value.capture_width_mm,
        capture_thickness_mm: form.value.capture_thickness_mm,
        fixed_length_mm: null,
        fixed_width_mm: null,
        fixed_thickness_mm: null,
      },
      example_input: props.preset?.example_input || null,
      expected_result: {
        length_mm: form.value.expected_length_mm ?? undefined,
        width_mm: form.value.expected_width_mm ?? undefined,
        thickness_mm: form.value.expected_thickness_mm ?? undefined,
      },
      confidence: 0.8
    }
    
    await adminMaterialDimensionsApi.createRule(payload)
    emit('saved')
  } catch (error: any) {
    formError.value = error?.response?.data?.message || 'Не удалось создать правило'
  } finally {
    saving.value = false
  }
}

// Initialize from preset
watch(() => props.preset, (preset) => {
  if (preset) {
    form.value.material_type = preset.material_type || null
    form.value.source = preset.source || ''
    // Auto-generate name
    const timestamp = Date.now().toString(36)
    const typePrefix = preset.material_type || 'auto'
    form.value.name = `${typePrefix}_rule_${timestamp}`
  }
}, { immediate: true })

onMounted(() => {
  if (props.preset?.example_input) {
    analyzeExample()
  }
})
</script>

<style scoped>
.rule-creator {
  padding: 16px 0;
}

.section-label {
  font-size: 12px;
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface), 0.65);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 8px;
}

.mono-text {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-size: 13px;
}

.blocks-grid {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.block-item {
  display: flex;
  align-items: center;
  gap: 12px;
}

.block-value {
  min-width: 80px;
  justify-content: center;
}

.block-role {
  flex: 1;
  max-width: 160px;
}

.gap-2 {
  gap: 8px;
}
</style>
