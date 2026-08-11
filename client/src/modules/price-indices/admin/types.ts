export type SourceFileStatus = 'pending_review' | 'approved' | 'active' | 'rejected' | 'superseded'
export type ValidationStatus = 'pending' | 'passed' | 'failed'
export type PreviewStatus = 'pending' | 'running' | 'ready' | 'failed' | 'expired'
export type ImportStatus =
  | 'pending'
  | 'importing'
  | 'validating'
  | 'ready_for_publish'
  | 'published'
  | 'superseded'
  | 'failed'

export interface PaginationMeta {
  current_page: number
  from: number | null
  last_page: number
  per_page: number
  to: number | null
  total: number
}

export interface PaginatedResponse<T> {
  data: T[]
  meta: PaginationMeta
  links?: Record<string, string | null>
}

export interface ResourceResponse<T> {
  data: T
  meta?: Record<string, unknown>
}

export interface StatisticalDataset {
  public_id: string
  code: string
  name: string
  description: string | null
  provider_code: string | null
  provider_name: string | null
  data_kind: string
  frequency: string
  classifier_code: string
  territory_scope: string
  is_enabled: boolean
  sources_count?: number
  source_files_count?: number
  created_at: string | null
  updated_at: string | null
}

export interface StatisticalSource {
  public_id: string
  dataset: Pick<StatisticalDataset, 'public_id' | 'code' | 'name'>
  code: string
  name: string
  source_page_url: string | null
  is_enabled: boolean
  source_files_count?: number
  created_at: string | null
}

export interface StatisticalSourceFile {
  public_id: string
  dataset: Pick<StatisticalDataset, 'public_id' | 'code' | 'name'>
  source: Pick<StatisticalSource, 'public_id' | 'code' | 'name'> | null
  acquisition_method: string
  reporting_year: number | null
  reporting_month: number | null
  original_filename: string
  source_url: string | null
  mime_type: string | null
  file_size: number
  sha256: string
  detected_at: string | null
  status: SourceFileStatus
  validation_status: ValidationStatus
  validation_summary: Record<string, unknown> | null
  rejection_reason?: string
  reviewed_at: string | null
  activated_at: string | null
  active: boolean
  supersedes_public_id: string | null
  metadata_json: Record<string, unknown> | null
  created_at: string | null
  updated_at: string | null
}

export interface PreviewCounters {
  sheets_total: number | null
  supported_sheets: number | null
  ignored_sheets: number | null
  commodity_occurrences: number | null
  unique_classifier_items: number | null
  observation_candidates: number | null
  numeric_count: number | null
  missing_count: number | null
  footnoted_count: number | null
  warnings_count: number | null
  fatal_errors_count: number | null
}

export interface StatisticalImportPreview {
  public_id: string
  status: PreviewStatus
  dataset?: Pick<StatisticalDataset, 'public_id' | 'code' | 'name'>
  source_file: { public_id: string; original_filename: string; sha256: string }
  importer: { code: string; version: string }
  timestamps: TaskTimestamps & { expires_at: string | null }
  counters: PreviewCounters
  progress?: { current_sheet: string | null; current_row: number | null; percent: number | null }
  failure?: { code: string | null; message: string | null }
  actions: { can_get_result: boolean; can_retry: boolean }
}

export interface PreviewStartMeta {
  queued: boolean
  cached: boolean
  reused?: boolean
}

export interface PreviewSheet {
  name: string
  year?: number | null
  comparison_basis?: string | null
  topology?: string | null
  month_columns?: Array<{ month?: number; column?: string }> | string[]
  reason?: string | null
  [key: string]: unknown
}

export interface PreviewSample {
  item_code: string
  item_name?: string | null
  period_start?: string | null
  value?: string | number | null
  sheet_name?: string | null
  source_cell_address?: string | null
  code_kind?: string
  [key: string]: unknown
}

export interface StatisticalImportPreviewResult {
  source_file: {
    public_id: string
    original_filename: string
    sha256: string
    reporting_period: { year: number | null; month: number | null }
    status: SourceFileStatus
  }
  importer: { code: string; version: string }
  workbook: {
    sheets_total: number
    supported_sheets: PreviewSheet[]
    ignored_sheets: PreviewSheet[]
    detected_years: number[]
    detected_comparison_bases: string[]
    detected_topologies: string[]
  }
  counts: {
    commodity_occurrences: number
    unique_classifier_items: number
    observation_candidates: number
    numeric: number
    missing: number
    footnoted: number
    warnings: number
    fatal_errors: number
  }
  samples: PreviewSample[]
}

export interface TaskTimestamps {
  created_at: string | null
  started_at: string | null
  finished_at: string | null
  failed_at: string | null
}

export interface StatisticalImport {
  public_id: string
  status: ImportStatus
  dataset: Pick<StatisticalDataset, 'public_id' | 'code' | 'name'>
  source_file: { public_id: string; original_filename: string; sha256: string }
  importer: { code: string; version: string }
  attempt_no: number
  timestamps: TaskTimestamps & {
    ready_at: string | null
    published_at: string | null
    superseded_at: string | null
  }
  counters: {
    rows_scanned: number
    observations_parsed: number
    observations_valid: number
    observations_rejected: number
    warnings_count: number
    errors_count: number
  }
  progress?: { current_sheet: string | null; current_row: number | null; percent: number | null }
  failure?: { code: string | null; message: string | null }
  publication: { is_current: boolean; supersedes_public_id?: string | null }
  actions: { can_publish: boolean; can_retry: boolean; can_view_observations: boolean }
}

export interface StatisticalImportIssue {
  public_id: string
  severity: 'warning' | 'error' | 'fatal'
  code: string
  message: string
  sheet_name: string | null
  source_row: number | null
  source_column: string | null
  classifier_item_code: string | null
  details: Record<string, unknown> | null
  created_at: string | null
}

export interface StatisticalObservation {
  public_id: string
  series: {
    public_id: string
    item_code: string
    item_name: string
    territory_code: string
    territory_name: string
    indicator_code: string
    indicator_name: string
    frequency: string
    comparison_basis: string
    unit: string
  }
  period_start: string
  value: string | null
  missing_reason: string | null
  provenance: {
    source_file_public_id: string
    sheet_name: string
    source_row: number
    source_column: string
    source_cell_address: string | null
    source_value_raw: string | null
    footnote_marker: string | null
  }
}

export interface ClassifierItemAdmin {
  public_id: string
  classifier_code: string
  item_code: string
  item_name: string
  provider_code_kind: 'numeric' | 'rosstat_local_ag' | string
}

export interface SeriesPeriodSummary {
  from: string
  to: string
  observations_count: number
}

export interface StatisticalSeriesAdmin {
  public_id: string
  classifier_item: ClassifierItemAdmin
  indicator: { code: string; name: string }
  territory: { code: string; name: string }
  frequency: string
  comparison_basis: string
  unit: string
  period: SeriesPeriodSummary
}

export interface ImportSeriesListParams {
  item_code?: string
  item_code_prefix?: string
  item_name?: string
  page?: number
  per_page?: number
  sort?: 'item_code' | 'item_name'
  direction?: 'asc' | 'desc'
}

export interface ImportObservationListParams {
  series_public_id: string
  period_from?: string
  period_to?: string
  missing?: boolean
  sheet_name?: string
  page?: number
  per_page?: number
  sort?: 'period_start' | 'item_code' | 'created_at'
  direction?: 'asc' | 'desc'
}

export interface ContinuityDiagnostic {
  isContinuous: boolean
  expectedCount: number
  actualCount: number
  missingPeriods: string[]
  nullPeriods: string[]
  duplicatePeriods: string[]
}

export interface DataExplorerState {
  datasetPublicId: string
  importPublicId: string
  searchQuery: string
  selectedSeriesPublicId: string
  periodFrom: string
  periodTo: string
}

export interface UploadSourceFilePayload {
  datasetPublicId: string
  sourcePublicId?: string | null
  reportingYear?: number | null
  reportingMonth?: number | null
  sourceUrl?: string | null
  comment?: string | null
  file: File
}

export interface SourceFileListParams {
  dataset?: string
  source?: string
  status?: SourceFileStatus
  page?: number
  per_page?: number
  sort?: 'detected_at' | 'created_at' | 'reporting_year' | 'reporting_month' | 'file_size'
  direction?: 'asc' | 'desc'
}

export interface ImportListParams {
  dataset_public_id?: string
  status?: ImportStatus
  importer_code?: string
  importer_version?: string
  created_from?: string
  created_to?: string
  page?: number
  per_page?: number
  sort?: 'created_at' | 'started_at' | 'finished_at' | 'published_at' | 'status'
  direction?: 'asc' | 'desc'
}
