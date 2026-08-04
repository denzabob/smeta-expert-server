export type PriceIndicesCapabilityStatus =
  | 'idle'
  | 'loading'
  | 'available'
  | 'forbidden'
  | 'disabled'
  | 'error'

export interface PriceIndicesCapabilities {
  application: 'price_indices'
  enabled: boolean
  access: boolean
  admin_only: boolean
  stage: 'skeleton'
}

export interface PriceIndicesCapabilitiesResponse {
  data: PriceIndicesCapabilities
}

export type ApplicationId = 'estimates' | 'price_indices' | 'admin' | 'parser'

export interface ApplicationMenuItem {
  id: ApplicationId
  label: string
  icon: string
  iconClass: string
  routeName: string
}

export type PriceIndicesGuardDecision = 'allow' | 'forbidden' | 'unavailable'
