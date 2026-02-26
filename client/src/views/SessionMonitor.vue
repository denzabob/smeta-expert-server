<template>
  <v-container fluid class="session-monitor">
    <v-row v-if="session">
      <!-- Header -->
      <v-col cols="12">
        <v-card class="session-header" elevation="2">
          <v-card-title class="d-flex align-center justify-space-between">
            <div class="d-flex align-center">
              <v-btn
                icon="mdi-arrow-left"
                variant="text"
                @click="goBack"
              />
              <div class="ml-3">
                <div class="text-h5">
                  {{ session.supplier }}
                  <v-chip
                    :color="getStatusColor(session.status)"
                    class="ml-2"
                    size="small"
                  >
                    {{ session.status.toUpperCase() }}
                  </v-chip>
                </div>
                <div class="text-subtitle-2 text-grey">
                  Session #{{ session.id }} | 
                  Started: {{ formatDate(session.started_at) }}
                  <span v-if="session.pid" class="ml-2">| PID: {{ session.pid }}</span>
                </div>
              </div>
            </div>

            <div class="d-flex align-center gap-2">
              <v-btn
                v-if="session.status === 'running'"
                color="error"
                variant="elevated"
                prepend-icon="mdi-stop"
                @click="forceStop"
                :loading="stopping"
              >
                Force Stop
              </v-btn>
              <v-btn
                icon="mdi-refresh"
                variant="text"
                @click="loadSession"
                :loading="loading.session"
              />
            </div>
          </v-card-title>
        </v-card>
      </v-col>

      <!-- Progress Metrics -->
      <v-col cols="12">
        <v-card class="metrics-card" elevation="2">
          <v-card-text>
            <v-row align="center">
              <!-- Progress Bar -->
              <v-col cols="12" md="6">
                <div class="metric-label mb-2">Processing Progress</div>
                <v-progress-linear
                  :model-value="progressPercentage"
                  :color="getStatusColor(session.status)"
                  height="30"
                  class="progress-bar"
                >
                  <strong>
                    {{ session.processed_count }} / {{ session.total_urls || '?' }}
                    ({{ progressPercentage }}%)
                  </strong>
                </v-progress-linear>
              </v-col>

              <!-- Statistics -->
              <v-col cols="6" md="3">
                <div class="stat-box success-stat">
                  <v-icon icon="mdi-check-circle" size="large" color="success" />
                  <div class="stat-value">{{ session.success_count }}</div>
                  <div class="stat-label">Success</div>
                </div>
              </v-col>

              <v-col cols="6" md="3">
                <div class="stat-box error-stat">
                  <v-icon icon="mdi-alert-circle" size="large" color="error" />
                  <div class="stat-value">{{ session.error_count }}</div>
                  <div class="stat-label">Errors</div>
                </div>
              </v-col>

              <!-- Runtime & ETA -->
              <v-col cols="6" md="3">
                <div class="metric-label">Runtime</div>
                <div class="metric-value">{{ runtime }}</div>
              </v-col>

              <v-col cols="6" md="3">
                <div class="metric-label">ETA</div>
                <div class="metric-value" :class="{'text-warning': etaMinutes > 30}">
                  {{ eta }}
                </div>
              </v-col>

              <v-col cols="6" md="3">
                <div class="metric-label">Speed</div>
                <div class="metric-value">{{ speed }}</div>
              </v-col>

              <v-col cols="6" md="3">
                <div class="metric-label">Screenshots</div>
                <div class="metric-value">{{ session.screenshots_taken }}</div>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- Queue Statistics -->
      <v-col cols="12">
        <v-card class="queue-stats-card" elevation="2">
          <v-card-title class="d-flex align-center justify-space-between">
            <div class="d-flex align-center">
              <v-icon icon="mdi-database" class="mr-2" />
              <span>URL Queue Status</span>
            </div>
            <div class="d-flex align-center gap-2">
              <v-btn
                size="small"
                variant="outlined"
                color="warning"
                prepend-icon="mdi-refresh"
                @click="resetStaleUrls"
                :loading="loading.resetStale"
              >
                Reset Stale
              </v-btn>
              <v-btn
                size="small"
                variant="outlined"
                color="error"
                prepend-icon="mdi-restart"
                @click="resetFailedUrls"
                :loading="loading.resetFailed"
              >
                Reset Failed
              </v-btn>
              <v-btn
                icon="mdi-refresh"
                variant="text"
                size="small"
                @click="loadQueueStats"
                :loading="loading.queue"
              />
            </div>
          </v-card-title>

          <v-card-text>
            <v-row>
              <v-col cols="6" sm="4" md="2">
                <div class="queue-stat pending">
                  <div class="queue-stat-value">{{ queueStats.pending }}</div>
                  <div class="queue-stat-label">Pending</div>
                </div>
              </v-col>
              <v-col cols="6" sm="4" md="2">
                <div class="queue-stat processing">
                  <div class="queue-stat-value">{{ queueStats.processing }}</div>
                  <div class="queue-stat-label">Processing</div>
                </div>
              </v-col>
              <v-col cols="6" sm="4" md="2">
                <div class="queue-stat done">
                  <div class="queue-stat-value">{{ queueStats.done }}</div>
                  <div class="queue-stat-label">Done</div>
                </div>
              </v-col>
              <v-col cols="6" sm="4" md="2">
                <div class="queue-stat failed">
                  <div class="queue-stat-value">{{ queueStats.failed }}</div>
                  <div class="queue-stat-label">Failed</div>
                </div>
              </v-col>
              <v-col cols="6" sm="4" md="2">
                <div class="queue-stat blocked">
                  <div class="queue-stat-value">{{ queueStats.blocked }}</div>
                  <div class="queue-stat-label">Blocked</div>
                </div>
              </v-col>
              <v-col cols="6" sm="4" md="2">
                <div class="queue-stat total">
                  <div class="queue-stat-value">{{ queueTotal }}</div>
                  <div class="queue-stat-label">Total</div>
                </div>
              </v-col>
            </v-row>

            <!-- Queue Progress -->
            <div class="mt-4">
              <div class="metric-label mb-2">Queue Completion</div>
              <v-progress-linear
                :model-value="queueCompletionPercent"
                color="success"
                height="24"
                class="queue-progress"
              >
                <strong>{{ queueCompletionPercent }}% done</strong>
              </v-progress-linear>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- Logs Terminal -->
      <v-col cols="12">
        <v-card class="logs-terminal" elevation="2">
          <v-card-title class="d-flex align-center justify-space-between">
            <div class="d-flex align-center">
              <v-icon icon="mdi-console" class="mr-2" />
              <span>Live Logs</span>
              <v-chip
                v-if="autoScroll"
                color="primary"
                size="small"
                class="ml-2"
              >
                AUTO-SCROLL
              </v-chip>
              <v-chip
                size="small"
                class="ml-2"
              >
                {{ logs.length }} entries
              </v-chip>
            </div>

            <div class="d-flex align-center gap-2">
              <!-- Filter Buttons -->
              <v-btn-toggle
                v-model="logFilter"
                variant="outlined"
                density="compact"
                mandatory
              >
                <v-btn value="all">All</v-btn>
                <v-btn value="error" color="error">Errors</v-btn>
                <v-btn value="warning" color="warning">Warnings</v-btn>
              </v-btn-toggle>

              <!-- Auto-scroll Toggle -->
              <v-btn
                :icon="autoScroll ? 'mdi-pause' : 'mdi-play'"
                :color="autoScroll ? 'primary' : 'grey'"
                variant="text"
                @click="toggleAutoScroll"
                :title="autoScroll ? 'Disable Auto-scroll' : 'Enable Auto-scroll'"
              />

              <!-- Clear -->
              <v-btn
                icon="mdi-delete"
                variant="text"
                @click="clearLogs"
                title="Clear Logs"
              />
            </div>
          </v-card-title>

          <v-divider />

          <v-card-text class="pa-0">
            <div
              ref="logsContainer"
              class="logs-container"
              @scroll="onLogsScroll"
            >
              <div
                v-for="log in filteredLogs"
                :key="log.id"
                class="log-line"
                :class="`log-${log.level || 'info'}`"
              >
                <span class="log-timestamp">{{ formatLogTime(log.created_at) }}</span>
                <span class="log-level">{{ (log.level || 'INFO').toUpperCase() }}</span>
                <span class="log-message">{{ log.message }}</span>
                <span v-if="log.context" class="log-context">
                  {{ JSON.stringify(log.context) }}
                </span>
              </div>

              <div v-if="logs.length === 0" class="text-center pa-4 text-grey">
                No logs yet...
              </div>

              <div v-if="loading.logs" class="text-center pa-4">
                <v-progress-circular indeterminate size="32" />
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- Loading State -->
    <v-row v-if="!session && loading.session">
      <v-col cols="12" class="text-center pa-8">
        <v-progress-circular indeterminate size="64" />
        <div class="mt-4 text-h6">Loading session...</div>
      </v-col>
    </v-row>

    <!-- Error State -->
    <v-row v-if="!session && !loading.session && error">
      <v-col cols="12">
        <v-alert type="error" prominent>
          <div class="text-h6">Failed to load session</div>
          <div>{{ error }}</div>
        </v-alert>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { parserApi, type ParsingSession, type ParsingLog, type QueueStats } from '@/api/parser'
import { formatDistanceToNow, format, differenceInSeconds } from 'date-fns'

const router = useRouter()
const route = useRoute()

// Флаг для предотвращения обновления состояния при размонтировании
let isUnmounted = false
let abortController: AbortController | null = null

// State
const session = ref<ParsingSession | null>(null)
const logs = ref<ParsingLog[]>([])
const queueStats = ref<QueueStats>({
  pending: 0,
  processing: 0,
  done: 0,
  failed: 0,
  blocked: 0
})
const loading = ref({
  session: false,
  logs: false,
  queue: false,
  resetStale: false,
  resetFailed: false
})
const stopping = ref(false)
const error = ref<string | null>(null)
const autoScroll = ref(true)
const logFilter = ref<'all' | 'error' | 'warning'>('all')
const logsContainer = ref<HTMLElement | null>(null)

let refreshInterval: number | null = null
let logsPollingInterval: number | null = null
let queuePollingInterval: number | null = null

// Computed
const progressPercentage = computed(() => {
  if (!session.value || !session.value.total_urls) return 0
  return Math.round((session.value.processed_count / session.value.total_urls) * 100)
})

const queueTotal = computed(() => {
  return queueStats.value.pending + queueStats.value.processing + 
         queueStats.value.done + queueStats.value.failed + queueStats.value.blocked
})

const queueCompletionPercent = computed(() => {
  if (queueTotal.value === 0) return 0
  return Math.round((queueStats.value.done / queueTotal.value) * 100)
})

const runtime = computed(() => {
  if (!session.value?.started_at) return 'N/A'
  try {
    return formatDistanceToNow(new Date(session.value.started_at), { includeSeconds: true })
  } catch {
    return 'N/A'
  }
})

const etaMinutes = computed(() => {
  if (!session.value || session.value.status !== 'running') return 0
  if (!session.value.started_at || session.value.processed_count === 0) return 0
  
  const elapsed = differenceInSeconds(new Date(), new Date(session.value.started_at))
  const remaining = session.value.total_urls - session.value.processed_count
  const rate = session.value.processed_count / elapsed
  
  if (rate === 0) return 0
  return Math.round((remaining / rate) / 60)
})

const eta = computed(() => {
  if (!session.value || session.value.status !== 'running') return 'N/A'
  if (etaMinutes.value === 0) return 'Calculating...'
  
  if (etaMinutes.value > 60) {
    const hours = Math.floor(etaMinutes.value / 60)
    const mins = etaMinutes.value % 60
    return `${hours}h ${mins}m`
  }
  
  return `${etaMinutes.value}m`
})

const speed = computed(() => {
  if (!session.value?.started_at || session.value.processed_count === 0) return 'N/A'
  
  const elapsed = differenceInSeconds(new Date(), new Date(session.value.started_at))
  if (elapsed === 0) return 'N/A'
  
  const rate = session.value.processed_count / elapsed
  return `${rate.toFixed(2)} items/sec`
})

const filteredLogs = computed(() => {
  if (logFilter.value === 'all') return logs.value
  if (logFilter.value === 'error') return logs.value.filter(l => l.level === 'error' || l.level === 'critical')
  if (logFilter.value === 'warning') return logs.value.filter(l => l.level === 'warning')
  return logs.value
})

// Lifecycle
onMounted(() => {
  isUnmounted = false
  loadData()
  // Refresh session every 5 seconds
  refreshInterval = window.setInterval(loadSession, 5000)
  // Poll logs every 2 seconds
  logsPollingInterval = window.setInterval(loadLogs, 2000)
  // Poll queue stats every 10 seconds
  queuePollingInterval = window.setInterval(loadQueueStats, 10000)
})

onBeforeUnmount(() => {
  isUnmounted = true
  if (refreshInterval) clearInterval(refreshInterval)
  if (logsPollingInterval) clearInterval(logsPollingInterval)
  if (queuePollingInterval) clearInterval(queuePollingInterval)
  if (abortController) {
    (abortController as AbortController).abort()
  }
})

// Watch for new logs to auto-scroll
watch(() => logs.value.length, () => {
  if (autoScroll.value && !isUnmounted) {
    scrollToBottom()
  }
})

// Methods
async function loadData() {
  if (isUnmounted) return
  
  await Promise.all([
    loadSession(),
    loadLogs(),
    loadQueueStats()
  ])
}

async function loadSession() {
  if (isUnmounted) return
  
  try {
    loading.value.session = true
    error.value = null
    const sessionId = Number(route.params.id)
    const sessionData = await parserApi.getSession(sessionId)
    
    if (!isUnmounted) {
      session.value = sessionData
    }
  } catch (err: any) {
    if (!isUnmounted) {
      error.value = err.message || 'Unknown error'
      console.error('Failed to load session:', err)
    }
  } finally {
    if (!isUnmounted) {
      loading.value.session = false
    }
  }
}

async function loadLogs() {
  if (isUnmounted) return
  
  try {
    loading.value.logs = true
    const sessionId = Number(route.params.id)
    const response = await parserApi.getSessionLogs(sessionId, {
      per_page: 500
    })
    
    if (!isUnmounted) {
      logs.value = response.data
    }
  } catch (err) {
    if (!isUnmounted) {
      console.error('Failed to load logs:', err)
    }
  } finally {
    if (!isUnmounted) {
      loading.value.logs = false
    }
  }
}

async function forceStop() {
  if (isUnmounted || !session.value) return
  
  if (!confirm('Are you sure you want to force stop this session?')) return
  
  try {
    stopping.value = true
    await parserApi.stopSession(session.value.id)
    
    if (!isUnmounted) {
      await loadSession()
    }
  } catch (err: any) {
    if (!isUnmounted) {
      // Extract error message from API response
      const errorMessage = err?.response?.data?.message || 'Failed to stop session'
      console.error('Failed to stop session:', err)
      alert(errorMessage)
    }
  } finally {
    if (!isUnmounted) {
      stopping.value = false
    }
  }
}

async function loadQueueStats() {
  if (isUnmounted || !session.value) return
  
  try {
    loading.value.queue = true
    const stats = await parserApi.getQueueStats(session.value.supplier)
    
    if (!isUnmounted) {
      queueStats.value = stats
    }
  } catch (err) {
    if (!isUnmounted) {
      console.error('Failed to load queue stats:', err)
    }
  } finally {
    if (!isUnmounted) {
      loading.value.queue = false
    }
  }
}

async function resetStaleUrls() {
  if (isUnmounted) return
  
  try {
    loading.value.resetStale = true
    const result = await parserApi.resetStaleUrls()
    
    if (!isUnmounted) {
      alert(`Reset ${result.reset_count} stale URLs`)
      await loadQueueStats()
    }
  } catch (err) {
    if (!isUnmounted) {
      console.error('Failed to reset stale URLs:', err)
      alert('Failed to reset stale URLs')
    }
  } finally {
    if (!isUnmounted) {
      loading.value.resetStale = false
    }
  }
}

async function resetFailedUrls() {
  if (isUnmounted || !session.value) return
  
  if (!confirm('Reset all failed URLs back to pending? This will retry them.')) return
  
  try {
    loading.value.resetFailed = true
    const result = await parserApi.resetFailedUrls(session.value.supplier)
    
    if (!isUnmounted) {
      alert(`Reset ${result.reset_count} failed URLs`)
      await loadQueueStats()
    }
  } catch (err) {
    if (!isUnmounted) {
      console.error('Failed to reset failed URLs:', err)
      alert('Failed to reset failed URLs')
    }
  } finally {
    if (!isUnmounted) {
      loading.value.resetFailed = false
    }
  }
}

function getStatusColor(status: string): string {
  switch (status) {
    case 'completed': return 'success'
    case 'running': return 'primary'
    case 'failed': return 'error'
    case 'stopped': return 'warning'
    case 'pending': return 'grey'
    default: return 'grey'
  }
}

function formatDate(date: string | null): string {
  if (!date) return 'N/A'
  try {
    return format(new Date(date), 'MMM dd, HH:mm:ss')
  } catch {
    return 'Invalid date'
  }
}

function formatLogTime(date: string): string {
  try {
    return format(new Date(date), 'HH:mm:ss.SSS')
  } catch {
    return ''
  }
}

function toggleAutoScroll() {
  autoScroll.value = !autoScroll.value
  if (autoScroll.value) {
    scrollToBottom()
  }
}

function clearLogs() {
  logs.value = []
}

function scrollToBottom() {
  nextTick(() => {
    if (logsContainer.value && !isUnmounted) {
      logsContainer.value.scrollTop = logsContainer.value.scrollHeight
    }
  })
}

function onLogsScroll() {
  if (!logsContainer.value) return
  
  const { scrollTop, scrollHeight, clientHeight } = logsContainer.value
  const isAtBottom = scrollTop + clientHeight >= scrollHeight - 10
  
  // Disable auto-scroll if user scrolled up
  if (!isAtBottom && autoScroll.value) {
    autoScroll.value = false
  }
}

function goBack() {
  router.push('/parser')
}
</script>

<style scoped lang="scss">
.session-monitor {
  max-width: 1800px;
  margin: 0 auto;
}

.session-header,
.metrics-card,
.logs-terminal {
  border-radius: 0 !important;
}

.progress-bar {
  border-radius: 0 !important;
  font-size: 0.9rem;
}

.stat-box {
  text-align: center;
  padding: 12px;
  border: 1px solid rgba(0, 0, 0, 0.12);

  .stat-value {
    font-size: 2rem;
    font-weight: bold;
    margin: 8px 0;
  }

  .stat-label {
    font-size: 0.875rem;
    color: rgba(0, 0, 0, 0.6);
    text-transform: uppercase;
  }
}

.metric-label {
  font-size: 0.75rem;
  color: rgba(0, 0, 0, 0.6);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.metric-value {
  font-size: 1.25rem;
  font-weight: 500;
}

.logs-terminal {
  .logs-container {
    height: 600px;
    overflow-y: auto;
    background: #1e1e1e;
    font-family: 'Courier New', Courier, monospace;
    font-size: 0.875rem;
    line-height: 1.5;

    .log-line {
      padding: 4px 12px;
      border-left: 3px solid transparent;
      display: flex;
      gap: 12px;

      &:hover {
        background: rgba(255, 255, 255, 0.05);
      }

      &.log-error,
      &.log-critical {
        border-left-color: #f44336;
        background: rgba(244, 67, 54, 0.1);
      }

      &.log-warning {
        border-left-color: #ff9800;
        background: rgba(255, 152, 0, 0.1);
      }

      &.log-info {
        border-left-color: #2196f3;
      }

      .log-timestamp {
        color: #666;
        white-space: nowrap;
      }

      .log-level {
        color: #fff;
        font-weight: bold;
        min-width: 60px;
        white-space: nowrap;
      }

      .log-message {
        color: #ddd;
        flex: 1;
      }

      .log-context {
        color: #888;
        font-size: 0.75rem;
      }
    }
  }
}

.gap-2 {
  gap: 8px;
}

.queue-stats-card {
  .queue-stat {
    text-align: center;
    padding: 16px;
    border-radius: 8px;
    
    .queue-stat-value {
      font-size: 1.75rem;
      font-weight: bold;
      margin-bottom: 4px;
    }
    
    .queue-stat-label {
      font-size: 0.75rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      opacity: 0.8;
    }
    
    &.pending {
      background: rgba(255, 193, 7, 0.15);
      color: #f57c00;
    }
    
    &.processing {
      background: rgba(33, 150, 243, 0.15);
      color: #1976d2;
    }
    
    &.done {
      background: rgba(76, 175, 80, 0.15);
      color: #388e3c;
    }
    
    &.failed {
      background: rgba(244, 67, 54, 0.15);
      color: #d32f2f;
    }
    
    &.blocked {
      background: rgba(158, 158, 158, 0.15);
      color: #616161;
    }
    
    &.total {
      background: rgba(63, 81, 181, 0.15);
      color: #303f9f;
    }
  }
  
  .queue-progress {
    border-radius: 4px;
  }
}
</style>
