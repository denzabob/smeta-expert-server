import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import {
  fetchCatalog,
  parseByUrl,
  checkDomain,
  storeCatalogMaterial,
  refreshMaterial,
  fetchPriceObservations,
  addPriceObservation,
  addToLibrary,
  removeFromLibrary,
  updateLibraryEntry,
  mergeMaterials,
  recalculateTrust,
  type CatalogMaterial,
  type CatalogMeta,
  type CatalogMode,
  type CatalogFilters,
  type ParseResult,
  type DomainCheckResult,
  type PriceObservation,
  type StoreMaterialPayload,
  type AddObservationPayload,
  type MaterialType,
  type TrustLevel,
} from '@/api/materialCatalog'

export const useMaterialCatalogStore = defineStore('materialCatalog', () => {
  // ===== State =====
  const materials = ref<CatalogMaterial[]>([])
  const meta = ref<CatalogMeta | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  // Filters
  const mode = ref<CatalogMode>('own')
  const typeFilter = ref<MaterialType | null>(null)
  const regionId = ref<number | null>(null)
  const trustLevelFilter = ref<TrustLevel | null>(null)
  const recentDays = ref<number | null>(null)
  const search = ref<string>('')
  const page = ref(1)
  const perPage = ref(50)

  // Parse state
  const parseResult = ref<ParseResult | null>(null)
  const parsing = ref(false)
  const domainCheck = ref<DomainCheckResult | null>(null)
  const checkingDomain = ref(false)

  // Price observations
  const observations = ref<PriceObservation[]>([])
  const loadingObservations = ref(false)

  // ===== Getters =====
  const totalItems = computed(() => meta.value?.total ?? 0)
  const lastPage = computed(() => meta.value?.last_page ?? 1)
  const currentFilters = computed<CatalogFilters>(() => ({
    mode: mode.value,
    type: typeFilter.value,
    region_id: regionId.value,
    trust_level: trustLevelFilter.value,
    recent_days: recentDays.value,
    search: search.value || null,
    per_page: perPage.value,
    page: page.value,
  }))

  // ===== Actions =====

  async function loadCatalog(filters?: Partial<CatalogFilters>) {
    loading.value = true
    error.value = null
    try {
      if (filters) {
        if (filters.mode !== undefined) mode.value = filters.mode!
        if (filters.type !== undefined) typeFilter.value = filters.type!
        if (filters.region_id !== undefined) regionId.value = filters.region_id!
        if (filters.trust_level !== undefined) trustLevelFilter.value = filters.trust_level!
        if (filters.recent_days !== undefined) recentDays.value = filters.recent_days!
        if (filters.search !== undefined) search.value = filters.search ?? ''
        if (filters.page !== undefined) page.value = filters.page!
      }

      const response = await fetchCatalog(currentFilters.value)
      materials.value = response.data.data
      meta.value = response.data.meta
    } catch (e: any) {
      error.value = e.response?.data?.message || e.message || 'Ошибка загрузки каталога'
      console.error('loadCatalog error:', e)
    } finally {
      loading.value = false
    }
  }

  async function parseUrl(url: string, type: MaterialType, region?: number | null) {
    parsing.value = true
    parseResult.value = null
    try {
      const response = await parseByUrl(url, type, region)
      parseResult.value = response.data
      return response.data
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка парсинга'
      throw e
    } finally {
      parsing.value = false
    }
  }

  async function checkDomainSupport(url: string) {
    checkingDomain.value = true
    domainCheck.value = null
    try {
      const response = await checkDomain(url)
      domainCheck.value = response.data
      return response.data
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка проверки домена'
      throw e
    } finally {
      checkingDomain.value = false
    }
  }

  async function createMaterial(payload: StoreMaterialPayload) {
    loading.value = true
    try {
      const response = await storeCatalogMaterial(payload)
      // Add to local list
      await loadCatalog()
      return response.data
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка создания материала'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function refreshMat(id: number, region?: number | null) {
    try {
      const response = await refreshMaterial(id, region)

      // Optimistic in-place update so UI can reflect the new price immediately
      const refreshed = response.data?.material as Partial<CatalogMaterial> | undefined
      if (refreshed) {
        const current = materials.value.find(m => m.id === id)
        if (current) {
          Object.assign(current, refreshed)
          if (refreshed.price_per_unit !== undefined && refreshed.price_per_unit !== null) {
            current.latest_price = {
              price_per_unit: Number(refreshed.price_per_unit),
              observed_at: (refreshed as any).price_checked_at ?? new Date().toISOString(),
              source_url: refreshed.source_url ?? current.source_url ?? null,
              region_id: region ?? current.region_id ?? null,
              is_verified: true,
              currency: 'RUB',
            }
          }
        }
      }

      // Sync list in background to keep server state authoritative
      void loadCatalog()
      return response.data
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка обновления'
      throw e
    }
  }

  async function loadObservations(materialId: number, region?: number | null) {
    loadingObservations.value = true
    try {
      const response = await fetchPriceObservations(materialId, region)
      observations.value = response.data.observations
      return response.data.observations
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка загрузки наблюдений'
      throw e
    } finally {
      loadingObservations.value = false
    }
  }

  async function addObservation(materialId: number, payload: AddObservationPayload) {
    try {
      const response = await addPriceObservation(materialId, payload)
      // Reload observations and catalog
      await loadObservations(materialId, regionId.value)
      await loadCatalog()
      return response.data
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка добавления наблюдения'
      throw e
    }
  }

  async function toggleLibrary(materialId: number, add: boolean) {
    try {
      if (add) {
        await addToLibrary(materialId, { preferred_region_id: regionId.value ?? undefined })
      } else {
        await removeFromLibrary(materialId)
      }
      // Update local state
      const mat = materials.value.find(m => m.id === materialId)
      if (mat) {
        mat.in_library = add
      }
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка обновления библиотеки'
      throw e
    }
  }

  async function pinMaterial(materialId: number, pinned: boolean) {
    try {
      await updateLibraryEntry(materialId, { pinned })
      const mat = materials.value.find(m => m.id === materialId)
      if (mat) mat.pinned = pinned
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка'
      throw e
    }
  }

  async function merge(primaryId: number, duplicateId: number) {
    try {
      const response = await mergeMaterials(primaryId, duplicateId)
      await loadCatalog()
      return response.data
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка слияния'
      throw e
    }
  }

  async function recalcTrust(materialId: number) {
    try {
      const response = await recalculateTrust(materialId)
      const mat = materials.value.find(m => m.id === materialId)
      if (mat) {
        mat.trust_score = response.data.trust_score
        mat.trust_level = response.data.trust_level
      }
      return response.data
    } catch (e: any) {
      error.value = e.response?.data?.message || 'Ошибка пересчёта'
      throw e
    }
  }

  function setMode(newMode: CatalogMode) {
    mode.value = newMode
    page.value = 1
  }

  function resetFilters() {
    typeFilter.value = null
    trustLevelFilter.value = null
    recentDays.value = null
    search.value = ''
    page.value = 1
  }

  return {
    // State
    materials,
    meta,
    loading,
    error,
    mode,
    typeFilter,
    regionId,
    trustLevelFilter,
    recentDays,
    search,
    page,
    perPage,
    parseResult,
    parsing,
    domainCheck,
    checkingDomain,
    observations,
    loadingObservations,
    // Getters
    totalItems,
    lastPage,
    currentFilters,
    // Actions
    loadCatalog,
    parseUrl,
    checkDomainSupport,
    createMaterial,
    refreshMat,
    loadObservations,
    addObservation,
    toggleLibrary,
    pinMaterial,
    merge,
    recalcTrust,
    setMode,
    resetFilters,
  }
})
