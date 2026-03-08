import api from '@/api/axios'

export type SystemLogType = 'laravel' | 'frontend'
export type SystemLogLevel = 'ERROR' | 'WARNING' | 'INFO'

export interface SystemLogEntry {
  timestamp: string | null
  level: string
  message: string
  stack_trace: string | null
}

export interface SystemLogFile {
  name: string
  size: number
  modified_at: string | null
  exists: boolean
}

export interface SystemLogsResponse {
  type: SystemLogType
  file: string | null
  lines: number
  level: SystemLogLevel | null
  available_files: SystemLogFile[]
  file_size: number
  updated_at: string | null
  last_entry_at: string | null
  entries: SystemLogEntry[]
  raw_text: string
  error: string | null
}

interface GetSystemLogsParams {
  type: SystemLogType
  file?: string
  lines?: number
  level?: 'error' | 'warning' | 'info'
}

function buildDownloadQuery(params: { type: SystemLogType; file?: string; inline?: boolean }): string {
  const query = new URLSearchParams()
  query.set('type', params.type)
  if (params.file) {
    query.set('file', params.file)
  }
  if (params.inline) {
    query.set('inline', '1')
  }

  return query.toString()
}

export const systemLogsApi = {
  async getLogs(params: GetSystemLogsParams): Promise<SystemLogsResponse> {
    const { data } = await api.get('/api/admin/system/logs', { params })
    return data
  },

  downloadUrl(params: { type: SystemLogType; file?: string; inline?: boolean }): string {
    return `/api/admin/system/logs/download?${buildDownloadQuery(params)}`
  },
}
