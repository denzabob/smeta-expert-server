import api from './axios'

export type BillingPreviewFlags = {
  enabled: boolean
  enforce_limits: boolean
  log_only: boolean
  checkout_enabled: boolean
  mode_label: string
}

export type BillingPreviewPlan = {
  code: string
  name: string
  description?: string | null
  price?: number | null
  price_minor?: number | null
  currency?: string | null
  billing_period?: string | null
  period?: string | null
  is_current?: boolean
  is_available?: boolean
  is_default?: boolean
  features?: string[]
  limits?: Array<{
    code: string
    name?: string
    label: string
    limit: number | null
    unit: string
  }>
}

export type BillingPreviewSubscription = {
  status: string
  current_period_start?: string | null
  current_period_end?: string | null
}

export type BillingPreviewUsageItem = {
  code: string
  label: string
  used: number
  limit: number | null
  unit: string
  period: 'current' | 'month' | string
}

export type BillingPreview = {
  billing: BillingPreviewFlags
  current_plan: BillingPreviewPlan
  subscription: BillingPreviewSubscription
  usage: BillingPreviewUsageItem[]
  public_plans: BillingPreviewPlan[]
}

export type BillingPublicPlansResponse = {
  plans: BillingPreviewPlan[]
}

export type BillingCapabilities = {
  enabled: boolean
  mode: 'off' | 'admin_only' | 'visible' | 'checkout' | 'enforced' | string
  adminUiEnabled: boolean
  userUiEnabled: boolean
  checkoutEnabled: boolean
  paymentsEnabled: boolean
  enforcementEnabled: boolean
  usageTrackingEnabled: boolean
  provider: string
  providerMode: string
  defaultPlan: string
  failOpen: boolean
}

export type BillingCapabilitiesResponse = {
  billing: BillingCapabilities
}

export type BillingCheckoutResponse = {
  invoice_id: number
  payment_id: number
  confirmation_url: string
}

export type BillingPaymentRefreshResponse = {
  payment: {
    id: number
    status: 'pending' | 'paid' | 'failed' | 'canceled' | string
    amount: number
    currency: string
  }
  invoice: {
    id: number
    status: 'pending' | 'paid' | 'failed' | 'canceled' | string
  } | null
  subscription: {
    status: string
    plan_code: string
    current_period_end?: string | null
  } | null
  message: string
}

export async function getMyBillingPreview(): Promise<BillingPreview> {
  const { data } = await api.get('/api/billing/me')
  return data
}

export async function getBillingCapabilities(): Promise<BillingCapabilitiesResponse> {
  const { data } = await api.get('/api/billing/capabilities')
  return data
}

export async function getBillingPlans(): Promise<BillingPublicPlansResponse> {
  const { data } = await api.get('/api/billing/plans')
  return data
}

export async function createBillingCheckout(planCode: string): Promise<BillingCheckoutResponse> {
  const { data } = await api.post('/api/billing/checkout', {
    plan_code: planCode,
  })
  return data
}

export async function refreshBillingPayment(paymentId: number | string): Promise<BillingPaymentRefreshResponse> {
  const { data } = await api.post(`/api/billing/payments/${paymentId}/refresh`)
  return data
}
