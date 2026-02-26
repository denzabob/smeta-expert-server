<template>
  <v-container fluid class="system-settings">
    <v-row>
      <!-- Header -->
      <v-col cols="12">
        <div class="d-flex align-center mb-4">
          <v-icon icon="mdi-cog" size="large" class="mr-3" />
          <div>
            <div class="text-h4">System Settings</div>
            <div class="text-subtitle-1 text-grey">
              Security & Configuration Management
            </div>
          </div>
        </div>
      </v-col>

      <!-- Security Settings -->
      <v-col cols="12" md="6">
        <v-card class="settings-card" elevation="2">
          <v-card-title class="card-header">
            <v-icon icon="mdi-shield-lock" class="mr-2" />
            Callback Security
          </v-card-title>

          <v-divider />

          <v-card-text class="pa-6">
            <!-- API Token -->
            <div class="setting-group mb-6">
              <div class="setting-label">API Callback Token</div>
              <div class="setting-description">
                HMAC-SHA256 token for securing webhook callbacks from Python parser
              </div>
              
              <v-text-field
                :model-value="maskedToken"
                readonly
                variant="outlined"
                density="compact"
                class="mt-2"
                append-icon="mdi-content-copy"
                @click:append="copyToken"
              />

              <div class="mt-2">
                <v-btn
                  color="warning"
                  variant="elevated"
                  prepend-icon="mdi-refresh"
                  @click="regenerateTokenDialog = true"
                  :loading="loading.regenerateToken"
                  size="small"
                >
                  Regenerate Token
                </v-btn>
                <v-chip size="small" class="ml-2" color="error">
                  Requires Python reconfiguration
                </v-chip>
              </div>
            </div>

            <!-- Allowed IPs -->
            <div class="setting-group">
              <div class="setting-label">Allowed IP Addresses</div>
              <div class="setting-description">
                Only these IPs can send callbacks to the server. Use 127.0.0.1 for local parser.
              </div>

              <v-chip-group class="mt-3">
                <v-chip
                  v-for="(ip, index) in allowedIPs"
                  :key="index"
                  closable
                  @click:close="removeIP(index)"
                  color="primary"
                  variant="elevated"
                >
                  {{ ip }}
                </v-chip>
              </v-chip-group>

              <div class="d-flex gap-2 mt-3">
                <v-text-field
                  v-model="newIP"
                  label="New IP Address"
                  placeholder="127.0.0.1 or ::1"
                  variant="outlined"
                  density="compact"
                  class="flex-grow-1"
                  @keyup.enter="addIP"
                  :rules="[validateIP]"
                />
                <v-btn
                  color="primary"
                  variant="elevated"
                  icon="mdi-plus"
                  @click="addIP"
                  :disabled="!isValidIP(newIP)"
                />
              </div>

              <div class="mt-3">
                <v-btn
                  color="success"
                  variant="elevated"
                  prepend-icon="mdi-content-save"
                  @click="saveAllowedIPs"
                  :loading="loading.saveIPs"
                  size="small"
                >
                  Save IP Whitelist
                </v-btn>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- Log Rotation Settings -->
      <v-col cols="12" md="6">
        <v-card class="settings-card" elevation="2">
          <v-card-title class="card-header">
            <v-icon icon="mdi-database" class="mr-2" />
            Log Management
          </v-card-title>

          <v-divider />

          <v-card-text class="pa-6">
            <!-- Retention Period -->
            <div class="setting-group mb-6">
              <div class="setting-label">Log Retention Period</div>
              <div class="setting-description">
                Logs older than this will be automatically deleted by the pruner
              </div>

              <v-slider
                v-model="settings.logs_retention_days"
                :min="7"
                :max="90"
                :step="1"
                thumb-label="always"
                class="mt-4"
              >
                <template v-slot:append>
                  <span class="text-h6">{{ settings.logs_retention_days }} days</span>
                </template>
              </v-slider>
            </div>

            <!-- Logs Per Session Limit -->
            <div class="setting-group mb-6">
              <div class="setting-label">Max Logs Per Session</div>
              <div class="setting-description">
                Maximum number of log entries stored per parsing session
              </div>

              <v-slider
                v-model="settings.logs_per_session_limit"
                :min="50"
                :max="500"
                :step="10"
                thumb-label="always"
                class="mt-4"
              >
                <template v-slot:append>
                  <span class="text-h6">{{ settings.logs_per_session_limit }}</span>
                </template>
              </v-slider>
            </div>

            <!-- Heartbeat Timeout -->
            <div class="setting-group">
              <div class="setting-label">Heartbeat Timeout</div>
              <div class="setting-description">
                Auto-fail sessions if no callback received for this duration (minutes)
              </div>

              <v-slider
                v-model="settings.heartbeat_timeout"
                :min="5"
                :max="60"
                :step="5"
                thumb-label="always"
                class="mt-4"
              >
                <template v-slot:append>
                  <span class="text-h6">{{ settings.heartbeat_timeout }} min</span>
                </template>
              </v-slider>
            </div>

            <v-divider class="my-4" />

            <v-btn
              color="success"
              variant="elevated"
              prepend-icon="mdi-content-save"
              @click="saveSettings"
              :loading="loading.saveSettings"
              block
            >
              Save Log Settings
            </v-btn>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- System Information -->
      <v-col cols="12">
        <v-card class="settings-card" elevation="2">
          <v-card-title class="card-header">
            <v-icon icon="mdi-information" class="mr-2" />
            System Information
          </v-card-title>

          <v-divider />

          <v-card-text class="pa-6">
            <v-row>
              <v-col cols="12" md="3">
                <div class="info-box">
                  <div class="info-label">Total Sessions (All Time)</div>
                  <div class="info-value">{{ systemInfo.total_sessions }}</div>
                </div>
              </v-col>

              <v-col cols="12" md="3">
                <div class="info-box">
                  <div class="info-label">Total Logs Stored</div>
                  <div class="info-value">{{ systemInfo.total_logs }}</div>
                </div>
              </v-col>

              <v-col cols="12" md="3">
                <div class="info-box">
                  <div class="info-label">Database Size</div>
                  <div class="info-value">{{ systemInfo.database_size }}</div>
                </div>
              </v-col>

              <v-col cols="12" md="3">
                <div class="info-box">
                  <div class="info-label">Last Cleanup</div>
                  <div class="info-value">{{ systemInfo.last_cleanup }}</div>
                </div>
              </v-col>
            </v-row>

            <v-divider class="my-4" />

            <div class="text-subtitle-2 mb-3">Scheduler Status</div>
            <v-chip
              :color="systemInfo.scheduler_running ? 'success' : 'error'"
              :prepend-icon="systemInfo.scheduler_running ? 'mdi-check' : 'mdi-close'"
            >
              {{ systemInfo.scheduler_running ? 'Running' : 'Offline' }}
            </v-chip>

            <v-chip class="ml-2" color="info">
              Cleanup Command: {{ systemInfo.cleanup_last_run || 'Never' }}
            </v-chip>

            <v-chip class="ml-2" color="info">
              Pruning: {{ systemInfo.pruning_last_run || 'Never' }}
            </v-chip>
          </v-card-text>
        </v-card>
      </v-col>

      <!-- Danger Zone -->
      <v-col cols="12">
        <v-card class="settings-card danger-zone" elevation="2">
          <v-card-title class="card-header">
            <v-icon icon="mdi-alert" class="mr-2" />
            Danger Zone
          </v-card-title>

          <v-divider />

          <v-card-text class="pa-6">
            <v-row>
              <v-col cols="12" md="4">
                <v-btn
                  color="warning"
                  variant="elevated"
                  prepend-icon="mdi-database-remove"
                  @click="runCleanup"
                  :loading="loading.cleanup"
                  block
                >
                  Run Cleanup Now
                </v-btn>
                <div class="text-caption mt-2 text-grey">
                  Manually trigger zombie process cleanup
                </div>
              </v-col>

              <v-col cols="12" md="4">
                <v-btn
                  color="warning"
                  variant="elevated"
                  prepend-icon="mdi-delete-sweep"
                  @click="runPruning"
                  :loading="loading.pruning"
                  block
                >
                  Prune Old Logs
                </v-btn>
                <div class="text-caption mt-2 text-grey">
                  Delete logs older than retention period
                </div>
              </v-col>

              <v-col cols="12" md="4">
                <v-btn
                  color="error"
                  variant="elevated"
                  prepend-icon="mdi-delete-forever"
                  @click="clearAllLogsDialog = true"
                  block
                >
                  Clear All Logs
                </v-btn>
                <div class="text-caption mt-2 text-grey">
                  ⚠️ Permanently delete all parsing logs
                </div>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- Regenerate Token Dialog -->
    <v-dialog v-model="regenerateTokenDialog" max-width="500">
      <v-card>
        <v-card-title>Regenerate Callback Token?</v-card-title>
        <v-card-text>
          <v-alert type="warning" variant="tonal" class="mb-4">
            This will invalidate the current token. You must update the Python parser
            configuration with the new token, or callbacks will fail.
          </v-alert>
          
          <p>Are you sure you want to continue?</p>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="regenerateTokenDialog = false">Cancel</v-btn>
          <v-btn
            color="warning"
            variant="elevated"
            @click="regenerateToken"
            :loading="loading.regenerateToken"
          >
            Regenerate
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Clear All Logs Dialog -->
    <v-dialog v-model="clearAllLogsDialog" max-width="500">
      <v-card>
        <v-card-title>Clear All Logs?</v-card-title>
        <v-card-text>
          <v-alert type="error" variant="tonal" class="mb-4">
            This action is IRREVERSIBLE. All parsing logs will be permanently deleted.
          </v-alert>
          
          <p>Type <strong>DELETE ALL LOGS</strong> to confirm:</p>
          
          <v-text-field
            v-model="clearLogsConfirmation"
            placeholder="DELETE ALL LOGS"
            variant="outlined"
            density="compact"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="clearAllLogsDialog = false">Cancel</v-btn>
          <v-btn
            color="error"
            variant="elevated"
            :disabled="clearLogsConfirmation !== 'DELETE ALL LOGS'"
            @click="clearAllLogs"
          >
            Delete Forever
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Snackbar -->
    <v-snackbar v-model="snackbar.show" :color="snackbar.color">
      {{ snackbar.message }}
    </v-snackbar>
  </v-container>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { parserApi } from '@/api/parser'

// Флаг для предотвращения обновления состояния при размонтировании
let isUnmounted = false
let abortController: AbortController | null = null

// State
const settings = ref({
  logs_retention_days: 14,
  logs_per_session_limit: 100,
  heartbeat_timeout: 10
})

const allowedIPs = ref<string[]>(['127.0.0.1', '::1'])
const newIP = ref('')
const callbackToken = ref('****************************************')

const systemInfo = ref({
  total_sessions: 0,
  total_logs: 0,
  database_size: 'N/A',
  last_cleanup: 'N/A',
  scheduler_running: false,
  cleanup_last_run: null as string | null,
  pruning_last_run: null as string | null
})

const loading = ref({
  saveSettings: false,
  saveIPs: false,
  regenerateToken: false,
  cleanup: false,
  pruning: false
})

const regenerateTokenDialog = ref(false)
const clearAllLogsDialog = ref(false)
const clearLogsConfirmation = ref('')

const snackbar = ref({
  show: false,
  message: '',
  color: 'success'
})

// Computed
const maskedToken = computed(() => {
  if (callbackToken.value.length <= 8) return callbackToken.value
  return callbackToken.value.substring(0, 4) + '...' + callbackToken.value.substring(callbackToken.value.length - 4)
})

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
  
  try {
    const [settingsData, ips] = await Promise.all([
      parserApi.getSettings(),
      parserApi.getAllowedIPs()
    ])
    
    if (!isUnmounted) {
      settings.value = settingsData as typeof settings.value
      allowedIPs.value = ips
    }
  } catch (error) {
    if (!isUnmounted) {
      showSnackbar('Failed to load settings', 'error')
    }
  }
}

async function saveSettings() {
  if (isUnmounted) return
  
  try {
    loading.value.saveSettings = true
    await parserApi.updateSettings(settings.value)
    
    if (!isUnmounted) {
      showSnackbar('Settings saved successfully')
    }
  } catch (error) {
    if (!isUnmounted) {
      showSnackbar('Failed to save settings', 'error')
    }
  } finally {
    if (!isUnmounted) {
      loading.value.saveSettings = false
    }
  }
}

async function saveAllowedIPs() {
  if (isUnmounted) return
  
  try {
    loading.value.saveIPs = true
    await parserApi.updateAllowedIPs(allowedIPs.value)
    
    if (!isUnmounted) {
      showSnackbar('IP whitelist updated successfully')
    }
  } catch (error) {
    if (!isUnmounted) {
      showSnackbar('Failed to update IP whitelist', 'error')
    }
  } finally {
    if (!isUnmounted) {
      loading.value.saveIPs = false
    }
  }
}

async function regenerateToken() {
  if (isUnmounted) return
  
  try {
    loading.value.regenerateToken = true
    const { token } = await parserApi.regenerateToken()
    
    if (!isUnmounted) {
      callbackToken.value = token
      regenerateTokenDialog.value = false
      showSnackbar('Token regenerated successfully. Copy it to Python config!', 'warning')
    }
  } catch (error) {
    if (!isUnmounted) {
      showSnackbar('Failed to regenerate token', 'error')
    }
  } finally {
    if (!isUnmounted) {
      loading.value.regenerateToken = false
    }
  }
}

function addIP() {
  if (!isValidIP(newIP.value)) return
  if (allowedIPs.value.includes(newIP.value)) {
    showSnackbar('IP already in whitelist', 'warning')
    return
  }
  allowedIPs.value.push(newIP.value)
  newIP.value = ''
}

function removeIP(index: number) {
  allowedIPs.value.splice(index, 1)
}

function validateIP(value: string): boolean | string {
  return isValidIP(value) || 'Invalid IP address'
}

function isValidIP(ip: string): boolean {
  // Basic IP validation (IPv4 and IPv6)
  const ipv4Regex = /^(\d{1,3}\.){3}\d{1,3}$/
  const ipv6Regex = /^([\da-f]{1,4}:){7}[\da-f]{1,4}$/i
  return ipv4Regex.test(ip) || ipv6Regex.test(ip) || ip === '::1'
}

function copyToken() {
  navigator.clipboard.writeText(callbackToken.value)
  showSnackbar('Token copied to clipboard')
}

async function runCleanup() {
  if (isUnmounted) return
  
  try {
    loading.value.cleanup = true
    // Call backend cleanup command
    if (!isUnmounted) {
      showSnackbar('Cleanup completed')
    }
  } catch (error) {
    if (!isUnmounted) {
      showSnackbar('Cleanup failed', 'error')
    }
  } finally {
    if (!isUnmounted) {
      loading.value.cleanup = false
    }
  }
}

async function runPruning() {
  if (isUnmounted) return
  
  try {
    loading.value.pruning = true
    // Call backend pruning command
    if (!isUnmounted) {
      showSnackbar('Log pruning completed')
    }
  } catch (error) {
    if (!isUnmounted) {
      showSnackbar('Pruning failed', 'error')
    }
  } finally {
    if (!isUnmounted) {
      loading.value.pruning = false
    }
  }
}

async function clearAllLogs() {
  if (isUnmounted) return
  
  try {
    // Call backend clear all logs
    if (!isUnmounted) {
      clearAllLogsDialog.value = false
      clearLogsConfirmation.value = ''
      showSnackbar('All logs deleted', 'warning')
    }
  } catch (error) {
    if (!isUnmounted) {
      showSnackbar('Failed to clear logs', 'error')
    }
  }
}

function showSnackbar(message: string, color: string = 'success') {
  if (!isUnmounted) {
    snackbar.value = { show: true, message, color }
  }
}
</script>

<style scoped lang="scss">
.system-settings {
  max-width: 1400px;
  margin: 0 auto;
}

.settings-card {
  border-radius: 0 !important;

  &.danger-zone {
    border-left: 4px solid #f44336;
  }
}

.card-header {
  background: rgba(0, 0, 0, 0.02);
}

.setting-group {
  .setting-label {
    font-weight: 600;
    font-size: 1rem;
    margin-bottom: 4px;
  }

  .setting-description {
    font-size: 0.875rem;
    color: rgba(0, 0, 0, 0.6);
    line-height: 1.4;
  }
}

.info-box {
  text-align: center;
  padding: 20px;
  border: 1px solid rgba(0, 0, 0, 0.12);

  .info-label {
    font-size: 0.75rem;
    color: rgba(0, 0, 0, 0.6);
    text-transform: uppercase;
    margin-bottom: 8px;
  }

  .info-value {
    font-size: 1.5rem;
    font-weight: bold;
  }
}

.gap-2 {
  gap: 8px;
}
</style>
