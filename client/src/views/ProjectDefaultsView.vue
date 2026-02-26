<template>
  <v-container fluid class="user-settings-page">
    <div class="d-flex align-center justify-space-between mb-4">
      <div>
        <div class="text-h5 font-weight-medium">Проект — настройки по умолчанию</div>
        <div class="text-body-2 text-medium-emphasis">
          Эти значения применяются только к новым проектам
        </div>
      </div>
    </div>

    <!-- Info banner -->
    <v-alert type="info" variant="tonal" density="compact" class="mb-4" closable>
      <div class="text-body-2">
        Применяется только к новым проектам. Нормо-часы и операции настраиваются в каждом проекте отдельно.
      </div>
    </v-alert>

    <v-card class="settings-shell" elevation="1">
      <div class="settings-topbar">
        <v-text-field
          v-model="searchQuery"
          placeholder="Поиск раздела: коэффициенты, материалы, блоки..."
          prepend-inner-icon="mdi-magnify"
          clearable
          hide-details
          density="compact"
          class="settings-search"
        />
        <div class="d-flex align-center gap-2 flex-wrap">
          <v-chip
            v-for="(section, idx) in sections"
            :key="section.id"
            :color="activeSection === idx ? 'primary' : undefined"
            :variant="activeSection === idx ? 'flat' : 'tonal'"
            size="small"
            class="cursor-pointer"
            @click="activeSection = idx"
          >
            {{ idx + 1 }}. {{ section.title }}
          </v-chip>
        </div>
      </div>

      <div class="d-flex settings-body">
        <div class="settings-content">
          <div class="settings-content-scroll">
            <div class="d-flex align-center justify-space-between mb-3">
              <div class="text-subtitle-1 font-weight-medium">{{ sections[activeSection]?.title }}</div>
              <v-btn
                size="small"
                variant="outlined"
                :disabled="loading || saving || !isDirty"
                @click="resetCurrentSection"
              >
                Сбросить раздел
              </v-btn>
            </div>

            <v-skeleton-loader
              v-if="loading"
              type="article, paragraph, paragraph, paragraph"
            />

            <ProjectDefaultsForm
              v-else
              :active-section="activeSection"
              :form="form"
              :regions="regions"
              :materials="materials"
              :search-query="searchQuery"
              @update:form="form = $event"
            />
          </div>

          <div class="settings-footer">
            <div class="d-flex align-center justify-space-between">
              <div class="text-body-2" :class="isDirty ? 'text-warning' : 'text-medium-emphasis'">
                {{ isDirty ? 'Есть несохранённые изменения' : 'Все изменения сохранены' }}
              </div>
              <div class="d-flex gap-2">
                <v-btn variant="text" @click="onCancel" :disabled="saving || !isDirty">Отменить</v-btn>
                <v-btn color="primary" variant="flat" @click="onSave" :loading="saving" :disabled="saving || !isDirty">Сохранить</v-btn>
              </div>
            </div>
          </div>
        </div>
      </div>
    </v-card>

    <v-snackbar v-model="snackbar.show" :timeout="snackbar.timeout" :color="snackbar.color" location="bottom right">
      {{ snackbar.message }}
    </v-snackbar>
  </v-container>
</template>

<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import api from '@/api/axios'
import ProjectDefaultsForm from '@/components/settings/ProjectDefaultsForm.vue'
import type { ProjectDefaultsData, Material, Region, CoefficientDescription } from '@/components/settings/ProjectDefaultsForm.vue'

const sections = [
  { id: 'general', title: 'Общие', icon: 'mdi-cog-outline', keywords: ['эксперт', 'регион', 'номер', 'режим'] },
  { id: 'base-coeff', title: 'Базовые коэффициенты', icon: 'mdi-tune', keywords: ['коэффициент', 'ремонтопригодности', 'общий'] },
  { id: 'materials', title: 'Материалы по умолчанию', icon: 'mdi-package-variant', keywords: ['материал', 'плита', 'кромка'] },
  { id: 'waste-by-type', title: 'Коэффициенты по типам', icon: 'mdi-recycle', keywords: ['отходов', 'кромка', 'операции', 'плитные'] },
  { id: 'text-blocks', title: 'Справочные блоки', icon: 'mdi-text-box-outline', keywords: ['блоки', 'текст', 'отчет', 'pdf'] },
]

const activeSection = ref(0)
const loading = ref(true)
const saving = ref(false)
const searchQuery = ref('')

const regions = ref<Region[]>([])
const materials = ref<Material[]>([])

const form = ref<ProjectDefaultsData>({
  region_id: null,
  use_area_calc_mode: false,
  waste_coefficient: 1.0,
  repair_coefficient: 1.0,
  default_plate_material_id: null,
  default_edge_material_id: null,
  default_expert_name: '',
  default_number: '',
  waste_plate_coefficient: null,
  waste_edge_coefficient: null,
  waste_operations_coefficient: null,
  apply_waste_to_plate: true,
  apply_waste_to_edge: true,
  apply_waste_to_operations: false,
  waste_plate_description: null,
  waste_edge_description: null,
  waste_operations_description: null,
  show_waste_plate_description: false,
  show_waste_edge_description: false,
  show_waste_operations_description: false,
  text_blocks: [],
})

const original = ref<string>('')

const snackbar = ref({
  show: false,
  message: '',
  color: 'info',
  timeout: 3000,
})

const showNotification = (message: string, color: string = 'info', timeout: number = 3000) => {
  snackbar.value = { show: true, message, color, timeout }
}

const serializeForDirty = (): string => {
  return JSON.stringify(form.value)
}

const isDirty = computed(() => {
  return !loading.value && original.value !== '' && serializeForDirty() !== original.value
})

const sectionFieldMap: Array<Array<keyof ProjectDefaultsData>> = [
  ['default_expert_name', 'default_number', 'region_id', 'use_area_calc_mode'],
  ['waste_coefficient', 'repair_coefficient'],
  ['default_plate_material_id', 'default_edge_material_id'],
  [
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
  ],
  ['text_blocks'],
]

const buildPayload = (): Partial<ProjectDefaultsData> => {
  const descOrNull = (d: CoefficientDescription | null): CoefficientDescription | null => {
    if (!d) return null
    const title = (d.title || '').trim()
    const text = (d.text || '').trim()
    return title || text ? { title, text } : null
  }

  return {
    ...form.value,
    waste_plate_description: descOrNull(form.value.waste_plate_description),
    waste_edge_description: descOrNull(form.value.waste_edge_description),
    waste_operations_description: descOrNull(form.value.waste_operations_description),
    text_blocks: form.value.text_blocks && form.value.text_blocks.length > 0 ? form.value.text_blocks : [],
  }
}

const loadAll = async () => {
  loading.value = true
  try {
    const [materialsRes, regionsRes, settingsRes] = await Promise.all([
      api.get('/api/materials').then(r => r.data),
      api.get('/api/regions').then(r => r.data?.data || []),
      api.get('/api/user/settings').then(r => r.data),
    ])

    materials.value = materialsRes || []
    regions.value = regionsRes || []

    const { text_blocks, ...otherSettings } = settingsRes || {}
    form.value = {
      ...form.value,
      ...otherSettings,
      text_blocks: text_blocks ?? [],
    }

    original.value = serializeForDirty()
  } catch (e: any) {
    console.error('Failed to load user settings:', e)
    showNotification(e.response?.data?.message || e.message || 'Не удалось загрузить настройки', 'error')
  } finally {
    loading.value = false
  }
}

const onSave = async () => {
  if (saving.value) return
  saving.value = true
  try {
    const payload = buildPayload()
    const { data } = await api.put('/api/user/settings', payload)

    const { text_blocks: _, ...otherData } = data || {}
    form.value = {
      ...form.value,
      ...otherData,
      text_blocks: data?.text_blocks ?? [],
    }

    original.value = serializeForDirty()
    showNotification('Настройки сохранены', 'success')
  } catch (e: any) {
    console.error('Failed to save user settings:', e)
    showNotification(e.response?.data?.message || e.message || 'Ошибка сохранения', 'error')
  } finally {
    saving.value = false
  }
}

const onCancel = async () => {
  if (!isDirty.value) return
  const ok = window.confirm('Отменить несохранённые изменения?')
  if (!ok) return
  await loadAll()
  showNotification('Изменения отменены', 'info')
}

const resetCurrentSection = () => {
  if (!isDirty.value || original.value === '') return
  const ok = window.confirm('Сбросить изменения только в текущем разделе?')
  if (!ok) return

  const baseline = JSON.parse(original.value) as ProjectDefaultsData
  const fields = sectionFieldMap[activeSection.value] || []
  const next = { ...form.value } as ProjectDefaultsData
  for (const field of fields) {
    ;(next as any)[field] = (baseline as any)[field]
  }
  form.value = next
  showNotification('Раздел сброшен', 'info')
}

watch(searchQuery, (value) => {
  const term = value.trim().toLowerCase()
  if (!term) return
  const idx = sections.findIndex((section) => {
    const titleMatch = section.title.toLowerCase().includes(term)
    const keywordMatch = (section.keywords || []).some((k: string) => k.includes(term))
    return titleMatch || keywordMatch
  })
  if (idx >= 0) {
    activeSection.value = idx
  }
})

// Confirm on leave
onBeforeRouteLeave((_to, _from, next) => {
  if (!isDirty.value) return next()
  const ok = window.confirm('Есть несохранённые изменения. Уйти со страницы?')
  return ok ? next() : next(false)
})

const beforeUnloadHandler = (event: BeforeUnloadEvent) => {
  if (!isDirty.value) return
  event.preventDefault()
  event.returnValue = ''
}

onMounted(async () => {
  window.addEventListener('beforeunload', beforeUnloadHandler)
  await loadAll()
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', beforeUnloadHandler)
})
</script>

<style scoped>
.user-settings-page {
  max-width: 1400px;
}

.settings-shell {
  overflow: hidden;
}

.settings-topbar {
  padding: 12px 16px;
  border-bottom: 1px solid rgba(0, 0, 0, 0.08);
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.settings-search {
  max-width: 460px;
}

.settings-body {
  min-height: 70vh;
}

.settings-content {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.settings-content-scroll {
  flex: 1;
  overflow: auto;
  padding: 16px;
}

.settings-footer {
  position: sticky;
  bottom: 0;
  padding: 12px 16px;
  border-top: 1px solid rgba(0,0,0,0.08);
  background: rgb(var(--v-theme-surface));
}

.gap-2 { gap: 8px; }
</style>
