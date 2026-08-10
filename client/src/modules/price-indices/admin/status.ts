import type { ImportStatus, PreviewStatus, SourceFileStatus } from './types'

export const sourceStatusLabels: Record<SourceFileStatus, string> = {
  pending_review: 'На проверке', approved: 'Одобрен', active: 'Активен', rejected: 'Отклонён', superseded: 'Заменён',
}
export const previewStatusLabels: Record<PreviewStatus, string> = {
  pending: 'В очереди', running: 'Выполняется', ready: 'Готов', failed: 'Ошибка', expired: 'Истёк',
}
export const importStatusLabels: Record<ImportStatus, string> = {
  pending: 'В очереди', importing: 'Импортируется', validating: 'Проверяется',
  ready_for_publish: 'Готов к публикации', published: 'Опубликован', superseded: 'Заменён', failed: 'Ошибка',
}

export function statusColor(status: string): string {
  if (['active', 'ready', 'ready_for_publish', 'published', 'passed'].includes(status)) return 'success'
  if (['failed', 'rejected'].includes(status)) return 'error'
  if (['pending', 'pending_review', 'running', 'importing', 'validating'].includes(status)) return 'warning'
  return 'default'
}

export function formatDate(value: string | null | undefined): string {
  return value ? new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '—'
}

export function formatPeriod(year: number | null, month: number | null): string {
  if (!year || !month) return 'Не определён'
  return new Intl.DateTimeFormat('ru-RU', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1))
}

export function formatBytes(bytes: number): string {
  if (!Number.isFinite(bytes)) return '—'
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} КБ`
  return `${(bytes / 1024 / 1024).toFixed(1)} МБ`
}
