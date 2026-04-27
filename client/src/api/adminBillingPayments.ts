import api from './axios'

export type BillingListParams = Record<string, string | number | null | undefined>

export type CreateBillingInvoicePayload = {
  user_id: number
  plan_code: string
  billing_period?: 'month' | 'year'
}

export type CreateBillingPaymentPayload = {
  provider_code?: string
}

function cleanParams(params: BillingListParams = {}) {
  return Object.fromEntries(
    Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== '')
  )
}

export async function getBillingInvoices(params: BillingListParams = {}) {
  const { data } = await api.get('/api/admin/billing/invoices', { params: cleanParams(params) })
  return data
}

export async function getBillingPayments(params: BillingListParams = {}) {
  const { data } = await api.get('/api/admin/billing/payments', { params: cleanParams(params) })
  return data
}

export async function getBillingProviderEvents(params: BillingListParams = {}) {
  const { data } = await api.get('/api/admin/billing/provider-events', { params: cleanParams(params) })
  return data
}

export async function getBillingPaymentPlans() {
  const { data } = await api.get('/api/admin/billing/payment-plans')
  return data
}

export async function createBillingInvoice(payload: CreateBillingInvoicePayload) {
  const { data } = await api.post('/api/admin/billing/invoices', payload)
  return data
}

export async function createBillingPayment(invoiceId: number, payload: CreateBillingPaymentPayload = {}) {
  const { data } = await api.post(`/api/admin/billing/invoices/${invoiceId}/payments`, payload)
  return data
}

export async function getBillingInvoice(invoiceId: number) {
  const { data } = await api.get(`/api/admin/billing/invoices/${invoiceId}`)
  return data
}

export async function getBillingPayment(paymentId: number) {
  const { data } = await api.get(`/api/admin/billing/payments/${paymentId}`)
  return data
}

export async function getBillingInvoiceDetails(invoiceId: number) {
  const { data } = await api.get(`/api/admin/billing/invoices/${invoiceId}/details`)
  return data
}

export async function getBillingPaymentDetails(paymentId: number) {
  const { data } = await api.get(`/api/admin/billing/payments/${paymentId}/details`)
  return data
}

export async function getBillingProviderEventDetails(eventId: number) {
  const { data } = await api.get(`/api/admin/billing/provider-events/${eventId}/details`)
  return data
}

export async function refreshBillingPaymentProviderStatus(paymentId: number) {
  const { data } = await api.post(`/api/admin/billing/payments/${paymentId}/refresh-provider-status`)
  return data
}
