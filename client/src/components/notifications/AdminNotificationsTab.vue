<template>
  <div>
    <!-- Toolbar -->
    <v-card class="mb-4">
      <v-card-text>
        <v-row align="center" dense>
          <v-col cols="12" sm="4">
            <v-text-field
              v-model="search"
              prepend-inner-icon="mdi-magnify"
              label="Поиск уведомлений"
              hide-details
              density="compact"
              clearable
              @update:model-value="debouncedLoad"
            />
          </v-col>
          <v-col cols="6" sm="3">
            <v-select
              v-model="statusFilter"
              :items="statusOptions"
              label="Статус"
              hide-details
              density="compact"
              clearable
              @update:model-value="loadList"
            />
          </v-col>
          <v-col cols="6" sm="3">
            <v-select
              v-model="audienceFilter"
              :items="audienceOptions"
              label="Аудитория"
              hide-details
              density="compact"
              clearable
              @update:model-value="loadList"
            />
          </v-col>
          <v-col cols="12" sm="2" class="text-right">
            <v-btn color="primary" prepend-icon="mdi-plus" @click="openForm()">
              Создать
            </v-btn>
          </v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <!-- List table -->
    <v-card :loading="loading">
      <v-data-table-server
        :headers="headers"
        :items="items"
        :items-length="total"
        :loading="loading"
        :page="page"
        :items-per-page="perPage"
        @update:page="page = $event; loadList()"
        @update:items-per-page="perPage = $event; loadList()"
        density="compact"
        no-data-text="Нет уведомлений"
      >
        <template #item.status="{ item }">
          <v-chip :color="statusColor(item.status)" size="small" variant="tonal">
            {{ statusLabel(item.status) }}
          </v-chip>
        </template>

        <template #item.audience_type="{ item }">
          {{ audienceLabel(item.audience_type) }}
        </template>

        <template #item.stats="{ item }">
          <span v-if="item.stats" class="text-caption">
            {{ item.stats.delivered }} / {{ item.stats.read }} / {{ item.stats.clicked }}
          </span>
          <span v-else class="text-caption text-grey">—</span>
        </template>

        <template #item.created_at="{ item }">
          {{ formatDate(item.created_at) }}
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex ga-1">
            <v-btn
              v-if="item.status === 'draft' || item.status === 'scheduled'"
              icon="mdi-pencil-outline"
              size="x-small"
              variant="text"
              @click="openForm(item)"
            />
            <v-btn
              v-if="item.status === 'draft' || item.status === 'scheduled'"
              icon="mdi-send"
              size="x-small"
              variant="text"
              color="primary"
              @click="confirmSend(item)"
            />
            <v-btn
              v-if="item.status === 'draft' || item.status === 'scheduled' || item.status === 'sending'"
              icon="mdi-cancel"
              size="x-small"
              variant="text"
              color="error"
              @click="confirmCancel(item)"
            />
            <v-btn
              v-if="item.status === 'sent'"
              icon="mdi-chart-bar"
              size="x-small"
              variant="text"
              color="info"
              @click="openStats(item)"
            />
          </div>
        </template>
      </v-data-table-server>
    </v-card>

    <!-- Create / Edit dialog -->
    <v-dialog v-model="formDialog" max-width="640" persistent>
      <v-card>
        <v-card-title>
          {{ editingId ? 'Редактировать уведомление' : 'Новое уведомление' }}
        </v-card-title>
        <v-card-text>
          <v-form ref="formRef" @submit.prevent="saveNotification">
            <v-text-field
              v-model="form.title"
              label="Заголовок (необязательно)"
              class="mb-3"
              density="compact"
              counter="255"
            />
            <RichTextEditor
              v-model="form.body"
              label="Текст уведомления *"
              :error="bodyError"
              class="mb-3"
            />

            <v-divider class="mb-4" />

            <v-select
              v-model="form.audience_type"
              :items="audienceOptions"
              label="Аудитория *"
              :rules="[v => !!v || 'Обязательное поле']"
              density="compact"
              class="mb-3"
            />

            <!-- User picker (shown for 'users' audience) -->
            <v-autocomplete
              v-if="form.audience_type === 'users'"
              v-model="form.userIds"
              :items="userResults"
              :loading="userSearchLoading"
              item-title="label"
              item-value="id"
              label="Пользователи *"
              density="compact"
              chips
              closable-chips
              multiple
              :search-input.sync="userSearchQuery"
              @update:search="searchUsers"
              no-data-text="Введите имя или email для поиска"
              class="mb-3"
            />

            <v-divider class="mb-4" />

            <v-text-field
              v-model="form.link_url"
              label="Ссылка URL (необязательно)"
              density="compact"
              class="mb-3"
              placeholder="/projects/1 или https://..."
            />
            <v-row dense>
              <v-col cols="8">
                <v-text-field
                  v-model="form.link_label"
                  label="Текст ссылки"
                  density="compact"
                  placeholder="Перейти к проекту"
                />
              </v-col>
              <v-col cols="4">
                <v-select
                  v-model="form.link_type"
                  :items="[{ title: 'Внутренняя', value: 'internal' }, { title: 'Внешняя', value: 'external' }]"
                  label="Тип"
                  density="compact"
                />
              </v-col>
            </v-row>

            <v-divider class="my-4" />

            <v-switch
              v-model="scheduleEnabled"
              label="Запланировать отправку"
              color="primary"
              density="compact"
              class="mb-2"
            />
            <v-text-field
              v-if="scheduleEnabled"
              v-model="form.send_at"
              label="Дата и время отправки"
              type="datetime-local"
              density="compact"
            />
          </v-form>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="formDialog = false">Отмена</v-btn>
          <v-btn color="primary" variant="tonal" :loading="saving" @click="saveNotification">
            {{ editingId ? 'Сохранить' : 'Создать черновик' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Stats dialog -->
    <v-dialog v-model="statsDialog" max-width="400">
      <v-card>
        <v-card-title>Статистика уведомления</v-card-title>
        <v-card-text v-if="statsData">
          <v-list density="compact">
            <v-list-item>
              <template #prepend><v-icon icon="mdi-account-group" class="mr-3" /></template>
              <v-list-item-title>Целевая аудитория</v-list-item-title>
              <template #append>{{ statsData.target }}</template>
            </v-list-item>
            <v-list-item>
              <template #prepend><v-icon icon="mdi-email-check-outline" class="mr-3" /></template>
              <v-list-item-title>Доставлено</v-list-item-title>
              <template #append>{{ statsData.delivered }}</template>
            </v-list-item>
            <v-list-item>
              <template #prepend><v-icon icon="mdi-eye-outline" class="mr-3" /></template>
              <v-list-item-title>Прочитано</v-list-item-title>
              <template #append>{{ statsData.read }} ({{ statsData.read_rate }}%)</template>
            </v-list-item>
            <v-list-item>
              <template #prepend><v-icon icon="mdi-cursor-default-click-outline" class="mr-3" /></template>
              <v-list-item-title>Кликнуто</v-list-item-title>
              <template #append>{{ statsData.clicked }} ({{ statsData.ctr }}%)</template>
            </v-list-item>
          </v-list>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="statsDialog = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Confirm send dialog -->
    <v-dialog v-model="sendDialog" max-width="400">
      <v-card>
        <v-card-title>Отправить уведомление?</v-card-title>
        <v-card-text>
          Уведомление будет отправлено всей указанной аудитории. Это действие нельзя отменить.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="sendDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="sending" @click="doSend">Отправить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Confirm cancel dialog -->
    <v-dialog v-model="cancelDialog" max-width="400">
      <v-card>
        <v-card-title>Отменить уведомление?</v-card-title>
        <v-card-text>
          Уведомление будет отменено. Пользователи, которые ещё не получили его, не получат.
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="cancelDialog = false">Нет</v-btn>
          <v-btn color="error" :loading="cancelling" @click="doCancel">Отменить уведомление</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import {
  adminNotificationsApi,
  type AdminNotification,
  type NotificationStats,
  type CreateNotificationPayload,
} from '@/api/notifications'
import RichTextEditor from './RichTextEditor.vue'

// ==================== List state ====================
const loading = ref(false)
const items = ref<(AdminNotification & { stats?: { delivered: number; read: number; clicked: number } })[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const search = ref('')
const statusFilter = ref<string | null>(null)
const audienceFilter = ref<string | null>(null)

const statusOptions = [
  { title: 'Черновик', value: 'draft' },
  { title: 'Запланировано', value: 'scheduled' },
  { title: 'Отправляется', value: 'sending' },
  { title: 'Отправлено', value: 'sent' },
  { title: 'Отменено', value: 'cancelled' },
]

const audienceOptions = [
  { title: 'Все', value: 'all' },
  { title: 'Выбранные', value: 'users' },
  { title: 'Сегмент', value: 'segment' },
]

const headers = [
  { title: 'Заголовок / Текст', key: 'body', sortable: false, width: '35%' },
  { title: 'Статус', key: 'status', sortable: false, width: '12%' },
  { title: 'Аудитория', key: 'audience_type', sortable: false, width: '10%' },
  { title: 'Доставлено / Прочитано / Клики', key: 'stats', sortable: false, width: '18%' },
  { title: 'Создано', key: 'created_at', sortable: false, width: '12%' },
  { title: '', key: 'actions', sortable: false, width: '13%' },
]

let searchTimeout: ReturnType<typeof setTimeout> | null = null
function debouncedLoad() {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => { loadList() }, 400)
}

async function loadList() {
  loading.value = true
  try {
    const params: Record<string, any> = { page: page.value, per_page: perPage.value }
    if (search.value) params.search = search.value
    if (statusFilter.value) params.status = statusFilter.value
    if (audienceFilter.value) params.audience_type = audienceFilter.value

    const res = await adminNotificationsApi.list(params)
    items.value = res.data
    total.value = res.meta.total
  } finally {
    loading.value = false
  }
}

// ==================== Form state ====================
const formDialog = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const formRef = ref<any>(null)
const scheduleEnabled = ref(false)
const bodyError = ref('')

const form = ref({
  title: '',
  body: '',
  audience_type: 'all' as 'all' | 'users' | 'segment',
  userIds: [] as number[],
  link_url: '',
  link_label: '',
  link_type: 'internal' as 'internal' | 'external',
  send_at: '',
})

// User search
const userSearchQuery = ref('')
const userSearchLoading = ref(false)
const userResults = ref<{ id: number; label: string }[]>([])

let userSearchTimeout: ReturnType<typeof setTimeout> | null = null
function searchUsers(q: string) {
  if (userSearchTimeout) clearTimeout(userSearchTimeout)
  if (!q || q.length < 2) return
  userSearchTimeout = setTimeout(async () => {
    userSearchLoading.value = true
    try {
      const res = await adminNotificationsApi.searchUsers(q)
      userResults.value = res.map((u) => ({ id: u.id, label: `${u.name} (${u.email})` }))
    } finally {
      userSearchLoading.value = false
    }
  }, 300)
}

function openForm(item?: AdminNotification) {
  if (item) {
    editingId.value = item.id
    form.value = {
      title: item.title || '',
      body: item.body,
      audience_type: item.audience_type,
      userIds: item.audience_payload?.user_ids || [],
      link_url: item.link_url || '',
      link_label: item.link_label || '',
      link_type: item.link_type || 'internal',
      send_at: item.send_at ? item.send_at.replace(' ', 'T').slice(0, 16) : '',
    }
    scheduleEnabled.value = !!item.send_at
  } else {
    editingId.value = null
    form.value = {
      title: '',
      body: '',
      audience_type: 'all',
      userIds: [],
      link_url: '',
      link_label: '',
      link_type: 'internal',
      send_at: '',
    }
    scheduleEnabled.value = false
  }
  formDialog.value = true
}

async function saveNotification() {
  bodyError.value = ''
  if (!form.value.body || form.value.body === '<p></p>') {
    bodyError.value = 'Обязательное поле'
    return
  }
  if (formRef.value) {
    const { valid } = await formRef.value.validate()
    if (!valid) return
  }

  saving.value = true
  try {
    const payload: CreateNotificationPayload = {
      title: form.value.title || undefined,
      body: form.value.body,
      audience_type: form.value.audience_type,
      link_url: form.value.link_url || undefined,
      link_label: form.value.link_label || undefined,
      link_type: form.value.link_type,
      send_at: scheduleEnabled.value && form.value.send_at ? form.value.send_at : null,
    }

    if (form.value.audience_type === 'users') {
      payload.audience_payload = { user_ids: form.value.userIds }
    }

    if (editingId.value) {
      await adminNotificationsApi.update(editingId.value, payload)
    } else {
      await adminNotificationsApi.create(payload)
    }

    formDialog.value = false
    loadList()
  } finally {
    saving.value = false
  }
}

// ==================== Send / Cancel ====================
const sendDialog = ref(false)
const cancelDialog = ref(false)
const sending = ref(false)
const cancelling = ref(false)
const actionTarget = ref<AdminNotification | null>(null)

function confirmSend(item: AdminNotification) {
  actionTarget.value = item
  sendDialog.value = true
}

function confirmCancel(item: AdminNotification) {
  actionTarget.value = item
  cancelDialog.value = true
}

async function doSend() {
  if (!actionTarget.value) return
  sending.value = true
  try {
    await adminNotificationsApi.send(actionTarget.value.id)
    sendDialog.value = false
    loadList()
  } finally {
    sending.value = false
  }
}

async function doCancel() {
  if (!actionTarget.value) return
  cancelling.value = true
  try {
    await adminNotificationsApi.cancel(actionTarget.value.id)
    cancelDialog.value = false
    loadList()
  } finally {
    cancelling.value = false
  }
}

// ==================== Stats ====================
const statsDialog = ref(false)
const statsData = ref<NotificationStats | null>(null)

async function openStats(item: AdminNotification) {
  statsData.value = null
  statsDialog.value = true
  statsData.value = await adminNotificationsApi.stats(item.id)
}

// ==================== Helpers ====================
function statusColor(s: string) {
  const map: Record<string, string> = {
    draft: 'grey',
    scheduled: 'blue',
    sending: 'orange',
    sent: 'green',
    cancelled: 'red',
  }
  return map[s] || 'grey'
}

function statusLabel(s: string) {
  const map: Record<string, string> = {
    draft: 'Черновик',
    scheduled: 'Запланировано',
    sending: 'Отправляется',
    sent: 'Отправлено',
    cancelled: 'Отменено',
  }
  return map[s] || s
}

function audienceLabel(a: string) {
  const map: Record<string, string> = { all: 'Все', users: 'Выбранные', segment: 'Сегмент' }
  return map[a] || a
}

function formatDate(d: string) {
  return new Date(d).toLocaleDateString('ru-RU', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

onMounted(() => {
  loadList()
})
</script>
