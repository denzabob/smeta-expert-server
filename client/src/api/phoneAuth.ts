import api, { ensureCsrfCookie } from '@/api/axios'

export interface RequestCodePayload {
  phone: string
}

export interface RequestCallPayload {
  phone: string
}

export interface RequestCallResponse {
  verification_id: string
  challenge_id: string
  status: 'pending'
  phone_masked: string
  call_phone?: string | null
  call_phone_pretty?: string | null
  expires_at: string
  ttl_seconds: number
}

export interface CallStatusPayload {
  verification_id: string
}

export interface CallStatusResponse {
  verification_id: string
  status: 'pending' | 'verified' | 'expired' | 'failed'
  call_phone?: string | null
  call_phone_pretty?: string | null
  expires_at: string
  ttl_seconds: number
  message?: string
  auth?: VerifyCodeResponse
}

export interface RequestCodeResponse {
  challenge_id: string
  channel: string
  verification_method: 'code' | 'call'
  call_phone?: string | null
  call_phone_pretty?: string | null
  phone_masked: string
  resend_available_at: string
  expires_at: string
}

export interface ResendCodePayload {
  challenge_id: string
}

export interface ResendCodeResponse {
  channel: string
  verification_method: 'code' | 'call'
  call_phone?: string | null
  call_phone_pretty?: string | null
  resend_available_at: string
}

export interface VerifyCodePayload {
  challenge_id: string
  code?: string
}

export interface VerifyCodeResponse {
  status: 'authenticated' | 'needs_onboarding'
  user?: {
    id: number
    full_name: string | null
    email: string | null
    phone: string
    activity_profile: string | null
  }
  need_profile_completion?: boolean
  should_offer_pin_enable?: boolean
  should_offer_pin_setup?: boolean
}

export interface CompleteRegistrationPayload {
  full_name: string
  email: string
  activity_profile: string
  accept_terms: boolean
  accept_privacy: boolean
}

export interface CompleteRegistrationResponse {
  user: {
    id: number
    full_name: string
    email: string
    phone: string
    activity_profile: string
  }
  should_offer_pin_enable: boolean
  should_offer_pin_setup: boolean
}

export const phoneAuthApi = {
  /**
   * Запросить challenge для входа/регистрации через звонок
   * POST /api/auth/phone/call/request
   */
  async requestCallChallenge(payload: RequestCallPayload): Promise<RequestCallResponse> {
    await ensureCsrfCookie()
    const { data } = await api.post('/api/auth/phone/call/request', payload)
    return data
  },

  /**
   * Проверить статус call challenge
   * POST /api/auth/phone/call/status
   */
  async getCallStatus(payload: CallStatusPayload): Promise<CallStatusResponse> {
    const { data } = await api.post('/api/auth/phone/call/status', payload)
    return data
  },

  /**
   * Запросить код подтверждения на телефон
   * POST /api/auth/phone/request-code
   */
  async requestCode(payload: RequestCodePayload): Promise<RequestCodeResponse> {
    await ensureCsrfCookie()
    const { data } = await api.post('/api/auth/phone/request-code', payload)
    return data
  },

  /**
   * Повторно отправить код
   * POST /api/auth/phone/resend-code
   */
  async resendCode(payload: ResendCodePayload): Promise<ResendCodeResponse> {
    const { data } = await api.post('/api/auth/phone/resend-code', payload)
    return data
  },

  /**
   * Подтвердить код
   * POST /api/auth/phone/verify-code
   */
  async verifyCode(payload: VerifyCodePayload): Promise<VerifyCodeResponse> {
    const { data } = await api.post('/api/auth/phone/verify-code', payload)
    return data
  },

  /**
   * Завершить регистрацию (onboarding)
   * POST /api/register/complete
   */
  async completeRegistration(payload: CompleteRegistrationPayload): Promise<CompleteRegistrationResponse> {
    const { data } = await api.post('/api/register/complete', payload)
    return data
  },

  /**
   * Получить URL для Yandex OAuth
   * GET /api/auth/yandex/redirect
   */
  async getYandexRedirectUrl(): Promise<{ redirect_url: string }> {
    const { data } = await api.get('/api/auth/yandex/redirect')
    return data
  },

  /**
   * Получить URL для VK ID OAuth
   * GET /api/auth/vk/redirect
   */
  async getVkRedirectUrl(): Promise<{ redirect_url: string }> {
    const { data } = await api.get('/api/auth/vk/redirect')
    return data
  },
}
