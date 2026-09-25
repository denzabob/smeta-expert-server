import { describe, expect, it } from 'vitest'
import { formatStorageBytes, storageQuotaErrorMessage, storageUsageTone } from './storagePresentation'

describe('storage presentation', () => {
  it('formats byte units for the account storage panel and quota errors', () => {
    expect(formatStorageBytes(1024)).toBe('1 КБ')
    expect(formatStorageBytes(5 * 1024 ** 3)).toBe('5 ГБ')
    expect(storageQuotaErrorMessage(400 * 1024 ** 2, 210 * 1024 ** 2))
      .toBe('Недостаточно места: нужно 400 МБ, доступно 210 МБ.')
  })

  it.each([
    [79.99, false, 'normal'],
    [80, false, 'warning'],
    [95, false, 'warning'],
    [95.01, false, 'critical'],
    [100, false, 'over-limit'],
    [40, true, 'over-limit'],
    [null, false, 'normal'],
  ] as const)('selects the semantic state for %s percent', (percent, isOverLimit, expected) => {
    expect(storageUsageTone(percent, isOverLimit)).toBe(expected)
  })
})
