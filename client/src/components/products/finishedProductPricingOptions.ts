import type {
  FinishedProductAggregationMethod,
  FinishedProductPriceEvidenceAssetDetails,
  FinishedProductPriceSourceKind,
  FinishedProductPriceSourceStatus,
} from '@/api/finishedProductPricing'

export const finishedProductPriceSourceKindItems: Array<{ value: FinishedProductPriceSourceKind; label: string }> = [
  { value: 'manual_entry', label: 'Ручной ввод' },
  { value: 'url_capture', label: 'Ссылка на источник' },
  { value: 'price_document', label: 'Документ поставщика' },
  { value: 'price_list_row', label: 'Строка из файла' },
]

export const finishedProductPriceSourceStatusItems: Array<{ value: FinishedProductPriceSourceStatus; label: string }> = [
  { value: 'active', label: 'Активен' },
  { value: 'inactive', label: 'Неактивен' },
  { value: 'stale', label: 'Устарел' },
  { value: 'invalid', label: 'Некорректен' },
  { value: 'superseded', label: 'Заменён' },
]

export const finishedProductAggregationMethodItems: Array<{
  value: FinishedProductAggregationMethod
  label: string
  description: string
}> = [
  { value: 'median', label: 'Медиана', description: 'Лучше сглаживает выбросы и подходит для рыночных цен.' },
  { value: 'mean', label: 'Среднее', description: 'Показывает обычное среднее по всем включенным источникам.' },
]

export function pricingMethodLabel(method?: string | null): string {
  if (method === 'median') return 'Медиана'
  if (method === 'mean') return 'Среднее'
  return 'Не задано'
}

export function pricingSourceKindLabel(kind?: string | null): string {
  return finishedProductPriceSourceKindItems.find((item) => item.value === kind)?.label ?? '—'
}

export function pricingSourceStatusLabel(status?: string | null): string {
  return finishedProductPriceSourceStatusItems.find((item) => item.value === status)?.label ?? '—'
}

export function pricingSourceStatusColor(status?: string | null): string {
  switch (status) {
    case 'active':
      return 'success'
    case 'inactive':
      return 'grey'
    case 'stale':
      return 'warning'
    case 'invalid':
      return 'error'
    case 'superseded':
      return 'secondary'
    default:
      return 'grey'
  }
}

export function formatPrice(value?: number | null): string {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '—'

  return new Intl.NumberFormat('ru-RU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(Number(value))
}

export function formatDate(value?: string | null): string {
  if (!value) return '—'

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  return new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(date)
}

export function formatDateTime(value?: string | null): string {
  if (!value) return '—'

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  return new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

export function formatFileSize(size?: number | null): string {
  if (size === null || size === undefined || size <= 0) return '—'

  const units = ['Б', 'КБ', 'МБ', 'ГБ']
  let value = size
  let unitIndex = 0

  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024
    unitIndex += 1
  }

  return `${value.toFixed(value >= 10 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`
}

export function pricingEvidenceLabel(type?: string | null): string {
  switch (type) {
    case 'screenshot':
      return 'Скриншот'
    case 'image':
      return 'Изображение'
    case 'file':
      return 'Файл'
    case 'link':
      return 'Ссылка'
    default:
      return type || 'Доказательство'
  }
}

export function pricingEvidenceIcon(type?: string | null): string {
  switch (type) {
    case 'screenshot':
      return 'mdi-monitor-screenshot'
    case 'image':
      return 'mdi-image-outline'
    case 'file':
      return 'mdi-file-document-outline'
    case 'link':
      return 'mdi-link-variant'
    default:
      return 'mdi-paperclip'
  }
}

export function pricingEvidenceActionLabel(asset: FinishedProductPriceEvidenceAssetDetails): string | null {
  if (asset.can_preview && asset.preview_url) return 'Предпросмотр'
  if (asset.can_download && asset.download_url) return 'Скачать'
  if (asset.open_url && asset.access_kind === 'external') return 'Открыть'
  return null
}
