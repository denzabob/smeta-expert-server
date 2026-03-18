import { describe, expect, it } from 'vitest'
import {
  extractPhoneDigits,
  formatRuPhoneMask,
  isCompleteRuPhone,
  toE164RuPhone,
  toStatusLabel,
} from '@/components/auth/phoneCallFlow'

describe('phoneCallFlow utils', () => {
  it('extracts only digits from mixed phone string', () => {
    expect(extractPhoneDigits('+7 (999) 123-45-67')).toBe('79991234567')
  })

  it('formats russian mask consistently', () => {
    expect(formatRuPhoneMask('89991234567')).toBe('+7 (999) 123-45-67')
    expect(formatRuPhoneMask('9991234')).toBe('+7 (999) 123-4')
  })

  it('validates complete russian phone values', () => {
    expect(isCompleteRuPhone('+7 (999) 123-45-67')).toBe(true)
    expect(isCompleteRuPhone('8 999 123 45 67')).toBe(true)
    expect(isCompleteRuPhone('+7 (999) 123-45')).toBe(false)
  })

  it('converts russian phone to e164', () => {
    expect(toE164RuPhone('+7 (999) 123-45-67')).toBe('+79991234567')
    expect(toE164RuPhone('8 (999) 123-45-67')).toBe('+79991234567')
    expect(toE164RuPhone('9991234567')).toBe('+79991234567')
  })

  it('returns user-facing status labels', () => {
    expect(toStatusLabel('pending')).toContain('Ожидаем')
    expect(toStatusLabel('verified')).toContain('подтвержден')
    expect(toStatusLabel('expired')).toContain('истекло')
    expect(toStatusLabel('failed')).toContain('Ошибка')
    expect(toStatusLabel('idle')).toBe('')
  })
})