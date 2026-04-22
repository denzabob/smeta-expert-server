import api from './axios'
import type { AxiosResponse } from 'axios'

// ==================== Types ====================

export interface FacadeClass {
  value: string
  label: string
}

export interface FacadeFilterOptions {
  facade_classes: FacadeClass[]
  finish_types: { value: string; label: string }[]
  base_materials: { value: string; label: string }[]
  finish_variants: { value: string; label: string }[]
  price_groups: string[]
  thickness_options: number[]
}

export interface FinishedProductPricingSummaryProfile {
  method: string | null
  include_only_active: boolean
  exclude_stale: boolean
  minimum_sources_count: number | null
}

export interface FinishedProductPricingSummary {
  available: boolean
  computed_price_per_m2: number | null
  method: string | null
  source_count: number
  min_price: number | null
  max_price: number | null
  computed_at: string | null
  profile: FinishedProductPricingSummaryProfile
  has_new_pricing_sources: boolean
  has_computed_price: boolean
  new_pricing_status: string
}

export interface FinishedProductPricingBreakdownSource {
  id: number
  supplier: {
    id: number | null
    name: string | null
  }
  source_kind: string | null
  source_price: number | null
  source_unit: string | null
  conversion_factor_to_m2: number | null
  price_per_m2_normalized: number | null
  captured_at: string | null
  effective_date: string | null
  status: string | null
  stale_reason: string | null
  article: string | null
  category: string | null
  description: string | null
  notes: string | null
  evidence_assets_count: number
  has_evidence: boolean
}

export interface FinishedProductPricingBreakdown {
  summary: {
    computed_price_per_m2: number | null
    method: string | null
    source_count: number
    min_price: number | null
    max_price: number | null
    computed_at: string | null
    status: string
  }
  profile: FinishedProductPricingSummaryProfile
  sources: FinishedProductPricingBreakdownSource[]
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
    source_kind: string | null
    source_price: number | null
    source_unit: string | null
    conversion_factor_to_m2: number | null
    price_per_m2_normalized: number | null
    captured_at: string | null
    effective_date: string | null
    status: string | null
    stale_reason: string | null
    article: string | null
    category: string | null
    description: string | null
    notes: string | null
    metadata: Record<string, any>
  }
  evidence_assets: FinishedProductPriceEvidenceAssetDetails[]
}

export interface Facade {
  id: number
  name: string
  article: string | null
  type: string
  unit: string
  is_active: boolean
  facade_class: string | null
  facade_base_type: string | null
  facade_thickness_mm: number | null
  facade_covering: string | null
  facade_cover_type: string | null
  facade_collection: string | null
  facade_price_group_label: string | null
  facade_decor_label: string | null
  facade_article_optional: string | null
  metadata: Record<string, any> | null
  product_type?: string
  quotes_count?: number
  last_quote_date?: string | null
  last_quote_price?: number | null
  finished_product_pricing_summary?: FinishedProductPricingSummary
  created_at: string
  updated_at: string
}

export interface FacadeQuote {
  id: number
  material_price_id: number
  material_id: number
  price_list_version_id: number
  supplier_id: number | null
  supplier_name: string
  price_per_m2: number
  source_price: number
  source_unit: string
  conversion_factor: number
  currency: string
  article: string | null
  category: string | null
  description: string | null
  source_row_index: number | null
  thickness: number | null
  price_list_name: string
  version_number: number | null
  captured_at: string | null
  effective_date: string | null
  source_type: string | null
  source_url: string | null
  original_filename: string | null
}

export interface SimilarQuote extends FacadeQuote {
  material_name: string
  facade_class: string | null
  mismatch_flags: string[]
}

export interface RevalidateResult {
  new_quote: FacadeQuote
  new_version: {
    id: number
    version_number: number
    effective_date: string
    captured_at: string
  }
  old_quote_id: number
  old_version_id: number
}

export interface DuplicateResult {
  quote: FacadeQuote
  created_material: Facade | null
}

export interface FacadeListParams {
  base_type?: string
  thickness_mm?: number
  covering?: string
  cover_type?: string
  facade_class?: string
  collection?: string
  is_active?: boolean
  search?: string
  sort_by?: string
  sort_dir?: 'asc' | 'desc'
  per_page?: number
  page?: number
}

export interface FacadeCreateData {
  name?: string
  auto_name?: boolean
  facade_class: string
  facade_base_type: string
  facade_thickness_mm: number
  facade_covering: string
  facade_cover_type?: string | null
  facade_collection?: string | null
  facade_price_group_label?: string | null
  facade_decor_label?: string | null
  facade_article_optional?: string | null
  is_active?: boolean
}

export interface QuoteCreateData {
  material_id: number
  supplier_id: number
  price_list_version_id: number
  source_price: number
  source_unit?: string
  conversion_factor?: number
  price_per_internal_unit?: number
  article?: string | null
  category?: string | null
  description?: string | null
  source_row_index?: number
  currency?: string
}

// ==================== API Client ====================

export class FacadesApiClient {
  private readonly productType = 'facade'

  private emptyFinishedProductPricingSummary(): FinishedProductPricingSummary {
    return {
      available: false,
      computed_price_per_m2: null,
      method: null,
      source_count: 0,
      min_price: null,
      max_price: null,
      computed_at: null,
      profile: {
        method: null,
        include_only_active: true,
        exclude_stale: true,
        minimum_sources_count: null,
      },
      has_new_pricing_sources: false,
      has_computed_price: false,
      new_pricing_status: 'none',
    }
  }

  private emptyFinishedProductPricingBreakdown(): FinishedProductPricingBreakdown {
    return {
      summary: {
        computed_price_per_m2: null,
        method: null,
        source_count: 0,
        min_price: null,
        max_price: null,
        computed_at: null,
        status: 'none',
      },
      profile: {
        method: null,
        include_only_active: true,
        exclude_stale: true,
        minimum_sources_count: null,
      },
      sources: [],
    }
  }

  private emptyFinishedProductPriceSourceDetails(): FinishedProductPriceSourceDetails {
    return {
      source: {
        id: 0,
        supplier: {
          id: null,
          name: null,
        },
        source_kind: null,
        source_price: null,
        source_unit: null,
        conversion_factor_to_m2: null,
        price_per_m2_normalized: null,
        captured_at: null,
        effective_date: null,
        status: null,
        stale_reason: null,
        article: null,
        category: null,
        description: null,
        notes: null,
        metadata: {},
      },
      evidence_assets: [],
    }
  }

  private normalizeFacadePayload(payload: any): Facade {
    const summary = payload?.finished_product_pricing_summary ?? {}
    const emptySummary = this.emptyFinishedProductPricingSummary()

    return {
      ...payload,
      finished_product_pricing_summary: {
        ...emptySummary,
        ...summary,
        profile: {
          ...emptySummary.profile,
          ...(summary?.profile ?? {}),
        },
      },
    } as Facade
  }

  private normalizeSinglePayload(payload: any): { facade: Facade; quotes: FacadeQuote[] } {
    return {
      facade: this.normalizeFacadePayload(payload?.facade ?? payload?.product ?? payload),
      quotes: payload?.quotes ?? [],
    }
  }

  private async requestWithFallback<T>(primary: () => Promise<AxiosResponse<T>>, fallback: () => Promise<AxiosResponse<T>>): Promise<AxiosResponse<T>> {
    try {
      return await primary()
    } catch (error: any) {
      const status = error?.response?.status
      if (status === 404 || status === 422) {
        return fallback()
      }
      throw error
    }
  }

  // ---- Facade CRUD ----

  async list(params: FacadeListParams = {}): Promise<AxiosResponse> {
    const response = await this.requestWithFallback(
      () => api.get('/api/finished-products', { params: { ...params, product_type: this.productType } }),
      () => api.get('/api/facades', { params }),
    )

    return {
      ...response,
      data: {
        ...response.data,
        data: Array.isArray(response.data?.data)
          ? response.data.data.map((item: any) => this.normalizeFacadePayload(item))
          : [],
      },
    } as AxiosResponse
  }

  async get(id: number): Promise<AxiosResponse<{ facade: Facade; quotes: FacadeQuote[] }>> {
    const response = await this.requestWithFallback(
      () => api.get(`/api/finished-products/${id}`, { params: { product_type: this.productType } }),
      () => api.get(`/api/facades/${id}`),
    )
    return {
      ...response,
      data: this.normalizeSinglePayload(response.data),
    } as AxiosResponse<{ facade: Facade; quotes: FacadeQuote[] }>
  }

  async create(data: FacadeCreateData): Promise<AxiosResponse<Facade>> {
    const response = await this.requestWithFallback(
      () => api.post('/api/finished-products', { ...data, product_type: this.productType }),
      () => api.post('/api/facades', data),
    )

    return {
      ...response,
      data: this.normalizeFacadePayload(response.data),
    } as AxiosResponse<Facade>
  }

  async update(id: number, data: Partial<FacadeCreateData>): Promise<AxiosResponse<Facade>> {
    const response = await this.requestWithFallback(
      () => api.put(`/api/finished-products/${id}`, { ...data, product_type: this.productType }),
      () => api.put(`/api/facades/${id}`, data),
    )

    return {
      ...response,
      data: this.normalizeFacadePayload(response.data),
    } as AxiosResponse<Facade>
  }

  async delete(id: number): Promise<AxiosResponse<{ action: string; reason?: string }>> {
    return this.requestWithFallback(
      () => api.delete(`/api/finished-products/${id}`, { params: { product_type: this.productType } }),
      () => api.delete(`/api/facades/${id}`),
    )
  }

  // ---- Quotes ----

  async getQuotes(facadeId: number): Promise<AxiosResponse<{ material_id: number; quotes: FacadeQuote[]; count: number }>> {
    const response = await this.requestWithFallback(
      () => api.get(`/api/finished-products/${facadeId}/quotes`, { params: { product_type: this.productType } }),
      () => api.get(`/api/facades/${facadeId}/quotes`),
    )
    return {
      ...response,
      data: {
        material_id: response.data?.material_id ?? response.data?.product_id ?? facadeId,
        quotes: response.data?.quotes ?? [],
        count: response.data?.count ?? (response.data?.quotes?.length ?? 0),
      },
    } as AxiosResponse<{ material_id: number; quotes: FacadeQuote[]; count: number }>
  }

  async getPricingBreakdown(id: number): Promise<AxiosResponse<FinishedProductPricingBreakdown>> {
    const response = await api.get(`/api/finished-products/${id}/pricing/breakdown`)
    const empty = this.emptyFinishedProductPricingBreakdown()
    const data = response.data ?? {}

    return {
      ...response,
      data: {
        summary: {
          ...empty.summary,
          ...(data.summary ?? {}),
        },
        profile: {
          ...empty.profile,
          ...(data.profile ?? {}),
        },
        sources: Array.isArray(data.sources) ? data.sources : [],
      },
    } as AxiosResponse<FinishedProductPricingBreakdown>
  }

  async getPricingSourceDetails(sourceId: number): Promise<AxiosResponse<FinishedProductPriceSourceDetails>> {
    const response = await api.get(`/api/finished-product-price-sources/${sourceId}/details`)
    const empty = this.emptyFinishedProductPriceSourceDetails()
    const data = response.data ?? {}

    return {
      ...response,
      data: {
        source: {
          ...empty.source,
          ...(data.source ?? {}),
          supplier: {
            ...empty.source.supplier,
            ...(data.source?.supplier ?? {}),
          },
          metadata: data.source?.metadata ?? {},
        },
        evidence_assets: Array.isArray(data.evidence_assets)
          ? data.evidence_assets.map((item: any) => ({
              id: item?.id ?? 0,
              asset_type: item?.asset_type ?? null,
              file_path: item?.file_path ?? null,
              original_name: item?.original_name ?? null,
              mime_type: item?.mime_type ?? null,
              file_size: item?.file_size ?? null,
              source_url: item?.source_url ?? null,
              content_hash: item?.content_hash ?? null,
              captured_at: item?.captured_at ?? null,
              metadata: item?.metadata ?? {},
              can_preview: !!item?.can_preview,
              can_download: !!item?.can_download,
              preview_url: item?.preview_url ?? null,
              download_url: item?.download_url ?? null,
              open_url: item?.open_url ?? null,
              access_kind: item?.access_kind ?? 'none',
            }))
          : [],
      },
    } as AxiosResponse<FinishedProductPriceSourceDetails>
  }

  async createQuote(data: QuoteCreateData): Promise<AxiosResponse> {
    return api.post('/api/facade-quotes', data)
  }

  async updateQuote(quoteId: number, data: {
    source_price?: number
    source_unit?: string
    conversion_factor?: number
    article?: string | null
    category?: string | null
    description?: string | null
  }): Promise<AxiosResponse> {
    return api.put(`/api/facade-quotes/${quoteId}`, data)
  }

  async deleteQuote(quoteId: number): Promise<AxiosResponse> {
    return api.delete(`/api/facade-quotes/${quoteId}`)
  }

  async duplicateQuote(quoteId: number, data: { target_material_id?: number; new_facade_class?: string }): Promise<AxiosResponse<DuplicateResult>> {
    return api.post(`/api/facade-quotes/${quoteId}/duplicate`, data)
  }

  async revalidateQuote(quoteId: number, newPrice?: number): Promise<AxiosResponse<RevalidateResult>> {
    return api.post(`/api/facade-quotes/${quoteId}/revalidate`, { new_price: newPrice })
  }

  async getSimilarQuotes(materialId: number, mode: 'strict' | 'extended' = 'strict'): Promise<AxiosResponse<{ quotes: SimilarQuote[]; count: number; mode: string }>> {
    return api.get('/api/facade-quotes/similar', { params: { material_id: materialId, mode } })
  }

  // ---- Filter Options ----

  async getFilterOptions(): Promise<AxiosResponse<FacadeFilterOptions>> {
    const response = await this.requestWithFallback(
      () => api.get('/api/finished-products/filter-options', { params: { product_type: this.productType } }),
      () => api.get('/api/facades/filter-options'),
    )
    return {
      ...response,
      data: response.data?.facade ?? response.data,
    } as AxiosResponse<FacadeFilterOptions>
  }

  // ---- Legacy (backward compat) ----

  async getSpecConstants(): Promise<AxiosResponse> {
    return api.get('/api/facade-materials/spec-constants')
  }
}

export const facadesApi = new FacadesApiClient()
export default facadesApi
