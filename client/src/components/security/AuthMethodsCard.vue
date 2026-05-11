<template>
  <v-card variant="outlined" class="mb-4">
    <v-card-text class="pb-2">
      <div class="text-subtitle-2 font-weight-medium mb-1">Способы входа</div>
      <div class="text-caption text-medium-emphasis mb-4">Как вы входите в аккаунт и восстанавливаете доступ</div>

      <div class="method-list">
        <!-- ── Phone ─────────────────────────────────────────────────── -->
        <div class="method-row">
          <div class="method-icon">
            <v-icon size="20" :color="status.phone.linked ? 'primary' : 'medium-emphasis'">mdi-cellphone</v-icon>
          </div>
          <div class="method-body">
            <div class="d-flex align-center gap-2 flex-wrap">
              <span class="text-body-2 font-weight-medium">Телефон</span>
              <v-chip
                v-if="status.phone.linked && status.phone.verified"
                size="x-small"
                color="success"
                variant="tonal"
              >Подтверждён</v-chip>
              <v-chip
                v-else-if="status.phone.linked && !status.phone.verified"
                size="x-small"
                color="warning"
                variant="tonal"
              >Не подтверждён</v-chip>
              <v-chip v-else size="x-small" variant="tonal">Не добавлен</v-chip>
            </div>
            <div class="text-caption text-medium-emphasis mt-0-5">
              <span v-if="status.phone.linked">{{ status.phone.masked }}</span>
              <span v-else>Для входа по SMS-коду и быстрого PIN</span>
            </div>
          </div>
          <div class="method-action">
            <v-btn
              v-if="!status.phone.linked"
              size="small"
              variant="outlined"
              @click="$emit('addPhone')"
            >Добавить</v-btn>
            <v-btn
              v-else-if="!status.phone.verified"
              size="small"
              variant="outlined"
              color="warning"
              @click="$emit('verifyPhone')"
            >Подтвердить</v-btn>
            <v-btn
              v-else
              size="small"
              variant="text"
              @click="$emit('changePhone')"
            >Изменить</v-btn>
          </div>
        </div>

        <v-divider class="my-2" />

        <!-- ── Email ─────────────────────────────────────────────────── -->
        <div class="method-row">
          <div class="method-icon">
            <v-icon size="20" :color="status.email.linked ? 'primary' : 'medium-emphasis'">mdi-email-outline</v-icon>
          </div>
          <div class="method-body">
            <div class="d-flex align-center gap-2 flex-wrap">
              <span class="text-body-2 font-weight-medium">Почта</span>
              <v-chip
                v-if="status.email.linked && status.email.verified"
                size="x-small"
                color="success"
                variant="tonal"
              >Подтверждена</v-chip>
              <v-chip
                v-else-if="status.email.linked && !status.email.verified"
                size="x-small"
                color="warning"
                variant="tonal"
              >Не подтверждена</v-chip>
              <v-chip v-else size="x-small" variant="tonal">Не добавлена</v-chip>
            </div>
            <div class="text-caption text-medium-emphasis mt-0-5">
              <span v-if="status.email.linked">{{ status.email.masked }}</span>
              <span v-else>Для уведомлений и сброса пароля</span>
            </div>
          </div>
          <div class="method-action">
            <v-btn
              v-if="!status.email.linked"
              size="small"
              variant="outlined"
              @click="$emit('addEmail')"
            >Добавить</v-btn>
            <v-btn
              v-else-if="!status.email.verified"
              size="small"
              variant="outlined"
              color="warning"
              @click="$emit('resendEmailVerification')"
            >Отправить письмо</v-btn>
            <v-btn
              v-else
              size="small"
              variant="text"
              @click="$emit('changeEmail')"
            >Изменить</v-btn>
          </div>
        </div>

        <v-divider class="my-2" />

        <!-- ── Password ──────────────────────────────────────────────── -->
        <div class="method-row">
          <div class="method-icon">
            <v-icon size="20" :color="status.password.set ? 'primary' : 'medium-emphasis'">mdi-key-outline</v-icon>
          </div>
          <div class="method-body">
            <div class="d-flex align-center gap-2 flex-wrap">
              <span class="text-body-2 font-weight-medium">Пароль</span>
              <v-chip
                v-if="status.password.set"
                size="x-small"
                color="success"
                variant="tonal"
              >Установлен</v-chip>
              <v-chip v-else size="x-small" color="error" variant="tonal">Не установлен</v-chip>
            </div>
            <div class="text-caption text-medium-emphasis mt-0-5">
              <span v-if="status.password.set">Вход с любого браузера без SMS</span>
              <span v-else-if="isPasswordBlocked">Сначала {{ prerequisiteLabel('set_password') }}</span>
              <span v-else>Для входа и восстановления с любого устройства</span>
            </div>
          </div>
          <div class="method-action">
            <v-btn
              v-if="!status.password.set && !isPasswordBlocked"
              size="small"
              variant="outlined"
              color="primary"
              @click="$emit('setPassword')"
            >Установить</v-btn>
            <v-btn
              v-else-if="!status.password.set && isPasswordBlocked"
              size="small"
              variant="outlined"
              color="warning"
              @click="$emit('action', prerequisiteAction('set_password'))"
            >
              {{ prerequisiteButtonLabel('set_password') }}
            </v-btn>
            <v-btn
              v-else
              size="small"
              variant="text"
              @click="$emit('changePassword')"
            >Изменить</v-btn>
          </div>
        </div>

        <v-divider class="my-2" />

        <!-- ── Yandex ─────────────────────────────────────────────────── -->
        <div class="method-row">
          <div class="method-icon">
            <v-icon
              size="20"
              :color="status.yandex.linked ? '#FC3F1D' : 'medium-emphasis'"
            >mdi-alpha-y-circle-outline</v-icon>
          </div>
          <div class="method-body">
            <div class="d-flex align-center gap-2 flex-wrap">
              <span class="text-body-2 font-weight-medium">Яндекс</span>
              <v-chip
                v-if="status.yandex.linked"
                size="x-small"
                color="success"
                variant="tonal"
              >Подключён</v-chip>
              <v-chip v-else size="x-small" variant="tonal">Не подключён</v-chip>
            </div>
            <div class="text-caption text-medium-emphasis mt-0-5">
              <span v-if="status.yandex.linked">Для входа через аккаунт Яндекс</span>
              <span v-else>Быстрый вход через Яндекс</span>
            </div>
          </div>
          <div class="method-action">
            <v-btn
              v-if="status.yandex.linked"
              size="small"
              variant="text"
              @click="$emit('unlinkYandex')"
            >Отвязать</v-btn>
            <v-btn
              v-else
              size="small"
              variant="outlined"
              @click="$emit('linkYandex')"
            >Подключить</v-btn>
          </div>
        </div>

        <v-divider class="my-2" />

        <!-- ── VK ID ──────────────────────────────────────────────────── -->
        <div class="method-row">
          <div class="method-icon">
            <v-icon
              size="20"
              :color="vkLinked ? '#0077FF' : 'medium-emphasis'"
            >mdi-alpha-v-circle-outline</v-icon>
          </div>
          <div class="method-body">
            <div class="d-flex align-center gap-2 flex-wrap">
              <span class="text-body-2 font-weight-medium">VK ID</span>
              <v-chip
                v-if="vkLinked"
                size="x-small"
                color="success"
                variant="tonal"
              >Подключён</v-chip>
              <v-chip
                v-else-if="!vkConfigured"
                size="x-small"
                color="warning"
                variant="tonal"
              >Недоступен</v-chip>
              <v-chip v-else size="x-small" variant="tonal">Не подключён</v-chip>
            </div>
            <div class="text-caption text-medium-emphasis mt-0-5">
              <span v-if="vkLinked">Для входа через аккаунт VK ID</span>
              <span v-else-if="!vkConfigured">Провайдер не настроен на сервере</span>
              <span v-else>Быстрый вход через VK ID</span>
            </div>
          </div>
          <div class="method-action">
            <v-btn
              v-if="vkLinked"
              size="small"
              variant="text"
              @click="$emit('unlinkProvider', 'vk')"
            >Отвязать</v-btn>
            <v-btn
              v-else
              size="small"
              variant="outlined"
              :disabled="!vkConfigured"
              @click="$emit('linkProvider', 'vk')"
            >Подключить</v-btn>
          </div>
        </div>

        <v-divider class="my-2" />

        <!-- ── Quick PIN ───────────────────────────────────────────────── -->
        <div class="method-row">
          <div class="method-icon">
            <v-icon size="20" :color="status.quick_pin.enabled ? 'primary' : 'medium-emphasis'">mdi-dialpad</v-icon>
          </div>
          <div class="method-body">
            <div class="d-flex align-center gap-2 flex-wrap">
              <span class="text-body-2 font-weight-medium">Быстрый PIN</span>
              <v-chip
                v-if="status.quick_pin.enabled"
                size="x-small"
                color="success"
                variant="tonal"
              >Включён</v-chip>
              <v-chip v-else size="x-small" variant="tonal">Выключен</v-chip>
            </div>
            <div class="text-caption text-medium-emphasis mt-0-5">
              <span v-if="status.quick_pin.enabled">
                4-значный код на этом устройстве, без SMS
              </span>
              <span v-else-if="isPinBlocked">
                Сначала {{ prerequisiteLabel('enable_quick_pin') }}
              </span>
              <span v-else>Вход 4-значным кодом — быстрее пароля и SMS</span>
            </div>
          </div>
          <div class="method-action">
            <v-btn
              v-if="!status.quick_pin.enabled && !isPinBlocked"
              size="small"
              variant="outlined"
              @click="$emit('enablePin')"
            >Включить</v-btn>
            <v-btn
              v-else-if="!status.quick_pin.enabled && isPinBlocked"
              size="small"
              variant="outlined"
              color="warning"
              @click="$emit('action', prerequisiteAction('enable_quick_pin'))"
            >
              {{ prerequisiteButtonLabel('enable_quick_pin') }}
            </v-btn>
            <v-btn
              v-else
              size="small"
              variant="text"
              color="error"
              @click="$emit('disablePin')"
            >Отключить</v-btn>
          </div>
        </div>
      </div>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { AuthMethodProfile } from '@/api/security'
import { blockedActionPrerequisiteLabel, recommendedActionMeta } from './securityHelpers'

const props = defineProps<{
  status: AuthMethodProfile
}>()

defineEmits<{
  (e: 'addPhone'): void
  (e: 'verifyPhone'): void
  (e: 'changePhone'): void
  (e: 'addEmail'): void
  (e: 'resendEmailVerification'): void
  (e: 'changeEmail'): void
  (e: 'setPassword'): void
  (e: 'changePassword'): void
  (e: 'linkYandex'): void
  (e: 'unlinkYandex'): void
  (e: 'linkProvider', provider: string): void
  (e: 'unlinkProvider', provider: string): void
  (e: 'enablePin'): void
  (e: 'disablePin'): void
  (e: 'action', action: string): void
}>()

const isPasswordBlocked = computed(() => props.status.blocked_actions.includes('set_password'))
const isPinBlocked = computed(() => props.status.blocked_actions.includes('enable_quick_pin'))
const vkLinked = computed(() => props.status.vk?.linked === true)
const vkConfigured = computed(() => props.status.vk?.configured !== false)

function prerequisiteAction(blockedAction: string): string {
  return props.status.prerequisite_actions[blockedAction] ?? ''
}

function prerequisiteButtonLabel(blockedAction: string): string {
  const prereq = prerequisiteAction(blockedAction)
  if (!prereq) return 'Выполнить условие'
  return recommendedActionMeta(prereq).buttonLabel
}

function prerequisiteLabel(action: string): string {
  return blockedActionPrerequisiteLabel(action, props.status.prerequisite_actions)
}
</script>

<style scoped>
.method-list {
  display: flex;
  flex-direction: column;
}

.method-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 8px 0;
}

.method-icon {
  flex-shrink: 0;
  padding-top: 2px;
  width: 24px;
  text-align: center;
}

.method-body {
  flex: 1;
  min-width: 0;
}

.method-action {
  flex-shrink: 0;
  display: flex;
  align-items: center;
}

.mt-0-5 {
  margin-top: 2px;
}
</style>
