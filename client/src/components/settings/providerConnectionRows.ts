import type { AuthMethodsResponse, LinkedProvider } from '@/api/auth'

export interface ProviderConnectionRow {
  provider: string
  label: string
  configured: boolean
  linked: boolean
  canUnlink: boolean
  action: 'connect' | 'disconnect' | 'none'
  statusText: string
  identityHint: string
}

export function buildProviderConnectionRows(authMethods: AuthMethodsResponse | null): ProviderConnectionRow[] {
  if (!authMethods) return []

  return authMethods.supported_providers.map((providerMeta) => {
    const linkedAccount = authMethods.linked_providers.find((item: LinkedProvider) => item.provider === providerMeta.provider)
    const linked = !!linkedAccount
    const configured = !!providerMeta.configured

    let statusText = 'Временно недоступен'
    if (configured && linked) statusText = `${providerMeta.label} подключён`
    if (configured && !linked) statusText = `${providerMeta.label} не подключён`

    return {
      provider: providerMeta.provider,
      label: providerMeta.label,
      configured,
      linked,
      canUnlink: linkedAccount?.can_unlink ?? false,
      action: linked ? 'disconnect' : configured ? 'connect' : 'none',
      statusText,
      identityHint: linked
        ? linkedAccount?.provider_email || linkedAccount?.provider_username || linkedAccount?.provider_user_id || ''
        : '',
    }
  })
}
