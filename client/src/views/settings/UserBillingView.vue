<template>
  <PageContainer class="user-billing-page">
    <PageHeader
      title="Тариф и лимиты"
      subtitle="Здесь показан ваш текущий тариф и использование сервиса."
    />

    <v-alert type="info" variant="tonal" density="comfortable" class="mb-4 user-billing-alert">
      {{ billingIntroText }}
    </v-alert>

    <v-alert
      v-if="paymentReturnNotice"
      :type="paymentReturnNotice.type"
      variant="tonal"
      density="comfortable"
      class="mb-4 user-billing-alert"
    >
      {{ paymentReturnNotice.message }}
    </v-alert>

    <AppStateBlock
      v-if="loading"
      title="Загружаем тариф и использование"
      description="Данные подготавливаются."
      loading
    />

    <AppStateBlock
      v-else-if="error"
      title="Не удалось загрузить данные"
      :description="error"
      icon="mdi-alert-circle-outline"
      tone="error"
    >
      <template #actions>
        <v-btn color="primary" variant="tonal" prepend-icon="mdi-refresh" @click="loadBilling">
          Повторить
        </v-btn>
      </template>
    </AppStateBlock>

    <template v-else-if="preview">
      <SectionCard variant="flat" class="billing-card billing-card--primary mb-4">
        <div class="billing-card__header">
          <div>
            <div class="billing-eyebrow">Текущий тариф</div>
            <h2 class="billing-plan-title">{{ currentPlanName }}</h2>
          </div>
          <StatusChip status="info" label="Тестовый период" color="info" />
        </div>

        <p class="billing-description">
          {{ currentPlanDescription }}
        </p>

        <div class="billing-status-grid">
          <div class="billing-status-item">
            <span>Статус</span>
            <strong>{{ subscriptionStatusLabel(preview.subscription.status) }}</strong>
          </div>
          <div class="billing-status-item">
            <span>Оплата</span>
            <strong>{{ paymentStatusLabel }}</strong>
          </div>
          <div class="billing-status-item">
            <span>Ограничения</span>
            <strong>{{ limitsStatusLabel }}</strong>
          </div>
        </div>

        <div v-if="hasSubscriptionPeriod" class="billing-period">
          Период действия: {{ subscriptionPeriodLabel }}
        </div>
      </SectionCard>

      <SectionCard title="Использование лимитов" variant="flat" class="mb-4">
        <template #subtitle>
          В тестовом режиме превышение не ограничивает работу.
        </template>

        <div class="usage-list">
          <BillingUsageLimitRow
            v-for="item in usageRows"
            :key="item.code"
            :label="item.label"
            :description="item.description"
            :used="item.used"
            :limit="item.limit"
            :unit="item.unit"
          />
        </div>
      </SectionCard>

      <SectionCard title="Доступные тарифы" variant="flat">
        <template #subtitle>
          {{ publicPlansSubtitle }}
        </template>

        <AppStateBlock
          v-if="!preview.public_plans.length"
          title="Тарифы скоро появятся"
          description="Сейчас сервис работает в тестовом режиме. После публикации тарифов они появятся здесь."
          icon="mdi-credit-card-off-outline"
          density="compact"
        />

        <div v-else class="billing-plans-grid">
          <BillingPlanCard
            v-for="plan in preview.public_plans"
            :key="plan.code"
            :plan="plan"
            :checkout-enabled="billingCapabilities.checkoutEnabled"
            :payments-enabled="billingCapabilities.paymentsEnabled"
            :current-plan-price="preview.current_plan.price ?? preview.current_plan.price_minor ?? null"
            :current-plan-code="preview.current_plan.code"
            :loading="checkoutLoadingPlanCode === plan.code"
            @checkout="openCheckoutConfirm"
          />
        </div>
      </SectionCard>
    </template>

    <v-dialog v-model="checkoutConfirmDialog" max-width="520">
      <v-card class="checkout-confirm-card">
        <v-card-title>Переход к оплате</v-card-title>
        <v-card-text>
          <p class="checkout-confirm-card__text">
            Вы переходите к оплате тарифа “{{ selectedCheckoutPlanName }}”.
          </p>
          <p class="checkout-confirm-card__text">
            {{ checkoutConfirmDescription }}
          </p>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn
            variant="text"
            :disabled="checkoutLoadingPlanCode !== null"
            @click="closeCheckoutConfirm"
          >
            Отмена
          </v-btn>
          <v-btn
            color="primary"
            :loading="checkoutLoadingPlanCode !== null"
            :disabled="!selectedCheckoutPlan || checkoutLoadingPlanCode !== null"
            @click="confirmCheckout"
          >
            Перейти к оплате
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </PageContainer>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import {
  createBillingCheckout,
  getMyBillingPreview,
  refreshBillingPayment,
  type BillingPreview,
  type BillingPreviewPlan,
  type BillingPreviewUsageItem,
} from '@/api/billing'
import { useBillingCapabilitiesStore } from '@/stores/billingCapabilities'
import BillingPlanCard from '@/components/billing/BillingPlanCard.vue'
import BillingUsageLimitRow from '@/components/billing/BillingUsageLimitRow.vue'
import AppStateBlock from '@/components/layout/AppStateBlock.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import StatusChip from '@/components/layout/StatusChip.vue'

const loading = ref(true)
const error = ref('')
const preview = ref<BillingPreview | null>(null)
const billingCapabilities = useBillingCapabilitiesStore()
const route = useRoute()
const checkoutLoadingPlanCode = ref<string | null>(null)
const checkoutConfirmDialog = ref(false)
const selectedCheckoutPlan = ref<BillingPreviewPlan | null>(null)
const paymentReturnNotice = ref<{ type: 'info' | 'success' | 'warning' | 'error', message: string } | null>(null)

const lastPaymentStorageKey = 'prismcore.billing.lastPaymentId'

const usageDefinitions = [
  {
    code: 'projects.owned',
    label: 'Проекты в аккаунте',
    unit: 'шт.',
    description: 'Все проекты в аккаунте, включая архивные.',
  },
  {
    code: 'pdf.generated',
    label: 'PDF-документы',
    unit: 'шт.',
    description: 'Сформированные документы за текущий месяц.',
  },
  {
    code: 'evidence.runs',
    label: 'Проверки цен',
    unit: 'шт.',
    description: 'Проверки и обоснования цен за текущий месяц.',
  },
  {
    code: 'chrome.captures',
    label: 'Скриншоты из расширения',
    unit: 'шт.',
    description: 'Снимки страниц, сохранённые через расширение за текущий месяц.',
  },
  {
    code: 'storage.uploaded',
    label: 'Хранилище файлов',
    unit: 'МБ',
    description: 'Объём загруженных файлов за текущий месяц.',
  },
]

const currentPlanName = computed(() => {
  const plan = preview.value?.current_plan
  if (!plan) return 'Тестовый тариф'
  if (plan.code === 'legacy_unlimited' || plan.name === 'Legacy Unlimited') return 'Тестовый тариф'

  return plan.name
})

const currentPlanDescription = computed(() => {
  const plan = preview.value?.current_plan
  if (!plan || plan.code === 'legacy_unlimited' || plan.name === 'Legacy Unlimited') {
    return 'Без ограничений на время тестового периода.'
  }

  return plan.description || 'Доступ настроен на время тестового периода.'
})

const billingIntroText = computed(() => {
  if (billingCapabilities.checkoutEnabled && billingCapabilities.paymentsEnabled) {
    return 'Оплата доступна. Ограничения пока не применяются до полного режима.'
  }

  return 'Сейчас действует тестовый период. Оплата и ограничения пока отключены.'
})
const paymentStatusLabel = computed(() => billingCapabilities.checkoutEnabled ? 'доступна' : 'пока отключена')
const limitsStatusLabel = computed(() => billingCapabilities.enforcementEnabled ? 'применяются' : 'не применяются')
const publicPlansSubtitle = computed(() => {
  if (!preview.value?.public_plans.length) {
    return 'Сейчас сервис работает в тестовом режиме. После публикации тарифов они появятся здесь.'
  }

  if (billingCapabilities.checkoutEnabled && billingCapabilities.paymentsEnabled) {
    return 'Выберите тариф и перейдите к оплате.'
  }

  return 'Тарифы показаны для ознакомления. Оплата пока отключена.'
})
const selectedCheckoutPlanName = computed(() => selectedCheckoutPlan.value?.name || 'выбранного тарифа')
const checkoutConfirmDescription = computed(() => {
  if (!selectedCheckoutPlan.value) {
    return 'После успешной оплаты тариф будет активирован автоматически.'
  }

  if (isUpgradePlan(selectedCheckoutPlan.value)) {
    return 'После оплаты новый тариф начнёт действовать сразу. Текущий тариф будет заменён.'
  }

  return 'После успешной оплаты тариф будет активирован автоматически. Статус оплаты можно будет увидеть после возврата в сервис.'
})

const hasSubscriptionPeriod = computed(() => {
  const subscription = preview.value?.subscription
  return Boolean(subscription?.current_period_start || subscription?.current_period_end)
})

const subscriptionPeriodLabel = computed(() => {
  const subscription = preview.value?.subscription
  if (!subscription?.current_period_start && !subscription?.current_period_end) {
    return 'без срока'
  }

  return `${formatDate(subscription.current_period_start)} — ${formatDate(subscription.current_period_end)}`
})

const usageRows = computed(() => {
  const items = preview.value?.usage ?? []

  return usageDefinitions.map((definition) => {
    const item = items.find((usageItem) => usageItem.code === definition.code)
      || (definition.code === 'projects.owned'
        ? items.find((usageItem) => usageItem.code === 'projects.active')
        : undefined)

    return {
      ...definition,
      used: Number(item?.used ?? 0),
      limit: normalizeLimit(item?.limit ?? null),
      unit: item?.unit || definition.unit,
    }
  })
})

onMounted(async () => {
  const isPaymentReturn = route.query.payment_return === '1'
  if (isPaymentReturn) {
    paymentReturnNotice.value = {
      type: 'info',
      message: 'Проверяем статус оплаты…',
    }
  }

  await loadBilling()

  if (isPaymentReturn) {
    await handlePaymentReturn()
  }
})

async function loadBilling() {
  loading.value = true
  error.value = ''

  try {
    await billingCapabilities.load()
    preview.value = await getMyBillingPreview()
  } catch (err: any) {
    error.value = err?.response?.data?.message || 'Попробуйте обновить страницу позже.'
  } finally {
    loading.value = false
  }
}

function openCheckoutConfirm(plan: BillingPreviewPlan) {
  if (!canStartCheckout(plan)) {
    return
  }

  selectedCheckoutPlan.value = plan
  checkoutConfirmDialog.value = true
}

function closeCheckoutConfirm() {
  if (checkoutLoadingPlanCode.value) return

  checkoutConfirmDialog.value = false
  selectedCheckoutPlan.value = null
}

async function confirmCheckout() {
  if (!selectedCheckoutPlan.value) return

  await startCheckout(selectedCheckoutPlan.value)
}

function canStartCheckout(plan: BillingPreviewPlan) {
  if (!billingCapabilities.checkoutEnabled || !billingCapabilities.paymentsEnabled || checkoutLoadingPlanCode.value) {
    return false
  }

  const priceMinor = plan.price_minor ?? plan.price
  if (plan.is_current || Number(priceMinor ?? 0) <= 0 || isDowngradePlan(plan) || isLateralPlanChange(plan)) {
    return false
  }

  return true
}

function currentPlanPriceMinor() {
  return Number((preview.value?.current_plan.price ?? preview.value?.current_plan.price_minor) ?? 0)
}

function planPriceMinor(plan: BillingPreviewPlan) {
  return Number((plan.price_minor ?? plan.price) ?? 0)
}

function isUpgradePlan(plan: BillingPreviewPlan) {
  return currentPlanPriceMinor() > 0 && planPriceMinor(plan) > currentPlanPriceMinor()
}

function isDowngradePlan(plan: BillingPreviewPlan) {
  return currentPlanPriceMinor() > 0 && planPriceMinor(plan) > 0 && planPriceMinor(plan) < currentPlanPriceMinor()
}

function isLateralPlanChange(plan: BillingPreviewPlan) {
  return currentPlanPriceMinor() > 0
    && planPriceMinor(plan) > 0
    && preview.value?.current_plan.code !== plan.code
    && planPriceMinor(plan) === currentPlanPriceMinor()
}

async function startCheckout(plan: BillingPreviewPlan) {
  if (!canStartCheckout(plan)) return

  checkoutLoadingPlanCode.value = plan.code
  error.value = ''

  try {
    const checkout = await createBillingCheckout(plan.code)

    if (!checkout.confirmation_url) {
      throw new Error('Не удалось получить ссылку на оплату.')
    }

    rememberLastPaymentId(checkout.payment_id)
    window.location.assign(checkout.confirmation_url)
  } catch (err: any) {
    paymentReturnNotice.value = {
      type: 'error',
      message: checkoutErrorMessage(err),
    }
  } finally {
    checkoutLoadingPlanCode.value = null
    checkoutConfirmDialog.value = false
    selectedCheckoutPlan.value = null
  }
}

function checkoutErrorMessage(err: any) {
  const status = err?.response?.status
  const message = String(err?.response?.data?.message || '')
  const planErrors = err?.response?.data?.errors?.plan_code
  const code = err?.response?.data?.code

  if (Array.isArray(planErrors) && planErrors[0]) {
    return String(planErrors[0])
  }

  if (code === 'current_plan') return 'Вы уже используете этот тариф.'
  if (code === 'downgrade_not_available') return 'Смена на этот тариф будет доступна после окончания текущего периода.'
  if (code === 'free_plan') return 'Бесплатный тариф не требует оплаты.'

  if (status === 403 || message.includes('недоступ')) {
    return 'Оплата временно отключена.'
  }

  if (status === 404) {
    return 'Этот тариф сейчас недоступен.'
  }

  if (status === 409) {
    return 'Вы уже используете этот тариф.'
  }

  if (message && !/exception|stack|provider|invoice|webhook/i.test(message)) {
    return message
  }

  return 'Не удалось создать оплату. Попробуйте ещё раз.'
}

async function handlePaymentReturn() {
  const paymentId = readLastPaymentId()

  if (!paymentId) {
    paymentReturnNotice.value = {
      type: 'warning',
      message: 'Проверяем оплату. Если статус не обновится, обновите страницу через несколько минут.',
    }
    return
  }

  try {
    const result = await refreshBillingPayment(paymentId)
    await loadBilling()

    if (result.payment.status === 'paid' || result.invoice?.status === 'paid') {
      forgetLastPaymentId()
      paymentReturnNotice.value = {
        type: 'success',
        message: 'Оплата прошла успешно. Тариф активирован.',
      }
      return
    }

    if (result.payment.status === 'canceled' || result.payment.status === 'failed') {
      forgetLastPaymentId()
      paymentReturnNotice.value = {
        type: 'error',
        message: 'Оплата не завершена. Вы можете попробовать снова.',
      }
      return
    }

    paymentReturnNotice.value = {
      type: 'warning',
      message: 'Платёж ещё обрабатывается. Обновите статус через несколько минут.',
    }
  } catch (err: any) {
    paymentReturnNotice.value = {
      type: 'warning',
      message: err?.response?.data?.message || 'Не удалось обновить статус оплаты. Попробуйте обновить страницу позже.',
    }
  }
}

function rememberLastPaymentId(paymentId: number | string) {
  try {
    window.sessionStorage.setItem(lastPaymentStorageKey, String(paymentId))
  } catch {
    // Storage can be unavailable in private mode; checkout itself should still proceed.
  }
}

function readLastPaymentId() {
  try {
    return window.sessionStorage.getItem(lastPaymentStorageKey)
  } catch {
    return null
  }
}

function forgetLastPaymentId() {
  try {
    window.sessionStorage.removeItem(lastPaymentStorageKey)
  } catch {
    // Nothing to clean up if storage is unavailable.
  }
}

function subscriptionStatusLabel(status: string) {
  if (status === 'active') return 'активен'
  if (status === 'trialing') return 'тестовый доступ'
  if (status === 'canceled') return 'отменён'
  if (status === 'replaced') return 'заменён'
  return status || 'активен'
}

function formatDate(value?: string | null) {
  if (!value) return 'без срока'

  return new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(value))
}

function normalizeLimit(value: BillingPreviewUsageItem['limit']) {
  if (value === null || Number(value) <= 0) return null

  return Number(value)
}
</script>

<style scoped>
.user-billing-page {
  max-width: 920px;
}

.user-billing-alert {
  border-radius: 12px;
}

.billing-card,
.billing-card--primary {
  height: 100%;
  border-radius: 16px;
  background: rgba(var(--v-theme-surface-container-low), 0.76);
}

.billing-card--primary {
  background: linear-gradient(135deg, rgba(var(--v-theme-primary), 0.08), rgba(var(--v-theme-surface-container-low), 0.9));
}

.billing-card__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
}

.billing-eyebrow {
  margin-bottom: 4px;
  color: rgb(var(--v-theme-on-surface-variant));
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
}

.billing-plan-title {
  margin: 0;
  font-size: 1.45rem;
  line-height: 1.25;
}

.billing-description {
  margin: 0 0 18px;
  color: rgb(var(--v-theme-on-surface-variant));
  line-height: 1.55;
}

.billing-status-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.billing-status-item {
  display: grid;
  gap: 4px;
  min-width: 0;
  padding: 12px;
  border-radius: 14px;
  background: rgba(var(--v-theme-surface), 0.58);
}

.billing-status-item span {
  color: rgb(var(--v-theme-on-surface-variant));
  font-size: 0.78rem;
}

.billing-period {
  margin-top: 14px;
  color: rgb(var(--v-theme-on-surface-variant));
  font-size: 0.82rem;
}

.usage-list {
  display: grid;
}

.billing-plans-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 360px));
  gap: 16px;
  align-items: stretch;
}

.checkout-confirm-card {
  border-radius: 18px;
}

.checkout-confirm-card__text {
  margin: 0 0 10px;
  color: rgb(var(--v-theme-on-surface-variant));
  line-height: 1.5;
}

.checkout-confirm-card__text:last-child {
  margin-bottom: 0;
}

@media (max-width: 960px) {
  .billing-card__header {
    align-items: flex-start;
  }

  .billing-status-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 600px) {
  .billing-card__header {
    flex-direction: column;
  }
}
</style>
