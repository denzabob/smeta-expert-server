export const CALCULATION_STARTED_EVENT = 'price-indices:calculation-started'
export const CALCULATION_SUCCEEDED_EVENT = 'price-indices:calculation-succeeded'
export const CALCULATION_FAILED_EVENT = 'price-indices:calculation-failed'

type CalculationFactor = {
  period: string
  index: string
  factor: string
  running_coefficient: string
}

export type PublicCalculationResult = {
  period: { start: string; end: string }
  coefficient: string
  change_percent: string
  amount: null | { original: string; adjusted: string }
  chain: CalculationFactor[]
  page: { title: string; classifier: { code: string } }
  provenance: {
    provider: string
    publication: { reference: string }
    source: { filename: string; sha256: string }
    snapshot: { period_to: string }
  }
}

const MONTHS = [
  'январь',
  'февраль',
  'март',
  'апрель',
  'май',
  'июнь',
  'июль',
  'август',
  'сентябрь',
  'октябрь',
  'ноябрь',
  'декабрь',
]

export function formatPeriod(value: string): string {
  const [year, monthValue] = String(value).split('-')
  const month = MONTHS[Number(monthValue) - 1]
  return month ? `${month} ${year}` : String(value)
}

export function formatDecimal(value: string, minimumScale: number, trim = false): string {
  const [integer, originalFraction = ''] = String(value).split('.')
  let fraction = trim ? originalFraction.replace(/0+$/, '') : originalFraction
  while (fraction.length < minimumScale) {
    fraction += '0'
  }
  return integer + (fraction ? `,${fraction}` : '')
}

export function formatMoney(value: string): string {
  const formatted = formatDecimal(value, 2).split(',')
  formatted[0] = (formatted[0] ?? '').replace(/\B(?=(\d{3})+(?!\d))/g, ' ')
  return `${formatted.join(',')} ₽`
}

export function formatChange(value: string): string {
  const stringValue = String(value)
  const zero = /^-?0(?:\.0+)?$/.test(stringValue)
  const negative = stringValue.charAt(0) === '-'
  const unsigned = negative ? stringValue.slice(1) : stringValue
  return `${zero ? '' : negative ? '−' : '+'}${formatDecimal(unsigned, 0, true)} %`
}
