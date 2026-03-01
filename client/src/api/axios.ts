import axios from 'axios';
import type { Router } from 'vue-router'

// All repository API calls already use absolute '/api/...' paths.
// Keep axios rooted at '/' so '/api' is not duplicated when VITE_API_URL='/api'.
const configuredBaseURL = import.meta.env.VITE_API_URL || '/'
const normalizedBaseURL =
  configuredBaseURL === '/api' || configuredBaseURL === '/api/'
    ? '/'
    : configuredBaseURL
const baseURL = normalizedBaseURL;

console.log('[AXIOS] Initializing with baseURL:', baseURL);
console.log('[AXIOS] Current origin:', window.location.origin);
console.log('[AXIOS] Current href:', window.location.href);

const api = axios.create({
  baseURL: baseURL,
  withCredentials: true,  // Отправляет/принимает куки
  timeout: 30000, // 30 секунд
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  }
});

// Sanctum CSRF must always hit /sanctum/* directly and must not inherit /api baseURL.
const csrfClient = axios.create({
  withCredentials: true,
  timeout: 5000,
  headers: {
    'Accept': 'application/json',
  }
});

export function ensureCsrfCookie(timeout = 5000) {
  return csrfClient.get('/sanctum/csrf-cookie', { timeout });
}

// Инициализируем CSRF токен при запуске
console.log('[AXIOS] Initializing CSRF token...');
ensureCsrfCookie()
  .then(() => {
    console.log('[AXIOS] ✅ CSRF token initialized successfully');
  })
  .catch(err => {
    console.warn('[AXIOS] ⚠️ CSRF cookie initialization warning:', err.message);
    console.warn('[AXIOS] This may be normal if XSRF-TOKEN is already in cookies');
  });

export type AxiosAuthInterceptorOptions = {
  router: Router
  onUnauthorized: () => void | Promise<void>
}

let interceptorsInstalled = false

export function setupAxiosInterceptors(options: AxiosAuthInterceptorOptions) {
  if (interceptorsInstalled) return
  interceptorsInstalled = true

  const { router, onUnauthorized } = options

  // Request interceptor для логирования
  api.interceptors.request.use(
    config => {
      const csrfToken = document.cookie
        .split('; ')
        .find(row => row.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];
      
      console.log('[AXIOS REQUEST]', {
        method: config.method?.toUpperCase(),
        url: config.url,
        baseURL: config.baseURL,
        fullURL: (config.baseURL || '') + (config.url || ''),
        withCredentials: config.withCredentials,
        hasCsrfToken: !!csrfToken,
        cookies: document.cookie
      });
      
      return config;
    },
    error => {
      console.error('[AXIOS REQUEST ERROR]', error);
      return Promise.reject(error);
    }
  );

  api.interceptors.response.use(
    response => {
      console.log('[AXIOS RESPONSE]', {
        status: response.status,
        url: response.config.url,
        headers: response.headers
      });
      return response;
    },
    async (error) => {
      // Логируем сетевые ошибки
      if (error.code === 'ERR_NETWORK' || error.message === 'Network Error') {
        console.error('❌ Network error:', {
          message: error.message,
          baseURL: api.defaults.baseURL,
          url: error.config?.url,
          fullUrl: baseURL
        });
      }

      const status = error.response?.status
      const config = error.config || {}
      const url = typeof config.url === 'string' ? config.url : ''

      // Обработка 419 (CSRF expired): один refresh и один retry
      if (status === 419 && !config._csrfRetry) {
        config._csrfRetry = true
        try {
          await ensureCsrfCookie()
          return api.request(config)
        } catch (csrfError) {
          try {
            await onUnauthorized()
          } catch {}

          const current = router.currentRoute.value
          if (current.name !== 'login') {
            await router.replace({ name: 'login', query: { intended: current.fullPath } })
          }
          return Promise.reject(csrfError)
        }
      }

      // 401: чистим auth state и уводим на login (кроме auth-эндпоинтов)
      if (status === 401) {
        const isAuthEndpoint =
          url.includes('/api/login') ||
          url.includes('/api/logout') ||
          url.includes('/api/forgot-password') ||
          url.includes('/api/reset-password') ||
          url.includes('/api/me') ||
          url.includes('/api/auth/pin/');  // PIN endpoints

        if (!isAuthEndpoint && !config._handled401) {
          config._handled401 = true

          // Проверяем причину завершения сеанса
          const reason = error.response?.data?.reason
          const sessionTerminated = reason === 'session_terminated'

          console.log('[AXIOS] 401 Unauthorized, redirecting to login',
            sessionTerminated ? '(session terminated by another device)' : ''
          );

          try {
            await onUnauthorized()
          } catch {}

          const current = router.currentRoute.value
          if (current.name !== 'login') {
            const query: any = { intended: current.fullPath }
            if (sessionTerminated) {
              query.message = 'session-terminated'
            }
            await router.replace({ name: 'login', query })
          }
        } else {
          console.log('[AXIOS] 401 on auth endpoint or /api/me, not redirecting');
        }
      }

      return Promise.reject(error)
    }
  )
}

export default api;
