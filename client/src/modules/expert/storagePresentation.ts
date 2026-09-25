export type StorageUsageTone = 'normal' | 'warning' | 'critical' | 'over-limit'

export function formatStorageBytes(bytes: number): string {
  const value = Math.max(0, Number.isFinite(bytes) ? bytes : 0)
  const units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ']
  let amount = value
  let unitIndex = 0

  while (amount >= 1024 && unitIndex < units.length - 1) {
    amount /= 1024
    unitIndex += 1
  }

  const formatted = new Intl.NumberFormat('ru-RU', {
    maximumFractionDigits: unitIndex === 0 ? 0 : 1,
  }).format(amount)

  return `${formatted} ${units[unitIndex]}`
}

export function storageUsageTone(percent: number | null, isOverLimit: boolean): StorageUsageTone {
  if (isOverLimit || (percent !== null && percent >= 100)) return 'over-limit'
  if (percent !== null && percent > 95) return 'critical'
  if (percent !== null && percent >= 80) return 'warning'
  return 'normal'
}

export function storageToneColor(tone: StorageUsageTone): 'success' | 'warning' | 'error' {
  if (tone === 'warning') return 'warning'
  if (tone === 'critical' || tone === 'over-limit') return 'error'
  return 'success'
}

export function storageQuotaErrorMessage(requestedBytes: number, remainingBytes: number): string {
  return `Недостаточно места: нужно ${formatStorageBytes(requestedBytes)}, доступно ${formatStorageBytes(remainingBytes)}.`
}
