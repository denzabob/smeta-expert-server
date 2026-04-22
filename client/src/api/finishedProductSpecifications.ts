import type { AxiosResponse } from 'axios'
import api from './axios'

export interface FinishedProductSpecificationComputedPriceSummary {
  computed_price_per_m2: number | null
  method: string | null
  source_count: number
  min_price: number | null
  max_price: number | null
  computed_at: string | null
}

export interface FinishedProductSpecification {
  id: number
  product_type: 'facade'
  name: string
  article: string | null
  is_active: boolean
  facade_class: string | null
  base_type: string | null
  thickness_mm: number | null
  covering: string | null
  cover_type: string | null
  collection: string | null
  decor_label: string | null
  price_group_label: string | null
  notes: string | null
  metadata: Record<string, any>
  source_count: number
  aggregation_method: string | null
  computed_price_summary: FinishedProductSpecificationComputedPriceSummary
  created_at: string | null
  updated_at: string | null
}

export interface FinishedProductSpecificationListParams {
  search?: string
  is_active?: boolean
  product_type?: 'facade'
  per_page?: number
  page?: number
}

export interface FinishedProductSpecificationFormData {
  product_type?: 'facade'
  name: string
  article?: string | null
  is_active?: boolean
  facade_class?: string | null
  base_type?: string | null
  thickness_mm?: number | null
  covering?: string | null
  cover_type?: string | null
  collection?: string | null
  decor_label?: string | null
  price_group_label?: string | null
  notes?: string | null
  metadata?: Record<string, any> | null
}

export interface FinishedProductSpecificationListResponse {
  data: FinishedProductSpecification[]
  meta?: {
    current_page?: number
    last_page?: number
    per_page?: number
    total?: number
  }
}

type RawSpecificationResponse = {
  data?: any
  meta?: any
}

function emptyComputedSummary(): FinishedProductSpecificationComputedPriceSummary {
  return {
    computed_price_per_m2: null,
    method: null,
    source_count: 0,
    min_price: null,
    max_price: null,
    computed_at: null,
  }
}

function normalizeSpecification(payload: any): FinishedProductSpecification {
  const emptySummary = emptyComputedSummary()
  const summary = payload?.computed_price_summary ?? {}

  return {
    id: Number(payload?.id ?? 0),
    product_type: 'facade',
    name: String(payload?.name ?? ''),
    article: payload?.article ?? null,
    is_active: Boolean(payload?.is_active ?? true),
    facade_class: payload?.facade_class ?? null,
    base_type: payload?.base_type ?? null,
    thickness_mm: payload?.thickness_mm ?? null,
    covering: payload?.covering ?? null,
    cover_type: payload?.cover_type ?? null,
    collection: payload?.collection ?? null,
    decor_label: payload?.decor_label ?? null,
    price_group_label: payload?.price_group_label ?? null,
    notes: payload?.notes ?? null,
    metadata: payload?.metadata ?? {},
    source_count: Number(payload?.source_count ?? 0),
    aggregation_method: payload?.aggregation_method ?? null,
    computed_price_summary: {
      ...emptySummary,
      ...(summary ?? {}),
    },
    created_at: payload?.created_at ?? null,
    updated_at: payload?.updated_at ?? null,
  }
}

export class FinishedProductSpecificationsApiClient {
  async list(
    params: FinishedProductSpecificationListParams = {},
  ): Promise<AxiosResponse<FinishedProductSpecificationListResponse>> {
    const response = await api.get<RawSpecificationResponse>('/api/finished-product-specifications', {
      params: {
        ...params,
        product_type: 'facade',
      },
    })

    return {
      ...response,
      data: {
        data: Array.isArray(response.data?.data)
          ? response.data.data.map((item: any) => normalizeSpecification(item))
          : [],
        meta: response.data?.meta,
      },
    } as AxiosResponse<FinishedProductSpecificationListResponse>
  }

  async get(id: number): Promise<AxiosResponse<{ data: FinishedProductSpecification }>> {
    const response = await api.get<RawSpecificationResponse>(`/api/finished-product-specifications/${id}`)

    return {
      ...response,
      data: {
        data: normalizeSpecification(response.data?.data),
      },
    } as AxiosResponse<{ data: FinishedProductSpecification }>
  }

  async create(
    payload: FinishedProductSpecificationFormData,
  ): Promise<AxiosResponse<{ data: FinishedProductSpecification }>> {
    const response = await api.post<RawSpecificationResponse>('/api/finished-product-specifications', {
      ...payload,
      product_type: 'facade',
    })

    return {
      ...response,
      data: {
        data: normalizeSpecification(response.data?.data),
      },
    } as AxiosResponse<{ data: FinishedProductSpecification }>
  }

  async update(
    id: number,
    payload: Partial<FinishedProductSpecificationFormData>,
  ): Promise<AxiosResponse<{ data: FinishedProductSpecification }>> {
    const response = await api.patch<RawSpecificationResponse>(`/api/finished-product-specifications/${id}`, {
      ...payload,
      product_type: 'facade',
    })

    return {
      ...response,
      data: {
        data: normalizeSpecification(response.data?.data),
      },
    } as AxiosResponse<{ data: FinishedProductSpecification }>
  }

  async delete(id: number): Promise<AxiosResponse<void>> {
    return api.delete(`/api/finished-product-specifications/${id}`)
  }
}

export const finishedProductSpecificationsApi = new FinishedProductSpecificationsApiClient()

export default finishedProductSpecificationsApi
