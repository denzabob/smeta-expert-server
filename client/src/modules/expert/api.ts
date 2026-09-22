import axios, { type AxiosInstance, type AxiosProgressEvent } from 'axios'
import type {
  ExpertConversation,
  ExpertFinding,
  ExpertFindingStatus,
  ExpertFindingType,
  ExpertMaterialKind,
  ExpertMessage,
  ExpertMessageFeedback,
  ExpertMessageAttachment,
  ExpertProject,
  ExpertProjectDraft,
  ExpertProjectMaterial,
  ExpertProjectStatus,
  ExpertResearchObject,
  ExpertChatMode,
} from './types'
import {
  expertDirectionValues,
  expertWorkTypeLabels,
  expertWorkTypeValues,
} from './options'
import { describeProjectMaterial, formatMaterialSize, safeMaterialDisplayName } from './materialPresentation'
import { parseExpertSseStream } from './chatStreaming'
import type { ExpertReasoningSummaryEvent, ExpertRunActivity } from './chatTimeline'

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
  last_message_at?: string | null
  created_at?: string
  updated_at?: string
}
export type ExpertMessageDto = {
  public_id: string
  role: string
  content: string
  metadata?: Record<string, unknown> | null
  feedback?: { rating: 'positive' | 'negative'; reason_code?: string | null; comment?: string | null } | null
  attachments?: { material_public_id: string; original_name: string; mime_type: string; size: number; kind: ExpertMaterialKind; available: boolean }[]
  created_at: string
  updated_at?: string
}
export type ExpertChatReplyDto = {
  user_message: ExpertMessageDto
  assistant_message: ExpertMessageDto
}
export type ExpertStreamHandlers = {
  onRun: (runId: string, userMessage: ExpertMessage) => void
  onDelta: (runId: string, seq: number, text: string) => void
  onActivity?: (activity: ExpertRunActivity) => void
  onReasoningSummary?: (summary: ExpertReasoningSummaryEvent) => void
  onDone: (assistantMessage?: ExpertMessage) => void
  onCancelled: (assistantMessage?: ExpertMessage) => void
  onError: (error: ExpertApiError, assistantMessage?: ExpertMessage) => void
}

export class ExpertStreamApiError extends Error {
  public readonly mapped: ExpertApiError
  public constructor(mapped: ExpertApiError) { super(mapped.message); this.mapped = mapped }
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
export type ExpertRunDiagnostic = {
  runId: string
  errorCode: string
  retryable: boolean
  lastActivityCode?: string
}
export type ExpertApiError = { status?: number; code?: string; message: string; validationErrors: ExpertValidationErrors; diagnostic?: ExpertRunDiagnostic }
const streamErrorMessages: Record<string, string> = {
  provider_auth_failed: 'Провайдер AI недоступен из-за настройки доступа.',
  provider_model_not_found: 'Выбранная модель AI недоступна.',
  provider_validation_failed: 'Провайдер AI отклонил запрос.',
  provider_rate_limited: 'Провайдер AI временно ограничил запросы.',
  provider_timeout: 'Время ожидания ответа AI истекло.',
  provider_connection_failed: 'Не удалось подключиться к провайдеру AI.',
  provider_server_error: 'Провайдер AI временно недоступен.',
  provider_unexpected_content_type: 'Провайдер AI вернул ответ в неожиданном формате.',
  stream_malformed: 'Провайдер AI вернул некорректный поток.',
  stream_eof_without_terminal: 'Ответ AI оборвался до завершения.',
  streaming_not_supported: 'Не удалось получить ответ AI. Повторите запрос.',
  pdf_ocr_failed: 'Не удалось обработать документ. Повторите запрос.',
  pdf_ocr_not_supported: 'Не удалось обработать документ. Повторите запрос.',
  pdf_processing_too_large: 'Документ превышает допустимый объём обработки.',
  vision_not_supported: 'Не удалось обработать изображение. Повторите запрос.',
  material_not_supported: 'Не удалось обработать приложенный материал.',
}
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

function isMessageDto(value: unknown): value is ExpertMessageDto {
  return value !== null
    && typeof value === 'object'
    && typeof (value as Record<string, unknown>).public_id === 'string'
    && typeof (value as Record<string, unknown>).content === 'string'
    && typeof (value as Record<string, unknown>).role === 'string'
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
    lastMessageAt: dto.last_message_at ?? undefined,
    createdAt: dto.created_at,
    updatedAt: dto.updated_at,
  }
}

export function mapMessage(dto: ExpertMessageDto): ExpertMessage {
  const metadata = dto.metadata ?? undefined
  const generationStatus = metadata?.generation_status
  const attachments: ExpertMessageAttachment[] = (dto.attachments ?? []).map((attachment) => ({
    id: attachment.material_public_id,
    name: safeMaterialDisplayName(attachment.original_name, attachment.original_name.split('.').pop() ?? ''),
    mimeType: attachment.mime_type,
    sizeBytes: attachment.size,
    kind: attachment.kind,
    available: attachment.available,
    icon: describeProjectMaterial({ kind: attachment.kind, format: attachment.original_name.split('.').pop() ?? '', mimeType: attachment.mime_type }).icon,
  }))
  return {
    id: dto.public_id,
    role: dto.role === 'user' ? 'user' : 'assistant',
    text: dto.content,
    createdAt: dto.created_at,
    metadata,
    feedback: dto.feedback ? { rating: dto.feedback.rating, reasonCode: dto.feedback.reason_code, comment: dto.feedback.comment } : null,
    attachments,
    clientMessageId: typeof metadata?.client_message_id === 'string' ? metadata.client_message_id : undefined,
    generationStatus: generationStatus === 'completed' || generationStatus === 'stopped' || generationStatus === 'interrupted' ? generationStatus : undefined,
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
  if (error instanceof ExpertStreamApiError) return error.mapped
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
    || code === 'pdf_no_usable_text'
    || code === 'pdf_local_extraction_failed'
    || code === 'pdf_encrypted'
    || code === 'pdf_malformed'
    || code === 'material_context_too_large'
    || code === 'material_context_not_found'
    || code === 'material_not_supported'
    || code === 'vision_not_supported'
    || code === 'pdf_ocr_not_supported'
    || code === 'pdf_ocr_disabled'
    || code === 'pdf_ocr_too_large'
    || code === 'pdf_processing_too_large'
    || code === 'pdf_ocr_too_many_pages'
    || code === 'pdf_ocr_failed'
    || code === 'pdf_ocr_cache_invalid'
    || code === 'vision_material_invalid'
    || code === 'vision_material_too_large'
    || code === 'vision_too_many_images'
    || code === 'vision_preparation_failed'
}

function mapStreamActivity(payload: Record<string, unknown>): ExpertRunActivity | null {
  const status = payload.status
  if (payload.version !== 1
    || typeof payload.run_id !== 'string'
    || !Number.isSafeInteger(payload.seq)
    || (payload.seq as number) < 1
    || typeof payload.activity_id !== 'string'
    || typeof payload.code !== 'string'
    || typeof payload.category !== 'string'
    || !['started', 'completed', 'skipped', 'failed'].includes(String(status))) return null

  return {
    runId: payload.run_id,
    seq: payload.seq as number,
    activityId: payload.activity_id,
    code: payload.code,
    status: status as ExpertRunActivity['status'],
    category: payload.category,
    detail: safeStreamActivityDetail(payload.detail),
  }
}

function safeStreamDiagnosticValue(value: unknown, pattern: RegExp, maxLength: number): string | undefined {
  return typeof value === 'string' && value.length <= maxLength && pattern.test(value) ? value : undefined
}

function mapStreamError(payload: Record<string, unknown>): ExpertApiError {
  const errorCode = safeStreamDiagnosticValue(payload.error_code, /^[a-z][a-z0-9_]{0,99}$/i, 100)
    ?? safeStreamDiagnosticValue(payload.code, /^[a-z][a-z0-9_]{0,99}$/i, 100)
    ?? 'expert_stream_interrupted'
  const runId = safeStreamDiagnosticValue(payload.run_id, /^[a-z0-9_-]{1,100}$/i, 100)
  const lastActivityCode = safeStreamDiagnosticValue(payload.last_activity_code, /^[a-z][a-z0-9._-]{0,99}$/i, 100)

  return {
    code: errorCode,
    message: streamErrorMessages[errorCode] ?? 'Потоковый ответ AI прерван.',
    validationErrors: {},
    diagnostic: runId
      ? { runId, errorCode, retryable: payload.retryable === true, ...(lastActivityCode ? { lastActivityCode } : {}) }
      : undefined,
  }
}

function mapReasoningSummary(payload: Record<string, unknown>): ExpertReasoningSummaryEvent | null {
  if (payload.version !== 1
    || typeof payload.run_id !== 'string'
    || !Number.isSafeInteger(payload.seq)
    || (payload.seq as number) < 1
    || typeof payload.text !== 'string'
    || (payload.final !== undefined && typeof payload.final !== 'boolean')) return null

  return {
    runId: payload.run_id,
    seq: payload.seq as number,
    text: payload.text.slice(0, 4000),
    final: payload.final === true,
  }
}

function safeStreamActivityDetail(value: unknown): string | undefined {
  if (typeof value !== 'string') return undefined
  const segments = value.replace(/\\/g, '/').split('/')
  const filename = (segments[segments.length - 1] ?? '').replace(/[\u0000-\u001F\u007F]/g, '').trim()
  return filename ? filename.slice(0, 180) : undefined
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
    async updateConversation(id: string, title: string) {
      const { data } = await (await resolveHttp()).patch<ExpertConversationDto>(
        `/api/expert/conversations/${encodeURIComponent(id)}`,
        { title },
      )
      return mapConversation(data)
    },
    async deleteConversation(id: string) {
      await (await resolveHttp()).delete(`/api/expert/conversations/${encodeURIComponent(id)}`)
    },
    async getConversationContext(projectId: string, conversationId: string) {
      const { data } = await (await resolveHttp()).get<{ active_materials: Array<{ id: string; name: string; mime_type: string }> }>(
        `/api/expert/projects/${encodeURIComponent(projectId)}/conversations/${encodeURIComponent(conversationId)}/context`,
      )
      return data.active_materials
    },
    async updateConversationContext(projectId: string, conversationId: string, ids: string[]) {
      const { data } = await (await resolveHttp()).put<{ active_materials: Array<{ id: string; name: string; mime_type: string }> }>(
        `/api/expert/projects/${encodeURIComponent(projectId)}/conversations/${encodeURIComponent(conversationId)}/context`,
        { active_material_ids: ids },
      )
      return data.active_materials
    },
    async listMessages(conversationId: string) {
      const { data } = await (await resolveHttp()).get<ExpertCollection<ExpertMessageDto>>(
        `/api/expert/conversations/${encodeURIComponent(conversationId)}/messages`,
      )
      return data.data.map(mapMessage)
    },
    async saveMessageFeedback(messageId: string, input: ExpertMessageFeedback) {
      const { data } = await (await resolveHttp()).put<NonNullable<ExpertMessageDto['feedback']>>(
        `/api/expert/messages/${encodeURIComponent(messageId)}/feedback`,
        { rating: input.rating, reason_code: input.reasonCode ?? null, comment: input.comment ?? null },
      )
      return { rating: data.rating, reasonCode: data.reason_code, comment: data.comment } satisfies ExpertMessageFeedback
    },
    async deleteMessageFeedback(messageId: string) {
      await (await resolveHttp()).delete(`/api/expert/messages/${encodeURIComponent(messageId)}/feedback`)
    },
    async sendMessage(
      conversationId: string,
      content: string,
      clientMessageId: string,
      materialPublicIds: string[] = [],
      mode: ExpertChatMode = 'auto',
    ) {
      const payload = materialPublicIds.length
        ? { content, mode, material_public_ids: materialPublicIds }
        : { content, mode }
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
    async streamMessage(
      conversationId: string,
      content: string,
      clientMessageId: string,
      materialPublicIds: string[],
      handlers: ExpertStreamHandlers,
      signal?: AbortSignal,
      assistantId?: string,
      mode: ExpertChatMode = 'auto',
    ) {
      const instance = await resolveHttp()
      const path = assistantId
        ? `/api/expert/conversations/${encodeURIComponent(conversationId)}/messages/${encodeURIComponent(assistantId)}/continue/stream`
        : `/api/expert/conversations/${encodeURIComponent(conversationId)}/messages/stream`
      const csrf = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length)
      const response = await fetch(instance.getUri({ url: path }), {
        method: 'POST',
        credentials: 'include',
        signal,
        headers: {
          Accept: 'text/event-stream',
          'Content-Type': 'application/json',
          'X-Expert-Message-Id': clientMessageId,
          ...(csrf ? { 'X-XSRF-TOKEN': decodeURIComponent(csrf) } : {}),
        },
        body: JSON.stringify(assistantId ? {} : materialPublicIds.length ? { content, mode, material_public_ids: materialPublicIds } : { content, mode }),
      })
      if (!response.ok || !response.body) {
        let data: { message?: string; code?: string; errors?: ExpertValidationErrors } = {}
        try { data = await response.json() as typeof data } catch { /* stable fallback below */ }
        throw new ExpertStreamApiError({ status: response.status, code: data.code, message: data.message ?? 'Не удалось начать потоковый ответ.', validationErrors: data.errors ?? {} })
      }
      let runId = ''
      let terminalReceived = false
      for await (const event of parseExpertSseStream(response.body)) {
        if (terminalReceived) continue
        const payload = event.data
        if (event.event === 'run' && typeof payload.run_id === 'string' && isMessageDto(payload.user_message)) {
          runId = payload.run_id
          handlers.onRun(runId, mapMessage(payload.user_message))
        } else if (event.event === 'delta' && runId && typeof payload.seq === 'number' && typeof payload.text === 'string') {
          handlers.onDelta(runId, payload.seq, payload.text)
        } else if (event.event === 'activity' && runId) {
          const activity = mapStreamActivity(payload)
          if (activity && activity.runId === runId) handlers.onActivity?.(activity)
        } else if (event.event === 'reasoning_summary' && runId) {
          const summary = mapReasoningSummary(payload)
          if (summary && summary.runId === runId) handlers.onReasoningSummary?.(summary)
        } else if (event.event === 'done') {
          terminalReceived = true
          handlers.onDone(isMessageDto(payload.assistant_message) ? mapMessage(payload.assistant_message) : undefined)
          break
        } else if (event.event === 'cancelled') {
          terminalReceived = true
          handlers.onCancelled(isMessageDto(payload.assistant_message) ? mapMessage(payload.assistant_message) : undefined)
          break
        } else if (event.event === 'error') {
          terminalReceived = true
          handlers.onError(mapStreamError(payload), isMessageDto(payload.assistant_message) ? mapMessage(payload.assistant_message) : undefined)
          break
        }
      }
      if (!terminalReceived) throw new ExpertStreamApiError({ code: 'stream_eof_without_terminal', message: 'Ответ AI оборвался до завершения.', validationErrors: {} })
    },
    async cancelStream(conversationId: string, runId: string) {
      await (await resolveHttp()).post(`/api/expert/conversations/${encodeURIComponent(conversationId)}/runs/${encodeURIComponent(runId)}/cancel`)
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
    async getMaterialThumbnail(id: string) {
      const response = await (await resolveHttp()).get<Blob>(
        `/api/expert/materials/${encodeURIComponent(id)}/thumbnail`,
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
