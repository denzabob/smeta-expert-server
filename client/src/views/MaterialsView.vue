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
          <v-chip size="small" color="primary" variant="tonal">
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
            Ссылка
          </a>
          <span v-else class="text-medium-emphasis">—</span>
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

    <!-- Dialog: Create / Edit -->
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
                  v-model="form.source_url"
                  label="Ссылка на товар"
                  placeholder="https://"
                  type="url"
                  :rules="[rules.url]"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.last_price_screenshot_path"
                  label="Скриншот"
                  placeholder="URL или путь"
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

    <!-- Dialog: History -->
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
                Ссылка
              </a>
              <span v-else>—</span>
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
              <span v-else>—</span>
            </template>
            <template #no-data>
              <div class="text-center pa-4 text-medium-emphasis">
                Нет записей истории цен
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

type Material = {
  id: number
  user_id: number | null
  origin: 'user' | 'parser'
  name: string
  article: string
  type: MaterialType
  unit: MaterialUnit
  price_per_unit: number
  source_url: string | null
  last_price_screenshot_path: string | null
  is_active: boolean
  version: number
}

type MaterialForm = Omit<Material, 'id' | 'user_id'> & { id?: number }

type PriceHistoryItem = {
  id: number
  version: number
  price_per_unit: number
  source_url: string | null
  screenshot_path: string | null
  changed_at: string
}

const headers = [
  { title: 'Название / Артикул', key: 'name', width: '220px' },
  { title: 'Источник', key: 'supplier', width: '120px' },
  { title: 'Тип', key: 'type', width: '120px' },
  { title: 'Ед.', key: 'unit', width: '80px', align: 'center' as const },
  { title: 'Цена', key: 'price_per_unit', width: '110px', align: 'end' as const },
  { title: 'Версия', key: 'version', width: '90px', align: 'center' as const },
  { title: 'Статус', key: 'is_active', width: '110px' },
  { title: 'Ссылка', key: 'source_url' },
  { title: '', key: 'actions', width: '70px', sortable: false },
]

const historyHeaders = [
  { title: 'Версия', key: 'version', width: '90px', align: 'center' as const },
  { title: 'Цена', key: 'price_per_unit', width: '110px', align: 'end' as const },
  { title: 'Ссылка', key: 'source_url' },
  { title: 'Скриншот', key: 'screenshot_path', width: '120px' },
  { title: 'Дата', key: 'changed_at', width: '180px' },
]

const typeOptions = [
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

const materials = ref<Material[]>([])
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
  origin: 'user',
  name: '',
  article: '',
  type: 'plate',
  unit: 'м²',
  price_per_unit: 0,
  source_url: '',
  last_price_screenshot_path: '',
  is_active: true,
  version: 1,
})

const snackbar = reactive({
  show: false,
  message: '',
  color: 'success',
})

const historyDialog = ref(false)
const historyMaterial = ref<Material | null>(null)
const priceHistory = ref<PriceHistoryItem[]>([])

const rules = {
  required: (v: any) => !!v || 'Обязательное поле',
  nonNegative: (v: number) => v >= 0 || 'Не может быть отрицательной',
  url: (v: string) => !v || /^https?:\/\//.test(v) || 'Должен начинаться с http:// или https://',
}

const getAuthToken = () => localStorage.getItem('auth_token')

const authHeaders = () => {
  const token = getAuthToken()
  return token ? { Authorization: `Bearer ${token}` } : {}
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
  new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' }).format(value || 0)

const fetchMaterials = async () => {
  loading.value = true
  try {
    const res = await fetch('/api/materials', {
      headers: { Authorization: `Bearer ${getAuthToken()}` },
      credentials: 'include',
    })
    if (!res.ok) throw new Error('Не удалось загрузить материалы')
    materials.value = await res.json()
  } catch (error) {
    console.error(error)
    snackbar.message = 'Ошибка загрузки материалов'
    snackbar.color = 'error'
    snackbar.show = true
  } finally {
    loading.value = false
  }
}

const resetForm = () => {
  form.id = undefined
  form.origin = 'user'
  form.name = ''
  form.article = ''
  form.type = 'plate'
  form.unit = 'м²'
  form.price_per_unit = 0
  form.source_url = ''
  form.last_price_screenshot_path = ''
  form.is_active = true
  form.version = 1
}

const openCreateDialog = () => {
  editingId.value = null
  resetForm()
  dialog.value = true
}

const openEditDialog = (item: Material) => {
  editingId.value = item.id
  Object.assign(form, { ...item })
  dialog.value = true
}

const openHistoryDialog = async (item: Material) => {
  historyMaterial.value = item
  historyDialog.value = true
  try {
    const res = await fetch(`/api/materials/${item.id}/history`, {
      headers: { Authorization: `Bearer ${getAuthToken()}` },
      credentials: 'include',
    })
    priceHistory.value = res.ok ? await res.json() : []
  } catch (e) {
    priceHistory.value = []
  }
}

const closeDialog = () => {
  dialog.value = false
}

const saveMaterial = async () => {
  const valid = (await formRef.value?.validate())?.valid ?? false
  if (!valid) return

  saving.value = true
  try {
    const url = editingId.value ? `/api/materials/${editingId.value}` : '/api/materials'
    const method = editingId.value ? 'PUT' : 'POST'
    const payload = { ...form }

    const res = await fetch(url, {
      method,
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${getAuthToken()}`,
      },
      credentials: 'include',
      body: JSON.stringify(payload),
    })

    if (!res.ok) throw new Error('Ошибка сохранения')

    await fetchMaterials()
    closeDialog()
    snackbar.message = editingId.value ? 'Материал обновлён' : 'Материал создан'
    snackbar.color = 'success'
    snackbar.show = true
  } catch (error) {
    snackbar.message = 'Ошибка при сохранении'
    snackbar.color = 'error'
    snackbar.show = true
  } finally {
    saving.value = false
  }
}

onMounted(fetchMaterials)
</script>

<style scoped>
.text-none {
  text-transform: none;
}
</style>
