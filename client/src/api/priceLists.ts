/**
 * Price Lists API Client
 * Handles operations for price lists and their versions
 */

import api from './axios'

// ============== PRICE LIST TYPES ==============

export interface PriceList {
  id: number
  supplier_id: number
  name: string
  title?: string // deprecated, use name
  type: 'operations' | 'materials'
  description?: string | null
  default_currency?: string
  is_active: boolean
  versions_count?: number
  active_version?: PriceListVersion | null
  latest_version?: PriceListVersion | null
  created_at: string
  updated_at: string
}

export interface PriceListCreatePayload {
  supplier_id: number
  /** Display label — mapped to `name` when sent to backend */
  title: string
  name?: string
  type?: 'operations' | 'materials'
  metadata?: Record<string, any> | null
  description?: string | null
  default_currency?: string
  is_active?: boolean
}

export interface PriceListUpdatePayload extends Partial<PriceListCreatePayload> {}

// ============== VERSION TYPES ==============

export interface PriceListVersion {
  id: number
  price_list_id: number
  status: 'inactive' | 'active' | 'archived'
  effective_date?: string | null
  captured_at?: string | null
  source_type?: 'file' | 'manual' | 'url' | null
  source_url?: string | null
  source_file_path?: string | null
  file_path?: string | null
  original_filename?: string | null
  manual_label?: string | null
  size_bytes?: number | null
  items_count?: number
  import_sessions?: any[]
  created_at: string
  updated_at: string
}

export interface PriceListVersionItem {
  id: number
  price_list_version_id: number
  price_type: 'operation' | 'material'
  operation_id?: number | null
  material_id?: number | null
  article?: string | null
  title: string
  operation_name?: string | null
  unit?: string | null
  category?: string | null
  price_supplier: number
  price_buy?: number | null
  currency?: string
  match_confidence?: string | null
  is_linked?: boolean
  notes?: string | null
  created_at: string
  updated_at: string
}

export interface ActualVersionResponse {
  version: PriceListVersion | null
  is_active: boolean
  warning?: string
}

/** A price document entry (DMS mode — no parsing) */
export interface PriceDocument {
  version_id: number
  price_list_id: number
  price_list_name: string
  version_number: number
  captured_at: string | null
  effective_date: string | null
  source_type: 'file' | 'url' | 'manual' | null
  original_filename: string | null
  source_url: string | null
  status: 'inactive' | 'active' | 'archived'
  size_bytes: number | null
}

// ============== API CLIENT ==============

class PriceListsApiClient {
  // ========== PRICE LIST METHODS ==========

  /**
   * Get all price lists for a supplier
   */
  async getAll(supplierId: number, params?: {
    page?: number
    per_page?: number
    is_active?: boolean
    type?: 'operations' | 'materials'
    domain?: 'operations' | 'materials' | 'finished_products'
  }): Promise<{ data: PriceList[] }> {
    const { data } = await api.get(`/api/suppliers/${supplierId}/price-lists`, { params })
    // API returns array directly, wrap it in the expected format
    return { data: Array.isArray(data) ? data : [] }
  }

  /**
   * Get single price list
   */
  async getById(priceListId: number): Promise<PriceList> {
    const { data } = await api.get(`/api/price-lists/${priceListId}`)
    return data.data || data
  }

  /**
   * Get actual (active or latest inactive) version
   */
  async getActualVersion(priceListId: number): Promise<ActualVersionResponse> {
    const { data } = await api.get(`/api/price-lists/${priceListId}/actual-version`)
    return data
  }

  /**
   * Create a new price list
   */
  async create(payload: PriceListCreatePayload): Promise<PriceList> {
    // Backend expects `name`, frontend uses `title` — remap
    const { title, supplier_id, ...rest } = payload
    const body = { ...rest, name: payload.name || title }
    const { data } = await api.post(`/api/suppliers/${supplier_id}/price-lists`, body)
    return data.data || data
  }

  /**
   * Update a price list
   */
  async update(priceListId: number, payload: PriceListUpdatePayload): Promise<PriceList> {
    // Backend expects `name`, frontend may send `title` — remap
    const { title, supplier_id, ...rest } = payload as any
    const body = { ...rest }
    if (title || payload.name) {
      body.name = payload.name || title
    }
    const { data } = await api.put(`/api/price-lists/${priceListId}`, body)
    return data.data || data
  }

  /**
   * Delete a price list
   */
  async delete(priceListId: number): Promise<void> {
    await api.delete(`/api/price-lists/${priceListId}`)
  }

  // ========== VERSION METHODS ==========

  /**
   * Get all versions for a price list
   */
  async getVersions(priceListId: number, params?: {
    page?: number
    per_page?: number
    status?: 'inactive' | 'active' | 'archived'
  }): Promise<{ data: PriceListVersion[] }> {
    const { data } = await api.get(`/api/price-lists/${priceListId}/versions`, { params })
    return data
  }

  /**
   * Get single version details
   */
  async getVersionById(versionId: number): Promise<PriceListVersion> {
    const { data } = await api.get(`/api/price-list-versions/${versionId}`)
    return data.data || data
  }

  /**
   * Activate a version (makes it active, archives old active)
   */
  async activateVersion(priceListId: number, versionId: number): Promise<PriceListVersion> {
    const { data } = await api.post(`/api/price-lists/${priceListId}/versions/${versionId}/activate`)
    return data.data || data
  }

  /**
   * Archive a version (only if not active)
   */
  async archiveVersion(priceListId: number, versionId: number): Promise<PriceListVersion> {
    const { data } = await api.post(`/api/price-lists/${priceListId}/versions/${versionId}/archive`)
    return data.data || data
  }

  /**
   * Download version file
   */
  async downloadVersion(versionId: number): Promise<Blob> {
    const { data } = await api.get(`/api/price-list-versions/${versionId}/download`, {
      responseType: 'blob'
    })
    return data
  }

  /**
   * Get version items (content)
   */
  async getVersionItems(versionId: number, params?: {
    page?: number
    per_page?: number
    q?: string
    price_type?: 'operation' | 'material'
    unlinked_only?: boolean
    linked_only?: boolean
  }): Promise<{ data: PriceListVersionItem[] }> {
    const { data } = await api.get(`/api/price-list-versions/${versionId}/items`, { params })
    return data
  }

  /**
   * Create a new version for a price list.
   * Supports file upload, URL, or manual source types.
   */
  async createVersion(payload: {
    price_list_id: number
    source_type: 'file' | 'url' | 'manual'
    file?: File
    source_url?: string
    effective_date?: string
    notes?: string
    manual_label?: string
  }): Promise<PriceListVersion> {
    const formData = new FormData()
    formData.append('price_list_id', String(payload.price_list_id))
    formData.append('source_type', payload.source_type)

    if (payload.file) formData.append('file', payload.file)
    if (payload.source_url) formData.append('source_url', payload.source_url)
    if (payload.effective_date) formData.append('effective_date', payload.effective_date)
    if (payload.notes) formData.append('notes', payload.notes)
    if (payload.manual_label) formData.append('manual_label', payload.manual_label)

    const { data } = await api.post('/api/price-list-versions', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return data
  }

  /**
   * Link an unlinked operation price to a base operation
   */
  async linkOperationPrice(operationPriceId: number, operationId: number, forceReplace: boolean = false): Promise<any> {
    const { data } = await api.put(`/api/operation-prices/${operationPriceId}/link`, {
      operation_id: operationId,
      force_replace: forceReplace
    })
    return data
  }

  /**
   * Unlink an operation price from its base operation
   */
  async unlinkOperationPrice(operationPriceId: number): Promise<any> {
    const { data } = await api.delete(`/api/operation-prices/${operationPriceId}/link`)
    return data
  }

  // ========== PRICE DOCUMENTS (DMS — no parsing) ==========

  /**
   * Upload a price document (PDF/XLSX/etc.) for a supplier.
   * No parsing — stores file as a version reference.
   */
  async uploadPriceDocument(supplierId: number, payload: {
    purpose?: 'finished_products' | 'operations'
    source_type: 'file' | 'url'
    file?: File
    source_url?: string
    title?: string
    effective_date?: string
  }): Promise<{ price_list: { id: number; name: string; type: string }; version: PriceListVersion }> {
    const formData = new FormData()
    formData.append('source_type', payload.source_type)
    if (payload.purpose) formData.append('purpose', payload.purpose)
    if (payload.file) formData.append('file', payload.file)
    if (payload.source_url) formData.append('source_url', payload.source_url)
    if (payload.title) formData.append('title', payload.title)
    if (payload.effective_date) formData.append('effective_date', payload.effective_date)

    const { data } = await api.post(`/api/suppliers/${supplierId}/price-documents`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return data
  }

  /**
   * List price documents (versions) for a supplier.
   */
  async getPriceDocuments(supplierId: number, params?: {
    purpose?: 'finished_products' | 'operations'
    page?: number
    per_page?: number
  }): Promise<{ data: PriceDocument[]; total: number }> {
    const { data } = await api.get(`/api/suppliers/${supplierId}/price-documents`, { params })
    return data
  }

  /**
   * Activate a price document version.
   */
  async activatePriceDocument(supplierId: number, versionId: number): Promise<any> {
    const { data } = await api.post(`/api/suppliers/${supplierId}/price-documents/${versionId}/activate`)
    return data
  }

  /**
   * Archive a price document version.
   */
  async archivePriceDocument(supplierId: number, versionId: number): Promise<any> {
    const { data } = await api.post(`/api/suppliers/${supplierId}/price-documents/${versionId}/archive`)
    return data
  }
}

export const priceListsApi = new PriceListsApiClient()
export default priceListsApi
