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

function cleanParams(params: AdminBillingFilters = {}) {
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
