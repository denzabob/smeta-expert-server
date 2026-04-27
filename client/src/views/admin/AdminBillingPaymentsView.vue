<template>
  <v-container fluid class="admin-billing-payments pa-0">
    <div class="page-header">
      <div>
        <h2 class="text-h5 font-weight-medium mb-1">Платежи</h2>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Скрытый админский раздел для тестирования invoice, payments и webhook-событий. Пользователям не отображается.
        </p>
      </div>
      <v-btn
        color="primary"
        variant="tonal"
        prepend-icon="mdi-refresh"
        :loading="loading"
        @click="loadAll"
      >
        Обновить
      </v-btn>
    </div>

    <v-alert
      type="warning"
      variant="tonal"
      density="compact"
      class="mb-4"
    >
      Оплата находится в тестовом режиме. Enforcement и пользовательский checkout отключены.
    </v-alert>

    <v-alert
      v-if="errorMessage"
      type="error"
      variant="tonal"
      density="compact"
      closable
      class="mb-4"
      @click:close="errorMessage = ''"
    >
      {{ errorMessage }}
    </v-alert>

    <v-alert
      v-if="successMessage"
      type="success"
      variant="tonal"
      density="compact"
      closable
      class="mb-4"
      @click:close="successMessage = ''"
    >
      {{ successMessage }}
    </v-alert>

    <v-card class="mb-4" variant="outlined">
      <v-card-title class="text-subtitle-1">Создать тестовый invoice</v-card-title>
      <v-card-text>
        <v-row dense>
          <v-col cols="12" md="3">
            <v-text-field
              v-model.number="invoiceForm.user_id"
              label="User ID"
              type="number"
              min="1"
              density="compact"
              variant="outlined"
              hide-details="auto"
            />
          </v-col>
          <v-col cols="12" md="4">
            <v-select
              v-model="invoiceForm.plan_code"
              :items="planItems"
              label="Тариф"
              density="compact"
              variant="outlined"
              hide-details="auto"
            />
          </v-col>
          <v-col cols="12" md="3">
            <v-select
              v-model="invoiceForm.billing_period"
              :items="periodItems"
              label="Период"
              density="compact"
              variant="outlined"
              hide-details="auto"
            />
          </v-col>
          <v-col cols="12" md="2" class="d-flex align-center">
            <v-btn
              color="primary"
              prepend-icon="mdi-file-plus-outline"
              :loading="creatingInvoice"
              :disabled="!canCreateInvoice"
              block
              @click="handleCreateInvoice"
            >
              Создать
            </v-btn>
          </v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <v-card class="mb-4" variant="outlined">
      <v-card-title class="section-title">
        <span>Invoices</span>
        <v-chip size="small" variant="tonal">{{ invoices.length }}</v-chip>
      </v-card-title>
      <v-table density="compact" class="billing-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>User</th>
            <th>Plan</th>
            <th class="text-right">Amount</th>
            <th>Status</th>
            <th>Created</th>
            <th>Paid</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="invoice in invoices"
            :key="invoice.id"
            :class="{ 'row-selected': invoice.id === selectedInvoiceId }"
          >
            <td>{{ invoice.id }}</td>
            <td>{{ userLabel(invoice.user, invoice.user_id) }}</td>
            <td>{{ invoice.plan_code }}</td>
            <td class="text-right">{{ formatMoneyMinor(invoice.amount_minor, invoice.currency) }}</td>
            <td><v-chip size="small" :color="statusColor(invoice.status)" variant="tonal">{{ invoice.status }}</v-chip></td>
            <td>{{ formatDateTime(invoice.created_at) }}</td>
            <td>{{ formatDateTime(invoice.paid_at) }}</td>
            <td class="text-right">
              <v-btn
                size="small"
                variant="text"
                color="primary"
                :disabled="invoice.status === 'paid'"
                :loading="paymentInvoiceId === invoice.id"
                @click="handleCreatePayment(invoice)"
              >
                Payment
              </v-btn>
              <v-btn
                size="small"
                variant="text"
                @click="openInvoiceDetails(invoice.id)"
              >
                Детали
              </v-btn>
            </td>
          </tr>
          <tr v-if="!invoices.length">
            <td colspan="8" class="empty-cell">Нет invoices</td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <v-card class="mb-4" variant="outlined">
      <v-card-title class="section-title">
        <span>Payments</span>
        <v-chip size="small" variant="tonal">{{ payments.length }}</v-chip>
      </v-card-title>
      <v-table density="compact" class="billing-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Invoice</th>
            <th>Provider</th>
            <th>Provider Payment ID</th>
            <th class="text-right">Amount</th>
            <th>Status</th>
            <th>Created</th>
            <th>Confirmation</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="payment in payments" :key="payment.id">
            <td>{{ payment.id }}</td>
            <td>{{ payment.invoice_id }}</td>
            <td>{{ payment.provider_code }}</td>
            <td class="mono-cell">{{ payment.provider_payment_id || '—' }}</td>
            <td class="text-right">{{ formatMoneyMinor(payment.amount_minor, payment.currency) }}</td>
            <td><v-chip size="small" :color="statusColor(payment.status)" variant="tonal">{{ payment.status }}</v-chip></td>
            <td>{{ formatDateTime(payment.created_at) }}</td>
            <td>
              <v-btn
                v-if="payment.confirmation_url"
                size="small"
                variant="tonal"
                color="primary"
                append-icon="mdi-open-in-new"
                @click="openConfirmation(payment.confirmation_url)"
              >
                Открыть оплату
              </v-btn>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td class="text-right">
              <v-btn
                size="small"
                variant="text"
                @click="openPaymentDetails(payment.id)"
              >
                Детали
              </v-btn>
            </td>
          </tr>
          <tr v-if="!payments.length">
            <td colspan="9" class="empty-cell">Нет payments</td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <v-card variant="outlined">
      <v-card-title class="section-title">
        <span>Provider events</span>
        <v-chip size="small" variant="tonal">{{ providerEvents.length }}</v-chip>
      </v-card-title>
      <v-table density="compact" class="billing-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Provider</th>
            <th>Event</th>
            <th>Provider Payment ID</th>
            <th>Status</th>
            <th>Processed</th>
            <th>Created</th>
            <th>Error</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="event in providerEvents" :key="event.id">
            <td>{{ event.id }}</td>
            <td>{{ event.provider_code }}</td>
            <td>{{ event.event_type }}</td>
            <td class="mono-cell">{{ event.provider_payment_id || '—' }}</td>
            <td><v-chip size="small" :color="statusColor(event.processing_status)" variant="tonal">{{ event.processing_status }}</v-chip></td>
            <td>{{ formatDateTime(event.processed_at) }}</td>
            <td>{{ formatDateTime(event.created_at) }}</td>
            <td class="error-cell">{{ event.processing_error || '—' }}</td>
            <td class="text-right">
              <v-btn
                size="small"
                variant="text"
                @click="openProviderEventDetails(event.id)"
              >
                Детали
              </v-btn>
            </td>
          </tr>
          <tr v-if="!providerEvents.length">
            <td colspan="9" class="empty-cell">Нет provider events</td>
          </tr>
        </tbody>
      </v-table>
    </v-card>

    <v-navigation-drawer
      v-model="detailsDrawerOpen"
      temporary
      location="right"
      width="520"
      class="details-drawer"
    >
      <div class="drawer-header">
        <div>
          <div class="text-subtitle-1 font-weight-medium">{{ detailsTitle }}</div>
          <div class="text-caption text-medium-emphasis">Read-only diagnostics</div>
        </div>
        <v-btn icon="mdi-close" variant="text" size="small" @click="closeDetails" />
      </div>
      <v-divider />

      <div class="drawer-body">
        <v-progress-linear v-if="detailsLoading" indeterminate color="primary" class="mb-3" />

        <template v-if="detailsKind === 'invoice' && invoiceDetails">
          <v-card variant="tonal" class="mb-3">
            <v-card-text>
              <div class="detail-grid">
                <span>ID</span><strong>#{{ invoiceDetails.invoice.id }}</strong>
                <span>UUID</span><code>{{ invoiceDetails.invoice.uuid }}</code>
                <span>User</span><strong>{{ userLabel(invoiceDetails.invoice.user, invoiceDetails.invoice.user_id) }}</strong>
                <span>Plan</span><strong>{{ invoiceDetails.invoice.plan_code }}</strong>
                <span>Amount</span><strong>{{ formatMoneyMinor(invoiceDetails.invoice.amount_minor, invoiceDetails.invoice.currency) }}</strong>
                <span>Status</span><v-chip size="small" :color="statusColor(invoiceDetails.invoice.status)" variant="tonal">{{ invoiceDetails.invoice.status }}</v-chip>
                <span>Period</span><strong>{{ formatDateTime(invoiceDetails.invoice.period_start) }} — {{ formatDateTime(invoiceDetails.invoice.period_end) }}</strong>
                <span>Paid</span><strong>{{ formatDateTime(invoiceDetails.invoice.paid_at) }}</strong>
                <span>Canceled</span><strong>{{ formatDateTime(invoiceDetails.invoice.canceled_at) }}</strong>
              </div>
            </v-card-text>
          </v-card>

          <h3 class="drawer-section-title">Payments</h3>
          <v-list density="compact" class="mb-3">
            <v-list-item
              v-for="payment in invoiceDetails.payments"
              :key="payment.id"
              :title="`#${payment.id} · ${payment.provider_code}`"
              :subtitle="`${payment.provider_payment_id || 'no provider id'} · ${formatMoneyMinor(payment.amount_minor, payment.currency)}`"
              @click="openPaymentDetails(payment.id)"
            >
              <template #append>
                <v-chip size="small" :color="statusColor(payment.status)" variant="tonal">{{ payment.status }}</v-chip>
              </template>
            </v-list-item>
          </v-list>

          <h3 class="drawer-section-title">Subscription</h3>
          <v-card v-if="invoiceDetails.subscription" variant="outlined">
            <v-card-text>
              <div class="detail-grid">
                <span>ID</span><strong>#{{ invoiceDetails.subscription.id }}</strong>
                <span>Plan</span><strong>{{ invoiceDetails.subscription.plan_code }}</strong>
                <span>Status</span><v-chip size="small" :color="statusColor(invoiceDetails.subscription.status)" variant="tonal">{{ invoiceDetails.subscription.status }}</v-chip>
                <span>Current end</span><strong>{{ formatDateTime(invoiceDetails.subscription.current_period_end) }}</strong>
              </div>
            </v-card-text>
          </v-card>
          <v-alert v-else density="compact" variant="tonal" type="info">Subscription ещё не создана.</v-alert>
        </template>

        <template v-if="detailsKind === 'payment' && paymentDetails">
          <div class="drawer-actions">
            <v-btn
              color="primary"
              variant="tonal"
              prepend-icon="mdi-sync"
              :loading="refreshingPayment"
              :disabled="!paymentDetails.payment.provider_payment_id"
              @click="handleRefreshProviderStatus(paymentDetails.payment.id)"
            >
              Обновить статус у провайдера
            </v-btn>
          </div>

          <v-card variant="tonal" class="mb-3">
            <v-card-text>
              <div class="detail-grid">
                <span>ID</span><strong>#{{ paymentDetails.payment.id }}</strong>
                <span>UUID</span><code>{{ paymentDetails.payment.uuid }}</code>
                <span>Provider</span><strong>{{ paymentDetails.payment.provider_code }}</strong>
                <span>Provider ID</span><code>{{ paymentDetails.payment.provider_payment_id || '—' }}</code>
                <span>Invoice</span><strong>#{{ paymentDetails.payment.invoice_id }}</strong>
                <span>Amount</span><strong>{{ formatMoneyMinor(paymentDetails.payment.amount_minor, paymentDetails.payment.currency) }}</strong>
                <span>Status</span><v-chip size="small" :color="statusColor(paymentDetails.payment.status)" variant="tonal">{{ paymentDetails.payment.status }}</v-chip>
                <span>Succeeded</span><strong>{{ formatDateTime(paymentDetails.payment.succeeded_at) }}</strong>
                <span>Canceled</span><strong>{{ formatDateTime(paymentDetails.payment.canceled_at) }}</strong>
              </div>
            </v-card-text>
          </v-card>

          <v-btn
            v-if="paymentDetails.payment.confirmation_url"
            class="mb-3"
            variant="outlined"
            append-icon="mdi-open-in-new"
            @click="openConfirmation(paymentDetails.payment.confirmation_url)"
          >
            Открыть confirmation_url
          </v-btn>

          <h3 class="drawer-section-title">Provider sync</h3>
          <v-card variant="outlined" class="mb-3">
            <v-card-text>
              <div class="detail-grid">
                <span>Provider payment ID</span><code>{{ paymentDetails.payment.provider_payment_id || '—' }}</code>
                <span>Last local status</span><v-chip size="small" :color="statusColor(paymentDetails.payment.status)" variant="tonal">{{ paymentDetails.payment.status }}</v-chip>
                <span>Provider payload status</span><strong>{{ providerPayloadStatus(paymentDetails.payment.provider_payload) }}</strong>
                <span>Last provider event</span><strong>{{ lastProviderEvent(paymentDetails.provider_events)?.event_type || '—' }}</strong>
                <span>Last processed at</span><strong>{{ formatDateTime(lastProviderEvent(paymentDetails.provider_events)?.processed_at) }}</strong>
                <span>Last refresh error</span><strong class="text-error">{{ paymentDetails.payment.error_message || '—' }}</strong>
              </div>
            </v-card-text>
          </v-card>

          <h3 class="drawer-section-title">Provider events</h3>
          <v-list density="compact" class="mb-3">
            <v-list-item
              v-for="event in paymentDetails.provider_events"
              :key="event.id"
              :title="event.event_type"
              :subtitle="formatDateTime(event.created_at)"
              @click="openProviderEventDetails(event.id)"
            >
              <template #append>
                <v-chip size="small" :color="statusColor(event.processing_status)" variant="tonal">{{ event.processing_status }}</v-chip>
              </template>
            </v-list-item>
          </v-list>

          <h3 class="drawer-section-title">Sanitized provider payload</h3>
          <pre class="json-block">{{ prettyJson(paymentDetails.payment.provider_payload) }}</pre>
        </template>

        <template v-if="detailsKind === 'event' && eventDetails">
          <v-card variant="tonal" class="mb-3">
            <v-card-text>
              <div class="detail-grid">
                <span>ID</span><strong>#{{ eventDetails.event.id }}</strong>
                <span>Provider</span><strong>{{ eventDetails.event.provider_code }}</strong>
                <span>Event</span><strong>{{ eventDetails.event.event_type }}</strong>
                <span>Payment ID</span><code>{{ eventDetails.event.provider_payment_id || '—' }}</code>
                <span>Status</span><v-chip size="small" :color="statusColor(eventDetails.event.processing_status)" variant="tonal">{{ eventDetails.event.processing_status }}</v-chip>
                <span>Processed</span><strong>{{ formatDateTime(eventDetails.event.processed_at) }}</strong>
                <span>Error</span><strong class="text-error">{{ eventDetails.event.processing_error || '—' }}</strong>
              </div>
            </v-card-text>
          </v-card>

          <h3 class="drawer-section-title">Linked payment</h3>
          <v-alert v-if="!eventDetails.payment" density="compact" variant="tonal" type="info" class="mb-3">Payment не найден.</v-alert>
          <v-btn
            v-else
            variant="outlined"
            class="mb-3"
            @click="openPaymentDetails(eventDetails.payment.id)"
          >
            Payment #{{ eventDetails.payment.id }} · {{ eventDetails.payment.status }}
          </v-btn>

          <h3 class="drawer-section-title">Sanitized payload</h3>
          <pre class="json-block">{{ prettyJson(eventDetails.event.payload) }}</pre>

          <h3 class="drawer-section-title mt-3">Sanitized headers</h3>
          <pre class="json-block">{{ prettyJson(eventDetails.event.headers) }}</pre>
        </template>
      </div>
    </v-navigation-drawer>
  </v-container>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import {
  createBillingInvoice,
  createBillingPayment,
  getBillingInvoiceDetails,
  getBillingInvoices,
  getBillingPaymentPlans,
  getBillingPaymentDetails,
  getBillingPayments,
  getBillingProviderEventDetails,
  getBillingProviderEvents,
  refreshBillingPaymentProviderStatus,
} from '@/api/adminBillingPayments'

type UserSummary = {
  id: number
  name?: string | null
  email?: string | null
}

type BillingInvoice = {
  id: number
  uuid: string
  user_id: number
  user?: UserSummary | null
  plan_code: string
  amount_minor: number
  currency: string
  status: string
  description?: string | null
  metadata_json?: Record<string, unknown> | null
  period_start?: string | null
  period_end?: string | null
  paid_at?: string | null
  canceled_at?: string | null
  created_at?: string | null
  updated_at?: string | null
}

type BillingPayment = {
  id: number
  uuid: string
  invoice_id: number
  user_id: number
  provider_code: string
  provider_payment_id?: string | null
  amount_minor: number
  currency: string
  status: string
  idempotency_key?: string | null
  confirmation_type?: string | null
  confirmation_url?: string | null
  confirmation_token?: string | null
  provider_payload?: Record<string, unknown> | null
  error_code?: string | null
  error_message?: string | null
  succeeded_at?: string | null
  canceled_at?: string | null
  created_at?: string | null
  updated_at?: string | null
}

type BillingProviderEvent = {
  id: number
  provider_code: string
  event_type: string
  provider_payment_id?: string | null
  processing_status: string
  processing_error?: string | null
  payload?: Record<string, unknown> | null
  headers?: Record<string, unknown> | null
  processed_at?: string | null
  created_at?: string | null
}

type BillingSubscriptionSummary = {
  id: number
  plan_code: string
  status: string
  current_period_start?: string | null
  current_period_end?: string | null
}

type InvoiceDetails = {
  invoice: BillingInvoice
  payments: BillingPayment[]
  subscription: BillingSubscriptionSummary | null
}

type PaymentDetails = {
  payment: BillingPayment
  invoice: BillingInvoice | null
  provider_events: BillingProviderEvent[]
}

type ProviderEventDetails = {
  event: BillingProviderEvent
  payment: {
    id: number
    status: string
    invoice_id: number
  } | null
}

type PaymentPlan = {
  code: string
  name: string
  price_minor: number
  currency: string
  billing_period: 'month' | 'year' | string
}

const invoices = ref<BillingInvoice[]>([])
const payments = ref<BillingPayment[]>([])
const providerEvents = ref<BillingProviderEvent[]>([])
const paymentPlans = ref<PaymentPlan[]>([])

const loading = ref(false)
const creatingInvoice = ref(false)
const paymentInvoiceId = ref<number | null>(null)
const selectedInvoiceId = ref<number | null>(null)
const errorMessage = ref('')
const successMessage = ref('')
const detailsDrawerOpen = ref(false)
const detailsLoading = ref(false)
const refreshingPayment = ref(false)
const detailsKind = ref<'invoice' | 'payment' | 'event' | null>(null)
const invoiceDetails = ref<InvoiceDetails | null>(null)
const paymentDetails = ref<PaymentDetails | null>(null)
const eventDetails = ref<ProviderEventDetails | null>(null)

const invoiceForm = reactive<{
  user_id: number | null
  plan_code: string
  billing_period: 'month' | 'year'
}>({
  user_id: null,
  plan_code: '',
  billing_period: 'month',
})

const periodItems = [
  { title: 'month', value: 'month' },
  { title: 'year', value: 'year' },
]

const planItems = computed(() => paymentPlans.value.map((plan) => ({
  title: `${plan.name} · ${formatMoneyMinor(plan.price_minor, plan.currency)} · ${plan.billing_period}`,
  value: plan.code,
})))

const canCreateInvoice = computed(() => Number(invoiceForm.user_id) > 0 && Boolean(invoiceForm.plan_code))

const detailsTitle = computed(() => {
  if (detailsKind.value === 'invoice') return 'Детали invoice'
  if (detailsKind.value === 'payment') return 'Детали платежа'
  if (detailsKind.value === 'event') return 'Событие провайдера'
  return 'Детали'
})

onMounted(() => {
  loadAll()
})

async function loadAll() {
  loading.value = true
  errorMessage.value = ''

  try {
    const [plansResponse, invoicesResponse, paymentsResponse, eventsResponse] = await Promise.all([
      getBillingPaymentPlans(),
      getBillingInvoices({ limit: 25 }),
      getBillingPayments({ limit: 25 }),
      getBillingProviderEvents({ limit: 25 }),
    ])

    paymentPlans.value = plansResponse.data || []
    invoices.value = invoicesResponse.data || []
    payments.value = paymentsResponse.data || []
    providerEvents.value = eventsResponse.data || []

    if (!invoiceForm.plan_code && paymentPlans.value.length) {
      invoiceForm.plan_code = paymentPlans.value[0]?.code || ''
    }
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message || 'Не удалось загрузить платежные данные'
  } finally {
    loading.value = false
  }
}

async function handleCreateInvoice() {
  if (!canCreateInvoice.value || !invoiceForm.user_id) return

  creatingInvoice.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const response = await createBillingInvoice({
      user_id: Number(invoiceForm.user_id),
      plan_code: invoiceForm.plan_code,
      billing_period: invoiceForm.billing_period,
    })

    selectedInvoiceId.value = response.invoice?.id || null
    successMessage.value = `Invoice #${response.invoice?.id} создан`
    await loadAll()
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message || 'Не удалось создать invoice'
  } finally {
    creatingInvoice.value = false
  }
}

async function handleCreatePayment(invoice: BillingInvoice) {
  paymentInvoiceId.value = invoice.id
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const response = await createBillingPayment(invoice.id, { provider_code: 'yookassa' })
    const confirmationUrl = response.payment?.confirmation_url

    selectedInvoiceId.value = invoice.id
    successMessage.value = `Payment #${response.payment?.id} создан`

    await loadAll()

    if (confirmationUrl) {
      openConfirmation(confirmationUrl)
    }
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message || 'Не удалось создать payment'
  } finally {
    paymentInvoiceId.value = null
  }
}

function openConfirmation(url: string) {
  window.open(url, '_blank', 'noopener')
}

async function openInvoiceDetails(invoiceId: number) {
  detailsKind.value = 'invoice'
  detailsDrawerOpen.value = true
  detailsLoading.value = true
  invoiceDetails.value = null
  paymentDetails.value = null
  eventDetails.value = null
  selectedInvoiceId.value = invoiceId

  try {
    invoiceDetails.value = await getBillingInvoiceDetails(invoiceId)
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message || 'Не удалось открыть детали invoice'
  } finally {
    detailsLoading.value = false
  }
}

async function openPaymentDetails(paymentId: number) {
  detailsKind.value = 'payment'
  detailsDrawerOpen.value = true
  detailsLoading.value = true
  invoiceDetails.value = null
  paymentDetails.value = null
  eventDetails.value = null

  try {
    paymentDetails.value = await getBillingPaymentDetails(paymentId)
    selectedInvoiceId.value = paymentDetails.value?.payment.invoice_id || null
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message || 'Не удалось открыть детали платежа'
  } finally {
    detailsLoading.value = false
  }
}

async function openProviderEventDetails(eventId: number) {
  detailsKind.value = 'event'
  detailsDrawerOpen.value = true
  detailsLoading.value = true
  invoiceDetails.value = null
  paymentDetails.value = null
  eventDetails.value = null

  try {
    eventDetails.value = await getBillingProviderEventDetails(eventId)
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message || 'Не удалось открыть событие провайдера'
  } finally {
    detailsLoading.value = false
  }
}

async function handleRefreshProviderStatus(paymentId: number) {
  refreshingPayment.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const response = await refreshBillingPaymentProviderStatus(paymentId)
    successMessage.value = `Статус payment #${response.payment?.id} обновлён: ${response.payment?.status}`
    await loadAll()
    await openPaymentDetails(paymentId)
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message || 'Не удалось обновить статус у провайдера'
  } finally {
    refreshingPayment.value = false
  }
}

function closeDetails() {
  detailsDrawerOpen.value = false
}

function formatMoneyMinor(amountMinor: number | null | undefined, currency: string | null | undefined): string {
  const amount = Number(amountMinor || 0) / 100

  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: currency || 'RUB',
  }).format(amount)
}

function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'

  return new Intl.DateTimeFormat('ru-RU', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(new Date(value))
}

function userLabel(user: UserSummary | null | undefined, fallbackId: number): string {
  if (!user) return `#${fallbackId}`

  return user.email || user.name || `#${user.id}`
}

function statusColor(status: string): string {
  if (['paid', 'succeeded', 'processed', 'active'].includes(status)) return 'success'
  if (['failed', 'canceled'].includes(status)) return 'error'
  if (['pending', 'pending_payment', 'waiting_for_capture', 'received'].includes(status)) return 'warning'
  if (['ignored', 'refunded'].includes(status)) return 'info'
  return 'default'
}

function prettyJson(value: unknown): string {
  if (!value) return '{}'

  return JSON.stringify(value, null, 2)
}

function providerPayloadStatus(payload: Record<string, unknown> | null | undefined): string {
  const status = payload?.status

  return typeof status === 'string' && status ? status : '—'
}

function lastProviderEvent(events: BillingProviderEvent[]): BillingProviderEvent | null {
  return events[0] || null
}
</script>

<style scoped>
.admin-billing-payments {
  max-width: 1440px;
}

.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
}

.section-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  font-size: 1rem;
}

.billing-table {
  font-size: 0.875rem;
}

.mono-cell {
  max-width: 260px;
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.error-cell {
  max-width: 280px;
  color: rgb(var(--v-theme-error));
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.empty-cell {
  padding: 24px;
  color: rgba(var(--v-theme-on-surface), 0.62);
  text-align: center;
}

.row-selected {
  background: rgba(var(--v-theme-primary), 0.08);
}

.details-drawer {
  border-left: 1px solid rgba(var(--v-theme-on-surface), 0.12);
}

.drawer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px;
}

.drawer-body {
  padding: 16px;
}

.drawer-actions {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 12px;
}

.drawer-section-title {
  margin: 0 0 8px;
  font-size: 0.875rem;
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
}

.detail-grid {
  display: grid;
  grid-template-columns: minmax(112px, 0.45fr) minmax(0, 1fr);
  gap: 8px 12px;
  align-items: center;
}

.detail-grid span {
  color: rgba(var(--v-theme-on-surface), 0.62);
}

.detail-grid code {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.json-block {
  max-height: 280px;
  margin: 0;
  padding: 12px;
  overflow: auto;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 8px;
  background: rgba(var(--v-theme-on-surface), 0.04);
  font-size: 0.78rem;
  line-height: 1.45;
  white-space: pre-wrap;
  word-break: break-word;
}

@media (max-width: 960px) {
  .page-header {
    flex-direction: column;
  }
}
</style>
