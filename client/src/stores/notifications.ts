import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import { notificationsApi, type NotificationItem, type PaginationMeta } from '@/api/notifications'

export const useNotificationsStore = defineStore('notifications', () => {
  // State
  const unreadCount = ref(0)
  const items = ref<NotificationItem[]>([])
  const meta = ref<PaginationMeta | null>(null)
  const loading = ref(false)
  const filter = ref<'all' | 'unread' | 'read'>('all')
  const pollTimer = ref<ReturnType<typeof setInterval> | null>(null)

  // Getters
  const hasUnread = computed(() => unreadCount.value > 0)
  const badgeText = computed(() => {
    if (unreadCount.value === 0) return ''
    return unreadCount.value > 99 ? '99+' : String(unreadCount.value)
  })

  // Actions
  async function fetchUnreadCount() {
    try {
      const res = await notificationsApi.unreadCount()
      unreadCount.value = res.count
    } catch {
      // silently ignore — badge is non-critical
    }
  }

  async function fetchNotifications(page = 1) {
    loading.value = true
    try {
      const params: Record<string, any> = { page, per_page: 30 }
      if (filter.value !== 'all') params.filter = filter.value
      const res = await notificationsApi.list(params)
      if (page === 1) {
        items.value = res.data
      } else {
        items.value.push(...res.data)
      }
      meta.value = res.meta
    } finally {
      loading.value = false
    }
  }

  async function markAsRead(id: number) {
    await notificationsApi.markRead(id)
    const item = items.value.find((n) => n.id === id)
    if (item && !item.read_at) {
      item.read_at = new Date().toISOString()
      unreadCount.value = Math.max(0, unreadCount.value - 1)
    }
  }

  async function markAllRead() {
    await notificationsApi.markAllRead()
    items.value.forEach((n) => {
      if (!n.read_at) n.read_at = new Date().toISOString()
    })
    unreadCount.value = 0
  }

  async function markClicked(id: number) {
    await notificationsApi.markClicked(id)
    const item = items.value.find((n) => n.id === id)
    if (item) {
      item.clicked_at = new Date().toISOString()
      if (!item.read_at) {
        item.read_at = new Date().toISOString()
        unreadCount.value = Math.max(0, unreadCount.value - 1)
      }
    }
  }

  function setFilter(f: 'all' | 'unread' | 'read') {
    filter.value = f
    fetchNotifications(1)
  }

  /** Start polling unread count every 60 seconds */
  function startPolling() {
    if (pollTimer.value) return
    fetchUnreadCount()
    pollTimer.value = setInterval(fetchUnreadCount, 60_000)
  }

  /** Stop polling */
  function stopPolling() {
    if (pollTimer.value) {
      clearInterval(pollTimer.value)
      pollTimer.value = null
    }
  }

  function $reset() {
    stopPolling()
    unreadCount.value = 0
    items.value = []
    meta.value = null
    loading.value = false
    filter.value = 'all'
  }

  return {
    // State
    unreadCount,
    items,
    meta,
    loading,
    filter,
    // Getters
    hasUnread,
    badgeText,
    // Actions
    fetchUnreadCount,
    fetchNotifications,
    markAsRead,
    markAllRead,
    markClicked,
    setFilter,
    startPolling,
    stopPolling,
    $reset,
  }
})
