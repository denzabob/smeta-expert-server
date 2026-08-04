import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PriceIndicesCapabilitiesResponse } from '../types'
import { usePriceIndicesCapabilitiesStore } from './priceIndicesCapabilities'

const fetchCapabilities = vi.hoisted(() => vi.fn())

vi.mock('../api/priceIndicesApi', () => ({
  fetchPriceIndicesCapabilities: fetchCapabilities,
}))

const availableResponse: PriceIndicesCapabilitiesResponse = {
  data: {
    application: 'price_indices',
    enabled: true,
    access: true,
    admin_only: true,
    stage: 'skeleton',
  },
}

describe('Price Indices capabilities store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    fetchCapabilities.mockReset()
  })

  it('starts closed and becomes available only after a successful response', async () => {
    fetchCapabilities.mockResolvedValue(availableResponse)
    const store = usePriceIndicesCapabilitiesStore()

    expect(store.status).toBe('idle')
    expect(store.isAvailable).toBe(false)

    await store.load('admin:1')

    expect(store.status).toBe('available')
    expect(store.capability).toEqual(availableResponse.data)
    expect(store.isAvailable).toBe(true)
  })

  it.each([
    [403, 'forbidden'],
    [404, 'disabled'],
  ] as const)('maps HTTP %s to %s', async (httpStatus, expectedStatus) => {
    fetchCapabilities.mockRejectedValue({ response: { status: httpStatus } })
    const store = usePriceIndicesCapabilitiesStore()

    await store.load('admin:1')

    expect(store.status).toBe(expectedStatus)
    expect(store.isAvailable).toBe(false)
  })

  it('maps a network failure to error and keeps access closed', async () => {
    fetchCapabilities.mockRejectedValue(new Error('Network Error'))
    const store = usePriceIndicesCapabilitiesStore()

    await store.load('admin:1')

    expect(store.status).toBe('error')
    expect(store.error).toBe('Network Error')
    expect(store.isAvailable).toBe(false)
  })

  it('deduplicates parallel requests', async () => {
    let resolveRequest!: (response: PriceIndicesCapabilitiesResponse) => void
    fetchCapabilities.mockReturnValue(new Promise((resolve) => {
      resolveRequest = resolve
    }))
    const store = usePriceIndicesCapabilitiesStore()

    const first = store.load('admin:1')
    const activeRequest = store.activeRequest
    const second = store.load('admin:1')

    expect(store.activeRequest).toBe(activeRequest)
    expect(fetchCapabilities).toHaveBeenCalledTimes(1)

    resolveRequest(availableResponse)
    await Promise.all([first, second])
  })

  it('does not repeat a terminal request until refresh is explicit', async () => {
    fetchCapabilities.mockResolvedValue(availableResponse)
    const store = usePriceIndicesCapabilitiesStore()

    await store.load('admin:1')
    await store.load('admin:1')
    expect(fetchCapabilities).toHaveBeenCalledTimes(1)

    await store.refresh('admin:1')
    expect(fetchCapabilities).toHaveBeenCalledTimes(2)
  })
})
