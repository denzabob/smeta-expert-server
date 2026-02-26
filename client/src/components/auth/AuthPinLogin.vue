<template>
  <div class="pin-login">
    <!-- Аватар / иконка пользователя -->
    <div class="text-center mb-4">
      <v-avatar color="primary" size="64" class="mb-3">
        <v-icon size="36" color="white">mdi-account</v-icon>
      </v-avatar>
      <div class="text-subtitle-1 font-weight-medium">{{ userName || 'Пользователь' }}</div>
      <div v-if="userEmail" class="text-caption text-medium-emphasis">{{ userEmail }}</div>
    </div>

    <!-- Сообщение об ошибке -->
    <v-alert
      v-if="errorMessage"
      type="error"
      variant="tonal"
      closable
      class="mb-4"
      density="compact"
      @click:close="errorMessage = ''"
    >
      {{ errorMessage }}
    </v-alert>

    <!-- Блокировка -->
    <v-alert
      v-if="isLocked"
      type="warning"
      variant="tonal"
      class="mb-4"
      density="compact"
    >
      PIN-вход заблокирован. Повторите позже или войдите по паролю.
    </v-alert>

    <!-- PIN ввод -->
    <div class="mb-4">
      <div class="text-body-2 text-center text-medium-emphasis mb-3">
        Введите PIN-код для быстрого входа
      </div>

      <PinInput
        ref="pinInputRef"
        v-model="pin"
        :disabled="loading || isLocked"
        :has-error="hasError"
        autofocus
        @complete="onPinComplete"
      />
    </div>

    <!-- Оставшиеся попытки -->
    <div v-if="attemptsRemaining !== null && attemptsRemaining < 5" class="text-center mb-3">
      <span class="text-caption text-warning">
        Осталось попыток: {{ attemptsRemaining }}
      </span>
    </div>

    <!-- Лоадер -->
    <div v-if="loading" class="text-center mb-3">
      <v-progress-circular indeterminate size="24" color="primary" />
    </div>

    <!-- Кнопки -->
    <div class="d-flex flex-column align-center gap-1 mt-2">
      <v-btn
        variant="text"
        size="small"
        class="text-none"
        @click="emit('forgot-pin')"
        :disabled="loading"
      >
        Забыли код?
      </v-btn>

      <v-btn
        variant="text"
        size="small"
        class="text-none"
        @click="emit('switch-account')"
        :disabled="loading"
      >
        Сменить аккаунт
      </v-btn>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { pinApi } from '@/api/pin'
import PinInput from './PinInput.vue'

const props = defineProps<{
  userName?: string | null
  userEmail?: string | null
}>()

const emit = defineEmits<{
  (e: 'forgot-pin'): void
  (e: 'switch-account'): void
}>()

const authStore = useAuthStore()
const router = useRouter()
const route = useRoute()

const pin = ref('')
const loading = ref(false)
const errorMessage = ref('')
const hasError = ref(false)
const isLocked = ref(false)
const attemptsRemaining = ref<number | null>(null)
const pinInputRef = ref<InstanceType<typeof PinInput> | null>(null)

const onPinComplete = async (completedPin: string) => {
  if (loading.value || isLocked.value) return

  loading.value = true
  errorMessage.value = ''
  hasError.value = false

  try {
    await pinApi.loginByPin(completedPin)
    await authStore.checkAuth(true)

    // Redirect
    const intendedRaw = route.query.intended
    const intended = typeof intendedRaw === 'string' ? intendedRaw : ''

    if (intended && intended.startsWith('/')) {
      await router.replace(intended)
    } else {
        await router.push({ name: 'projects' })
    }
  } catch (e: any) {
    const status = e.response?.status
    const data = e.response?.data

    if (status === 429) {
      isLocked.value = true
      errorMessage.value = data?.message || 'Слишком много попыток'
    } else if (status === 403 && data?.device_revoked) {
      errorMessage.value = 'Устройство отозвано. Войдите по паролю.'
      isLocked.value = true
    } else if (status === 401) {
      errorMessage.value = data?.message || 'Неверный PIN-код'
      attemptsRemaining.value = data?.attempts_remaining ?? null
      hasError.value = true
    } else {
      errorMessage.value = 'Ошибка входа. Попробуйте позже.'
    }

    // Очистить PIN и поставить фокус
    pin.value = ''
    pinInputRef.value?.clear()
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.pin-login {
  padding: 8px 0;
}
.text-none {
  text-transform: none;
}
.gap-1 {
  gap: 4px;
}
</style>
