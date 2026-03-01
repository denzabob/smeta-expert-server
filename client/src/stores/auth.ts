// src/stores/auth.ts
import { ref } from 'vue'
import { defineStore } from 'pinia'
import api, { ensureCsrfCookie } from '@/api/axios'  // твой Axios с withCredentials: true

export const useAuthStore = defineStore('auth', () => {
  const user = ref<any>(null)
  const isAuthenticated = ref(false)
  const authChecked = ref(false)  // флаг: авторизация уже проверена

  // Проверка текущего пользователя
  async function checkAuth(force = false) {
    if (authChecked.value && !force) {
      console.log('[AUTH] Already checked, skipping (use force=true to recheck)');
      return;
    }

    console.log('[AUTH] Checking authentication...');

    try {
      // Убедимся, что CSRF токен инициализирован (может помочь при повторных проверках)
      try {
        await ensureCsrfCookie(2000)
        console.log('[AUTH] CSRF token refreshed');
      } catch (e) {
        // Это не критично, может быть offline или уже есть токен
        console.debug('[AUTH] CSRF cookie note:', (e as any).message)
      }
      
      // Теперь проверяем авторизацию
      console.log('[AUTH] Requesting /api/me...');
      const response = await api.get('/api/me', { timeout: 5000 })
      user.value = response.data
      isAuthenticated.value = true
      console.log('[AUTH] ✅ Authenticated as:', response.data.email || response.data.id)
    } catch (error: any) {
      console.log('[AUTH] ❌ Not authenticated:', error.response?.status || error.message)
      user.value = null
      isAuthenticated.value = false
    } finally {
      authChecked.value = true
      console.log('[AUTH] Check complete. Authenticated:', isAuthenticated.value)
    }
  }

  // Logout
  async function logout(options?: { skipApi?: boolean }) {
    try {
      if (!options?.skipApi) {
        await api.post('/api/logout')
      }
    } catch (error) {
      console.error('Ошибка при logout:', error)
    } finally {
      user.value = null
      isAuthenticated.value = false
      authChecked.value = false
    }
  }

  return { user, isAuthenticated, authChecked, checkAuth, logout }
})
