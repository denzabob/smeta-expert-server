import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getMyStorageUsage } from './billing'
import api from './axios'

vi.mock('./axios', () => ({ default: { get: vi.fn() } }))

beforeEach(() => vi.clearAllMocks())

describe('account storage summary adapter', () => {
  it('loads the shared account totals and preserves the existing settings contract', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: {
      used_bytes: 19, reserved_bytes: 1, limit_bytes: 20, available_bytes: 0,
      percent: 100, files_count: 3, is_unlimited: false, over_quota: false,
      modules: { expert: { bytes: 8, count: 1 }, smeta: { bytes: 11, count: 2 } },
      categories: { images: { bytes: 8, count: 1 }, files: { bytes: 11, count: 2 } },
    } })
    const usage = await getMyStorageUsage()
    expect(api.get).toHaveBeenCalledWith('/api/account/storage')
    expect(usage).toEqual({ used_bytes: 19, reserved_bytes: 1, limit_bytes: 20,
      remaining_bytes: 0, usage_percent: 100, materials_count: 3, is_unlimited: false,
      is_over_limit: false, limit_available: true, limit_visible: true, enforcement_enabled: true })
  })

  it('preserves an unlimited plan without inventing a numeric limit', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { used_bytes: 10, reserved_bytes: 0,
      limit_bytes: null, available_bytes: null, percent: null, files_count: 1,
      is_unlimited: true, over_quota: false } })
    expect(await getMyStorageUsage()).toMatchObject({ limit_bytes: null, remaining_bytes: null,
      usage_percent: null, is_unlimited: true })
  })
})
