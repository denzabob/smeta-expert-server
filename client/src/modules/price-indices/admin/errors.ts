import axios from 'axios'

const messages: Record<string, string> = {
  source_file_not_active: 'Файл больше не является активным источником.',
  source_file_missing: 'Файл отсутствует в закрытом хранилище.',
  unsupported_dataset: 'Для выбранного набора данных нет поддерживаемого импортера.',
  preview_not_ready: 'Предварительный анализ ещё не завершён.',
  preview_failed: 'Предварительный анализ завершился ошибкой.',
  preview_expired: 'Срок хранения результата анализа истёк. Запустите анализ повторно.',
  preview_retry_not_allowed: 'Этот анализ нельзя запустить повторно в текущем состоянии.',
  preview_already_running: 'Эквивалентный анализ уже выполняется.',
  import_already_running: 'Импорт этого файла уже выполняется.',
  import_already_ready: 'Данные этого файла уже готовы к публикации.',
  import_already_published: 'Данные этого файла уже опубликованы.',
  import_retry_required: 'Предыдущая попытка завершилась ошибкой. Используйте повтор импорта.',
  import_not_ready: 'Импорт ещё не готов к публикации.',
  publication_conflict: 'Состояние публикации изменилось. Данные будут обновлены.',
  job_dispatch_failed: 'Не удалось поставить задачу в очередь. Повторите попытку позже.',
  duplicate_file: 'Этот файл уже загружен для выбранного набора данных.',
}

export function getPriceIndicesErrorMessage(error: unknown, fallback = 'Не удалось выполнить операцию.'): string {
  if (!axios.isAxiosError(error)) return fallback
  const code = typeof error.response?.data?.code === 'string' ? error.response.data.code : ''
  if (messages[code]) return messages[code]
  const status = error.response?.status
  if (status === 403) return 'Недостаточно прав для выполнения операции.'
  if (status === 422) return 'Проверьте заполнение полей и выбранный файл.'
  return fallback
}

export function isPublicationConflict(error: unknown): boolean {
  return axios.isAxiosError(error) && error.response?.data?.code === 'publication_conflict'
}
