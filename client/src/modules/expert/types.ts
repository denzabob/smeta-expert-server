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
  sources?: ExpertSource[]
}

export interface ExpertConversation {
  id: string
  title: string
  messages: ExpertMessage[]
}

export type ExpertMaterialKind = 'document' | 'image' | 'spreadsheet' | 'video' | 'other'
export type ExpertMaterialStatus = 'Обработан' | 'Обрабатывается' | 'Ошибка'

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
}

export type ExpertFindingType =
  | 'fact'
  | 'measurement'
  | 'defect'
  | 'damage'
  | 'non_compliance'
  | 'observation'
  | 'calculation'
  | 'conclusion'

export type ExpertFindingStatus = 'Предложено AI' | 'Подтверждено экспертом' | 'Отклонено'

export interface ExpertFinding {
  id: string
  number: number
  title: string
  type: ExpertFindingType
  typeLabel: string
  object: string
  description: string
  factualData?: string
  measurement?: string
  cause?: string
  normativeComparison?: string
  recommendation?: string
  calculation?: string
  sources: ExpertSource[]
  images?: string[]
  status: ExpertFindingStatus
}

export interface ExpertNormative {
  id: string
  code: string
  title: string
  applicability: string
  connected: boolean
  recommended?: boolean
}

export interface ExpertReportSection {
  id: string
  number: number
  title: string
  content: string
}

export interface ExpertReportRevision {
  id: string
  label: string
  createdAt: string
  current?: boolean
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
  findings: ExpertFinding[]
  normatives: ExpertNormative[]
  reportSections: ExpertReportSection[]
  revisions: ExpertReportRevision[]
  quickActions: string[]
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
