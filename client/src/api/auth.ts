import api from '@/api/axios'

export interface ChangePasswordPayload {
  current_password: string
  new_password: string
  new_password_confirmation: string
}

export interface SessionInfo {
  id: string
  is_current: boolean
  platform: string
  client: string
  device_name: string
  browser: string
  ip_address: string | null
  last_active_at: string | null
  city: string | null
  country: string | null
}

export interface SessionsResponse {
  current: SessionInfo
  others: SessionInfo[]
}

export interface ChromeTokenResponse {
  token: string
  user: {
    id: number
    name: string
    email: string
  }
}

export interface ChromeTokenStatusResponse {
  has_token: boolean
  token_meta: {
    id: number
    created_at: string | null
    last_used_at: string | null
  } | null
}

export const authApi = {
  /**
   * Сменить пароль (с инвалидацией других сессий и отзывом устройств)
   * POST /api/auth/password/change
   */
  async changePassword(payload: ChangePasswordPayload): Promise<{ message: string }> {
    const { data } = await api.post('/api/auth/password/change', payload)
    return data
  },

  /**
   * Получить список активных сессий пользователя
   * GET /api/auth/sessions
   */
  async getSessions(): Promise<SessionsResponse> {
    const { data } = await api.get('/api/auth/sessions')
    return data
  },

  /**
   * Завершить все сессии кроме текущей
   * POST /api/auth/sessions/terminate-others
   */
  async terminateOtherSessions(): Promise<{ message: string }> {
    const { data } = await api.post('/api/auth/sessions/terminate-others')
    return data
  },

  /**
   * Выпустить токен для Chrome-расширения
   * POST /api/chrome/auth/token
   */
  async issueChromeToken(email: string, password: string): Promise<ChromeTokenResponse> {
    const { data } = await api.post('/api/chrome/auth/token', { email, password })
    return data
  },

  /**
   * Выпустить токен для Chrome-расширения из текущей авторизованной сессии
   * POST /api/chrome/auth/token/session
   */
  async issueChromeTokenFromSession(): Promise<ChromeTokenResponse> {
    const { data } = await api.post('/api/chrome/auth/token/session')
    return data
  },

  /**
   * Получить статус токена Chrome-расширения
   * GET /api/chrome/auth/status
   */
  async getChromeTokenStatus(): Promise<ChromeTokenStatusResponse> {
    const { data } = await api.get('/api/chrome/auth/status')
    return data
  },

  /**
   * Отозвать токен Chrome-расширения
   * POST /api/chrome/auth/revoke
   */
  async revokeChromeToken(): Promise<{ message: string }> {
    const { data } = await api.post('/api/chrome/auth/revoke')
    return data
  },
}
