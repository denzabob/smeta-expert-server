import type { ExpertProject } from '../types'

const commodityProject: ExpertProject = {
  id: 'demo-commodity',
  title: 'Соколова С.В. — кухонный гарнитур',
  profile: 'commodity',
  direction: 'Товароведческое',
  workType: 'Досудебное исследование',
  customer: 'Соколова Светлана Владимировна',
  object: 'Кухонный гарнитур по договору № 144',
  address: 'г. Екатеринбург, ул. Примерная, 12',
  researchDate: '12 сентября 2026',
  updatedAt: 'Сегодня, 21:35',
  status: 'В работе',
  questions: [
    'Имеются ли недостатки объекта исследования?',
    'Каковы причины их возникновения?',
    'Какова стоимость устранения?',
  ],
  quickActions: [
    'Проанализировать материалы',
    'Выделить ключевые факты',
    'Проверить документы на противоречия',
    'Подобрать применимые нормативы',
    'Начать исследовательскую часть',
  ],
  conversations: [
    {
      id: 'general-analysis',
      title: 'Общий анализ',
      messages: [
        {
          id: 'm-1',
          role: 'user',
          text: 'Проанализируй материалы проекта и выдели основные обстоятельства, значимые для исследования.',
          createdAt: '21:28',
        },
        {
          id: 'm-2',
          role: 'assistant',
          text: 'По представленным материалам можно выделить три значимых обстоятельства: условия договора и согласованную спецификацию, зафиксированные при осмотре отклонения межфасадных зазоров, а также расхождения между заявленным и фактическим качеством монтажа. Для окончательного вывода требуется сопоставить результаты замеров с требованиями подключённых нормативов.',
          createdAt: '21:29',
          sources: [
            { id: 's-1', label: 'Договор №144', detail: 'стр. 7', icon: 'mdi-file-pdf-box' },
            { id: 's-2', label: 'Акт осмотра', detail: 'стр. 4', icon: 'mdi-file-document-outline' },
            { id: 's-3', label: 'Фото №32–38', detail: '7 изображений', icon: 'mdi-image-multiple-outline' },
          ],
        },
      ],
    },
    { id: 'contract-analysis', title: 'Анализ договора', messages: [] },
  ],
  materials: [
    { id: 'mat-1', name: 'Договор №144.pdf', kind: 'document', format: 'PDF', meta: '12 страниц', size: '4,8 МБ', pages: 12, category: 'Договор', status: 'Обработан', useInAi: true, icon: 'mdi-file-pdf-box' },
    { id: 'mat-2', name: 'Спецификация.pdf', kind: 'document', format: 'PDF', meta: '7 страниц', size: '2,1 МБ', pages: 7, category: 'Исходные данные', status: 'Обработан', useInAi: true, icon: 'mdi-file-pdf-box' },
    { id: 'mat-3', name: 'Акт осмотра.docx', kind: 'document', format: 'DOCX', meta: 'Документ', size: '890 КБ', pages: 6, category: 'Осмотр', status: 'Обработан', useInAi: true, icon: 'mdi-file-word-outline' },
    { id: 'mat-4', name: 'Замеры.xlsx', kind: 'spreadsheet', format: 'XLSX', meta: '3 листа', size: '360 КБ', category: 'Измерения', status: 'Обработан', useInAi: true, icon: 'mdi-file-excel-outline' },
    { id: 'mat-5', name: 'Фото осмотра', kind: 'image', format: 'JPG', meta: '83 изображения', size: '148 МБ', category: 'Фотоматериалы', status: 'Обрабатывается', useInAi: true, icon: 'mdi-image-multiple-outline' },
  ],
  researchObjects: [
    { id: 'commodity-kitchen-set', name: 'Кухонный гарнитур', type: 'Изделие', description: 'Комплект мебели по договору № 144.' },
    { id: 'commodity-facade-group', name: 'Фасадная группа', type: 'Часть изделия', description: 'Фасады и межфасадные зазоры кухонного гарнитура.' },
    { id: 'commodity-wall-cabinet', name: 'Навесной шкаф', type: 'Часть изделия', description: 'Навесной модуль кухонного гарнитура.' },
  ],
  findings: [
    {
      id: 'finding-1', number: 1, title: 'Неравномерность межфасадных зазоров', type: 'defect', typeLabel: 'Дефект', object: 'Фасадная группа', researchObjectId: 'commodity-facade-group',
      description: 'Зафиксирована визуально различимая неравномерность межфасадных зазоров.', factualData: 'Отклонение подтверждается результатами контрольных замеров.', measurement: '2,5–6,5 мм', cause: 'Требует дополнительного исследования монтажа и регулировки.', normativeComparison: 'Сопоставление с ГОСТ 16371-2014 подготовлено к проверке экспертом.', recommendation: 'Проверить регулировку фасадов и геометрию корпуса.',
      sources: [{ id: 's-2', label: 'Акт осмотра', detail: 'стр. 4' }, { id: 's-3', label: 'Фото №32–38' }], materialIds: ['mat-3', 'mat-4', 'mat-5'], normativeReferenceIds: ['n-1'], status: 'Подтверждено экспертом', images: ['Фото №32', 'Фото №35'],
    },
    {
      id: 'finding-2', number: 2, title: 'Расхождение комплектации', type: 'non_compliance', typeLabel: 'Несоответствие', object: 'Кухонный гарнитур', researchObjectId: 'commodity-kitchen-set',
      description: 'Фактическая комплектация отличается от перечня в спецификации.', factualData: 'Не представлен один из согласованных элементов.',
      sources: [{ id: 's-1', label: 'Договор №144', detail: 'приложение 1' }], materialIds: ['mat-1', 'mat-2'], normativeReferenceIds: ['n-1'], status: 'Предложено AI',
    },
    {
      id: 'finding-3', number: 3, title: 'Скол покрытия фасада', type: 'damage', typeLabel: 'Повреждение', object: 'Фасадная группа', researchObjectId: 'commodity-facade-group',
      description: 'На лицевой поверхности фасада обнаружен локальный скол защитно-декоративного покрытия.',
      sources: [{ id: 's-4', label: 'Фото осмотра', detail: 'Фото №41' }], materialIds: ['mat-5'], normativeReferenceIds: ['n-1'], status: 'Подтверждено экспертом', images: ['Фото №41'],
    },
    {
      id: 'finding-4', number: 4, title: 'Следы неравномерности тона покрытия', type: 'observation', typeLabel: 'Наблюдение', object: 'Навесной шкаф', researchObjectId: 'commodity-wall-cabinet',
      description: 'AI предложил проверить различие оттенка покрытия; при осмотре эксперт не подтвердил существенного отклонения.',
      sources: [{ id: 's-5', label: 'Фото осмотра', detail: 'Фото №47' }], materialIds: ['mat-5'], status: 'Отклонено экспертом', images: ['Фото №47'],
    },
  ],
  normatives: [
    { id: 'n-1', code: 'ГОСТ 16371-2014', title: 'Мебель. Общие технические условия', applicability: 'Требования к качеству изготовления и внешнему виду мебели.', connected: true, status: 'Действующий' },
    { id: 'n-2', code: 'ТР ТС 025/2012', title: 'О безопасности мебельной продукции', applicability: 'Общие требования безопасности мебельной продукции.', connected: true, status: 'Действующий' },
    { id: 'n-3', code: 'ГОСТ 20400-2013', title: 'Продукция мебельного производства. Термины и определения', applicability: 'Терминология для описания объекта и результатов исследования.', connected: false, recommended: true, recommendationReason: 'Документ помогает единообразно описывать выявленные дефекты и может применяться при ответе на вопрос о недостатках объекта.', status: 'Действующий' },
  ],
  reportSections: [
    { id: 'r-1', number: 1, title: 'Вводная часть', content: 'Настоящее заключение подготовлено по результатам исследования представленных материалов и осмотра объекта.', entityReferences: [{ entityType: 'research_object', entityId: 'commodity-kitchen-set' }] },
    { id: 'r-2', number: 2, title: 'Материалы исследования', content: 'Для исследования представлены договор, спецификация, акт осмотра, результаты измерений и фотоматериалы.', entityReferences: [{ entityType: 'material', entityId: 'mat-1' }, { entityType: 'material', entityId: 'mat-3' }] },
    { id: 'r-3', number: 3, title: 'Исследовательская часть', content: 'В ходе исследования выполнено сопоставление фактических характеристик объекта с исходными документами.', entityReferences: [{ entityType: 'finding', entityId: 'finding-1' }, { entityType: 'normative', entityId: 'n-1' }] },
    { id: 'r-4', number: 4, title: 'Выводы', content: 'Формулировки ответов на поставленные вопросы находятся в работе.', entityReferences: [{ entityType: 'finding', entityId: 'finding-2' }] },
  ],
  revisions: [
    { id: 'v3', label: 'v3', createdAt: 'Сегодня, 21:35', current: true, description: 'Текущая контрольная версия' },
    { id: 'v2', label: 'v2', createdAt: 'Сегодня, 20:12', description: 'После предварительного анализа материалов' },
    { id: 'v1', label: 'v1', createdAt: 'Сегодня, 18:44', description: 'После первичного обследования' },
  ],
}

const constructionProject: ExpertProject = {
  id: 'demo-construction',
  title: 'Жилой дом — обследование перекрытия',
  profile: 'construction',
  direction: 'Строительно-техническое',
  workType: 'Заключение специалиста',
  customer: 'ООО «Заказчик»',
  object: 'Монолитное перекрытие жилого дома',
  address: 'г. Екатеринбург, ул. Строителей, 24',
  researchDate: '10 сентября 2026',
  updatedAt: 'Сегодня, 19:10',
  status: 'В работе',
  questions: [
    'Каково фактическое техническое состояние конструкции?',
    'Имеются ли отклонения от проектной документации?',
    'Соответствует ли выполненная работа нормативным требованиям?',
  ],
  quickActions: [
    'Проанализировать материалы',
    'Выявить дефекты и повреждения',
    'Сопоставить с проектной документацией',
    'Подобрать применимые нормативы',
    'Начать исследовательскую часть',
  ],
  conversations: [
    { id: 'general-analysis', title: 'Общий анализ', messages: [] },
    { id: 'measurements', title: 'Результаты измерений', messages: [] },
  ],
  materials: [
    { id: 'c-mat-1', name: 'Проектная документация.pdf', kind: 'document', format: 'PDF', meta: '48 страниц', size: '18,2 МБ', pages: 48, category: 'Проектная документация', status: 'Обработан', useInAi: true, icon: 'mdi-file-pdf-box' },
    { id: 'c-mat-2', name: 'Акт обследования.docx', kind: 'document', format: 'DOCX', meta: '9 страниц', size: '1,4 МБ', pages: 9, category: 'Обследование', status: 'Обработан', useInAi: true, icon: 'mdi-file-word-outline' },
    { id: 'c-mat-3', name: 'Результаты измерений.xlsx', kind: 'spreadsheet', format: 'XLSX', meta: '5 листов', size: '720 КБ', category: 'Измерения', status: 'Обработан', useInAi: true, icon: 'mdi-file-excel-outline' },
    { id: 'c-mat-4', name: 'Фото конструкций', kind: 'image', format: 'JPG', meta: '46 изображений', size: '96 МБ', category: 'Фотоматериалы', status: 'Обрабатывается', useInAi: true, icon: 'mdi-image-multiple-outline' },
  ],
  researchObjects: [
    { id: 'construction-slab', name: 'Монолитное перекрытие', type: 'Конструкция', description: 'Участок монолитного перекрытия жилого дома.' },
    { id: 'construction-slab-joint', name: 'Участок примыкания перекрытия', type: 'Конструктивный узел', description: 'Участок примыкания перекрытия к смежной конструкции.' },
  ],
  findings: [
    {
      id: 'c-finding-1', number: 1, title: 'Отклонение поверхности перекрытия', type: 'non_compliance', typeLabel: 'Несоответствие', object: 'Монолитное перекрытие — участок 2 м', researchObjectId: 'construction-slab',
      description: 'Фактическое отклонение поверхности превышает значение, указанное в проектной документации.', factualData: 'Отклонение зафиксировано в трёх контрольных точках.', measurement: 'до 18 мм на участке 2 м', normativeComparison: 'Требуется финальная проверка применимой редакции СП.', recommendation: 'Выполнить поверочный расчёт и разработать техническое решение.', calculation: 'Поверочный расчёт подготовлен на 60%.',
      sources: [{ id: 'c-s-1', label: 'Акт обследования', detail: 'стр. 6' }, { id: 'c-s-2', label: 'Результаты измерений', detail: 'лист 2' }], materialIds: ['c-mat-1', 'c-mat-2', 'c-mat-3'], normativeReferenceIds: ['c-n-2'], status: 'Подтверждено экспертом', images: ['Фото №12', 'Фото №14'],
    },
    {
      id: 'c-finding-2', number: 2, title: 'Локальные следы увлажнения', type: 'observation', typeLabel: 'Наблюдение', object: 'Участок примыкания перекрытия', researchObjectId: 'construction-slab-joint',
      description: 'При визуальном обследовании зафиксированы локальные следы увлажнения.',
      sources: [{ id: 'c-s-3', label: 'Фото конструкций', detail: 'Фото №21–24' }], materialIds: ['c-mat-4'], normativeReferenceIds: ['c-n-1'], status: 'Предложено AI',
    },
  ],
  normatives: [
    { id: 'c-n-1', code: 'СП 13-102-2003', title: 'Правила обследования несущих строительных конструкций', applicability: 'Методика обследования и фиксации технического состояния.', connected: true, status: 'Действующий' },
    { id: 'c-n-2', code: 'СП 70.13330.2012', title: 'Несущие и ограждающие конструкции', applicability: 'Сопоставление качества выполненных бетонных работ.', connected: true, status: 'Действующий' },
    { id: 'c-n-3', code: 'ГОСТ 31937-2024', title: 'Здания и сооружения. Правила обследования', applicability: 'Обследование технического состояния конструкций.', connected: false, recommended: true, recommendationReason: 'Документ относится к обследованию несущих конструкций и может применяться при анализе вопроса №2.', status: 'Действующий' },
  ],
  reportSections: [
    { id: 'c-r-1', number: 1, title: 'Вводная часть', content: 'Исследование выполнено в отношении конструкций жилого дома на основании представленных исходных данных.', entityReferences: [{ entityType: 'research_object', entityId: 'construction-slab' }] },
    { id: 'c-r-2', number: 2, title: 'Материалы исследования', content: 'Исследованы проектная документация, акт обследования, результаты измерений и фотоматериалы.', entityReferences: [{ entityType: 'material', entityId: 'c-mat-1' }, { entityType: 'material', entityId: 'c-mat-3' }] },
    { id: 'c-r-3', number: 3, title: 'Исследовательская часть', content: 'Выполнены визуальный анализ, сопоставление с проектной документацией и оценка результатов измерений.', entityReferences: [{ entityType: 'finding', entityId: 'c-finding-1' }, { entityType: 'normative', entityId: 'c-n-2' }] },
    { id: 'c-r-4', number: 4, title: 'Выводы', content: 'Ответы на вопросы будут сформулированы после проверки расчётной части.', entityReferences: [{ entityType: 'finding', entityId: 'c-finding-2' }] },
  ],
  revisions: [
    { id: 'v2', label: 'v2', createdAt: 'Сегодня, 19:10', current: true, description: 'Текущая контрольная версия' },
    { id: 'v1', label: 'v1', createdAt: 'Сегодня, 16:42', description: 'После первичного обследования' },
  ],
}

export const expertProjects: ExpertProject[] = [commodityProject, constructionProject]

export function getExpertProject(projectId: string): ExpertProject | undefined {
  return expertProjects.find((project) => project.id === projectId)
}

export const expertDashboardStats = [
  { label: 'Активные проекты', value: 12, icon: 'mdi-briefcase-outline' },
  { label: 'Черновики', value: 4, icon: 'mdi-file-edit-outline' },
  { label: 'На проверке', value: 3, icon: 'mdi-clipboard-check-outline' },
  { label: 'Завершено', value: 27, icon: 'mdi-check-circle-outline' },
]

export const expertDirections = ['Товароведческое', 'Строительно-техническое', 'Иное']

export const expertWorkTypes = [
  'Досудебное исследование',
  'Судебная экспертиза',
  'Заключение специалиста',
  'Рецензия',
  'Акт осмотра / обследования',
  'Отчёт',
  'Иное',
]
