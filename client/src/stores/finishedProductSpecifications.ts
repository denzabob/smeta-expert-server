import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import {
  finishedProductSpecificationsApi,
  type FinishedProductSpecification,
  type FinishedProductSpecificationFormData,
  type FinishedProductSpecificationListParams,
} from '@/api/finishedProductSpecifications'

type ValidationErrors = Record<string, string[]>

function extractValidationErrors(error: any): ValidationErrors {
  return error?.response?.data?.errors ?? {}
}

export const useFinishedProductSpecificationsStore = defineStore('finished-product-specifications', () => {
  const items = ref<FinishedProductSpecification[]>([])
  const currentItem = ref<FinishedProductSpecification | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<string | null>(null)
  const validationErrors = ref<ValidationErrors>({})
  const totalItems = ref(0)
  const currentPage = ref(1)
  const lastPage = ref(1)
  const perPage = ref(25)
  const filters = ref<FinishedProductSpecificationListParams>({
    product_type: 'facade',
    per_page: 25,
    page: 1,
  })

  const hasItems = computed(() => items.value.length > 0)

  function resetValidationErrors() {
    validationErrors.value = {}
  }

  function setFilters(next: Partial<FinishedProductSpecificationListParams>) {
    filters.value = { ...filters.value, ...next }
  }

  async function fetchItems(params?: Partial<FinishedProductSpecificationListParams>) {
    loading.value = true
    error.value = null

    try {
      const merged = { ...filters.value, ...params, product_type: 'facade' as const }
      const { data } = await finishedProductSpecificationsApi.list(merged)
      items.value = data.data
      totalItems.value = Number(data.meta?.total ?? data.data.length)
      currentPage.value = Number(data.meta?.current_page ?? 1)
      lastPage.value = Number(data.meta?.last_page ?? 1)
      perPage.value = Number(data.meta?.per_page ?? merged.per_page ?? 25)
      filters.value = merged
    } catch (e: any) {
      error.value = e?.response?.data?.message ?? e?.message ?? 'Не удалось загрузить спецификации фасадов'
    } finally {
      loading.value = false
    }
  }

  async function fetchItem(id: number) {
    loading.value = true
    error.value = null

    try {
      const { data } = await finishedProductSpecificationsApi.get(id)
      currentItem.value = data.data
      return data.data
    } catch (e: any) {
      error.value = e?.response?.data?.message ?? e?.message ?? 'Не удалось загрузить спецификацию'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function createItem(payload: FinishedProductSpecificationFormData) {
    saving.value = true
    error.value = null
    resetValidationErrors()

    try {
      const { data } = await finishedProductSpecificationsApi.create(payload)
      items.value.unshift(data.data)
      return data.data
    } catch (e: any) {
      validationErrors.value = extractValidationErrors(e)
      error.value = e?.response?.data?.message ?? e?.message ?? 'Не удалось создать спецификацию'
      throw e
    } finally {
      saving.value = false
    }
  }

  async function updateItem(id: number, payload: Partial<FinishedProductSpecificationFormData>) {
    saving.value = true
    error.value = null
    resetValidationErrors()

    try {
      const { data } = await finishedProductSpecificationsApi.update(id, payload)
      const index = items.value.findIndex((item) => item.id === id)
      if (index !== -1) items.value[index] = data.data
      if (currentItem.value?.id === id) currentItem.value = data.data
      return data.data
    } catch (e: any) {
      validationErrors.value = extractValidationErrors(e)
      error.value = e?.response?.data?.message ?? e?.message ?? 'Не удалось обновить спецификацию'
      throw e
    } finally {
      saving.value = false
    }
  }

  async function deleteItem(id: number) {
    saving.value = true
    error.value = null

    try {
      await finishedProductSpecificationsApi.delete(id)
      items.value = items.value.filter((item) => item.id !== id)
      if (currentItem.value?.id === id) currentItem.value = null
    } catch (e: any) {
      error.value = e?.response?.data?.message ?? e?.message ?? 'Не удалось удалить спецификацию'
      throw e
    } finally {
      saving.value = false
    }
  }

  return {
    items,
    currentItem,
    loading,
    saving,
    error,
    validationErrors,
    totalItems,
    currentPage,
    lastPage,
    perPage,
    filters,
    hasItems,
    setFilters,
    resetValidationErrors,
    fetchItems,
    fetchItem,
    createItem,
    updateItem,
    deleteItem,
  }
})
