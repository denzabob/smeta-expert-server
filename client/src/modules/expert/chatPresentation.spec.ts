import { describe, expect, it } from 'vitest'
import { formatExpertMessageTimestamp } from './chatPresentation'

describe('formatExpertMessageTimestamp', () => {
  const now = new Date(2026, 8, 12, 21, 42)

  it('formats today, yesterday, current-year and past-year messages without ISO timestamps', () => {
    expect(formatExpertMessageTimestamp(new Date(2026, 8, 12, 9, 5).toISOString(), now)).toBe('09:05')
    expect(formatExpertMessageTimestamp(new Date(2026, 8, 11, 21, 42).toISOString(), now)).toBe('Вчера, 21:42')
    expect(formatExpertMessageTimestamp(new Date(2026, 7, 3, 8, 7).toISOString(), now)).toBe('3 августа, 08:07')
    expect(formatExpertMessageTimestamp(new Date(2025, 8, 12, 21, 42).toISOString(), now)).toBe('12 сентября 2025, 21:42')
  })

  it('uses a safe placeholder for malformed server data', () => {
    expect(formatExpertMessageTimestamp('not-a-date', now)).toBe('—')
  })
})
