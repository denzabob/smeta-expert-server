<template>
  <div>
    <v-alert
      v-if="infoMessage"
      type="info"
      variant="tonal"
      closable
      class="mb-4"
      @click:close="infoMessage = ''"
    >
      {{ infoMessage }}
    </v-alert>

    <v-form ref="formRef" v-model="formValid" @submit.prevent="submit">
      <v-text-field
        ref="nameRef"
        v-model="form.name"
        label="Имя"
        autocomplete="name"
      />

      <v-text-field
        v-model="form.email"
        label="E-mail"
        type="email"
        autocomplete="email"
        :rules="[rules.required, rules.email]"
      />

      <v-text-field
        v-model="form.password"
        label="Пароль"
        :type="showPassword ? 'text' : 'password'"
        autocomplete="new-password"
        :rules="[rules.required, rules.minLength(8)]"
        :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
        @click:append-inner="showPassword = !showPassword"
      />

      <v-text-field
        v-model="form.passwordConfirm"
        label="Подтвердите пароль"
        :type="showPasswordConfirm ? 'text' : 'password'"
        autocomplete="new-password"
        :rules="[rules.required, rules.passwordMatch]"
        :append-inner-icon="showPasswordConfirm ? 'mdi-eye-off' : 'mdi-eye'"
        @click:append-inner="showPasswordConfirm = !showPasswordConfirm"
      />

      <v-checkbox
        v-model="form.acceptTerms"
        :rules="[rules.mustAccept]"
        density="compact"
      >
        <template #label>
          <span class="text-body-2">Согласен с условиями использования</span>
        </template>
      </v-checkbox>

      <div class="d-flex justify-space-between align-center mt-2">
        <v-btn
          variant="text"
          size="small"
          class="text-none px-0"
          @click="emit('login')"
        >
          Уже есть аккаунт? Войти
        </v-btn>

        <v-btn
          color="primary"
          class="text-none"
          :loading="loading"
          :disabled="!formValid"
          type="submit"
        >
          Создать аккаунт
        </v-btn>
      </div>
    </v-form>
  </div>
</template>

<script setup lang="ts">
import { onMounted, nextTick, ref } from 'vue'

const emit = defineEmits<{ (e: 'login'): void }>()

const form = ref({
  name: '',
  email: '',
  password: '',
  passwordConfirm: '',
  acceptTerms: false,
})

const formRef = ref<any>()
const formValid = ref(false)

const nameRef = ref<any>()

const loading = ref(false)
const infoMessage = ref('')

const showPassword = ref(false)
const showPasswordConfirm = ref(false)

const rules = {
  required: (v: string) => (!!v && v.trim() !== '') || 'Обязательное поле',
  email: (v: string) => /.+@.+\..+/.test(v) || 'Некорректный e-mail',
  minLength: (min: number) => (v: string) => (v && v.length >= min) || `Минимум ${min} символов`,
  passwordMatch: (v: string) => v === form.value.password || 'Пароли не совпадают',
  mustAccept: (v: boolean) => v === true || 'Необходимо принять условия',
}

onMounted(async () => {
  await nextTick()
  nameRef.value?.focus?.()
})

const submit = async () => {
  const { valid } = (await formRef.value?.validate()) ?? { valid: false }
  if (!valid) return

  loading.value = true
  infoMessage.value = ''

  await new Promise((resolve) => setTimeout(resolve, 400))

  loading.value = false
  infoMessage.value = 'Регистрация будет доступна позже. Функция находится в разработке.'
}
</script>

<style scoped>
.text-none {
  text-transform: none;
}
</style>
