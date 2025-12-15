<template>
  <v-container fluid class="pa-0">
    <v-sheet class="pa-4" color="surface">
      <div class="d-flex flex-wrap align-center ga-3">
        <div>
          <div class="text-h5 font-weight-medium">Материалы</div>
          <div class="text-medium-emphasis">Просмотр и корректировка свойств</div>
        </div>
        <v-spacer />
        <v-btn
          color="primary"
          prepend-icon="mdi-plus"
          class="text-none"
          @click="openCreateDialog"
        >
          Добавить
        </v-btn>
        <v-btn
          variant="text"
          prepend-icon="mdi-refresh"
          class="text-none"
          :loading="loading"
          @click="fetchMaterials"
        >
          Обновить
        </v-btn>
      </div>
    </v-sheet>

    <v-sheet class="pa-4">
      <v-row class="mb-3" align="center" dense>
        <v-col cols="12" md="4">
          <v-text-field
            v-model="search"
            label="Поиск по названию или артикулу"
            prepend-inner-icon="mdi-magnify"
            hide-details
            clearable
          />
        </v-col>
        <v-col cols="12" md="3">
          <v-select
            v-model="typeFilter"
            :items="typeOptions"
            item-title="label"
            item-value="value"
            label="Тип"
            clearable
            hide-details
          />
        </v-col>
        <v-col cols="12" md="3">
          <v-select
            v-model="unitFilter"
            :items="unitOptions"
            label="Ед. изм."
            clearable
            hide-details
          />
        </v-col>
      </v-row>

      <v-data-table
        :headers="headers"
        :items="filteredMaterials"
        :loading="loading"
        class="elevation-1"
        item-key="id"
        density="comfortable"
      >
        <template #item.supplier="{ item }">
          <v-chip
            size="small"
            color="primary"
            variant="tonal"
          >
            {{ item.supplier || '—' }}
          </v-chip>
        </template>

        <template #item.name="{ item }">
          <div class="d-flex flex-column">
            <span class="font-weight-medium">{{ item.name }}</span>
            <span class="text-caption text-medium-emphasis">{{ item.article }}</span>
          </div>
        </template>

        <template #item.type="{ item }">
          <v-chip size="small" variant="tonal" color="primary">
            {{ typeLabels[item.type] ?? item.type }}
          </v-chip>
        </template>

        <template #item.unit="{ item }">
          <v-chip size="small" variant="text">{{ item.unit }}</v-chip>
        </template>

        <template #item.price_per_unit="{ item }">
          {{ formatPrice(item.price_per_unit) }}
        </template>

        <template #item.version="{ item }">
          <v-chip size="small" variant="outlined">
            v{{ item.version ?? 1 }}
          </v-chip>
        </template>

        <template #item.is_active="{ item }">
          <v-chip
            size="small"
            :color="item.is_active ? 'success' : 'grey'"
            variant="tonal"
          >
            {{ item.is_active ? 'Активен' : 'Выключен' }}
          </v-chip>
        </template>

        <template #item.source_url="{ item }">
          <a
            v-if="item.source_url"
            :href="item.source_url"
            target="_blank"
            rel="noreferrer"
          >
            {{ item.source_url }}
          </a>
          <span v-else class="text-medium-emphasis">—</span>
        </template>

        <template #item.actions="{ item }">
          <v-btn
            icon
            size="small"
            variant="text"
            color="primary"
            :disabled="item.sourceType === 'user'"
            @click="openEditDialog(item)"
          >
            <v-icon icon="mdi-pencil" />
          </v-btn>
          <v-btn
            icon
            size="small"
            variant="text"
            color="secondary"
            :disabled="item.sourceType === 'user'"
            @click="openHistoryDialog(item)"
          >
            <v-icon icon="mdi-history" />
          </v-btn>
        </template>

        <template #no-data>
          <div class="text-center pa-4">
            <div class="text-subtitle-1 mb-1">Нет данных</div>
            <div class="text-medium-emphasis mb-3">
              Добавьте материал или измените условия поиска
            </div>
            <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreateDialog">
              Добавить материал
            </v-btn>
          </div>
        </template>
      </v-data-table>
    </v-sheet>

    <v-dialog v-model="dialog" max-width="640" persistent>
      <v-card>
        <v-card-title class="d-flex align-center">
          <v-icon class="mr-2" :icon="editingId ? 'mdi-pencil' : 'mdi-plus'" />
          <span class="text-h6">
            {{ editingId ? 'Редактировать материал' : 'Новый материал' }}
          </span>
          <v-spacer />
          <v-btn icon variant="text" @click="closeDialog">
            <v-icon icon="mdi-close" />
          </v-btn>
        </v-card-title>
        <v-divider />

        <v-card-text>
          <v-form ref="formRef" v-model="formValid">
            <v-row dense>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.name"
                  label="Название"
                  :rules="[rules.required]"
                  required
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.article"
                  label="Артикул"
                  :rules="[rules.required]"
                  required
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-select
                  v-model="form.type"
                  :items="typeOptions"
                  item-title="label"
                  item-value="value"
                  label="Тип"
                  :rules="[rules.required]"
                  required
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-select
                  v-model="form.unit"
                  :items="unitOptions"
                  label="Единица"
                  :rules="[rules.required]"
                  required
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model.number="form.price_per_unit"
                  label="Цена за единицу"
                  type="number"
                  min="0"
                  step="0.01"
                  :rules="[rules.required, rules.nonNegative]"
                  required
                  prefix="₽"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.supplier"
                  label="Поставщик"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.source_url"
                  label="Ссылка на поставщика"
                  placeholder="https://"
                  type="url"
                  :rules="[rules.url]"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.screenshot_path"
                  label="Скриншот страницы товара"
                  placeholder="https://..."
                  type="url"
                  :rules="[rules.url]"
                />
              </v-col>
              <v-col cols="12">
                <v-switch
                  v-model="form.is_active"
                  color="primary"
                  inset
                  label="Материал активен"
                />
              </v-col>
            </v-row>
          </v-form>
        </v-card-text>

        <v-card-actions class="px-4 pb-4">
          <v-spacer />
          <v-btn variant="text" class="text-none" @click="closeDialog">
            Отмена
          </v-btn>
          <v-btn
            color="primary"
            class="text-none"
            :loading="saving"
            :disabled="!formValid"
            @click="saveMaterial"
          >
            Сохранить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="historyDialog" max-width="720">
      <v-card>
        <v-card-title class="d-flex align-center">
          <v-icon class="mr-2" icon="mdi-history" />
          <span class="text-h6">История цен — {{ historyMaterial?.name }}</span>
          <v-spacer />
          <v-btn icon variant="text" @click="historyDialog = false">
            <v-icon icon="mdi-close" />
          </v-btn>
        </v-card-title>
        <v-divider />
        <v-card-text>
          <v-data-table
            :headers="historyHeaders"
            :items="priceHistory"
            density="comfortable"
          >
            <template #item.price_per_unit="{ item }">
              {{ formatPrice(item.price_per_unit) }}
            </template>
            <template #item.changed_at="{ item }">
              {{ new Date(item.changed_at).toLocaleString('ru-RU') }}
            </template>
            <template #item.source_url="{ item }">
              <a
                v-if="item.source_url"
                :href="item.source_url"
                target="_blank"
                rel="noreferrer"
              >
                {{ item.source_url }}
              </a>
              <span v-else class="text-medium-emphasis">—</span>
            </template>
            <template #item.screenshot_path="{ item }">
              <a
                v-if="item.screenshot_path"
                :href="item.screenshot_path"
                target="_blank"
                rel="noreferrer"
              >
                Скриншот
              </a>
              <span v-else class="text-medium-emphasis">—</span>
            </template>
            <template #no-data>
              <div class="text-center pa-4 text-medium-emphasis">
                Нет записей истории цен для этого материала
              </div>
            </template>
          </v-data-table>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3000">
      {{ snackbar.message }}
    </v-snackbar>
  </v-container>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'

type MaterialType = 'plate' | 'edge' | 'fitting'
type MaterialUnit = 'м²' | 'м.п.' | 'шт'
type SystemMaterial = {
  id: number
  name: string
  article: string
  type: MaterialType
  unit: MaterialUnit
  price_per_unit: number
  supplier?: string | null
  source_url?: string | null
  is_active: boolean
  version: number
  screenshot_path?: string | null
}

type MaterialForm = Omit<SystemMaterial, 'id'> & { id?: number }

type SelectOption<T> = { label: string; value: T }

type PriceHistoryItem = {
  id: number
  version: number
  price_per_unit: number
  source_url?: string | null
  screenshot_path?: string | null
  changed_at: string
}

const headers = [
  { title: 'Название / Артикул', key: 'name', width: '220px' },
  { title: 'Поставщик', key: 'supplier', width: '160px' },
  { title: 'Тип', key: 'type', width: '120px' },
  { title: 'Ед.', key: 'unit', width: '80px', align: 'center' },
  { title: 'Цена', key: 'price_per_unit', width: '110px', align: 'end' },
  { title: 'Версия', key: 'version', width: '90px', align: 'center' },
  { title: 'Статус', key: 'is_active', width: '110px' },
  { title: 'Ссылка', key: 'source_url' },
  { title: '', key: 'actions', width: '70px', sortable: false },
] as const

const historyHeaders = [
  { title: 'Версия', key: 'version', width: '90px', align: 'center' as const },
  { title: 'Цена', key: 'price_per_unit', width: '110px', align: 'end' as const },
  { title: 'Источник', key: 'source_url' },
  { title: 'Скриншот', key: 'screenshot_path', width: '120px' },
  { title: 'Дата изменения', key: 'changed_at', width: '180px' },
] as const

const typeOptions: SelectOption<MaterialType>[] = [
  { label: 'Плита', value: 'plate' },
  { label: 'Кромка', value: 'edge' },
  { label: 'Фурнитура', value: 'fitting' },
]

const unitOptions: MaterialUnit[] = ['м²', 'м.п.', 'шт']

const typeLabels: Record<MaterialType, string> = {
  plate: 'Плита',
  edge: 'Кромка',
  fitting: 'Фурнитура',
}

type MaterialRowSource = 'system' | 'user'

type MaterialRow = SystemMaterial & {
  sourceType: MaterialRowSource
}

const materials = ref<MaterialRow[]>([])
const loading = ref(false)
const saving = ref(false)
const dialog = ref(false)
const editingId = ref<number | null>(null)
const formValid = ref(false)
const formRef = ref()
const search = ref('')
const typeFilter = ref<MaterialType | null>(null)
const unitFilter = ref<MaterialUnit | null>(null)

const form = reactive<MaterialForm>({
  id: undefined,
  name: '',
  article: '',
  type: 'plate',
  unit: 'м²',
  price_per_unit: 0,
  supplier: '',
  source_url: '',
  is_active: true,
  version: 1,
  screenshot_path: '',
})

const snackbar = reactive({
  show: false,
  message: '',
  color: 'success',
})

const historyDialog = ref(false)
const historyMaterial = ref<MaterialRow | null>(null)
const priceHistory = ref<PriceHistoryItem[]>([])

const rules = {
  required: (v: string | number | null | undefined) =>
    (v !== undefined && v !== null && String(v).trim() !== '') || 'Обязательное поле',
  nonNegative: (v: number) => v >= 0 || 'Не может быть отрицательной',
  url: (v: string) =>
    !v || /^https?:\/\/.+/i.test(v) || 'Укажите корректный URL (http/https)',
}

const filteredMaterials = computed(() => {
  const term = search.value.trim().toLowerCase()
  return materials.value.filter((item) => {
    const matchesTerm =
      !term ||
      item.name.toLowerCase().includes(term) ||
      item.article.toLowerCase().includes(term)
    const matchesType = !typeFilter.value || item.type === typeFilter.value
    const matchesUnit = !unitFilter.value || item.unit === unitFilter.value
    return matchesTerm && matchesType && matchesUnit
  })
})

const formatPrice = (value: number) =>
  new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' }).format(
    Number(value) || 0
  )

const getAuthToken = () => localStorage.getItem('auth_token') ?? ''

const authHeaders = (): Record<string, string> => {
  const token = getAuthToken()
  return token ? { Authorization: `Bearer ${token}` } : {}
}

const fetchMaterials = async () => {
  loading.value = true
  try {
    const [systemRes, userRes] = await Promise.all([
      fetch('/api/system-materials', { credentials: 'include' }),
      fetch('/api/user-materials', { headers: authHeaders(), credentials: 'include' }).catch(
        () => null
      ),
    ])

    if (!systemRes.ok) throw new Error('Не удалось загрузить системные материалы')

    const systemData = (await systemRes.json()) as SystemMaterial[]
    const userData =
      userRes && userRes.ok ? ((await userRes.json()) as SystemMaterial[]) : ([] as SystemMaterial[])

    const systemRows: MaterialRow[] = systemData.map((m) => ({
      ...m,
      is_active: Boolean(m.is_active),
      version: m.version ?? 1,
      sourceType: 'system',
    }))

    const userRows: MaterialRow[] = userData.map((m) => ({
      ...m,
      is_active: Boolean(m.is_active),
      version: m.version ?? 1,
      supplier: m.supplier || 'Пользователь',
      sourceType: 'user',
    }))

    materials.value = [...systemRows, ...userRows]
  } catch (error) {
    showMessage(error instanceof Error ? error.message : 'Ошибка загрузки', 'error')
  } finally {
    loading.value = false
  }
}

const resetForm = () => {
  form.id = undefined
  form.name = ''
  form.article = ''
  form.type = 'plate'
  form.unit = 'м²'
  form.price_per_unit = 0
  form.supplier = ''
  form.source_url = ''
  form.is_active = true
  form.version = 1
  form.screenshot_path = ''
  formValid.value = false
}

const openCreateDialog = () => {
  editingId.value = null
  resetForm()
  dialog.value = true
}

const openEditDialog = (item: SystemMaterial) => {
  editingId.value = item.id
  form.id = item.id
  form.name = item.name
  form.article = item.article
  form.type = item.type
  form.unit = item.unit
  form.price_per_unit = item.price_per_unit
  form.supplier = item.supplier ?? ''
  form.source_url = item.source_url ?? ''
  form.is_active = item.is_active
  form.version = item.version ?? 1
  form.screenshot_path = item.screenshot_path ?? ''
  dialog.value = true
}

const openHistoryDialog = async (item: MaterialRow) => {
  if (item.sourceType === 'user') return
  historyMaterial.value = item
  historyDialog.value = true
  priceHistory.value = []
  try {
    const response = await fetch(`/api/system-materials/${item.id}/history`, {
      credentials: 'include',
    })
    if (!response.ok) {
      throw new Error('Не удалось загрузить историю цен')
    }
    const data = (await response.json()) as PriceHistoryItem[]
    priceHistory.value = data
  } catch (error) {
    showMessage(error instanceof Error ? error.message : 'Ошибка загрузки истории', 'error')
  }
}

const closeDialog = () => {
  dialog.value = false
}

const saveMaterial = async () => {
  const { valid } = (await formRef.value?.validate()) ?? { valid: false }
  if (!valid) return

  saving.value = true
  try {
    const payload = {
      name: form.name,
      article: form.article,
      type: form.type,
      unit: form.unit,
      price_per_unit: Number(form.price_per_unit),
      supplier: form.supplier || null,
      source_url: form.source_url || null,
      is_active: form.is_active,
      screenshot_path: form.screenshot_path || null,
    }

    const isEdit = Boolean(editingId.value)
    const url = isEdit
      ? `/api/system-materials/${editingId.value}`
      : '/api/system-materials'
    const method = isEdit ? 'PUT' : 'POST'

    const response = await fetch(url, {
      method,
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify(payload),
    })

    if (!response.ok) {
      const errorBody = await response.json().catch(() => ({}))
      const message =
        errorBody?.message || (isEdit ? 'Не удалось сохранить изменения' : 'Не удалось создать')
      throw new Error(message)
    }

    const saved = (await response.json()) as SystemMaterial

    const savedRow: MaterialRow = {
      ...saved,
      is_active: Boolean(saved.is_active),
      version: saved.version ?? 1,
      sourceType: 'system',
    }

    if (isEdit) {
      materials.value = materials.value.map((m) => (m.id === savedRow.id ? savedRow : m))
      showMessage('Материал обновлён', 'success')
    } else {
      materials.value = [savedRow, ...materials.value]
      showMessage('Материал создан', 'success')
    }

    closeDialog()
  } catch (error) {
    showMessage(error instanceof Error ? error.message : 'Ошибка сохранения', 'error')
  } finally {
    saving.value = false
  }
}

const showMessage = (message: string, color: 'success' | 'error' | 'warning') => {
  snackbar.message = message
  snackbar.color = color
  snackbar.show = true
}

onMounted(fetchMaterials)
</script>

<style scoped>
.text-none {
  text-transform: none;
}
</style>
