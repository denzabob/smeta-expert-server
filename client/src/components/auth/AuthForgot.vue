<template>
  <div>
    <v-alert
      v-if="successMessage"
      type="success"
      variant="tonal"
      closable
      class="mb-4"
      @click:close="successMessage = ''"
    >
      {{ successMessage }}
    </v-alert>

    <v-form ref="formRef" v-model="formValid" @submit.prevent="submit">
      <v-text-field
        ref="emailRef"
        v-model="form.email"
        label="E-mail"
        type="email"
        autocomplete="email"
        :rules="[rules.required, rules.email]"
      />

      <div class="d-flex justify-space-between align-center mt-2">
        <v-btn
          variant="text"
          size="small"
          class="text-none px-0"
          @click="emit('back')"
        >
          Назад ко входу
        </v-btn>

        <v-btn
          color="primary"
          class="text-none"
          :loading="loading"
          :disabled="!formValid"
          type="submit"
        >
          Отправить ссылку
        </v-btn>
      </div>
    </v-form>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, nextTick } from 'vue'
import api from '@/api/axios'

const emit = defineEmits<{ (e: 'back'): void }>()

const form = ref({
  email: '',
})

const formRef = ref<any>()
const formValid = ref(false)
const emailRef = ref<any>()

const loading = ref(false)
const successMessage = ref('')

const rules = {
  required: (v: string) => (!!v && v.trim() !== '') || 'Обязательное поле',
  email: (v: string) => /.+@.+\..+/.test(v) || 'Некорректный e-mail',
}

onMounted(async () => {
  await nextTick()
  emailRef.value?.focus?.()
})

const neutralSuccess = 'Если такой email существует, мы отправили на него инструкции по восстановлению пароля.'

const submit = async () => {
  const { valid } = (await formRef.value?.validate()) ?? { valid: false }
  if (!valid) return

  loading.value = true
  successMessage.value = ''

  try {
    // Реальный API, но UI всегда показывает успех (anti-enumeration)
    await api.post('/api/forgot-password', { email: form.value.email })
  } catch (e) {
    // Даже при ошибке сети показываем нейтральный успех
    console.warn('Forgot-password request failed:', e)
  } finally {
    loading.value = false
    successMessage.value = neutralSuccess
  }
}
</script>

<style scoped>
.text-none {
  text-transform: none;
}
</style>
