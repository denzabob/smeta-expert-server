import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { getBillingCapabilities, type BillingCapabilities } from '@/api/billing'

export const useBillingCapabilitiesStore = defineStore('billingCapabilities', () => {
  const capabilities = ref<BillingCapabilities | null>(null)
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref('')

  const billingEnabled = computed(() => capabilities.value?.enabled === true)
  const billingMode = computed(() => capabilities.value?.mode || 'off')
  const adminUiEnabled = computed(() => capabilities.value?.adminUiEnabled === true)
  const userUiEnabled = computed(() => capabilities.value?.userUiEnabled === true)
  const checkoutEnabled = computed(() => capabilities.value?.checkoutEnabled === true)
  const paymentsEnabled = computed(() => capabilities.value?.paymentsEnabled === true)
  const enforcementEnabled = computed(() => capabilities.value?.enforcementEnabled === true)
  const usageTrackingEnabled = computed(() => capabilities.value?.usageTrackingEnabled === true)
  const provider = computed(() => capabilities.value?.provider || '—')
  const providerMode = computed(() => capabilities.value?.providerMode || '—')
  const defaultPlan = computed(() => capabilities.value?.defaultPlan || '—')
  const failOpen = computed(() => capabilities.value?.failOpen === true)

  async function load(force = false) {
    if (loading.value) return
    if (loaded.value && !force) return

    loading.value = true
    error.value = ''

    try {
      const response = await getBillingCapabilities()
      capabilities.value = response.billing
      loaded.value = true
    } catch (err: any) {
      capabilities.value = null
      loaded.value = true
      error.value = err?.response?.data?.message || err?.message || 'Не удалось загрузить состояние биллинга'
    } finally {
      loading.value = false
    }
  }

  function reset() {
    capabilities.value = null
    loading.value = false
    loaded.value = false
    error.value = ''
  }

  return {
    capabilities,
    loading,
    loaded,
    error,
    billingEnabled,
    billingMode,
    adminUiEnabled,
    userUiEnabled,
    checkoutEnabled,
    paymentsEnabled,
    enforcementEnabled,
    usageTrackingEnabled,
    provider,
    providerMode,
    defaultPlan,
    failOpen,
    load,
    reset,
  }
})
