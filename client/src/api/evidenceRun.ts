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
}
