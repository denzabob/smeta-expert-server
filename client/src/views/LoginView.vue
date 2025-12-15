<template>
  <v-container class="fill-height" fluid>
    <v-row align="center" justify="center">
      <v-col cols="12" sm="8" md="4">
        <v-card>
          <v-card-title class="text-h6">Вход в систему</v-card-title>
          <v-card-text>
            <v-form ref="formRef" v-model="formValid">
              <v-text-field
                v-model="email"
                label="E-mail"
                type="email"
                autocomplete="email"
                :rules="[rules.required]"
              />
              <v-text-field
                v-model="password"
                label="Пароль"
                type="password"
                autocomplete="current-password"
                :rules="[rules.required]"
              />
            </v-form>
            <p class="text-caption text-medium-emphasis mt-2">
              Авторизация пока не подключена к API. Форма подготовлена под будущий бэкенд.
            </p>
          </v-card-text>
          <v-card-actions>
            <v-spacer />
            <v-btn
              color="primary"
              class="text-none"
              :loading="loading"
              :disabled="!formValid"
              @click="submit"
            >
              Войти
            </v-btn>
          </v-card-actions>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()

const email = ref('')
const password = ref('')
const formValid = ref(false)
const formRef = ref()
const loading = ref(false)

const rules = {
  required: (v: string) => (!!v && v.trim() !== '') || 'Обязательное поле',
}

const submit = async () => {
  const { valid } = (await formRef.value?.validate()) ?? { valid: false }
  if (!valid) return

  loading.value = true
  try {
    const response = await fetch('/api/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: email.value, password: password.value }),
    })

    const data = await response.json().catch(() => ({}))

    if (!response.ok) {
      throw new Error(data?.message || 'Ошибка авторизации')
    }

    if (data?.token) {
      localStorage.setItem('auth_token', data.token as string)
    }

    await router.push({ name: 'materials' })
  } catch (error) {
    console.error(error)
    alert(error instanceof Error ? error.message : 'Ошибка авторизации')
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


