import api from '@/api/axios'

export interface ExpertAiRun {
  run_id: string
  started_at: string | null
  first_token_at: string | null
  finished_at: string | null
  duration_ms: number | null
  status: string
  stage: string
  user: { id: number; name: string } | null
  project: { id: number; name: string } | null
  conversation: { id: number } | null
  selected_material_count: number
  persisted_material_count: number
  resolved_material_count: number
  active_material_count: number
  requested_mode: string | null
  resolved_mode: string | null
  provider: string | null
  model: string | null
  error_code: string | null
  retryable: boolean
  finish_reason: string | null
}

export interface ExpertAiRunEvent {
  seq: number
  created_at: string | null
  source: string
  level: string
  event_code: string
  stage: string
  status: string
  payload: Record<string, string | number | boolean> | null
}

export interface ExpertAiRunFilters {
  status?: string
  stage?: string
  error_code?: string
  user_id?: string
  project_id?: string
  conversation_id?: string
  run_id?: string
  date_from?: string
  date_to?: string
  provider?: string
  model?: string
  requested_mode?: string
  page?: number
  per_page?: number
}

export interface ExpertAiRunListResponse {
  data: ExpertAiRun[]
  meta: { current_page: number; per_page: number; total: number; last_page: number }
}

export interface ExpertAiRunDetailResponse {
  run: ExpertAiRun
  events: ExpertAiRunEvent[]
}

export const adminExpertAiRunsApi = {
  list(params: ExpertAiRunFilters = {}): Promise<ExpertAiRunListResponse> {
    const query = Object.fromEntries(
      Object.entries(params).filter(([, value]) => value !== '' && value != null),
    )
    return api.get('/api/admin/expert-ai-runs', { params: query }).then((response) => response.data)
  },

  show(runId: string): Promise<ExpertAiRunDetailResponse> {
    return api.get(`/api/admin/expert-ai-runs/${encodeURIComponent(runId)}`).then((response) => response.data)
  },
}
