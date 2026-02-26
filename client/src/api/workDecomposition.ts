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
  
  // Map backend response to frontend expected structure
  // Backend returns: { source, meta, suggestion: { steps, totals: { hours } } }
  // Frontend expects: { tier, preset_id, status, steps, total_hours }
  return {
    tier: data.source === 'ai' ? 3 : (data.source === 'preset' ? 1 : 2),
    preset_id: data.preset_id ?? null,
    status: data.meta?.is_draft ? 'draft' : (data.status ?? null),
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

// === Fingerprint Helper (matches backend) ===

/**
 * Calculate fingerprint for anti-spam check
 * Must match backend ContextNormalizer::makeFingerprint
 */
export function makeFingerprint(
  title: string,
  steps: Array<{ title: string; hours: number }>
): string {
  const normalizedTitle = title.toLowerCase().trim()
  const stepsStr = steps
    .map(s => `${s.title.toLowerCase().trim()}:${Number(s.hours).toFixed(2)}`)
    .join('|')
  
  // Simple hash for fingerprint (matches MD5 concept but client-side)
  // We use a simple string for comparison, not crypto hash
  return `${normalizedTitle}::${stepsStr}`
}

export default {
  decompose,
  feedback,
  makeFingerprint
}
