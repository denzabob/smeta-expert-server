import api from '@/api/axios'

export type IdeaStatus = 'NEW' | 'PLANNED' | 'REJECTED' | 'IMPLEMENTED'
export type IdeaSort = 'new' | 'top' | 'hot'
export type IdeaVoteType = 'up' | 'down'

export const IDEA_STATUS_LABELS: Record<IdeaStatus, string> = {
  NEW: 'Новая',
  PLANNED: 'Запланирована',
  REJECTED: 'Отклонена',
  IMPLEMENTED: 'Реализована',
}

export function formatIdeaStatus(status: IdeaStatus): string {
  return IDEA_STATUS_LABELS[status] ?? status
}

export interface IdeaTag {
  id: number
  name: string
}

export interface IdeaAttachment {
  id: number
  file_path: string
  mime_type: string
  url: string
  created_at: string
}

export interface IdeaComment {
  id: number
  idea_id: number
  user_id: number
  comment: string
  text: string
  author_nickname: string | null
  author_avatar: string | null
  created_at: string
}

export interface IdeaItem {
  id: number
  title: string
  description: string
  author_nickname: string | null
  user_id: number
  votes_up: number
  votes_down: number
  score: number
  views: number
  comments_count: number
  status: IdeaStatus
  tags: IdeaTag[]
  attachments: IdeaAttachment[]
  comments?: IdeaComment[]
  created_at: string
  updated_at: string
}

export interface IdeasListMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface IdeasListResponse {
  data: IdeaItem[]
  meta: IdeasListMeta
}

export interface ListIdeasParams {
  status?: IdeaStatus | ''
  tag?: string
  search?: string
  sort?: IdeaSort
  per_page?: number
  page?: number
}

export interface CreateIdeaPayload {
  title: string
  description: string
  tags?: string[]
  attachments?: File[]
}

function unpackResourceData<T>(payload: any): T {
  if (payload && typeof payload === 'object' && 'data' in payload && payload.data) {
    return payload.data as T
  }

  return payload as T
}

export const ideasApi = {
  async list(params: ListIdeasParams = {}): Promise<IdeasListResponse> {
    const sanitizedParams = Object.fromEntries(
      Object.entries(params).filter(([, value]) => value !== '' && value !== null && value !== undefined),
    )

    const { data } = await api.get('/api/ideas', { params: sanitizedParams })
    return {
      data: (data?.data ?? []) as IdeaItem[],
      meta: (data?.meta ?? { current_page: 1, last_page: 1, per_page: 20, total: 0 }) as IdeasListMeta,
    }
  },

  async get(id: number): Promise<IdeaItem> {
    const { data } = await api.get(`/api/ideas/${id}`)
    return unpackResourceData<IdeaItem>(data)
  },

  async create(payload: CreateIdeaPayload): Promise<IdeaItem> {
    const formData = new FormData()
    formData.append('title', payload.title)
    formData.append('description', payload.description)

    for (const tag of payload.tags ?? []) {
      formData.append('tags[]', tag)
    }

    for (const file of payload.attachments ?? []) {
      formData.append('attachments[]', file)
    }

    const { data } = await api.post('/api/ideas', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    })

    return unpackResourceData<IdeaItem>(data)
  },

  async vote(id: number, voteType: IdeaVoteType): Promise<IdeaItem> {
    const { data } = await api.post(`/api/ideas/${id}/vote`, { vote_type: voteType })
    return unpackResourceData<IdeaItem>(data)
  },

  async removeVote(id: number): Promise<IdeaItem> {
    const { data } = await api.delete(`/api/ideas/${id}/vote`)
    return unpackResourceData<IdeaItem>(data)
  },

  async addComment(id: number, comment: string): Promise<IdeaComment> {
    const { data } = await api.post(`/api/ideas/${id}/comments`, { comment })
    return unpackResourceData<IdeaComment>(data)
  },

  async deleteComment(ideaId: number, commentId: number): Promise<void> {
    await api.delete(`/api/ideas/${ideaId}/comments/${commentId}`)
  },

  async updateStatus(id: number, status: IdeaStatus): Promise<IdeaItem> {
    const { data } = await api.patch(`/api/ideas/${id}/status`, { status })
    return unpackResourceData<IdeaItem>(data)
  },

  async delete(id: number): Promise<void> {
    await api.delete(`/api/ideas/${id}`)
  },
}
