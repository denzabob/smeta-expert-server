import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import axiosInstance, { setupAxiosInterceptors } from './api/axios.ts'
import { useAuthStore } from '@/stores/auth'
import '@mdi/font/css/materialdesignicons.css'
import vuetify from '@/plugins/vuetify' // ← должен существовать

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)
app.use(vuetify) // ← ОБЯЗАТЕЛЬНО

const authStore = useAuthStore(pinia)
setupAxiosInterceptors({
	router,
	onUnauthorized: async () => {
		await authStore.logout({ skipApi: true })
	},
})

app.provide('axios', axiosInstance)
app.mount('#app')