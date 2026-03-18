<template>
  <div class="auth-onboarding">
    <div class="text-body-2 text-medium-emphasis mb-4">
      Осталось заполнить профиль для начала работы
    </div>

    <v-text-field
      v-model="form.full_name"
      label="Полное имя (ФИО)"
      variant="outlined"
      density="comfortable"
      prepend-inner-icon="mdi-account"
      :error-messages="errors.full_name"
      @input="errors.full_name = ''"
    />

    <v-text-field
      v-model="form.email"
      label="Email"
      type="email"
      variant="outlined"
      density="comfortable"
      prepend-inner-icon="mdi-email"
      :error-messages="errors.email"
      @input="errors.email = ''"
    />

    <v-select
      v-model="form.activity_profile"
      label="Профиль деятельности"
      variant="outlined"
      density="comfortable"
      prepend-inner-icon="mdi-briefcase"
      :items="activityProfiles"
      :error-messages="errors.activity_profile"
    />

    <v-checkbox
      v-model="form.accept_terms"
      density="compact"
      hide-details="auto"
      :error-messages="errors.accept_terms"
    >
      <template #label>
        <span class="text-body-2">
          Принимаю <a href="#" @click.prevent>условия использования</a>
        </span>
      </template>
    </v-checkbox>

    <v-checkbox
      v-model="form.accept_privacy"
      density="compact"
      hide-details="auto"
      class="mb-4"
      :error-messages="errors.accept_privacy"
    >
      <template #label>
        <span class="text-body-2">
          Согласен с <a href="#" @click.prevent>политикой конфиденциальности</a>
        </span>
      </template>
    </v-checkbox>

    <v-btn
      block
      color="primary"
      size="large"
      :loading="loading"
      :disabled="!isFormValid"
      @click="submit"
    >
      Завершить регистрацию
    </v-btn>

    <v-btn
      block
      variant="text"
      class="mt-2"
      :disabled="loading"
      @click="emit('switch-account')"
    >
      Войти под другим аккаунтом
    </v-btn>

    <v-alert v-if="generalError" type="error" variant="tonal" class="mt-3" density="compact">
      {{ generalError }}
    </v-alert>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { phoneAuthApi, type CompleteRegistrationResponse } from '@/api/phoneAuth'

const emit = defineEmits<{
  (e: 'completed', data: CompleteRegistrationResponse): void
  (e: 'switch-account'): void
}>()

const activityProfiles = [
  { title: 'Оценщик', value: 'appraiser' },
  { title: 'Строитель / Прораб', value: 'builder' },
  { title: 'Дизайнер интерьеров', value: 'designer' },
  { title: 'Заказчик ремонта', value: 'customer' },
  { title: 'Другое', value: 'other' },
]

const form = reactive({
  full_name: '',
  email: '',
  activity_profile: '',
  accept_terms: false,
  accept_privacy: false,
})

const errors = reactive({
  full_name: '',
  email: '',
  activity_profile: '',
  accept_terms: '',
  accept_privacy: '',
})

const loading = ref(false)
const generalError = ref('')

const isFormValid = computed(() => {
  return (
    form.full_name.trim().length >= 2 &&
    form.email.includes('@') &&
    form.activity_profile &&
    form.accept_terms &&
    form.accept_privacy
  )
})

async function submit() {
  if (!isFormValid.value || loading.value) return

  generalError.value = ''
  Object.keys(errors).forEach((k) => ((errors as any)[k] = ''))
  loading.value = true

  try {
    const result = await phoneAuthApi.completeRegistration({
      full_name: form.full_name.trim(),
      email: form.email.trim(),
      activity_profile: form.activity_profile,
      accept_terms: form.accept_terms,
      accept_privacy: form.accept_privacy,
    })
    emit('completed', result)
  } catch (err: any) {
    const status = err.response?.status
    const data = err.response?.data

    if (status === 422 && data?.errors) {
      for (const [field, messages] of Object.entries(data.errors)) {
        if (field in errors) {
          ;(errors as any)[field] = (messages as string[])[0]
        }
      }
    } else {
      generalError.value = data?.message || 'Ошибка завершения регистрации'
    }
  } finally {
    loading.value = false
  }
}
</script>
