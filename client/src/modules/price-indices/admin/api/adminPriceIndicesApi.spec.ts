import { describe, expect, it, vi } from 'vitest'
import type { AxiosInstance } from 'axios'
import { createAdminPriceIndicesApi } from './adminPriceIndicesApi'

vi.mock('@/api/axios', () => ({ default: {} }))

function clientMock() {
  return {
    get: vi.fn().mockResolvedValue({ data: { data: [], meta: { total: 0 } } }),
    post: vi.fn().mockResolvedValue({ data: { data: { public_id: 'uuid', status: 'pending' }, meta: { queued: true, cached: false } } }),
  } as unknown as AxiosInstance
}

describe('adminPriceIndicesApi', () => {
  it('uses public UUID paths for preview/import lifecycle endpoints', async () => {
    const client = clientMock(); const api = createAdminPriceIndicesApi(client)
    await api.startPreview('file-uuid'); await api.getPreview('preview-uuid'); await api.getPreviewResult('preview-uuid')
    await api.retryPreview('preview-uuid'); await api.startImport('file-uuid'); await api.getImport('import-uuid')
    await api.retryImport('import-uuid'); await api.publishImport('import-uuid'); await api.getActiveImport('dataset-uuid')
    expect(client.post).toHaveBeenCalledWith('/api/indices/admin/source-files/file-uuid/preview')
    expect(client.get).toHaveBeenCalledWith('/api/indices/admin/previews/preview-uuid')
    expect(client.get).toHaveBeenCalledWith('/api/indices/admin/previews/preview-uuid/result')
    expect(client.post).toHaveBeenCalledWith('/api/indices/admin/previews/preview-uuid/retry')
    expect(client.post).toHaveBeenCalledWith('/api/indices/admin/source-files/file-uuid/imports')
    expect(client.post).toHaveBeenCalledWith('/api/indices/admin/imports/import-uuid/retry')
    expect(client.post).toHaveBeenCalledWith('/api/indices/admin/imports/import-uuid/publish')
    expect(client.get).toHaveBeenCalledWith('/api/indices/admin/datasets/dataset-uuid/active-import')
  })

  it('passes bounded server-side filters and pagination', async () => {
    const client = clientMock(); const api = createAdminPriceIndicesApi(client)
    await api.listSourceFiles({ dataset: 'dataset-uuid', source: 'source-uuid', page: 3, per_page: 25, sort: 'detected_at', direction: 'desc' })
    await api.listImports({ dataset_public_id: 'dataset-uuid', status: 'failed', page: 2, per_page: 50, sort: 'created_at', direction: 'desc' })
    await api.getImportIssues('import-uuid', 4, 20)
    await api.listImportSeries('import-uuid', { item_code_prefix: '31.02', page: 2, per_page: 25, sort: 'item_code' })
    await api.listImportSeries('import-uuid', { item_code: '05.10.10.101.АГ', per_page: 25 })
    await api.listImportSeries('import-uuid', { item_name: 'кухонной мебели', per_page: 25 })
    await api.getImportObservations('import-uuid', { series_public_id: 'series-uuid', period_from: '2024-01-01', period_to: '2026-06-01', page: 1, per_page: 100 })
    expect(client.get).toHaveBeenCalledWith('/api/indices/admin/source-files', { params: expect.objectContaining({ dataset: 'dataset-uuid', page: 3, per_page: 25 }) })
    expect(client.get).toHaveBeenCalledWith('/api/indices/admin/imports', { params: expect.objectContaining({ dataset_public_id: 'dataset-uuid', status: 'failed', page: 2 }) })
    expect(client.get).toHaveBeenCalledWith('/api/indices/admin/imports/import-uuid/issues', { params: { page: 4, per_page: 20, sort: 'created_at', direction: 'asc' } })
    expect(client.get).toHaveBeenCalledWith('/api/indices/admin/imports/import-uuid/series', { params: expect.objectContaining({ item_code_prefix: '31.02', page: 2, per_page: 25 }) })
    expect(client.get).toHaveBeenCalledWith('/api/indices/admin/imports/import-uuid/series', { params: expect.objectContaining({ item_code: '05.10.10.101.АГ' }) })
    expect(client.get).toHaveBeenCalledWith('/api/indices/admin/imports/import-uuid/series', { params: expect.objectContaining({ item_name: 'кухонной мебели' }) })
    expect(client.get).toHaveBeenCalledWith('/api/indices/admin/imports/import-uuid/observations', { params: expect.objectContaining({ series_public_id: 'series-uuid', period_from: '2024-01-01' }) })
  })

  it('builds multipart upload with backend field names and progress', async () => {
    const client = clientMock(); const api = createAdminPriceIndicesApi(client); const progress = vi.fn()
    await api.uploadSourceFile({ datasetPublicId: 'dataset-uuid', sourcePublicId: 'source-uuid', reportingYear: 2026, reportingMonth: 6, comment: 'audit', file: new File(['xlsx'], 'data.xlsx') }, progress)
    const [, form, config] = vi.mocked(client.post).mock.calls[0]!
    expect(form).toBeInstanceOf(FormData)
    expect((form as FormData).get('dataset_public_id')).toBe('dataset-uuid')
    expect((form as FormData).get('source_public_id')).toBe('source-uuid')
    expect((form as FormData).get('reporting_year')).toBe('2026')
    expect((form as FormData).get('file')).toBeInstanceOf(File)
    expect(config).toMatchObject({ headers: { 'Content-Type': 'multipart/form-data' } })
  })

  it.each([
    [{ status: 202, data: { data: { status: 'pending' }, meta: { queued: true, cached: false } } }, 'pending', false],
    [{ status: 200, data: { data: { status: 'ready' }, meta: { queued: false, cached: true } } }, 'ready', true],
  ])('preserves async and cached preview payload variants', async (response, status, cached) => {
    const client = clientMock(); vi.mocked(client.post).mockResolvedValueOnce(response); const api = createAdminPriceIndicesApi(client)
    const result = await api.startPreview('file-uuid')
    expect(result.data.status).toBe(status); expect(result.meta.cached).toBe(cached)
  })
})
