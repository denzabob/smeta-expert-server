import { describe, expect, it } from 'vitest'
import { canStartImport, shouldPollImport, shouldPollPreview, sourceFileActions } from './workflow'
import type { StatisticalImportPreviewResult } from './types'

describe('Price Indices admin workflow decisions', () => {
  it('polls only non-terminal backend states', () => {
    expect(['pending', 'running'].filter((status) => shouldPollPreview(status as 'pending' | 'running'))).toHaveLength(2)
    expect(shouldPollPreview('ready')).toBe(false); expect(shouldPollPreview('failed')).toBe(false); expect(shouldPollPreview('expired')).toBe(false)
    expect(shouldPollImport('pending')).toBe(true); expect(shouldPollImport('importing')).toBe(true); expect(shouldPollImport('validating')).toBe(true)
    expect(shouldPollImport('ready_for_publish')).toBe(false); expect(shouldPollImport('failed')).toBe(false)
  })

  it('maps source states to safe visible actions', () => {
    expect(sourceFileActions('pending_review')).toEqual(['approve', 'reject', 'download'])
    expect(sourceFileActions('approved')).toEqual(['activate', 'download'])
    expect(sourceFileActions('active')).toEqual(['preview', 'download'])
    expect(sourceFileActions('rejected')).toEqual(['download']); expect(sourceFileActions('superseded')).toEqual(['download'])
  })

  it('allows import only for active ready preview without fatal errors', () => {
    const result = { counts: { fatal_errors: 0 } } as StatisticalImportPreviewResult
    expect(canStartImport('active', 'ready', result)).toBe(true)
    expect(canStartImport('approved', 'ready', result)).toBe(false)
    expect(canStartImport('active', 'running', result)).toBe(false)
    expect(canStartImport('active', 'ready', { counts: { fatal_errors: 1 } } as StatisticalImportPreviewResult)).toBe(false)
  })
})
