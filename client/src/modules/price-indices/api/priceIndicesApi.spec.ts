import { describe, expect, it, vi } from 'vitest'
import type { AxiosInstance } from 'axios'
import { createPriceIndicesApi } from './priceIndicesApi'

vi.mock('@/api/axios', () => ({ default: {} }))

function clientMock() {
  return {
    get: vi.fn().mockResolvedValue({ data: { data: [], meta: { total: 0 } } }),
    post: vi.fn().mockResolvedValue({ data: { data: { coefficient: '1.000000000000' } } }),
  } as unknown as AxiosInstance
}

describe('priceIndicesApi user adapter', () => {
  it('uses only user routes and passes bounded series filters', async () => {
    const client = clientMock()
    const adapter = createPriceIndicesApi(client)
    await adapter.searchSeries({ item_code_prefix: '05.10.10.101.АГ', page: 2, per_page: 25 })
    await adapter.getSeries('01900000-0000-7000-8000-000000000001')

    expect(client.get).toHaveBeenNthCalledWith(1, '/api/indices/series', {
      params: { item_code_prefix: '05.10.10.101.АГ', page: 2, per_page: 25 },
    })
    expect(client.get).toHaveBeenNthCalledWith(
      2,
      '/api/indices/series/01900000-0000-7000-8000-000000000001',
    )
    expect(client.get).not.toHaveBeenCalledWith(expect.stringContaining('/admin/'), expect.anything())
  })

  it('sends decimal input strings unchanged and does not add an import identifier', async () => {
    const client = clientMock()
    const adapter = createPriceIndicesApi(client)
    await adapter.calculate({
      series_public_id: '01900000-0000-7000-8000-000000000001',
      start_period: '2024-01',
      end_period: '2026-06',
      base_amount: '663940.00',
    })

    expect(client.post).toHaveBeenCalledWith('/api/indices/calculate', {
      series_public_id: '01900000-0000-7000-8000-000000000001',
      start_period: '2024-01',
      end_period: '2026-06',
      base_amount: '663940.00',
    })
  })
})
