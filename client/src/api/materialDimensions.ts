import api from '@/api/axios'

export type MaterialDimensionMaterialType = 'plate' | 'edge' | 'hardware' | 'facade' | 'fitting'

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface MaterialDimensionRuleConfig {
  pattern: string
  flags?: string
  use_normalized_text?: boolean
  captures?: {
    length_mm?: number
    width_mm?: number
    thickness_mm?: number
  }
  fixed?: {
    length_mm?: number
    width_mm?: number
    thickness_mm?: number
  }
}

export interface MaterialDimensionRuleExpectedResult {
  length_mm?: number
  width_mm?: number
  thickness_mm?: number
}

export interface MaterialDimensionRule {
  id: number
  name: string
  description: string | null
  is_active: boolean
  priority: number
  material_type: MaterialDimensionMaterialType | null
  source: string | null
  rule_type: 'regex'
  config: MaterialDimensionRuleConfig
  example_input: string | null
  expected_result: MaterialDimensionRuleExpectedResult | null
  confidence: number
  created_by_user_id: number | null
  updated_by_user_id: number | null
  created_at: string | null
  updated_at: string | null
}

export interface MaterialDimensionRuleListResponse {
  data: MaterialDimensionRule[]
  meta: PaginationMeta
}

export interface MaterialDimensionRulesListParams {
  search?: string
  material_type?: MaterialDimensionMaterialType
  source?: string
  is_active?: boolean
  page?: number
  per_page?: number
}

export interface UpsertMaterialDimensionRulePayload {
  name: string
  description?: string | null
  is_active?: boolean
  priority?: number
  material_type?: MaterialDimensionMaterialType | null
  source?: string | null
  rule_type: 'regex'
  config: MaterialDimensionRuleConfig
  example_input?: string | null
  expected_result?: MaterialDimensionRuleExpectedResult | null
  confidence?: number
}

export interface MaterialDimensionParseFailure {
  id: number
  fingerprint: string
  raw_text: string
  normalized_text: string
  material_type: MaterialDimensionMaterialType | null
  source: string | null
  parse_error_reason: string | null
  occurrences: number
  first_seen_at: string | null
  last_seen_at: string | null
  resolved_length_mm: number | null
  resolved_width_mm: number | null
  resolved_thickness_mm: number | null
  resolution_note: string | null
  resolved_by_user_id: number | null
  resolved_at: string | null
  last_result: Record<string, unknown> | null
  created_at: string | null
  updated_at: string | null
}

export interface MaterialDimensionParseFailureListResponse {
  data: MaterialDimensionParseFailure[]
  meta: PaginationMeta
}

export interface MaterialDimensionFailuresListParams {
  search?: string
  material_type?: MaterialDimensionMaterialType
  source?: string
  status?: 'resolved' | 'unresolved'
  page?: number
  per_page?: number
}

export interface UpdateMaterialDimensionParseFailurePayload {
  resolved_length_mm?: number | null
  resolved_width_mm?: number | null
  resolved_thickness_mm?: number | null
  resolution_note?: string | null
}

export interface MaterialDimensionRulePreset {
  name?: string
  description?: string | null
  material_type?: MaterialDimensionMaterialType | null
  source?: string | null
  example_input?: string | null
  expected_length_mm?: number | null
  expected_width_mm?: number | null
  expected_thickness_mm?: number | null
  pattern?: string
  captures?: {
    length_mm?: number
    width_mm?: number
    thickness_mm?: number
  }
  fixed?: {
    length_mm?: number
    width_mm?: number
    thickness_mm?: number
  }
  from_failed_case?: boolean
  prefilled_fields?: string[]
}

export interface MaterialDimensionParsePreviewResult {
  success: boolean
  length_mm: number | null
  width_mm: number | null
  thickness_mm: number | null
  confidence: number
  source: string
  rule_type: string | null
  strategy_name: string | null
  normalized_text: string
  error_reason: string | null
  rule_id: number | null
  meta: Record<string, unknown>
}

export interface MaterialDimensionRulePreviewResponse {
  parse_result: MaterialDimensionParsePreviewResult
  test_text: string
}

export type MaterialTypePatternTargetField = 'title' | 'url' | 'title_or_url'

export interface MaterialTypePattern {
  id: number
  name: string
  description: string | null
  is_active: boolean
  priority: number
  material_type: MaterialDimensionMaterialType
  source: string | null
  rule_type: 'regex'
  target_field: MaterialTypePatternTargetField
  pattern: string
  flags: string
  use_normalized_text: boolean
  example_input: string | null
  expected_material_type: MaterialDimensionMaterialType | null
  created_by_user_id: number | null
  updated_by_user_id: number | null
  created_at: string | null
  updated_at: string | null
}

export interface MaterialTypePatternListResponse {
  data: MaterialTypePattern[]
  meta: PaginationMeta
}

export interface MaterialTypePatternsListParams {
  search?: string
  material_type?: MaterialDimensionMaterialType
  target_field?: MaterialTypePatternTargetField
  source?: string
  is_active?: boolean
  page?: number
  per_page?: number
}

export interface UpsertMaterialTypePatternPayload {
  name: string
  description?: string | null
  is_active?: boolean
  priority?: number
  material_type: MaterialDimensionMaterialType
  source?: string | null
  rule_type: 'regex'
  target_field: MaterialTypePatternTargetField
  pattern: string
  flags?: string
  use_normalized_text?: boolean
  example_input?: string | null
  expected_material_type?: MaterialDimensionMaterialType | null
}

export interface MaterialTypePatternConflictInfo {
  has_exact_duplicate: boolean
  exact_duplicate: { id: number; name: string } | null
  priority_conflicts: Array<{ id: number; name: string; material_type: MaterialDimensionMaterialType }>
}

export interface MaterialTypePatternPreviewResponse {
  preview_result: {
    matched: boolean
    material_type: MaterialDimensionMaterialType
    unit: string
    target_field: MaterialTypePatternTargetField
    expression: string
    matched_value: string | null
    haystack: string
    normalized_title: string
  }
  conflicts: MaterialTypePatternConflictInfo
  test_title: string
  test_url: string | null
}

export const adminMaterialDimensionsApi = {
  async listRules(params: MaterialDimensionRulesListParams = {}): Promise<MaterialDimensionRuleListResponse> {
    const { data } = await api.get('/api/admin/material-dimension-rules', { params })
    return data
  },

  async getRule(id: number): Promise<MaterialDimensionRule> {
    const { data } = await api.get(`/api/admin/material-dimension-rules/${id}`)
    return data
  },

  async createRule(payload: UpsertMaterialDimensionRulePayload): Promise<MaterialDimensionRule> {
    const { data } = await api.post('/api/admin/material-dimension-rules', payload)
    return data
  },

  async previewRule(
    payload: UpsertMaterialDimensionRulePayload,
    testText: string,
  ): Promise<MaterialDimensionRulePreviewResponse> {
    const { data } = await api.post('/api/admin/material-dimension-rules/preview', {
      ...payload,
      test_text: testText,
    })
    return data
  },

  async updateRule(id: number, payload: UpsertMaterialDimensionRulePayload): Promise<MaterialDimensionRule> {
    const { data } = await api.put(`/api/admin/material-dimension-rules/${id}`, payload)
    return data
  },

  async deleteRule(id: number): Promise<void> {
    await api.delete(`/api/admin/material-dimension-rules/${id}`)
  },

  async listFailures(params: MaterialDimensionFailuresListParams = {}): Promise<MaterialDimensionParseFailureListResponse> {
    const { data } = await api.get('/api/admin/material-dimension-failures', { params })
    return data
  },

  async getFailure(id: number): Promise<MaterialDimensionParseFailure> {
    const { data } = await api.get(`/api/admin/material-dimension-failures/${id}`)
    return data
  },

  async updateFailure(
    id: number,
    payload: UpdateMaterialDimensionParseFailurePayload,
  ): Promise<MaterialDimensionParseFailure> {
    const { data } = await api.patch(`/api/admin/material-dimension-failures/${id}`, payload)
    return data
  },

  async listTypePatterns(params: MaterialTypePatternsListParams = {}): Promise<MaterialTypePatternListResponse> {
    const { data } = await api.get('/api/admin/material-type-patterns', { params })
    return data
  },

  async getTypePattern(id: number): Promise<MaterialTypePattern> {
    const { data } = await api.get(`/api/admin/material-type-patterns/${id}`)
    return data
  },

  async createTypePattern(payload: UpsertMaterialTypePatternPayload): Promise<MaterialTypePattern> {
    const { data } = await api.post('/api/admin/material-type-patterns', payload)
    return data
  },

  async updateTypePattern(id: number, payload: UpsertMaterialTypePatternPayload): Promise<MaterialTypePattern> {
    const { data } = await api.put(`/api/admin/material-type-patterns/${id}`, payload)
    return data
  },

  async deleteTypePattern(id: number): Promise<void> {
    await api.delete(`/api/admin/material-type-patterns/${id}`)
  },

  async previewTypePattern(
    payload: UpsertMaterialTypePatternPayload,
    testTitle: string,
    testUrl?: string,
  ): Promise<MaterialTypePatternPreviewResponse> {
    const { data } = await api.post('/api/admin/material-type-patterns/preview', {
      ...payload,
      test_title: testTitle,
      test_url: testUrl,
    })
    return data
  },
}
