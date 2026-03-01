import api, { ensureCsrfCookie } from '@/api/axios'

export interface PinStatus {
  pin_enabled: boolean
  trusted_device_present: boolean
  requires_password_login: boolean
  user_name: string | null
  user_email: string | null
}

export interface TrustedDevice {
  id: number
  device_label: string
  ip_last: string | null
  last_used_at: string | null
  created_at: string | null
  is_current: boolean
}

export const pinApi = {
  /**
   * Проверить статус PIN (публичный, без auth)
   */
  async getStatus(): Promise<PinStatus> {
    const { data } = await api.get('/api/auth/pin/status')
    return data
  },

  /**
   * Вход по PIN (публичный, требует cookie tdid/tds)
   */
  async loginByPin(pin: string) {
    await ensureCsrfCookie()
    const { data } = await api.post('/api/auth/pin/login', { pin })
    return data
  },

  /**
   * Установить / изменить PIN (требует auth + пароль)
   */
  async setPin(payload: {
    pin: string
    pin_confirm: string
    password: string
    trust_device?: boolean
  }) {
    const { data } = await api.post('/api/auth/pin/set', payload)
    return data
  },

  /**
   * Отключить PIN (требует auth + пароль)
   */
  async disablePin(password: string) {
    const { data } = await api.post('/api/auth/pin/disable', { password })
    return data
  },

  /**
   * Список доверенных устройств
   */
  async getTrustedDevices(): Promise<TrustedDevice[]> {
    const { data } = await api.get('/api/auth/trusted-devices')
    return data
  },

  /**
   * Отозвать доверенное устройство
   */
  async revokeDevice(id: number) {
    const { data } = await api.post(`/api/auth/trusted-devices/${id}/revoke`)
    return data
  },

  /**
   * «Сменить аккаунт» — забыть устройство (публичный)
   */
  async forgetDevice() {
    const { data } = await api.post('/api/auth/trusted-device/forget')
    return data
  },

  /**
   * Завершить все другие сеансы
   */
  async terminateSessions() {
    const { data } = await api.post('/api/auth/terminate-sessions')
    return data
  },
}
