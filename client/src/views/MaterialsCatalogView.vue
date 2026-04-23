<template>
  <PageContainer>
    <PageHeader class="catalog-page-header" title="Каталог материалов" :subtitle="modeSubtitle">
      <template #actions>
        <ButtonGroup class="catalog-page-actions">
          <v-btn color="primary" prepend-icon="mdi-form-select" class="text-none" @click="openAddManual">
            Добавить вручную
          </v-btn>
          <v-btn color="primary" variant="tonal" prepend-icon="mdi-link-plus" class="text-none" @click="openAddByUrl">
            Добавить по ссылке
          </v-btn>
          <v-btn variant="text" prepend-icon="mdi-refresh" class="text-none" :loading="store.loading" @click="reload">
            Обновить
          </v-btn>
        </ButtonGroup>
      </template>
    </PageHeader>

    <SectionCard class="catalog-card">
      <AppDataTableShell>
        <template #toolbar>
          <div class="catalog-controls">
        <div class="catalog-mode-surface">
          <div class="catalog-mode-header">
            <div>
              <div class="catalog-mode-title">Режим каталога</div>
              <div class="catalog-mode-caption">{{ modeSubtitle }}</div>
            </div>
            <StatusChip size="small" variant="tonal" color="primary">
              {{ store.totalItems }} поз.
            </StatusChip>
          </div>

          <div class="catalog-mode-tabs" role="tablist" aria-label="Режим каталога">
            <v-btn
              v-for="option in modeOptions"
              :key="option.value"
              :prepend-icon="option.icon"
              :variant="currentMode === option.value ? 'flat' : 'text'"
              :color="currentMode === option.value ? 'primary' : undefined"
              class="catalog-mode-tabs__button"
              @click="currentMode = option.value"
            >
              {{ option.label }}
            </v-btn>
          </div>
        </div>

        <div class="catalog-filter-surface">
          <TableToolbar>
            <template #search>
            <v-text-field
              v-model="searchInput"
              label="Поиск по названию или артикулу"
              prepend-inner-icon="mdi-magnify"
              variant="outlined"
              density="compact"
              hide-details
              clearable
              class="catalog-filter-grid__search"
              @click:clear="searchInput = ''"
              @keyup.enter="applySearch"
            />
            </template>
            <template #filters>
            <v-select
              v-model="typeFilter"
              :items="typeOptions"
              item-title="label"
              item-value="value"
              label="Тип"
              variant="outlined"
              density="compact"
              clearable
              hide-details
            />
            <v-select
              v-model="regionFilter"
              :items="regionOptions"
              item-title="label"
              item-value="value"
              label="Регион"
              variant="outlined"
              density="compact"
              clearable
              hide-details
            />
            </template>
            <template #actions>
              <div class="catalog-filter-actions">
              <v-btn
                variant="tonal"
                prepend-icon="mdi-tune-variant"
                class="text-none"
                @click="showAdvancedFilters = !showAdvancedFilters"
              >
                {{ showAdvancedFilters ? 'Скрыть фильтры' : 'Расширенные' }}
              </v-btn>
              <v-btn
                icon="mdi-filter-off"
                variant="text"
                class="catalog-filter-reset"
                title="Сбросить фильтры"
                @click="resetFilters"
              />
            </div>
            </template>
          </TableToolbar>

          <v-expand-transition>
            <div v-if="showAdvancedFilters" class="catalog-advanced-filters">
              <v-select
                v-model="trustFilter"
                :items="trustOptions"
                item-title="label"
                item-value="value"
                label="Надежность"
                variant="outlined"
                density="compact"
                clearable
                hide-details
              />
              <v-select
                v-model="recentDaysFilter"
                :items="recentOptions"
                item-title="label"
                item-value="value"
                label="Актуальность цены"
                variant="outlined"
                density="compact"
                clearable
                hide-details
              />
            </div>
          </v-expand-transition>

          <div v-if="activeFilterBadges.length" class="catalog-active-filters">
            <StatusChip
              v-for="badge in activeFilterBadges"
              :key="badge"
              size="small"
              variant="tonal"
            >
              {{ badge }}
            </StatusChip>
          </div>
        </div>
      </div>
        </template>

      <!-- Data table -->
      <v-data-table
        :headers="tableHeaders"
        :items="store.materials"
        :loading="store.loading"
        class="catalog-table"
        item-key="id"
        density="comfortable"
        :items-per-page="store.perPage"
        :page="store.page"
      >
        <!-- Name + article -->
        <template #item.name="{ item }">
          <div>
            <span class="font-weight-medium">{{ item.name }}</span>
            <div v-if="item.article" class="text-caption text-medium-emphasis">{{ item.article }}</div>
          </div>
        </template>

        <!-- Type -->
        <template #item.type="{ item }">
          <StatusChip size="x-small" :color="typeColor(item.type)" variant="tonal">
            {{ typeLabel(item.type) }}
          </StatusChip>
        </template>

        <!-- Trust -->
        <template #item.trust="{ item }">
          <TrustBadge :score="item.trust_score" :level="item.trust_level" :show-label="false" />
        </template>

        <!-- Price -->
        <template #item.price="{ item }">
          <div v-if="item.latest_price">
            <span class="font-weight-medium">
              {{ formatPrice(item.latest_price.price_per_unit) }} ₽
              <v-progress-circular
                v-if="refreshingId === item.id"
                indeterminate
                size="12"
                width="2"
                color="primary"
                class="ml-1"
              />
            </span>
            <div class="text-caption text-medium-emphasis">
              {{ formatDate(item.latest_price.observed_at) }}
              <v-icon v-if="item.latest_price.is_verified" icon="mdi-check-circle" color="success" size="x-small" class="ml-1" />
            </div>
          </div>
          <div v-else>
            <span class="font-weight-medium">
              {{ formatPrice(item.price_per_unit) }} ₽
              <v-progress-circular
                v-if="refreshingId === item.id"
                indeterminate
                size="12"
                width="2"
                color="primary"
                class="ml-1"
              />
            </span>
            <div class="text-caption text-disabled">нет наблюдений</div>
          </div>
        </template>

        <!-- Unit -->
        <template #item.unit="{ item }">
          {{ item.unit }}
        </template>

        <!-- Price actuality -->
        <template #item.price_checked_at="{ item }">
          <div v-if="item.price_checked_at">
            <span :class="priceAgeClass(item.price_checked_at)">
              {{ formatDate(item.price_checked_at) }}
            </span>
            <div class="text-caption text-medium-emphasis">
              {{ priceAgeLabel(item.price_checked_at) }}
            </div>
          </div>
          <span v-else class="text-disabled">—</span>
        </template>

        <!-- Visibility -->
        <template #item.visibility="{ item }">
          <v-icon
            :icon="visibilityIcon(item.visibility)"
            :color="visibilityColor(item.visibility)"
            size="small"
            :title="visibilityLabel(item.visibility)"
          />
        </template>

        <!-- Source -->
        <template #item.source_url="{ item }">
          <a v-if="item.source_url" :href="item.source_url" target="_blank" class="text-primary text-decoration-none">
            <v-icon size="small">mdi-open-in-new</v-icon>
          </a>
          <span v-else class="text-disabled">—</span>
        </template>

        <!-- Actions -->
        <template #item.actions="{ item }">
          <AppRowActions class="catalog-row-actions">
            <!-- Detail -->
            <v-tooltip text="Подробнее" location="top">
              <template #activator="{ props: tp }">
                <v-btn v-bind="tp" icon="mdi-information-outline" variant="text" size="small" @click="openDetail(item)" />
              </template>
            </v-tooltip>

            <!-- Add price -->
            <v-tooltip text="Добавить цену" location="top">
              <template #activator="{ props: tp }">
                <v-btn v-bind="tp" icon="mdi-currency-rub" variant="text" size="small" @click="openAddPrice(item)" />
              </template>
            </v-tooltip>

            <!-- More menu -->
            <v-menu>
              <template #activator="{ props: mp }">
                <v-btn v-bind="mp" icon="mdi-dots-vertical" variant="text" size="small" />
              </template>
              <v-list density="compact">
                <v-list-item
                  v-if="isOwner(item)"
                  prepend-icon="mdi-pencil-outline"
                  title="Редактировать"
                  @click="openEditFromTable(item)"
                />
                <v-list-item
                  v-if="canToggleLibrary(item)"
                  :prepend-icon="item.in_library ? 'mdi-bookmark-check' : 'mdi-bookmark-plus-outline'"
                  :title="item.in_library ? 'Убрать из библиотеки' : 'В библиотеку'"
                  @click="toggleLibrary(item)"
                />
                <v-list-item
                  prepend-icon="mdi-history"
                  title="История цен"
                  @click="openHistory(item)"
                />
                <v-list-item
                  v-if="isOwner(item)"
                  prepend-icon="mdi-refresh"
                  title="Обновить по ссылке"
                  :disabled="refreshingId === item.id"
                  @click="doRefresh(item)"
                />
                <v-list-item
                  v-if="isOwner(item) && item.visibility === 'private'"
                  prepend-icon="mdi-earth"
                  title="Сделать публичным"
                  @click="changeVisibility(item, 'public')"
                />
                <v-list-item
                  prepend-icon="mdi-shield-refresh"
                  title="Пересчитать Trust"
                  @click="recalcTrust(item)"
                />
              </v-list>
            </v-menu>
          </AppRowActions>
        </template>

        <template #no-data>
          <AppStateBlock
            icon="mdi-package-variant-closed"
            :title="noDataText"
            description="Попробуйте сменить режим каталога, скорректировать фильтры или добавить материал вручную."
            class="catalog-empty-state"
          >
            <template #actions>
              <div v-if="activeFilterBadges.length" class="catalog-empty-state__badges">
                <StatusChip
                  v-for="badge in activeFilterBadges"
                  :key="badge"
                  size="small"
                  variant="tonal"
                >
                  {{ badge }}
                </StatusChip>
              </div>
              <div class="catalog-empty-state__actions">
                <v-btn variant="text" @click="resetFilters">Сбросить фильтры</v-btn>
                <v-btn color="primary" @click="openAddManual">Добавить материал</v-btn>
              </div>
            </template>
          </AppStateBlock>
        </template>

        <!-- Footer pagination -->
        <template #bottom>
          <div class="d-flex align-center justify-center pa-3" v-if="store.lastPage > 1">
            <v-pagination
              v-model="paginationPage"
              :length="store.lastPage"
              :total-visible="7"
              density="comfortable"
            />
          </div>
        </template>
      </v-data-table>
      </AppDataTableShell>
    </SectionCard>

    <!-- Dialogs -->
    <AddMaterialDialog
      v-model="showAddDialog"
      :initial-flow="addFlow"
      :default-region-id="effectiveRegionId"
      :regions="regions"
      @created="onMaterialCreated"
      @use-existing="onUseExisting"
    />

    <PriceObservationDialog
      v-model="showPriceDialog"
      :material-id="selectedMaterialId"
      :default-region-id="effectiveRegionId"
      :regions="regions"
      @saved="onPriceAdded"
    />

    <PriceHistoryDialog
      v-model="showHistoryDialog"
      :material-id="selectedMaterialId"
      :material-name="selectedMaterialName"
      :default-region-id="effectiveRegionId"
      :regions="regions"
    />

    <MaterialDetailDialog
      v-model="showDetailDialog"
      :material-id="selectedMaterialId"
      :project-id="activeProjectId"
      @edit="onEditFromDetail"
    />

    <MaterialEditDialog
      v-model="showEditDialog"
      :material="editMaterial"
      :regions="regions"
      @saved="onMaterialEdited"
    />

    <!-- Snackbar -->
    <v-snackbar v-model="snackbar" :color="snackbarColor" :timeout="3000">
      {{ snackbarText }}
    </v-snackbar>
  </PageContainer>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useMaterialCatalogStore } from '@/stores/materialCatalog'
import api from '@/api/axios'
import AppDataTableShell from '@/components/layout/AppDataTableShell.vue'
import AppRowActions from '@/components/layout/AppRowActions.vue'
import AppStateBlock from '@/components/layout/AppStateBlock.vue'
import ButtonGroup from '@/components/layout/ButtonGroup.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import StatusChip from '@/components/layout/StatusChip.vue'
import TableToolbar from '@/components/layout/TableToolbar.vue'
import TrustBadge from '@/components/catalog/TrustBadge.vue'
import AddMaterialDialog from '@/components/catalog/AddMaterialDialog.vue'
import PriceObservationDialog from '@/components/catalog/PriceObservationDialog.vue'
import PriceHistoryDialog from '@/components/catalog/PriceHistoryDialog.vue'
import MaterialDetailDialog from '@/components/catalog/MaterialDetailDialog.vue'
import MaterialEditDialog from '@/components/catalog/MaterialEditDialog.vue'
import { updateCatalogMaterial } from '@/api/materialCatalog'
import type {
  CatalogMaterial,
  CatalogMode,
  MaterialDetail,
  MaterialType,
  MaterialVisibility,
  TrustLevel,
} from '@/api/materialCatalog'
import { useAuthStore } from '@/stores/auth'

const store = useMaterialCatalogStore()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

// ===== Local state =====
const searchInput = ref('')
const regions = ref<Array<{ id: number; region_name: string }>>([])
const userRegionId = ref<number | null>(null)
const refreshingId = ref<number | null>(null)
const showAdvancedFilters = ref(false)
const addFlow = ref<'url' | 'manual'>('url')

// Dialogs
const showAddDialog = ref(false)
const showPriceDialog = ref(false)
const showHistoryDialog = ref(false)
const showDetailDialog = ref(false)
const showEditDialog = ref(false)

// Project context from query (passed when navigating from project editor)
const activeProjectId = computed(() => {
  const raw = route.query.project_id
  const id = Number(Array.isArray(raw) ? raw[0] : raw)
  return Number.isFinite(id) && id > 0 ? id : null
})
const selectedMaterialId = ref<number | null>(null)
const selectedMaterialName = ref('')
const editMaterial = ref<MaterialDetail | null>(null)

// Snackbar
const snackbar = ref(false)
const snackbarText = ref('')
const snackbarColor = ref('success')

// ===== Filter bindings =====
const currentMode = computed({
  get: () => store.mode,
  set: (v: CatalogMode) => {
    store.setMode(v)
    reload()
  },
})

const typeFilter = computed({
  get: () => store.typeFilter,
  set: (v: MaterialType | null) => {
    store.typeFilter = v
    reload()
  },
})

const regionFilter = computed({
  get: () => store.regionId,
  set: (v: number | null) => {
    store.regionId = v
    reload()
  },
})

const trustFilter = computed({
  get: () => store.trustLevelFilter,
  set: (v: TrustLevel | null) => {
    store.trustLevelFilter = v
    reload()
  },
})

const recentDaysFilter = ref<number | null>(null)

const paginationPage = computed({
  get: () => store.page,
  set: (v: number) => {
    store.page = v
    reload()
  },
})

const effectiveRegionId = computed(() => store.regionId ?? userRegionId.value)

// ===== Options =====
const typeOptions = [
  { label: 'Плита', value: 'plate' },
  { label: 'Кромка', value: 'edge' },
  { label: 'Фасад', value: 'facade' },
  { label: 'Фурнитура', value: 'hardware' },
]

const trustOptions = [
  { label: 'Проверен', value: 'verified' },
  { label: 'Частично', value: 'partial' },
  { label: 'Не проверен', value: 'unverified' },
]

const recentOptions = [
  { label: 'До 7 дней', value: 7 },
  { label: 'До 30 дней', value: 30 },
  { label: 'До 90 дней', value: 90 },
]

const regionOptions = computed(() => {
  if (!Array.isArray(regions.value)) return []
  return regions.value.map(r => ({ label: r.region_name, value: r.id }))
})

const modeOptions: Array<{ value: CatalogMode; label: string; icon: string }> = [
  { value: 'own', label: 'Мои', icon: 'mdi-account' },
  { value: 'library', label: 'Библиотека', icon: 'mdi-bookmark' },
  { value: 'public', label: 'Общий каталог', icon: 'mdi-earth' },
  { value: 'curated', label: 'Кураторский', icon: 'mdi-star' },
]

// ===== Table config =====
const tableHeaders = [
  { title: 'Материал', key: 'name', sortable: true },
  { title: 'Тип', key: 'type', width: '100px', sortable: true },
  { title: 'Ед.', key: 'unit', width: '60px' },
  { title: 'Цена', key: 'price', width: '160px', sortable: false },
  { title: 'Актуальность', key: 'price_checked_at', width: '110px', sortable: true },
  { title: 'Надежность', key: 'trust', width: '100px', sortable: true },
  { title: '', key: 'visibility', width: '40px' },
  { title: 'URL', key: 'source_url', width: '50px' },
  { title: '', key: 'actions', width: '200px', sortable: false },
]

// ===== Computed =====
const modeSubtitle = computed(() => {
  const map: Record<string, string> = {
    own: 'Ваши материалы и парсерные',
    library: 'Ваша персональная библиотека',
    public: 'Публичные материалы всех пользователей',
    curated: 'Проверенные кураторами',
  }
  const count = store.totalItems
  return `${map[store.mode] || ''} (${count})`
})

const noDataText = computed(() => {
  const map: Record<string, string> = {
    own: 'У вас пока нет материалов',
    library: 'Ваша библиотека пуста. Добавьте материалы из каталога.',
    public: 'В каталоге пока нет публичных материалов',
    curated: 'Кураторские материалы скоро появятся',
  }
  return map[store.mode] || 'Нет данных'
})

const activeFilterBadges = computed(() => {
  const badges: string[] = []
  if (searchInput.value) badges.push(`Поиск: ${searchInput.value}`)
  if (store.typeFilter) badges.push(`Тип: ${typeLabel(store.typeFilter)}`)
  if (store.regionId) {
    const region = regionOptions.value.find(r => r.value === store.regionId)
    badges.push(`Регион: ${region?.label || store.regionId}`)
  }
  if (store.trustLevelFilter) {
    const trust = trustOptions.find(t => t.value === store.trustLevelFilter)
    badges.push(`Надежность: ${trust?.label || store.trustLevelFilter}`)
  }
  if (recentDaysFilter.value) badges.push(`Цена до ${recentDaysFilter.value} дн.`)
  return badges
})

// ===== Actions =====

async function reload() {
  await store.loadCatalog({
    recent_days: recentDaysFilter.value,
  })
}

function applySearch() {
  store.search = searchInput.value
  store.page = 1
  reload()
}

function resetFilters() {
  searchInput.value = ''
  recentDaysFilter.value = null
  store.resetFilters()
  reload()
}

function openAddManual() {
  addFlow.value = 'manual'
  showAddDialog.value = true
}

function openAddByUrl() {
  addFlow.value = 'url'
  showAddDialog.value = true
}

async function toggleLibrary(item: CatalogMaterial) {
  try {
    const willAdd = !item.in_library
    await store.toggleLibrary(item.id, willAdd)
    showSnack(willAdd ? 'Материал добавлен в библиотеку' : 'Материал убран из библиотеки')
  } catch (error: any) {
    showSnack(error.response?.data?.error || store.error || 'Не удалось обновить библиотеку', 'error')
  }
}

function openHistory(item: CatalogMaterial) {
  selectedMaterialId.value = item.id
  selectedMaterialName.value = item.name
  showHistoryDialog.value = true
}

function openAddPrice(item: CatalogMaterial) {
  selectedMaterialId.value = item.id
  showPriceDialog.value = true
}

async function doRefresh(item: CatalogMaterial) {
  refreshingId.value = item.id
  try {
    const result = await store.refreshMat(item.id, effectiveRegionId.value)
    if (result?.material?.price_per_unit !== undefined && result?.material?.price_per_unit !== null) {
      item.price_per_unit = Number(result.material.price_per_unit)
      item.price_checked_at = result.material.price_checked_at ?? item.price_checked_at
      item.latest_price = {
        price_per_unit: Number(result.material.price_per_unit),
        observed_at: result.material.price_checked_at ?? new Date().toISOString(),
        source_url: result.material.source_url ?? item.source_url ?? null,
        region_id: effectiveRegionId.value ?? item.region_id ?? null,
        is_verified: true,
        currency: 'RUB',
      }
    }

    if (result?.success === false) {
      showSnack(result.message || 'Не удалось обновить данные по ссылке', 'warning')
      return
    }

    if (result?.price_updated) {
      showSnack('Цена обновлена по ссылке')
    } else {
      showSnack('Ссылка проверена, но новая цена не найдена', 'warning')
    }
  } catch (error: any) {
    showSnack(error.response?.data?.message || store.error || 'Ошибка обновления', 'error')
  } finally {
    refreshingId.value = null
  }
}

async function changeVisibility(item: CatalogMaterial, visibility: MaterialVisibility) {
  try {
    await updateCatalogMaterial(item.id, { visibility })
    await reload()
    showSnack(visibility === 'public' ? 'Материал опубликован' : 'Видимость изменена')
  } catch (error: any) {
    showSnack(error.response?.data?.message || 'Не удалось изменить видимость материала', 'error')
  }
}

async function recalcTrust(item: CatalogMaterial) {
  try {
    const result = await store.recalcTrust(item.id)
    showSnack(`Trust Score: ${result.trust_score}`)
  } catch {
    showSnack('Ошибка пересчёта', 'error')
  }
}

function isOwner(item: CatalogMaterial): boolean {
  const currentUserId = auth.user?.id
  return !!currentUserId && item.user_id === currentUserId
}

function canToggleLibrary(item: CatalogMaterial): boolean {
  if (item.in_library) {
    return true
  }

  return item.visibility !== 'private' || isOwner(item)
}

function onMaterialCreated() {
  showSnack('Материал создан')
  reload()
}

function onUseExisting(material: CatalogMaterial) {
  // Add to library
  toggleLibrary(material)
}

function onPriceAdded() {
  showSnack('Наблюдение цены добавлено')
}

function openDetail(item: CatalogMaterial) {
  selectedMaterialId.value = item.id
  showDetailDialog.value = true
}

async function openMaterialFromQuery() {
  const rawId = route.query.material_id
  const materialId = Number(Array.isArray(rawId) ? rawId[0] : rawId)

  if (!Number.isFinite(materialId) || materialId <= 0) {
    return
  }

  selectedMaterialId.value = materialId
  showDetailDialog.value = true

  const query = { ...route.query }
  delete query.material_id

  await router.replace({
    query,
  })
}

async function openEditFromTable(item: CatalogMaterial) {
  try {
    const { fetchMaterialDetail } = await import('@/api/materialCatalog')
    const resp = await fetchMaterialDetail(item.id)
    editMaterial.value = resp.data.material
    showEditDialog.value = true
  } catch {
    showSnack('Не удалось загрузить данные материала', 'error')
  }
}

function onEditFromDetail(material: MaterialDetail) {
  showDetailDialog.value = false
  editMaterial.value = material
  showEditDialog.value = true
}

function onMaterialEdited() {
  showSnack('Материал обновлён')
  reload()
}

// ===== Helpers =====

function formatPrice(v: number | string): string {
  const num = typeof v === 'string' ? parseFloat(v) : v
  return num.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function formatDate(d: string | null): string {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('ru-RU')
}

function priceAgeLabel(d: string | null): string {
  if (!d) return ''
  const days = Math.floor((Date.now() - new Date(d).getTime()) / (1000 * 60 * 60 * 24))
  if (days === 0) return 'сегодня'
  if (days === 1) return 'вчера'
  if (days < 7) return `${days} дн. назад`
  if (days < 30) return `${Math.floor(days / 7)} нед. назад`
  if (days < 365) return `${Math.floor(days / 30)} мес. назад`
  return `${Math.floor(days / 365)} г. назад`
}

function priceAgeClass(d: string | null): string {
  if (!d) return ''
  const days = Math.floor((Date.now() - new Date(d).getTime()) / (1000 * 60 * 60 * 24))
  if (days <= 7) return 'text-success font-weight-medium'
  if (days <= 30) return 'font-weight-medium'
  if (days <= 90) return 'text-warning'
  return 'text-error'
}

function typeLabel(t: string): string {
  const map: Record<string, string> = { plate: 'Плита', edge: 'Кромка', facade: 'Фасад', hardware: 'Фурнитура' }
  return map[t] || t
}

function typeColor(t: string): string {
  const map: Record<string, string> = { plate: 'blue', edge: 'orange', facade: 'purple', hardware: 'teal' }
  return map[t] || 'grey'
}

function visibilityIcon(v: string): string {
  const map: Record<string, string> = { private: 'mdi-lock', public: 'mdi-earth', curated: 'mdi-star' }
  return map[v] || 'mdi-help'
}

function visibilityColor(v: string): string {
  const map: Record<string, string> = { private: 'grey', public: 'primary', curated: 'warning' }
  return map[v] || 'grey'
}

function visibilityLabel(v: string): string {
  const map: Record<string, string> = { private: 'Приватный', public: 'Публичный', curated: 'Кураторский' }
  return map[v] || v
}

function showSnack(text: string, color = 'success') {
  snackbarText.value = text
  snackbarColor.value = color
  snackbar.value = true
}

// ===== Init =====

async function loadRegions() {
  try {
    const res = await api.get('/api/regions')
    regions.value = Array.isArray(res.data) ? res.data : (res.data?.data ?? [])
  } catch {
    // Regions optional
  }
}

async function loadUserSettings() {
  try {
    const res = await api.get('/api/user/settings')
    if (res.data?.region_id) {
      userRegionId.value = res.data.region_id
      if (!store.regionId) {
        store.regionId = res.data.region_id
      }
    }
  } catch {
    // Settings optional
  }
}

onMounted(async () => {
  await Promise.all([loadRegions(), loadUserSettings()])
  await reload()
  await openMaterialFromQuery()
})

// Watch search debounce
let searchTimeout: ReturnType<typeof setTimeout> | null = null
watch(searchInput, (val) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    store.search = val
    store.page = 1
    reload()
  }, 400)
})

watch(recentDaysFilter, () => {
  reload()
})

watch(
  () => route.query.material_id,
  async () => {
    await openMaterialFromQuery()
  }
)
</script>

<style scoped>
.catalog-card {
  background: color-mix(in srgb, var(--md-sys-color-surface-container-low) 96%, transparent);
}

.catalog-card :deep(.v-card-text) {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-12);
}

.catalog-controls {
  display: grid;
  gap: var(--ds-space-12);
}

.catalog-mode-surface,
.catalog-filter-surface {
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.78);
  border-radius: var(--ds-radius-12);
  background: rgba(var(--v-theme-surface-container-lowest), 0.9);
}

.catalog-mode-surface {
  padding: var(--ds-space-12);
}

.catalog-mode-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--ds-space-12);
  margin-bottom: var(--ds-space-10);
}

.catalog-mode-title {
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--ds-text-secondary);
}

.catalog-mode-caption {
  margin-top: 4px;
  font-size: 0.95rem;
  color: var(--ds-text-secondary);
}

.catalog-mode-tabs {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
  width: 100%;
}

.catalog-mode-tabs__button {
  width: 100%;
  min-height: 40px;
  border-radius: var(--ds-radius-10) !important;
  background: rgba(var(--v-theme-surface-container-high), 0.86);
  color: var(--ds-text-secondary);
  justify-content: center;
  padding-inline: 16px;
  font-weight: 600;
}

.catalog-mode-tabs__button:hover {
  background: rgba(var(--v-theme-surface-container-highest), 0.96);
}

.catalog-filter-surface {
  padding: var(--ds-space-12);
}

.catalog-filter-grid__search {
  min-width: 0;
}

.catalog-filter-surface :deep(.ds-table-toolbar) {
  padding-bottom: 0;
}

.catalog-filter-surface :deep(.ds-table-toolbar__filters) {
  min-width: min(100%, 420px);
}

.catalog-filter-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--ds-space-4);
}

.catalog-filter-reset {
  color: var(--ds-text-secondary);
}

.catalog-advanced-filters {
  display: grid;
  grid-template-columns: repeat(2, minmax(200px, 1fr));
  gap: var(--ds-space-10);
  padding-top: var(--ds-space-10);
}

.catalog-active-filters {
  display: flex;
  flex-wrap: wrap;
  gap: var(--ds-space-6);
  padding-top: var(--ds-space-10);
}

.catalog-row-actions {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 6px;
  border-radius: var(--ds-radius-10);
  background: rgba(var(--v-theme-surface-container-low), 0.86);
}

.catalog-row-actions :deep(.v-btn) {
  color: var(--ds-text-secondary);
}

.catalog-row-actions :deep(.v-btn:hover) {
  background: rgba(var(--v-theme-primary), 0.08);
}

.catalog-table :deep(.v-table__wrapper) {
  border-radius: var(--ds-radius-12);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.78);
  background: rgba(var(--v-theme-surface), 0.96);
}

.catalog-table :deep(thead th) {
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.68) !important;
  background: rgba(var(--v-theme-surface-container-high), 0.92) !important;
}

.catalog-table :deep(tbody td) {
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.44) !important;
}

.catalog-empty-state {
  margin: var(--ds-space-12);
  border: 1px dashed rgba(var(--v-theme-outline-variant), 0.78);
  border-radius: var(--ds-radius-12);
  background: rgba(var(--v-theme-surface-container-high), 0.62);
}

.catalog-empty-state__badges {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: var(--ds-space-6);
}

.catalog-empty-state__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: var(--ds-space-8);
}

@media (max-width: 1260px) {
  .catalog-mode-tabs {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .catalog-filter-actions {
    justify-content: flex-start;
  }
}

@media (max-width: 760px) {
  .catalog-page-header {
    align-items: stretch;
  }

  .catalog-page-header :deep(.saas-page-header__actions) {
    width: 100%;
    min-width: 0;
  }

  .catalog-page-actions {
    display: grid;
    grid-template-columns: 1fr;
    width: 100%;
  }

  .catalog-page-actions :deep(.v-btn) {
    width: 100%;
    min-width: 0;
  }

  .catalog-mode-tabs {
    grid-template-columns: 1fr;
  }

  .catalog-mode-header {
    flex-direction: column;
    align-items: flex-start;
  }

  .catalog-advanced-filters {
    grid-template-columns: 1fr;
  }

  .catalog-filter-actions {
    width: 100%;
  }

  .catalog-empty-state__actions {
    width: 100%;
  }
}
</style>
