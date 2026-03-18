<template>
  <div class="auth-phone">
    <!-- Шаг 1: Ввод номера телефона -->
    <div v-if="step === 'phone'">
      <v-text-field
        v-model="phone"
        label="Номер телефона"
        placeholder="+7 (999) 123-45-67"
        variant="outlined"
        density="comfortable"
        prepend-inner-icon="mdi-phone"
        :error-messages="phoneError"
        :disabled="loading"
        @keyup.enter="requestCode"
      />

      <v-btn
        block
        color="primary"
        size="large"
        :loading="loading"
        :disabled="!isPhoneValid"
        class="mt-2"
        @click="requestCode"
      >
        Получить код
      </v-btn>

      <v-alert v-if="generalError" type="error" variant="tonal" class="mt-3" density="compact">
        {{ generalError }}
      </v-alert>
    </div>

    <!-- Шаг 2: Ввод кода подтверждения -->
    <div v-else-if="step === 'code'">
      <div class="text-body-2 text-medium-emphasis mb-3">
        Код отправлен на <strong>{{ phoneMasked }}</strong>
        <span v-if="currentChannel" class="ml-1">({{ channelLabel }})</span>
      </div>

      <v-otp-input
        ref="otpInputRef"
        v-model="code"
        :length="6"
        :disabled="loading"
        type="number"
        @finish="verifyCode"
      />

      <v-btn
        block
        color="primary"
        size="large"
        :loading="loading"
        :disabled="code.length < 6"
        class="mt-4"
        @click="verifyCode"
      >
        Подтвердить
      </v-btn>

      <v-alert v-if="codeError" type="error" variant="tonal" class="mt-3" density="compact">
        {{ codeError }}
      </v-alert>

      <div class="d-flex align-center justify-space-between mt-3">
        <v-btn
          variant="text"
          size="small"
          :disabled="!canResend"
          :loading="resending"
          @click="resendCode"
        >
          {{ canResend ? 'Отправить повторно' : `Повторно через ${resendCountdown}с` }}
        </v-btn>

        <v-btn variant="text" size="small" @click="backToPhone">
          Изменить номер
        </v-btn>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onUnmounted } from 'vue'
import { phoneAuthApi, type VerifyCodeResponse } from '@/api/phoneAuth'

const emit = defineEmits<{
  (e: 'verified', data: VerifyCodeResponse): void
}>()

const step = ref<'phone' | 'code'>('phone')
const phone = ref('')
const code = ref('')
const loading = ref(false)
const resending = ref(false)
const phoneError = ref('')
const codeError = ref('')
const generalError = ref('')

const challengeId = ref('')
const phoneMasked = ref('')
const currentChannel = ref('')
const resendAvailableAt = ref<Date | null>(null)
const resendCountdown = ref(0)
let resendTimer: ReturnType<typeof setInterval> | null = null

const otpInputRef = ref<any>(null)

const channelLabel = computed(() => {
  if (currentChannel.value === 'telegram') return 'Telegram'
  if (currentChannel.value === 'sms') return 'SMS'
  return currentChannel.value
})

const isPhoneValid = computed(() => {
  const digits = phone.value.replace(/\D/g, '')
  return digits.length >= 10 && digits.length <= 12
})

const canResend = computed(() => resendCountdown.value <= 0)

function startResendTimer(availableAt: string | Date) {
  if (resendTimer) clearInterval(resendTimer)

  const target = new Date(availableAt)
  resendAvailableAt.value = target

  const updateCountdown = () => {
    const diff = Math.max(0, Math.ceil((target.getTime() - Date.now()) / 1000))
    resendCountdown.value = diff
    if (diff <= 0 && resendTimer) {
      clearInterval(resendTimer)
      resendTimer = null
    }
  }

  updateCountdown()
  resendTimer = setInterval(updateCountdown, 1000)
}

onUnmounted(() => {
  if (resendTimer) clearInterval(resendTimer)
})

async function requestCode() {
  if (!isPhoneValid.value || loading.value) return

  phoneError.value = ''
  generalError.value = ''
  loading.value = true

  try {
    const result = await phoneAuthApi.requestCode({ phone: phone.value })
    challengeId.value = result.challenge_id
    phoneMasked.value = result.phone_masked
    currentChannel.value = result.channel
    startResendTimer(result.resend_available_at)
    step.value = 'code'
    code.value = ''
  } catch (err: any) {
    const status = err.response?.status
    const data = err.response?.data

    if (status === 422) {
      phoneError.value = data?.errors?.phone?.[0] || 'Некорректный номер телефона'
    } else if (status === 429) {
      generalError.value = 'Слишком много запросов. Подождите немного.'
    } else {
      generalError.value = data?.message || 'Ошибка при отправке кода'
    }
  } finally {
    loading.value = false
  }
}

async function resendCode() {
  if (!canResend.value || resending.value) return

  codeError.value = ''
  resending.value = true

  try {
    const result = await phoneAuthApi.resendCode({ challenge_id: challengeId.value })
    currentChannel.value = result.channel
    startResendTimer(result.resend_available_at)
    code.value = ''
  } catch (err: any) {
    const data = err.response?.data
    codeError.value = data?.message || 'Не удалось отправить код повторно'
  } finally {
    resending.value = false
  }
}

async function verifyCode() {
  if (code.value.length < 6 || loading.value) return

  codeError.value = ''
  loading.value = true

  try {
    const result = await phoneAuthApi.verifyCode({
      challenge_id: challengeId.value,
      code: code.value,
    })
    emit('verified', result)
  } catch (err: any) {
    const status = err.response?.status
    const data = err.response?.data

    if (status === 422) {
      codeError.value = data?.errors?.code?.[0] || data?.message || 'Неверный код'
    } else if (status === 410) {
      codeError.value = 'Код истёк или исчерпаны попытки. Запросите новый.'
      step.value = 'phone'
    } else if (status === 429) {
      codeError.value = 'Слишком много попыток. Подождите.'
    } else {
      codeError.value = data?.message || 'Ошибка проверки кода'
    }
  } finally {
    loading.value = false
  }
}

function backToPhone() {
  step.value = 'phone'
  code.value = ''
  codeError.value = ''
  challengeId.value = ''
}
</script>
