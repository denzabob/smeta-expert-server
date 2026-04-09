<template>
  <v-dialog
    :model-value="modelValue"
    @update:model-value="$emit('update:modelValue', $event)"
    max-width="440"
    persistent
  >
    <v-card>
      <v-card-title class="d-flex align-center gap-2 pt-5 pb-2 px-6">
        <v-icon color="primary" size="22">mdi-key-outline</v-icon>
        <span class="text-subtitle-1 font-weight-medium">
          {{ mode === 'set' ? 'Установить пароль' : 'Изменить пароль' }}
        </span>
      </v-card-title>

      <!-- MODE: set — step 1: step-up required -->
      <template v-if="mode === 'set' && !stepUpToken">
        <v-card-text>
          <v-alert type="info" variant="tonal" density="compact" class="mb-4">
            Для установки пароля сначала подтвердите личность.
          </v-alert>
          <p class="text-body-2 text-medium-emphasis">
            После установки пароля вы сможете входить в аккаунт с любого устройства
            и использовать сброс пароля через почту для восстановления.
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

      <!-- MODE: set — step 2: enter new password (after step-up) -->
      <!-- MODE: change — show current + new password -->
      <template v-else>
        <v-card-text>
          <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">
            {{ error }}
          </v-alert>
          <v-alert v-if="success" type="success" variant="tonal" density="compact" class="mb-4">
            {{ success }}
          </v-alert>

          <div class="d-flex flex-column gap-3">
            <!-- Current password (only for change mode) -->
            <v-text-field
              v-if="mode === 'change'"
              v-model="form.currentPassword"
              label="Текущий пароль"
              :type="showCurrent ? 'text' : 'password'"
              :append-inner-icon="showCurrent ? 'mdi-eye-off' : 'mdi-eye'"
              @click:append-inner="showCurrent = !showCurrent"
              autocomplete="current-password"
              hide-details="auto"
            />

            <!-- New password -->
            <v-text-field
              v-model="form.newPassword"
              label="Новый пароль"
              :type="showNew ? 'text' : 'password'"
              :append-inner-icon="showNew ? 'mdi-eye-off' : 'mdi-eye'"
              @click:append-inner="showNew = !showNew"
              autocomplete="new-password"
              hint="Минимум 8 символов"
              persistent-hint
            />

            <!-- Confirm -->
            <v-text-field
              v-model="form.confirmPassword"
              label="Подтвердите новый пароль"
              :type="showConfirm ? 'text' : 'password'"
              :append-inner-icon="showConfirm ? 'mdi-eye-off' : 'mdi-eye'"
              @click:append-inner="showConfirm = !showConfirm"
              autocomplete="new-password"
              :error-messages="passwordMismatch ? ['Пароли не совпадают'] : []"
              @keydown.enter="submit"
            />
          </div>
        </v-card-text>

        <v-card-actions class="px-6 pb-5">
          <v-btn variant="text" @click="close" :disabled="saving">Отмена</v-btn>
          <v-spacer />
          <v-btn
            color="primary"
            variant="flat"
            :loading="saving"
            :disabled="!canSubmit"
            @click="submit"
          >
            {{ mode === 'set' ? 'Установить пароль' : 'Изменить пароль' }}
          </v-btn>
        </v-card-actions>
      </template>
    </v-card>
  </v-dialog>

  <!-- Step-up dialog for 'set' mode -->
  <StepUpDialog
    v-model="showStepUp"
    scope="set_password"
    title="Подтверждение для установки пароля"
    @completed="onStepUpCompleted"
    @cancelled="showStepUp = false"
  />
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { securityApi } from '@/api/security'
import { authApi } from '@/api/auth'
import StepUpDialog from './StepUpDialog.vue'

const props = defineProps<{
  modelValue: boolean
  mode: 'set' | 'change'
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', val: boolean): void
  (e: 'completed'): void
}>()

// ── State ────────────────────────────────────────────────────────────────────

const showStepUp = ref(false)
const stepUpToken = ref<string | null>(null)
const saving = ref(false)
const error = ref<string | null>(null)
const success = ref<string | null>(null)

const showCurrent = ref(false)
const showNew = ref(false)
const showConfirm = ref(false)

const form = ref({
  currentPassword: '',
  newPassword: '',
  confirmPassword: '',
})

const passwordMismatch = computed(
  () => !!form.value.confirmPassword && form.value.newPassword !== form.value.confirmPassword,
)

const canSubmit = computed(() => {
  if (form.value.newPassword.length < 8) return false
  if (passwordMismatch.value) return false
  if (props.mode === 'change' && !form.value.currentPassword) return false
  return true
})

// ── Reset on open ────────────────────────────────────────────────────────────

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      stepUpToken.value = null
      error.value = null
      success.value = null
      form.value = { currentPassword: '', newPassword: '', confirmPassword: '' }
    }
  },
)

// ── Step-up callback (set mode) ──────────────────────────────────────────────

function onStepUpCompleted(token: string) {
  stepUpToken.value = token
  showStepUp.value = false
}

// ── Submit ───────────────────────────────────────────────────────────────────

async function submit() {
  if (!canSubmit.value) return
  saving.value = true
  error.value = null
  success.value = null

  try {
    if (props.mode === 'set') {
      await securityApi.setPassword(stepUpToken.value!, form.value.newPassword, form.value.confirmPassword)
      success.value = 'Пароль успешно установлен.'
    } else {
      await authApi.changePassword({
        current_password: form.value.currentPassword,
        new_password: form.value.newPassword,
        new_password_confirmation: form.value.confirmPassword,
      })
      success.value = 'Пароль успешно изменён.'
    }

    setTimeout(() => {
      emit('completed')
      close()
    }, 1200)
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Не удалось сохранить пароль. Попробуйте снова.'
  } finally {
    saving.value = false
  }
}

function close() {
  emit('update:modelValue', false)
}
</script>
