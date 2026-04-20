<template>
  <v-dialog
    :model-value="modelValue"
    max-width="680"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <v-card>
      <v-card-title class="d-flex align-center pa-4 pb-2">
        <div>
          <div class="text-subtitle-1 font-weight-medium">Выберите операцию</div>
          <div class="picker-subtitle">Найдите операцию, для которой хотите добавить цену</div>
        </div>
        <v-spacer />
        <v-btn icon="mdi-close" variant="text" size="small" @click="emit('update:modelValue', false)" />
      </v-card-title>

      <v-card-text class="pa-4 pt-2">
        <v-text-field
          v-model="searchInput"
          prepend-inner-icon="mdi-magnify"
          placeholder="Поиск по названию операции..."
          variant="outlined"
          density="compact"
          hide-details
          clearable
          class="mb-4"
        />

        <div v-if="loading" class="picker-loading">
          <v-progress-circular indeterminate size="20" width="2" color="primary" />
          <span>Загрузка операций...</span>
        </div>

        <div v-else-if="filteredOperations.length === 0" class="picker-empty">
          <v-icon size="28" color="grey-lighten-1">mdi-magnify-close</v-icon>
          <span>Операции не найдены</span>
        </div>

        <v-list v-else class="picker-list" lines="two">
          <v-list-item
            v-for="operation in filteredOperations"
            :key="operation.id"
            class="picker-list-item"
            @click="selectOperation(operation)"
          >
            <template #title>
              <div class="picker-item-title">{{ operation.name }}</div>
            </template>
            <template #subtitle>
              <div class="picker-item-subtitle">
                {{ buildSubtitle(operation) }}
              </div>
            </template>
          </v-list-item>
        </v-list>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import api from '@/api/axios'

interface OperationRow {
  id: number
  name: string
  category?: string
  unit?: string
}

const props = defineProps<{
  modelValue: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  selected: [operation: OperationRow]
}>()

const operations = ref<OperationRow[]>([])
const loading = ref(false)
const loaded = ref(false)
const searchInput = ref('')
const debouncedSearch = ref('')
let debounceTimer: ReturnType<typeof setTimeout> | null = null

watch(() => props.modelValue, async (open) => {
  if (!open) {
    searchInput.value = ''
    debouncedSearch.value = ''
    return
  }

  if (loaded.value || loading.value) return

  loading.value = true
  try {
    operations.value = (await api.get('/api/operations')).data
    loaded.value = true
  } finally {
    loading.value = false
  }
})

watch(searchInput, (value) => {
  if (debounceTimer) clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    debouncedSearch.value = value.trim().toLowerCase()
  }, 220)
})

const filteredOperations = computed(() => {
  if (!debouncedSearch.value) return operations.value
  return operations.value.filter((operation) => (
    (operation.name || '').toLowerCase().includes(debouncedSearch.value)
  ))
})

function buildSubtitle(operation: OperationRow): string {
  return [operation.unit, operation.category].filter(Boolean).join(' · ')
}

function selectOperation(operation: OperationRow) {
  emit('update:modelValue', false)
  emit('selected', operation)
}
</script>

<style scoped>
.picker-subtitle {
  margin-top: 2px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.5);
}

.picker-loading,
.picker-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  min-height: 180px;
  color: rgba(0, 0, 0, 0.45);
  text-align: center;
}

.picker-list {
  max-height: 420px;
  overflow-y: auto;
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 10px;
  padding: 4px;
}

.picker-list-item {
  border-radius: 8px;
  cursor: pointer;
}

.picker-list-item:hover {
  background: rgba(var(--v-theme-primary), 0.06);
}

.picker-item-title {
  font-size: 14px;
  font-weight: 500;
}

.picker-item-subtitle {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.5);
}
</style>
