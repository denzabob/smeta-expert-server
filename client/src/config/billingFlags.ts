export const billingFlags = {
  enabled: import.meta.env.VITE_BILLING_ENABLED === 'true',
  userUiEnabled: import.meta.env.VITE_BILLING_USER_UI_ENABLED === 'true',
  adminUiEnabled: import.meta.env.VITE_BILLING_ADMIN_UI_ENABLED !== 'false',
  checkoutEnabled: import.meta.env.VITE_BILLING_CHECKOUT_ENABLED === 'true',
  enforcementEnabled: import.meta.env.VITE_BILLING_ENFORCEMENT_ENABLED === 'true',
}
