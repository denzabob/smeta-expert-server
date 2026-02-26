import api from './axios'
import type { AxiosResponse } from 'axios'

// ===== Types =====

export type MaterialType = 'plate' | 'edge' | 'facade' | 'hardware'
export type MaterialUnit = 'м²' | 'м.п.' | 'шт'
export type MaterialVisibility = 'private' | 'public' | 'curated'
export type TrustLevel = 'unverified' | 'partial' | 'verified'
export type DataOrigin = 'manual' | 'url_parse' | 'price_list' | 'chrome_ext'
export type SourceType = 'web' | 'manual' | 'price_list' | 'chrome_ext'
export type CatalogMode = 'own' | 'library' | 'public' | 'curated'

export interface LatestPrice {
  price_per_unit: number
  observed_at: string | null
  source_url: string | null
  region_id: number | null
  is_verified: boolean
  currency: string
}

export interface CatalogMaterial {
  id: number
  name: string
  article: string
  type: MaterialType
  unit: MaterialUnit
  price_per_unit: number
  source_url: string | null
  visibility: MaterialVisibility
  trust_score: number
  trust_level: TrustLevel
  data_origin: DataOrigin
  user_id: number | null
  region_id: number | null
  is_active: boolean
  created_at: string
  updated_at: string
  price_checked_at: string | null
  latest_price: LatestPrice | null
  in_library: boolean
  pinned: boolean
  facade_class: string | null
  metadata: Record<string, any> | null
}

export interface CatalogMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
  mode: CatalogMode
  region_id: number | null
}

export interface CatalogResponse {
  data: CatalogMaterial[]
  meta: CatalogMeta
}

export interface PriceObservation {
  id: number
  price_per_unit: number
  source_url: string | null
  observed_at: string | null
  valid_from: string | null
  region_id: number | null
  source_type: SourceType
  is_verified: boolean
  currency: string
  availability: string | null
  screenshot_path: string | null
  snapshot_path: string | null
  created_at: string
}

export interface ParseResult {
  success: boolean
  data: {
    name: string | null
    article: string | null
    price_per_unit: number | null
    type: MaterialType
    unit: MaterialUnit | null
    source_url: string
  } | null
  duplicates: Array<{
    material: CatalogMaterial
    reason: string
    confidence: string
  }>
  parse_session_id: number | null
  confidence: number
  has_selectors?: boolean
  parse_source?: string
  parse_status?: 'ok' | 'partial' | 'no_fields' | 'blocked' | 'error'
  diagnostics?: {
    page_status?: string | null
    filled_fields?: string[]
    missing_fields?: string[]
    domain_supported?: boolean
  }
  message: string
}

export interface DomainCheckResult {
  supported: boolean
  source: string | null
  detected_type: MaterialType
  has_selectors: boolean
  selector_fields: string[]
}

export interface StoreMaterialPayload {
  name: string
  article: string
  type: MaterialType
  unit: MaterialUnit
  price_per_unit: number
  source_url: string
  region_id?: number | null
  data_origin?: DataOrigin
  visibility?: MaterialVisibility
  observation_region_id?: number | null
  observation_source_type?: SourceType
  parse_session_id?: number | null
  // Optional fields
  thickness?: number | null
  waste_factor?: number | null
  length_mm?: number | null
  width_mm?: number | null
  thickness_mm?: number | null
  material_tag?: string | null
  metadata?: Record<string, any> | null
  operation_ids?: number[] | null
  // Facade fields
  facade_class?: string | null
  facade_base_type?: string | null
  facade_thickness_mm?: number | null
  facade_covering?: string | null
  facade_cover_type?: string | null
  facade_collection?: string | null
  facade_price_group_label?: string | null
  facade_decor_label?: string | null
  facade_article_optional?: string | null
}

export interface AddObservationPayload {
  price_per_unit: number
  source_url: string
  region_id?: number | null
  source_type?: SourceType
  currency?: string
  availability?: string | null
  screenshot_path?: string | null
  snapshot_path?: string | null
}

export interface CatalogFilters {
  mode?: CatalogMode
  type?: MaterialType | null
  region_id?: number | null
  trust_level?: TrustLevel | null
  recent_days?: number | null
  search?: string | null
  per_page?: number
  page?: number
}

export interface TrustBreakdownItem {
  label: string
  points: number
  max: number
  met: boolean
  description: string
}

export interface MaterialDetail extends CatalogMaterial {
  thickness: number | null
  thickness_mm: number | null
  length_mm: number | null
  width_mm: number | null
  waste_factor: number | null
  material_tag: string | null
  last_parsed_at: string | null
  last_parse_status: string | null
  last_parse_error: string | null
  observation_count: number
}

export interface MaterialDetailResponse {
  material: MaterialDetail
  trust_breakdown: TrustBreakdownItem[]
}

export interface UpdateMaterialPayload {
  name?: string
  article?: string | null
  type?: MaterialType
  unit?: MaterialUnit
  source_url?: string | null
  visibility?: MaterialVisibility
  thickness_mm?: number | null
  length_mm?: number | null
  width_mm?: number | null
  waste_factor?: number | null
  material_tag?: string | null
  region_id?: number | null
}

// ===== API Functions =====

/**
 * GET /api/materials/catalog
 * Browse catalog with filters.
 */
export function fetchCatalog(filters: CatalogFilters = {}): Promise<AxiosResponse<CatalogResponse>> {
  const params: Record<string, any> = {}
  if (filters.mode) params.mode = filters.mode
  if (filters.type) params.type = filters.type
  if (filters.region_id) params.region_id = filters.region_id
  if (filters.trust_level) params.trust_level = filters.trust_level
  if (filters.recent_days) params.recent_days = filters.recent_days
  if (filters.search) params.search = filters.search
  if (filters.per_page) params.per_page = filters.per_page
  if (filters.page) params.page = filters.page

  return api.get('/api/materials/catalog', { params })
}

/**
 * POST /api/materials/parse-by-url
 * Parse data from URL.
 */
export function parseByUrl(url: string, type: MaterialType, regionId?: number | null): Promise<AxiosResponse<ParseResult>> {
  return api.post('/api/materials/parse-by-url', {
    url,
    type,
    region_id: regionId ?? undefined,
  })
}

/**
 * POST /api/materials/check-domain
 * Check if domain has parsing selectors.
 */
export function checkDomain(url: string): Promise<AxiosResponse<DomainCheckResult>> {
  return api.post('/api/materials/check-domain', { url })
}

/**
 * POST /api/materials/catalog
 * Create material via catalog flow.
 */
export function storeCatalogMaterial(payload: StoreMaterialPayload): Promise<AxiosResponse<CatalogMaterial>> {
  return api.post('/api/materials/catalog', payload)
}

/**
 * POST /api/materials/{id}/refresh
 * Re-parse material from source URL.
 */
export function refreshMaterial(id: number, regionId?: number | null): Promise<AxiosResponse<any>> {
  return api.post(`/api/materials/${id}/refresh`, { region_id: regionId ?? undefined })
}

/**
 * GET /api/materials/{id}/price-observations
 * List price observations.
 */
export function fetchPriceObservations(id: number, regionId?: number | null): Promise<AxiosResponse<{ material_id: number; observations: PriceObservation[] }>> {
  const params: Record<string, any> = {}
  if (regionId) params.region_id = regionId
  return api.get(`/api/materials/${id}/price-observations`, { params })
}

/**
 * POST /api/materials/{id}/price-observations
 * Add manual price observation.
 */
export function addPriceObservation(id: number, payload: AddObservationPayload): Promise<AxiosResponse<PriceObservation>> {
  return api.post(`/api/materials/${id}/price-observations`, payload)
}

/**
 * POST /api/materials/{id}/library
 * Add material to user's library.
 */
export function addToLibrary(id: number, opts?: { preferred_region_id?: number; notes?: string }): Promise<AxiosResponse<any>> {
  return api.post(`/api/materials/${id}/library`, opts ?? {})
}

/**
 * DELETE /api/materials/{id}/library
 * Remove material from user's library.
 */
export function removeFromLibrary(id: number): Promise<AxiosResponse<any>> {
  return api.delete(`/api/materials/${id}/library`)
}

/**
 * PATCH /api/materials/{id}/library
 * Update library entry (pin, notes, etc).
 */
export function updateLibraryEntry(id: number, data: { pinned?: boolean; notes?: string; preferred_region_id?: number | null }): Promise<AxiosResponse<any>> {
  return api.patch(`/api/materials/${id}/library`, data)
}

/**
 * POST /api/materials/merge
 * Merge duplicate into primary.
 */
export function mergeMaterials(primaryId: number, duplicateId: number): Promise<AxiosResponse<any>> {
  return api.post('/api/materials/merge', { primary_id: primaryId, duplicate_id: duplicateId })
}

/**
 * POST /api/materials/{id}/recalculate-trust
 * Force recalculate trust score.
 */
export function recalculateTrust(id: number): Promise<AxiosResponse<{ trust_score: number; trust_level: TrustLevel }>> {
  return api.post(`/api/materials/${id}/recalculate-trust`)
}

/**
 * GET /api/materials/catalog/{id}
 * Get detailed material info with trust breakdown.
 */
export function fetchMaterialDetail(id: number): Promise<AxiosResponse<MaterialDetailResponse>> {
  return api.get(`/api/materials/catalog/${id}`)
}

/**
 * PUT /api/materials/catalog/{id}
 * Update material parameters.
 */
export function updateCatalogMaterial(id: number, payload: UpdateMaterialPayload): Promise<AxiosResponse<{ message: string; material: CatalogMaterial }>> {
  return api.put(`/api/materials/catalog/${id}`, payload)
}

export default {
  fetchCatalog,
  parseByUrl,
  checkDomain,
  storeCatalogMaterial,
  refreshMaterial,
  fetchPriceObservations,
  addPriceObservation,
  addToLibrary,
  removeFromLibrary,
  updateLibraryEntry,
  mergeMaterials,
  recalculateTrust,
  fetchMaterialDetail,
  updateCatalogMaterial,
}
