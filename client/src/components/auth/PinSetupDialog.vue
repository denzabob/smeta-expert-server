<template>
  <v-dialog v-model="dialogVisible" max-width="420" persistent>
    <v-card>
      <v-card-title class="text-h6">
        <v-icon class="mr-2" color="primary">mdi-shield-lock</v-icon>
        Быстрый вход по PIN
      </v-card-title>

      <v-card-text>
        <div class="text-body-2 text-medium-emphasis mb-4">
          Настройте 4-значный PIN-код для быстрого входа на этом устройстве.
          При следующем входе вам не потребуется вводить логин и пароль.
        </div>

        <v-alert
          v-if="errorMessage"
          type="error"
          variant="tonal"
          class="mb-4"
          density="compact"
        >
          {{ errorMessage }}
        </v-alert>

        <!-- Шаг 1: Ввод PIN -->
        <template v-if="step === 'enter'">
          <div class="text-body-2 font-weight-medium text-center mb-3">Придумайте PIN-код</div>
          <PinInput
            ref="pinRef1"
            v-model="pin1"
            autofocus
            @complete="onPin1Complete"
          />
        </template>

        <!-- Шаг 2: Подтверждение PIN -->
        <template v-if="step === 'confirm'">
          <div class="text-body-2 font-weight-medium text-center mb-3">Подтвердите PIN-код</div>
          <PinInput
            ref="pinRef2"
            v-model="pin2"
            autofocus
            @complete="onPin2Complete"
          />
        </template>

        <!-- Шаг 3: Ввод пароля -->
        <template v-if="step === 'password'">
          <div class="text-body-2 font-weight-medium text-center mb-3">
            Введите текущий пароль для подтверждения
          </div>
          <v-text-field
            ref="passwordRef"
            v-model="password"
            label="Пароль"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="current-password"
            :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
            @click:append-inner="showPassword = !showPassword"
            @keydown.enter="onSubmit"
          />
          <v-checkbox
            v-model="trustDevice"
            label="Доверять этому устройству"
            hide-details
            density="compact"
          />
        </template>
      </v-card-text>

      <v-card-actions>
        <v-btn variant="text" @click="onSkip" :disabled="loading">
          Пропустить
        </v-btn>
        <v-spacer />

        <v-btn
          v-if="step === 'password'"
          color="primary"
          variant="flat"
          :loading="loading"
          :disabled="!password"
          @click="onSubmit"
        >
          Установить PIN
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, watch, nextTick } from 'vue'
import { pinApi } from '@/api/pin'
import PinInput from './PinInput.vue'

const props = defineProps<{
  modelValue: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'done'): void
  (e: 'skip'): void
}>()

const dialogVisible = ref(props.modelValue)

watch(
  () => props.modelValue,
  (val) => { dialogVisible.value = val }
)

watch(dialogVisible, (val) => {
  emit('update:modelValue', val)
})

type Step = 'enter' | 'confirm' | 'password'
const step = ref<Step>('enter')
const pin1 = ref('')
const pin2 = ref('')
const password = ref('')
const showPassword = ref(false)
const trustDevice = ref(true)
const loading = ref(false)
const errorMessage = ref('')

const pinRef1 = ref<InstanceType<typeof PinInput> | null>(null)
const pinRef2 = ref<InstanceType<typeof PinInput> | null>(null)
const passwordRef = ref<any>(null)

const onPin1Complete = () => {
  step.value = 'confirm'
  nextTick(() => {
    pinRef2.value?.focus()
  })
}

const onPin2Complete = () => {
  if (pin1.value !== pin2.value) {
    errorMessage.value = 'PIN-коды не совпадают'
    pin2.value = ''
    pinRef2.value?.clear()
    return
  }

  errorMessage.value = ''
  step.value = 'password'
  nextTick(() => {
    passwordRef.value?.focus?.()
  })
}

const onSubmit = async () => {
  if (!password.value || loading.value) return

  loading.value = true
  errorMessage.value = ''

  try {
    await pinApi.setPin({
      pin: pin1.value,
      pin_confirm: pin2.value,
      password: password.value,
      trust_device: trustDevice.value,
    })

    dialogVisible.value = false
    emit('done')
  } catch (e: any) {
    const msg = e.response?.data?.message
    errorMessage.value = msg || 'Не удалось установить PIN'

    if (msg?.toLowerCase().includes('пароль')) {
      // Пароль неверен — остаёмся на шаге password
    } else {
      // PIN ошибка — начать заново
      step.value = 'enter'
      pin1.value = ''
      pin2.value = ''
      password.value = ''
      nextTick(() => {
        pinRef1.value?.focus()
      })
    }
  } finally {
    loading.value = false
  }
}

const onSkip = () => {
  dialogVisible.value = false
  emit('skip')
}
</script>
