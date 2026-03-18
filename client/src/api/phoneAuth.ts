import api, { ensureCsrfCookie } from '@/api/axios'

export interface RequestCodePayload {
  phone: string
}

export interface RequestCodeResponse {
  challenge_id: string
  channel: string
  phone_masked: string
  resend_available_at: string
  expires_at: string
}

export interface ResendCodePayload {
  challenge_id: string
}

export interface ResendCodeResponse {
  channel: string
  resend_available_at: string
}

export interface VerifyCodePayload {
  challenge_id: string
  code: string
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
}
