<template>
  <section class="section-panel section-panel--wide storage-settings">
    <div class="storage-settings__header">
      <div>
        <h3 class="section-title">Хранилище</h3>
        <p class="section-desc">Объём оригиналов файлов в проектах Эксперта.</p>
      </div>
      <v-btn
        icon="mdi-refresh"
        variant="text"
        size="small"
        :loading="loading"
        aria-label="Обновить использование хранилища"
        @click="loadStorage"
      />
    </div>

    <AppStateBlock
      v-if="loading && !storage"
      title="Загружаем хранилище"
      description="Получаем текущее использование файлов."
      loading
      density="compact"
    />
    <AppStateBlock
      v-else-if="error && !storage"
      title="Не удалось загрузить хранилище"
      :description="error"
      icon="mdi-alert-circle-outline"
      tone="error"
      density="compact"
    >
      <template #actions>
        <v-btn color="primary" variant="tonal" prepend-icon="mdi-refresh" @click="loadStorage">
          Повторить
        </v-btn>
      </template>
    </AppStateBlock>

    <template v-else-if="storage">
      <v-card class="storage-card" variant="flat">
        <div class="storage-card__summary">
          <div class="storage-card__icon" :class="`storage-card__icon--${tone}`">
            <v-icon icon="mdi-folder-multiple-outline" size="24" />
          </div>
          <div class="storage-card__main">
            <span class="storage-card__category">Файлы проектов</span>
            <strong>{{ usageLabel }}</strong>
          </div>
          <div class="storage-card__count">
            <strong>{{ storage.materials_count }}</strong>
            <span>{{ fileCountLabel }}</span>
          </div>
        </div>

        <template v-if="storage.limit_visible && storage.limit_available && !storage.is_unlimited">
          <v-progress-linear
            class="storage-card__progress"
            :model-value="progressValue"
            :color="progressColor"
            height="8"
            rounded
            aria-label="Использование лимита хранилища"
          />
          <div class="storage-card__details">
            <span>Свободно {{ formatStorageBytes(storage.remaining_bytes ?? 0) }}</span>
            <span>{{ percentLabel }}</span>
          </div>
        </template>
        <div v-else-if="storage.limit_visible && storage.limit_available" class="storage-card__unlimited">
          Лимит не установлен
        </div>
        <div v-else-if="!storage.limit_visible" class="storage-card__unlimited">
          Лимит тарифа не отображается в текущем режиме.
        </div>
        <v-alert v-else type="warning" variant="tonal" density="compact" class="mt-4">
          Сейчас не удалось определить лимит тарифа. Использование файлов доступно.
        </v-alert>
        <v-alert
          v-if="storage.is_over_limit"
          type="error"
          variant="tonal"
          density="compact"
          class="mt-4"
        >
          Использовано больше лимита. Существующие файлы остаются доступны; {{ storage.enforcement_enabled ? 'новые загрузки будут отклонены.' : 'в текущем режиме новые загрузки не ограничиваются.' }}
        </v-alert>
        <v-alert
          v-else-if="storage.limit_visible && storage.limit_available && storage.limit_bytes === 0"
          type="warning"
          variant="tonal"
          density="compact"
          class="mt-4"
        >
          На этом тарифе нельзя добавлять файлы в хранилище.
        </v-alert>
        <v-alert v-if="error" type="warning" variant="tonal" density="compact" class="mt-4">
          {{ error }}
        </v-alert>
      </v-card>

      <div class="storage-settings__footer">
        <p>Чтобы удалить или скачать файл, откройте библиотеку нужного проекта.</p>
        <v-btn
          color="primary"
          variant="tonal"
          prepend-icon="mdi-folder-search-outline"
          :to="{ name: 'expert-projects' }"
        >
          Открыть проекты Эксперта
        </v-btn>
      </div>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { getMyStorageUsage, type StorageUsageSnapshot } from '@/api/billing'
import { STORAGE_USAGE_CHANGED_EVENT } from '@/api/storageUsageEvents'
import AppStateBlock from '@/components/layout/AppStateBlock.vue'
import { formatStorageBytes, storageToneColor, storageUsageTone } from '@/modules/expert/storagePresentation'

const loading = ref(false)
const error = ref('')
const storage = ref<StorageUsageSnapshot | null>(null)

const tone = computed(() => storageUsageTone(storage.value?.usage_percent ?? null, storage.value?.is_over_limit ?? false))
const progressColor = computed(() => storageToneColor(tone.value))
const progressValue = computed(() => Math.min(100, Math.max(0, storage.value?.usage_percent ?? 0)))
const usageLabel = computed(() => {
  const current = storage.value
  if (!current) return ''
  const used = formatStorageBytes(current.used_bytes)
  if (!current.limit_visible) return `${used} использовано`
  if (!current.limit_available) return `${used} использовано`
  if (current.is_unlimited) return `${used} использовано`

  return `${used} из ${formatStorageBytes(current.limit_bytes ?? 0)} использовано`
})
const percentLabel = computed(() => storage.value?.usage_percent === null || storage.value?.usage_percent === undefined
  ? ''
  : `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(storage.value.usage_percent)}%`)
const fileCountLabel = computed(() => {
  const count = storage.value?.materials_count ?? 0
  const mod10 = count % 10
  const mod100 = count % 100
  const noun = mod10 === 1 && mod100 !== 11 ? 'файл' : mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20) ? 'файла' : 'файлов'
  return noun
})

async function loadStorage() {
  loading.value = true
  error.value = ''
  try {
    storage.value = await getMyStorageUsage()
  } catch (cause: unknown) {
    error.value = responseErrorMessage(cause)
  } finally {
    loading.value = false
  }
}

function responseErrorMessage(cause: unknown): string {
  if (cause && typeof cause === 'object') {
    const message = (cause as { response?: { data?: { message?: unknown } } }).response?.data?.message
    if (typeof message === 'string') return message
  }

  return 'Попробуйте обновить данные позже.'
}

function onStorageUsageChanged() {
  void loadStorage()
}

onMounted(() => {
  void loadStorage()
  window.addEventListener(STORAGE_USAGE_CHANGED_EVENT, onStorageUsageChanged)
})

onBeforeUnmount(() => window.removeEventListener(STORAGE_USAGE_CHANGED_EVENT, onStorageUsageChanged))
</script>

<style scoped>
.storage-settings__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.storage-card { padding: 20px; border: 1px solid rgba(var(--v-theme-outline-variant), .72); border-radius: var(--md-sys-shape-corner-extra-large); background: rgb(var(--v-theme-surface-container-lowest)); }
.storage-card__summary { display: flex; align-items: center; gap: 14px; }
.storage-card__icon { display: grid; width: 48px; height: 48px; flex: 0 0 auto; place-items: center; border-radius: var(--md-sys-shape-corner-large); color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .1); }
.storage-card__icon--warning { color: rgb(var(--v-theme-warning)); background: rgba(var(--v-theme-warning), .12); }
.storage-card__icon--critical, .storage-card__icon--over-limit { color: rgb(var(--v-theme-error)); background: rgba(var(--v-theme-error), .1); }
.storage-card__main { display: grid; min-width: 0; gap: 4px; }
.storage-card__category { color: rgb(var(--v-theme-on-surface-variant)); font-size: .82rem; }
.storage-card__main strong { color: rgb(var(--v-theme-on-surface)); font-size: 1.1rem; }
.storage-card__count { display: grid; flex: 0 0 auto; gap: 2px; margin-left: auto; text-align: right; }
.storage-card__count strong { color: rgb(var(--v-theme-on-surface)); font-size: 1rem; }
.storage-card__count span, .storage-card__details, .storage-card__unlimited { color: rgb(var(--v-theme-on-surface-variant)); font-size: .8rem; }
.storage-card__progress { margin-top: 20px; }
.storage-card__details { display: flex; justify-content: space-between; gap: 12px; margin-top: 8px; }
.storage-card__unlimited { margin-top: 18px; }
.storage-settings__footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-top: 18px; }
.storage-settings__footer p { margin: 0; color: rgb(var(--v-theme-on-surface-variant)); font-size: .84rem; }
@media (max-width: 520px) { .storage-card { padding: 16px; } .storage-card__summary { align-items: flex-start; } .storage-card__count { font-size: .85rem; } .storage-settings__footer :deep(.v-btn) { width: 100%; } }
</style>
