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

  private normalizeSinglePayload(payload: any): { facade: Facade; quotes: FacadeQuote[] } {
    return {
      facade: payload?.facade ?? payload?.product ?? payload,
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
    return this.requestWithFallback(
      () => api.get('/api/finished-products', { params: { ...params, product_type: this.productType } }),
      () => api.get('/api/facades', { params }),
    )
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
    return this.requestWithFallback(
      () => api.post('/api/finished-products', { ...data, product_type: this.productType }),
      () => api.post('/api/facades', data),
    )
  }

  async update(id: number, data: Partial<FacadeCreateData>): Promise<AxiosResponse<Facade>> {
    return this.requestWithFallback(
      () => api.put(`/api/finished-products/${id}`, { ...data, product_type: this.productType }),
      () => api.put(`/api/facades/${id}`, data),
    )
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
