<template>
  <div class="billing-plan-card" :class="{ 'billing-plan-card--current': isCurrent }">
    <div class="billing-plan-card__header">
      <div class="billing-plan-card__heading">
        <h3>{{ displayName }}</h3>
        <div class="billing-plan-card__price">{{ priceLabel }}</div>
      </div>
      <StatusChip
        v-if="isCurrent"
        status="active"
        label="Текущий тариф"
        color="success"
        size="x-small"
      />
    </div>

    <p v-if="plan.description" class="billing-plan-card__description">
      {{ plan.description }}
    </p>

    <ul class="billing-plan-card__limits">
      <li v-for="item in visibleLimits" :key="item.code">
        <v-icon icon="mdi-check-circle-outline" size="16" />
        <span>{{ limitLine(item) }}</span>
      </li>
    </ul>

    <v-btn
      v-if="showAction"
      block
      class="billing-plan-card__action"
      color="primary"
      variant="tonal"
      :disabled="!canCheckout"
      :loading="loading"
      @click="$emit('checkout', plan)"
    >
      {{ actionLabel }}
    </v-btn>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { BillingPreviewPlan } from '@/api/billing'
import StatusChip from '@/components/layout/StatusChip.vue'

const props = defineProps<{
  plan: BillingPreviewPlan
  checkoutEnabled?: boolean
  paymentsEnabled?: boolean
  loading?: boolean
}>()

defineEmits<{
  checkout: [plan: BillingPreviewPlan]
}>()

const preferredLimitOrder = [
  'projects.active',
  'pdf.generated',
  'evidence.runs',
  'chrome.captures',
  'storage.uploaded',
]

const isCurrent = computed(() => Boolean(props.plan.is_current))
const showAction = computed(() => true)
const hasPositivePrice = computed(() => {
  const priceMinor = props.plan.price_minor ?? props.plan.price
  return Number(priceMinor ?? 0) > 0
})
const checkoutAvailable = computed(() => (
  !isCurrent.value
  && props.checkoutEnabled === true
  && props.paymentsEnabled === true
  && hasPositivePrice.value
))
const canCheckout = computed(() => checkoutAvailable.value && !props.loading)
const actionLabel = computed(() => {
  if (isCurrent.value) return 'Текущий тариф'
  if (checkoutAvailable.value) return 'Оплатить'

  return 'Будет доступно после запуска оплаты'
})

const displayName = computed(() => {
  if (props.plan.code === 'sandbox_pro_month') return 'Профессиональный'

  return props.plan.name
})

const priceLabel = computed(() => {
  const priceMinor = props.plan.price_minor ?? props.plan.price

  if (priceMinor === null || priceMinor === undefined) {
    return 'Цена будет указана позже'
  }

  if (Number(priceMinor) === 0) {
    return 'Бесплатно'
  }

  const amount = Number(priceMinor) / 100
  const price = new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: props.plan.currency || 'RUB',
    maximumFractionDigits: 0,
  }).format(amount)

  const period = periodLabel(props.plan.period || props.plan.billing_period || '')

  return period ? `${price} ${period}` : price
})

const visibleLimits = computed(() => {
  const limits = props.plan.limits || []

  return [...limits]
    .sort((a, b) => preferredLimitOrder.indexOf(a.code) - preferredLimitOrder.indexOf(b.code))
    .filter((item) => preferredLimitOrder.includes(item.code))
    .slice(0, 5)
})

function limitLine(item: NonNullable<BillingPreviewPlan['limits']>[number]) {
  const label = item.name || item.label || limitName(item.code)
  if (item.limit === null || Number(item.limit) <= 0) return `${label}: без ограничений`

  const value = formatNumber(Number(item.limit))

  if (item.code === 'storage.uploaded') {
    return `${label}: до ${value} ${item.unit}`
  }

  if (item.code === 'projects.active') {
    return `${label}: до ${value} ${item.unit}`
  }

  return `${label}: до ${value} ${item.unit} в месяц`
}

function periodLabel(period: string) {
  if (period === 'month') return 'в месяц'
  if (period === 'year') return 'в год'
  if (period === 'one_time') return 'разово'

  return ''
}

function limitName(code: string) {
  if (code === 'projects.active') return 'Активные проекты'
  if (code === 'pdf.generated') return 'PDF-документы'
  if (code === 'evidence.runs') return 'Проверки цен'
  if (code === 'chrome.captures') return 'Скриншоты из расширения'
  if (code === 'storage.uploaded') return 'Хранилище файлов'

  return code
}

function formatNumber(value: number) {
  return new Intl.NumberFormat('ru-RU').format(value)
}
</script>

<style scoped>
.billing-plan-card {
  display: flex;
  flex-direction: column;
  min-height: 100%;
  padding: 18px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  border-radius: 18px;
  background: rgba(var(--v-theme-surface-container-low), 0.82);
}

.billing-plan-card--current {
  border-color: rgba(var(--v-theme-primary), 0.35);
  background: linear-gradient(180deg, rgba(var(--v-theme-primary), 0.07), rgba(var(--v-theme-surface-container-low), 0.9));
}

.billing-plan-card__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.billing-plan-card__heading {
  min-width: 0;
}

.billing-plan-card__heading h3 {
  margin: 0;
  font-size: 1.12rem;
  line-height: 1.25;
}

.billing-plan-card__price {
  margin-top: 8px;
  color: rgb(var(--v-theme-primary));
  font-size: 1.08rem;
  font-weight: 750;
}

.billing-plan-card__description {
  margin: 12px 0 0;
  color: rgb(var(--v-theme-on-surface-variant));
  font-size: 0.9rem;
  line-height: 1.45;
}

.billing-plan-card__limits {
  display: grid;
  gap: 9px;
  margin: 16px 0 18px;
  padding: 0;
  list-style: none;
}

.billing-plan-card__limits li {
  display: grid;
  grid-template-columns: 18px minmax(0, 1fr);
  gap: 8px;
  align-items: start;
  color: rgb(var(--v-theme-on-surface-variant));
  font-size: 0.88rem;
  line-height: 1.35;
}

.billing-plan-card__limits .v-icon {
  margin-top: 1px;
  color: rgb(var(--v-theme-primary));
}

.billing-plan-card__action {
  margin-top: auto;
  min-height: 40px;
}

.billing-plan-card__action :deep(.v-btn__content) {
  white-space: normal;
  line-height: 1.2;
  text-align: center;
}

@media (max-width: 600px) {
  .billing-plan-card {
    padding: 16px;
  }

  .billing-plan-card__header {
    flex-direction: column;
  }
}
</style>
