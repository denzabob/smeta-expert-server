import api from '@/api/axios'

// ── Types ─────────────────────────────────────────────────────────────────────

export type ConversationStatus = 'open' | 'pending' | 'closed'
export type ParticipantRole   = 'customer' | 'admin' | 'bot' | 'system'
export type MessageType       = 'text' | 'system' | 'file' | 'ai'

export interface ChatAttachment {
  id:            number
  original_name: string
  mime_type:     string
  size:          number
  width:         number | null
  height:        number | null
  url:           string
}

export interface ChatMessage {
  id:                   number
  conversation_id:      number
  sender_id:            number | null
  sender_role:          ParticipantRole
  type:                 MessageType
  body:                 string | null
  meta_json:            Record<string, unknown> | null
  is_mine:              boolean
  sender_display_name:  string | null
  created_at:           string
  attachments?:         ChatAttachment[]
}

export interface ChatParticipant {
  id:      number
  user_id: number
  role:    ParticipantRole
  user:    { id: number; name: string } | null
}

export interface ChatConversation {
  id:                number
  status:            ConversationStatus
  subject:           string | null
  assigned_admin_id: number | null
  last_message_at:   string | null
  unread_count:      number
  participants:      ChatParticipant[]
  messages:          ChatMessage[]
  created_at:        string
}

// ── API functions ─────────────────────────────────────────────────────────────

export const supportChatApi = {
  /** GET /api/support-chat/conversation — returns or creates active conversation */
  getConversation(): Promise<{ conversation: ChatConversation }> {
    return api.get('/api/support-chat/conversation').then((r) => r.data)
  },

  /** GET /api/support-chat/conversations/{id}/messages?after_id= */
  getMessages(
    conversationId: number,
    afterId = 0,
  ): Promise<{ messages: ChatMessage[] }> {
    const params = afterId > 0 ? { after_id: afterId } : {}
    return api
      .get(`/api/support-chat/conversations/${conversationId}/messages`, { params })
      .then((r) => r.data)
  },

  /** POST /api/support-chat/conversations/{id}/messages */
  sendMessage(
    conversationId: number,
    payload: { body?: string; file?: File },
  ): Promise<{ message: ChatMessage }> {
    const form = new FormData()
    if (payload.body?.trim()) form.append('body', payload.body.trim())
    if (payload.file)         form.append('attachment', payload.file)
    return api
      .post(`/api/support-chat/conversations/${conversationId}/messages`, form, {
        headers: { 'Content-Type': undefined },
      })
      .then((r) => r.data)
  },

  /** POST /api/support-chat/conversations/{id}/read */
  markRead(conversationId: number): Promise<void> {
    return api
      .post(`/api/support-chat/conversations/${conversationId}/read`)
      .then(() => undefined)
  },
}
