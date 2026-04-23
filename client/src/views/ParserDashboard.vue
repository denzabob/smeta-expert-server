<template>
  <PageContainer class="parser-dashboard">
    <PageHeader
      title="Parser Dashboard"
      subtitle="Состояние поставщиков, текущие parser sessions и быстрые действия запуска."
    >
      <template #actions>
        <ButtonGroup>
          <v-btn
            variant="text"
            prepend-icon="mdi-refresh"
            :loading="loading.status || loading.suppliers"
            @click="loadData"
          >
            Обновить
          </v-btn>
        </ButtonGroup>
      </template>
    </PageHeader>

    <!-- System Health Status Bar -->
    <v-alert
      :type="systemStatus?.scheduler_running ? 'success' : 'error'"
      :icon="systemStatus?.scheduler_running ? 'mdi-check-circle' : 'mdi-alert-circle'"
      variant="tonal"
      density="compact"
      class="system-status-alert"
    >
      <div class="system-status-alert__content">
        <div>
          <div class="system-status-alert__title">
            {{ systemStatus?.scheduler_running ? 'System Operational' : 'Scheduler Offline' }}
          </div>
          <div class="system-status-alert__meta">
            Active Sessions: {{ systemStatus?.active_sessions || 0 }} | 
            24h Total: {{ systemStatus?.total_sessions_24h || 0 }} | 
            Health Score: {{ systemStatus?.health_score || 0 }}%
          </div>
        </div>
        <div>
          <v-btn
            icon="mdi-refresh"
            variant="text"
            @click="loadSystemStatus"
            :loading="loading.status"
          />
        </div>
      </div>
    </v-alert>

    <!-- Suppliers Grid -->
    <v-row>
      <v-col
        v-for="supplier in suppliers"
        :key="supplier.supplier"
        cols="12"
        md="6"
        lg="4"
      >
        <SectionCard
          class="supplier-card"
          :class="getSupplierCardClass(supplier)"
        >
          <!-- Card Header -->
          <template #title>
            <div class="d-flex align-center">
              <v-icon
                :icon="getSupplierIcon(supplier)"
                :color="getSupplierColor(supplier)"
                class="mr-2"
                size="large"
              />
              <div>
                <div class="text-h6">{{ supplier.supplier }}</div>
                <StatusChip
                  :color="supplier.active ? 'success' : 'grey'"
                  size="small"
                  class="mt-1"
                >
                  {{ supplier.active ? 'Active' : 'Disabled' }}
                </StatusChip>
              </div>
            </div>
          </template>

          <template #header-actions>
            <!-- Activity Indicator -->
            <div v-if="supplier.current_pid" class="activity-pulse">
              <v-icon
                icon="mdi-circle"
                color="primary"
                size="small"
                class="pulse-icon"
              />
              <span class="text-caption ml-1">PID: {{ supplier.current_pid }}</span>
            </div>
          </template>

          <!-- Metrics -->
            <v-row dense>
              <!-- Health Score -->
              <v-col cols="12">
                <div class="metric-label">Health Score (24h)</div>
                <v-progress-linear
                  :model-value="supplier.health_score"
                  :color="getHealthColor(supplier.health_score)"
                  height="20"
                  class="health-bar"
                >
                  <strong>{{ supplier.health_score }}%</strong>
                </v-progress-linear>
              </v-col>

              <!-- Last Sync -->
              <v-col cols="6">
                <div class="metric-label">Last Sync</div>
                <div class="metric-value">
                  {{ formatLastSync(supplier.last_sync) }}
                </div>
              </v-col>

              <!-- Success Rate -->
              <v-col cols="6">
                <div class="metric-label">Success Rate</div>
                <div class="metric-value">
                  {{ supplier.success_rate_24h }}%
                </div>
              </v-col>

              <!-- Sessions Count -->
              <v-col cols="12">
                <div class="metric-label">Sessions (24h)</div>
                <div class="metric-value">
                  {{ supplier.sessions_count_24h }} runs
                </div>
              </v-col>
            </v-row>

          <!-- Actions -->
          <template #actions>
            <v-btn
              v-if="!supplier.current_pid"
              color="primary"
              variant="flat"
              prepend-icon="mdi-play"
              @click="openRunDialog(supplier.supplier)"
              :disabled="!supplier.active"
              :loading="loading.run[supplier.supplier]"
            >
              Run
            </v-btn>

            <v-btn
              v-else
              color="error"
              variant="flat"
              prepend-icon="mdi-stop-circle"
              @click="stopSupplier(supplier)"
              :loading="loading.stop?.[supplier.supplier]"
            >
              Force Stop
            </v-btn>

            <v-btn
              color="primary"
              variant="tonal"
              prepend-icon="mdi-link-variant"
              @click="openCollectDialog(supplier.supplier)"
              :disabled="!supplier.active"
              :loading="loading.collect[supplier.supplier]"
            >
              Collect URLs
            </v-btn>

            <v-spacer />

            <v-btn
              variant="text"
              icon="mdi-history"
              @click="goToHistory(supplier.supplier)"
              title="View History"
            />

            <v-btn
              variant="text"
              icon="mdi-cog"
              @click="goToSettings(supplier.supplier)"
              title="Settings"
            />
          </template>
        </SectionCard>
      </v-col>
    </v-row>

    <!-- Run Session Dialog -->
    <v-dialog v-model="runDialog.open" max-width="600">
      <v-card class="parser-dialog-card">
        <v-card-title>
          <span class="text-h5">Run Parser: {{ runDialog.supplier }}</span>
        </v-card-title>

        <v-card-text>
          <v-form ref="runForm">
            <v-textarea
              v-model="runDialog.config"
              label="Configuration (JSON)"
              placeholder='{}'
              rows="10"
              variant="outlined"
              class="mono-font"
              :rules="[validateJSON]"
            />
          </v-form>
        </v-card-text>

        <AppActionFooter>
          <v-btn
            variant="text"
            @click="runDialog.open = false"
          >
            Cancel
          </v-btn>
          <v-btn
            color="primary"
            variant="flat"
            @click="runParser"
            :loading="loading.run[runDialog.supplier]"
          >
            Start
          </v-btn>
        </AppActionFooter>
      </v-card>
    </v-dialog>

    <v-dialog v-model="collectDialog.open" max-width="520">
      <v-card class="parser-dialog-card">
        <v-card-title>
          <span class="text-h6">Collect URLs: {{ collectDialog.supplier }}</span>
        </v-card-title>

        <v-card-text>
          <v-select
            v-model="collectDialog.profileId"
            :items="collectDialog.profileItems"
            label="Профиль сбора"
            variant="outlined"
            density="compact"
            :loading="collectDialog.loading"
          />
          <div class="text-caption text-grey-darken-1 mt-2">
            Профиль задаёт фильтры/категории для collect-urls. Без профиля используется базовая конфигурация.
          </div>
        </v-card-text>

        <AppActionFooter>
          <v-btn variant="text" @click="collectDialog.open = false">Отмена</v-btn>
          <v-btn color="primary" :loading="loading.collect[collectDialog.supplier]" @click="confirmCollectUrls">
            Запустить
          </v-btn>
        </AppActionFooter>
      </v-card>
    </v-dialog>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color">
      {{ snackbar.message }}
    </v-snackbar>
  </PageContainer>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import AppActionFooter from '@/components/layout/AppActionFooter.vue'
import ButtonGroup from '@/components/layout/ButtonGroup.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import StatusChip from '@/components/layout/StatusChip.vue'
import { parserApi, type SystemStatus, type SupplierHealth } from '@/api/parser'
import { formatDistanceToNow } from 'date-fns'

const router = useRouter()

// Флаг для предотвращения обновления состояния при размонтировании
let isUnmounted = false
let abortController: AbortController | null = null

// State
const systemStatus = ref<SystemStatus | null>(null)
const suppliers = ref<SupplierHealth[]>([])
const loading = ref({
  status: false,
  suppliers: false,
  run: {} as Record<string, boolean>,
  collect: {} as Record<string, boolean>,
  stop: {} as Record<string, boolean>
})

const runDialog = ref({
  open: false,
  supplier: '',
  config: '{}'
})

const collectDialog = ref({
  open: false,
  supplier: '',
  profileId: null as number | null,
  profileItems: [] as Array<{ title: string; value: number | null }>,
  loading: false
})

const snackbar = ref({
  show: false,
  message: '',
  color: 'success'
})

let refreshInterval: number | null = null

// Lifecycle
onMounted(() => {
  isUnmounted = false
  loadData()
  // Auto-refresh every 10 seconds
  refreshInterval = window.setInterval(loadData, 10000)
})

onBeforeUnmount(() => {
  isUnmounted = true
  if (refreshInterval) {
    clearInterval(refreshInterval)
  }
  if (abortController) {
    (abortController as AbortController).abort()
  }
})

// Methods
async function loadData() {
  if (isUnmounted) return
  
  await Promise.all([
    loadSystemStatus(),
    loadSuppliers()
  ])
}

async function loadSystemStatus() {
  if (isUnmounted) return
  
  try {
    loading.value.status = true
    const data = await parserApi.getSystemStatus()
    
    if (!isUnmounted) {
      systemStatus.value = data
    }
  } catch (error) {
    if (!isUnmounted) {
      console.error('Failed to load system status:', error)
    }
  } finally {
    if (!isUnmounted) {
      loading.value.status = false
    }
  }
}

async function loadSuppliers() {
  if (isUnmounted) return
  
  try {
    loading.value.suppliers = true
    const data = await parserApi.getSuppliersHealth()
    
    console.log('Suppliers data received:', data)
    
    if (!isUnmounted) {
      suppliers.value = data
    }
  } catch (error) {
    if (!isUnmounted) {
      console.error('Failed to load suppliers:', error)
    }
  } finally {
    if (!isUnmounted) {
      loading.value.suppliers = false
    }
  }
}

function getSupplierIcon(supplier: SupplierHealth): string {
  if (supplier.current_pid) return 'mdi-sync'
  if (!supplier.active) return 'mdi-pause-circle'
  if (supplier.health_score >= 80) return 'mdi-check-circle'
  if (supplier.health_score >= 50) return 'mdi-alert-circle'
  return 'mdi-close-circle'
}

function getSupplierColor(supplier: SupplierHealth): string {
  if (supplier.current_pid) return 'primary'
  if (!supplier.active) return 'grey'
  if (supplier.health_score >= 80) return 'success'
  if (supplier.health_score >= 50) return 'warning'
  return 'error'
}

function getSupplierCardClass(supplier: SupplierHealth): string {
  if (supplier.current_pid) return 'card-running'
  if (!supplier.active) return 'card-disabled'
  return ''
}

function getHealthColor(score: number): string {
  if (score >= 80) return 'success'
  if (score >= 50) return 'warning'
  return 'error'
}

function formatLastSync(lastSync: string | null): string {
  if (!lastSync) return 'Never'
  try {
    return formatDistanceToNow(new Date(lastSync), { addSuffix: true })
  } catch {
    return 'Unknown'
  }
}

function openRunDialog(supplier: string) {
  runDialog.value = {
    open: true,
    supplier,
    config: '{}'
  }
}

async function openCollectDialog(supplier: string) {
  if (isUnmounted) return

  collectDialog.value = {
    open: true,
    supplier,
    profileId: null,
    profileItems: [{ title: 'Без профиля (базовая конфигурация)', value: null }],
    loading: true
  }

  try {
    const profiles = await parserApi.getCollectProfiles(supplier)
    const items = profiles.map((profile: any) => ({
      title: profile.is_default ? `${profile.name} (по умолчанию)` : profile.name,
      value: profile.id
    }))

    const defaultProfile = profiles.find((p: any) => p.is_default)
    collectDialog.value.profileItems = [
      { title: 'Без профиля (базовая конфигурация)', value: null },
      ...items
    ]
    collectDialog.value.profileId = defaultProfile?.id ?? null
  } catch (error) {
    if (!isUnmounted) {
      console.error('Failed to load collect profiles:', error)
      showSnackbar('Не удалось загрузить профили сбора URL.', 'error')
    }
  } finally {
    if (!isUnmounted) {
      collectDialog.value.loading = false
    }
  }
}

async function confirmCollectUrls() {
  if (isUnmounted) return

  const supplier = collectDialog.value.supplier
  try {
    loading.value.collect[supplier] = true
    const response = await parserApi.collectSupplierUrlsWithProfile(supplier, collectDialog.value.profileId ?? undefined)
    if (!isUnmounted) {
      collectDialog.value.open = false
      const sessionInfo = response?.session_id ? ` (session #${response.session_id})` : ''
      showSnackbar(`URL сбор запущен для ${supplier}${sessionInfo}`, 'success')
    }
  } catch (error) {
    if (!isUnmounted) {
      console.error('Failed to collect URLs:', error)
      showSnackbar('Не удалось запустить сбор URL. Проверьте консоль.', 'error')
    }
  } finally {
    if (!isUnmounted) {
      loading.value.collect[supplier] = false
    }
  }
}

function validateJSON(value: string): boolean | string {
  try {
    JSON.parse(value)
    return true
  } catch {
    return 'Invalid JSON'
  }
}

async function runParser() {
  if (isUnmounted) return
  
  try {
    const config = JSON.parse(runDialog.value.config)
    config.collect_urls = false
    loading.value.run[runDialog.value.supplier] = true
    
    const session = await parserApi.createSession(runDialog.value.supplier, config)
    
    if (!isUnmounted) {
      runDialog.value.open = false
      
      // Navigate to monitoring page
      router.push(`/parser/sessions/${session.id}`)
    }
  } catch (error) {
    if (!isUnmounted) {
      console.error('Failed to start parser:', error)
      showSnackbar('Не удалось запустить парсер. Проверьте консоль.', 'error')
    }
  } finally {
    if (!isUnmounted) {
      loading.value.run[runDialog.value.supplier] = false
    }
  }
}

async function stopSupplier(supplier: SupplierHealth) {
  if (isUnmounted || !supplier.current_pid) return
  
  try {
    loading.value.stop[supplier.supplier] = true
    
    // Get the current session and stop it
    const sessions = await parserApi.getSessions({ 
      supplier: supplier.supplier,
      status: 'running'
    })
    
    if (sessions.data && sessions.data.length > 0) {
      const session = sessions.data[0]!
      await parserApi.stopSession(session.id)
      
      if (!isUnmounted) {
        showSnackbar(`Сеанс парсирования ${supplier.supplier} остановлен`, 'success')
        // Reload data immediately
        await loadData()
      }
    }
  } catch (error) {
    if (!isUnmounted) {
      console.error('Failed to stop supplier:', error)
      showSnackbar('Не удалось остановить парсирование. Проверьте консоль.', 'error')
    }
  } finally {
    if (!isUnmounted) {
      loading.value.stop[supplier.supplier] = false
    }
  }
}

function goToHistory(supplier: string) {
  router.push(`/parser/history?supplier=${supplier}`)
}

function goToSettings(supplier: string) {
  router.push(`/parser/suppliers/${supplier}/config`)
}

function showSnackbar(message: string, color: string = 'success') {
  if (!isUnmounted) {
    snackbar.value = { show: true, message, color }
  }
}
</script>

<style scoped lang="scss">
.parser-dashboard {
  max-width: 1600px;
  margin: 0 auto;
}

.system-status-alert {
  margin: 0;
}

.system-status-alert__content {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--ds-space-12);
}

.system-status-alert__title {
  font-weight: 700;
  line-height: 1.35;
}

.system-status-alert__meta {
  margin-top: 2px;
  color: var(--ds-text-secondary);
  font-size: 0.82rem;
  line-height: 1.4;
}

.supplier-card {
  height: 100%;
  border: 1px solid var(--ds-border-color);
  transition: border-color 0.2s ease, background-color 0.2s ease;

  &:hover {
    border-color: rgba(var(--v-theme-primary), 0.42);
  }

  &.card-running {
    border-color: rgba(var(--v-theme-primary), 0.56);
  }

  &.card-disabled {
    opacity: 0.7;
  }
}

.metric-label {
  font-size: 0.75rem;
  color: var(--ds-text-tertiary);
  text-transform: uppercase;
  letter-spacing: 0;
  margin-bottom: 4px;
}

.metric-value {
  font-size: 1rem;
  font-weight: 500;
}

.health-bar {
  border-radius: var(--ds-radius-8) !important;
}

.activity-pulse {
  display: flex;
  align-items: center;
}

.pulse-icon {
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0%, 100% {
    opacity: 1;
  }
  50% {
    opacity: 0.3;
  }
}

.mono-font {
  font-family: 'Courier New', Courier, monospace;
}

.parser-dialog-card {
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-16) !important;
  overflow: hidden;
}
</style>
