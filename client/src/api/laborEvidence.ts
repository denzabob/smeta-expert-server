import api from './axios'

export interface RegionOption {
  id: number
  name?: string | null
  region_name?: string | null
}

export interface LaborProvider {
  id: number
  user_id: number
  title: string
  domain: string | null
  base_url: string | null
  is_active: boolean
  sort_order: number
  created_at?: string | null
  updated_at?: string | null
}

export interface LaborProfile {
  id: number
  user_id: number
  title: string
  description: string | null
  is_active: boolean
  sort_order: number
  created_at?: string | null
  updated_at?: string | null
}

export interface LaborEvidenceAsset {
  id: number
  uuid: string
  evidence_record_id: number
  asset_type: 'screenshot' | 'document' | string
  file_path: string
  original_filename: string | null
  mime_type: string | null
  file_size: number | null
  sha256?: string | null
  uploaded_by?: number | null
  created_at?: string | null
  updated_at?: string | null
}

export interface LaborAssetUploadPayload {
  file: File
  type: 'screenshot' | 'document'
}

export interface LaborEvidenceRecord {
  id: number
  uuid: string
  source_url: string | null
  source_domain: string | null
  observed_price: string | null
  currency: string | null
  observed_at: string | null
  capture_method: string | null
  verification_status: string | null
  assets?: LaborEvidenceAsset[]
}

export interface LaborEvidenceSource {
  id: number
  user_id: number
  region_id: number
  labor_profile_id: number | null
  provider_id: number
  evidence_record_id: number | null
  source_title: string | null
  source_url: string
  source_date: string | null
  employer_name: string | null
  vacancy_title: string | null
  vacancy_description: string | null
  vacancy_excerpt: string | null
  salary_raw_text: string | null
  salary_value: string | number | null
  salary_value_min: string | number | null
  salary_value_max: string | number | null
  salary_period: 'hour' | 'day' | 'month' | 'year' | 'project' | null
  hours_per_month: number
  derived_hourly_rate: string | number | null
  currency: string
  note: string | null
  captured_via: 'manual' | 'chrome' | 'import'
  verification_status: 'pending' | 'verified' | 'rejected'
  is_active: boolean
  created_at?: string | null
  updated_at?: string | null
  provider?: LaborProvider | null
  labor_profile?: LaborProfile | null
  laborProfile?: LaborProfile | null
  region?: RegionOption | null
  evidence_record?: LaborEvidenceRecord | null
  evidenceRecord?: LaborEvidenceRecord | null
}

export interface PaginatedResponse<T> {
  current_page: number
  data: T[]
  per_page: number
  total: number
  last_page: number
}

export interface UserSettingsResponse {
  region_id: number | null
}

export interface LaborSourcePayload {
  region_id: number
  provider_id: number
  labor_profile_id?: number | null
  source_title?: string | null
  source_url: string
  source_date?: string | null
  employer_name?: string | null
  vacancy_title?: string | null
  vacancy_description?: string | null
  vacancy_excerpt?: string | null
  salary_raw_text?: string | null
  salary_value?: number | null
  salary_value_min?: number | null
  salary_value_max?: number | null
  salary_period?: string | null
  hours_per_month?: number
  derived_hourly_rate?: number | null
  currency?: string
  note?: string | null
  captured_via?: string
  verification_status?: string
  is_active?: boolean
}

export interface ProjectLaborCostProfile {
  labor_profile_id: number
  labor_profile_name: string | null
  sources: {
    used_count: number
    skipped_count: number
    used_sources?: Array<{
      source_id: number
      title: string | null
      provider: string | null
      employer_name: string | null
      source_url: string | null
      source_date: string | null
      normalization_method: string | null
      hourly_rate: number
    }>
    skipped_sources?: Array<{
      source_id: number
      reason: string
      source_title: string | null
      provider: string | null
      labor_profile_id: number | null
      labor_profile_name: string | null
    }>
  }
  normalized_rates: number[]
  aggregation: {
    strategy: string
    method: string
    base_rate: number | null
  } | null
  model: {
    insurance_rate: number
    insurance_amount: number | null
    loaded_rate: number | null
    load_factor: number | null
    calendar_hours?: number
    productive_hours?: number
    cost_rate: number | null
    profitability_rate: number
    profit_amount: number | null
    final_rate: number | null
    rounding_scale: number
  } | null
  warnings: string[]
}

export interface ProjectLaborCostResponse {
  project_id: number
  calculated_at: string
  region: {
    id: number | null
    name: string | null
  }
  profiles: ProjectLaborCostProfile[]
  summary: {
    profiles_count: number
    total_used_sources: number
    total_skipped_sources: number
  }
  mapping: {
    mode: string
    works_mapping_supported: boolean
    note: string
  }
  deprecated_project_level_rate: boolean
  settings?: {
    aggregation_strategy: string
    employer_insurance_rate: number
    load_factor_calendar_hours: number
    load_factor_productive_hours: number
    planned_profitability_rate: number
    rounding_scale: number
  }
  skipped_sources: Array<{
    source_id: number
    reason: string
    source_title: string | null
    provider: string | null
    labor_profile_id: number | null
    labor_profile_name: string | null
  }>
  warnings: string[]
}

export function laborAssetUrl(asset: LaborEvidenceAsset): string {
  if (!asset.file_path) return '#'
  if (asset.file_path.startsWith('screenshots/')) {
    return `/api/${asset.file_path}`
  }

  return `/storage/${asset.file_path}`
}

export function laborRegionLabel(region?: RegionOption | null): string {
  return region?.name || region?.region_name || '—'
}

export function laborProfileOf(source: LaborEvidenceSource): LaborProfile | null {
  return source.laborProfile || source.labor_profile || null
}

export function laborEvidenceRecordOf(source: LaborEvidenceSource): LaborEvidenceRecord | null {
  return source.evidenceRecord || source.evidence_record || null
}

export const laborEvidenceApi = {
  async listSources(params: Record<string, unknown> = {}): Promise<PaginatedResponse<LaborEvidenceSource>> {
    const { data } = await api.get('/api/pricing/labor/sources', { params })
    return data
  },

  async getSource(id: number): Promise<LaborEvidenceSource> {
    const { data } = await api.get(`/api/pricing/labor/sources/${id}`)
    return data
  },

  async createSource(payload: LaborSourcePayload): Promise<LaborEvidenceSource> {
    const { data } = await api.post('/api/pricing/labor/sources', payload)
    return data
  },

  async updateSource(id: number, payload: Partial<LaborSourcePayload>): Promise<LaborEvidenceSource> {
    const { data } = await api.put(`/api/pricing/labor/sources/${id}`, payload)
    return data
  },

  async deleteSource(id: number): Promise<void> {
    await api.delete(`/api/pricing/labor/sources/${id}`)
  },

  async listProviders(params: Record<string, unknown> = {}): Promise<PaginatedResponse<LaborProvider>> {
    const { data } = await api.get('/api/pricing/labor/providers', { params })
    return data
  },

  async createProvider(payload: Partial<LaborProvider>): Promise<LaborProvider> {
    const { data } = await api.post('/api/pricing/labor/providers', payload)
    return data
  },

  async updateProvider(id: number, payload: Partial<LaborProvider>): Promise<LaborProvider> {
    const { data } = await api.put(`/api/pricing/labor/providers/${id}`, payload)
    return data
  },

  async deleteProvider(id: number): Promise<void> {
    await api.delete(`/api/pricing/labor/providers/${id}`)
  },

  async listProfiles(params: Record<string, unknown> = {}): Promise<PaginatedResponse<LaborProfile>> {
    const { data } = await api.get('/api/pricing/labor/profiles', { params })
    return data
  },

  async createProfile(payload: Partial<LaborProfile>): Promise<LaborProfile> {
    const { data } = await api.post('/api/pricing/labor/profiles', payload)
    return data
  },

  async updateProfile(id: number, payload: Partial<LaborProfile>): Promise<LaborProfile> {
    const { data } = await api.put(`/api/pricing/labor/profiles/${id}`, payload)
    return data
  },

  async deleteProfile(id: number): Promise<void> {
    await api.delete(`/api/pricing/labor/profiles/${id}`)
  },

  async listAssets(sourceId: number): Promise<LaborEvidenceAsset[]> {
    const { data } = await api.get(`/api/pricing/labor/sources/${sourceId}/assets`)
    return data?.data || []
  },

  async uploadAsset(sourceId: number, payload: LaborAssetUploadPayload): Promise<LaborEvidenceAsset> {
    const formData = new FormData()
    formData.append('file', payload.file)
    formData.append('type', payload.type)

    const { data } = await api.post(`/api/pricing/labor/sources/${sourceId}/assets`, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })

    return data
  },

  async deleteAsset(sourceId: number, assetId: number): Promise<void> {
    await api.delete(`/api/pricing/labor/sources/${sourceId}/assets/${assetId}`)
  },

  async getProjectSources(projectId: number | string): Promise<LaborEvidenceSource[]> {
    const { data } = await api.get(`/api/projects/${projectId}/labor-sources`)
    return data?.data || []
  },

  async attachProjectSources(projectId: number | string, sourceIds: number[]): Promise<LaborEvidenceSource[]> {
    const { data } = await api.post(`/api/projects/${projectId}/labor-sources/attach`, {
      source_ids: sourceIds,
    })
    return data?.data || []
  },

  async detachProjectSources(projectId: number | string, sourceIds: number[]): Promise<LaborEvidenceSource[]> {
    const { data } = await api.post(`/api/projects/${projectId}/labor-sources/detach`, {
      source_ids: sourceIds,
    })
    return data?.data || []
  },

  async getUserSettings(): Promise<UserSettingsResponse> {
    const { data } = await api.get('/api/user/settings')
    return data
  },

  async getProjectLaborCost(projectId: number | string): Promise<ProjectLaborCostResponse> {
    const { data } = await api.get(`/api/projects/${projectId}/labor-cost`)
    return data
  },
}
