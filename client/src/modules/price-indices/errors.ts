import axios from 'axios'
import { formatMonthGenitive } from './calculator'

export interface PriceIndicesUserError {
  code: string
  message: string
  missingPeriods: string[]
}

const messages: Record<string, string> = {
  dataset_required: 'Выберите набор опубликованных статистических данных.',
  no_active_publication: 'Для выбранного набора данных пока нет опубликованной версии.',
  series_not_available: 'Выбранный статистический ряд больше недоступен в текущей версии данных. Выберите его заново.',
  unsupported_series_calculation: 'Для выбранного статистического ряда этот способ расчёта пока не поддерживается.',
  invalid_period_range: 'Начальный период не должен быть позже конечного.',
  period_before_available_range: 'Начальный период находится раньше доступных данных.',
  period_after_available_range: 'Конечный период находится позже доступных данных.',
  incomplete_observation_chain: 'Расчёт невозможно выполнить: в выбранном периоде отсутствуют необходимые статистические данные.',
  invalid_base_amount: 'Проверьте введённую исходную стоимость.',
  calculation_integrity_error: 'Опубликованные данные не прошли внутреннюю проверку целостности. Расчёт не выполнен.',
  calculation_failed: 'Не удалось выполнить расчёт. Повторите попытку позже.',
}

export function getPriceIndicesUserError(error: unknown, fallback = 'Не удалось выполнить операцию.'): PriceIndicesUserError {
  if (!axios.isAxiosError(error)) return { code: '', message: fallback, missingPeriods: [] }
  const payload = error.response?.data as Record<string, unknown> | undefined
  const code = typeof payload?.code === 'string' ? payload.code : ''
  const details = isRecord(payload?.details) ? payload.details : {}
  const missingPeriods = [
    ...stringArray(details.missing_periods),
    ...stringArray(details.missing_value_periods),
  ].filter((value, index, items) => items.indexOf(value) === index)
  let message = messages[code] ?? fallback
  if (error.response?.status === 403) message = 'Недостаточно прав для работы с приложением «Индексы».'
  if (code === 'incomplete_observation_chain' && missingPeriods.length === 1) {
    message = `Для ${formatMonthGenitive(missingPeriods[0]!)} отсутствуют необходимые данные.`
  }
  return { code, message, missingPeriods }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function stringArray(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : []
}
