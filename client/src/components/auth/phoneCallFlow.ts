export type CallUiStatus = 'idle' | 'pending' | 'verified' | 'expired' | 'failed'

export function extractPhoneDigits(value: string): string {
  return (value || '').replace(/\D/g, '')
}

export function formatRuPhoneMask(value: string): string {
  let digits = extractPhoneDigits(value)

  if (digits.startsWith('8')) {
    digits = `7${digits.slice(1)}`
  }

  if (!digits.startsWith('7')) {
    digits = `7${digits}`
  }

  digits = digits.slice(0, 11)

  const a = digits.slice(1, 4)
  const b = digits.slice(4, 7)
  const c = digits.slice(7, 9)
  const d = digits.slice(9, 11)

  let result = '+7'
  if (a) result += ` (${a}`
  if (a.length === 3) result += ')'
  if (b) result += ` ${b}`
  if (c) result += `-${c}`
  if (d) result += `-${d}`

  return result
}

export function isCompleteRuPhone(value: string): boolean {
  const digits = extractPhoneDigits(value)
  return digits.length === 11 && (digits.startsWith('7') || digits.startsWith('8'))
}

export function toE164RuPhone(value: string): string {
  let digits = extractPhoneDigits(value)
  if (digits.startsWith('8')) {
    digits = `7${digits.slice(1)}`
  }
  if (digits.length === 10) {
    digits = `7${digits}`
  }
  return `+${digits.slice(0, 11)}`
}

export function toStatusLabel(status: CallUiStatus): string {
  if (status === 'pending') return 'Ожидаем звонок'
  if (status === 'verified') return 'Звонок подтвержден'
  if (status === 'expired') return 'Время ожидания истекло'
  if (status === 'failed') return 'Ошибка подтверждения'
  return ''
}
