import api from './axios'

export interface ImportSession {
  id: number
  project_id: number | null
  original_filename: string
  file_type: 'xlsx' | 'xls' | 'csv'
  status: 'uploaded' | 'mapped' | 'imported' | 'failed'
  header_row_index: number
  sheet_index: number
  options: ImportOptions
  result: ImportResult | null
  column_mappings: ColumnMapping[]
  mapping_summary: MappingSummary
  created_at: string
  updated_at: string
}

export interface ImportOptions {
  csv_encoding?: string
  csv_delimiter?: string
  units_length?: 'mm' | 'cm' | 'm'
  default_qty_if_empty?: number
  skip_empty_rows?: boolean
  default_kind?: 'panel' | 'facade'
  default_facade_material_id?: number | null
}

export interface ColumnMapping {
  column_index: number
  field: 'width' | 'length' | 'qty' | 'name' | 'kind' | 'price_item_code' | 'height' | 'ignore' | null
}

export interface MappingSummary {
  has_width: boolean
  has_length: boolean
  has_qty: boolean
  is_valid: boolean
  mappings: ColumnMapping[]
}

export interface ImportResult {
  created_count: number
  skipped_count: number
  errors_count: number
  errors: ImportError[]
  sample_created_ids?: number[]
}

export interface ImportError {
  row: number
  reason: string
}

export interface SheetInfo {
  index: number
  name: string
}

export interface ColumnInfo {
  index: number
  name_guess: string
}

export interface PreviewRow {
  original_index: number
  cells: (string | number | null)[]
}

export interface UploadResponse {
  import_session_id: number
  file_info: {
    original_filename: string
    file_type: string
  }
  meta: {
    sheets: SheetInfo[]
    sheets_count: number
    column_count: number
  }
  preview: {
    columns: ColumnInfo[]
    rows: PreviewRow[]
    header_row_index: number
    sheet_index: number
    total_preview_rows: number
  }
  options: ImportOptions
}

export interface SaveMappingRequest {
  sheet_index?: number
  header_row_index?: number
  options?: ImportOptions
  mapping: ColumnMapping[]
}

export interface ImportPreviewItem {
  row: number
  raw: {
    width: string | number | null
    length: string | number | null
    qty: string | number | null
  }
  parsed: {
    width_mm: number | null
    length_mm: number | null
    qty: number | null
  }
  status: 'ok' | 'error'
  error: string | null
}

export interface ImportPreviewResponse {
  preview: {
    items: ImportPreviewItem[]
    units_length: string
    default_qty: number
  }
  mapping_summary: MappingSummary
  options: ImportOptions
}

export interface RunImportResponse {
  message: string
  result: ImportResult
}

class ProjectImportApiClient {
  /**
   * Upload a file and create an import session
   */
  async upload(
    projectId: number,
    file: File,
    options?: {
      sheet_index?: number
      header_row_index?: number
      csv_encoding?: string
      csv_delimiter?: string
    }
  ): Promise<UploadResponse> {
    const formData = new FormData()
    formData.append('file', file)
    
    if (options?.sheet_index !== undefined) {
      formData.append('sheet_index', String(options.sheet_index))
    }
    if (options?.header_row_index !== undefined) {
      formData.append('header_row_index', String(options.header_row_index))
    }
    if (options?.csv_encoding) {
      formData.append('csv_encoding', options.csv_encoding)
    }
    if (options?.csv_delimiter) {
      formData.append('csv_delimiter', options.csv_delimiter)
    }

    const { data } = await api.post(`/api/projects/${projectId}/imports`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    return data
  }

  /**
   * Get preview data for an import session
   */
  async getPreview(
    sessionId: number,
    options?: {
      sheet_index?: number
      header_row_index?: number
      csv_encoding?: string
      csv_delimiter?: string
    }
  ): Promise<UploadResponse> {
    const params = new URLSearchParams()
    if (options?.sheet_index !== undefined) {
      params.append('sheet_index', String(options.sheet_index))
    }
    if (options?.header_row_index !== undefined) {
      params.append('header_row_index', String(options.header_row_index))
    }
    if (options?.csv_encoding) {
      params.append('csv_encoding', options.csv_encoding)
    }
    if (options?.csv_delimiter) {
      params.append('csv_delimiter', options.csv_delimiter)
    }

    const { data } = await api.get(`/api/imports/${sessionId}/preview?${params.toString()}`)
    return data
  }

  /**
   * Save column mapping for an import session
   */
  async saveMapping(sessionId: number, request: SaveMappingRequest): Promise<{
    message: string
    import_session_id: number
    status: string
    mapping_summary: MappingSummary
    options: ImportOptions
  }> {
    const { data } = await api.post(`/api/imports/${sessionId}/mapping`, request)
    return data
  }

  /**
   * Get import preview (dry run)
   */
  async getImportPreview(sessionId: number): Promise<ImportPreviewResponse> {
    const { data } = await api.get(`/api/imports/${sessionId}/import-preview`)
    return data
  }

  /**
   * Run the import
   */
  async run(projectId: number, sessionId: number, mode: 'append' = 'append'): Promise<RunImportResponse> {
    const { data } = await api.post(`/api/projects/${projectId}/imports/${sessionId}/run`, { mode })
    return data
  }

  /**
   * Get import session details
   */
  async getSession(sessionId: number): Promise<ImportSession> {
    const { data } = await api.get(`/api/imports/${sessionId}`)
    return data
  }

  /**
   * Delete an import session
   */
  async deleteSession(sessionId: number): Promise<void> {
    await api.delete(`/api/imports/${sessionId}`)
  }
}

export default new ProjectImportApiClient()
