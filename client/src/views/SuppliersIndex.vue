<template>
  <v-container fluid class="pa-0 soft-page suppliers-page">
    <v-sheet class="pa-4 soft-content-card" color="surface">
      <div class="d-flex flex-wrap align-center ga-3">
        <div>
          <div class="text-h5 font-weight-medium">Поставщики</div>
          <div class="text-medium-emphasis">Управление поставщиками и их прайс-листами</div>
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
          @click="fetchSuppliers"
        >
          Обновить
        </v-btn>
      </div>
    </v-sheet>

    <v-sheet class="pa-4 soft-content-card soft-data-card">
      <v-row class="mb-3" align="center" dense>
        <v-col cols="12" md="6">
          <v-text-field
            v-model="search"
            label="Поиск по названию или контактам"
            prepend-inner-icon="mdi-magnify"
            variant="outlined"
            density="compact"
            hide-details
            clearable
            @click:clear="search = ''"
          />
        </v-col>
        <v-col cols="12" md="3">
          <v-select
            v-model="statusFilter"
            :items="statusOptions"
            item-title="label"
            item-value="value"
            label="Статус"
            variant="outlined"
            density="compact"
            clearable
            hide-details
          />
        </v-col>
        <v-col cols="12" md="3">
          <v-btn-toggle v-model="typeFilter" color="primary" density="compact" class="w-100">
            <v-btn :value="null" size="small" class="flex-grow-1">Все</v-btn>
            <v-btn value="operations" size="small" class="flex-grow-1">Операции</v-btn>
            <v-btn value="materials" size="small" class="flex-grow-1">Материалы</v-btn>
          </v-btn-toggle>
        </v-col>
      </v-row>

      <v-data-table
        :headers="headers"
        :items="filteredSuppliers"
        :loading="loading"
        class="soft-data-table"
        item-key="id"
        density="comfortable"
      >
        <!-- Название -->
        <template #item.name="{ item }">
          <router-link
            :to="{ name: 'supplier-show', params: { id: item.id } }"
            class="text-decoration-none font-weight-medium"
          >
            {{ item.name }}
          </router-link>
        </template>

        <!-- Статус -->
        <template #item.is_active="{ item }">
          <v-chip
            size="small"
            :color="item.is_active ? 'success' : 'grey'"
            variant="tonal"
          >
            {{ item.is_active ? 'Активен' : 'Неактивен' }}
          </v-chip>
        </template>

        <!-- Контакты -->
        <template #item.contacts="{ item }">
          <div class="text-caption">
            <div v-if="item.contact_person">{{ item.contact_person }}</div>
            <div v-if="item.contact_email">{{ item.contact_email }}</div>
            <div v-if="item.contact_phone">{{ item.contact_phone }}</div>
          </div>
        </template>

        <!-- Прайс-листы -->
        <template #item.price_lists_count="{ item }">
          <v-chip size="small" variant="text">
            {{ item.price_lists_count || 0 }}
          </v-chip>
        </template>

        <!-- Активные версии -->
        <template #item.active_versions_count="{ item }">
          <v-chip
            size="small"
            :color="(item.active_versions_count ?? 0) > 0 ? 'primary' : 'grey'"
            variant="tonal"
          >
            {{ item.active_versions_count || 0 }}
          </v-chip>
        </template>

        <!-- Последнее обновление -->
        <template #item.last_version_at="{ item }">
          <span v-if="item.last_version_at" class="text-caption">
            {{ formatDate(item.last_version_at) }}
          </span>
          <span v-else class="text-medium-emphasis text-caption">—</span>
        </template>

        <!-- Действия -->
        <template #item.actions="{ item }">
          <v-menu>
            <template v-slot:activator="{ props }">
              <v-btn
                icon="mdi-dots-vertical"
                variant="text"
                size="small"
                v-bind="props"
              />
            </template>
            <v-list density="compact">
              <v-list-item
                :to="{ name: 'supplier-show', params: { id: item.id } }"
                prepend-icon="mdi-eye"
              >
                Просмотр
              </v-list-item>
              <v-list-item
                @click="openEditDialog(item)"
                prepend-icon="mdi-pencil"
              >
                Редактировать
              </v-list-item>
              <v-divider />
              <v-list-item
                v-if="item.is_active"
                @click="archiveSupplier(item)"
                prepend-icon="mdi-archive"
                class="text-warning"
              >
                Архивировать
              </v-list-item>
              <v-list-item
                v-else
                @click="restoreSupplier(item)"
                prepend-icon="mdi-restore"
                class="text-success"
              >
                Восстановить
              </v-list-item>
              <v-divider />
              <v-list-item
                @click="deleteSupplier(item)"
                prepend-icon="mdi-delete"
                class="text-error"
              >
                Удалить
              </v-list-item>
            </v-list>
          </v-menu>
        </template>
      </v-data-table>
    </v-sheet>

    <!-- Create/Edit Dialog -->
    <v-dialog v-model="showDialog" max-width="600px" persistent>
      <v-card class="soft-content-card soft-dialog-card">
        <v-card-title>
          <span class="text-h5">{{ editMode ? 'Редактировать' : 'Создать' }} поставщика</span>
        </v-card-title>
        <v-card-text>
          <v-form ref="form">
            <v-text-field
              v-model="formData.name"
              label="Название *"
              variant="outlined"
              density="compact"
              :rules="[v => !!v || 'Обязательное поле']"
              required
            />
            <v-text-field
              v-model="formData.website"
              label="Веб-сайт"
              type="url"
              variant="outlined"
              density="compact"
            />
            <v-text-field
              v-model="formData.contact_person"
              label="Контактное лицо"
              variant="outlined"
              density="compact"
            />
            <v-text-field
              v-model="formData.contact_email"
              label="Email"
              type="email"
              variant="outlined"
              density="compact"
            />
            <v-text-field
              v-model="formData.contact_phone"
              label="Телефон"
              variant="outlined"
              density="compact"
            />
            <v-textarea
              v-model="formData.notes"
              label="Примечания"
              variant="outlined"
              density="compact"
              rows="3"
            />
            <v-switch
              v-model="formData.is_active"
              label="Активен"
              color="primary"
            />
          </v-form>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="closeDialog">Отмена</v-btn>
          <v-btn
            color="primary"
            variant="flat"
            @click="saveSupplier"
            :loading="saving"
          >
            Сохранить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Snackbar -->
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3000">
      {{ snackbar.message }}
      <template v-slot:actions>
        <v-btn variant="text" @click="snackbar.show = false">Закрыть</v-btn>
      </template>
    </v-snackbar>
  </v-container>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { suppliersApi, type Supplier, type SupplierCreatePayload } from '@/api/suppliers'
import { format } from 'date-fns'
import { ru } from 'date-fns/locale'

// State
const loading = ref(false)
const saving = ref(false)
const suppliers = ref<Supplier[]>([])
const search = ref('')
const statusFilter = ref<boolean | null>(null)
const typeFilter = ref<string | null>(null)
const showDialog = ref(false)
const editMode = ref(false)
const currentSupplier = ref<Supplier | null>(null)

const formData = ref<SupplierCreatePayload>({
  name: '',
  website: null,
  contact_person: null,
  contact_email: null,
  contact_phone: null,
  notes: null,
  is_active: true
})

const snackbar = ref({
  show: false,
  message: '',
  color: 'success'
})

// Table headers
const headers = [
  { title: 'Название', key: 'name', sortable: true },
  { title: 'Статус', key: 'is_active', sortable: true, width: '120px' },
  { title: 'Контакты', key: 'contacts', sortable: false },
  { title: 'Прайс-листы', key: 'price_lists_count', sortable: true, width: '130px' },
  { title: 'Активные версии', key: 'active_versions_count', sortable: true, width: '150px' },
  { title: 'Последнее обновление', key: 'last_version_at', sortable: true, width: '180px' },
  { title: 'Действия', key: 'actions', sortable: false, width: '80px' }
]

// Filter options
const statusOptions = [
  { label: 'Активные', value: true },
  { label: 'Неактивные', value: false }
]

// Computed
const filteredSuppliers = computed(() => {
  let result = [...suppliers.value]

  // Search filter
  if (search.value) {
    const query = search.value.toLowerCase()
    result = result.filter(s =>
      s.name.toLowerCase().includes(query) ||
      s.contact_person?.toLowerCase().includes(query) ||
      s.contact_email?.toLowerCase().includes(query) ||
      s.contact_phone?.toLowerCase().includes(query)
    )
  }

  // Status filter
  if (statusFilter.value !== null) {
    result = result.filter(s => s.is_active === statusFilter.value)
  }

  return result
})

// Methods
const fetchSuppliers = async () => {
  loading.value = true
  try {
    const params: Record<string, any> = {}
    if (typeFilter.value) params.price_list_type = typeFilter.value
    const response = await suppliersApi.getAll(params)
    suppliers.value = response.data || []
  } catch (error: any) {
    showSnackbar('Ошибка загрузки поставщиков: ' + error.message, 'error')
  } finally {
    loading.value = false
  }
}

const openCreateDialog = () => {
  editMode.value = false
  currentSupplier.value = null
  formData.value = {
    name: '',
    website: null,
    contact_person: null,
    contact_email: null,
    contact_phone: null,
    notes: null,
    is_active: true
  }
  showDialog.value = true
}

const openEditDialog = (supplier: Supplier) => {
  editMode.value = true
  currentSupplier.value = supplier
  formData.value = {
    name: supplier.name,
    website: supplier.website,
    contact_person: supplier.contact_person,
    contact_email: supplier.contact_email,
    contact_phone: supplier.contact_phone,
    notes: supplier.notes,
    is_active: supplier.is_active
  }
  showDialog.value = true
}

const closeDialog = () => {
  showDialog.value = false
  editMode.value = false
  currentSupplier.value = null
}

const saveSupplier = async () => {
  saving.value = true
  try {
    if (editMode.value && currentSupplier.value) {
      await suppliersApi.update(currentSupplier.value.id, formData.value)
      showSnackbar('Поставщик обновлен', 'success')
    } else {
      await suppliersApi.create(formData.value)
      showSnackbar('Поставщик создан', 'success')
    }
    closeDialog()
    await fetchSuppliers()
  } catch (error: any) {
    showSnackbar('Ошибка сохранения: ' + error.message, 'error')
  } finally {
    saving.value = false
  }
}

const archiveSupplier = async (supplier: Supplier) => {
  if (!confirm(`Архивировать поставщика "${supplier.name}"?`)) return
  
  try {
    await suppliersApi.archive(supplier.id)
    showSnackbar('Поставщик архивирован', 'success')
    await fetchSuppliers()
  } catch (error: any) {
    showSnackbar('Ошибка архивации: ' + error.message, 'error')
  }
}

const restoreSupplier = async (supplier: Supplier) => {
  try {
    await suppliersApi.restore(supplier.id)
    showSnackbar('Поставщик восстановлен', 'success')
    await fetchSuppliers()
  } catch (error: any) {
    showSnackbar('Ошибка восстановления: ' + error.message, 'error')
  }
}

const deleteSupplier = async (supplier: Supplier) => {
  const message = `Удалить поставщика "${supplier.name}"?\n\n` +
    `⚠️ ВНИМАНИЕ: Это удалит:\n` +
    `• Все прайс-листы (${supplier.price_lists_count || 0} шт.)\n` +
    `• Все версии прайс-листов\n` +
    `• Все позиции во всех версиях\n\n` +
    `Это действие НЕОБРАТИМО!`
  
  if (!confirm(message)) return
  
  try {
    await suppliersApi.delete(supplier.id)
    showSnackbar('Поставщик и все связанные данные удалены', 'success')
    await fetchSuppliers()
  } catch (error: any) {
    showSnackbar('Ошибка удаления: ' + error.message, 'error')
  }
}

const formatDate = (date: string) => {
  return format(new Date(date), 'dd MMM yyyy HH:mm', { locale: ru })
}

const showSnackbar = (message: string, color: string = 'success') => {
  snackbar.value = { show: true, message, color }
}

// Lifecycle
onMounted(() => {
  fetchSuppliers()
})

// Re-fetch when type filter changes
watch(typeFilter, () => {
  fetchSuppliers()
})
</script>

<style scoped>
@import '@/assets/soft-cards.css';

.suppliers-page :deep(.v-sheet.soft-content-card) {
  border-radius: 12px;
}
</style>
