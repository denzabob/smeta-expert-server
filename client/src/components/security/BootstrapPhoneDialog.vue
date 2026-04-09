<template>
  <v-dialog
    :model-value="modelValue"
    @update:model-value="$emit('update:modelValue', $event)"
    max-width="440"
    persistent
  >
    <v-card>
      <v-card-title class="d-flex align-center gap-2 pt-5 pb-2 px-6">
        <v-icon color="primary" size="22">mdi-cellphone-plus</v-icon>
        <span class="text-subtitle-1 font-weight-medium">Добавить номер телефона</span>
      </v-card-title>

      <!-- Step 1: Phone input -->
      <template v-if="step === 'phone'">
        <v-card-text>
          <v-alert type="info" variant="tonal" density="compact" class="mb-4">
            После добавления телефона вы сможете установить пароль и быстрый PIN.
          </v-alert>
          <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
            {{ error }}
          </v-alert>
          <v-text-field
            v-model="phone"
            label="Номер телефона"
            placeholder="+7 999 000 00 00"
            type="tel"
            inputmode="tel"
            autocomplete="tel"
            @keydown.enter="initiatePhone"
            autofocus
            hint="Формат: +7XXXXXXXXXX"
            persistent-hint
          />
        </v-card-text>
        <v-card-actions class="px-6 pb-5">
          <v-btn variant="text" @click="close">Отмена</v-btn>
          <v-spacer />
          <v-btn
            color="primary"
            variant="flat"
            :loading="loading"
            :disabled="!phone.trim()"
            @click="initiatePhone"
          >
            Получить код
          </v-btn>
        </v-card-actions>
      </template>

      <!-- Step 2: OTP -->
      <template v-else-if="step === 'otp'">
        <v-card-text>
          <div class="text-body-2 mb-1">
            Код отправлен на <strong>{{ phoneMasked }}</strong>.
          </div>
          <div class="text-caption text-medium-emphasis mb-4">
            Введите 4–6 цифр из SMS-сообщения.
          </div>
          <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
            {{ error }}
          </v-alert>
          <v-text-field
            v-model="code"
            label="Код подтверждения"
            inputmode="numeric"
            maxlength="6"
            @keydown.enter="verifyOtp"
            autofocus
            hide-details="auto"
          />
        </v-card-text>
        <v-card-actions class="px-6 pb-5">
          <v-btn variant="text" @click="step = 'phone'" :disabled="loading">Назад</v-btn>
          <v-spacer />
          <v-btn
            color="primary"
            variant="flat"
            :loading="loading"
            :disabled="!code.trim()"
            @click="verifyOtp"
          >
            Подтвердить
          </v-btn>
        </v-card-actions>
      </template>

      <!-- Success -->
      <template v-else-if="step === 'success'">
        <v-card-text class="text-center py-8">
          <v-icon color="success" size="48">mdi-check-circle-outline</v-icon>
          <div class="text-body-1 font-weight-medium mt-3">Телефон добавлен</div>
          <div class="text-body-2 text-medium-emphasis mt-1">
            Теперь вы можете установить пароль и включить быстрый вход по PIN.
          </div>
        </v-card-text>
        <v-card-actions class="px-6 pb-5">
          <v-spacer />
          <v-btn color="primary" variant="flat" @click="close">Готово</v-btn>
        </v-card-actions>
      </template>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { securityApi } from '@/api/security'

const props = defineProps<{
  modelValue: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', val: boolean): void
  (e: 'completed', recommendedActions: string[]): void
}>()

// ── State ────────────────────────────────────────────────────────────────────

const step = ref<'phone' | 'otp' | 'success'>('phone')
const phone = ref('')
const code = ref('')
const phoneMasked = ref('')
const challengeId = ref<string | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

// ── Reset on open ────────────────────────────────────────────────────────────

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      step.value = 'phone'
      phone.value = ''
      code.value = ''
      phoneMasked.value = ''
      challengeId.value = null
      error.value = null
    }
  },
)

// ── Actions ──────────────────────────────────────────────────────────────────

async function initiatePhone() {
  if (!phone.value.trim()) return
  loading.value = true
  error.value = null
  try {
    const res = await securityApi.bootstrapPhoneInitiate(phone.value.trim())
    challengeId.value = res.challenge_id
    phoneMasked.value = res.phone_masked
    step.value = 'otp'
  } catch (e: any) {
    const status = e?.response?.status
    if (status === 409) {
      error.value = 'У вашего аккаунта уже есть телефон.'
    } else if (status === 403) {
      error.value = 'Эта функция доступна только для Яндекс-аккаунтов без телефона.'
    } else {
      error.value = e?.response?.data?.message || 'Не удалось отправить код. Попробуйте снова.'
    }
  } finally {
    loading.value = false
  }
}

async function verifyOtp() {
  if (!code.value.trim() || !challengeId.value) return
  loading.value = true
  error.value = null
  try {
    const res = await securityApi.bootstrapPhoneVerify(challengeId.value, code.value.trim())
    step.value = 'success'
    setTimeout(() => {
      emit('completed', res.recommended_actions)
      close()
    }, 1500)
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Неверный код. Попробуйте снова.'
  } finally {
    loading.value = false
  }
}

function close() {
  emit('update:modelValue', false)
}
</script>
