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
}
