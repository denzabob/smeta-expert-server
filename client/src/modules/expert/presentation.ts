import type { ExpertFindingType, ExpertProject } from './types'

export type ExpertResearchTabValue = ExpertFindingType | 'all'

export interface ExpertResearchTab {
  label: string
  value: ExpertResearchTabValue
}

export const expertResearchTabs: ExpertResearchTab[] = [
  { label: 'Результаты исследования', value: 'all' },
  { label: 'Факты', value: 'fact' },
  { label: 'Измерения', value: 'measurement' },
  { label: 'Дефекты', value: 'defect' },
  { label: 'Повреждения', value: 'damage' },
  { label: 'Несоответствия', value: 'non_compliance' },
  { label: 'Наблюдения', value: 'observation' },
  { label: 'Расчёты', value: 'calculation' },
  { label: 'Выводы', value: 'conclusion' },
]

export type ExpertProjectReadinessState = 'complete' | 'in_progress' | 'draft'

export interface ExpertProjectReadinessItem {
  label: string
  value: string
  state: ExpertProjectReadinessState
}

export function getExpertProjectReadiness(project: ExpertProject): ExpertProjectReadinessItem[] {
  const hasMaterials = project.materials.length > 0
  const hasResearchObjects = project.researchObjects.length > 0

  return [
    { label: 'Материалы', value: hasMaterials ? 'Готово' : 'Нет материалов', state: hasMaterials ? 'complete' : 'draft' },
    { label: 'Объекты исследования', value: hasResearchObjects ? 'Готово' : 'Не определены', state: hasResearchObjects ? 'complete' : 'draft' },
    { label: 'Исходные данные', value: hasMaterials ? 'Готово' : 'В ожидании', state: hasMaterials ? 'complete' : 'draft' },
    { label: 'Исследование', value: 'В работе', state: 'in_progress' },
    { label: 'Ответы на вопросы', value: '2 / ' + project.questions.length, state: 'in_progress' },
    { label: 'Заключение', value: 'Черновик', state: 'draft' },
  ]
}
