import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import vuetify from 'vite-plugin-vuetify'

export default defineConfig({
  plugins: [
    vue(),
    vuetify({ autoImport: true }),
  ],
  server: {
    port: 5173,
    allowedHosts: ['localhost', 'rembro.ru', 'www.rembro.ru'],
    proxy: {
      '^/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
        secure: false,
        //cookieDomainRewrite: 'localhost',
        // НЕ переписываем путь - проксируем как есть
        // /api/login -> http://localhost:8000/api/login
      },
      '^/sanctum': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
        secure: false,
        //cookieDomainRewrite: 'localhost',
      },
      '^/docs': {
        target: 'http://127.0.0.1:5174',
        changeOrigin: true,
        secure: false,
        ws: true,
      },
    },
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks: {
          vendor: ['vue', 'vue-router', 'vuetify'],
        },
      },
    },
  },
})
