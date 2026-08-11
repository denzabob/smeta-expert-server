import type { AxiosInstance, AxiosProgressEvent } from 'axios'
import api from '@/api/axios'
import type {
  ImportListParams,
  ImportObservationListParams,
  ImportSeriesListParams,
  PaginatedResponse,
  PreviewStartMeta,
  ResourceResponse,
  SourceFileListParams,
  StatisticalDataset,
  StatisticalImport,
  StatisticalImportIssue,
  StatisticalImportPreview,
  StatisticalImportPreviewResult,
  StatisticalObservation,
  StatisticalSeriesAdmin,
  StatisticalSource,
  StatisticalSourceFile,
  UploadSourceFilePayload,
} from '../types'

export function createAdminPriceIndicesApi(client: AxiosInstance = api) {
  const base = '/api/indices/admin'

  return {
    async listDatasets() {
      return (await client.get<PaginatedResponse<StatisticalDataset>>(`${base}/datasets`, {
        params: { per_page: 100, sort: 'created_at', direction: 'asc' },
      })).data
    },
    async listSources(datasetPublicId: string) {
      return (await client.get<PaginatedResponse<StatisticalSource>>(`${base}/sources`, {
        params: { dataset: datasetPublicId, per_page: 100, sort: 'created_at', direction: 'asc' },
      })).data
    },
    async listSourceFiles(params: SourceFileListParams) {
      return (await client.get<PaginatedResponse<StatisticalSourceFile>>(`${base}/source-files`, { params })).data
    },
    async uploadSourceFile(payload: UploadSourceFilePayload, onProgress?: (percent: number) => void) {
      const form = new FormData()
      form.append('dataset_public_id', payload.datasetPublicId)
      if (payload.sourcePublicId) form.append('source_public_id', payload.sourcePublicId)
      if (payload.reportingYear != null) form.append('reporting_year', String(payload.reportingYear))
      if (payload.reportingMonth != null) form.append('reporting_month', String(payload.reportingMonth))
      if (payload.sourceUrl) form.append('source_url', payload.sourceUrl)
      if (payload.comment) form.append('comment', payload.comment)
      form.append('file', payload.file)
      return (await client.post<ResourceResponse<StatisticalSourceFile>>(`${base}/source-files/upload`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress: (event: AxiosProgressEvent) => {
          if (event.total && onProgress) onProgress(Math.round((event.loaded / event.total) * 100))
        },
      })).data
    },
    async approveSourceFile(publicId: string) {
      return (await client.post<ResourceResponse<StatisticalSourceFile>>(`${base}/source-files/${publicId}/approve`)).data
    },
    async rejectSourceFile(publicId: string, reason: string) {
      return (await client.post<ResourceResponse<StatisticalSourceFile>>(`${base}/source-files/${publicId}/reject`, { reason })).data
    },
    async activateSourceFile(publicId: string) {
      return (await client.post<ResourceResponse<StatisticalSourceFile>>(`${base}/source-files/${publicId}/activate`)).data
    },
    async downloadSourceFile(publicId: string) {
      return client.get<Blob>(`${base}/source-files/${publicId}/download`, { responseType: 'blob' })
    },
    async startPreview(sourceFilePublicId: string) {
      return (await client.post<ResourceResponse<StatisticalImportPreview> & { meta: PreviewStartMeta }>(
        `${base}/source-files/${sourceFilePublicId}/preview`,
      )).data
    },
    async getPreview(publicId: string) {
      return (await client.get<ResourceResponse<StatisticalImportPreview>>(`${base}/previews/${publicId}`)).data
    },
    async getPreviewResult(publicId: string) {
      return (await client.get<ResourceResponse<StatisticalImportPreviewResult>>(`${base}/previews/${publicId}/result`)).data
    },
    async retryPreview(publicId: string) {
      return (await client.post<ResourceResponse<StatisticalImportPreview> & { meta: PreviewStartMeta }>(
        `${base}/previews/${publicId}/retry`,
      )).data
    },
    async startImport(sourceFilePublicId: string) {
      return (await client.post<ResourceResponse<StatisticalImport>>(`${base}/source-files/${sourceFilePublicId}/imports`)).data
    },
    async listImports(params: ImportListParams) {
      return (await client.get<PaginatedResponse<StatisticalImport>>(`${base}/imports`, { params })).data
    },
    async getImport(publicId: string) {
      return (await client.get<ResourceResponse<StatisticalImport>>(`${base}/imports/${publicId}`)).data
    },
    async getImportIssues(publicId: string, page = 1, perPage = 50) {
      return (await client.get<PaginatedResponse<StatisticalImportIssue>>(`${base}/imports/${publicId}/issues`, {
        params: { page, per_page: perPage, sort: 'created_at', direction: 'asc' },
      })).data
    },
    async retryImport(publicId: string) {
      return (await client.post<ResourceResponse<StatisticalImport>>(`${base}/imports/${publicId}/retry`)).data
    },
    async publishImport(publicId: string) {
      return (await client.post<ResourceResponse<StatisticalImport>>(`${base}/imports/${publicId}/publish`)).data
    },
    async getActiveImport(datasetPublicId: string) {
      return (await client.get<ResourceResponse<StatisticalImport | null>>(
        `${base}/datasets/${datasetPublicId}/active-import`,
      )).data
    },
    async listImportSeries(importPublicId: string, params: ImportSeriesListParams) {
      return (await client.get<PaginatedResponse<StatisticalSeriesAdmin>>(
        `${base}/imports/${importPublicId}/series`,
        { params },
      )).data
    },
    async getImportObservations(importPublicId: string, params: ImportObservationListParams) {
      return (await client.get<PaginatedResponse<StatisticalObservation>>(
        `${base}/imports/${importPublicId}/observations`,
        { params },
      )).data
    },
  }
}

export const adminPriceIndicesApi = createAdminPriceIndicesApi()
