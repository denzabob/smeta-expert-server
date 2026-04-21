<template>
  <v-btn
    variant="outlined"
    block
    size="large"
    :loading="loading"
    :disabled="loading"
    class="yandex-login-btn md3-auth-provider"
    @click="redirectToYandex"
  >
    <template #prepend>
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M12 24C18.6274 24 24 18.6274 24 12C24 5.37258 18.6274 0 12 0C5.37258 0 0 5.37258 0 12C0 18.6274 5.37258 24 12 24Z" fill="#FC3F1D"/>
        <path d="M13.63 7.2H12.7C11.16 7.2 10.35 7.95 10.35 9.09C10.35 10.38 10.9 10.98 12.04 11.73L12.97 12.36L10.38 16.8H8.4L10.72 12.81C9.33 11.85 8.55 10.95 8.55 9.24C8.55 7.08 10.02 5.7 12.72 5.7H15.42V16.8H13.63V7.2Z" fill="white"/>
      </svg>
    </template>
    Войти через Яндекс
  </v-btn>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { phoneAuthApi } from '@/api/phoneAuth'

const loading = ref(false)

async function redirectToYandex() {
  if (loading.value) return
  loading.value = true

  try {
    const { redirect_url } = await phoneAuthApi.getYandexRedirectUrl()
    window.location.href = redirect_url
  } catch {
    loading.value = false
  }
}
</script>

<style scoped>
.yandex-login-btn {
  text-transform: none;
  letter-spacing: normal;
}
</style>
