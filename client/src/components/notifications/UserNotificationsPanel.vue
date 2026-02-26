<template>
  <div class="notifications-panel">
    <!-- Header -->
    <div class="np-header">
      <div class="np-title-row">
        <v-icon icon="mdi-bell-outline" size="22" class="mr-2" />
        <span class="np-title">Уведомления</span>
        <v-chip v-if="store.unreadCount > 0" color="error" size="x-small" class="ml-2">
          {{ store.badgeText }}
        </v-chip>
        <v-spacer />
        <v-btn
          v-if="store.hasUnread"
          variant="text"
          size="small"
          color="primary"
          :loading="markingAll"
          @click="handleMarkAllRead"
        >
          Прочитать все
        </v-btn>
      </div>

      <!-- Filter tabs -->
      <div class="np-filters">
        <v-btn-toggle v-model="activeFilter" mandatory density="compact" variant="outlined" divided>
          <v-btn value="all" size="small">Все</v-btn>
          <v-btn value="unread" size="small">
            Непрочитанные
            <v-chip
              v-if="store.unreadCount > 0"
              color="error"
              size="x-small"
              class="ml-1"
              variant="flat"
            >
              {{ store.badgeText }}
            </v-chip>
          </v-btn>
        </v-btn-toggle>
      </div>
    </div>

    <!-- List -->
    <div class="np-list" ref="listRef">
      <template v-if="store.loading && store.items.length === 0">
        <div class="np-skeleton" v-for="i in 5" :key="i">
          <v-skeleton-loader type="list-item-three-line" />
        </div>
      </template>

      <template v-else-if="store.items.length === 0">
        <div class="np-empty">
          <v-icon icon="mdi-bell-check-outline" size="48" color="grey-lighten-1" />
          <div class="np-empty-text">
            {{ activeFilter === 'unread' ? 'Нет непрочитанных уведомлений' : 'Нет уведомлений' }}
          </div>
        </div>
      </template>

      <template v-else>
        <div
          v-for="item in store.items"
          :key="item.id"
          class="np-item"
          :class="{
            'np-item--unread': !item.read_at,
            'np-item--clickable': !!item.link_url,
          }"
          @click="handleItemClick(item)"
        >
          <!-- Unread dot -->
          <div class="np-dot-col">
            <div v-if="!item.read_at" class="np-dot" />
          </div>

          <div class="np-item-content">
            <div v-if="item.title" class="np-item-title">{{ item.title }}</div>
            <div
              class="np-item-body"
              :class="{ 'np-item-body--expanded': expandedId === item.id }"
              v-html="item.body"
            />
            <button
              v-if="isBodyLong(item.body)"
              class="np-expand-btn"
              @click.stop="toggleExpand(item.id)"
            >
              {{ expandedId === item.id ? 'Свернуть' : 'Читать полностью' }}
            </button>
            <div class="np-item-meta">
              <span class="np-item-time">{{ formatTime(item.delivered_at) }}</span>
              <v-btn
                v-if="item.link_url && item.link_label"
                variant="text"
                size="x-small"
                color="primary"
                :append-icon="item.link_type === 'external' ? 'mdi-open-in-new' : 'mdi-arrow-right'"
                @click.stop="handleLinkClick(item)"
              >
                {{ item.link_label }}
              </v-btn>
            </div>
          </div>
        </div>

        <!-- Load more -->
        <div v-if="canLoadMore" class="np-load-more">
          <v-btn
            variant="text"
            size="small"
            :loading="store.loading"
            @click="loadMore"
          >
            Загрузить ещё
          </v-btn>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationsStore } from '@/stores/notifications'
import type { NotificationItem } from '@/api/notifications'

const store = useNotificationsStore()
const router = useRouter()

const markingAll = ref(false)
const expandedId = ref<number | null>(null)
const activeFilter = ref<'all' | 'unread'>(store.hasUnread ? 'unread' : 'all')

const canLoadMore = computed(() => {
  if (!store.meta) return false
  return store.meta.current_page < store.meta.last_page
})

watch(activeFilter, (f) => {
  store.setFilter(f === 'unread' ? 'unread' : 'all')
})

onMounted(() => {
  // Auto-select unread tab if there are unread notifications
  if (store.hasUnread) {
    activeFilter.value = 'unread'
    store.setFilter('unread')
  }
  store.fetchNotifications(1)
})

function toggleExpand(id: number) {
  expandedId.value = expandedId.value === id ? null : id
}

function isBodyLong(body: string): boolean {
  // Consider body "long" if it has more than ~120 chars or multiple lines
  const plainLen = body.replace(/<[^>]*>/g, '').length
  const hasMultipleBlocks = (body.match(/<\/(p|li|div|br)>/gi) || []).length > 3
  return plainLen > 120 || hasMultipleBlocks
}

async function handleMarkAllRead() {
  markingAll.value = true
  try {
    await store.markAllRead()
  } finally {
    markingAll.value = false
  }
}

async function handleItemClick(item: NotificationItem) {
  if (!item.read_at) {
    await store.markAsRead(item.id)
  }
}

async function handleLinkClick(item: NotificationItem) {
  if (!item.link_url) return
  await store.markClicked(item.id)
  if (item.link_type === 'external') {
    window.open(item.link_url, '_blank')
  } else {
    router.push(item.link_url)
  }
}

function loadMore() {
  if (!store.meta) return
  store.fetchNotifications(store.meta.current_page + 1)
}

function formatTime(dateStr: string): string {
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  const diffMin = Math.floor(diffMs / 60000)
  if (diffMin < 1) return 'только что'
  if (diffMin < 60) return `${diffMin} мин назад`
  const diffH = Math.floor(diffMin / 60)
  if (diffH < 24) return `${diffH} ч назад`
  const diffD = Math.floor(diffH / 24)
  if (diffD < 7) return `${diffD} дн назад`
  return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })
}
</script>

<style scoped>
.notifications-panel {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.np-header {
  padding: 16px 16px 8px;
  flex-shrink: 0;
}

.np-title-row {
  display: flex;
  align-items: center;
  margin-bottom: 12px;
}

.np-title {
  font-size: 1.1rem;
  font-weight: 600;
}

.np-filters {
  margin-bottom: 4px;
}

.np-list {
  flex: 1;
  overflow-y: auto;
  padding: 0 8px 8px;
}

.np-item {
  display: flex;
  gap: 8px;
  padding: 12px 8px;
  border-radius: 8px;
  cursor: default;
  transition: background 0.15s;
}

.np-item:hover {
  background: rgba(0, 0, 0, 0.04);
}

.np-item--unread {
  background: rgba(var(--v-theme-primary), 0.04);
}

.np-item--unread:hover {
  background: rgba(var(--v-theme-primary), 0.08);
}

.np-item--clickable {
  cursor: pointer;
}

.np-dot-col {
  width: 10px;
  flex-shrink: 0;
  padding-top: 6px;
}

.np-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: rgb(var(--v-theme-primary));
}

.np-item-content {
  flex: 1;
  min-width: 0;
}

.np-item-title {
  font-weight: 600;
  font-size: 0.875rem;
  margin-bottom: 2px;
}

.np-item-body {
  font-size: 0.825rem;
  color: rgba(var(--v-theme-on-surface), 0.75);
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.np-item-body--expanded {
  display: block;
  -webkit-line-clamp: unset;
  line-clamp: unset;
  overflow: visible;
}

/* HTML content styling inside notification body */
.np-item-body :deep(ul),
.np-item-body :deep(ol) {
  padding-left: 18px;
  margin: 4px 0;
}

.np-item-body :deep(li) {
  margin-bottom: 2px;
}

.np-item-body :deep(p) {
  margin: 4px 0;
}

.np-item-body :deep(br) {
  content: '';
  display: block;
  margin-top: 4px;
}

.np-expand-btn {
  background: none;
  border: none;
  padding: 0;
  margin-top: 4px;
  font-size: 0.75rem;
  color: rgb(var(--v-theme-primary));
  cursor: pointer;
  font-weight: 500;
}

.np-expand-btn:hover {
  text-decoration: underline;
}

.np-item-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 4px;
}

.np-item-time {
  font-size: 0.75rem;
  color: rgba(var(--v-theme-on-surface), 0.5);
}

.np-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 16px;
  gap: 12px;
}

.np-empty-text {
  font-size: 0.875rem;
  color: rgba(var(--v-theme-on-surface), 0.5);
}

.np-load-more {
  display: flex;
  justify-content: center;
  padding: 12px 0;
}

.np-skeleton {
  margin-bottom: 8px;
}
</style>
