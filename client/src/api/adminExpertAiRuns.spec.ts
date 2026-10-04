import { afterEach, describe, expect, it, vi } from 'vitest'
import api from '@/api/axios'
import { adminExpertAiRunsApi } from './adminExpertAiRuns'

vi.mock('@/api/axios', () => ({ default: { get: vi.fn() } }))

afterEach(() => vi.clearAllMocks())

describe('admin Expert AI runs API', () => {
  it('loads runs with server-side filters and omits empty query values', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } } } as never)

    await adminExpertAiRunsApi.list({ run_id: '1234abcd', status: 'failed', stage: '', page: 2, per_page: 25 })

    expect(api.get).toHaveBeenCalledWith('/api/admin/expert-ai-runs', {
      params: { run_id: '1234abcd', status: 'failed', page: 2, per_page: 25 },
    })
  })

  it('loads run detail from the run id endpoint', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { run: { run_id: 'a/b' }, events: [] } } as never)

    await adminExpertAiRunsApi.show('a/b')

    expect(api.get).toHaveBeenCalledWith('/api/admin/expert-ai-runs/a%2Fb')
  })
})
