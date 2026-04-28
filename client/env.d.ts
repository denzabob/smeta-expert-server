/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_ENABLE_DEV_VISUAL_AUTH?: string
  readonly VITE_BILLING_ENABLED?: string
  readonly VITE_BILLING_USER_UI_ENABLED?: string
  readonly VITE_BILLING_ADMIN_UI_ENABLED?: string
  readonly VITE_BILLING_CHECKOUT_ENABLED?: string
  readonly VITE_BILLING_ENFORCEMENT_ENABLED?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
