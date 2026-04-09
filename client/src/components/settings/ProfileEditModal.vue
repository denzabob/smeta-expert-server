<template>
  <v-dialog
    v-model="open"
    max-width="420"
    :fullscreen="mobile"
    :transition="mobile ? 'dialog-bottom-transition' : 'dialog-transition'"
    @keydown.esc="open = false"
  >
    <v-card class="pem-card" :rounded="mobile ? '0' : 'xl'">
      <!-- Header -->
      <div class="pem-header">
        <div class="pem-avatar">{{ userInitial }}</div>
        <div class="pem-header-text">
          <div class="pem-title">Профиль</div>
          <div class="pem-email">{{ userEmail }}</div>
        </div>
        <button class="pem-close" @click="open = false">
          <v-icon icon="mdi-close" size="20" />
        </button>
      </div>

      <v-divider />

      <!-- Body -->
      <div class="pem-body">
        <div class="pem-field-group">
          <label class="pem-label">Имя</label>
          <input
            v-model="nameInput"
            class="pem-input"
            type="text"
            placeholder="Ваше имя"
            :disabled="saving"
            @keydown.enter="save"
          />
        </div>

        <div class="pem-field-group">
          <label class="pem-label">Email</label>
          <input
            class="pem-input pem-input--readonly"
            type="email"
            :value="userEmail"
            readonly
            disabled
          />
          <span class="pem-hint">Email изменить нельзя</span>
        </div>

        <div v-if="message" class="pem-message" :class="messageClass">
          {{ message }}
        </div>
      </div>

      <v-divider />

      <!-- Footer -->
      <div class="pem-footer">
        <button class="pem-btn pem-btn--ghost" @click="open = false" :disabled="saving">
          Отмена
        </button>
        <button
          class="pem-btn pem-btn--primary"
          :disabled="saving || !isDirty"
          @click="save"
        >
          {{ saving ? 'Сохранение…' : 'Сохранить' }}
        </button>
      </div>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useDisplay } from 'vuetify'
import { useAuthStore } from '@/stores/auth'
import api from '@/api/axios'

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ (e: 'update:modelValue', v: boolean): void }>()

const { smAndDown } = useDisplay()
const mobile = computed(() => smAndDown.value)
const authStore = useAuthStore()

const open = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const userEmail = computed(() => authStore.user?.email ?? '')
const userName = computed(() => authStore.user?.name ?? '')
const userInitial = computed(() => {
  const n = userName.value || userEmail.value
  return n.charAt(0).toUpperCase()
})

const nameInput = ref('')
const saving = ref(false)
const message = ref('')
const messageClass = ref('')

const isDirty = computed(() => nameInput.value.trim() !== (userName.value ?? ''))

// Reset on open
watch(open, (opened) => {
  if (opened) {
    nameInput.value = userName.value
    message.value = ''
    messageClass.value = ''
  }
})

async function save() {
  if (!isDirty.value || saving.value) return
  saving.value = true
  message.value = ''
  try {
    await api.put('/api/me', { name: nameInput.value.trim() })
    if (authStore.user) authStore.user.name = nameInput.value.trim()
    message.value = 'Имя сохранено'
    messageClass.value = 'pem-message--success'
    setTimeout(() => { open.value = false }, 700)
  } catch (e: any) {
    message.value = e.response?.data?.message || 'Ошибка сохранения'
    messageClass.value = 'pem-message--error'
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
/* === Profile Edit Modal === */
.pem-card {
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.pem-header {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 20px 20px 16px;
}

.pem-avatar {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  background: rgba(var(--v-theme-primary), 0.15);
  color: rgb(var(--v-theme-primary));
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 18px;
  flex-shrink: 0;
}

.pem-header-text {
  flex: 1;
  min-width: 0;
}

.pem-title {
  font-size: 16px;
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
  line-height: 1.3;
}

.pem-email {
  font-size: 13px;
  color: rgba(var(--v-theme-on-surface), 0.6);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pem-close {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: rgba(var(--v-theme-on-surface), 0.5);
  cursor: pointer;
  transition: all 0.15s;
  flex-shrink: 0;
}

.pem-close:hover {
  background: rgba(var(--v-theme-on-surface), 0.08);
  color: rgb(var(--v-theme-on-surface));
}

.pem-body {
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  flex: 1;
}

.pem-field-group {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.pem-label {
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgba(var(--v-theme-on-surface), 0.55);
}

.pem-input {
  padding: 10px 12px;
  font-size: 14px;
  color: rgb(var(--v-theme-on-surface));
  background: rgba(var(--v-theme-on-surface), 0.05);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.18);
  border-radius: 10px;
  transition: border-color 0.15s;
  width: 100%;
}

.pem-input:focus {
  outline: none;
  border-color: rgba(var(--v-theme-primary), 0.7);
  background: rgba(var(--v-theme-on-surface), 0.04);
}

.pem-input--readonly {
  background: rgba(var(--v-theme-on-surface), 0.06);
  color: rgba(var(--v-theme-on-surface), 0.5);
  cursor: not-allowed;
}

.pem-hint {
  font-size: 11px;
  color: rgba(var(--v-theme-on-surface), 0.45);
}

.pem-message {
  padding: 8px 12px;
  font-size: 13px;
  border-radius: 8px;
}

.pem-message--success {
  color: rgb(var(--v-theme-success));
  background: rgba(var(--v-theme-success), 0.12);
}

.pem-message--error {
  color: rgb(var(--v-theme-error));
  background: rgba(var(--v-theme-error), 0.12);
}

.pem-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 14px 20px;
}

.pem-btn {
  padding: 9px 18px;
  font-size: 13px;
  font-weight: 500;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.15s;
}

.pem-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.pem-btn--ghost {
  background: rgba(var(--v-theme-on-surface), 0.08);
  color: rgb(var(--v-theme-on-surface));
}

.pem-btn--ghost:hover:not(:disabled) {
  background: rgba(var(--v-theme-on-surface), 0.14);
}

.pem-btn--primary {
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
}

.pem-btn--primary:hover:not(:disabled) {
  background: rgba(var(--v-theme-primary), 0.85);
}
</style>
