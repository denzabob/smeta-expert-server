<template>
  <v-card variant="outlined" class="mb-4">
    <v-card-text>
      <div class="d-flex align-center mb-1">
        <div>
          <div class="text-subtitle-2 font-weight-medium">Активные сеансы</div>
          <div class="text-caption text-medium-emphasis">Устройства, на которых вы вошли в аккаунт</div>
        </div>
        <v-spacer />
        <v-btn
          v-if="sessions.length > 1"
          size="small"
          variant="text"
          color="error"
          @click="confirmRevokeAll = true"
        >
          Завершить все другие
        </v-btn>
      </div>

      <!-- Loading skeleton -->
      <div v-if="loading" class="mt-4">
        <v-skeleton-loader type="list-item-three-line" />
        <v-skeleton-loader type="list-item-three-line" />
      </div>

      <!-- Empty state -->
      <div v-else-if="sessions.length === 0" class="text-center py-4">
        <v-icon size="40" color="medium-emphasis">mdi-monitor-off</v-icon>
        <div class="text-caption text-medium-emphasis mt-2">Нет активных сеансов</div>
      </div>

      <!-- Sessions list -->
      <div v-else class="mt-2">
        <div
          v-for="(session, idx) in sessions"
          :key="session.id"
          class="session-row"
        >
          <div class="d-flex align-center gap-3 py-3">
            <v-icon size="24" :color="session.current ? 'primary' : 'medium-emphasis'">
              {{ deviceIcon(session.device) }}
            </v-icon>

            <div class="flex-1">
              <div class="d-flex align-center gap-2 flex-wrap">
                <span class="text-body-2">{{ session.device || 'Неизвестное устройство' }}</span>
                <v-chip v-if="session.current" size="x-small" color="primary" variant="tonal">
                  Текущий
                </v-chip>
              </div>
              <div class="text-caption text-medium-emphasis">
                IP: {{ session.ip || '—' }}
                <span class="mx-1">·</span>
                {{ formatDate(session.last_active_at) }}
              </div>
            </div>

            <v-btn
              v-if="!session.current"
              size="small"
              variant="text"
              color="error"
              :loading="revokingId === session.id"
              @click="revokeSession(session.id)"
            >
              Завершить
            </v-btn>
          </div>
          <v-divider v-if="idx < sessions.length - 1" />
        </div>
      </div>
    </v-card-text>

    <!-- Confirm revoke all dialog -->
    <v-dialog v-model="confirmRevokeAll" max-width="380">
      <v-card>
        <v-card-title class="pt-5 px-6 text-subtitle-1">Завершить другие сеансы?</v-card-title>
        <v-card-text class="px-6">
          <p class="text-body-2 text-medium-emphasis">
            Все сеансы, кроме текущего, будут завершены. Вам придётся снова войти на других устройствах.
          </p>
        </v-card-text>
        <v-card-actions class="px-6 pb-5">
          <v-btn variant="text" @click="confirmRevokeAll = false">Отмена</v-btn>
          <v-spacer />
          <v-btn color="error" variant="flat" :loading="revoking" @click="revokeOthers">
            Завершить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-card>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useSecurityStore } from '@/stores/security'
import type { SecuritySession } from '@/api/security'

const props = defineProps<{
  sessions: SecuritySession[]
  loading: boolean
}>()

const store = useSecurityStore()

const revokingId = ref<string | null>(null)
const confirmRevokeAll = ref(false)
const revoking = ref(false)

async function revokeSession(id: string) {
  revokingId.value = id
  await store.revokeSession(id)
  revokingId.value = null
}

async function revokeOthers() {
  revoking.value = true
  await store.revokeOtherSessions()
  confirmRevokeAll.value = false
  revoking.value = false
}

function formatDate(iso: string | null): string {
  if (!iso) return 'Не известно'
  const d = new Date(iso)
  return d.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function deviceIcon(device: string | null): string {
  if (!device) return 'mdi-help-circle-outline'
  const d = device.toLowerCase()
  if (d.includes('mobile') || d.includes('android') || d.includes('iphone')) return 'mdi-cellphone'
  if (d.includes('tablet') || d.includes('ipad')) return 'mdi-tablet'
  return 'mdi-monitor'
}
</script>

<style scoped>
.flex-1 {
  flex: 1;
  min-width: 0;
}
</style>
