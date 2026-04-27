<template>
  <PageContainer>
    <PageHeader
      title="Использование"
      subtitle="Read-only обзор активности и накопленных usage events"
    />

    <SectionCard class="mb-4" variant="outlined">
      <v-row dense>
        <v-col cols="12" md="3" sm="6">
          <v-text-field
            v-model="filters.period_start"
            label="Период с"
            type="date"
            density="compact"
            variant="outlined"
            hide-details
          />
        </v-col>
        <v-col cols="12" md="3" sm="6">
          <v-text-field
            v-model="filters.period_end"
            label="Период по"
            type="date"
            density="compact"
            variant="outlined"
            hide-details
          />
        </v-col>
        <v-col cols="12" md="3" sm="6">
          <v-text-field
            v-model="filters.user_id"
            label="User ID"
            density="compact"
            variant="outlined"
            hide-details
          />
        </v-col>
        <v-col cols="12" md="3" sm="6">
          <v-text-field
            v-model="filters.metric_code"
            label="Metric code"
            density="compact"
            variant="outlined"
            hide-details
          />
        </v-col>
      </v-row>
      <div class="d-flex justify-end mt-3">
        <v-btn color="primary" variant="tonal" prepend-icon="mdi-refresh" :loading="loading" @click="loadAll">
          Обновить
        </v-btn>
      </div>
    </SectionCard>

    <v-alert
      v-if="error"
      type="error"
      variant="tonal"
      density="comfortable"
      class="mb-4"
    >
      {{ error }}
    </v-alert>

    <v-row class="mb-4">
      <v-col
        v-for="card in statCards"
        :key="card.label"
        cols="12"
        sm="6"
        md="4"
        lg="2"
      >
        <SectionCard variant="outlined" class="usage-stat">
          <div class="usage-stat__icon">
            <v-icon :icon="card.icon" size="22" />
          </div>
          <div class="usage-stat__value">{{ card.value }}</div>
          <div class="usage-stat__label">{{ card.label }}</div>
        </SectionCard>
      </v-col>
    </v-row>

    <SectionCard
      v-if="userOverview"
      class="mb-4"
      title="Пользователь"
      variant="outlined"
    >
      <v-row dense>
        <v-col cols="12" md="3">
          <div class="text-caption text-medium-emphasis">User</div>
          <div class="text-body-2">{{ userOverview.user?.name || '—' }}</div>
          <div class="text-caption text-medium-emphasis">{{ userOverview.user?.email || '—' }}</div>
        </v-col>
        <v-col cols="12" md="3">
          <div class="text-caption text-medium-emphasis">Plan</div>
          <div class="text-body-2">{{ userOverview.billing?.plan_code || '—' }}</div>
          <div class="text-caption text-medium-emphasis">{{ userOverview.billing?.subscription_status || '—' }}</div>
        </v-col>
        <v-col cols="12" md="3">
          <div class="text-caption text-medium-emphasis">Projects</div>
          <div class="text-body-2">
            {{ userOverview.projects?.active ?? 0 }} active / {{ userOverview.projects?.total ?? 0 }} total
          </div>
        </v-col>
        <v-col cols="12" md="3">
          <div class="text-caption text-medium-emphasis">Storage</div>
          <div class="text-body-2">{{ formatBytes(userOverview.storage?.bytes_uploaded || 0) }}</div>
          <div class="text-caption text-medium-emphasis">legacy storage not included</div>
        </v-col>
      </v-row>
    </SectionCard>

    <v-row>
      <v-col cols="12" lg="5">
        <SectionCard title="Top metrics" variant="outlined" :loading="loading">
          <v-data-table
            :headers="topMetricHeaders"
            :items="overview?.top_metrics || []"
            density="compact"
            :items-per-page="10"
          >
            <template #item.quantity="{ item }">
              {{ formatNumber(eventQuantity(item)) }}
            </template>
            <template #item.period>
              {{ overview?.period?.start }} — {{ overview?.period?.end }}
            </template>
          </v-data-table>
        </SectionCard>
      </v-col>

      <v-col cols="12" lg="7">
        <SectionCard title="Recent events" variant="outlined" :loading="loading">
          <v-data-table
            :headers="eventHeaders"
            :items="events?.items || overview?.recent_events || []"
            density="compact"
            :items-per-page="10"
          >
            <template #item.occurred_at="{ item }">
              <span class="text-no-wrap">{{ formatDateTime(eventOccurredAt(item)) }}</span>
            </template>
            <template #item.user="{ item }">
              {{ eventUserLabel(item) }}
            </template>
            <template #item.project="{ item }">
              {{ eventProjectLabel(item) }}
            </template>
            <template #item.quantity="{ item }">
              {{ formatNumber(eventQuantity(item)) }} {{ eventUnit(item) }}
            </template>
          </v-data-table>
        </SectionCard>
      </v-col>
    </v-row>
  </PageContainer>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import {
  getAdminBillingEvents,
  getAdminBillingOverview,
  getAdminBillingUsage,
  getAdminBillingUserOverview,
  type AdminBillingFilters,
} from '@/api/adminBilling'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'

const loading = ref(false)
const error = ref('')
const overview = ref<any>(null)
const usage = ref<any>(null)
const events = ref<any>(null)
const userOverview = ref<any>(null)

const filters = reactive<AdminBillingFilters>({
  period_start: '',
  period_end: '',
  user_id: '',
  metric_code: '',
  limit: 100,
})

const topMetricHeaders = [
  { title: 'Metric', key: 'metric_code' },
  { title: 'Quantity', key: 'quantity', align: 'end' as const },
  { title: 'Period', key: 'period' },
] as const

const eventHeaders = [
  { title: 'Time', key: 'occurred_at' },
  { title: 'User', key: 'user' },
  { title: 'Project', key: 'project' },
  { title: 'Metric', key: 'metric_code' },
  { title: 'Feature', key: 'feature_code' },
  { title: 'Quantity', key: 'quantity', align: 'end' as const },
  { title: 'Source', key: 'source' },
] as const

const statCards = computed(() => {
  const totals = overview.value?.totals || {}
  const pdfTotal =
    Number(totals.pdf_smeta_generated || 0) +
    Number(totals.pdf_price_justification_generated || 0) +
    Number(totals.pdf_evidence_run_generated || 0)

  return [
    { label: 'Users', value: formatNumber(totals.users || 0), icon: 'mdi-account-group-outline' },
    { label: 'Active projects', value: formatNumber(totals.active_projects || 0), icon: 'mdi-folder-outline' },
    { label: 'PDF generated', value: formatNumber(pdfTotal), icon: 'mdi-file-pdf-box' },
    { label: 'Evidence runs', value: formatNumber(totals.evidence_runs_created || 0), icon: 'mdi-shield-search' },
    { label: 'Chrome captures', value: formatNumber(totals.chrome_extract_with_evidence || 0), icon: 'mdi-google-chrome' },
    { label: 'Storage uploaded', value: formatBytes(totals.storage_bytes_uploaded || 0), icon: 'mdi-cloud-upload-outline' },
  ]
})

async function loadAll() {
  loading.value = true
  error.value = ''

  try {
    const params = { ...filters }
    const [overviewData, usageData, eventsData] = await Promise.all([
      getAdminBillingOverview(params),
      getAdminBillingUsage(params),
      getAdminBillingEvents({
        user_id: filters.user_id,
        metric_code: filters.metric_code,
        from: filters.period_start,
        to: filters.period_end,
        limit: filters.limit,
      }),
    ])

    overview.value = overviewData
    usage.value = usageData
    events.value = eventsData

    userOverview.value = filters.user_id
      ? await getAdminBillingUserOverview(filters.user_id, params)
      : null
  } catch (err: any) {
    error.value = err?.response?.data?.message || 'Не удалось загрузить данные использования'
  } finally {
    loading.value = false
  }
}

function formatNumber(value: number | string) {
  return new Intl.NumberFormat('ru-RU').format(Number(value || 0))
}

function formatBytes(value: number | string) {
  const bytes = Number(value || 0)
  if (bytes < 1024) return `${formatNumber(bytes)} B`
  if (bytes < 1024 * 1024) return `${formatNumber((bytes / 1024).toFixed(1))} KB`
  if (bytes < 1024 * 1024 * 1024) return `${formatNumber((bytes / 1024 / 1024).toFixed(1))} MB`
  return `${formatNumber((bytes / 1024 / 1024 / 1024).toFixed(1))} GB`
}

function formatDateTime(value?: string | null) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value))
}

function eventUserLabel(item: unknown) {
  const event = item as any
  return event?.user?.email || event?.user?.name || event?.owner_id || '—'
}

function eventProjectLabel(item: unknown) {
  const event = item as any
  return event?.project?.number || event?.project?.id || '—'
}

function eventQuantity(item: unknown) {
  return (item as any)?.quantity || 0
}

function eventUnit(item: unknown) {
  return (item as any)?.unit || ''
}

function eventOccurredAt(item: unknown) {
  return (item as any)?.occurred_at || null
}

onMounted(loadAll)
</script>

<style scoped>
.usage-stat {
  min-height: 132px;
}

.usage-stat__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 8px;
  color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.12);
  margin-bottom: 12px;
}

.usage-stat__value {
  font-size: 24px;
  line-height: 32px;
  font-weight: 700;
}

.usage-stat__label {
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface-variant));
}
</style>
