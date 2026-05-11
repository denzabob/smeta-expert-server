<template>
  <v-btn
    variant="outlined"
    block
    size="large"
    :loading="loading"
    :disabled="loading"
    class="vk-login-btn md3-auth-provider"
    @click="redirectToVk"
  >
    <template #prepend>
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M12 24C18.6274 24 24 18.6274 24 12C24 5.37258 18.6274 0 12 0C5.37258 0 0 5.37258 0 12C0 18.6274 5.37258 24 12 24Z" fill="#0077FF"/>
        <path d="M12.72 16.54C7.41 16.54 4.38 12.9 4.25 6.84H6.91C6.99 11.29 8.96 13.17 10.52 13.56V6.84H13.03V10.68C14.57 10.51 16.18 8.77 16.72 6.84H19.23C18.82 9.22 17.08 10.96 15.84 11.68C17.08 12.26 19.06 13.78 19.81 16.54H17.05C16.45 14.69 14.98 13.26 13.03 13.06V16.54H12.72Z" fill="white"/>
      </svg>
    </template>
    Войти через VK ID
  </v-btn>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { phoneAuthApi } from '@/api/phoneAuth'

const loading = ref(false)

async function redirectToVk() {
  if (loading.value) return
  loading.value = true

  try {
    const { redirect_url } = await phoneAuthApi.getVkRedirectUrl()
    window.location.href = redirect_url
  } catch {
    loading.value = false
  }
}
</script>

<style scoped>
.vk-login-btn {
  text-transform: none;
  letter-spacing: normal;
}
</style>
