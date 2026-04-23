<template>
  <PageContainer>
    <PageHeader title="Версии прайс-листа" :subtitle="priceList?.title">
      <template #prefix>
        <v-btn
          icon="mdi-arrow-left"
          variant="text"
          size="small"
          @click="goBack"
        />
      </template>

      <template #actions>
        <ButtonGroup>
          <v-btn
            variant="text"
            prepend-icon="mdi-refresh"
            class="text-none"
            :loading="loading"
            @click="fetchVersions"
          >
            Обновить
          </v-btn>
        </ButtonGroup>
      </template>
    </PageHeader>

    <SectionCard
      class="price-list-versions-card"
      subtitle="Список версий прайс-листа, их статусов, источников и связанных обоснований в общем MD3-паттерне."
    >
      <AppDataTableShell>
      <v-data-table
        :headers="headers"
        :items="versions"
        :loading="loading"
        class="price-list-versions-table"
        item-key="id"
        density="comfortable"
      >
        <!-- Статус -->
        <template #item.status="{ item }">
          <StatusChip
            :status="item.status"
            :color="getStatusColor(item.status)"
            :label="getStatusLabel(item.status)"
          />
        </template>

        <!-- Дата -->
        <template #item.effective_date="{ item }">
          <div>
            <div class="font-weight-medium">
              {{ formatDate(item.effective_date || item.captured_at || item.created_at) }}
            </div>
            <div v-if="item.source_type" class="text-caption text-medium-emphasis">
              <v-icon size="x-small" class="mr-1">{{ getSourceIcon(item.source_type) }}</v-icon>
              {{ getSourceLabel(item.source_type) }}
            </div>
          </div>
        </template>

        <!-- Источник -->
        <template #item.source="{ item }">
          <div v-if="item.manual_label" class="text-caption">
            {{ item.manual_label }}
          </div>
          <div v-else-if="getDisplayFilename(item)" class="text-caption">
            <a
              href="#"
              class="text-decoration-none text-primary"
              @click.prevent="downloadVersion(item)"
            >
              <v-icon size="x-small" class="mr-1">mdi-download</v-icon>
              {{ getDisplayFilename(item) }}
            </a>
          </div>
          <div v-else-if="item.source_url" class="text-caption">
            <a :href="item.source_url" target="_blank" class="text-decoration-none">
              Ссылка
            </a>
          </div>
          <span v-else class="text-medium-emphasis text-caption">—</span>
        </template>

        <!-- Размер -->
        <template #item.size_bytes="{ item }">
          <span v-if="item.size_bytes" class="text-caption">
            {{ formatBytes(item.size_bytes) }}
          </span>
          <span v-else class="text-medium-emphasis text-caption">—</span>
        </template>

        <!-- Позиции -->
        <template #item.items_count="{ item }">
          <StatusChip size="small" variant="text" color="grey">
            {{ item.items_count || 0 }}
          </StatusChip>
        </template>

        <!-- Обоснования -->
        <template #item.evidence="{ item }">
          <EvidenceBadge
            :count="item.evidence_links_count ?? 0"
            @click="openEvidenceDrawer(item)"
          />
        </template>

        <!-- Действия -->
        <template #item.actions="{ item }">
          <AppRowActions>
            <v-btn
              v-if="item.status === 'inactive' || item.status === 'archived'"
              color="success"
              variant="tonal"
              size="small"
              class="text-none"
              @click="activateVersion(item)"
              :loading="actionLoading[item.id]"
            >
              Активировать
            </v-btn>
            <v-btn
              v-if="item.status === 'inactive'"
              color="warning"
              variant="tonal"
              size="small"
              class="text-none"
              @click="archiveVersion(item)"
              :loading="actionLoading[item.id]"
            >
              Архивировать
            </v-btn>
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
                  @click="viewVersionDetails(item)"
                  prepend-icon="mdi-eye"
                >
                  Просмотр содержимого
                </v-list-item>
                <v-list-item
                  v-if="item.source_file_path"
                  @click="downloadVersion(item)"
                  prepend-icon="mdi-download"
                >
                  Скачать файл
                </v-list-item>
              </v-list>
            </v-menu>
          </AppRowActions>
        </template>

        <template #no-data>
          <AppStateBlock
            class="versions-empty-state"
            icon="mdi-history"
            title="Версии ещё не загружены"
            description="Здесь появятся импортированные, архивные и активные версии выбранного прайс-листа."
          >
            <template #actions>
            <v-btn variant="text" prepend-icon="mdi-refresh" class="text-none" @click="fetchVersions">
              Обновить список
            </v-btn>
            </template>
          </AppStateBlock>
        </template>
      </v-data-table>
      </AppDataTableShell>
    </SectionCard>

    <!-- Evidence drawer -->
    <EvidenceDrawer
      v-model="evidenceDrawer.open"
      :linkable-type="evidenceDrawer.linkableType"
      :linkable-id="evidenceDrawer.linkableId"
      :title="evidenceDrawer.title"
      @detached="onVersionEvidenceDetached"
    />

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
import { ref, onMounted, reactive } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { priceListsApi, type PriceList, type PriceListVersion } from '@/api/priceLists'
import { format } from 'date-fns'
import { ru } from 'date-fns/locale'
import ButtonGroup from '@/components/layout/ButtonGroup.vue'
import AppDataTableShell from '@/components/layout/AppDataTableShell.vue'
import AppRowActions from '@/components/layout/AppRowActions.vue'
import AppStateBlock from '@/components/layout/AppStateBlock.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import StatusChip from '@/components/layout/StatusChip.vue'
import EvidenceBadge from '@/components/evidence/EvidenceBadge.vue'
import EvidenceDrawer from '@/components/evidence/EvidenceDrawer.vue'

const route = useRoute()
const router = useRouter()

// State
const loading = ref(false)
const priceList = ref<PriceList | null>(null)
const versions = ref<PriceListVersion[]>([])
const actionLoading = reactive<Record<number, boolean>>({})

const evidenceDrawer = ref({
  open: false,
  linkableType: '',
  linkableId: 0,
  title: '',
})

const snackbar = ref({
  show: false,
  message: '',
  color: 'success'
})

// Table headers
const headers = [
  { title: 'Статус', key: 'status', sortable: true, width: '130px' },
  { title: 'Дата', key: 'effective_date', sortable: true, width: '200px' },
  { title: 'Источник', key: 'source', sortable: false },
  { title: 'Размер', key: 'size_bytes', sortable: true, width: '100px' },
  { title: 'Позиций', key: 'items_count', sortable: true, width: '110px' },
  { title: 'Обоснования', key: 'evidence', sortable: false, width: '120px' },
  { title: 'Действия', key: 'actions', sortable: false, width: '280px' }
]

function openEvidenceDrawer(version: PriceListVersion) {
  evidenceDrawer.value = {
    open: true,
    linkableType: 'price_list_version',
    linkableId: version.id,
    title: `Обоснования: версия от ${formatDate(version.effective_date || version.captured_at || version.created_at)}`,
  }
}

function onVersionEvidenceDetached() {
  const versionId = evidenceDrawer.value.linkableId
  const version = versions.value.find(v => v.id === versionId)
  if (version) version.evidence_links_count = Math.max(0, (version.evidence_links_count ?? 1) - 1)
}

// Methods
const fetchPriceList = async () => {
  try {
    const priceListId = Number(route.params.priceListId)
    priceList.value = await priceListsApi.getById(priceListId)
  } catch (error: any) {
    showSnackbar('Ошибка загрузки прайс-листа: ' + error.message, 'error')
  }
}

const fetchVersions = async () => {
  loading.value = true
  try {
    const priceListId = Number(route.params.priceListId)
    const response = await priceListsApi.getVersions(priceListId)
    versions.value = response.data || []
  } catch (error: any) {
    showSnackbar('Ошибка загрузки версий: ' + error.message, 'error')
  } finally {
    loading.value = false
  }
}

const activateVersion = async (version: PriceListVersion) => {
  if (!confirm(`Активировать версию от ${formatDate(version.effective_date || version.created_at)}?`)) return
  
  actionLoading[version.id] = true
  try {
    const priceListId = Number(route.params.priceListId)
    await priceListsApi.activateVersion(priceListId, version.id)
    showSnackbar('Версия активирована', 'success')
    await fetchVersions()
  } catch (error: any) {
    showSnackbar('Ошибка активации: ' + error.message, 'error')
  } finally {
    actionLoading[version.id] = false
  }
}

const archiveVersion = async (version: PriceListVersion) => {
  if (!confirm(`Архивировать версию от ${formatDate(version.effective_date || version.created_at)}?`)) return
  
  actionLoading[version.id] = true
  try {
    const priceListId = Number(route.params.priceListId)
    await priceListsApi.archiveVersion(priceListId, version.id)
    showSnackbar('Версия архивирована', 'success')
    await fetchVersions()
  } catch (error: any) {
    showSnackbar('Ошибка архивации: ' + error.message, 'error')
  } finally {
    actionLoading[version.id] = false
  }
}

const downloadVersion = async (version: PriceListVersion) => {
  try {
    const blob = await priceListsApi.downloadVersion(version.id)
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = getFileName(version.source_file_path || `version_${version.id}.xlsx`) || ''
    link.click()
    window.URL.revokeObjectURL(url)
    showSnackbar('Файл загружен', 'success')
  } catch (error: any) {
    showSnackbar('Ошибка загрузки файла: ' + error.message, 'error')
  }
}

const viewVersionDetails = (version: PriceListVersion) => {
  router.push({
    name: 'price-list-version-show',
    params: {
      supplierId: route.params.supplierId,
      priceListId: route.params.priceListId,
      versionId: version.id
    }
  })
}

const goBack = () => {
  router.push({
    name: 'supplier-show',
    params: { id: route.params.supplierId }
  })
}

const getStatusColor = (status: string) => {
  switch (status) {
    case 'active': return 'success'
    case 'inactive': return 'warning'
    case 'archived': return 'grey'
    default: return 'grey'
  }
}

const getStatusLabel = (status: string) => {
  switch (status) {
    case 'active': return 'Активна'
    case 'inactive': return 'Неактивна'
    case 'archived': return 'Архив'
    default: return status
  }
}

const getSourceIcon = (sourceType: string) => {
  switch (sourceType) {
    case 'file': return 'mdi-file'
    case 'manual': return 'mdi-pencil'
    case 'url': return 'mdi-web'
    default: return 'mdi-help'
  }
}

const getSourceLabel = (sourceType: string) => {
  switch (sourceType) {
    case 'file': return 'Файл'
    case 'manual': return 'Ручной ввод'
    case 'url': return 'URL'
    default: return sourceType
  }
}

const getFileName = (path: string) => {
  if (!path) return ''
  const parts = path.split(/[/\\]/)
  return parts[parts.length - 1]
}

const getDisplayFilename = (ver: PriceListVersion) => {
  if (ver.original_filename) return ver.original_filename
  if (ver.source_file_path) return getFileName(ver.source_file_path)
  if (ver.file_path) return getFileName(ver.file_path)
  return ''
}

const formatBytes = (bytes: number) => {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

const formatDate = (date: string) => {
  return format(new Date(date), 'dd MMM yyyy HH:mm', { locale: ru })
}

const showSnackbar = (message: string, color: string = 'success') => {
  snackbar.value = { show: true, message, color }
}

// Lifecycle
onMounted(async () => {
  await fetchPriceList()
  await fetchVersions()
})
</script>

<style scoped>
.price-list-versions-card {
  background: color-mix(in srgb, var(--md-sys-color-surface-container-low) 94%, transparent);
}

.price-list-versions-table :deep(.v-table__wrapper) {
  border-radius: var(--ds-radius-12);
  border: 1px solid var(--ds-border-color);
  background: rgba(var(--v-theme-surface-container-lowest), 0.78);
}

.versions-empty-state {
  border: 1px dashed var(--ds-border-color);
  border-radius: var(--ds-radius-16);
  background: rgba(var(--v-theme-surface-container-high), 0.48);
}
</style>
