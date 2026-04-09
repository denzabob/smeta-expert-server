import { describe, expect, it } from 'vitest'
import {
  recoveryLevel,
  recoveryMethodLabel,
  recommendedActionMeta,
  prerequisiteLabel,
  blockedActionPrerequisiteLabel,
} from './securityHelpers'
import type { AuthMethodProfile } from '@/api/security'

// ── Minimal status factory ────────────────────────────────────────────────────

function makeStatus(
  overrides: Partial<Pick<AuthMethodProfile, 'can_self_recover' | 'recovery_methods' | 'blocked_actions' | 'prerequisite_actions'>>,
): Pick<AuthMethodProfile, 'can_self_recover' | 'recovery_methods' | 'blocked_actions' | 'prerequisite_actions'> {
  return {
    can_self_recover: true,
    recovery_methods: [],
    blocked_actions: [],
    prerequisite_actions: {},
    ...overrides,
  }
}

// ── recoveryLevel ─────────────────────────────────────────────────────────────

describe('recoveryLevel', () => {
  it('returns critical when can_self_recover is false', () => {
    expect(recoveryLevel(makeStatus({ can_self_recover: false }))).toBe('critical')
  })

  it('returns critical even with recovery_methods when can_self_recover is false', () => {
    expect(
      recoveryLevel(makeStatus({ can_self_recover: false, recovery_methods: ['phone_otp', 'password_reset'] })),
    ).toBe('critical')
  })

  it('returns weak when can_self_recover and exactly one recovery method', () => {
    expect(
      recoveryLevel(makeStatus({ can_self_recover: true, recovery_methods: ['phone_otp'] })),
    ).toBe('weak')
  })

  it('returns strong when can_self_recover and two or more recovery methods', () => {
    expect(
      recoveryLevel(makeStatus({ can_self_recover: true, recovery_methods: ['phone_otp', 'password_reset'] })),
    ).toBe('strong')

    expect(
      recoveryLevel(
        makeStatus({ can_self_recover: true, recovery_methods: ['phone_otp', 'password_reset', 'yandex_oauth'] }),
      ),
    ).toBe('strong')
  })
})

// ── recoveryMethodLabel ───────────────────────────────────────────────────────

describe('recoveryMethodLabel', () => {
  it('returns Russian label for phone_otp', () => {
    expect(recoveryMethodLabel('phone_otp')).toContain('телефон')
  })

  it('returns Russian label for password_reset', () => {
    expect(recoveryMethodLabel('password_reset')).toContain('Сброс')
  })

  it('returns Russian label for yandex_oauth', () => {
    expect(recoveryMethodLabel('yandex_oauth')).toContain('Яндекс')
  })

  it('returns raw value for unknown key', () => {
    expect(recoveryMethodLabel('unknown_method')).toBe('unknown_method')
  })
})

// ── recommendedActionMeta ─────────────────────────────────────────────────────

describe('recommendedActionMeta', () => {
  it('returns meta for set_password', () => {
    const meta = recommendedActionMeta('set_password')
    expect(meta.label).toContain('пароль')
    expect(meta.buttonLabel).toContain('Установить')
    expect(meta.color).toBe('primary')
  })

  it('returns meta for verify_email with warning color', () => {
    const meta = recommendedActionMeta('verify_email')
    expect(meta.color).toBe('warning')
    expect(meta.label).toContain('почту')
  })

  it('returns meta for bootstrap_add_phone', () => {
    const meta = recommendedActionMeta('bootstrap_add_phone')
    expect(meta.label).toContain('телефон')
    expect(meta.buttonLabel).toContain('Добавить')
  })

  it('falls back gracefully for unknown actions', () => {
    const meta = recommendedActionMeta('totally_unknown')
    expect(meta.label).toBe('totally_unknown')
    expect(meta.buttonLabel).toBeDefined()
  })
})

// ── prerequisiteLabel ─────────────────────────────────────────────────────────

describe('prerequisiteLabel', () => {
  it('returns label for bootstrap_add_phone', () => {
    expect(prerequisiteLabel('bootstrap_add_phone')).toContain('телефон')
  })

  it('returns label for verify_phone', () => {
    expect(prerequisiteLabel('verify_phone')).toContain('телефон')
  })

  it('returns fallback for unknown prerequisite', () => {
    expect(prerequisiteLabel('some_unknown')).toContain('шаг')
  })
})

// ── blockedActionPrerequisiteLabel ────────────────────────────────────────────

describe('blockedActionPrerequisiteLabel', () => {
  it('resolves prerequisite from map and returns label', () => {
    const prerequisiteActions = { set_password: 'bootstrap_add_phone' }
    const label = blockedActionPrerequisiteLabel('set_password', prerequisiteActions)
    expect(label).toContain('телефон')
  })

  it('returns fallback when blocked action has no prerequisite in map', () => {
    const label = blockedActionPrerequisiteLabel('set_password', {})
    expect(label).toContain('шаг')
  })

  it('returns fallback when prerequisite value is unknown', () => {
    const label = blockedActionPrerequisiteLabel('set_password', { set_password: 'nonexistent' })
    expect(label).toContain('шаг')
  })
})
