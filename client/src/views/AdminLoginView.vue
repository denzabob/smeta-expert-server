<template>
  <v-container class="fill-height" fluid>
    <v-row align="center" justify="center">
      <v-col cols="auto">
        <v-card width="380">
          <v-card-title class="text-h6">Вход в админ‑панель</v-card-title>
          <v-card-subtitle class="text-medium-emphasis">
            Доступ только для пользователя с правами администратора
          </v-card-subtitle>
          <v-card-text class="auth-card-text">
            <AuthLogin @forgot="goForgot" @register="goRegister" />
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthLogin from '@/components/auth/AuthLogin.vue'

const route = useRoute()
const router = useRouter()

onMounted(async () => {
  const intendedRaw = route.query.intended
  const intended = typeof intendedRaw === 'string' ? intendedRaw : ''
  if (!intended) {
    await router.replace({ query: { ...route.query, intended: '/admin' } })
  }
})

const goForgot = async () => {
  await router.replace({ name: 'login', query: { mode: 'forgot' } })
}

const goRegister = async () => {
  await router.replace({ name: 'login', query: { mode: 'register' } })
}
</script>

<style scoped>
.auth-card-text {
  padding: 10px;
}
</style>
