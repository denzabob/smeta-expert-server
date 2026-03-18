<template>
  <div class="auth-phone">
    <div v-if="step === 'phone'">
      <v-text-field
        v-model="phoneModel"
        label="Номер телефона"
        placeholder="+7 (999) 123-45-67"
        variant="outlined"
        density="comfortable"
        prepend-inner-icon="mdi-phone"
        :error-messages="phoneError"
        :disabled="loading"
        @keyup.enter="requestCallChallenge"
      />

      <div class="text-caption text-medium-emphasis mb-2">
        Если вы еще не зарегистрированы, аккаунт будет создан после подтверждения номера.
      </div>

      <v-btn
        block
        color="primary"
        size="large"
        :loading="loading"
        :disabled="!isPhoneValid"
        class="mt-2"
        @click="requestCallChallenge"
      >
        Продолжить
      </v-btn>

      <v-alert v-if="generalError" type="error" variant="tonal" class="mt-3" density="compact">
        {{ generalError }}
      </v-alert>
    </div>

    <div v-else>
      <div class="text-body-2 text-medium-emphasis mb-2">
        Позвоните с номера <strong>{{ phoneMasked }}</strong> на номер:
      </div>

      <div class="call-number mb-3">
        {{ callPhonePretty || callPhoneRaw || 'номер недоступен' }}
      </div>

      <v-alert type="info" variant="tonal" density="compact" class="mb-3">
        Звонок бесплатный. После звонка подтверждение произойдет автоматически, статус проверяется каждые несколько секунд.
      </v-alert>

      <div class="d-flex align-center justify-space-between mb-2">
        <v-chip
          size="small"
          :color="statusColor"
          variant="flat"
        >
          {{ statusLabel }}
        </v-chip>
        <span class="text-caption text-medium-emphasis">Осталось: {{ ttlLabel }}</span>
      </div>

      <v-progress-linear
        :model-value="ttlProgress"
        color="primary"
        height="6"
        rounded
        class="mb-3"
      />

      <v-alert v-if="statusMessage" :type="statusAlertType" variant="tonal" class="mb-3" density="compact">
        {{ statusMessage }}
      </v-alert>

      <v-btn
        v-if="callPhoneRaw"
        block
        variant="outlined"
        class="mt-2"
        :href="`tel:${callPhoneRaw}`"
      >
        Позвонить {{ callPhonePretty || callPhoneRaw }}
      </v-btn>

      <div class="d-flex align-center justify-space-between mt-3">
        <v-btn
          variant="text"
          size="small"
          :disabled="loading"
          @click="requestNewNumber"
        >
          Запросить новый номер
        </v-btn>

        <v-btn variant="text" size="small" @click="backToPhone">
          Изменить номер
        </v-btn>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue'
import {
  phoneAuthApi,
  type VerifyCodeResponse,
  type CallStatusResponse,
} from '@/api/phoneAuth'
import {
  formatRuPhoneMask,
  isCompleteRuPhone,
  toE164RuPhone,
  toStatusLabel,
  type CallUiStatus,
} from '@/components/auth/phoneCallFlow'

const emit = defineEmits<{
  (e: 'verified', data: VerifyCodeResponse): void
}>()

const step = ref<'phone' | 'waiting'>('phone')
const phoneInput = ref('')
const phoneError = ref('')
const generalError = ref('')
const loading = ref(false)
const loadingStatus = ref(false)

const verificationId = ref('')
const phoneMasked = ref('')
const callPhoneRaw = ref('')
const callPhonePretty = ref('')

const callStatus = ref<CallUiStatus>('idle')
const statusMessage = ref('')
const ttlSeconds = ref(0)
const ttlInitial = ref(0)

let pollTimer: ReturnType<typeof setInterval> | null = null
let ttlTimer: ReturnType<typeof setInterval> | null = null

const phoneModel = computed({
  get: () => phoneInput.value,
  set: (value: string) => {
    phoneInput.value = formatRuPhoneMask(value)
  },
})

const isPhoneValid = computed(() => isCompleteRuPhone(phoneInput.value))

const statusLabel = computed(() => toStatusLabel(callStatus.value) || 'Ожидаем звонок')

const statusColor = computed(() => {
  if (callStatus.value === 'verified') return 'success'
  if (callStatus.value === 'expired') return 'warning'
  if (callStatus.value === 'failed') return 'error'
  return 'info'
})

const statusAlertType = computed<'info' | 'success' | 'warning' | 'error'>(() => {
  if (callStatus.value === 'verified') return 'success'
  if (callStatus.value === 'expired') return 'warning'
  if (callStatus.value === 'failed') return 'error'
  return 'info'
})

const ttlLabel = computed(() => {
  if (ttlSeconds.value <= 0) return '00:00'
  const minutes = String(Math.floor(ttlSeconds.value / 60)).padStart(2, '0')
  const seconds = String(ttlSeconds.value % 60).padStart(2, '0')
  return `${minutes}:${seconds}`
})

const ttlProgress = computed(() => {
  if (ttlInitial.value <= 0) return 0
  return Math.max(0, Math.min(100, (ttlSeconds.value / ttlInitial.value) * 100))
})

function resetTimers() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
  if (ttlTimer) {
    clearInterval(ttlTimer)
    ttlTimer = null
  }
}

function startTimers() {
  resetTimers()

  ttlTimer = setInterval(() => {
    if (ttlSeconds.value > 0) {
      ttlSeconds.value -= 1
    }

    if (ttlSeconds.value <= 0 && callStatus.value === 'pending') {
      callStatus.value = 'expired'
      statusMessage.value = 'Время ожидания звонка истекло. Запросите новый номер.'
      resetTimers()
    }
  }, 1000)

  pollTimer = setInterval(() => {
    if (callStatus.value === 'pending') {
      void pollStatus()
    }
  }, 3000)
}

function applyCallStatusPayload(payload: CallStatusResponse) {
  if (payload.call_phone) callPhoneRaw.value = payload.call_phone
  if (payload.call_phone_pretty) callPhonePretty.value = payload.call_phone_pretty

  const nextStatus = payload.status
  callStatus.value = nextStatus
  statusMessage.value = payload.message || ''

  if (payload.ttl_seconds >= 0) {
    ttlSeconds.value = payload.ttl_seconds
    if (ttlInitial.value < payload.ttl_seconds) {
      ttlInitial.value = payload.ttl_seconds
    }
  }

  if (nextStatus === 'verified' && payload.auth) {
    resetTimers()
    emit('verified', payload.auth)
    return
  }

  if (nextStatus === 'expired' || nextStatus === 'failed') {
    resetTimers()
  }
}

async function requestCallChallenge() {
  if (!isPhoneValid.value || loading.value) return

  phoneError.value = ''
  generalError.value = ''
  loading.value = true

  try {
    const result = await phoneAuthApi.requestCallChallenge({
      phone: toE164RuPhone(phoneInput.value),
    })

    verificationId.value = result.verification_id
    phoneMasked.value = result.phone_masked
    callPhoneRaw.value = result.call_phone || ''
    callPhonePretty.value = result.call_phone_pretty || ''

    ttlSeconds.value = Math.max(0, result.ttl_seconds)
    ttlInitial.value = Math.max(1, result.ttl_seconds)

    callStatus.value = 'pending'
    statusMessage.value = 'Ожидаем звонок.'
    step.value = 'waiting'

    startTimers()
    await pollStatus()
  } catch (err: any) {
    const status = err.response?.status
    const data = err.response?.data

    if (status === 422) {
      phoneError.value = data?.errors?.phone?.[0] || data?.message || 'Некорректный номер телефона'
    } else if (status === 429) {
      generalError.value = 'Слишком много запросов. Подождите немного.'
    } else {
      generalError.value = data?.message || 'Не удалось запросить номер для звонка.'
    }
  } finally {
    loading.value = false
  }
}

async function pollStatus() {
  if (!verificationId.value || loadingStatus.value) return

  loadingStatus.value = true
  try {
    const result = await phoneAuthApi.getCallStatus({
      verification_id: verificationId.value,
    })
    applyCallStatusPayload(result)
  } catch (err: any) {
    const status = err.response?.status
    const data = err.response?.data

    if (status === 410) {
      callStatus.value = 'expired'
      statusMessage.value = data?.message || 'Время ожидания звонка истекло. Запросите новый номер.'
      resetTimers()
      return
    }

    callStatus.value = 'failed'
    statusMessage.value = data?.message || 'Не удалось проверить статус звонка. Попробуйте снова.'
  } finally {
    loadingStatus.value = false
  }
}

async function requestNewNumber() {
  if (loading.value) return
  await requestCallChallenge()
}

function backToPhone() {
  resetTimers()
  step.value = 'phone'
  callStatus.value = 'idle'
  statusMessage.value = ''
  verificationId.value = ''
  callPhoneRaw.value = ''
  callPhonePretty.value = ''
  ttlSeconds.value = 0
  ttlInitial.value = 0
}

onUnmounted(() => {
  resetTimers()
})
</script>

<style scoped>
.call-number {
  font-size: 1.25rem;
  font-weight: 700;
  letter-spacing: 0.02em;
}
</style>
