import axios, { type AxiosInstance, type AxiosProgressEvent } from 'axios'
import type {
  ExpertConversation,
  ExpertFinding,
  ExpertFindingStatus,
  ExpertFindingType,
  ExpertMaterialKind,
  ExpertMessage,
  ExpertProject,
  ExpertProjectDraft,
  ExpertProjectMaterial,
  ExpertProjectStatus,
  ExpertResearchObject,
} from './types'
import {
  expertDirectionValues,
  expertWorkTypeLabels,
  expertWorkTypeValues,
} from './options'
import { describeProjectMaterial, formatMaterialSize, safeMaterialDisplayName } from './materialPresentation'

export type ExpertCountsDto = {
  research_objects: number
  conversations: number
  materials: number
  findings: number
}
export type ExpertQuestionDto = { id: string; order: number; text: string }
export type ExpertResearchObjectDto = {
  public_id: string
  name: string
  type?: string | null
  description?: string | null
  sort_order: number
}
export type ExpertConversationDto = {
  public_id: string
  title: string
  messages_count?: number
  created_at?: string
  updated_at?: string
}
export type ExpertMessageDto = {
  public_id: string
  role: string
  content: string
  metadata?: Record<string, unknown> | null
  created_at: string
  updated_at?: string
}
export type ExpertChatReplyDto = {
  user_message: ExpertMessageDto
  assistant_message: ExpertMessageDto
}
export type ExpertMaterialDto = {
  public_id: string
  original_name: string
  mime_type: string
  extension: string
  size: number
  category: string
  status: string
  metadata?: Record<string, unknown> | null
  created_at: string
  updated_at?: string
}
export type ExpertFindingDto = {
  public_id: string
  type: ExpertFindingType
  title: string
  description?: string | null
  value?: string | null
  unit?: string | null
  status: string
  research_object?: ExpertResearchObjectDto | null
  materials?: ExpertMaterialDto[]
  created_at: string
  updated_at?: string
}
export type ExpertProjectDto = {
  public_id: string
  name: string
  domain: 'commodity' | 'construction' | 'other'
  work_type: string
  customer?: string | null
  object_summary?: string | null
  address?: string | null
  research_date?: string | null
  research_questions?: ExpertQuestionDto[] | null
  status: string
  counts?: ExpertCountsDto
  research_objects?: ExpertResearchObjectDto[]
  conversations?: ExpertConversationDto[]
  materials?: ExpertMaterialDto[]
  findings?: ExpertFindingDto[]
  created_at: string
  updated_at: string
}
export type ExpertCollection<T> = { data: T[] }
export type ExpertValidationErrors = Record<string, string[]>
export type ExpertApiError = { status?: number; code?: string; message: string; validationErrors: ExpertValidationErrors }
export type ExpertUploadOptions = { onProgress?: (progress: number) => void }
export type ExpertDownloadOptions = { onProgress?: (progress: number | null) => void }
export type ExpertFindingInput = {
  type: ExpertFindingType
  title: string
  description?: string | null
  value?: string | null
  unit?: string | null
  status?: 'ai_proposed' | 'expert_confirmed' | 'expert_rejected'
  research_object_public_id?: string | null
  material_public_ids?: string[]
}

const demoIds = new Set(['demo-commodity', 'demo-construction'])
export const isDemoProjectId = (id: string): boolean => demoIds.has(id)

const domainLabels = {
  commodity: 'Товароведческое',
  construction: 'Строительно-техническое',
  other: 'Иное',
} as const
const statusLabels: Record<string, ExpertProjectStatus> = {
  active: 'В работе',
  draft: 'Черновик',
  review: 'На проверке',
  completed: 'Завершено',
}
const findingTypeLabels: Record<ExpertFindingType, string> = {
  fact: 'Факт',
  measurement: 'Измерение',
  defect: 'Дефект',
  damage: 'Повреждение',
  non_compliance: 'Несоответствие',
  observation: 'Наблюдение',
  calculation: 'Расчёт',
  conclusion: 'Вывод',
}
const findingStatusLabels: Record<string, ExpertFindingStatus> = {
  ai_proposed: 'Предложено AI',
  expert_confirmed: 'Подтверждено экспертом',
  expert_rejected: 'Отклонено экспертом',
}

export function mapResearchObject(dto: ExpertResearchObjectDto): ExpertResearchObject {
  return {
    id: dto.public_id,
    name: dto.name,
    type: dto.type ?? undefined,
    description: dto.description ?? undefined,
    sortOrder: dto.sort_order,
  }
}

export function mapConversation(dto: ExpertConversationDto): ExpertConversation {
  return {
    id: dto.public_id,
    title: dto.title,
    messages: [],
    messagesCount: dto.messages_count ?? 0,
    createdAt: dto.created_at,
    updatedAt: dto.updated_at,
  }
}

export function mapMessage(dto: ExpertMessageDto): ExpertMessage {
  return {
    id: dto.public_id,
    role: dto.role === 'user' ? 'user' : 'assistant',
    text: dto.content,
    createdAt: dto.created_at,
  }
}

export function mapMaterial(dto: ExpertMaterialDto): ExpertProjectMaterial {
  const kind: ExpertMaterialKind = dto.mime_type.startsWith('image/')
    ? 'image'
    : dto.category === 'spreadsheet'
      ? 'spreadsheet'
      : dto.category === 'document'
        ? 'document'
        : 'other'
  const presentation = describeProjectMaterial({
    kind,
    format: dto.extension,
    mimeType: dto.mime_type,
  })
  return {
    id: dto.public_id,
    name: safeMaterialDisplayName(dto.original_name, dto.extension),
    kind: presentation.kind,
    format: presentation.format,
    meta: dto.mime_type,
    size: formatMaterialSize(dto.size),
    category: dto.category,
    status: dto.status === 'uploaded' ? 'Загружен' : dto.status === 'processing' ? 'Обрабатывается' : dto.status === 'failed' ? 'Ошибка' : 'Обработан',
    useInAi: false,
    icon: presentation.icon,
    mimeType: dto.mime_type,
    sizeBytes: dto.size,
    createdAt: dto.created_at,
  }
}

export function mapFinding(dto: ExpertFindingDto, index = 0): ExpertFinding {
  const value = dto.value ?? undefined
  const unit = dto.unit ?? undefined
  return {
    id: dto.public_id,
    number: index + 1,
    title: dto.title,
    type: dto.type,
    typeLabel: findingTypeLabels[dto.type] ?? dto.type,
    object: dto.research_object?.name ?? 'Объект не выбран',
    researchObjectId: dto.research_object?.public_id,
    description: dto.description ?? '',
    value,
    unit,
    measurement: value ? [value, unit].filter(Boolean).join(' ') : undefined,
    sources: [],
    materialIds: (dto.materials ?? []).map((item) => item.public_id),
    status: findingStatusLabels[dto.status] ?? 'Подтверждено экспертом',
  }
}

export function mapExpertProject(dto: ExpertProjectDto): ExpertProject {
  return {
    id: dto.public_id,
    title: dto.name,
    profile: dto.domain,
    direction: domainLabels[dto.domain],
    workType: expertWorkTypeLabels[dto.work_type] ?? dto.work_type,
    customer: dto.customer ?? '—',
    object: dto.object_summary ?? '—',
    address: dto.address ?? '—',
    researchDate: dto.research_date ?? '—',
    updatedAt: dto.updated_at,
    status: statusLabels[dto.status] ?? 'В работе',
    questions: [...(dto.research_questions ?? [])]
      .sort((a, b) => a.order - b.order)
      .map((item) => item.text),
    conversations: (dto.conversations ?? []).map(mapConversation),
    materials: (dto.materials ?? []).map(mapMaterial),
    researchObjects: (dto.research_objects ?? []).map(mapResearchObject),
    findings: (dto.findings ?? []).map(mapFinding),
    normatives: [],
    reportSections: [],
    revisions: [],
    quickActions: [],
    counts: dto.counts
      ? {
          researchObjects: dto.counts.research_objects,
          conversations: dto.counts.conversations,
          materials: dto.counts.materials,
          findings: dto.counts.findings,
        }
      : {
          researchObjects: dto.research_objects?.length ?? 0,
          conversations: dto.conversations?.length ?? 0,
          materials: dto.materials?.length ?? 0,
          findings: dto.findings?.length ?? 0,
        },
  }
}

export function toProjectPayload(draft: ExpertProjectDraft) {
  return {
    name: draft.title.trim(),
    domain: expertDirectionValues[draft.direction as keyof typeof expertDirectionValues] ?? 'other',
    work_type: expertWorkTypeValues[draft.workType] ?? 'other',
    customer: draft.customer.trim() || null,
    object_summary: draft.object.trim() || null,
    address: draft.address.trim() || null,
    research_date: draft.researchDate || null,
    research_questions: draft.questions
      .map((text) => text.trim())
      .filter(Boolean)
      .map((text, index) => ({ id: crypto.randomUUID(), order: index + 1, text })),
    initial_research_object: draft.object.trim()
      ? { name: draft.object.trim(), sort_order: 0 }
      : undefined,
  }
}

export function mapExpertApiError(error: unknown): ExpertApiError {
  if (!axios.isAxiosError(error))
    return { message: 'Не удалось выполнить запрос.', validationErrors: {} }
  const data = error.response?.data as
    | { message?: string; code?: string; errors?: ExpertValidationErrors }
    | undefined
  return {
    status: error.response?.status,
    code: data?.code,
    message:
      data?.message ??
      (error.response?.status === 404
        ? 'Проект не найден.'
        : 'Не удалось выполнить запрос.'),
    validationErrors: data?.errors ?? {},
  }
}

export function isExpertMaterialContextError(code?: string): boolean {
  return code === 'material_context_unsupported'
    || code === 'material_context_temporarily_disabled'
    || code === 'material_context_extraction_failed'
    || code === 'material_context_too_large'
    || code === 'material_context_not_found'
}

async function defaultHttp(): Promise<AxiosInstance> {
  return (await import('@/api/axios')).default
}

export function createExpertApi(http?: AxiosInstance) {
  const resolveHttp = async () => http ?? defaultHttp()
  return {
    async listProjects() {
      const { data } = await (await resolveHttp()).get<ExpertCollection<ExpertProjectDto>>(
        '/api/expert/projects',
      )
      return data.data.map(mapExpertProject)
    },
    async getProject(id: string) {
      const { data } = await (await resolveHttp()).get<ExpertProjectDto>(
        `/api/expert/projects/${encodeURIComponent(id)}`,
      )
      return mapExpertProject(data)
    },
    async createProject(draft: ExpertProjectDraft) {
      const { data } = await (await resolveHttp()).post<ExpertProjectDto>(
        '/api/expert/projects',
        toProjectPayload(draft),
      )
      return mapExpertProject(data)
    },
    async listResearchObjects(projectId: string) {
      const { data } = await (await resolveHttp()).get<ExpertCollection<ExpertResearchObjectDto>>(
        `/api/expert/projects/${encodeURIComponent(projectId)}/research-objects`,
      )
      return data.data.map(mapResearchObject)
    },
    async createResearchObject(
      projectId: string,
      input: { name: string; type?: string; description?: string },
    ) {
      const { data } = await (await resolveHttp()).post<ExpertResearchObjectDto>(
        `/api/expert/projects/${encodeURIComponent(projectId)}/research-objects`,
        input,
      )
      return mapResearchObject(data)
    },
    async updateResearchObject(
      id: string,
      input: { name?: string; type?: string | null; description?: string | null },
    ) {
      const { data } = await (await resolveHttp()).patch<ExpertResearchObjectDto>(
        `/api/expert/research-objects/${encodeURIComponent(id)}`,
        input,
      )
      return mapResearchObject(data)
    },
    async deleteResearchObject(id: string) {
      await (await resolveHttp()).delete(
        `/api/expert/research-objects/${encodeURIComponent(id)}`,
      )
    },
    async listConversations(projectId: string) {
      const { data } = await (await resolveHttp()).get<ExpertCollection<ExpertConversationDto>>(
        `/api/expert/projects/${encodeURIComponent(projectId)}/conversations`,
      )
      return data.data.map(mapConversation)
    },
    async createConversation(projectId: string, title: string) {
      const { data } = await (await resolveHttp()).post<ExpertConversationDto>(
        `/api/expert/projects/${encodeURIComponent(projectId)}/conversations`,
        { title },
      )
      return mapConversation(data)
    },
    async listMessages(conversationId: string) {
      const { data } = await (await resolveHttp()).get<ExpertCollection<ExpertMessageDto>>(
        `/api/expert/conversations/${encodeURIComponent(conversationId)}/messages`,
      )
      return data.data.map(mapMessage)
    },
    async sendMessage(
      conversationId: string,
      content: string,
      clientMessageId: string,
      materialPublicIds: string[] = [],
    ) {
      const payload = materialPublicIds.length
        ? { content, material_public_ids: materialPublicIds }
        : { content }
      const { data } = await (await resolveHttp()).post<ExpertChatReplyDto>(
        `/api/expert/conversations/${encodeURIComponent(conversationId)}/messages`,
        payload,
        { headers: { 'X-Expert-Message-Id': clientMessageId } },
      )
      return {
        userMessage: mapMessage(data.user_message),
        assistantMessage: mapMessage(data.assistant_message),
      }
    },
    async listMaterials(projectId: string) {
      const { data } = await (await resolveHttp()).get<ExpertCollection<ExpertMaterialDto>>(
        `/api/expert/projects/${encodeURIComponent(projectId)}/materials`,
      )
      return data.data.map(mapMaterial)
    },
    async uploadMaterial(projectId: string, file: File, options: ExpertUploadOptions = {}) {
      const form = new FormData()
      form.append('file', file)
      const { data } = await (await resolveHttp()).post<ExpertMaterialDto>(
        `/api/expert/projects/${encodeURIComponent(projectId)}/materials`,
        form,
        {
          headers: { 'Content-Type': 'multipart/form-data' },
          onUploadProgress: (event: AxiosProgressEvent) => options.onProgress?.(toProgress(event)),
        },
      )
      return mapMaterial(data)
    },
    async downloadMaterial(id: string, options: ExpertDownloadOptions = {}) {
      const response = await (await resolveHttp()).get<Blob>(
        `/api/expert/materials/${encodeURIComponent(id)}/download`,
        {
          responseType: 'blob',
          onDownloadProgress: (event: AxiosProgressEvent) => options.onProgress?.(toOptionalProgress(event)),
        },
      )
      return response.data
    },
    async getMaterialImageContent(id: string) {
      const response = await (await resolveHttp()).get<Blob>(
        `/api/expert/materials/${encodeURIComponent(id)}/content`,
        { responseType: 'blob' },
      )
      return response.data
    },
    async deleteMaterial(id: string) {
      await (await resolveHttp()).delete(`/api/expert/materials/${encodeURIComponent(id)}`)
    },
    async listFindings(projectId: string) {
      const { data } = await (await resolveHttp()).get<ExpertCollection<ExpertFindingDto>>(
        `/api/expert/projects/${encodeURIComponent(projectId)}/findings`,
      )
      return data.data.map(mapFinding)
    },
    async createFinding(projectId: string, input: ExpertFindingInput) {
      const { data } = await (await resolveHttp()).post<ExpertFindingDto>(
        `/api/expert/projects/${encodeURIComponent(projectId)}/findings`,
        input,
      )
      return mapFinding(data)
    },
    async updateFinding(id: string, input: Partial<ExpertFindingInput>) {
      const { data } = await (await resolveHttp()).patch<ExpertFindingDto>(
        `/api/expert/findings/${encodeURIComponent(id)}`,
        input,
      )
      return mapFinding(data)
    },
    async deleteFinding(id: string) {
      await (await resolveHttp()).delete(`/api/expert/findings/${encodeURIComponent(id)}`)
    },
  }
}

export const expertApi = createExpertApi()

function toProgress(event: AxiosProgressEvent): number {
  return event.total && event.total > 0
    ? Math.min(100, Math.round((event.loaded / event.total) * 100))
    : 0
}

function toOptionalProgress(event: AxiosProgressEvent): number | null {
  return event.total && event.total > 0 ? toProgress(event) : null
}
