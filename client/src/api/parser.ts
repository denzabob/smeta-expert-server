/**
 * Parser API Client
 * Professional-grade API client for Parser Management System
 */

import type { AxiosInstance } from 'axios'
import api from './axios'  // Импортируем основной axios с withCredentials

interface ParsingSession {
  id: number
  supplier: string
  status: 'pending' | 'running' | 'completed' | 'failed' | 'stopped' | 'canceling'
  config: Record<string, any> | null
  started_at: string | null
  completed_at: string | null
  pid: number | null
  last_heartbeat_at: string | null
  error_message: string | null
  exit_code: number | null
  total_urls: number
  processed_count: number
  success_count: number
  error_count: number
  screenshots_taken: number
  created_at: string
  updated_at: string
}

interface ParsingLog {
  id: number
  parsing_session_id: number
  level: 'debug' | 'info' | 'warning' | 'error' | 'critical'
  message: string
  context: Record<string, any> | null
  created_at: string
}

interface SystemStatus {
  scheduler_running: boolean
  active_sessions: number
  total_sessions_24h: number
  failed_sessions_24h: number
  health_score: number
  last_check: string
}

interface SupplierHealth {
  supplier: string
  active: boolean
  health_score: number
  last_sync: string | null
  current_pid: number | null
  sessions_count_24h: number
  success_rate_24h: number
}

interface SupplierConfigResponse {
  supplier: string
  config: Record<string, any>
  source?: 'db' | 'file'
}

interface CollectProfile {
  id: number
  supplier_name: string
  name: string
  config_override: Record<string, any>
  is_default: boolean
}

interface SessionStats {
  avg_duration: number
  avg_speed: number // items per second
  total_processed: number
  total_errors: number
  success_rate: number
}

interface ChartDataPoint {
  date: string
  processed: number
  errors: number
}

// Интерфейсы для очереди URL
interface QueueStats {
  pending: number
  processing: number
  done: number
  failed: number
  blocked: number
  total?: number
}

interface UrlQueueItem {
  id: number
  url: string
  supplier_id: number
  material_type: string | null
  status: 'pending' | 'processing' | 'done' | 'failed' | 'blocked'
  attempts: number
  locked_by: string | null
  locked_at: string | null
  last_attempt_at: string | null
  last_parsed_at: string | null
  next_retry_at: string | null
  last_error_code: string | null
  last_error_message: string | null
  created_at: string
  updated_at: string
}

class ParserApiClient {
  private api: AxiosInstance

  constructor(baseURL: string = import.meta.env.VITE_API_URL || '/api') {
    // Используем основной api instance с правильными настройками (withCredentials, cookies)
    this.api = api
  }

  // ==================== System Status ====================

  async getSystemStatus(): Promise<SystemStatus> {
    const { data } = await this.api.get('/api/system/parser/status')
    return data
  }

  // ==================== Suppliers ====================

  async getSuppliersHealth(): Promise<SupplierHealth[]> {
    const { data } = await this.api.get('/api/parsing/suppliers/health')
    return data.suppliers || []
  }

  async collectSupplierUrls(supplier: string): Promise<{ message: string; supplier: string }> {
    const { data } = await this.api.post(`/api/parsing/collect-urls/${supplier}`)
    return data
  }

  async collectSupplierUrlsWithProfile(supplier: string, profileId?: number): Promise<{ message: string; supplier: string } & Record<string, any>> {
    const payload = profileId ? { profile_id: profileId } : {}
    const { data } = await this.api.post(`/api/parsing/collect-urls/${supplier}`, payload)
    return data
  }

  async getCollectProfiles(supplier: string): Promise<CollectProfile[]> {
    const { data } = await this.api.get(`/api/parsing/suppliers/${supplier}/collect-profiles`)
    return data.profiles || []
  }

  async createCollectProfile(supplier: string, payload: { name: string; config_override: Record<string, any>; is_default?: boolean }): Promise<CollectProfile> {
    const { data } = await this.api.post(`/api/parsing/suppliers/${supplier}/collect-profiles`, payload)
    return data.profile
  }

  async updateCollectProfile(supplier: string, profileId: number, payload: Partial<{ name: string; config_override: Record<string, any>; is_default: boolean }>): Promise<CollectProfile> {
    const { data } = await this.api.put(`/api/parsing/suppliers/${supplier}/collect-profiles/${profileId}`, payload)
    return data.profile
  }

  async deleteCollectProfile(supplier: string, profileId: number): Promise<void> {
    await this.api.delete(`/api/parsing/suppliers/${supplier}/collect-profiles/${profileId}`)
  }

  async getSupplierConfig(supplier: string): Promise<SupplierConfigResponse> {
    const { data } = await this.api.get(`/api/parsing/suppliers/${supplier}/config`)
    return data
  }

  async updateSupplierConfig(supplier: string, config: Record<string, any>): Promise<SupplierConfigResponse> {
    const { data } = await this.api.put(`/api/parsing/suppliers/${supplier}/config`, { config })
    return data
  }

  // ==================== Sessions ====================

  async getSessions(params?: {
    supplier?: string
    status?: string
    from?: string
    to?: string
    page?: number
    per_page?: number
  }): Promise<{ data: ParsingSession[], meta: any }> {
    const { data } = await this.api.get('/api/parsing/sessions', { params })
    return data
  }

  async getSession(id: number): Promise<ParsingSession> {
    const { data } = await this.api.get(`/api/parsing/sessions/${id}`)
    return data.data
  }

  async createSession(supplier: string, config?: Record<string, any>): Promise<ParsingSession> {
    const { data } = await this.api.post('/api/parsing/sessions', {
      supplier,
      config: config || {}
    })
    return data.session
  }

  async stopSession(id: number): Promise<void> {
    await this.api.post(`/api/parsing/sessions/${id}/stop`)
  }

  // ==================== Logs ====================

  async getSessionLogs(
    sessionId: number,
    params?: {
      level?: string
      page?: number
      per_page?: number
    }
  ): Promise<{ data: ParsingLog[], meta: any }> {
    const { data } = await this.api.get(`/api/parsing/sessions/${sessionId}/logs`, { params })
    return data
  }

  // ==================== Analytics ====================

  async getSessionStats(sessionId: number): Promise<SessionStats> {
    const { data } = await this.api.get(`/api/parsing/sessions/${sessionId}/stats`)
    return data
  }

  async getChartData(params: {
    supplier?: string
    from: string
    to: string
  }): Promise<ChartDataPoint[]> {
    const { data } = await this.api.get('/api/parsing/analytics/chart', { params })
    return data
  }

  // ==================== Settings ====================

  async getSettings(): Promise<Record<string, any>> {
    const { data } = await this.api.get('/api/parsing/settings')
    return data
  }

  async updateSettings(settings: Record<string, any>): Promise<void> {
    await this.api.put('/api/parsing/settings', settings)
  }

  async regenerateToken(): Promise<{ token: string }> {
    const { data } = await this.api.post('/api/parsing/settings/regenerate-token')
    return data
  }

  async getAllowedIPs(): Promise<string[]> {
    const { data } = await this.api.get('/api/parsing/settings/allowed-ips')
    return data
  }

  async updateAllowedIPs(ips: string[]): Promise<void> {
    await this.api.put('/api/parsing/settings/allowed-ips', { ips })
  }

  // ==================== URL Queue (ЭТАП 3) ====================

  async getQueueStats(supplierCode?: string): Promise<QueueStats> {
    const params = supplierCode ? { supplier_code: supplierCode } : {}
    const { data } = await this.api.get('/api/parser/urls/stats', { params })
    return data.stats
  }

  async getQueueUrls(params?: {
    supplier_code?: string
    status?: string
    material_type?: string
    page?: number
    per_page?: number
  }): Promise<{ data: UrlQueueItem[], meta: any }> {
    const { data } = await this.api.get('/api/parser/urls', { params })
    return data
  }

  async resetStaleUrls(): Promise<{ reset_count: number }> {
    const { data } = await this.api.post('/api/parser/urls/reset-stale')
    return data
  }

  async resetFailedUrls(supplierCode?: string): Promise<{ reset_count: number }> {
    const params = supplierCode ? { supplier_code: supplierCode } : {}
    const { data } = await this.api.post('/api/parser/urls/reset-failed', params)
    return data
  }
}

export const parserApi = new ParserApiClient()

export type {
  ParsingSession,
  ParsingLog,
  SystemStatus,
  SupplierHealth,
  SessionStats,
  ChartDataPoint,
  QueueStats,
  UrlQueueItem
}
