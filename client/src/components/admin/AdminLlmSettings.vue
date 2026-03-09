<template>
  <v-card variant="outlined" :loading="loading">
    <v-card-title class="d-flex align-center">
      <v-icon class="mr-2">mdi-robot</v-icon>
      Настройки LLM провайдеров
      <v-spacer />
      <v-btn
        color="primary"
        variant="tonal"
        size="small"
        :loading="testing"
        @click="testProviders"
      >
        <v-icon start>mdi-connection</v-icon>
        Тест подключения
      </v-btn>
    </v-card-title>

    <v-card-text>
      <!-- Mode Toggle -->
      <v-row class="mb-4">
        <v-col cols="12" md="6">
          <v-select
            v-model="settings.mode"
            :items="modeOptions"
            label="Режим работы"
            variant="outlined"
            density="comfortable"
            hint="Auto = автоматический failover при ошибках"
            persistent-hint
            @update:model-value="saveSettings"
          />
        </v-col>
        <v-col cols="12" md="6">
          <v-select
            v-model="settings.primary_provider"
            :items="providerOptions"
            label="Primary провайдер"
            variant="outlined"
            density="comfortable"
            @update:model-value="saveSettings"
          />
        </v-col>
      </v-row>

      <!-- Fallback Providers -->
      <v-row class="mb-4">
        <v-col cols="12" md="6">
          <v-select
            v-model="settings.fallback_providers"
            :items="fallbackProviderOptions"
            label="Fallback провайдеры (по приоритету)"
            variant="outlined"
            density="comfortable"
            multiple
            chips
            closable-chips
            hint="Будут использованы при недоступности primary"
            persistent-hint
            @update:model-value="saveSettings"
          />
        </v-col>
      </v-row>

      <v-divider class="my-4" />

      <!-- Provider Settings -->
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
        <div v-for="(result, provider) in testResults" :key="provider" class="mb-1">
          <strong>{{ provider }}:</strong>
          <span :class="result.success ? 'text-success' : 'text-error'">
            {{ result.success ? 'OK' : result.error }}
          </span>
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

const loading = ref(false)
const testing = ref(false)
const savingProvider = ref<string | null>(null)
const testResults = ref<Record<string, { success: boolean; error?: string }> | null>(null)

const settings = ref({
  mode: 'auto',
  primary_provider: 'openai',
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

const providerForms = reactive<Record<string, ProviderForm>>({
  openai: { api_key: '', model: '', base_url: '' },
  anthropic: { api_key: '', model: '', base_url: '' },
  gemini: { api_key: '', model: '', base_url: '' },
  openrouter: { api_key: '', model: '', base_url: '' }
})

const modeOptions = [
  { title: 'Auto (failover)', value: 'auto' },
  { title: 'Manual', value: 'manual' }
]

const providerOptions = [
  { title: 'OpenAI', value: 'openai' },
  { title: 'Anthropic', value: 'anthropic' },
  { title: 'Gemini', value: 'gemini' },
  { title: 'OpenRouter', value: 'openrouter' }
]

const fallbackProviderOptions = computed(() => {
  return providerOptions.filter(p => p.value !== settings.value.primary_provider)
})

const availableProviders = computed(() => {
  return settings.value.available_providers?.length
    ? settings.value.available_providers
    : providerOptions.map(p => ({
        value: p.value,
        title: p.title,
        icon: 'mdi-robot',
        description: ''
      }))
})

const testResultsType = computed(() => {
  if (!testResults.value) return 'info'
  const allSuccess = Object.values(testResults.value).every(r => r.success)
  const anySuccess = Object.values(testResults.value).some(r => r.success)
  return allSuccess ? 'success' : anySuccess ? 'warning' : 'error'
})

async function loadSettings() {
  loading.value = true
  try {
    const response = await api.get('/api/admin/llm-settings')
    settings.value = response.data
  } catch (error) {
    console.error('Failed to load LLM settings:', error)
  } finally {
    loading.value = false
  }
}

async function saveSettings() {
  try {
    await api.put('/api/admin/llm-settings', {
      mode: settings.value.mode,
      primary_provider: settings.value.primary_provider,
      fallback_providers: settings.value.fallback_providers
    })
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
    // Clear form after save
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
  } catch (error) {
    console.error('Failed to test providers:', error)
  } finally {
    testing.value = false
  }
}

onMounted(() => {
  loadSettings()
})
</script>
