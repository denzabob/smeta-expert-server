<template>
  <v-card variant="outlined" :loading="loading">
    <v-card-title class="d-flex align-center">
      <v-icon class="mr-2">mdi-robot</v-icon>
      Управление LLM провайдерами
      <v-spacer />
      <v-btn
        color="primary"
        variant="tonal"
        size="small"
        :loading="testing"
        @click="testProviders"
        class="mr-2"
      >
        <v-icon start>mdi-connection</v-icon>
        Тест подключения
      </v-btn>
      <v-btn
        color="secondary"
        variant="tonal"
        size="small"
        :loading="loadingStates"
        @click="loadProviderStates"
      >
        <v-icon start>mdi-refresh</v-icon>
        Обновить
      </v-btn>
    </v-card-title>

    <v-card-text>
      <!-- Validation Errors -->
      <v-alert
        v-if="validation && !validation.valid"
        type="error"
        variant="tonal"
        class="mb-4"
        density="compact"
      >
        <div class="font-weight-medium mb-1">Ошибки конфигурации:</div>
        <ul class="pl-4">
          <li v-for="err in validation.errors" :key="err">{{ err }}</li>
        </ul>
      </v-alert>

      <!-- Validation Warnings -->
      <v-alert
        v-if="validation && validation.warnings?.length"
        type="warning"
        variant="tonal"
        class="mb-4"
        density="compact"
      >
        <div class="font-weight-medium mb-1">Предупреждения:</div>
        <ul class="pl-4">
          <li v-for="warn in validation.warnings" :key="warn">{{ warn }}</li>
        </ul>
      </v-alert>

      <!-- Execution Chain -->
      <v-card variant="tonal" color="blue-lighten-5" class="mb-4 pa-3" v-if="fullExecutionPlan.length">
        <div class="text-subtitle-2 font-weight-medium mb-2">
          <v-icon size="small" class="mr-1">mdi-transit-connection-variant</v-icon>
          Цепочка выполнения
          <v-chip size="x-small" class="ml-2" :color="settings.mode === 'auto' ? 'success' : 'warning'">
            {{ settings.mode === 'auto' ? 'Auto (failover)' : 'Manual' }}
          </v-chip>
        </div>
        <div class="d-flex align-center flex-wrap ga-1">
          <template v-for="(name, idx) in fullExecutionPlan" :key="name">
            <v-tooltip :text="getChainTooltip(name)" location="bottom">
              <template #activator="{ props }">
                <v-chip
                  v-bind="props"
                  :color="getProviderChipColor(name)"
                  :variant="isInHealthyPlan(name) ? 'flat' : 'outlined'"
                  size="small"
                >
                  <v-icon start size="small">{{ getStatusIcon(name) }}</v-icon>
                  {{ getDisplayName(name) }}
                  <span v-if="!isInHealthyPlan(name)" class="ml-1 text-caption">
                    ({{ getSkipReason(name) }})
                  </span>
                </v-chip>
              </template>
            </v-tooltip>
            <v-icon v-if="idx < fullExecutionPlan.length - 1" size="small" color="grey">mdi-arrow-right</v-icon>
          </template>
        </div>
      </v-card>

      <!-- Provider Status Cards -->
      <div class="text-subtitle-1 font-weight-medium mb-3">Статус провайдеров</div>

      <v-row>
        <v-col
          v-for="ps in providerStates"
          :key="ps.provider"
          cols="12"
          md="6"
          lg="3"
        >
          <v-card
            variant="outlined"
            :class="{'border-opacity-100': ps.used_in_chain}"
            :style="ps.used_in_chain ? 'border-color: rgb(var(--v-theme-primary))' : ''"
          >
            <v-card-title class="d-flex align-center text-body-1 pb-1">
              {{ ps.display_name }}
              <v-spacer />
              <v-chip
                :color="statusColor(ps.status)"
                size="x-small"
                variant="flat"
              >
                {{ statusLabel(ps.status) }}
              </v-chip>
            </v-card-title>
            <v-card-text class="pt-1">
              <div class="d-flex flex-column ga-1 text-body-2">
                <div class="d-flex justify-space-between">
                  <span class="text-medium-emphasis">Источник ключа</span>
                  <v-chip size="x-small" :color="ps.source === 'db' ? 'info' : ps.source === 'env' ? 'warning' : 'grey'">
                    {{ ps.source === 'db' ? 'DB' : ps.source === 'env' ? 'ENV' : '—' }}
                  </v-chip>
                </div>
                <div class="d-flex justify-space-between">
                  <span class="text-medium-emphasis">Модель</span>
                  <span class="font-weight-medium text-truncate ml-2" style="max-width: 160px" :title="ps.model">{{ ps.model }}</span>
                </div>
                <div class="d-flex justify-space-between">
                  <span class="text-medium-emphasis">Circuit</span>
                  <v-chip size="x-small" :color="circuitColor(ps.circuit)">{{ ps.circuit }}</v-chip>
                </div>
                <div class="d-flex justify-space-between" v-if="ps.latency_ms != null">
                  <span class="text-medium-emphasis">Latency (avg)</span>
                  <span>{{ ps.latency_ms }} ms</span>
                </div>
                <div class="d-flex justify-space-between" v-if="ps.error_rate != null">
                  <span class="text-medium-emphasis">Error rate</span>
                  <span :class="ps.error_rate > 0.1 ? 'text-error' : ''">{{ (ps.error_rate * 100).toFixed(1) }}%</span>
                </div>
                <div class="d-flex justify-space-between" v-if="ps.usage_percentage != null">
                  <span class="text-medium-emphasis">Использование</span>
                  <span>{{ (ps.usage_percentage * 100).toFixed(1) }}%</span>
                </div>
                <div class="d-flex justify-space-between" v-if="ps.unavailable_reason">
                  <span class="text-medium-emphasis">Причина</span>
                  <span class="text-error text-truncate ml-2" style="max-width: 160px" :title="ps.unavailable_reason_label">{{ ps.unavailable_reason_label }}</span>
                </div>
                <div class="d-flex justify-space-between" v-if="ps.last_error">
                  <span class="text-medium-emphasis">Последняя ошибка</span>
                  <span class="text-error text-truncate ml-2" style="max-width: 120px" :title="ps.last_error">{{ ps.last_error }}</span>
                </div>
              </div>

              <!-- Circuit breaker reset -->
              <v-btn
                v-if="ps.circuit !== 'closed'"
                color="warning"
                variant="tonal"
                size="x-small"
                class="mt-2"
                block
                @click="resetCircuit(ps.provider)"
              >
                <v-icon start size="small">mdi-restart</v-icon>
                Сбросить Circuit Breaker
              </v-btn>
            </v-card-text>
          </v-card>
        </v-col>
      </v-row>

      <v-divider class="my-5" />

      <!-- Routing Settings -->
      <div class="text-subtitle-1 font-weight-medium mb-3">Маршрутизация</div>

      <v-row class="mb-4">
        <v-col cols="12" md="4">
          <v-select
            v-model="settings.mode"
            :items="modeOptions"
            label="Режим маршрутизации"
            variant="outlined"
            density="comfortable"
            hint="Auto = автоматический failover при ошибках"
            persistent-hint
            @update:model-value="saveSettings"
          />
        </v-col>
        <v-col cols="12" md="4">
          <v-select
            v-model="settings.primary_provider"
            :items="providerSelectOptions"
            label="Primary провайдер"
            variant="outlined"
            density="comfortable"
            @update:model-value="saveSettings"
          />
        </v-col>
        <v-col cols="12" md="4">
          <v-select
            v-model="settings.fallback_providers"
            :items="fallbackProviderOptions"
            label="Fallback (по приоритету)"
            variant="outlined"
            density="comfortable"
            multiple
            chips
            closable-chips
            hint="Используются при недоступности primary"
            persistent-hint
            @update:model-value="saveSettings"
          />
        </v-col>
      </v-row>

      <v-divider class="my-5" />

      <!-- Provider Settings (Expandable) -->
      <div class="text-subtitle-1 font-weight-medium mb-3">Настройки провайдеров</div>

      <v-expansion-panels variant="accordion">
        <v-expansion-panel v-for="provider in availableProviders" :key="provider.value">
          <v-expansion-panel-title>
            <v-icon class="mr-2">{{ provider.icon }}</v-icon>
            {{ provider.title }}
            <v-chip
              v-if="settings.providers?.[provider.value]?.api_key_set"
              color="success"
              size="x-small"
              class="ml-2"
            >
              Настроен
            </v-chip>
            <v-chip
              v-else-if="settings.providers?.[provider.value]?.is_env_fallback"
              color="warning"
              size="x-small"
              class="ml-2"
            >
              ENV
            </v-chip>
            <v-chip v-else color="grey" size="x-small" class="ml-2">
              Не настроен
            </v-chip>
          </v-expansion-panel-title>
          <v-expansion-panel-text>
            <div class="text-body-2 text-medium-emphasis mb-3">
              {{ provider.description }}
            </div>
            <v-row v-if="providerForms[provider.value]">
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="providerForms[provider.value]!.api_key"
                  label="API Key"
                  variant="outlined"
                  density="comfortable"
                  type="password"
                  :placeholder="settings.providers?.[provider.value]?.api_key_masked || 'Введите ключ'"
                  hint="Оставьте пустым, чтобы сохранить текущий"
                  persistent-hint
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="providerForms[provider.value]!.model"
                  label="Модель"
                  variant="outlined"
                  density="comfortable"
                  :placeholder="settings.providers?.[provider.value]?.model"
                />
              </v-col>
              <v-col cols="12">
                <v-text-field
                  v-model="providerForms[provider.value]!.base_url"
                  label="Base URL"
                  variant="outlined"
                  density="comfortable"
                  :placeholder="settings.providers?.[provider.value]?.base_url"
                />
              </v-col>
              <v-col cols="12">
                <v-btn
                  color="primary"
                  variant="tonal"
                  :loading="savingProvider === provider.value"
                  @click="saveProviderSettings(provider.value)"
                >
                  Сохранить настройки {{ provider.title }}
                </v-btn>
              </v-col>
            </v-row>
          </v-expansion-panel-text>
        </v-expansion-panel>
      </v-expansion-panels>

      <!-- Test Results -->
      <v-alert
        v-if="testResults"
        :type="testResultsType"
        variant="tonal"
        class="mt-4"
        closable
        @click:close="testResults = null"
      >
        <div class="font-weight-medium mb-2">Результаты тестирования</div>
        <div v-for="(result, provider) in testResults" :key="provider" class="mb-1">
          <strong>{{ provider }}:</strong>
          <span v-if="result.available" class="text-success">
            OK <span class="text-medium-emphasis">({{ result.latency_ms }}ms)</span>
          </span>
          <span v-else class="text-error">{{ result.error || 'Недоступен' }}</span>
        </div>
      </v-alert>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/api/axios'

interface ProviderForm {
  api_key: string
  model: string
  base_url: string
}

interface ProviderStateItem {
  provider: string
  display_name: string
  status: string
  configured: boolean
  healthy: boolean
  available: boolean
  unavailable_reason: string | null
  unavailable_reason_label: string | null
  circuit: string
  fail_count: number
  last_error: string | null
  last_error_at: string | null
  last_success_at: string | null
  source: string
  model: string
  base_url: string
  priority: number
  used_in_chain: boolean
  latency_ms: number | null
  error_rate: number | null
  usage_percentage: number | null
}

const loading = ref(false)
const loadingStates = ref(false)
const testing = ref(false)
const savingProvider = ref<string | null>(null)
const testResults = ref<Record<string, { available: boolean; error?: string; latency_ms?: number }> | null>(null)

const providerStates = ref<ProviderStateItem[]>([])
const executionPlan = ref<string[]>([])
const fullExecutionPlan = ref<string[]>([])
const validation = ref<{ valid: boolean; errors: string[]; warnings: string[] } | null>(null)

const settings = ref({
  mode: 'auto',
  primary_provider: 'openrouter',
  fallback_providers: [] as string[],
  available_providers: [] as Array<{ value: string; title: string; icon: string; description: string }>,
  providers: {} as Record<string, {
    api_key_set: boolean
    api_key_masked?: string
    is_env_fallback?: boolean
    model?: string
    base_url?: string
  }>
})

const providerForms = reactive<Record<string, ProviderForm>>({})

const modeOptions = [
  { title: 'Automatic (рекомендуется)', value: 'auto' },
  { title: 'Manual (без failover)', value: 'manual' }
]

const providerSelectOptions = computed(() => {
  if (settings.value.available_providers?.length) {
    return settings.value.available_providers.map(p => ({
      title: p.title,
      value: p.value,
    }))
  }
  return []
})

const fallbackProviderOptions = computed(() => {
  return providerSelectOptions.value.filter(p => p.value !== settings.value.primary_provider)
})

const availableProviders = computed(() => {
  return settings.value.available_providers || []
})

const testResultsType = computed(() => {
  if (!testResults.value) return 'info'
  const vals = Object.values(testResults.value)
  const allOk = vals.every(r => r.available)
  const anyOk = vals.some(r => r.available)
  return allOk ? 'success' : anyOk ? 'warning' : 'error'
})

function getDisplayName(provider: string): string {
  const ps = providerStates.value.find(s => s.provider === provider)
  return ps?.display_name || provider
}

function getStatusIcon(provider: string): string {
  const ps = providerStates.value.find(s => s.provider === provider)
  if (!ps) return 'mdi-help-circle-outline'
  switch (ps.status) {
    case 'healthy': return 'mdi-check-circle'
    case 'down': return 'mdi-close-circle'
    case 'recovering': return 'mdi-timer-sand'
    case 'misconfigured': return 'mdi-alert-circle'
    default: return 'mdi-help-circle-outline'
  }
}

function getProviderChipColor(provider: string): string {
  const ps = providerStates.value.find(s => s.provider === provider)
  return ps ? statusColor(ps.status) : 'grey'
}

function isInHealthyPlan(provider: string): boolean {
  return executionPlan.value.includes(provider)
}

function getSkipReason(provider: string): string {
  const ps = providerStates.value.find(s => s.provider === provider)
  if (!ps) return 'unknown'
  if (ps.unavailable_reason === 'no_api_key') return 'нет ключа'
  if (ps.unavailable_reason === 'circuit_open') return 'circuit open'
  if (ps.unavailable_reason === 'not_configured') return 'не настроен'
  if (ps.unavailable_reason === 'invalid_config') return 'ошибка конфига'
  if (!ps.configured) return 'нет ключа'
  if (ps.circuit === 'open') return 'circuit open'
  return 'пропущен'
}

function getChainTooltip(provider: string): string {
  const ps = providerStates.value.find(s => s.provider === provider)
  if (!ps) return provider
  const parts = [`${ps.display_name} — ${statusLabel(ps.status)}`]
  if (ps.unavailable_reason_label) parts.push(`Причина: ${ps.unavailable_reason_label}`)
  if (ps.latency_ms != null) parts.push(`Latency: ${ps.latency_ms}ms`)
  if (ps.usage_percentage != null) parts.push(`Использование: ${(ps.usage_percentage * 100).toFixed(1)}%`)
  if (ps.last_error) parts.push(`Ошибка: ${ps.last_error}`)
  return parts.join('\n')
}

function statusColor(status: string): string {
  switch (status) {
    case 'healthy': return 'success'
    case 'down': return 'error'
    case 'recovering': return 'warning'
    case 'misconfigured': return 'grey'
    default: return 'grey'
  }
}

function statusLabel(status: string): string {
  switch (status) {
    case 'healthy': return 'Работает'
    case 'down': return 'Недоступен'
    case 'recovering': return 'Восстановление'
    case 'misconfigured': return 'Не настроен'
    default: return status
  }
}

function circuitColor(circuit: string): string {
  switch (circuit) {
    case 'closed': return 'success'
    case 'open': return 'error'
    case 'half_open': return 'warning'
    default: return 'grey'
  }
}

function initProviderForms() {
  const providers = settings.value.available_providers || []
  for (const p of providers) {
    if (!providerForms[p.value]) {
      providerForms[p.value] = { api_key: '', model: '', base_url: '' }
    }
  }
}

async function loadSettings() {
  loading.value = true
  try {
    const response = await api.get('/api/admin/llm-settings')
    settings.value = response.data
    initProviderForms()
  } catch (error) {
    console.error('Failed to load LLM settings:', error)
  } finally {
    loading.value = false
  }
}

async function loadProviderStates() {
  loadingStates.value = true
  try {
    const response = await api.get('/api/admin/llm-provider-states')
    providerStates.value = response.data.providers || []
    executionPlan.value = response.data.execution_plan || []
    fullExecutionPlan.value = response.data.full_execution_plan || []
    validation.value = response.data.validation || null
  } catch (error) {
    console.error('Failed to load provider states:', error)
  } finally {
    loadingStates.value = false
  }
}

async function saveSettings() {
  try {
    const response = await api.put('/api/admin/llm-settings', {
      mode: settings.value.mode,
      primary_provider: settings.value.primary_provider,
      fallback_providers: settings.value.fallback_providers
    })
    if (response.data.validation) {
      validation.value = response.data.validation
    }
    // Reload states after settings change
    await loadProviderStates()
  } catch (error) {
    console.error('Failed to save settings:', error)
  }
}

async function saveProviderSettings(provider: string) {
  savingProvider.value = provider
  try {
    const form = providerForms[provider]
    if (!form) return
    const providerPayload: Record<string, string> = {}
    if (form.api_key) providerPayload.api_key = form.api_key
    if (form.model) providerPayload.model = form.model
    if (form.base_url) providerPayload.base_url = form.base_url

    if (Object.keys(providerPayload).length === 0) {
      return
    }

    await api.put('/api/admin/llm-settings', {
      providers: {
        [provider]: providerPayload,
      },
    })

    await loadSettings()
    await loadProviderStates()
    providerForms[provider] = { api_key: '', model: '', base_url: '' }
  } catch (error) {
    console.error('Failed to save provider settings:', error)
  } finally {
    savingProvider.value = null
  }
}

async function testProviders() {
  testing.value = true
  testResults.value = null
  try {
    const response = await api.post('/api/admin/llm-test')
    testResults.value = response.data.results
    // Refresh states after testing
    await loadProviderStates()
  } catch (error) {
    console.error('Failed to test providers:', error)
  } finally {
    testing.value = false
  }
}

async function resetCircuit(provider: string) {
  try {
    await api.post('/api/admin/llm-reset-circuit', { provider })
    await loadProviderStates()
  } catch (error) {
    console.error('Failed to reset circuit breaker:', error)
  }
}

onMounted(async () => {
  await loadSettings()
  await loadProviderStates()
})
</script>
