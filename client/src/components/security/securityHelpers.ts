// Pure helper functions for security UI components — testable without mounting

import type { AuthMethodProfile } from '@/api/security'

// ── Recovery readiness ────────────────────────────────────────────────────────

export type RecoveryLevel = 'critical' | 'weak' | 'strong'

export function recoveryLevel(status: Pick<AuthMethodProfile, 'can_self_recover' | 'recovery_methods'>): RecoveryLevel {
  if (!status.can_self_recover) return 'critical'
  if (status.recovery_methods.length === 1) return 'weak'
  return 'strong'
}

// ── Recovery method labels ────────────────────────────────────────────────────

const recoveryMethodLabels: Record<string, string> = {
  phone_otp: 'Вход по телефону (SMS-код)',
  password_reset: 'Сброс пароля через почту',
  yandex_oauth: 'Вход через аккаунт Яндекс',
}

export function recoveryMethodLabel(method: string): string {
  return recoveryMethodLabels[method] ?? method
}

// ── Recommended action metadata ───────────────────────────────────────────────

export interface ActionMeta {
  label: string
  description: string
  buttonLabel: string
  icon: string
  color: string
}

const actionMap: Record<string, ActionMeta> = {
  verify_email: {
    label: 'Подтвердить почту',
    description: 'Без подтверждённой почты вы не сможете сбросить пароль.',
    buttonLabel: 'Подтвердить',
    icon: 'mdi-email-check-outline',
    color: 'warning',
  },
  add_email: {
    label: 'Добавить почту',
    description: 'Позволит получать уведомления и сбрасывать пароль через почту.',
    buttonLabel: 'Добавить',
    icon: 'mdi-email-plus-outline',
    color: 'primary',
  },
  add_phone: {
    label: 'Добавить телефон',
    description: 'Для входа по SMS-коду и установки пароля.',
    buttonLabel: 'Добавить',
    icon: 'mdi-cellphone-plus',
    color: 'primary',
  },
  verify_phone: {
    label: 'Подтвердить телефон',
    description: 'Телефон не будет работать для входа, пока не подтверждён.',
    buttonLabel: 'Подтвердить',
    icon: 'mdi-cellphone-check',
    color: 'warning',
  },
  set_password: {
    label: 'Установить пароль',
    description: 'Без пароля вы теряете один из способов восстановления доступа.',
    buttonLabel: 'Установить',
    icon: 'mdi-key-plus',
    color: 'primary',
  },
  enable_quick_pin: {
    label: 'Включить быстрый PIN',
    description: 'Удобный вход без полного пароля на доверенных устройствах.',
    buttonLabel: 'Включить',
    icon: 'mdi-dialpad',
    color: 'primary',
  },
  bootstrap_add_phone: {
    label: 'Добавить телефон через Яндекс',
    description: 'Привяжите телефон Яндекс-аккаунта для восстановления доступа.',
    buttonLabel: 'Добавить',
    icon: 'mdi-cellphone-plus',
    color: 'primary',
  },
}

const fallbackMeta: ActionMeta = {
  label: 'Выполнить действие',
  description: '',
  buttonLabel: 'Выполнить',
  icon: 'mdi-alert-circle-outline',
  color: 'warning',
}

export function recommendedActionMeta(action: string): ActionMeta {
  return actionMap[action] ?? { ...fallbackMeta, label: action }
}

// ── Prerequisite action labels ────────────────────────────────────────────────

const prerequisiteLabels: Record<string, string> = {
  bootstrap_add_phone: 'добавьте телефон через Яндекс-аккаунт',
  verify_phone: 'подтвердите телефон',
  add_phone: 'добавьте телефон',
  add_email: 'добавьте почту',
  verify_email: 'подтвердите почту',
}

export function prerequisiteLabel(prerequisiteAction: string): string {
  return prerequisiteLabels[prerequisiteAction] ?? 'выполните предыдущий шаг'
}

export function blockedActionPrerequisiteLabel(
  blockedAction: string,
  prerequisiteActions: Record<string, string>,
): string {
  const prerequisite = prerequisiteActions[blockedAction]
  return prerequisite ? prerequisiteLabel(prerequisite) : 'выполните предыдущий шаг'
}
