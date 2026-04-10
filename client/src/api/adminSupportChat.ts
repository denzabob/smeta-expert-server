import api from '@/api/axios'
import type {
  ChatMessage,
  ChatConversation,
  ConversationStatus,
} from '@/api/supportChat'

// ── Admin-specific types ──────────────────────────────────────────────────────

export interface AdminConversationMeta {
  id:                number
  status:            ConversationStatus
  subject:           string | null
  assigned_admin_id: number | null
  assigned_admin:    { id: number; name: string } | null
  creator:           { id: number; name: string; email: string } | null
  last_message_at:   string | null
  unread_count:      number
  created_at:        string
}

export interface AdminConversationDetail extends AdminConversationMeta {
  participants: Array<{
    id:        number
    user_id:   number
    role:      string
    joined_at: string
    user:      { id: number; name: string } | null
  }>
  messages: ChatMessage[]
}

export interface AdminListParams {
  status?:             ConversationStatus | ''
  assigned_admin_id?:  number | null
  unassigned?:         boolean
  search?:             string
  per_page?:           number
  page?:               number
}

export interface AdminListResponse {
  conversations: AdminConversationMeta[]
  pagination: {
    total:        number
    per_page:     number
    current_page: number
    last_page:    number
  }
}

export interface AssignResponse {
  assigned_admin_id: number | null
  assigned_admin:    { id: number; name: string } | null
}

// ── API functions ─────────────────────────────────────────────────────────────

export const adminChatApi = {
  /** GET /api/admin/chat/conversations */
  list(params: AdminListParams = {}): Promise<AdminListResponse> {
    const query: Record<string, string | number | boolean> = {}
    if (params.status)                           query.status = params.status
    if (params.unassigned)                       query.unassigned = 1
    else if (params.assigned_admin_id != null)   query.assigned_admin_id = params.assigned_admin_id
    if (params.search)                           query.search = params.search
    if (params.per_page)                         query.per_page = params.per_page
    if (params.page)                             query.page = params.page
    return api.get('/api/admin/chat/conversations', { params: query }).then((r) => r.data)
  },

  /** GET /api/admin/chat/conversations/{id} */
  show(id: number): Promise<{ conversation: AdminConversationDetail }> {
    return api.get(`/api/admin/chat/conversations/${id}`).then((r) => r.data)
  },

  /** GET /api/admin/chat/conversations/{id}/messages?after_id= */
  getMessages(id: number, afterId = 0): Promise<{ messages: ChatMessage[] }> {
    const params = afterId > 0 ? { after_id: afterId } : {}
    return api.get(`/api/admin/chat/conversations/${id}/messages`, { params }).then((r) => r.data)
  },

  /** POST /api/admin/chat/conversations/{id}/messages */
  sendMessage(id: number, payload: { body?: string; file?: File }): Promise<{ message: ChatMessage }> {
    const form = new FormData()
    if (payload.body?.trim()) form.append('body', payload.body.trim())
    if (payload.file)         form.append('attachment', payload.file)
    return api.post(`/api/admin/chat/conversations/${id}/messages`, form, {
      headers: { 'Content-Type': undefined },
    }).then((r) => r.data)
  },

  /** POST /api/admin/chat/conversations/{id}/read */
  markRead(id: number): Promise<void> {
    return api.post(`/api/admin/chat/conversations/${id}/read`).then(() => undefined)
  },

  /** POST /api/admin/chat/conversations/{id}/assign */
  assignMe(id: number): Promise<AssignResponse> {
    return api.post(`/api/admin/chat/conversations/${id}/assign`).then((r) => r.data)
  },

  /** POST /api/admin/chat/conversations/{id}/typing */
  reportTyping(id: number): Promise<void> {
    return api.post(`/api/admin/chat/conversations/${id}/typing`).then(() => undefined)
  },

  /** GET /api/admin/chat/conversations/{id}/typing-status */
  typingStatus(id: number): Promise<{ user_typing: boolean }> {
    return api.get(`/api/admin/chat/conversations/${id}/typing-status`).then((r) => r.data)
  },
}
