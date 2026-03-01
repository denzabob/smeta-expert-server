<template>
  <v-container>
    <v-card>
      <v-card-title>Проекты</v-card-title>
      <v-card-actions>
        <v-btn prepend-icon="mdi-plus" @click="createProject" :loading="creating">Новый проект</v-btn>
      </v-card-actions>
      <v-data-table
        :headers="headers"
        :items="projects"
        :loading="loading"
        :row-props="rowProps"
        item-value="id"
      >
        <template #item.revisions_count="{ item }">
          <v-chip size="small" variant="tonal" :color="(item.revisions_count || 0) > 0 ? 'primary' : 'grey'">
            {{ item.revisions_count || 0 }}
          </v-chip>
        </template>
        <template #item.latest_revision_status="{ item }">
          <v-chip
            size="small"
            variant="tonal"
            :color="getRevisionStatusColor(item.latest_revision_status)"
          >
            {{ getRevisionStatusLabel(item.latest_revision_status) }}
          </v-chip>
        </template>
        <template #item.latest_revision_at="{ item }">
          {{ formatDateOnly(item.latest_revision_at) }}
        </template>
        <template #item.created_at="{ item }">
          {{ formatDateOnly(item.created_at) }}
        </template>
        <template #item.updated_at="{ item }">
          {{ formatDateOnly(item.updated_at) }}
        </template>
        <template #item.actions="{ item }">
          <v-btn variant="text" size="small" class="action-icon-btn" @click.stop="editProject(item)">
            <v-icon>mdi-pencil</v-icon>
          </v-btn>
          <v-btn variant="text" size="small" class="action-icon-btn" @click.stop="deleteProject(item)">
            <v-icon>mdi-delete</v-icon>
          </v-btn>
        </template>
      </v-data-table>
    </v-card>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3000" location="bottom right">
      {{ snackbar.message }}
    </v-snackbar>

    <v-dialog v-model="deleteConfirmDialog" max-width="520">
      <v-card>
        <v-card-title>Подтверждение архивирования</v-card-title>
        <v-card-text>
          <div class="mb-2">
            В проекте есть ревизии ({{ deleteTarget?.revisions_count || 0 }}). Для архивирования введите:
            <strong>УДАЛИТЬ</strong>
          </div>
          <v-text-field
            v-model="deleteConfirmText"
            label="Подтверждение"
            placeholder="УДАЛИТЬ"
            density="comfortable"
            :disabled="deleting"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="closeDeleteConfirmDialog" :disabled="deleting">Отмена</v-btn>
          <v-btn
            color="error"
            variant="flat"
            :loading="deleting"
            :disabled="deleteConfirmText !== DELETE_CONFIRM_PHRASE"
            @click="confirmDeleteWithRevisions"
          >
            Архивировать проект
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-container>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/axios'
import { consumeProjectsFlashMessage } from '@/router/projectAccess'

const router = useRouter()

const projects = ref<any[]>([])
const loading = ref(false)
const creating = ref(false)
const deleting = ref(false)
const deleteConfirmDialog = ref(false)
const deleteConfirmText = ref('')
const deleteTarget = ref<any | null>(null)
const DELETE_CONFIRM_PHRASE = 'УДАЛИТЬ'
const snackbar = ref({
  show: false,
  message: '',
  color: 'warning'
})

const showNotification = (message: string, color = 'warning') => {
  snackbar.value = {
    show: true,
    message,
    color
  }
}

const headers = [
  { title: '№ дела', key: 'number' },
  { title: 'Эксперт', key: 'expert_name' },
  { title: 'Адрес', key: 'address' },
  { title: 'Ревизии', key: 'revisions_count' },
  { title: 'Статус ревизии', key: 'latest_revision_status' },
  { title: 'Дата ревизии', key: 'latest_revision_at' },
  { title: 'Дата создания', key: 'created_at' },
  { title: 'Последняя редакция', key: 'updated_at' },
  { title: 'Действия', key: 'actions', sortable: false }
]

const formatDateOnly = (value?: string | null) => {
  if (!value) return '—'

  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'

  const day = String(date.getDate()).padStart(2, '0')
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const year = String(date.getFullYear())
  return `${day}.${month}.${year}`
}

const getRevisionStatusLabel = (status?: string | null) => {
  if (!status) return 'Нет ревизий'
  if (status === 'published') return 'Опубликована'
  if (status === 'locked') return 'Зафиксирована'
  if (status === 'stale') return 'Устарела'
  return status
}

const getRevisionStatusColor = (status?: string | null) => {
  if (!status) return 'grey'
  if (status === 'published') return 'success'
  if (status === 'locked') return 'primary'
  if (status === 'stale') return 'warning'
  return 'grey'
}

const rowProps = ({ item }: { item: any }) => ({
  onClick: () => goToEditor(item),
  style: { cursor: 'pointer' }
})

const fetchProjects = async () => {
  loading.value = true
  try {
    projects.value = (await api.get('/api/projects')).data
  } catch (e) {
    console.error('Ошибка загрузки проектов:', e)
  } finally {
    loading.value = false
  }
}

const showQueuedNotification = () => {
  const flash = consumeProjectsFlashMessage()
  if (!flash) return

  showNotification(flash.message, flash.color)
}

// Создание проекта с настройками по умолчанию → сразу в редактор
const createProject = async () => {
  if (creating.value) return
  creating.value = true
  try {
    const response = await api.post('/api/projects', {})
    router.push(`/projects/${response.data.id}/edit`)
  } catch (e) {
    console.error('Ошибка создания проекта:', e)
    alert('Не удалось создать проект')
  } finally {
    creating.value = false
  }
}

// Клик по строке → редактор
const goToEditor = (item: any) => {
  router.push(`/projects/${item.id}/edit`)
}

// Кнопка "Редактировать" (остаётся для явного действия)
const editProject = (item: any) => {
  router.push(`/projects/${item.id}/edit`)
}

const deleteProject = async (item: any) => {
  const revisionsCount = Number(item?.revisions_count || 0)

  if (revisionsCount > 0) {
    deleteTarget.value = item
    deleteConfirmText.value = ''
    deleteConfirmDialog.value = true
    return
  }

  await deleteProjectRequest(item)
}

const deleteProjectRequest = async (item: any, confirmDelete?: string) => {
  if (deleting.value) return
  deleting.value = true
  try {
    await api.delete(`/api/projects/${item.id}`, {
      data: confirmDelete ? { confirm_delete: confirmDelete } : undefined
    })
    await fetchProjects()
  } catch (e) {
    console.error('Ошибка удаления:', e)
    alert('Не удалось архивировать проект')
  } finally {
    deleting.value = false
  }
}

const closeDeleteConfirmDialog = () => {
  if (deleting.value) return
  deleteConfirmDialog.value = false
  deleteConfirmText.value = ''
  deleteTarget.value = null
}

const confirmDeleteWithRevisions = async () => {
  if (!deleteTarget.value) return
  if (deleteConfirmText.value !== DELETE_CONFIRM_PHRASE) return
  await deleteProjectRequest(deleteTarget.value, DELETE_CONFIRM_PHRASE)
  closeDeleteConfirmDialog()
}

onMounted(async () => {
  await fetchProjects()
  showQueuedNotification()
})
</script>

<style scoped>
.action-icon-btn {
  min-width: 30px;
  width: 30px;
  height: 30px;
  padding: 0;
  border-radius: 6px;
}
</style>
