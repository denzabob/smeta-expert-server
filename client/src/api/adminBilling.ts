import api from './axios'

export type AdminBillingFilters = {
  user_id?: number | string | null
  metric_code?: string | null
  feature_code?: string | null
  project_id?: number | string | null
  period_start?: string | null
  period_end?: string | null
  from?: string | null
  to?: string | null
  source?: string | null
  limit?: number
}

export type AdminBillingGateEventFilters = {
  user_id?: number | string | null
  capability?: string | null
  would_block?: boolean | string | number | null
  enforced?: boolean | string | number | null
  date_from?: string | null
  date_to?: string | null
  per_page?: number
  page?: number
}

export type AdminBillingPlanPayload = {
  code?: string
  name?: string
  is_active?: boolean
  price_minor?: number
  currency?: 'RUB'
  billing_period?: 'month' | 'year' | 'one_time' | 'custom'
  hidden?: boolean
  sandbox?: boolean
  system?: boolean
  sort_order?: number | null
  description?: string | null
  features?: string[]
  limits?: Record<string, number | string | null>
}

export type AssignBillingSubscriptionPayload = {
  plan_code: string
  period?: 'month' | 'year' | 'custom' | null
  starts_at?: string | null
  ends_at?: string | null
  reason?: string | null
}

export type ExtendBillingSubscriptionPayload = {
  months?: number | null
  days?: number | null
  reason?: string | null
}

function cleanParams(params: Record<string, unknown> = {}) {
  return Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== '')
  )
}

export async function getAdminBillingOverview(params: AdminBillingFilters = {}) {
  const { data } = await api.get('/api/admin/billing/overview', { params: cleanParams(params) })
  return data
}

export async function getAdminBillingUserOverview(userId: number | string, params: AdminBillingFilters = {}) {
  const { data } = await api.get(`/api/admin/billing/users/${userId}/overview`, { params: cleanParams(params) })
  return data
}

export async function getAdminBillingUsage(params: AdminBillingFilters = {}) {
  const { data } = await api.get('/api/admin/billing/usage', { params: cleanParams(params) })
  return data
}

export async function getAdminBillingEvents(params: AdminBillingFilters = {}) {
  const { data } = await api.get('/api/admin/billing/events', { params: cleanParams(params) })
  return data
}

export async function getAdminBillingGateEvents(params: AdminBillingGateEventFilters = {}) {
  const { data } = await api.get('/api/admin/billing/gate-events', { params: cleanParams(params) })
  return data
}

export async function getAdminBillingGateEventsSummary(params: AdminBillingGateEventFilters = {}) {
  const { data } = await api.get('/api/admin/billing/gate-events/summary', { params: cleanParams(params) })
  return data
}

export async function getAdminBillingPlans() {
  const { data } = await api.get('/api/admin/billing/plans')
  return data
}

export async function getAdminBillingPlan(planId: number | string) {
  const { data } = await api.get(`/api/admin/billing/plans/${planId}`)
  return data
}

export async function createAdminBillingPlan(payload: AdminBillingPlanPayload) {
  const { data } = await api.post('/api/admin/billing/plans', payload)
  return data
}

export async function updateAdminBillingPlan(planId: number | string, payload: AdminBillingPlanPayload) {
  const { data } = await api.patch(`/api/admin/billing/plans/${planId}`, payload)
  return data
}

export async function getAdminBillingUserSubscription(userId: number | string) {
  const { data } = await api.get(`/api/admin/billing/users/${userId}/subscription`)
  return data
}

export async function searchAdminBillingUsers(query: string) {
  const { data } = await api.get('/api/admin/users/search', { params: { q: query } })
  return data
}

export async function assignAdminBillingUserSubscription(userId: number | string, payload: AssignBillingSubscriptionPayload) {
  const { data } = await api.post(`/api/admin/billing/users/${userId}/subscription/assign`, payload)
  return data
}

export async function extendAdminBillingUserSubscription(userId: number | string, payload: ExtendBillingSubscriptionPayload) {
  const { data } = await api.post(`/api/admin/billing/users/${userId}/subscription/extend`, payload)
  return data
}

export async function cancelAdminBillingUserSubscription(userId: number | string, payload: { reason?: string | null } = {}) {
  const { data } = await api.post(`/api/admin/billing/users/${userId}/subscription/cancel`, payload)
  return data
}

export async function switchAdminBillingUserSubscriptionToLegacy(userId: number | string, payload: { reason?: string | null } = {}) {
  const { data } = await api.post(`/api/admin/billing/users/${userId}/subscription/legacy`, payload)
  return data
}

export async function getAdminBillingUserSubscriptionHistory(userId: number | string) {
  const { data } = await api.get(`/api/admin/billing/users/${userId}/subscription/history`)
  return data
}
