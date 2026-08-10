import type { ImportStatus, PreviewStatus, SourceFileStatus, StatisticalImportPreviewResult } from './types'

export function shouldPollPreview(status: PreviewStatus): boolean {
  return status === 'pending' || status === 'running'
}

export function shouldPollImport(status: ImportStatus): boolean {
  return status === 'pending' || status === 'importing' || status === 'validating'
}

export function canStartImport(sourceStatus: SourceFileStatus, previewStatus: PreviewStatus, result: StatisticalImportPreviewResult | null): boolean {
  return sourceStatus === 'active' && previewStatus === 'ready' && result !== null && result.counts.fatal_errors === 0
}

export function sourceFileActions(status: SourceFileStatus): string[] {
  if (status === 'pending_review') return ['approve', 'reject', 'download']
  if (status === 'approved') return ['activate', 'download']
  if (status === 'active') return ['preview', 'download']
  return ['download']
}
