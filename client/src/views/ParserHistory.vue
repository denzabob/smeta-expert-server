<template>
  <v-container fluid class="parser-history">
    <v-row>
      <!-- Header & Filters -->
      <v-col cols="12">
        <v-card class="filters-card" elevation="2">
          <v-card-title>
            <v-icon icon="mdi-history" class="mr-2" />
            Parsing History & Analytics
          </v-card-title>

          <v-card-text>
            <v-row align="center">
              <v-col cols="12" md="3">
                <v-select
                  v-model="filters.supplier"
                  :items="suppliers"
                  label="Supplier"
                  clearable
                  variant="outlined"
                  density="compact"
                />
              </v-col>

              <v-col cols="12" md="3">
                <v-select
                  v-model="filters.status"
                  :items="statuses"
                  label="Status"
                  clearable
                  variant="outlined"
                  density="compact"
                />
              </v-col>

              <v-col cols="12" md="3">
                <v-text-field
                  v-model="filters.dateFrom"
                  label="From Date"
                  type="date"
                  variant="outlined"
                  density="compact"
                />
              </v-col>

              <v-col cols="12" md="3">
                <v-text-field
                  v-model="filters.dateTo"
                  label="To Date"
                  type="date"
                  variant="outlined"
                  density="compact"
                />
              </v-col>

              <v-col cols="12" class="text-right">
                <v-btn
                  color="primary"
                  variant="elevated"
                  prepend-icon="mdi-filter"
                  @click="applyFilters"
                  :loading="loading.sessions"
                >
                  Apply Filters
                </v-btn>
                <v-btn
                  variant="text"
                  prepend-icon="mdi-refresh"
                  @click="resetFilters"
                  class="ml-2"
                >
                  Reset
                </v-btn>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- Charts -->
      <v-col cols="12" md="6">
        <v-card class="chart-card" elevation="2">
          <v-card-title>Processed Items Over Time</v-card-title>
          <v-card-text>
            <apexchart
              type="bar"
              :options="processedChartOptions"
              :series="processedChartSeries"
              height="300"
            />
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" md="6">
        <v-card class="chart-card" elevation="2">
          <v-card-title>Error Trends</v-card-title>
          <v-card-text>
            <apexchart
              type="line"
              :options="errorsChartOptions"
              :series="errorsChartSeries"
              height="300"
            />
          </v-card-text>
        </v-card>
      </v-col>

      <!-- Sessions Table -->
      <v-col cols="12">
        <v-card class="sessions-table" elevation="2">
          <v-card-title>
            <div class="d-flex align-center justify-space-between w-100">
              <span>Sessions ({{ total }} total)</span>
              <v-text-field
                v-model="search"
                prepend-inner-icon="mdi-magnify"
                label="Search"
                single-line
                hide-details
                density="compact"
                variant="outlined"
                style="max-width: 300px"
              />
            </div>
          </v-card-title>

          <v-data-table
            :headers="headers"
            :items="sessions"
            :loading="loading.sessions"
            :search="search"
            item-value="id"
            class="elevation-0"
            :items-per-page="20"
          >
            <!-- Expand Panel -->
            <template v-slot:expanded-row="{ item }">
              <tr class="expansion-row">
                <td :colspan="headers.length">
                  <v-card flat class="ma-3">
                    <v-card-text>
                      <v-row>
                        <v-col cols="12" md="4">
                          <div class="detail-section">
                            <div class="detail-title">Session Details</div>
                            <div class="detail-item">
                              <span class="label">PID:</span>
                              <span class="value">{{ item.pid || 'N/A' }}</span>
                            </div>
                            <div class="detail-item">
                              <span class="label">Exit Code:</span>
                              <span class="value">{{ item.exit_code ?? 'N/A' }}</span>
                            </div>
                            <div class="detail-item">
                              <span class="label">Last Heartbeat:</span>
                              <span class="value">{{ formatDate(item.last_heartbeat_at) }}</span>
                            </div>
                            <div v-if="item.error_message" class="detail-item">
                              <span class="label">Error:</span>
                              <span class="value error-text">{{ item.error_message }}</span>
                            </div>
                          </div>
                        </v-col>

                        <v-col cols="12" md="4">
                          <div class="detail-section">
                            <div class="detail-title">Performance Metrics</div>
                            <div class="detail-item">
                              <span class="label">Total Runtime:</span>
                              <span class="value">{{ calculateRuntime(item) }}</span>
                            </div>
                            <div class="detail-item">
                              <span class="label">Avg Speed:</span>
                              <span class="value">{{ calculateSpeed(item) }}</span>
                            </div>
                            <div class="detail-item">
                              <span class="label">Success Rate:</span>
                              <span class="value">{{ calculateSuccessRate(item) }}%</span>
                            </div>
                            <div class="detail-item">
                              <span class="label">Screenshots:</span>
                              <span class="value">{{ item.screenshots_taken }}</span>
                            </div>
                          </div>
                        </v-col>

                        <v-col cols="12" md="4">
                          <div class="detail-section">
                            <div class="detail-title">Actions</div>
                            <v-btn
                              color="primary"
                              variant="elevated"
                              prepend-icon="mdi-eye"
                              @click="viewSession(item.id)"
                              block
                              class="mb-2"
                            >
                              View Details
                            </v-btn>
                            <v-btn
                              variant="outlined"
                              prepend-icon="mdi-file-download"
                              @click="downloadLogs(item.id)"
                              block
                            >
                              Export Logs
                            </v-btn>
                          </div>
                        </v-col>
                      </v-row>
                    </v-card-text>
                  </v-card>
                </td>
              </tr>
            </template>

            <!-- Status Column -->
            <template v-slot:item.status="{ item }">
              <v-chip
                :color="getStatusColor(item.status)"
                size="small"
              >
                {{ item.status.toUpperCase() }}
              </v-chip>
            </template>

            <!-- Started At Column -->
            <template v-slot:item.started_at="{ item }">
              {{ formatDate(item.started_at) }}
            </template>

            <!-- Duration Column -->
            <template v-slot:item.duration="{ item }">
              {{ calculateRuntime(item) }}
            </template>

            <!-- Progress Column -->
            <template v-slot:item.progress="{ item }">
              <div class="d-flex align-center">
                <v-progress-linear
                  :model-value="calculateProgress(item)"
                  :color="getStatusColor(item.status)"
                  height="20"
                  class="flex-grow-1"
                >
                  <small>{{ item.processed_count }}/{{ item.total_urls }}</small>
                </v-progress-linear>
              </div>
            </template>

            <!-- Results Column -->
            <template v-slot:item.results="{ item }">
              <div class="results-cell">
                <span class="success-count">✓ {{ item.success_count }}</span>
                <span class="error-count">✗ {{ item.error_count }}</span>
              </div>
            </template>

            <!-- Actions Column -->
            <template v-slot:item.actions="{ item }">
              <v-btn
                icon="mdi-chevron-down"
                variant="text"
                size="small"
              />
            </template>
          </v-data-table>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { parserApi, type ParsingSession, type ChartDataPoint } from '@/api/parser'
import { differenceInSeconds, format } from 'date-fns'
import VueApexCharts from 'vue3-apexcharts'

const router = useRouter()
const route = useRoute()

// Флаг для предотвращения обновления состояния при размонтировании
let isUnmounted = false
let abortController: AbortController | null = null

// State
const sessions = ref<ParsingSession[]>([])
const chartData = ref<ChartDataPoint[]>([])
const loading = ref({
  sessions: false,
  chart: false
})
const search = ref('')
const total = ref(0)

const filters = ref({
  supplier: route.query.supplier as string || '',
  status: '',
  dateFrom: '',
  dateTo: ''
})

const suppliers = ref(['skm_mebel', 'template']) // TODO: Load from API
const statuses = ref([
  { title: 'Completed', value: 'completed' },
  { title: 'Failed', value: 'failed' },
  { title: 'Running', value: 'running' },
  { title: 'Stopped', value: 'stopped' }
])

const headers = [
  { title: 'ID', key: 'id', sortable: true },
  { title: 'Supplier', key: 'supplier' },
  { title: 'Status', key: 'status' },
  { title: 'Started', key: 'started_at' },
  { title: 'Duration', key: 'duration' },
  { title: 'Progress', key: 'progress', sortable: false },
  { title: 'Results', key: 'results', sortable: false },
  { title: '', key: 'data-table-expand' }
]

// Charts Configuration
const processedChartOptions = computed(() => ({
  chart: {
    type: 'bar',
    toolbar: { show: false },
    background: 'transparent'
  },
  plotOptions: {
    bar: {
      borderRadius: 0,
      columnWidth: '80%'
    }
  },
  dataLabels: { enabled: false },
  xaxis: {
    categories: chartData.value.map(d => format(new Date(d.date), 'MMM dd')),
    labels: { style: { colors: '#666' } }
  },
  yaxis: {
    labels: { style: { colors: '#666' } }
  },
  colors: ['#2196F3'],
  tooltip: {
    theme: 'dark'
  }
}))

const processedChartSeries = computed(() => [{
  name: 'Processed Items',
  data: chartData.value.map(d => d.processed)
}])

const errorsChartOptions = computed(() => ({
  chart: {
    type: 'line',
    toolbar: { show: false },
    background: 'transparent'
  },
  stroke: {
    curve: 'smooth',
    width: 3
  },
  markers: {
    size: 5
  },
  dataLabels: { enabled: false },
  xaxis: {
    categories: chartData.value.map(d => format(new Date(d.date), 'MMM dd')),
    labels: { style: { colors: '#666' } }
  },
  yaxis: {
    labels: { style: { colors: '#666' } }
  },
  colors: ['#F44336'],
  tooltip: {
    theme: 'dark'
  }
}))

const errorsChartSeries = computed(() => [{
  name: 'Errors',
  data: chartData.value.map(d => d.errors)
}])

// Lifecycle
onMounted(() => {
  isUnmounted = false
  loadData()
})

onBeforeUnmount(() => {
  isUnmounted = true
  if (abortController) {
    (abortController as AbortController).abort()
  }
})

// Methods
async function loadData() {
  if (isUnmounted) return
  
  await Promise.all([
    loadSessions(),
    loadChartData()
  ])
}

async function loadSessions() {
  if (isUnmounted) return
  
  try {
    loading.value.sessions = true
    const params: any = {}
    if (filters.value.supplier) params.supplier = filters.value.supplier
    if (filters.value.status) params.status = filters.value.status
    if (filters.value.dateFrom) params.from = filters.value.dateFrom
    if (filters.value.dateTo) params.to = filters.value.dateTo

    const response = await parserApi.getSessions(params)
    
    // Проверяем, жив ли компонент перед обновлением состояния
    if (!isUnmounted) {
      sessions.value = response.data
      total.value = response.meta?.total || response.data.length
    }
  } catch (error) {
    if (!isUnmounted) {
      console.error('Failed to load sessions:', error)
    }
  } finally {
    if (!isUnmounted) {
      loading.value.sessions = false
    }
  }
}

async function loadChartData() {
  if (isUnmounted) return
  
  try {
    loading.value.chart = true
    const params: any = {
      from: filters.value.dateFrom || new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
      to: filters.value.dateTo || new Date().toISOString().split('T')[0]
    }
    if (filters.value.supplier) params.supplier = filters.value.supplier

    const data = await parserApi.getChartData(params)
    
    // Проверяем, жив ли компонент перед обновлением состояния
    if (!isUnmounted) {
      chartData.value = data
    }
  } catch (error) {
    if (!isUnmounted) {
      console.error('Failed to load chart data:', error)
    }
  } finally {
    if (!isUnmounted) {
      loading.value.chart = false
    }
  }
}

function applyFilters() {
  loadData()
}

function resetFilters() {
  filters.value = {
    supplier: '',
    status: '',
    dateFrom: '',
    dateTo: ''
  }
  loadData()
}

function getStatusColor(status: string): string {
  switch (status) {
    case 'completed': return 'success'
    case 'running': return 'primary'
    case 'failed': return 'error'
    case 'stopped': return 'warning'
    default: return 'grey'
  }
}

function formatDate(date: string | null): string {
  if (!date) return 'N/A'
  try {
    return format(new Date(date), 'MMM dd, HH:mm')
  } catch {
    return 'Invalid'
  }
}

function calculateRuntime(session: ParsingSession): string {
  if (!session.started_at) return 'N/A'
  const end = session.completed_at ? new Date(session.completed_at) : new Date()
  const seconds = differenceInSeconds(end, new Date(session.started_at))
  
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const secs = seconds % 60
  
  if (hours > 0) return `${hours}h ${minutes}m`
  if (minutes > 0) return `${minutes}m ${secs}s`
  return `${secs}s`
}

function calculateSpeed(session: ParsingSession): string {
  if (!session.started_at || session.processed_count === 0) return 'N/A'
  const end = session.completed_at ? new Date(session.completed_at) : new Date()
  const seconds = differenceInSeconds(end, new Date(session.started_at))
  if (seconds === 0) return 'N/A'
  
  const rate = session.processed_count / seconds
  return `${rate.toFixed(2)} items/sec`
}

function calculateSuccessRate(session: ParsingSession): number {
  if (session.processed_count === 0) return 0
  return Math.round((session.success_count / session.processed_count) * 100)
}

function calculateProgress(session: ParsingSession): number {
  if (!session.total_urls || session.total_urls === 0) return 0
  return Math.round((session.processed_count / session.total_urls) * 100)
}

function viewSession(id: number) {
  router.push(`/parser/sessions/${id}`)
}

function downloadLogs(id: number) {
  // TODO: Implement log export
  alert('Export functionality coming soon')
}
</script>

<style scoped lang="scss">
.parser-history {
  max-width: 1800px;
  margin: 0 auto;
}

.filters-card,
.chart-card,
.sessions-table {
  border-radius: 0 !important;
}

.expansion-row {
  background: #f5f5f5;

  .detail-section {
    .detail-title {
      font-weight: bold;
      margin-bottom: 12px;
      text-transform: uppercase;
      font-size: 0.875rem;
      color: rgba(0, 0, 0, 0.6);
    }

    .detail-item {
      display: flex;
      justify-content: space-between;
      padding: 6px 0;
      border-bottom: 1px solid rgba(0, 0, 0, 0.08);

      .label {
        font-weight: 500;
        color: rgba(0, 0, 0, 0.7);
      }

      .value {
        color: rgba(0, 0, 0, 0.87);
        
        &.error-text {
          color: #f44336;
          font-size: 0.875rem;
        }
      }
    }
  }
}

.results-cell {
  display: flex;
  gap: 12px;

  .success-count {
    color: #4caf50;
    font-weight: 500;
  }

  .error-count {
    color: #f44336;
    font-weight: 500;
  }
}
</style>
