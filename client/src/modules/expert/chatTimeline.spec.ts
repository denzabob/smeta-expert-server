import { describe, expect, it } from 'vitest'
import {
  addExpertTimelineRun,
  appendExpertReasoningSummary,
  applyExpertTimelineActivity,
  createExpertTimelineRun,
  expertTimelineAria,
  finishExpertTimelineRun,
  hasSignificantExpertTimelineActivity,
  isSignificantExpertTimelineActivity,
  moveExpertTimelineRuns,
  presentExpertTimelineActivity,
  removeExpertTimelineRuns,
  type ExpertRunActivity,
} from './chatTimeline'

const started: ExpertRunActivity = {
  runId: 'run-1', seq: 1, activityId: 'activity-1', code: 'pdf.ocr.started', status: 'started', category: 'material', detail: 'scan.pdf',
}

describe('Expert activity timeline state', () => {
  it('updates a started item in place, deduplicates delivery, and never reverses a terminal item', () => {
    let runs = [createExpertTimelineRun('run-1')]
    runs = applyExpertTimelineActivity(runs, started)
    runs = applyExpertTimelineActivity(runs, { ...started, seq: 2, code: 'pdf.ocr.completed', status: 'completed' })
    runs = applyExpertTimelineActivity(runs, { ...started, seq: 2, status: 'started' })
    runs = applyExpertTimelineActivity(runs, { ...started, seq: 3, status: 'started' })

    expect(runs[0]?.activities).toEqual([expect.objectContaining({ activityId: 'activity-1', code: 'pdf.ocr.completed', status: 'completed' })])
    expect(runs[0]?.lastActivitySeq).toBe(3)
  })

  it('keeps a continuation as a separate run and bounds session history', () => {
    let runs = [createExpertTimelineRun('run-1')]
    runs = finishExpertTimelineRun(runs, 'run-1', 'cancelled')
    runs = addExpertTimelineRun(runs, 'run-2')

    expect(runs.map((run) => run.runId)).toEqual(['run-1', 'run-2'])
    expect(runs[0]?.terminal).toBe('cancelled')
    expect(runs[1]?.terminal).toBeUndefined()
  })

  it('cleans active visual state after Stop and Interrupted without inventing a new stage', () => {
    const cancelled = finishExpertTimelineRun([createExpertTimelineRun('run-1')].map((run) => ({ ...run, activities: [started] })), 'run-1', 'cancelled')
    const interrupted = finishExpertTimelineRun([createExpertTimelineRun('run-1')].map((run) => ({ ...run, activities: [started] })), 'run-1', 'interrupted')

    expect(cancelled[0]?.activities[0]?.status).toBe('skipped')
    expect(interrupted[0]?.activities[0]?.status).toBe('failed')
  })

  it('ignores activity and terminal delivery after a terminal state', () => {
    let runs = finishExpertTimelineRun([createExpertTimelineRun('run-1')].map((run) => ({ ...run, activities: [started] })), 'run-1', 'completed')
    runs = applyExpertTimelineActivity(runs, { ...started, seq: 2, code: 'generation.interrupted', status: 'failed' })
    runs = finishExpertTimelineRun(runs, 'run-1', 'interrupted')

    expect(runs[0]?.terminal).toBe('completed')
    expect(runs[0]?.activities).toEqual([expect.objectContaining({ code: 'pdf.ocr.started', status: 'skipped' })])
  })

  it('keeps an explicit safe summary separate from the assistant answer and ignores no provider metadata itself', () => {
    let runs = [createExpertTimelineRun('run-1')]
    runs = appendExpertReasoningSummary(runs, { runId: 'run-1', seq: 1, text: 'Проверены вложенные материалы.', final: true })

    expect(runs[0]?.reasoningSummary).toBe('Проверены вложенные материалы.')
    expect(runs[0]).not.toHaveProperty('reasoning')
    expect(JSON.stringify(runs)).not.toContain('raw_chain_of_thought')
  })

  it('maps OCR/cache states and unknown codes to neutral presentation', () => {
  expect(presentExpertTimelineActivity({ ...started, code: 'pdf.ocr_cache.hit', status: 'completed' })).toMatchObject({ label: 'Использован кеш документа' })
    expect(presentExpertTimelineActivity({ ...started, code: 'pdf.text.started', status: 'started' })).toMatchObject({ label: 'Обрабатываю большой документ' })
    expect(presentExpertTimelineActivity({ ...started, code: 'pdf.text_cache.hit', status: 'completed' })).toMatchObject({ label: 'Использован кеш разбора документа' })
    expect(presentExpertTimelineActivity({ ...started, code: 'future.provider.operation', status: 'started' })).toMatchObject({ label: 'Выполняется операция' })
    expect(presentExpertTimelineActivity({ ...started, code: 'generation.cancelled', status: 'completed' }).icon).not.toBe('mdi-check-circle-outline')
  })

  it('keeps fast text-only transport stages out of the visible timeline but identifies material work', () => {
    expect(isSignificantExpertTimelineActivity({ ...started, code: 'request.accepted', status: 'completed' })).toBe(false)
    expect(isSignificantExpertTimelineActivity({ ...started, code: 'model.first_token', status: 'completed' })).toBe(false)
    expect(isSignificantExpertTimelineActivity({ ...started, code: 'material.image_prepare.started' })).toBe(true)
    expect(isSignificantExpertTimelineActivity({ ...started, code: 'analysis.material.started' })).toBe(true)
    expect(hasSignificantExpertTimelineActivity({
      ...createExpertTimelineRun('run-1'),
      activities: [{ ...started, code: 'pdf.ocr.started' }],
    })).toBe(true)
  })

  it('exposes stable keyboard ARIA controls and clears session-only runs', () => {
    const aria = expertTimelineAria('run-1', false)
    const moved = moveExpertTimelineRuns({ local: [createExpertTimelineRun('run-1')] }, 'local', 'assistant-1')
    const cleaned = removeExpertTimelineRuns(moved, 'assistant-1')

    expect(aria).toMatchObject({ ariaExpanded: false, ariaControls: 'expert-timeline-activity-run-1' })
    expect(cleaned).toEqual({})
  })
})
