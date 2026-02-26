import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import { finishedProductsApi } from '@/api/finishedProducts'
import type {
  FinishedProduct as Facade,
  FinishedProductQuote as FacadeQuote,
  SimilarFinishedProductQuote as SimilarQuote,
  FinishedProductListParams as FacadeListParams,
  FinishedProductCreateData as FacadeCreateData,
  FinishedProductQuoteCreateData as QuoteCreateData,
  FinishedProductFilterOptions as FacadeFilterOptions,
} from '@/api/finishedProducts'

export const useFacadesStore = defineStore('facades', () => {
  // State
  const facades = ref<Facade[]>([])
  const currentFacade = ref<Facade | null>(null)
  const currentQuotes = ref<FacadeQuote[]>([])
  const similarQuotes = ref<SimilarQuote[]>([])
  const filterOptions = ref<FacadeFilterOptions | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const totalItems = ref(0)
  const currentPage = ref(1)
  const lastPage = ref(1)
  const perPage = ref(50)
  const error = ref<string | null>(null)

  // Filters state
  const filters = ref<FacadeListParams>({
    sort_by: 'name',
    sort_dir: 'asc',
    per_page: 50,
    page: 1,
  })

  // Computed
  const hasQuotes = computed(() => currentQuotes.value.length > 0)
  const quotesCount = computed(() => currentQuotes.value.length)

  // Actions

  async function fetchFacades(params?: Partial<FacadeListParams>) {
    loading.value = true
    error.value = null

    try {
      const mergedParams = { ...filters.value, ...params }
      const { data } = await finishedProductsApi.list(mergedParams)

      facades.value = data.data ?? []
      totalItems.value = data.total ?? 0
      currentPage.value = data.current_page ?? 1
      lastPage.value = data.last_page ?? 1
      perPage.value = data.per_page ?? 50
    } catch (e: any) {
      error.value = e.response?.data?.message ?? e.message ?? 'Ошибка загрузки фасадов'
      console.error('[FacadesStore] fetchFacades error:', e)
    } finally {
      loading.value = false
    }
  }

  async function fetchFacade(id: number) {
    loading.value = true
    error.value = null

    try {
      const { data } = await finishedProductsApi.get(id)
      currentFacade.value = data.facade
      currentQuotes.value = data.quotes ?? []
    } catch (e: any) {
      error.value = e.response?.data?.message ?? e.message ?? 'Ошибка загрузки фасада'
      console.error('[FacadesStore] fetchFacade error:', e)
    } finally {
      loading.value = false
    }
  }

  async function createFacade(payload: FacadeCreateData): Promise<Facade | null> {
    saving.value = true
    error.value = null

    try {
      const { data } = await finishedProductsApi.create(payload)
      facades.value.unshift(data)
      return data
    } catch (e: any) {
      error.value = e.response?.data?.message ?? e.message ?? 'Ошибка создания фасада'
      console.error('[FacadesStore] createFacade error:', e)
      return null
    } finally {
      saving.value = false
    }
  }

  async function updateFacade(id: number, payload: Partial<FacadeCreateData>): Promise<Facade | null> {
    saving.value = true
    error.value = null

    try {
      const { data } = await finishedProductsApi.update(id, payload)
      // Update in list
      const idx = facades.value.findIndex(f => f.id === id)
      if (idx !== -1) facades.value[idx] = data
      if (currentFacade.value?.id === id) currentFacade.value = data
      return data
    } catch (e: any) {
      error.value = e.response?.data?.message ?? e.message ?? 'Ошибка обновления фасада'
      console.error('[FacadesStore] updateFacade error:', e)
      return null
    } finally {
      saving.value = false
    }
  }

  async function deleteFacade(id: number): Promise<boolean> {
    saving.value = true
    error.value = null

    try {
      await finishedProductsApi.delete(id)
      facades.value = facades.value.filter(f => f.id !== id)
      if (currentFacade.value?.id === id) currentFacade.value = null
      return true
    } catch (e: any) {
      error.value = e.response?.data?.message ?? e.message ?? 'Ошибка удаления фасада'
      console.error('[FacadesStore] deleteFacade error:', e)
      return false
    } finally {
      saving.value = false
    }
  }

  // Quotes

  async function fetchQuotes(facadeId: number) {
    try {
      const { data } = await finishedProductsApi.getQuotes(facadeId)
      currentQuotes.value = data.quotes ?? []
    } catch (e: any) {
      console.error('[FacadesStore] fetchQuotes error:', e)
    }
  }

  async function createQuote(payload: QuoteCreateData): Promise<boolean> {
    saving.value = true
    error.value = null

    try {
      const { data } = await finishedProductsApi.createQuote(payload)
      currentQuotes.value.unshift(data)
      return true
    } catch (e: any) {
      error.value = e.response?.data?.message ?? e.message ?? 'Ошибка создания котировки'
      return false
    } finally {
      saving.value = false
    }
  }

  async function duplicateQuote(quoteId: number, opts: { target_material_id?: number; new_facade_class?: string }): Promise<boolean> {
    saving.value = true
    error.value = null

    try {
      await finishedProductsApi.duplicateQuote(quoteId, opts)
      return true
    } catch (e: any) {
      error.value = e.response?.data?.message ?? e.message ?? 'Ошибка дублирования котировки'
      return false
    } finally {
      saving.value = false
    }
  }

  async function revalidateQuote(quoteId: number, newPrice?: number): Promise<boolean> {
    saving.value = true
    error.value = null

    try {
      const { data } = await finishedProductsApi.revalidateQuote(quoteId, newPrice)
      // Refresh quotes if viewing the same facade
      if (currentFacade.value) {
        await fetchQuotes(currentFacade.value.id)
      }
      return true
    } catch (e: any) {
      error.value = e.response?.data?.message ?? e.message ?? 'Ошибка актуализации котировки'
      return false
    } finally {
      saving.value = false
    }
  }

  async function updateQuote(quoteId: number, data: { source_price?: number; source_unit?: string; conversion_factor?: number; article?: string | null; category?: string | null; description?: string | null }): Promise<boolean> {
    saving.value = true
    error.value = null
    try {
      await finishedProductsApi.updateQuote(quoteId, data)
      return true
    } catch (e: any) {
      error.value = e.response?.data?.message ?? e.message ?? 'Ошибка обновления котировки'
      return false
    } finally {
      saving.value = false
    }
  }

  async function deleteQuote(quoteId: number): Promise<boolean> {
    saving.value = true
    error.value = null
    try {
      await finishedProductsApi.deleteQuote(quoteId)
      return true
    } catch (e: any) {
      error.value = e.response?.data?.message ?? e.message ?? 'Ошибка удаления котировки'
      return false
    } finally {
      saving.value = false
    }
  }

  async function fetchSimilarQuotes(materialId: number, mode: 'strict' | 'extended' = 'strict') {
    try {
      const { data } = await finishedProductsApi.getSimilarQuotes(materialId, mode)
      similarQuotes.value = data.quotes ?? []
    } catch (e: any) {
      console.error('[FacadesStore] fetchSimilarQuotes error:', e)
      similarQuotes.value = []
    }
  }

  async function fetchFilterOptions() {
    if (filterOptions.value) return // cache
    try {
      const { data } = await finishedProductsApi.getFilterOptions()
      filterOptions.value = data
    } catch (e: any) {
      console.error('[FacadesStore] fetchFilterOptions error:', e)
    }
  }

  function setFilters(newFilters: Partial<FacadeListParams>) {
    filters.value = { ...filters.value, ...newFilters, page: 1 }
  }

  function resetFilters() {
    filters.value = { sort_by: 'name', sort_dir: 'asc', per_page: 50, page: 1 }
  }

  return {
    // State
    facades,
    currentFacade,
    currentQuotes,
    similarQuotes,
    filterOptions,
    loading,
    saving,
    error,
    totalItems,
    currentPage,
    lastPage,
    perPage,
    filters,

    // Computed
    hasQuotes,
    quotesCount,

    // Actions
    fetchFacades,
    fetchFacade,
    createFacade,
    updateFacade,
    deleteFacade,
    fetchQuotes,
    createQuote,
    duplicateQuote,
    revalidateQuote,
    updateQuote,
    deleteQuote,
    fetchSimilarQuotes,
    fetchFilterOptions,
    setFilters,
    resetFilters,
  }
})
