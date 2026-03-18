import { describe, expect, it } from 'vitest'
import { buildProviderConnectionRows } from '@/components/settings/providerConnectionRows'
import type { AuthMethodsResponse } from '@/api/auth'

describe('buildProviderConnectionRows', () => {
  it('returns connect action when provider is not linked', () => {
    const payload: AuthMethodsResponse = {
      password: { enabled: true },
      phone: { value: '+79990001122', masked: '+7999***1122', verified: true },
      email: { value: 'test@example.com', verified: true },
      linked_providers: [],
      supported_providers: [
        {
          provider: 'yandex',
          label: 'Яндекс',
          configured: true,
          linked: false,
          connection_status: 'not_connected',
          can_connect: true,
          can_disconnect: false,
          linked_account: null,
        },
      ],
      login_methods_count: 2,
    }

    const rows = buildProviderConnectionRows(payload)
    expect(rows).toHaveLength(1)
    const row = rows[0]
    expect(row).toBeDefined()
    expect(row!.provider).toBe('yandex')
    expect(row!.action).toBe('connect')
    expect(row!.statusText).toContain('не подключ')
  })

  it('returns disconnect action when provider is linked', () => {
    const payload: AuthMethodsResponse = {
      password: { enabled: true },
      phone: { value: '+79990001122', masked: '+7999***1122', verified: true },
      email: { value: 'test@example.com', verified: true },
      linked_providers: [
        {
          provider: 'yandex',
          label: 'Яндекс',
          provider_user_id: 'ya_1',
          provider_username: 'tester',
          provider_email: 'test@example.com',
          provider_phone: null,
          linked_at: null,
          last_used_at: null,
          can_unlink: true,
        },
      ],
      supported_providers: [
        {
          provider: 'yandex',
          label: 'Яндекс',
          configured: true,
          linked: true,
          connection_status: 'connected',
          can_connect: false,
          can_disconnect: true,
          linked_account: {
            provider_user_id: 'ya_1',
            provider_username: 'tester',
            provider_email: 'test@example.com',
            last_used_at: null,
          },
        },
      ],
      login_methods_count: 3,
    }

    const rows = buildProviderConnectionRows(payload)
    expect(rows).toHaveLength(1)
    const row = rows[0]
    expect(row).toBeDefined()
    expect(row!.action).toBe('disconnect')
    expect(row!.canUnlink).toBe(true)
    expect(row!.identityHint).toContain('test@example.com')
  })
})
