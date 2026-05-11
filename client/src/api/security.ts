// src/api/security.ts
// Block 6 — API layer for all /api/security/* endpoints (Blocks 4 + 5)
import api from '@/api/axios'

// ── Auth-status profile ───────────────────────────────────────────────────────

export interface AuthMethodProfile {
  phone: {
    linked: boolean
    verified: boolean
    masked: string | null
  }
  email: {
    linked: boolean
    verified: boolean
    masked: string | null
  }
  password: { set: boolean }
  yandex: { linked: boolean }
  vk: { linked: boolean }
  quick_pin: { enabled: boolean }
  trusted_devices: { count: number }
  recommended_actions: string[]
  completion: {
    needs_email: boolean
    needs_email_verification: boolean
    needs_password_setup: boolean
    can_enable_quick_pin: boolean
  }
  can_self_recover: boolean
  recovery_methods: string[]
  can_manage_sessions: boolean
  can_manage_trusted_devices: boolean
  blocked_actions: string[]
  prerequisite_actions: Record<string, string>
}

// ── Step-up ───────────────────────────────────────────────────────────────────

export interface StepUpInitiateResponse {
  challenge_id: string
  scope: string
  allowed_methods: string[]
  expires_at: string
  phone_masked: string | null
  email_masked: string | null
}

export interface StepUpTokenResponse {
  step_up_token: string
  scope: string
  expires_at: string
}

export interface PhoneOtpRequestResponse {
  phone_challenge_id: string
  phone_masked: string
  resend_available_at: string
  expires_at: string
}

export interface EmailOtpRequestResponse {
  email_challenge_id: string
  email_masked: string
  resend_available_at: string
  expires_at: string
}

// ── Sessions ──────────────────────────────────────────────────────────────────

export interface SecuritySession {
  id: string
  current: boolean
  created_at: string | null
  last_active_at: string | null
  ip: string | null
  device: string | null
}

// ── Trusted devices ───────────────────────────────────────────────────────────

export interface SecurityDevice {
  id: number
  device_label: string | null
  ip_last: string | null
  last_used_at: string | null
  created_at: string | null
  is_current: boolean
}

// ── API calls ─────────────────────────────────────────────────────────────────

export const securityApi = {
  // Auth status
  async getAuthStatus(): Promise<AuthMethodProfile> {
    const { data } = await api.get('/api/security/auth-status')
    return data
  },

  // Step-up: initiate
  async stepUpInitiate(scope: string): Promise<StepUpInitiateResponse> {
    const { data } = await api.post('/api/security/step-up/initiate', { scope })
    return data
  },

  // Step-up: verify by password
  async stepUpVerifyPassword(challengeId: string, password: string): Promise<StepUpTokenResponse> {
    const { data } = await api.post('/api/security/step-up/verify-password', {
      challenge_id: challengeId,
      password,
    })
    return data
  },

  // Step-up: request phone OTP
  async stepUpRequestPhoneOtp(challengeId: string): Promise<PhoneOtpRequestResponse> {
    const { data } = await api.post('/api/security/step-up/request-phone-otp', {
      challenge_id: challengeId,
    })
    return data
  },

  // Step-up: verify phone OTP
  async stepUpVerifyPhoneOtp(
    challengeId: string,
    phoneChallengeId: string,
    code: string,
  ): Promise<StepUpTokenResponse> {
    const { data } = await api.post('/api/security/step-up/verify-phone-otp', {
      challenge_id: challengeId,
      phone_challenge_id: phoneChallengeId,
      code,
    })
    return data
  },

  // Step-up: request email OTP (Block 6A)
  async stepUpRequestEmailOtp(challengeId: string): Promise<EmailOtpRequestResponse> {
    const { data } = await api.post('/api/security/step-up/request-email-otp', {
      challenge_id: challengeId,
    })
    return data
  },

  // Step-up: verify email OTP (Block 6A)
  async stepUpVerifyEmailOtp(
    challengeId: string,
    emailChallengeId: string,
    code: string,
  ): Promise<StepUpTokenResponse> {
    const { data } = await api.post('/api/security/step-up/verify-email-otp', {
      challenge_id: challengeId,
      email_challenge_id: emailChallengeId,
      code,
    })
    return data
  },

  // Set password (requires step-up token with scope 'set_password')
  async setPassword(
    stepUpToken: string,
    password: string,
    passwordConfirmation: string,
  ): Promise<{ message: string }> {
    const { data } = await api.post('/api/security/password/set', {
      step_up_token: stepUpToken,
      password,
      password_confirmation: passwordConfirmation,
    })
    return data
  },

  // Sessions
  async getSessions(): Promise<{ sessions: SecuritySession[] }> {
    const { data } = await api.get('/api/security/sessions')
    return data
  },

  async revokeSession(id: string): Promise<{ message: string }> {
    const { data } = await api.delete(`/api/security/sessions/${id}`)
    return data
  },

  async revokeOtherSessions(): Promise<{ message: string; revoked_count: number }> {
    const { data } = await api.delete('/api/security/sessions/others')
    return data
  },

  // Trusted devices
  async getDevices(): Promise<{ trusted_devices: SecurityDevice[] }> {
    const { data } = await api.get('/api/security/trusted-devices')
    return data
  },

  async revokeDevice(id: number): Promise<{ message: string }> {
    const { data } = await api.delete(`/api/security/trusted-devices/${id}`)
    return data
  },

  async revokeAllDevices(stepUpToken: string): Promise<{ message: string; revoked_count: number }> {
    const { data } = await api.delete('/api/security/trusted-devices', {
      data: { step_up_token: stepUpToken },
    })
    return data
  },

  // Bootstrap: phone link for Yandex-only users
  async bootstrapPhoneInitiate(phone: string): Promise<{ challenge_id: string; phone_masked: string }> {
    const { data } = await api.post('/api/security/bootstrap/phone/initiate', { phone })
    return data
  },

  async bootstrapPhoneVerify(
    challengeId: string,
    code: string,
  ): Promise<{ message: string; recommended_actions: string[] }> {
    const { data } = await api.post('/api/security/bootstrap/phone/verify', {
      challenge_id: challengeId,
      code,
    })
    return data
  },

  // ── Quick PIN ─────────────────────────────────────────────────────────────

  async enablePin(
    stepUpToken: string,
    pin: string,
    pinConfirm: string,
    trustDevice?: boolean,
  ): Promise<{ message: string; pin_enabled: boolean }> {
    const { data } = await api.post('/api/auth/pin/set', {
      step_up_token: stepUpToken,
      pin,
      pin_confirm: pinConfirm,
      ...(trustDevice !== undefined && { trust_device: trustDevice }),
    })
    return data
  },

  async disablePin(stepUpToken: string): Promise<{ message: string; pin_enabled: boolean }> {
    const { data } = await api.post('/api/auth/pin/disable', {
      step_up_token: stepUpToken,
    })
    return data
  },
}
