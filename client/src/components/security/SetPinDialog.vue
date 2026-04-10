<template>
  <v-dialog
    :model-value="modelValue"
    @update:model-value="$emit('update:modelValue', $event)"
    max-width="400"
    persistent
  >
    <v-card>
      <v-card-title class="d-flex align-center gap-2 pt-5 pb-2 px-6">
        <v-icon color="primary" size="22">mdi-dialpad</v-icon>
        <span class="text-subtitle-1 font-weight-medium">Включить быстрый PIN</span>
      </v-card-title>

      <!-- Step 1: step-up required -->
      <template v-if="!stepUpToken">
        <v-card-text>
          <v-alert type="info" variant="tonal" density="compact" class="mb-4">
            Для установки PIN сначала подтвердите личность.
          </v-alert>
          <p class="text-body-2 text-medium-emphasis">
            Быстрый PIN позволяет входить на доверенных устройствах без пароля или SMS.
            4-значный код, только на этом устройстве.
          </p>
        </v-card-text>
        <v-card-actions class="px-6 pb-5">
          <v-btn variant="text" @click="close">Отмена</v-btn>
          <v-spacer />
          <v-btn color="primary" variant="flat" @click="showStepUp = true">
            Подтвердить личность
          </v-btn>
        </v-card-actions>
      </template>

      <!-- Step 2: enter PIN -->
      <template v-else>
        <v-card-text>
          <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
            {{ error }}
          </v-alert>
          <p class="text-body-2 text-medium-emphasis mb-4">
            Введите 4-значный цифровой код. Он будет действовать только на этом устройстве.
          </p>

          <div class="d-flex flex-column gap-3">
            <v-text-field
              v-model="pin"
              label="PIN-код"
              :type="showPin ? 'text' : 'password'"
              inputmode="numeric"
              maxlength="4"
              :append-inner-icon="showPin ? 'mdi-eye-off' : 'mdi-eye'"
              @click:append-inner="showPin = !showPin"
              hint="Ровно 4 цифры"
              persistent-hint
              autocomplete="new-password"
              autofocus
            />
            <v-text-field
              v-model="pinConfirm"
              label="Повторите PIN-код"
              :type="showPin ? 'text' : 'password'"
              inputmode="numeric"
              maxlength="4"
              :error-messages="pinMismatch ? ['PIN-коды не совпадают'] : []"
              @keydown.enter="submit"
              autocomplete="new-password"
              hide-details="auto"
            />
          </div>
        </v-card-text>
        <v-card-actions class="px-6 pb-5">
          <v-btn variant="text" :disabled="saving" @click="close">Отмена</v-btn>
          <v-spacer />
          <v-btn
            color="primary"
            variant="flat"
            :loading="saving"
            :disabled="!canSubmit"
            @click="submit"
          >
            Установить PIN
          </v-btn>
        </v-card-actions>
      </template>
    </v-card>
  </v-dialog>

  <StepUpDialog
    v-model="showStepUp"
    scope="set_quick_pin"
    title="Подтверждение для установки PIN"
    @completed="onStepUpCompleted"
    @cancelled="showStepUp = false"
  />
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useSecurityStore } from '@/stores/security'
import StepUpDialog from './StepUpDialog.vue'

const props = defineProps<{
  modelValue: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', val: boolean): void
  (e: 'completed'): void
}>()

const store = useSecurityStore()

const showStepUp = ref(false)
const stepUpToken = ref<string | null>(null)
const pin = ref('')
const pinConfirm = ref('')
const showPin = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)

const pinMismatch = computed(
  () => !!pinConfirm.value && pin.value !== pinConfirm.value,
)

const canSubmit = computed(
  () => pin.value.length === 4 && /^\d{4}$/.test(pin.value) && !pinMismatch.value,
)

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      stepUpToken.value = null
      pin.value = ''
      pinConfirm.value = ''
      showPin.value = false
      error.value = null
    }
  },
)

function onStepUpCompleted(token: string) {
  stepUpToken.value = token
  showStepUp.value = false
}

async function submit() {
  if (!canSubmit.value || !stepUpToken.value) return
  saving.value = true
  error.value = null
  try {
    await store.enablePin(stepUpToken.value, pin.value)
    emit('completed')
    close()
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось установить PIN. Попробуйте снова.'
  } finally {
    saving.value = false
  }
}

function close() {
  emit('update:modelValue', false)
}
</script>
