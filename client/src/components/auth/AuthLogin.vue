<template>
  <div>
    <v-alert
      v-if="errorMessage"
      type="error"
      variant="tonal"
      closable
      class="mb-4"
      @click:close="errorMessage = ''"
    >
      {{ errorMessage }}
    </v-alert>

    <v-form ref="formRef" v-model="formValid" @submit.prevent="submit">
      <v-text-field
        ref="emailRef"
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
        autocomplete="current-password"
        :rules="[rules.required]"
        :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
        @click:append-inner="showPassword = !showPassword"
      />

      <div class="mt-2">
        <v-btn
          color="primary"
          class="text-none"
          block
          :loading="loading"
          :disabled="!formValid"
          type="submit"
        >
          Войти
        </v-btn>

        <v-btn
          variant="text"
          size="small"
          class="text-none px-0 mt-2"
          @click="emit('forgot')"
        >
          Забыли пароль?
        </v-btn>
      </div>

      <div class="mt-2">
        <v-btn
          variant="text"
          size="small"
          class="text-none px-0"
          @click="emit('register')"
        >
          Нет аккаунта? Зарегистрироваться
        </v-btn>
      </div>
    </v-form>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '@/api/axios'
import { useAuthStore } from '@/stores/auth'

const emit = defineEmits<{ (e: 'forgot'): void; (e: 'register'): void; (e: 'login-success', data: any): void }>()

const authStore = useAuthStore()
const router = useRouter()
const route = useRoute()

const form = ref({
  email: '',
  password: '',
})

const formRef = ref<any>()
const formValid = ref(false)
const emailRef = ref<any>()

const loading = ref(false)
const errorMessage = ref('')
const showPassword = ref(false)

const rules = {
  required: (v: string) => (!!v && v.trim() !== '') || 'Обязательное поле',
  email: (v: string) => /.+@.+\..+/.test(v) || 'Некорректный e-mail',
}

onMounted(async () => {
  await nextTick()
  emailRef.value?.focus?.()
})

const submit = async () => {
  const { valid } = (await formRef.value?.validate()) ?? { valid: false }
  if (!valid) return

  loading.value = true
  errorMessage.value = ''

  try {
    await api.get('/sanctum/csrf-cookie')

    const response = await api.post('/api/login', {
      email: form.value.email,
      password: form.value.password,
    })

    await authStore.checkAuth(true)

    // Emit login-success, позволяя родителю решить нужно ли показать PIN setup
    emit('login-success', response.data)

    // Если родитель не обработал (нет слушателя) — навигация по умолчанию
    // LoginView обрабатывает этот emit и управляет навигацией
  } catch (e) {
    errorMessage.value = 'Неверные учетные данные'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.text-none {
  text-transform: none;
}
</style>
