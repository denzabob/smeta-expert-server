import api from './axios'

// ── Run statuses (mirror server/app/Evidence/EvidenceRunStatus.php) ──
export type EvidenceRunStatus = 'pending' | 'in_progress' | 'ready' | 'finalized' | 'failed'

// ── Item statuses (mirror server/app/Evidence/EvidenceItemStatus.php) ──
export type EvidenceItemStatus = 'pending' | 'collecting' | 'resolved' | 'failed' | 'skipped'

// ── Cost component types ──
export type CostComponent =
  | 'plate'
  | 'edge'
  | 'facade'
  | 'fitting'
  | 'operation'
  | 'labor_work'
  | 'expense'

// ── Evidence record (linked to resolved item) ──
export interface EvidenceRecord {
  id: number
  uuid: string
  cost_component: string | null
  source_type: string | null
  capture_method: string | null
  verification_status: string | null
  source_url: string | null
  source_domain: string | null
  observed_price: string | null
  currency: string | null
  observed_at: string | null
  extracted_name: string | null
  extracted_article: string | null
  created_at: string | null
}

// ── H11 list endpoint item ──
export interface EvidenceListItem {
  id: number
  observed_price: string | null
  currency: string | null
  source_type: string | null
  verification_status: string | null
  created_at: string | null
  assets_count: number
  linked_targets: { type: string; id: number }[]
}

export interface EvidenceListResponse {
  data: EvidenceListItem[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

// ── H9 detail endpoint ──
export interface EvidenceRecordAsset {
  asset_id: number
  asset_type: string
  original_filename: string | null
  mime_type: string | null
  file_size: number | null
  download_url: string | null
}

export interface EvidenceRecordDetail {
  evidence_record_id: number
  uuid: string
  observed_price: string | null
  currency: string | null
  cost_component: string | null
  source_type: string | null
  capture_method: string | null
  source_url: string | null
  verification_status: string | null
  metadata_json: Record<string, unknown> | null
  created_by: number | null
  created_at: string | null
  linked_targets: { type: string; id: number }[]
  assets: EvidenceRecordAsset[]
}

export interface EvidenceDetailResponse {
  data: EvidenceRecordDetail
}

// ── Evidence record picker item (shaped for search endpoint) ──
export interface EvidenceRecordPickerItem {
  id: number
  uuid: string
  extracted_name: string | null
  source_url: string | null
  source_domain: string | null
  observed_price: string | null
  currency: string | null
  cost_component: string | null
  capture_method: string | null
  observed_at: string | null
  created_at: string | null
  has_screenshot: boolean
}

export interface EvidenceRecordSearchResponse {
  success: boolean
  data: EvidenceRecordPickerItem[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

// ── Evidence item ──
export interface EvidenceItem {
  id: number
  uuid: string
  evidence_run_id: number
  cost_component: CostComponent | null
  label: string | null
  status: EvidenceItemStatus
  resolution_type: string | null
  subject_type: string | null
  subject_id: number | null
  evidence_record_id: number | null
  source_url: string | null
  effective_value: string | null
  currency: string | null
  diagnostics_json: Record<string, unknown> | null
  evidence_record?: EvidenceRecord | null
  created_at: string
  updated_at: string
}

// ── Evidence run ──
export interface EvidenceRun {
  id: number
  uuid: string
  project_id: number
  initiated_by: number
  status: EvidenceRunStatus
  total_items: number
  completed_items: number
  failed_items: number
  metadata_json: Record<string, unknown> | null
  snapshot_json: Record<string, unknown> | null
  started_at: string | null
  finalized_at: string | null
  created_at: string
  updated_at: string
  items?: EvidenceItem[]
}

// ── Response wrappers ──
export interface EvidenceRunListResponse {
  success: boolean
  data: EvidenceRun[]
}

export interface EvidenceRunShowResponse {
  success: boolean
  data: EvidenceRun & { items: EvidenceItem[] }
}

export interface EvidenceRunCreateResponse {
  success: boolean
  data: EvidenceRun & { items: EvidenceItem[] }
}

export interface EvidenceRunActionResponse {
  success: boolean
  data: EvidenceItem
}

export interface EvidenceRunFinalizeResponse {
  success: boolean
  data: EvidenceRun & { items: EvidenceItem[] }
}

export const evidenceRunApi = {
  /** GET /api/projects/{project}/evidence-runs */
  async list(projectId: number | string): Promise<EvidenceRunListResponse> {
    const { data } = await api.get(`/api/projects/${projectId}/evidence-runs`)
    return data
  },

  /** POST /api/projects/{project}/evidence-runs */
  async create(projectId: number | string): Promise<EvidenceRunCreateResponse> {
    const { data } = await api.post(`/api/projects/${projectId}/evidence-runs`)
    return data
  },

  /** GET /api/projects/{project}/evidence-runs/{runId} */
  async show(projectId: number | string, runId: number | string): Promise<EvidenceRunShowResponse> {
    const { data } = await api.get(`/api/projects/${projectId}/evidence-runs/${runId}`)
    return data
  },

  /** POST /api/projects/{project}/evidence-runs/{runId}/items/{itemId}/resolve */
  async resolveItem(
    projectId: number | string,
    runId: number | string,
    itemId: number | string,
    payload: { evidence_record_id: number; resolution_type?: string },
  ): Promise<EvidenceRunActionResponse> {
    const { data } = await api.post(
      `/api/projects/${projectId}/evidence-runs/${runId}/items/${itemId}/resolve`,
      payload,
    )
    return data
  },

  /** POST /api/projects/{project}/evidence-runs/{runId}/items/{itemId}/skip */
  async skipItem(
    projectId: number | string,
    runId: number | string,
    itemId: number | string,
    payload: { reason?: string },
  ): Promise<EvidenceRunActionResponse> {
    const { data } = await api.post(
      `/api/projects/${projectId}/evidence-runs/${runId}/items/${itemId}/skip`,
      payload,
    )
    return data
  },

  /** POST /api/projects/{project}/evidence-runs/{runId}/refresh */
  async refreshRun(
    projectId: number | string,
    runId: number | string,
  ): Promise<EvidenceRunShowResponse & { auto_resolved: number }> {
    const { data } = await api.post(
      `/api/projects/${projectId}/evidence-runs/${runId}/refresh`,
    )
    return data
  },

  /** POST /api/projects/{project}/evidence-runs/{runId}/finalize */
  async finalize(
    projectId: number | string,
    runId: number | string,
  ): Promise<EvidenceRunFinalizeResponse> {
    const { data } = await api.post(
      `/api/projects/${projectId}/evidence-runs/${runId}/finalize`,
    )
    return data
  },

  /** GET /api/projects/{project}/evidence-runs/{runId}/pdf — returns blob */
  async downloadPdf(projectId: number | string, runId: number | string): Promise<Blob> {
    const { data } = await api.get(
      `/api/projects/${projectId}/evidence-runs/${runId}/pdf`,
      { responseType: 'blob' },
    )
    return data
  },

  /** GET /api/evidence-records/search — paginated search for picker */
  async searchRecords(params: {
    q?: string
    cost_component?: string
    per_page?: number
    page?: number
  }): Promise<EvidenceRecordSearchResponse> {
    const { data } = await api.get('/api/evidence-records/search', { params })
    return data
  },

  /** GET /api/projects/{project}/evidence-runs/{runId}/items/{itemId}/candidates — strict picker */
  async searchCandidatesForItem(
    projectId: number | string,
    runId: number | string,
    itemId: number | string,
    params: { q?: string; per_page?: number; page?: number },
  ): Promise<EvidenceRecordSearchResponse> {
    const { data } = await api.get(
      `/api/projects/${projectId}/evidence-runs/${runId}/items/${itemId}/candidates`,
      { params },
    )
    return data
  },

  /** POST /api/projects/{project}/evidence-runs/{runId}/items/{itemId}/manual-resolve */
  async manualResolveItem(
    projectId: number | string,
    runId: number | string,
    itemId: number | string,
    formData: FormData,
  ): Promise<EvidenceRunActionResponse> {
    const { data } = await api.post(
      `/api/projects/${projectId}/evidence-runs/${runId}/items/${itemId}/manual-resolve`,
      formData,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    )
    return data
  },

  /** GET /api/evidence-records — H11 paginated list filtered by linked target */
  async listRecords(params: {
    linkable_type?: string
    linkable_id?: number
    verification_status?: string
    source_type?: string
    has_assets?: boolean
    per_page?: number
    page?: number
  }): Promise<EvidenceListResponse> {
    const { data } = await api.get('/api/evidence-records', { params })
    return data
  },

  /** GET /api/evidence-records/{id} — H9 single record detail */
  async getRecord(id: number): Promise<EvidenceDetailResponse> {
    const { data } = await api.get(`/api/evidence-records/${id}`)
    return data
  },

  /**
   * H6/H7 create-and-attach endpoint.
   * POST /api/operation-prices/{id}/evidence-records
   * POST /api/price-list-versions/{id}/evidence-records
   * Sends multipart/form-data (files[] is optional).
   */
  async createAndAttach(
    linkableType: 'operation_price' | 'price_list_version',
    linkableId: number,
    formData: FormData,
  ): Promise<{ message: string; evidence_record_id: number; evidence_link_id: number; has_evidence: boolean; assets_count: number }> {
    const url =
      linkableType === 'operation_price'
        ? `/api/operation-prices/${linkableId}/evidence-records`
        : `/api/price-list-versions/${linkableId}/evidence-records`
    const { data } = await api.post(url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return data
  },

  /**
   * H8 detach endpoint — deletes only the EvidenceLink row, not the record itself.
   * DELETE /api/operation-prices/{id}/evidence-links/{linkId}
   * DELETE /api/price-list-versions/{id}/evidence-links/{linkId}
   */
  async detachLink(
    linkableType: 'operation_price' | 'price_list_version',
    linkableId: number,
    linkId: number,
  ): Promise<void> {
    const base =
      linkableType === 'operation_price'
        ? `/api/operation-prices/${linkableId}/evidence-links`
        : `/api/price-list-versions/${linkableId}/evidence-links`
    await api.delete(`${base}/${linkId}`)
  },

  /**
   * H10 update verification status.
   * PATCH /api/evidence-records/{id}/verification-status
   */
  async updateVerificationStatus(
    recordId: number,
    status: string,
  ): Promise<{ data: { evidence_record_id: number; verification_status: string } }> {
    const { data } = await api.patch(`/api/evidence-records/${recordId}/verification-status`, {
      verification_status: status,
    })
    return data
  },

  /**
   * List evidence links for a target to retrieve evidence_link_id values (needed for detach).
   * GET /api/operation-prices/{id}/evidence-links
   * GET /api/price-list-versions/{id}/evidence-links
   */
  async listLinks(
    linkableType: 'operation_price' | 'price_list_version',
    linkableId: number,
  ): Promise<{ data: { evidence_link_id: number; evidence_record_id: number }[] }> {
    const url =
      linkableType === 'operation_price'
        ? `/api/operation-prices/${linkableId}/evidence-links`
        : `/api/price-list-versions/${linkableId}/evidence-links`
    const { data } = await api.get(url)
    return data
  },
}
