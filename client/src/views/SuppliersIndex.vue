<template>
  <PageContainer>
    <PageHeader
      title="Поставщики"
      subtitle="Управление поставщиками и их прайс-листами"
    >
      <template #actions>
        <ButtonGroup>
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
        </ButtonGroup>
      </template>
    </PageHeader>

    <SectionCard
      class="suppliers-card"
    >
      <AppDataTableShell>
      <TableToolbar>
        <template #search>
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
        </template>
        <template #filters>
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
          <div class="suppliers-type-tabs" role="tablist" aria-label="Тип прайс-листов">
            <v-btn
              v-for="option in typeOptions"
              :key="option.key"
              :variant="typeFilter === option.value ? 'flat' : 'text'"
              :color="typeFilter === option.value ? 'primary' : undefined"
              class="suppliers-type-tabs__button"
              @click="typeFilter = option.value"
            >
              {{ option.label }}
            </v-btn>
          </div>
        </template>
      </TableToolbar>

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
          <StatusChip
            :status="item.is_active ? 'active' : 'inactive'"
            :label="item.is_active ? 'Активен' : 'Неактивен'"
          />
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
          <StatusChip
            :status="(item.active_versions_count ?? 0) > 0 ? 'active' : 'none'"
            :color="(item.active_versions_count ?? 0) > 0 ? 'primary' : 'grey'"
          >
            {{ item.active_versions_count || 0 }}
          </StatusChip>
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
          <AppRowActions dense>
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
          </AppRowActions>
        </template>
      </v-data-table>
      </AppDataTableShell>
    </SectionCard>

    <!-- Create/Edit Dialog -->
    <v-dialog v-model="showDialog" max-width="600px" persistent>
      <v-card class="supplier-dialog-card">
        <v-card-item class="supplier-dialog-card__header">
          <template #title>
            <div class="supplier-dialog-card__title">
              {{ editMode ? 'Редактирование поставщика' : 'Новый поставщик' }}
            </div>
          </template>
          <template #subtitle>
            <div class="supplier-dialog-card__subtitle">
              Заполните основные контактные данные и параметры доступности поставщика.
            </div>
          </template>
          <template #append>
            <v-btn icon="mdi-close" variant="text" size="small" @click="closeDialog" />
          </template>
        </v-card-item>

        <v-card-text class="supplier-dialog-card__content">
          <v-form ref="form">
            <AppFormSection
              title="Контактные данные"
              description="Основная информация поставщика для прайс-листов и связанных операций."
              compact
            >
              <div class="supplier-dialog-grid">
            <v-text-field
              v-model="formData.name"
              label="Название"
              variant="outlined"
              density="compact"
              :rules="[v => !!v || 'Обязательное поле']"
              required
            />
            <v-text-field
              v-model="formData.website"
              label="Сайт поставщика"
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
              label="Эл. почта"
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
              </div>

              <div class="supplier-dialog-status">
              <div class="supplier-dialog-status__text">
                <div class="supplier-dialog-status__title">Статус поставщика</div>
                <div class="supplier-dialog-status__subtitle">
                  Активные поставщики доступны для прайс-листов и связанных операций.
                </div>
              </div>
              <v-switch
                v-model="formData.is_active"
                label="Активен"
                color="primary"
                inset
              />
              </div>
            </AppFormSection>
          </v-form>
        </v-card-text>

        <AppActionFooter class="supplier-dialog-card__actions">
          <v-btn variant="text" @click="closeDialog">Отмена</v-btn>
          <v-btn
            color="primary"
            variant="flat"
            @click="saveSupplier"
            :loading="saving"
          >
            {{ editMode ? 'Сохранить изменения' : 'Создать поставщика' }}
          </v-btn>
        </AppActionFooter>
      </v-card>
    </v-dialog>

    <!-- Snackbar -->
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3000">
      {{ snackbar.message }}
      <template v-slot:actions>
        <v-btn variant="text" @click="snackbar.show = false">Закрыть</v-btn>
      </template>
    </v-snackbar>
  </PageContainer>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { suppliersApi, type Supplier, type SupplierCreatePayload } from '@/api/suppliers'
import { format } from 'date-fns'
import { ru } from 'date-fns/locale'
import ButtonGroup from '@/components/layout/ButtonGroup.vue'
import AppActionFooter from '@/components/layout/AppActionFooter.vue'
import AppDataTableShell from '@/components/layout/AppDataTableShell.vue'
import AppFormSection from '@/components/layout/AppFormSection.vue'
import AppRowActions from '@/components/layout/AppRowActions.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import StatusChip from '@/components/layout/StatusChip.vue'
import TableToolbar from '@/components/layout/TableToolbar.vue'

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

const typeOptions = [
  { key: 'all', label: 'Все', value: null },
  { key: 'operations', label: 'Операции', value: 'operations' },
  { key: 'materials', label: 'Материалы', value: 'materials' },
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
.suppliers-card {
  background: color-mix(in srgb, var(--md-sys-color-surface-container-low) 94%, transparent);
}

.suppliers-card :deep(.ds-table-toolbar) {
  margin-bottom: var(--ds-space-4);
}

.suppliers-type-tabs {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 6px;
  min-width: min(100%, 336px);
  padding: 6px;
  border-radius: var(--ds-radius-12);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  background: rgba(var(--v-theme-surface-container-lowest), 0.88);
}

.suppliers-type-tabs__button {
  min-height: 38px;
  border-radius: var(--ds-radius-10) !important;
  justify-content: center;
  font-weight: 600;
}

.suppliers-type-tabs__button:not(.bg-primary) {
  background: rgba(var(--v-theme-surface-container-high), 0.84);
  color: var(--ds-text-secondary);
}

.suppliers-card :deep(.soft-data-table .v-table__wrapper) {
  border-radius: var(--ds-radius-12);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.76);
  background: rgba(var(--v-theme-surface), 0.96);
}

.suppliers-card :deep(.soft-data-table thead th) {
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.68) !important;
  background: rgba(var(--v-theme-surface-container-high), 0.92) !important;
}

.suppliers-card :deep(.soft-data-table tbody td) {
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.44) !important;
}

.supplier-dialog-card {
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.76);
  border-radius: var(--md-sys-shape-corner-extra-large) !important;
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.03), transparent 140px),
    rgba(var(--v-theme-surface-container-low), 0.98);
}

.supplier-dialog-card__header {
  align-items: start;
  padding: 18px 20px 12px;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.6);
}

.supplier-dialog-card__title {
  font-size: 1.125rem;
  font-weight: 700;
  line-height: 1.3;
  color: var(--ds-text-primary);
}

.supplier-dialog-card__subtitle {
  margin-top: 4px;
  color: var(--ds-text-secondary);
}

.supplier-dialog-card__content {
  padding: 20px !important;
}

.supplier-dialog-grid {
  display: grid;
  gap: var(--ds-space-10);
}

.supplier-dialog-status {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--ds-space-12);
  margin-top: var(--ds-space-12);
  padding: 12px 14px;
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.68);
  border-radius: var(--ds-radius-12);
  background: rgba(var(--v-theme-surface-container-lowest), 0.84);
}

.supplier-dialog-status__text {
  min-width: 0;
}

.supplier-dialog-status__title {
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--ds-text-primary);
}

.supplier-dialog-status__subtitle {
  margin-top: 2px;
  color: var(--ds-text-secondary);
}

.supplier-dialog-card__actions {
  padding: 12px 20px 18px !important;
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.56);
}

@media (max-width: 760px) {
  .suppliers-type-tabs {
    width: 100%;
    min-width: 0;
  }

  .supplier-dialog-status {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
