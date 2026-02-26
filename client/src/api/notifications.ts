import api from '@/api/axios'

// ==================== Types ====================

export interface NotificationItem {
  id: number
  notification_id: number
  title: string | null
  body: string
  link_url: string | null
  link_label: string | null
  link_type: 'internal' | 'external'
  delivered_at: string
  read_at: string | null
  clicked_at: string | null
}

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface NotificationListResponse {
  data: NotificationItem[]
  meta: PaginationMeta
}

export interface UnreadCountResponse {
  count: number
}

// Admin types
export interface AdminNotification {
  id: number
  title: string | null
  body: string
  link_url: string | null
  link_label: string | null
  link_type: 'internal' | 'external'
  audience_type: 'all' | 'users' | 'segment'
  audience_payload: { user_ids?: number[] } | null
  status: 'draft' | 'scheduled' | 'sending' | 'sent' | 'cancelled'
  send_at: string | null
  created_by: number | null
  created_at: string
  updated_at: string
  stats?: NotificationStats
}

export interface NotificationStats {
  target?: number
  delivered: number
  read: number
  clicked: number
  read_rate?: number
  ctr?: number
}

export interface AdminNotificationListResponse {
  data: (AdminNotification & { stats: NotificationStats })[]
  meta: PaginationMeta
}

export interface CreateNotificationPayload {
  title?: string
  body: string
  link_url?: string
  link_label?: string
  link_type?: 'internal' | 'external'
  audience_type: 'all' | 'users' | 'segment'
  audience_payload?: { user_ids?: number[] }
  send_at?: string | null
}

export interface UpdateNotificationPayload extends Partial<CreateNotificationPayload> {}

export interface UserSearchResult {
  id: number
  name: string
  email: string
}

// ==================== User API ====================

export const notificationsApi = {
  /** GET /api/notifications */
  async list(params?: { filter?: 'unread' | 'read'; page?: number; per_page?: number }): Promise<NotificationListResponse> {
    const { data } = await api.get('/api/notifications', { params })
    return data
  },

  /** GET /api/notifications/unread-count */
  async unreadCount(): Promise<UnreadCountResponse> {
    const { data } = await api.get('/api/notifications/unread-count')
    return data
  },

  /** POST /api/notifications/{id}/read */
  async markRead(id: number): Promise<void> {
    await api.post(`/api/notifications/${id}/read`)
  },

  /** POST /api/notifications/read-all */
  async markAllRead(): Promise<void> {
    await api.post('/api/notifications/read-all')
  },

  /** POST /api/notifications/{id}/click */
  async markClicked(id: number): Promise<void> {
    await api.post(`/api/notifications/${id}/click`)
  },
}

// ==================== Admin API ====================

export const adminNotificationsApi = {
  /** GET /api/admin/notifications */
  async list(params?: {
    status?: string
    audience_type?: string
    search?: string
    page?: number
    per_page?: number
  }): Promise<AdminNotificationListResponse> {
    const { data } = await api.get('/api/admin/notifications', { params })
    return data
  },

  /** POST /api/admin/notifications */
  async create(payload: CreateNotificationPayload): Promise<AdminNotification> {
    const { data } = await api.post('/api/admin/notifications', payload)
    return data
  },

  /** GET /api/admin/notifications/{id} */
  async get(id: number): Promise<AdminNotification & { stats: NotificationStats }> {
    const { data } = await api.get(`/api/admin/notifications/${id}`)
    return data
  },

  /** PUT /api/admin/notifications/{id} */
  async update(id: number, payload: UpdateNotificationPayload): Promise<AdminNotification> {
    const { data } = await api.put(`/api/admin/notifications/${id}`, payload)
    return data
  },

  /** POST /api/admin/notifications/{id}/send */
  async send(id: number): Promise<{ message: string; status: string }> {
    const { data } = await api.post(`/api/admin/notifications/${id}/send`)
    return data
  },

  /** POST /api/admin/notifications/{id}/cancel */
  async cancel(id: number): Promise<{ message: string }> {
    const { data } = await api.post(`/api/admin/notifications/${id}/cancel`)
    return data
  },

  /** GET /api/admin/notifications/{id}/stats */
  async stats(id: number): Promise<NotificationStats> {
    const { data } = await api.get(`/api/admin/notifications/${id}/stats`)
    return data
  },

  /** GET /api/admin/users/search */
  async searchUsers(q: string): Promise<UserSearchResult[]> {
    const { data } = await api.get('/api/admin/users/search', { params: { q } })
    return data
  },
}
