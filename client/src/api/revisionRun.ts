import api from './axios'

export type RevisionRunStatus = 'PENDING' | 'IN_PROGRESS' | 'NEEDS_MANUAL' | 'READY' | 'FINALIZED' | 'FAILED'
export type RevisionRunItemStatus = 'PENDING' | 'OK' | 'OK_NO_PRICE' | 'BLOCKED' | 'TIMEOUT' | 'PARSE_ERROR' | 'NO_TEMPLATE' | 'NEEDS_MANUAL'

export interface RevisionRun {
  id: number
  project_id: number
  status: RevisionRunStatus
  total_items: number
  ok_items: number
  failed_items: number
  started_at?: string | null
  finished_at?: string | null
  last_error?: string | null
}

export type CostDriverType = 'plate' | 'edge' | 'facade' | 'fitting' | 'operation' | 'labor_work' | 'expense'
export type CaptureSourceType = 'auto' | 'manual' | 'chrome_ext' | 'internal'

export interface EvidenceAssetDetail {
  id: number
  evidence_artifact_id: number
  asset_type: string
  mime_type: string | null
  original_filename: string | null
  file_size: number | null
}

export interface EvidenceArtifactDetail {
  id: number
  revision_run_item_id: number
  capture_source: CaptureSourceType | null
  mode: string | null
  extracted_price: string | null
  currency: string | null
  source_url_raw: string | null
  source_domain: string | null
  captured_at: string | null
  created_at: string | null
  trust_score: number | null
  assets: EvidenceAssetDetail[]
}

export interface RevisionRunItem {
  id: number
  revision_run_id: number
  project_position_id: number | null
  project_fitting_id?: number | null
  material_id: number | null
  material?: Record<string, unknown>
  source_url: string | null
  status: RevisionRunItemStatus
  message: string | null
  price_history_id: number | null
  position?: Record<string, unknown>
  projectFitting?: Record<string, unknown>
  price_history?: Record<string, unknown>
  priceHistory?: Record<string, unknown>
  cost_driver_type?: CostDriverType | null
  resolved_capture_source?: CaptureSourceType | null
  has_evidence?: boolean
  evidence_artifacts?: EvidenceArtifactDetail[]
}

export function evidenceAssetFileUrl(assetId: number): string {
  return `/api/evidence-assets/${assetId}/file`
}

export interface StartRunResponse {
  success: boolean
  run_id: number
  status: RevisionRunStatus
  total_items: number
}

export interface RunShowResponse {
  run: RevisionRun
  items: RevisionRunItem[]
}

export interface FinalizeRunResponse {
  success: boolean
  revision: {
    id: number
    number: number
  }
  pdf: {
    smeta: string
    price_justification: string
  }
}

export const revisionRunApi = {
  async start(projectId: string | number): Promise<StartRunResponse> {
    const { data } = await api.post(`/api/projects/${projectId}/revisions/run`)
    return data
  },

  async show(projectId: string | number, runId: string | number): Promise<RunShowResponse> {
    const { data } = await api.get(`/api/projects/${projectId}/revisions/run/${runId}`)
    return data
  },

  async retry(projectId: string | number, runId: string | number): Promise<{ success: boolean; run_id: number; status: RevisionRunStatus }> {
    const { data } = await api.post(`/api/projects/${projectId}/revisions/run/${runId}/retry`)
    return data
  },

  async manual(
    runId: string | number,
    itemId: string | number,
    payload: {
      price_per_unit: number
      currency: string
      screenshot_file: File
      source_url?: string
      region_id?: number
    }
  ) {
    const formData = new FormData()
    formData.append('price_per_unit', String(payload.price_per_unit))
    formData.append('currency', payload.currency)
    formData.append('screenshot_file', payload.screenshot_file)
    if (payload.source_url) {
      formData.append('source_url', payload.source_url)
    }
    if (typeof payload.region_id === 'number') {
      formData.append('region_id', String(payload.region_id))
    }

    const { data } = await api.post(`/api/revisions/run/${runId}/items/${itemId}/manual`, formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })
    return data
  },

  async finalize(projectId: string | number, runId: string | number): Promise<FinalizeRunResponse> {
    const { data } = await api.post(`/api/projects/${projectId}/revisions/run/${runId}/finalize`)
    return data
  },

  async attachDocument(runId: string | number, itemId: string | number, file: File) {
    const formData = new FormData()
    formData.append('document_file', file)
    const { data } = await api.post(`/api/revisions/run/${runId}/items/${itemId}/attach-document`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return data
  },
}
