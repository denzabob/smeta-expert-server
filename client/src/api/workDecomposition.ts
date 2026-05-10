/**
 * Work Decomposition API Client
 * AI-powered work decomposition with tier-based caching
 */

import api from './axios'

// === Types ===

export interface DecomposeContext {
  domain?: 'furniture' | 'construction' | 'electrical' | 'plumbing' | 'cleaning'
  action_type?: 'install' | 'dismantle' | 'repair' | 'adjust'
  constraints?: 'normal' | 'cramped'
  site_state?: 'rough' | 'living' | 'emergency'
  material?: string
  object_type?: string
  appliances?: string
  floor_access?: string
  note?: string
}

export interface DecomposeStep {
  title: string
  basis: string
  hours: number
  input_data?: string
}

export interface DecomposeResponse {
  tier: 1 | 2 | 3
  source: 'tier1_exact' | 'ai' | 'fallback_local' | string
  preset_id: number | null
  status: 'draft' | 'candidate' | 'verified' | null
  steps: DecomposeStep[]
  total_hours: number
}

export interface FeedbackPayload {
  title: string
  context: DecomposeContext
  steps: Array<{
    title: string
    basis: string
    hours: number
    input_data?: string
  }>
  source: 'ai' | 'manual'
}

// === API Functions ===

/**
 * Request AI decomposition for a work title
 */
export async function decompose(
  title: string,
  context: DecomposeContext,
  desiredHours?: number,
  note?: string
): Promise<DecomposeResponse> {
  const { data } = await api.post('/api/work/decompose', {
    title,
    context,
    desired_hours: desiredHours,
    note: note || undefined
  })
  
  const source = String(data.source ?? '')
  const status = (data.meta?.status ?? data.status ?? (data.meta?.is_draft ? 'draft' : null)) as DecomposeResponse['status']

  return {
    tier: source === 'tier1_exact' ? 1 : (source === 'ai' || source === 'fallback_local' ? 3 : 2),
    source,
    preset_id: data.meta?.preset_id ?? data.preset_id ?? null,
    status,
    steps: data.suggestion?.steps ?? [],
    total_hours: data.suggestion?.totals?.hours ?? 0
  }
}

/**
 * Send feedback to accumulate presets
 */
export async function feedback(payload: FeedbackPayload): Promise<void> {
  await api.post('/api/work/presets/feedback', payload)
}

// === Local feedback fingerprint helper ===

/**
 * Calculate a local fingerprint for anti-spam checks inside the current modal session.
 * It is intentionally UI-local and does not match backend preset fingerprints.
 */
export function makeLocalFeedbackFingerprint(
  title: string,
  steps: Array<{ title: string; hours: number }>
): string {
  const normalizedTitle = title.toLowerCase().trim()
  const stepsStr = steps
    .map(s => `${s.title.toLowerCase().trim()}:${Number(s.hours).toFixed(2)}`)
    .join('|')
  
  return `${normalizedTitle}::${stepsStr}`
}

export default {
  decompose,
  feedback,
  makeLocalFeedbackFingerprint
}
