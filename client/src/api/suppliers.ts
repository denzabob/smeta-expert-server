/**
 * Suppliers API Client
 * Handles CRUD operations for suppliers
 */

import api from './axios'

export interface Supplier {
  id: number
  name: string
  website?: string | null
  contact_person?: string | null
  contact_email?: string | null
  contact_phone?: string | null
  notes?: string | null
  is_active: boolean
  price_lists_count?: number
  active_versions_count?: number
  last_version_at?: string | null
  created_at: string
  updated_at: string
  deleted_at?: string | null
}

export interface SupplierCreatePayload {
  name: string
  website?: string | null
  contact_person?: string | null
  contact_email?: string | null
  contact_phone?: string | null
  notes?: string | null
  is_active?: boolean
}

export interface SupplierUpdatePayload extends Partial<SupplierCreatePayload> {}

export interface SuppliersListResponse {
  data: Supplier[]
  current_page?: number
  last_page?: number
  total?: number
}

class SuppliersApiClient {
  /**
   * Get all suppliers with aggregates
   */
  async getAll(params?: { 
    page?: number
    per_page?: number
    search?: string
    is_active?: boolean
    with_trashed?: boolean
    price_list_type?: 'operations' | 'materials'
  }): Promise<SuppliersListResponse> {
    const { data } = await api.get('/api/suppliers', { params })
    // API returns array directly, wrap it in the expected format
    return { data: Array.isArray(data) ? data : [] }
  }

  /**
   * Get single supplier by ID
   */
  async getById(id: number): Promise<Supplier> {
    const { data } = await api.get(`/api/suppliers/${id}`)
    return data.data || data
  }

  /**
   * Create a new supplier
   */
  async create(payload: SupplierCreatePayload): Promise<Supplier> {
    const { data } = await api.post('/api/suppliers', payload)
    return data.data || data
  }

  /**
   * Update an existing supplier
   */
  async update(id: number, payload: SupplierUpdatePayload): Promise<Supplier> {
    const { data } = await api.put(`/api/suppliers/${id}`, payload)
    return data.data || data
  }

  /**
   * Delete (soft delete) a supplier
   */
  async delete(id: number): Promise<void> {
    await api.delete(`/api/suppliers/${id}`)
  }

  /**
   * Archive a supplier
   */
  async archive(id: number): Promise<Supplier> {
    const { data } = await api.post(`/api/suppliers/${id}/archive`)
    return data.data || data
  }

  /**
   * Restore an archived supplier
   */
  async restore(id: number): Promise<Supplier> {
    const { data } = await api.post(`/api/suppliers/${id}/restore`)
    return data.data || data
  }
}

export const suppliersApi = new SuppliersApiClient()
export default suppliersApi
