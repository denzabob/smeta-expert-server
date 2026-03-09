<template>
  <Teleport to="body">
    <Transition name="dialog">
      <div v-if="modelValue" class="dialog-overlay" @click.self="requestClose">
        <div class="dialog-container">
          <div class="dialog-content">
            <!-- Header -->
            <div class="dialog-header">
              <h2 class="dialog-title">Настройки аккаунта</h2>
              <button class="close-btn" @click="requestClose">×</button>
            </div>

            <div class="dialog-body" :class="{ 'dialog-body--direct': directEntryMode }">
              <!-- Left navigation -->
              <nav v-if="!directEntryMode" class="settings-nav">
                <template v-for="section in sections" :key="section.id">
                  <div v-if="section.dividerBefore" class="nav-divider"></div>
                  <button
                    class="nav-btn"
                    :class="{ 'nav-btn--active': activeSection === section.id }"
                    @click="activeSection = section.id"
                  >
                    {{ section.title }}
                  </button>
                </template>
              </nav>

              <!-- Right content -->
              <div class="settings-content">
                <!-- Profile -->
                <div v-if="activeSection === 'profile'" class="section-panel">
                  <h3 class="section-title">Профиль</h3>
                  <p class="section-desc">Основная информация о вашем аккаунте</p>

                  <form @submit.prevent="saveProfile" class="settings-form">
                    <div class="form-group">
                      <label class="form-label">Email</label>
                      <input
                        type="email"
                        class="form-input form-input--readonly"
                        :value="user?.email"
                        readonly
                        disabled
                      />
                      <span class="form-hint">Email изменить нельзя</span>
                    </div>

                    <div class="form-group">
                      <label class="form-label">Имя</label>
                      <input
                        v-model="profileForm.name"
                        type="text"
                        class="form-input"
                        placeholder="Ваше имя"
                      />
                    </div>

                    <div class="form-actions">
                      <button
                        type="submit"
                        class="btn btn--primary"
                        :disabled="profileSaving"
                      >
                        {{ profileSaving ? 'Сохранение...' : 'Сохранить' }}
                      </button>
                    </div>

                    <div v-if="profileMessage" class="form-message" :class="profileMessageClass">
                      {{ profileMessage }}
                    </div>
                  </form>
                </div>

                <!-- Security -->
                <div v-if="activeSection === 'security'" class="section-panel section-panel--wide">
                  <UserSecurityPanel />
                </div>

                <!-- Preferences -->
                <div v-if="activeSection === 'preferences'" class="section-panel">
                  <h3 class="section-title">Предпочтения</h3>
                  <p class="section-desc">Настройки интерфейса и поведения приложения</p>

                  <div class="settings-form">
                    <div class="form-group">
                      <label class="form-label">Тема оформления</label>
                      <div class="radio-group">
                        <label class="radio-item">
                          <input
                            type="radio"
                            v-model="preferencesForm.theme"
                            value="light"
                          />
                          <span>Светлая</span>
                        </label>
                        <label class="radio-item">
                          <input
                            type="radio"
                            v-model="preferencesForm.theme"
                            value="dark"
                          />
                          <span>Тёмная</span>
                        </label>
                        <label class="radio-item">
                          <input
                            type="radio"
                            v-model="preferencesForm.theme"
                            value="auto"
                          />
                          <span>Автоматическая</span>
                        </label>
                      </div>
                    </div>

                    <div class="form-actions">
                      <button
                        type="button"
                        class="btn btn--primary"
                        @click="savePreferences"
                      >
                        Сохранить
                      </button>
                    </div>

                    <div v-if="preferencesMessage" class="form-message form-message--success">
                      {{ preferencesMessage }}
                    </div>
                  </div>
                </div>

                <!-- Data -->
                <div v-if="activeSection === 'data'" class="section-panel">
                  <h3 class="section-title">Данные</h3>
                  <p class="section-desc">Экспорт и управление данными аккаунта</p>

                  <div class="settings-form">
                    <div class="data-section">
                      <h4 class="data-title">Экспорт данных</h4>
                      <p class="data-desc">
                        Вы можете экспортировать все ваши проекты и настройки.
                      </p>
                      <button class="btn btn--secondary" disabled>
                        Экспорт (скоро)
                      </button>
                    </div>

                    <div class="data-section data-section--danger">
                      <h4 class="data-title">Удаление аккаунта</h4>
                      <p class="data-desc">
                        Удаление аккаунта приведёт к безвозвратной потере всех данных.
                      </p>
                      <button class="btn btn--danger" disabled>
                        Удалить аккаунт (скоро)
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Подтверждение закрытия -->
          <Transition name="confirm">
            <div v-if="showCloseConfirm" class="confirm-overlay">
              <div class="confirm-dialog">
                <h3 class="confirm-title">Несохранённые изменения</h3>
                <p class="confirm-text">У вас есть несохранённые изменения. Закрыть без сохранения?</p>
                <div class="confirm-actions">
                  <button class="btn btn--secondary" @click="cancelClose">Отмена</button>
                  <button class="btn btn--danger" @click="confirmClose">Закрыть</button>
                </div>
              </div>
            </div>
          </Transition>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import api from '@/api/axios'
import UserSecurityPanel from '@/components/settings/UserSecurityPanel.vue'

interface Section {
  id: string
  title: string
  dividerBefore?: boolean
}

const props = defineProps<{
  modelValue: boolean
  initialTab?: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
}>()

const authStore = useAuthStore()
const user = computed(() => authStore.user)

const sections: Section[] = [
  { id: 'profile', title: 'Профиль' },
  { id: 'security', title: 'Безопасность' },
  { id: 'preferences', title: 'Предпочтения' },
  { id: 'data', title: 'Данные', dividerBefore: true },
]

const activeSection = ref('profile')
const directEntryTabs = new Set(['profile', 'security', 'preferences'])
const directEntryMode = computed(() => !!props.initialTab && directEntryTabs.has(props.initialTab))

// Profile form
const profileForm = ref({
  name: '',
})
const profileSaving = ref(false)
const profileMessage = ref('')
const profileMessageClass = ref('')

// Password form (moved to UserSecurityPanel, kept for unsaved changes check)
const passwordForm = ref({
  current: '',
  new: '',
  confirm: '',
})

// Preferences form
const savedTheme = localStorage.getItem('app-theme-mode') as 'light' | 'dark' | 'auto' | null
const preferencesForm = ref({
  theme: savedTheme || 'auto',
})
const preferencesMessage = ref('')

// === Отслеживание несохранённых изменений ===
const initialProfileName = ref('')
const initialTheme = ref(savedTheme || 'auto')

const hasUnsavedChanges = computed(() => {
  // Проверяем изменения в профиле
  const profileChanged = profileForm.value.name !== initialProfileName.value
  
  // Проверяем изменения в форме пароля (любое поле заполнено)
  const passwordChanged = passwordForm.value.current !== '' || 
                          passwordForm.value.new !== '' || 
                          passwordForm.value.confirm !== ''
  
  // Проверяем изменения в предпочтениях
  const preferencesChanged = preferencesForm.value.theme !== initialTheme.value
  
  return profileChanged || passwordChanged || preferencesChanged
})

// Показ подтверждения закрытия
const showCloseConfirm = ref(false)

// Initialize form when dialog opens
watch(() => props.modelValue, (open) => {
  if (open) {
    const name = user.value?.name || ''
    profileForm.value.name = name
    initialProfileName.value = name
    
    const theme = localStorage.getItem('app-theme-mode') as 'light' | 'dark' | 'auto' || 'auto'
    preferencesForm.value.theme = theme
    initialTheme.value = theme
    
    profileMessage.value = ''
    preferencesMessage.value = ''
    passwordForm.value = { current: '', new: '', confirm: '' }
    showCloseConfirm.value = false
    
    if (props.initialTab && sections.find(s => s.id === props.initialTab)) {
      activeSection.value = props.initialTab
    }
  }
})

function requestClose() {
  if (hasUnsavedChanges.value) {
    showCloseConfirm.value = true
  } else {
    close()
  }
}

function confirmClose() {
  showCloseConfirm.value = false
  close()
}

function cancelClose() {
  showCloseConfirm.value = false
}

function close() {
  emit('update:modelValue', false)
}

async function saveProfile() {
  profileSaving.value = true
  profileMessage.value = ''

  try {
    await api.put('/api/me', {
      name: profileForm.value.name,
    })
    
    // Update store
    if (authStore.user) {
      authStore.user.name = profileForm.value.name
    }
    
    // Update initial value
    initialProfileName.value = profileForm.value.name
    
    profileMessage.value = 'Профиль сохранён'
    profileMessageClass.value = 'form-message--success'
  } catch (error: any) {
    profileMessage.value = error.response?.data?.message || 'Ошибка сохранения'
    profileMessageClass.value = 'form-message--error'
  } finally {
    profileSaving.value = false
  }
}

function savePreferences() {
  localStorage.setItem('app-theme-mode', preferencesForm.value.theme)
  window.dispatchEvent(new CustomEvent('theme-mode-change', { detail: preferencesForm.value.theme }))
  
  // Update initial value
  initialTheme.value = preferencesForm.value.theme
  
  preferencesMessage.value = 'Настройки сохранены'
  setTimeout(() => {
    preferencesMessage.value = ''
  }, 2000)
}
</script>

<style scoped>
.dialog-overlay {
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(var(--v-theme-on-surface), 0.42);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
}

.dialog-container {
  width: 100%;
  max-width: 800px;
  max-height: 90vh;
  margin: 16px;
}

.dialog-content {
  background: rgb(var(--v-theme-surface));
  border-radius: 16px;
  box-shadow: 0 12px 32px rgba(5, 10, 20, 0.35);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  max-height: 90vh;
}

.dialog-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 24px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.12);
}

.dialog-title {
  font-size: 18px;
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
  margin: 0;
}

.close-btn {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  color: rgb(var(--v-theme-on-surface-variant));
  background: transparent;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.close-btn:hover {
  background: rgba(var(--v-theme-on-surface), 0.1);
  color: rgb(var(--v-theme-on-surface));
}

.dialog-body {
  display: flex;
  flex: 1;
  overflow: hidden;
}

.settings-nav {
  width: 180px;
  flex-shrink: 0;
  padding: 16px 12px;
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  background: rgb(var(--v-theme-surface-bright));
}

.nav-btn {
  display: block;
  width: 100%;
  padding: 10px 12px;
  margin-bottom: 4px;
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface-variant));
  background: transparent;
  border: none;
  border-radius: 8px;
  text-align: left;
  cursor: pointer;
  transition: all 0.15s ease;
}

.nav-btn:hover {
  background: rgba(var(--v-theme-on-surface), 0.1);
}

.nav-btn--active {
  background: rgba(var(--v-theme-primary), 0.2);
  color: rgb(var(--v-theme-on-surface));
  font-weight: 500;
}

.nav-divider {
  height: 1px;
  background: rgba(var(--v-theme-on-surface), 0.12);
  margin: 8px 0;
}

.settings-content {
  flex: 1;
  padding: 24px;
  overflow-y: auto;
}

.section-panel {
  max-width: 480px;
}

.section-panel--wide {
  max-width: 520px;
}

.section-title {
  font-size: 16px;
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
  margin: 0 0 4px;
}

.section-desc {
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface-variant));
  margin: 0 0 24px;
}

.settings-form {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.form-label {
  font-size: 13px;
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface));
}

.form-input {
  padding: 10px 12px;
  font-size: 14px;
  color: rgb(var(--v-theme-on-surface));
  background: rgba(var(--v-theme-on-surface), 0.05);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.2);
  border-radius: 12px;
  transition: border-color 0.15s ease;
}

.form-input:focus {
  outline: none;
  border-color: rgba(var(--v-theme-primary), 0.7);
}

.form-input--readonly {
  background: rgba(var(--v-theme-on-surface), 0.08);
  color: rgb(var(--v-theme-on-surface-variant));
}

.form-hint {
  font-size: 12px;
  color: rgb(var(--v-theme-on-surface-variant));
}

.form-actions {
  padding-top: 8px;
}

.btn {
  padding: 10px 20px;
  font-size: 13px;
  font-weight: 500;
  border: none;
  border-radius: 12px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn--primary {
  color: rgb(var(--v-theme-on-primary));
  background: rgb(var(--v-theme-primary));
}

.btn--primary:hover:not(:disabled) {
  background: rgba(var(--v-theme-primary), 0.85);
}

.btn--secondary {
  color: rgb(var(--v-theme-on-surface));
  background: rgba(var(--v-theme-on-surface), 0.12);
}

.btn--secondary:hover:not(:disabled) {
  background: rgba(var(--v-theme-on-surface), 0.2);
}

.btn--danger {
  color: rgb(var(--v-theme-on-error));
  background: rgb(var(--v-theme-error));
}

.btn--danger:hover:not(:disabled) {
  background: rgba(var(--v-theme-error), 0.85);
}

.form-message {
  padding: 10px 12px;
  font-size: 13px;
  border-radius: 4px;
}

.form-message--success {
  color: rgb(var(--v-theme-success));
  background: rgba(var(--v-theme-success), 0.14);
}

.form-message--error {
  color: rgb(var(--v-theme-error));
  background: rgba(var(--v-theme-error), 0.14);
}

.radio-group {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.radio-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
}

.radio-item input {
  width: 16px;
  height: 16px;
}

.data-section {
  padding: 16px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 12px;
}

.data-section--danger {
  border-color: rgba(var(--v-theme-error), 0.35);
  background: rgba(var(--v-theme-error), 0.08);
}

.data-title {
  font-size: 14px;
  font-weight: 500;
  color: rgb(var(--v-theme-on-surface));
  margin: 0 0 8px;
}

.data-desc {
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface-variant));
  margin: 0 0 12px;
}

/* Transition */
.dialog-enter-active,
.dialog-leave-active {
  transition: opacity 0.2s ease;
}

.dialog-enter-active .dialog-content,
.dialog-leave-active .dialog-content {
  transition: transform 0.2s ease;
}

.dialog-enter-from,
.dialog-leave-to {
  opacity: 0;
}

.dialog-enter-from .dialog-content,
.dialog-leave-to .dialog-content {
  transform: scale(0.95);
}

/* Confirm dialog */
.confirm-overlay {
  position: absolute;
  inset: 0;
  background: rgba(var(--v-theme-on-surface), 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 16px;
}

.confirm-dialog {
  background: rgb(var(--v-theme-surface-bright));
  border-radius: 16px;
  padding: 24px;
  max-width: 320px;
  box-shadow: 0 8px 24px rgba(5, 10, 20, 0.35);
}

.confirm-title {
  font-size: 16px;
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
  margin: 0 0 8px;
}

.confirm-text {
  font-size: 14px;
  color: rgb(var(--v-theme-on-surface-variant));
  margin: 0 0 20px;
  line-height: 1.5;
}

.confirm-actions {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
}

.confirm-enter-active,
.confirm-leave-active {
  transition: opacity 0.15s ease;
}

.confirm-enter-from,
.confirm-leave-to {
  opacity: 0;
}

/* Mobile */
@media (max-width: 600px) {
  .dialog-container {
    margin: 8px;
    max-height: calc(100vh - 16px);
  }

  .dialog-body {
    flex-direction: column;
  }

  .settings-nav {
    width: 100%;
    flex-direction: row;
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    padding: 12px;
    border-right: none;
    border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  }

  .nav-btn {
    flex: 0 0 auto;
    width: auto;
    margin-bottom: 0;
  }

  .settings-content {
    padding: 16px;
  }
}
</style>
