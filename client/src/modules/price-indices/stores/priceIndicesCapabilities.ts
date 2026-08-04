import { computed, ref, shallowRef } from 'vue'
import { defineStore } from 'pinia'
import { fetchPriceIndicesCapabilities } from '../api/priceIndicesApi'
import type {
  PriceIndicesCapabilities,
  PriceIndicesCapabilityStatus,
} from '../types'

export const usePriceIndicesCapabilitiesStore = defineStore('priceIndicesCapabilities', () => {
  const status = ref<PriceIndicesCapabilityStatus>('idle')
  const capability = ref<PriceIndicesCapabilities | null>(null)
  const error = ref<string | null>(null)
  const loadedFor = ref<string | null>(null)
  const activeRequest = shallowRef<Promise<PriceIndicesCapabilityStatus> | null>(null)

  const isAvailable = computed(() => status.value === 'available')

  function load(scope = 'current-user'): Promise<PriceIndicesCapabilityStatus> {
    if (loadedFor.value !== null && loadedFor.value !== scope) {
      reset()
    }

    if (activeRequest.value) {
      return activeRequest.value
    }

    if (status.value !== 'idle') {
      return Promise.resolve(status.value)
    }

    loadedFor.value = scope
    status.value = 'loading'
    error.value = null

    const request = fetchPriceIndicesCapabilities()
      .then((response) => {
        capability.value = response.data
        status.value = response.data.enabled && response.data.access ? 'available' : 'error'
        return status.value
      })
      .catch((requestError: unknown) => {
        capability.value = null

        const httpStatus = getHttpStatus(requestError)
        if (httpStatus === 403) {
          status.value = 'forbidden'
        } else if (httpStatus === 404) {
          status.value = 'disabled'
        } else {
          status.value = 'error'
          error.value = getErrorMessage(requestError)
        }

        return status.value
      })
      .finally(() => {
        if (activeRequest.value === request) {
          activeRequest.value = null
        }
      })

    activeRequest.value = request
    return request
  }

  function refresh(scope = loadedFor.value ?? 'current-user'): Promise<PriceIndicesCapabilityStatus> {
    if (activeRequest.value) {
      return activeRequest.value.then(() => {
        reset()
        return load(scope)
      })
    }

    reset()
    return load(scope)
  }

  function reset(): void {
    status.value = 'idle'
    capability.value = null
    error.value = null
    loadedFor.value = null
    activeRequest.value = null
  }

  return {
    status,
    capability,
    error,
    loadedFor,
    activeRequest,
    isAvailable,
    load,
    refresh,
    reset,
  }
})

function getHttpStatus(error: unknown): number | null {
  if (typeof error !== 'object' || error === null || !('response' in error)) {
    return null
  }

  const response = (error as { response?: { status?: unknown } }).response
  return typeof response?.status === 'number' ? response.status : null
}

function getErrorMessage(error: unknown): string {
  if (error instanceof Error && error.message.trim() !== '') {
    return error.message
  }

  return 'Не удалось проверить доступность приложения.'
}
