import type { AxiosResponse } from 'axios'
import api from './axios'

export type FinishedProductAggregationMethod = 'mean' | 'median'
export type FinishedProductPriceSourceKind =
  | 'price_list_row'
  | 'price_document'
  | 'url_capture'
  | 'manual_entry'
export type FinishedProductPriceSourceStatus =
  | 'active'
  | 'inactive'
  | 'stale'
  | 'invalid'
  | 'superseded'

export interface FinishedProductAggregationProfile {
  method: FinishedProductAggregationMethod | null
  include_only_active: boolean
  exclude_stale: boolean
  minimum_sources_count: number | null
}

export interface FinishedProductComputedPriceSummary {
  computed_price_per_m2: number | null
  method: FinishedProductAggregationMethod | null
  source_count: number
  min_price: number | null
  max_price: number | null
  computed_at: string | null
}

export interface FinishedProductPricingSummaryResponse {
  finished_product_specification_id: number
  profile: FinishedProductAggregationProfile
  computed_price: FinishedProductComputedPriceSummary
}

export interface FinishedProductPricingBreakdownSource {
  id: number
  supplier: {
    id: number | null
    name: string | null
  }
  source_kind: FinishedProductPriceSourceKind | null
  source_price: number | null
  source_unit: string | null
  conversion_factor_to_m2: number | null
  price_per_m2_normalized: number | null
  captured_at: string | null
  effective_date: string | null
  status: FinishedProductPriceSourceStatus | null
  stale_reason: string | null
  article: string | null
  category: string | null
  description: string | null
  notes: string | null
  evidence_assets_count: number
  has_evidence: boolean
}

export interface FinishedProductPricingBreakdown {
  summary: FinishedProductComputedPriceSummary & {
    status: 'computed' | 'sources_only' | 'profile_only' | 'none'
  }
  profile: FinishedProductAggregationProfile
  sources: FinishedProductPricingBreakdownSource[]
}

export interface FinishedProductPriceSource {
  id: number
  finished_product_specification_id: number
  supplier_id: number | null
  price_list_version_id: number | null
  source_kind: FinishedProductPriceSourceKind | null
  source_price: number | null
  source_unit: string | null
  conversion_factor_to_m2: number | null
  price_per_m2_normalized: number | null
  captured_at: string | null
  effective_date: string | null
  article: string | null
  category: string | null
  description: string | null
  status: FinishedProductPriceSourceStatus | null
  stale_reason: string | null
  notes: string | null
  metadata: Record<string, any>
  supplier: {
    id: number | null
    name: string | null
  }
  price_list_version?: {
    id: number
    price_list_id?: number | null
    status?: string | null
    effective_date?: string | null
    captured_at?: string | null
    original_filename?: string | null
    manual_label?: string | null
    source_type?: string | null
  } | null
  evidence_assets_count?: number
  has_evidence?: boolean
}

export interface FinishedProductPriceSourcePayload {
  supplier_id?: number | null
  source_kind: FinishedProductPriceSourceKind
  price_list_version_id?: number | null
  source_price: number | null
  source_unit?: string | null
  conversion_factor_to_m2?: number | null
  price_per_m2_normalized?: number | null
  captured_at?: string | null
  effective_date?: string | null
  article?: string | null
  category?: string | null
  description?: string | null
  status?: FinishedProductPriceSourceStatus | null
  stale_reason?: string | null
  notes?: string | null
  metadata?: Record<string, any> | null
}

export interface FinishedProductPriceEvidenceAssetDetails {
  id: number
  asset_type: string | null
  file_path: string | null
  original_name: string | null
  mime_type: string | null
  file_size: number | null
  source_url: string | null
  content_hash: string | null
  captured_at: string | null
  metadata: Record<string, any>
  can_preview: boolean
  can_download: boolean
  preview_url: string | null
  download_url: string | null
  open_url: string | null
  access_kind: string
}

export interface FinishedProductPriceSourceDetails {
  source: {
    id: number
    supplier: {
      id: number | null
      name: string | null
    }
    source_kind: FinishedProductPriceSourceKind | null
    source_price: number | null
    source_unit: string | null
    conversion_factor_to_m2: number | null
    price_per_m2_normalized: number | null
    captured_at: string | null
    effective_date: string | null
    status: FinishedProductPriceSourceStatus | null
    stale_reason: string | null
    article: string | null
    category: string | null
    description: string | null
    notes: string | null
    metadata: Record<string, any>
  }
  evidence_assets: FinishedProductPriceEvidenceAssetDetails[]
}

export interface FinishedProductPriceEvidenceAssetCreatePayload {
  asset_type: 'screenshot' | 'file' | 'image' | 'link'
  file?: File | null
  source_url?: string | null
  captured_at?: string | null
  metadata?: Record<string, any> | null
}

function emptyProfile(): FinishedProductAggregationProfile {
  return {
    method: 'median',
    include_only_active: true,
    exclude_stale: true,
    minimum_sources_count: null,
  }
}

function emptyComputed(): FinishedProductComputedPriceSummary {
  return {
    computed_price_per_m2: null,
    method: null,
    source_count: 0,
    min_price: null,
    max_price: null,
    computed_at: null,
  }
}

function normalizeSource(payload: any): FinishedProductPriceSource {
  return {
    id: Number(payload?.id ?? 0),
    finished_product_specification_id: Number(payload?.finished_product_specification_id ?? 0),
    supplier_id: payload?.supplier_id ?? null,
    price_list_version_id: payload?.price_list_version_id ?? null,
    source_kind: payload?.source_kind ?? null,
    source_price: payload?.source_price !== null && payload?.source_price !== undefined ? Number(payload.source_price) : null,
    source_unit: payload?.source_unit ?? null,
    conversion_factor_to_m2:
      payload?.conversion_factor_to_m2 !== null && payload?.conversion_factor_to_m2 !== undefined
        ? Number(payload.conversion_factor_to_m2)
        : null,
    price_per_m2_normalized:
      payload?.price_per_m2_normalized !== null && payload?.price_per_m2_normalized !== undefined
        ? Number(payload.price_per_m2_normalized)
        : null,
    captured_at: payload?.captured_at ?? null,
    effective_date: payload?.effective_date ?? null,
    article: payload?.article ?? null,
    category: payload?.category ?? null,
    description: payload?.description ?? null,
    status: payload?.status ?? null,
    stale_reason: payload?.stale_reason ?? null,
    notes: payload?.notes ?? null,
    metadata: payload?.metadata ?? {},
    supplier: {
      id: payload?.supplier?.id ?? payload?.supplier_id ?? null,
      name: payload?.supplier?.name ?? null,
    },
    price_list_version: payload?.price_list_version
      ? {
          id: Number(payload.price_list_version.id),
          price_list_id: payload.price_list_version.price_list_id ?? null,
          status: payload.price_list_version.status ?? null,
          effective_date: payload.price_list_version.effective_date ?? null,
          captured_at: payload.price_list_version.captured_at ?? null,
          original_filename: payload.price_list_version.original_filename ?? null,
          manual_label: payload.price_list_version.manual_label ?? null,
          source_type: payload.price_list_version.source_type ?? null,
        }
      : null,
    evidence_assets_count:
      payload?.evidence_assets_count !== null && payload?.evidence_assets_count !== undefined
        ? Number(payload.evidence_assets_count)
        : undefined,
    has_evidence: payload?.has_evidence !== undefined ? Boolean(payload.has_evidence) : undefined,
  }
}

function normalizeDetails(payload: any): FinishedProductPriceSourceDetails {
  return {
    source: {
      id: Number(payload?.source?.id ?? 0),
      supplier: {
        id: payload?.source?.supplier?.id ?? null,
        name: payload?.source?.supplier?.name ?? null,
      },
      source_kind: payload?.source?.source_kind ?? null,
      source_price: payload?.source?.source_price !== null && payload?.source?.source_price !== undefined
        ? Number(payload.source.source_price)
        : null,
      source_unit: payload?.source?.source_unit ?? null,
      conversion_factor_to_m2:
        payload?.source?.conversion_factor_to_m2 !== null && payload?.source?.conversion_factor_to_m2 !== undefined
          ? Number(payload.source.conversion_factor_to_m2)
          : null,
      price_per_m2_normalized:
        payload?.source?.price_per_m2_normalized !== null && payload?.source?.price_per_m2_normalized !== undefined
          ? Number(payload.source.price_per_m2_normalized)
          : null,
      captured_at: payload?.source?.captured_at ?? null,
      effective_date: payload?.source?.effective_date ?? null,
      status: payload?.source?.status ?? null,
      stale_reason: payload?.source?.stale_reason ?? null,
      article: payload?.source?.article ?? null,
      category: payload?.source?.category ?? null,
      description: payload?.source?.description ?? null,
      notes: payload?.source?.notes ?? null,
      metadata: payload?.source?.metadata ?? {},
    },
    evidence_assets: Array.isArray(payload?.evidence_assets)
      ? payload.evidence_assets.map((item: any) => ({
          id: Number(item?.id ?? 0),
          asset_type: item?.asset_type ?? null,
          file_path: item?.file_path ?? null,
          original_name: item?.original_name ?? null,
          mime_type: item?.mime_type ?? null,
          file_size: item?.file_size !== null && item?.file_size !== undefined ? Number(item.file_size) : null,
          source_url: item?.source_url ?? null,
          content_hash: item?.content_hash ?? null,
          captured_at: item?.captured_at ?? null,
          metadata: item?.metadata ?? {},
          can_preview: Boolean(item?.can_preview),
          can_download: Boolean(item?.can_download),
          preview_url: item?.preview_url ?? null,
          download_url: item?.download_url ?? null,
          open_url: item?.open_url ?? null,
          access_kind: item?.access_kind ?? 'none',
        }))
      : [],
  }
}

export class FinishedProductPricingApiClient {
  async getSummary(specificationId: number): Promise<AxiosResponse<FinishedProductPricingSummaryResponse>> {
    const response = await api.get(`/api/finished-product-specifications/${specificationId}/pricing/summary`)

    return {
      ...response,
      data: {
        finished_product_specification_id: Number(response.data?.finished_product_specification_id ?? specificationId),
        profile: {
          ...emptyProfile(),
          ...(response.data?.profile ?? {}),
        },
        computed_price: {
          ...emptyComputed(),
          ...(response.data?.computed_price ?? {}),
        },
      },
    } as AxiosResponse<FinishedProductPricingSummaryResponse>
  }

  async getBreakdown(specificationId: number): Promise<AxiosResponse<FinishedProductPricingBreakdown>> {
    const response = await api.get(`/api/finished-product-specifications/${specificationId}/pricing/breakdown`)

    return {
      ...response,
      data: {
        summary: {
          ...emptyComputed(),
          status: 'none',
          ...(response.data?.summary ?? {}),
        },
        profile: {
          ...emptyProfile(),
          ...(response.data?.profile ?? {}),
        },
        sources: Array.isArray(response.data?.sources)
          ? response.data.sources.map((item: any) => ({
              ...normalizeSource(item),
              evidence_assets_count: Number(item?.evidence_assets_count ?? 0),
              has_evidence: Boolean(item?.has_evidence),
            }))
          : [],
      },
    } as AxiosResponse<FinishedProductPricingBreakdown>
  }

  async listSources(specificationId: number): Promise<AxiosResponse<{ finished_product_specification_id: number; sources: FinishedProductPriceSource[] }>> {
    const response = await api.get(`/api/finished-product-specifications/${specificationId}/pricing/sources`)

    return {
      ...response,
      data: {
        finished_product_specification_id: Number(response.data?.finished_product_specification_id ?? specificationId),
        sources: Array.isArray(response.data?.sources)
          ? response.data.sources.map((item: any) => normalizeSource(item))
          : [],
      },
    } as AxiosResponse<{ finished_product_specification_id: number; sources: FinishedProductPriceSource[] }>
  }

  async createSource(
    specificationId: number,
    payload: FinishedProductPriceSourcePayload,
  ): Promise<AxiosResponse<FinishedProductPriceSource>> {
    const response = await api.post(`/api/finished-product-specifications/${specificationId}/pricing/sources`, payload)

    return {
      ...response,
      data: normalizeSource(response.data),
    } as AxiosResponse<FinishedProductPriceSource>
  }

  async updateSource(
    sourceId: number,
    payload: Partial<FinishedProductPriceSourcePayload>,
  ): Promise<AxiosResponse<FinishedProductPriceSource>> {
    const response = await api.put(`/api/finished-product-price-sources/${sourceId}`, payload)

    return {
      ...response,
      data: normalizeSource(response.data),
    } as AxiosResponse<FinishedProductPriceSource>
  }

  async activateSource(sourceId: number): Promise<AxiosResponse<FinishedProductPriceSource>> {
    const response = await api.post(`/api/finished-product-price-sources/${sourceId}/activate`)

    return {
      ...response,
      data: normalizeSource(response.data),
    } as AxiosResponse<FinishedProductPriceSource>
  }

  async deactivateSource(sourceId: number): Promise<AxiosResponse<FinishedProductPriceSource>> {
    const response = await api.post(`/api/finished-product-price-sources/${sourceId}/deactivate`)

    return {
      ...response,
      data: normalizeSource(response.data),
    } as AxiosResponse<FinishedProductPriceSource>
  }

  async updateAggregationProfile(
    specificationId: number,
    payload: {
      method: FinishedProductAggregationMethod
      include_only_active?: boolean
      exclude_stale?: boolean
      minimum_sources_count?: number | null
      metadata?: Record<string, any> | null
    },
  ): Promise<AxiosResponse<FinishedProductPricingSummaryResponse>> {
    const response = await api.put(
      `/api/finished-product-specifications/${specificationId}/pricing/aggregation-profile`,
      payload,
    )

    return {
      ...response,
      data: {
        finished_product_specification_id: specificationId,
        profile: {
          ...emptyProfile(),
          ...(response.data?.profile ?? {}),
        },
        computed_price: {
          ...emptyComputed(),
          ...(response.data?.computed_price ?? {}),
        },
      },
    } as AxiosResponse<FinishedProductPricingSummaryResponse>
  }

  async getSourceDetails(sourceId: number): Promise<AxiosResponse<FinishedProductPriceSourceDetails>> {
    const response = await api.get(`/api/finished-product-price-sources/${sourceId}/details`)

    return {
      ...response,
      data: normalizeDetails(response.data),
    } as AxiosResponse<FinishedProductPriceSourceDetails>
  }

  async listEvidenceAssets(
    sourceId: number,
  ): Promise<AxiosResponse<{ finished_product_price_source_id: number; assets: FinishedProductPriceEvidenceAssetDetails[] }>> {
    const response = await api.get(`/api/finished-product-price-sources/${sourceId}/evidence-assets`)

    return {
      ...response,
      data: {
        finished_product_price_source_id: Number(response.data?.finished_product_price_source_id ?? sourceId),
        assets: Array.isArray(response.data?.assets)
          ? normalizeDetails({ evidence_assets: response.data.assets }).evidence_assets
          : [],
      },
    } as AxiosResponse<{ finished_product_price_source_id: number; assets: FinishedProductPriceEvidenceAssetDetails[] }>
  }

  async createEvidenceAsset(
    sourceId: number,
    payload: FinishedProductPriceEvidenceAssetCreatePayload,
  ): Promise<AxiosResponse<FinishedProductPriceEvidenceAssetDetails>> {
    let response

    if (payload.file) {
      const formData = new FormData()
      formData.append('asset_type', payload.asset_type)
      formData.append('file', payload.file)
      if (payload.captured_at) formData.append('captured_at', payload.captured_at)

      response = await api.post(`/api/finished-product-price-sources/${sourceId}/evidence-assets`, formData, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      })
    } else {
      response = await api.post(`/api/finished-product-price-sources/${sourceId}/evidence-assets`, {
        asset_type: payload.asset_type,
        source_url: payload.source_url,
        captured_at: payload.captured_at,
        metadata: payload.metadata,
      })
    }

    return {
      ...response,
      data: normalizeDetails({ evidence_assets: [response.data] }).evidence_assets[0],
    } as AxiosResponse<FinishedProductPriceEvidenceAssetDetails>
  }

  async deleteEvidenceAsset(assetId: number): Promise<AxiosResponse<void>> {
    return api.delete(`/api/finished-product-price-evidence-assets/${assetId}`)
  }
}

export const finishedProductPricingApi = new FinishedProductPricingApiClient()

export default finishedProductPricingApi
