<template>
  <v-alert
    v-if="showWarning"
    type="warning"
    variant="tonal"
    closable
    class="mb-4"
  >
    <template #title>
      Подключение не из России
    </template>
    <div class="text-body2">
      <p class="mb-2">
        Вы подключились к системе не из России. Это может привести к:
      </p>
      <ul class="pl-4 mb-2">
        <li>Замедлению работы приложения</li>
        <li>Увеличению времени отклика API</li>
        <li>Потенциальным проблемам с доступом к определённым функциям</li>
      </ul>
      <p class="mb-0 text-muted">
        Рекомендуем использовать VPN или другое подключение с IP из России для оптимальной работы.
      </p>
    </div>
  </v-alert>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const showWarning = ref(false)

// Показываем уведомление если IP не из РФ
watch(
  () => authStore.isRussiaIp,
  (newVal) => {
    showWarning.value = !newVal
  },
  { immediate: true }
)

// Закрыть уведомление
const closeWarning = () => {
  showWarning.value = false
}
</script>

<style scoped>
ul {
  list-style-type: disc;
}

li {
  margin-bottom: 0.5rem;
}

.text-muted {
  opacity: 0.7;
}
</style>
