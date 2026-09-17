export type ExpertDomainProfile = 'commodity' | 'construction' | 'other'

export type ExpertProjectStatus = 'В работе' | 'Черновик' | 'На проверке' | 'Завершено'

export interface ExpertSource {
  id: string
  label: string
  detail?: string
  icon?: string
}

export interface ExpertMessage {
  id: string
  role: 'user' | 'assistant'
  text: string
  createdAt: string
  metadata?: Record<string, unknown>
  generationStatus?: 'completed' | 'stopped' | 'interrupted'
  deliveryState?: ExpertMessageDeliveryState
  deliveryError?: string
  clientMessageId?: string
  runtimeMaterialContext?: ExpertMessageMaterialContext[]
  sources?: ExpertSource[]
}

export type ExpertMessageDeliveryState = 'sending' | 'sent' | 'error'

export interface ExpertMessageMaterialContext {
  id: string
  name: string
  kind: ExpertMaterialKind
  format: string
  icon: string
}

export interface ExpertConversation {
  id: string
  title: string
  messages: ExpertMessage[]
  messagesCount?: number
  createdAt?: string
  updatedAt?: string
}

export type ExpertMaterialKind = 'document' | 'image' | 'spreadsheet' | 'video' | 'other'
export type ExpertMaterialStatus = 'Загружен' | 'Обработан' | 'Обрабатывается' | 'Ошибка'

export interface ExpertProjectMaterial {
  id: string
  name: string
  kind: ExpertMaterialKind
  format: string
  meta: string
  size: string
  pages?: number
  category: string
  status: ExpertMaterialStatus
  useInAi: boolean
  icon: string
  mimeType?: string
  sizeBytes?: number
  createdAt?: string
}

export interface ExpertResearchObject {
  id: string
  name: string
  type?: string
  description?: string
  sortOrder?: number
}

export type ExpertProjectMode = 'demo' | 'real'
export interface ExpertProjectCounts { researchObjects: number; conversations: number; materials: number; findings: number }

export type ExpertFindingType =
  | 'fact'
  | 'measurement'
  | 'defect'
  | 'damage'
  | 'non_compliance'
  | 'observation'
  | 'calculation'
  | 'conclusion'

export type ExpertFindingStatus = 'Предложено AI' | 'Подтверждено экспертом' | 'Отклонено экспертом'

export interface ExpertFinding {
  id: string
  number: number
  title: string
  type: ExpertFindingType
  typeLabel: string
  object: string
  researchObjectId?: string
  description: string
  factualData?: string
  measurement?: string
  value?: string
  unit?: string
  cause?: string
  normativeComparison?: string
  recommendation?: string
  calculation?: string
  sources: ExpertSource[]
  materialIds?: string[]
  normativeReferenceIds?: string[]
  images?: string[]
  status: ExpertFindingStatus
}

export type ExpertNormativeStatus = 'Действующий' | 'Требует проверки'

export interface ExpertNormative {
  id: string
  code: string
  title: string
  applicability: string
  connected: boolean
  recommended?: boolean
  recommendationReason?: string
  status?: ExpertNormativeStatus
}

export type ExpertReportEntityType =
  | 'finding'
  | 'measurement'
  | 'research_object'
  | 'normative'
  | 'material'
  | 'conclusion'

export interface ExpertReportEntityReference {
  entityType: ExpertReportEntityType
  entityId: string
}

export interface ExpertReportSection {
  id: string
  number: number
  title: string
  content: string
  entityReferences?: ExpertReportEntityReference[]
}

export interface ExpertReportRevision {
  id: string
  label: string
  createdAt: string
  current?: boolean
  description?: string
}

export interface ExpertProject {
  id: string
  title: string
  profile: ExpertDomainProfile
  direction: string
  workType: string
  customer: string
  object: string
  address: string
  researchDate: string
  updatedAt: string
  status: ExpertProjectStatus
  questions: string[]
  conversations: ExpertConversation[]
  materials: ExpertProjectMaterial[]
  researchObjects: ExpertResearchObject[]
  findings: ExpertFinding[]
  normatives: ExpertNormative[]
  reportSections: ExpertReportSection[]
  revisions: ExpertReportRevision[]
  quickActions: string[]
  counts?: ExpertProjectCounts
}

export interface ExpertProjectDraft {
  title: string
  direction: string
  workType: string
  customer: string
  object: string
  address: string
  researchDate: string
  questions: string[]
}
