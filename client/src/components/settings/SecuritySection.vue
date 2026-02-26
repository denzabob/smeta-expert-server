<template>
  <div class="section-content">
    <div class="section-title">Безопасность</div>
    <div class="section-hint">Управление PIN-кодом, доверенными устройствами и сеансами</div>

    <v-snackbar v-model="snack.show" :timeout="3000" :color="snack.color" location="bottom right">
      {{ snack.message }}
    </v-snackbar>

    <!-- PIN-код -->
    <v-card variant="outlined" class="content-card mb-4">
      <v-card-text>
        <div class="d-flex align-center justify-space-between mb-3">
          <div>
            <div class="text-subtitle-1 font-weight-medium">Быстрый вход по PIN</div>
            <div class="text-body-2 text-medium-emphasis">
              Входите по 4-значному PIN-коду на доверенных устройствах
            </div>
          </div>
          <v-switch
            :model-value="pinEnabled"
            color="primary"
            hide-details
            :loading="pinToggleLoading"
            @update:model-value="onPinToggle"
          />
        </div>

        <v-btn
          v-if="pinEnabled"
          variant="outlined"
          size="small"
          class="text-none"
          @click="showChangePinDialog = true"
        >
          <v-icon size="small" class="mr-1">mdi-pencil</v-icon>
          Изменить PIN
        </v-btn>
      </v-card-text>
    </v-card>

    <!-- Доверенные устройства -->
    <v-card variant="outlined" class="content-card mb-4">
      <v-card-text>
        <div class="d-flex align-center justify-space-between mb-3">
          <div class="text-subtitle-1 font-weight-medium">Доверенные устройства</div>
          <v-btn
            icon
            variant="text"
            size="small"
            @click="loadDevices"
            :loading="devicesLoading"
          >
            <v-icon size="small">mdi-refresh</v-icon>
          </v-btn>
        </div>

        <v-skeleton-loader v-if="devicesLoading && devices.length === 0" type="list-item-two-line, list-item-two-line" />

        <div v-else-if="devices.length === 0" class="text-body-2 text-medium-emphasis">
          Нет доверенных устройств
        </div>

        <v-list v-else density="compact" class="py-0">
          <v-list-item
            v-for="device in devices"
            :key="device.id"
            class="px-0"
          >
            <template #prepend>
              <v-icon :color="device.is_current ? 'primary' : undefined" class="mr-3">
                {{ getDeviceIcon(device.device_label) }}
              </v-icon>
            </template>

            <v-list-item-title class="text-body-2">
              {{ device.device_label }}
              <v-chip v-if="device.is_current" size="x-small" color="primary" class="ml-2">
                Текущее
              </v-chip>
            </v-list-item-title>
            <v-list-item-subtitle class="text-caption">
              IP: {{ device.ip_last || '—' }} · Последний вход: {{ formatDate(device.last_used_at) }}
            </v-list-item-subtitle>

            <template #append>
              <v-btn
                icon
                variant="text"
                size="small"
                color="error"
                :disabled="device.is_current"
                :loading="revokingDeviceId === device.id"
                @click="revokeDevice(device)"
                title="Отозвать устройство"
              >
                <v-icon size="small">mdi-close-circle</v-icon>
              </v-btn>
            </template>
          </v-list-item>
        </v-list>
      </v-card-text>
    </v-card>

    <!-- Управление сеансами -->
    <v-card variant="outlined" class="content-card">
      <v-card-text>
        <div class="d-flex align-center justify-space-between">
          <div>
            <div class="text-subtitle-1 font-weight-medium">Активные сеансы</div>
            <div class="text-body-2 text-medium-emphasis">
              Завершите все сеансы кроме текущего
            </div>
          </div>
          <v-btn
            variant="outlined"
            color="error"
            size="small"
            class="text-none"
            :loading="terminateLoading"
            @click="terminateSessions"
          >
            <v-icon size="small" class="mr-1">mdi-logout</v-icon>
            Завершить все сеансы
          </v-btn>
        </div>
      </v-card-text>
    </v-card>

    <!-- Диалог: Отключение PIN (ввод пароля) -->
    <v-dialog v-model="showDisablePinDialog" max-width="400">
      <v-card>
        <v-card-title>Отключить PIN</v-card-title>
        <v-card-text>
          <div class="text-body-2 text-medium-emphasis mb-3">
            Все доверенные устройства будут отозваны. Введите пароль для подтверждения.
          </div>
          <v-text-field
            v-model="disablePassword"
            label="Пароль"
            :type="showDisablePassword ? 'text' : 'password'"
            :append-inner-icon="showDisablePassword ? 'mdi-eye-off' : 'mdi-eye'"
            @click:append-inner="showDisablePassword = !showDisablePassword"
            :error-messages="disableError"
            @keydown.enter="confirmDisablePin"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showDisablePinDialog = false">Отмена</v-btn>
          <v-btn color="error" variant="flat" :loading="disableLoading" @click="confirmDisablePin">
            Отключить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Диалог: Изменение PIN -->
    <PinSetupDialog
      v-model="showChangePinDialog"
      @done="onPinChanged"
      @skip="showChangePinDialog = false"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { pinApi, type TrustedDevice } from '@/api/pin'
import PinSetupDialog from '@/components/auth/PinSetupDialog.vue'

const pinEnabled = ref(false)
const pinToggleLoading = ref(false)
const devices = ref<TrustedDevice[]>([])
const devicesLoading = ref(false)
const revokingDeviceId = ref<number | null>(null)
const terminateLoading = ref(false)

// Disable PIN dialog
const showDisablePinDialog = ref(false)
const disablePassword = ref('')
const showDisablePassword = ref(false)
const disableLoading = ref(false)
const disableError = ref('')

// Change PIN dialog
const showChangePinDialog = ref(false)

const snack = ref({ show: false, message: '', color: 'info' })
const notify = (message: string, color = 'info') => {
  snack.value = { show: true, message, color }
}

const loadStatus = async () => {
  try {
    const status = await pinApi.getStatus()
    pinEnabled.value = status.pin_enabled
  } catch {
    // ignore
  }
}

const loadDevices = async () => {
  devicesLoading.value = true
  try {
    devices.value = await pinApi.getTrustedDevices()
  } catch {
    notify('Не удалось загрузить устройства', 'error')
  } finally {
    devicesLoading.value = false
  }
}

const onPinToggle = async (val: boolean | null) => {
  if (val) {
    // Включить — открываем диалог установки PIN
    showChangePinDialog.value = true
  } else {
    // Выключить — нужен пароль
    disablePassword.value = ''
    disableError.value = ''
    showDisablePinDialog.value = true
  }
}

const confirmDisablePin = async () => {
  if (!disablePassword.value) {
    disableError.value = 'Введите пароль'
    return
  }

  disableLoading.value = true
  disableError.value = ''

  try {
    await pinApi.disablePin(disablePassword.value)
    pinEnabled.value = false
    showDisablePinDialog.value = false
    await loadDevices()
    notify('PIN-код отключён', 'success')
  } catch (e: any) {
    disableError.value = e.response?.data?.message || 'Ошибка'
  } finally {
    disableLoading.value = false
  }
}

const onPinChanged = async () => {
  pinEnabled.value = true
  await loadDevices()
  notify('PIN-код установлен', 'success')
}

const revokeDevice = async (device: TrustedDevice) => {
  if (!confirm(`Отозвать устройство «${device.device_label}»?`)) return

  revokingDeviceId.value = device.id
  try {
    await pinApi.revokeDevice(device.id)
    await loadDevices()
    notify('Устройство отозвано', 'success')
  } catch {
    notify('Ошибка отзыва устройства', 'error')
  } finally {
    revokingDeviceId.value = null
  }
}

const terminateSessions = async () => {
  if (!confirm('Завершить все сеансы кроме текущего?')) return

  terminateLoading.value = true
  try {
    await pinApi.terminateSessions()
    notify('Все другие сеансы завершены', 'success')
  } catch {
    notify('Ошибка завершения сеансов', 'error')
  } finally {
    terminateLoading.value = false
  }
}

const getDeviceIcon = (label: string): string => {
  const lower = label.toLowerCase()
  if (lower.includes('chrome')) return 'mdi-google-chrome'
  if (lower.includes('firefox')) return 'mdi-firefox'
  if (lower.includes('safari')) return 'mdi-apple-safari'
  if (lower.includes('edge')) return 'mdi-microsoft-edge'
  return 'mdi-monitor'
}

const formatDate = (dateStr: string | null): string => {
  if (!dateStr) return '—'
  try {
    return new Date(dateStr).toLocaleString('ru-RU', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return dateStr
  }
}

onMounted(async () => {
  await Promise.all([loadStatus(), loadDevices()])
})
</script>

<style scoped>
.section-title {
  font-size: 1.1rem;
  font-weight: 600;
  margin-bottom: 4px;
}

.section-hint {
  font-size: 0.875rem;
  opacity: 0.75;
  margin-bottom: 12px;
}

.content-card {
  border-radius: 12px;
}

.text-none {
  text-transform: none;
}
</style>

