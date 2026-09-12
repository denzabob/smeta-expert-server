export const expertDirections: string[] = ['Товароведческое', 'Строительно-техническое', 'Иное']

export const expertWorkTypes: string[] = [
  'Досудебное исследование',
  'Судебная экспертиза',
  'Заключение специалиста',
  'Рецензия',
  'Акт осмотра / обследования',
  'Отчёт',
  'Иное',
]

export const expertDirectionValues = {
  Товароведческое: 'commodity',
  'Строительно-техническое': 'construction',
  Иное: 'other',
} as const

export const expertWorkTypeLabels: Record<string, string> = {
  pretrial_research: 'Досудебное исследование',
  court_expertise: 'Судебная экспертиза',
  specialist_opinion: 'Заключение специалиста',
  review: 'Рецензия',
  inspection_act: 'Акт осмотра / обследования',
  report: 'Отчёт',
  other: 'Иное',
}

export const expertWorkTypeValues: Record<string, string> = Object.fromEntries(
  Object.entries(expertWorkTypeLabels).map(([value, label]) => [label, value]),
)
