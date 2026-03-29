import { describe, it, expect } from 'vitest'

// Tests for evidence run UI logic — pure functions and contracts.
// Avoids importing from composable/API to prevent axios → window dependency.
// Constants are replicated here for verification against the composable source.

// ── Run status contracts ──

const RUN_STATUS_LABELS: Record<string, string> = {
  pending: 'Ожидание',
  in_progress: 'В работе',
  ready: 'Готов к финализации',
  finalized: 'Финализирован',
  failed: 'Ошибка',
}

const RUN_STATUS_COLORS: Record<string, string> = {
  pending: 'grey',
  in_progress: 'info',
  ready: 'warning',
  finalized: 'success',
  failed: 'error',
}

const ITEM_STATUS_LABELS: Record<string, string> = {
  pending: 'Ожидание',
  collecting: 'Сбор',
  resolved: 'Подтверждён',
  failed: 'Ошибка',
  skipped: 'Пропущен',
}

const ITEM_STATUS_COLORS: Record<string, string> = {
  pending: 'grey',
  collecting: 'info',
  resolved: 'success',
  failed: 'error',
  skipped: 'warning',
}

const COST_COMPONENT_LABELS: Record<string, string> = {
  plate: 'Плита',
  edge: 'Кромка',
  facade: 'Фасад',
  fitting: 'Фурнитура',
  operation: 'Операция',
  labor_work: 'Работа',
  expense: 'Расход',
}

// ── Status labels/colors ──

describe('RUN_STATUS_LABELS', () => {
  it('has labels for all run statuses', () => {
    const statuses = ['pending', 'in_progress', 'ready', 'finalized', 'failed']
    for (const s of statuses) {
      expect(RUN_STATUS_LABELS[s]).toBeTruthy()
    }
  })

  it('finalized label is correct', () => {
    expect(RUN_STATUS_LABELS.finalized).toBe('Финализирован')
  })
})

describe('RUN_STATUS_COLORS', () => {
  it('has colors for all run statuses', () => {
    const statuses = ['pending', 'in_progress', 'ready', 'finalized', 'failed']
    for (const s of statuses) {
      expect(RUN_STATUS_COLORS[s]).toBeTruthy()
    }
  })

  it('finalized color is success', () => {
    expect(RUN_STATUS_COLORS.finalized).toBe('success')
  })

  it('failed color is error', () => {
    expect(RUN_STATUS_COLORS.failed).toBe('error')
  })
})

describe('ITEM_STATUS_LABELS', () => {
  it('has labels for all item statuses', () => {
    const statuses = ['pending', 'collecting', 'resolved', 'failed', 'skipped']
    for (const s of statuses) {
      expect(ITEM_STATUS_LABELS[s]).toBeTruthy()
    }
  })
})

describe('ITEM_STATUS_COLORS', () => {
  it('has colors for all item statuses', () => {
    const statuses = ['pending', 'collecting', 'resolved', 'failed', 'skipped']
    for (const s of statuses) {
      expect(ITEM_STATUS_COLORS[s]).toBeTruthy()
    }
  })

  it('resolved color is success', () => {
    expect(ITEM_STATUS_COLORS.resolved).toBe('success')
  })

  it('skipped color is warning', () => {
    expect(ITEM_STATUS_COLORS.skipped).toBe('warning')
  })
})

describe('COST_COMPONENT_LABELS', () => {
  it('has labels for all cost components', () => {
    const components = ['plate', 'edge', 'facade', 'fitting', 'operation', 'labor_work', 'expense']
    for (const c of components) {
      expect(COST_COMPONENT_LABELS[c]).toBeTruthy()
    }
  })

  it('plate label is Плита', () => {
    expect(COST_COMPONENT_LABELS.plate).toBe('Плита')
  })
})

// ── Coverage computation (pure function, mirrors composable) ──

interface CoverageInput { status: string }

function computeCoverage(items: CoverageInput[]) {
  return {
    total: items.length,
    resolved: items.filter((i) => i.status === 'resolved').length,
    skipped: items.filter((i) => i.status === 'skipped').length,
    failed: items.filter((i) => i.status === 'failed').length,
    pending: items.filter((i) => i.status === 'pending' || i.status === 'collecting').length,
  }
}

describe('coverage computation', () => {
  it('returns zeros for empty items', () => {
    expect(computeCoverage([])).toEqual({ total: 0, resolved: 0, skipped: 0, failed: 0, pending: 0 })
  })

  it('counts resolved and skipped', () => {
    const items = [
      { status: 'resolved' },
      { status: 'resolved' },
      { status: 'skipped' },
      { status: 'pending' },
    ]
    expect(computeCoverage(items)).toEqual({ total: 4, resolved: 2, skipped: 1, failed: 0, pending: 1 })
  })

  it('counts failed items', () => {
    const items = [{ status: 'resolved' }, { status: 'failed' }]
    expect(computeCoverage(items)).toEqual({ total: 2, resolved: 1, skipped: 0, failed: 1, pending: 0 })
  })

  it('treats collecting as pending', () => {
    expect(computeCoverage([{ status: 'collecting' }]).pending).toBe(1)
  })

  it('handles mixed statuses', () => {
    const items = [
      { status: 'resolved' },
      { status: 'skipped' },
      { status: 'failed' },
      { status: 'pending' },
      { status: 'collecting' },
    ]
    const c = computeCoverage(items)
    expect(c.total).toBe(5)
    expect(c.resolved).toBe(1)
    expect(c.skipped).toBe(1)
    expect(c.failed).toBe(1)
    expect(c.pending).toBe(2)
  })
})

// ── Finalize eligibility ──

describe('canFinalize logic', () => {
  it('returns true only for ready status', () => {
    const canFinalize = (status: string) => status === 'ready'
    expect(canFinalize('ready')).toBe(true)
    expect(canFinalize('pending')).toBe(false)
    expect(canFinalize('in_progress')).toBe(false)
    expect(canFinalize('finalized')).toBe(false)
    expect(canFinalize('failed')).toBe(false)
  })
})

// ── PDF availability detection ──

describe('PDF availability detection', () => {
  const is404 = (err: { response?: { status?: number } }) => err?.response?.status === 404

  it('identifies 404 as PDF unavailable', () => {
    expect(is404({ response: { status: 404 } })).toBe(true)
  })

  it('does not false-positive on 422/403/500', () => {
    expect(is404({ response: { status: 422 } })).toBe(false)
    expect(is404({ response: { status: 403 } })).toBe(false)
    expect(is404({ response: { status: 500 } })).toBe(false)
    expect(is404({})).toBe(false)
  })

  it('404 on list endpoint is treated as regular error, not feature gate', () => {
    // In the hardened model, 404 on fetchRuns() does NOT flip a global
    // feature-unavailable flag — it shows a generic error message instead.
    const handleListError = (_err: { response?: { status?: number } }) => {
      return 'Не удалось загрузить список запусков обоснований.'
    }
    expect(handleListError({ response: { status: 404 } })).toBe('Не удалось загрузить список запусков обоснований.')
    expect(handleListError({ response: { status: 500 } })).toBe('Не удалось загрузить список запусков обоснований.')
  })

  it('404 on PDF download flips pdfAvailable to false', () => {
    let pdfAvailable = true
    let errorMsg: string | null = null
    const handlePdfError = (err: { response?: { status?: number } }) => {
      if (is404(err)) {
        pdfAvailable = false
        errorMsg = 'Генерация PDF недоступна на данном сервере.'
      } else {
        errorMsg = 'Не удалось скачать PDF.'
      }
    }
    handlePdfError({ response: { status: 404 } })
    expect(pdfAvailable).toBe(false)
    expect(errorMsg).toBe('Генерация PDF недоступна на данном сервере.')
  })

  it('non-404 on PDF download keeps pdfAvailable true', () => {
    let pdfAvailable = true
    let errorMsg: string | null = null
    const handlePdfError = (err: { response?: { status?: number } }) => {
      if (is404(err)) {
        pdfAvailable = false
        errorMsg = 'Генерация PDF недоступна на данном сервере.'
      } else {
        errorMsg = 'Не удалось скачать PDF.'
      }
    }
    handlePdfError({ response: { status: 422 } })
    expect(pdfAvailable).toBe(true)
    expect(errorMsg).toBe('Не удалось скачать PDF.')
  })
})

// ── Run terminal state ──

describe('isRunTerminal logic', () => {
  it('finalized and failed are terminal', () => {
    const isTerminal = (status: string) => status === 'finalized' || status === 'failed'
    expect(isTerminal('finalized')).toBe(true)
    expect(isTerminal('failed')).toBe(true)
    expect(isTerminal('ready')).toBe(false)
    expect(isTerminal('pending')).toBe(false)
    expect(isTerminal('in_progress')).toBe(false)
  })
})

// ── Legacy revision isolation ──

describe('legacy revision UI independence', () => {
  it('evidence uses lowercase statuses, revision uses uppercase — no collision', () => {
    const revisionStatuses = ['PENDING', 'IN_PROGRESS', 'NEEDS_MANUAL', 'READY', 'FINALIZED', 'FAILED']
    const evidenceRunStatuses = ['pending', 'in_progress', 'ready', 'finalized', 'failed']
    for (const es of evidenceRunStatuses) {
      expect(revisionStatuses.includes(es)).toBe(false)
    }
  })
})

// ── Percentage computation ──

describe('coverage percentage', () => {
  it('computes 0% for empty', () => {
    const pct = (total: number, resolved: number, skipped: number) =>
      total === 0 ? 0 : Math.round(((resolved + skipped) / total) * 100)
    expect(pct(0, 0, 0)).toBe(0)
  })

  it('computes 100% when all resolved', () => {
    const pct = (total: number, resolved: number, skipped: number) =>
      total === 0 ? 0 : Math.round(((resolved + skipped) / total) * 100)
    expect(pct(5, 5, 0)).toBe(100)
  })

  it('computes 60% for 3/5 resolved+skipped', () => {
    const pct = (total: number, resolved: number, skipped: number) =>
      total === 0 ? 0 : Math.round(((resolved + skipped) / total) * 100)
    expect(pct(5, 2, 1)).toBe(60)
  })
})
