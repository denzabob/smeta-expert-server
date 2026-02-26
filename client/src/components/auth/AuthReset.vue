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
        ref="passwordRef"
        v-model="form.password"
        label="Новый пароль"
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

      <div class="d-flex justify-end mt-2">
        <v-btn
          color="primary"
          class="text-none"
          :loading="loading"
          :disabled="!formValid"
          type="submit"
        >
          Сменить пароль
        </v-btn>
      </div>
    </v-form>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/api/axios'

const route = useRoute()
const router = useRouter()

const form = ref({
  password: '',
  passwordConfirm: '',
})

const formRef = ref<any>()
const formValid = ref(false)

const passwordRef = ref<any>()

const loading = ref(false)
const errorMessage = ref('')

const showPassword = ref(false)
const showPasswordConfirm = ref(false)

const rules = {
  required: (v: string) => (!!v && v.trim() !== '') || 'Обязательное поле',
  minLength: (min: number) => (v: string) => (v && v.length >= min) || `Минимум ${min} символов`,
  passwordMatch: (v: string) => v === form.value.password || 'Пароли не совпадают',
}

onMounted(async () => {
  await nextTick()
  passwordRef.value?.focus?.()
})

const invalidLinkMessage = 'Ссылка недействительна или устарела'

const submit = async () => {
  const { valid } = (await formRef.value?.validate()) ?? { valid: false }
  if (!valid) return

  const tokenRaw = route.query.token
  const emailRaw = route.query.email
  const token = typeof tokenRaw === 'string' ? tokenRaw : ''
  const email = typeof emailRaw === 'string' ? emailRaw : ''

  if (!token || !email) {
    errorMessage.value = invalidLinkMessage
    return
  }

  loading.value = true
  errorMessage.value = ''

  try {
    await api.post('/api/reset-password', {
      token,
      email,
      password: form.value.password,
      password_confirmation: form.value.passwordConfirm,
    })

    // После успеха — на login с сообщением
    await router.replace({
      name: 'login',
      query: {
        message: 'password-reset',
      },
    })
  } catch (e) {
    errorMessage.value = invalidLinkMessage
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
