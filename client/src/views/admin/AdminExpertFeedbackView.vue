<template>
  <PageContainer>
    <PageHeader title="Обратная связь Expert Chat" subtitle="Оценки ответов и комментарии пользователей" />
    <div class="expert-feedback-filters">
      <label>Оценка<select v-model="filters.rating"><option value="negative">👎 Отрицательные</option><option value="positive">👍 Положительные</option><option value="all">Все</option></select></label>
      <label>Провайдер<input v-model.trim="filters.provider" placeholder="Все" /></label>
      <label>Модель<input v-model.trim="filters.model" placeholder="Все" /></label>
      <label>Причина<select v-model="filters.reason_code"><option value="">Все</option><option v-for="reason in reasons" :key="reason.code" :value="reason.code">{{ reason.label }}</option></select></label>
      <label>С<input v-model="filters.from" type="date" /></label>
      <label>По<input v-model="filters.to" type="date" /></label>
      <button type="button" @click="applyFilters">Применить</button>
    </div>
    <p v-if="error" role="alert" class="expert-feedback-error">{{ error }}</p>
    <p v-if="loading" role="status">Загружаю отзывы…</p>
    <p v-else-if="!rows.length">По выбранным фильтрам отзывов нет.</p>
    <div v-else class="expert-feedback-table-wrap">
      <table class="expert-feedback-table">
        <thead><tr><th>Дата</th><th>Пользователь</th><th>Проект</th><th>Модель</th><th>Оценка</th><th>Причина</th><th>Комментарий</th></tr></thead>
        <tbody>
          <template v-for="row in rows" :key="row.id">
            <tr>
              <td><button type="button" class="expert-feedback-detail-button" :aria-expanded="expandedId === row.id" @click="toggleDetail(row.id)">{{ formatDate(row.created_at) }} {{ expandedId === row.id ? '▴' : '▾' }}</button></td>
              <td>{{ row.user.name || row.user.email || '—' }}</td>
              <td>{{ row.project.name || '—' }}</td>
              <td>{{ row.model || '—' }}</td>
              <td>{{ row.rating === 'negative' ? '👎' : '👍' }}</td>
              <td>{{ reasonLabel(row.reason_code) }}</td>
              <td class="expert-feedback-comment">{{ row.comment || '—' }}</td>
            </tr>
            <tr v-if="expandedId === row.id"><td colspan="7">
              <p v-if="detailLoading">Загружаю подробности…</p>
              <div v-else-if="details[row.id]" class="expert-feedback-detail">
                <div><strong>Запрос пользователя</strong><p>{{ details[row.id]?.request || '—' }}</p></div>
                <div><strong>Ответ модели</strong><p>{{ details[row.id]?.answer || '—' }}</p></div>
                <div><strong>Материалы</strong><p>{{ details[row.id]?.materials.join(', ') || '—' }}</p></div>
                <div><strong>Provider / model / run</strong><p>{{ details[row.id]?.provider || '—' }} / {{ details[row.id]?.model || '—' }} / {{ details[row.id]?.run_id || '—' }}</p></div>
                <div><strong>Задержка</strong><p>{{ details[row.id]?.latency_ms == null ? '—' : `${details[row.id]?.latency_ms} мс` }}</p></div>
              </div>
            </td></tr>
          </template>
        </tbody>
      </table>
    </div>
    <div v-if="lastPage > 1" class="expert-feedback-pager"><button type="button" :disabled="page <= 1" @click="goToPage(page - 1)">Назад</button><span>{{ page }} / {{ lastPage }}</span><button type="button" :disabled="page >= lastPage" @click="goToPage(page + 1)">Далее</button></div>
  </PageContainer>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import api from '@/api/axios'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'

interface FeedbackRow {
  id: string
  created_at: string
  user: { name: string | null; email: string | null }
  project: { id: string | null; name: string | null }
  provider: string | null
  model: string | null
  rating: 'positive' | 'negative'
  reason_code: string | null
  comment: string | null
}
interface FeedbackDetail { request: string | null; answer: string | null; materials: string[]; provider: string | null; model: string | null; run_id: string | null; latency_ms: number | null }
const reasons = [
  { code: 'incorrect_or_incomplete', label: 'Неправильно или неполно' },
  { code: 'not_requested', label: 'Не то, что я просил' },
  { code: 'material_analysis_error', label: 'Ошибка в анализе материалов' },
  { code: 'too_slow', label: 'Слишком медленно' },
  { code: 'style_or_formatting', label: 'Стиль / оформление' },
  { code: 'other', label: 'Другое' },
]
const filters = reactive({ rating: 'negative', provider: '', model: '', reason_code: '', from: '', to: '' })
const rows = ref<FeedbackRow[]>([])
const details = ref<Record<string, FeedbackDetail>>({})
const expandedId = ref('')
const detailLoading = ref(false)
const loading = ref(false)
const error = ref('')
const page = ref(1)
const lastPage = ref(1)
let requestSequence = 0
function reasonLabel(code: string | null) { return reasons.find((item) => item.code === code)?.label || '—' }
function formatDate(value: string) { return new Date(value).toLocaleString('ru-RU') }
async function load() {
  const sequence = ++requestSequence
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get<{ data: FeedbackRow[]; current_page: number; last_page: number }>('/api/admin/expert-feedback', {
      params: { ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')), page: page.value },
    })
    if (sequence !== requestSequence) return
    rows.value = data.data
    page.value = data.current_page
    lastPage.value = data.last_page
  } catch {
    if (sequence === requestSequence) error.value = 'Не удалось загрузить отзывы.'
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}
function applyFilters() { page.value = 1; expandedId.value = ''; void load() }
function goToPage(next: number) { page.value = next; expandedId.value = ''; void load() }
async function toggleDetail(id: string) {
  if (expandedId.value === id) { expandedId.value = ''; return }
  expandedId.value = id
  if (details.value[id]) return
  detailLoading.value = true
  try {
    const { data } = await api.get<FeedbackDetail>(`/api/admin/expert-feedback/${encodeURIComponent(id)}`)
    details.value = { ...details.value, [id]: data }
  } catch {
    error.value = 'Не удалось загрузить подробности отзыва.'
  } finally {
    detailLoading.value = false
  }
}
onMounted(() => { void load() })
</script>

<style scoped>
.expert-feedback-filters { display: flex; flex-wrap: wrap; align-items: end; gap: 10px; margin-bottom: 18px; }
.expert-feedback-filters label { display: grid; gap: 4px; min-width: 130px; font-size: .8rem; }
.expert-feedback-filters input, .expert-feedback-filters select { min-height: 34px; padding: 5px 7px; border: 1px solid rgb(var(--v-theme-outline-variant)); border-radius: var(--md-sys-shape-corner-small); color: inherit; background: rgb(var(--v-theme-surface)); font: inherit; }
.expert-feedback-filters button, .expert-feedback-pager button { min-height: 34px; padding: 5px 11px; border: 1px solid rgb(var(--v-theme-outline-variant)); border-radius: var(--md-sys-shape-corner-small); color: inherit; background: rgb(var(--v-theme-surface)); cursor: pointer; }
.expert-feedback-error { color: rgb(var(--v-theme-error)); }
.expert-feedback-table-wrap { overflow-x: auto; }
.expert-feedback-table { width: 100%; border-collapse: collapse; text-align: left; }
.expert-feedback-table th, .expert-feedback-table td { padding: 9px; border-bottom: 1px solid rgb(var(--v-theme-outline-variant)); vertical-align: top; }
.expert-feedback-table th { white-space: nowrap; }
.expert-feedback-comment { max-width: 280px; overflow-wrap: anywhere; }
.expert-feedback-detail-button { border: 0; color: rgb(var(--v-theme-primary)); background: transparent; cursor: pointer; font: inherit; white-space: nowrap; }
.expert-feedback-detail { display: grid; gap: 10px; padding: 10px; background: rgb(var(--v-theme-surface-container-low)); }
.expert-feedback-detail p { margin: 4px 0 0; white-space: pre-wrap; overflow-wrap: anywhere; }
.expert-feedback-pager { display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 14px; }
@media (max-width: 600px) { .expert-feedback-filters label { flex: 1 1 140px; } }
</style>
