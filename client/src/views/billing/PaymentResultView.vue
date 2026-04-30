<template>
  <PageContainer class="payment-result-page">
    <PageHeader
      title="Результат оплаты"
      subtitle="Проверяем статус платежа и обновляем доступ к тарифу."
    />

    <SectionCard variant="flat" class="payment-result-card">
      <AppStateBlock
        v-if="loading"
        title="Проверяем статус оплаты..."
        description="Обычно это занимает несколько секунд."
        loading
      />

      <AppStateBlock
        v-else-if="!invoiceId"
        title="Не удалось определить платёж"
        description="Вернитесь к тарифам и попробуйте открыть статус оплаты ещё раз."
        icon="mdi-alert-circle-outline"
        tone="warning"
      >
        <template #actions>
          <v-btn color="primary" variant="tonal" @click="goToBilling">
            Вернуться к тарифам
          </v-btn>
        </template>
      </AppStateBlock>

      <AppStateBlock
        v-else-if="error"
        title="Произошла ошибка проверки"
        :description="error"
        icon="mdi-alert-circle-outline"
        tone="error"
      >
        <template #actions>
          <v-btn color="primary" variant="tonal" prepend-icon="mdi-refresh" @click="loadResult">
            Проверить ещё раз
          </v-btn>
          <v-btn variant="text" @click="goToBilling">
            Вернуться к тарифам
          </v-btn>
        </template>
      </AppStateBlock>

      <template v-else-if="result">
        <div class="payment-result-status" :class="`payment-result-status--${statusTone}`">
          <v-icon :icon="statusIcon" size="36" />
          <div>
            <h2>{{ result.title }}</h2>
            <p>{{ result.message }}</p>
          </div>
        </div>

        <div v-if="result.invoice" class="payment-result-summary">
          <div>
            <span>Тариф</span>
            <strong>{{ result.invoice.plan_name }}</strong>
          </div>
          <div>
            <span>Сумма</span>
            <strong>{{ amountLabel }}</strong>
          </div>
          <div v-if="periodEndLabel">
            <span>Доступ действует до</span>
            <strong>{{ periodEndLabel }}</strong>
          </div>
        </div>

        <div class="payment-result-actions">
          <v-btn
            v-if="result.status === 'pending'"
            color="primary"
            variant="tonal"
            prepend-icon="mdi-refresh"
            :loading="loading"
            @click="loadResult"
          >
            Обновить статус платежа
          </v-btn>
          <v-btn color="primary" @click="goToBilling">
            Перейти к тарифам
          </v-btn>
          <v-btn variant="text" @click="goToProjects">
            Перейти в проекты
          </v-btn>
        </div>
      </template>
    </SectionCard>
  </PageContainer>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  getBillingPaymentResult,
  type BillingPaymentResultResponse,
} from '@/api/billing'
import AppStateBlock from '@/components/layout/AppStateBlock.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const error = ref('')
const result = ref<BillingPaymentResultResponse | null>(null)

const invoiceId = computed(() => {
  const value = route.query.invoice_id
  return Array.isArray(value) ? value[0] : value
})

const statusTone = computed(() => {
  if (result.value?.status === 'paid') return 'success'
  if (result.value?.status === 'pending') return 'pending'
  if (result.value?.status === 'not_found') return 'warning'
  return 'error'
})

const statusIcon = computed(() => {
  if (result.value?.status === 'paid') return 'mdi-check-circle-outline'
  if (result.value?.status === 'pending') return 'mdi-timer-sand'
  if (result.value?.status === 'not_found') return 'mdi-help-circle-outline'
  return 'mdi-alert-circle-outline'
})

const amountLabel = computed(() => {
  const invoice = result.value?.invoice
  if (!invoice) return ''

  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: invoice.currency || 'RUB',
    maximumFractionDigits: 0,
  }).format(Number(invoice.amount || 0) / 100)
})

const periodEndLabel = computed(() => {
  const value = result.value?.subscription?.period_ends_at
  if (!value) return ''

  return new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(value))
})

onMounted(() => {
  if (invoiceId.value) {
    void loadResult()
  }
})

async function loadResult() {
  if (!invoiceId.value) return

  loading.value = true
  error.value = ''

  try {
    result.value = await getBillingPaymentResult(invoiceId.value)
  } catch (err: any) {
    error.value = err?.response?.data?.message || 'Не удалось проверить статус оплаты. Попробуйте позже.'
  } finally {
    loading.value = false
  }
}

function goToBilling() {
  void router.push({ name: 'settings-billing' })
}

function goToProjects() {
  void router.push({ name: 'projects' })
}
</script>

<style scoped>
.payment-result-page {
  max-width: 760px;
}

.payment-result-card {
  border-radius: 18px;
}

.payment-result-status {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 16px;
  align-items: start;
  padding: 18px;
  border-radius: 16px;
  background: rgba(var(--v-theme-surface-container-low), 0.82);
}

.payment-result-status h2 {
  margin: 0;
  font-size: 1.35rem;
  line-height: 1.25;
}

.payment-result-status p {
  margin: 8px 0 0;
  color: rgb(var(--v-theme-on-surface-variant));
}

.payment-result-status--success .v-icon {
  color: rgb(var(--v-theme-success));
}

.payment-result-status--pending .v-icon,
.payment-result-status--warning .v-icon {
  color: rgb(var(--v-theme-warning));
}

.payment-result-status--error .v-icon {
  color: rgb(var(--v-theme-error));
}

.payment-result-summary {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin-top: 16px;
}

.payment-result-summary div {
  display: grid;
  gap: 4px;
  min-width: 0;
  padding: 12px;
  border-radius: 14px;
  background: rgba(var(--v-theme-surface), 0.58);
}

.payment-result-summary span {
  color: rgb(var(--v-theme-on-surface-variant));
  font-size: 0.78rem;
}

.payment-result-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 18px;
}

@media (max-width: 600px) {
  .payment-result-status,
  .payment-result-summary {
    grid-template-columns: 1fr;
  }

  .payment-result-actions {
    flex-direction: column;
  }
}
</style>
