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

export interface LinkedProvider {
  provider: string
  label: string
  provider_user_id: string
  provider_username?: string | null
  provider_email?: string | null
  provider_phone?: string | null
  linked_at?: string | null
  last_used_at?: string | null
  can_unlink: boolean
}

export interface AuthMethodsResponse {
  password: {
    enabled: boolean
  }
  phone: {
    value: string | null
    masked: string | null
    verified: boolean
  }
  email: {
    value: string | null
    verified: boolean
  }
  linked_providers: LinkedProvider[]
  supported_providers: Array<{
    provider: string
    label: string
    configured: boolean
    linked: boolean
  }>
  login_methods_count: number
}

export interface AuthMethodChallengeResponse {
  challenge_id: string
  channel: string
  verification_method: 'code' | 'call'
  call_phone?: string | null
  call_phone_pretty?: string | null
  phone_masked: string
  resend_available_at: string
  expires_at: string
}

export interface AuthMethodResendResponse {
  channel: string
  verification_method: 'code' | 'call'
  call_phone?: string | null
  call_phone_pretty?: string | null
  resend_available_at: string
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

  /**
   * Получить методы входа и связанные провайдеры
   * GET /api/auth/methods
   */
  async getAuthMethods(): Promise<AuthMethodsResponse> {
    const { data } = await api.get('/api/auth/methods')
    return data
  },

  /**
   * Получить redirect URL для привязки OAuth провайдера
   * GET /api/auth/methods/providers/{provider}/redirect
   */
  async getProviderLinkRedirect(provider: string): Promise<{ redirect_url: string }> {
    const { data } = await api.get(`/api/auth/methods/providers/${provider}/redirect`)
    return data
  },

  /**
   * Отвязать OAuth провайдер от аккаунта
   * POST /api/auth/methods/providers/{provider}/unlink
   */
  async unlinkProvider(provider: string): Promise<{ message: string; login_methods_count: number }> {
    const { data } = await api.post(`/api/auth/methods/providers/${provider}/unlink`)
    return data
  },

  /**
   * Запросить смену телефона с подтверждением
   * POST /api/auth/methods/phone/request-change
   */
  async requestPhoneChange(payload: {
    phone: string
    current_password?: string
  }): Promise<AuthMethodChallengeResponse> {
    const { data } = await api.post('/api/auth/methods/phone/request-change', payload)
    return data
  },

  /**
   * Повторно отправить подтверждение смены телефона
   * POST /api/auth/methods/phone/resend-change
   */
  async resendPhoneChange(challenge_id: string): Promise<AuthMethodResendResponse> {
    const { data } = await api.post('/api/auth/methods/phone/resend-change', { challenge_id })
    return data
  },

  /**
   * Подтвердить смену телефона
   * POST /api/auth/methods/phone/confirm-change
   */
  async confirmPhoneChange(payload: {
    challenge_id: string
    code?: string
  }): Promise<{ message: string; phone: string; phone_masked: string }> {
    const { data } = await api.post('/api/auth/methods/phone/confirm-change', payload)
    return data
  },

  /**
   * Изменить email текущего аккаунта
   * POST /api/auth/methods/email/change
   */
  async changeEmail(payload: {
    email: string
    current_password?: string
  }): Promise<{ message: string; email: string; email_verified: boolean }> {
    const { data } = await api.post('/api/auth/methods/email/change', payload)
    return data
  },

  /**
   * Повторно отправить письмо для подтверждения email
   * POST /api/email/verification-notification
   */
  async resendEmailVerification(): Promise<{ message: string }> {
    const { data } = await api.post('/api/email/verification-notification')
    return data
  },
}
