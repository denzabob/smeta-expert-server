<template>
  <v-card variant="outlined" class="mb-4">
    <v-card-text>
      <div class="d-flex align-center mb-1">
        <div>
          <div class="text-subtitle-2 font-weight-medium">Доверенные устройства</div>
          <div class="text-caption text-medium-emphasis">
            Устройства, на которых включён быстрый PIN
          </div>
        </div>
        <v-spacer />
        <v-btn
          v-if="devices.length > 0"
          size="small"
          variant="text"
          color="error"
          @click="confirmRevokeAll = true"
        >
          Отозвать все
        </v-btn>
      </div>

      <!-- Loading skeleton -->
      <div v-if="loading" class="mt-4">
        <v-skeleton-loader type="list-item-two-line" />
        <v-skeleton-loader type="list-item-two-line" />
      </div>

      <!-- Empty state -->
      <div v-else-if="devices.length === 0" class="text-center py-4">
        <v-icon size="40" color="medium-emphasis">mdi-devices</v-icon>
        <div class="text-caption text-medium-emphasis mt-2">Нет доверенных устройств</div>
      </div>

      <!-- Devices list -->
      <div v-else class="mt-2">
        <div
          v-for="(device, idx) in devices"
          :key="device.id"
          class="device-row"
        >
          <div class="d-flex align-center gap-3 py-3">
            <v-icon size="24" :color="device.is_current ? 'primary' : 'medium-emphasis'">
              mdi-devices
            </v-icon>

            <div class="flex-1">
              <div class="d-flex align-center gap-2 flex-wrap">
                <span class="text-body-2">{{ device.device_label || 'Неизвестное устройство' }}</span>
                <v-chip v-if="device.is_current" size="x-small" color="primary" variant="tonal">
                  Это устройство
                </v-chip>
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ formatDate(device.last_used_at ?? device.created_at) }}
              </div>
            </div>

            <v-btn
              size="small"
              variant="text"
              color="error"
              :loading="revokingId === device.id"
              @click="revokeDevice(device.id)"
            >
              Отозвать
            </v-btn>
          </div>
          <v-divider v-if="idx < devices.length - 1" />
        </div>
      </div>
    </v-card-text>

    <!-- Step-up dialog for revoke all -->
    <StepUpDialog
      v-model="showStepUp"
      scope="revoke_all_devices"
      title="Подтверждение для отзыва всех устройств"
      @completed="onStepUpCompleted"
      @cancelled="showStepUp = false"
    />

    <!-- Confirm revoke all dialog -->
    <v-dialog v-model="confirmRevokeAll" max-width="380">
      <v-card>
        <v-card-title class="pt-5 px-6 text-subtitle-1">Отозвать все устройства?</v-card-title>
        <v-card-text class="px-6">
          <p class="text-body-2 text-medium-emphasis">
            Быстрый PIN будет отключён на всех устройствах. На каждом из них потребуется снова
            войти обычным способом — через телефон или пароль.
          </p>
        </v-card-text>
        <v-card-actions class="px-6 pb-5">
          <v-btn variant="text" @click="confirmRevokeAll = false">Отмена</v-btn>
          <v-spacer />
          <v-btn color="error" variant="flat" @click="startRevokeAll">
            Отозвать все
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-card>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useSecurityStore } from '@/stores/security'
import type { SecurityDevice } from '@/api/security'
import StepUpDialog from './StepUpDialog.vue'

const props = defineProps<{
  devices: SecurityDevice[]
  loading: boolean
}>()

const emit = defineEmits<{
  (e: 'notify', message: string, color: 'success' | 'error' | 'warning' | 'info'): void
}>()

const store = useSecurityStore()

const revokingId = ref<number | null>(null)
const confirmRevokeAll = ref(false)
const showStepUp = ref(false)

async function revokeDevice(id: number) {
  revokingId.value = id
  try {
    await store.revokeDevice(id)
    emit('notify', 'Устройство отозвано.', 'success')
  } catch {
    emit('notify', 'Не удалось отозвать устройство. Попробуйте снова.', 'error')
  } finally {
    revokingId.value = null
  }
}

function startRevokeAll() {
  confirmRevokeAll.value = false
  showStepUp.value = true
}

async function onStepUpCompleted(token: string) {
  showStepUp.value = false
  try {
    await store.revokeAllDevices(token)
    emit('notify', 'Все доверенные устройства отозваны.', 'success')
  } catch {
    emit('notify', 'Не удалось отозвать устройства. Попробуйте снова.', 'error')
  }
}

function formatDate(iso: string | null): string {
  if (!iso) return 'Не известно'
  const d = new Date(iso)
  return d.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}


</script>

<style scoped>
.flex-1 {
  flex: 1;
  min-width: 0;
}
</style>
