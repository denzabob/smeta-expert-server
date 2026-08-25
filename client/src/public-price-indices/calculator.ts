import {
  CALCULATION_FAILED_EVENT,
  CALCULATION_STARTED_EVENT,
  CALCULATION_SUCCEEDED_EVENT,
  formatChange,
  formatDecimal,
  formatMoney,
  formatPeriod,
  type PublicCalculationResult,
} from './shared'

export {
  CALCULATION_FAILED_EVENT,
  CALCULATION_STARTED_EVENT,
  CALCULATION_SUCCEEDED_EVENT,
  formatChange,
  formatDecimal,
  formatMoney,
  formatPeriod,
  type PublicCalculationResult,
} from './shared'

export function monthSpan(start: string, end: string): number | null {
  const startMatch = /^(\d{4})-(\d{2})$/.exec(start)
  const endMatch = /^(\d{4})-(\d{2})$/.exec(end)
  if (!startMatch || !endMatch) return null

  return (Number(endMatch[1]) - Number(startMatch[1])) * 12 + Number(endMatch[2]) - Number(startMatch[2])
}

export function validatePeriodRange(start: string, end: string, maximumMonths: number): string | null {
  const span = monthSpan(start, end)
  if (span === null || span < 0) return 'invalid_period_range'
  if (span > maximumMonths) return 'period_too_long'
  return null
}

export function calculationSummary(result: PublicCalculationResult): {
  period: string
  coefficient: string
  change: string
  amount: string | null
} {
  return {
    period: `${formatPeriod(result.period.start)} → ${formatPeriod(result.period.end)}`,
    coefficient: `×${formatDecimal(result.coefficient, 4, true)}`,
    change: formatChange(result.change_percent),
    amount: result.amount ? `${formatMoney(result.amount.original)} → ${formatMoney(result.amount.adjusted)}` : null,
  }
}

function createCell(row: HTMLTableRowElement, value: string): void {
  const node = document.createElement('td')
  node.textContent = value
  row.appendChild(node)
}

export function errorMessage(payload: { code?: string } | null): string {
  const messages: Record<string, string> = {
    invalid_amount: 'Проверьте сумму: используйте положительное число не более чем с двумя знаками после запятой.',
    invalid_period_range: 'Проверьте начальный и конечный периоды.',
    period_before_available_range: 'Начальный период находится раньше доступных данных.',
    period_after_available_range: 'Конечный период находится позже доступных данных.',
    period_too_long: 'Выбранный период превышает допустимые 120 месяцев.',
    incomplete_observation_chain: 'Для выбранного периода нет полной последовательности месячных значений.',
    unsupported_series_calculation: 'Этот статистический ряд не поддерживает публичный расчёт.',
    public_series_not_available: 'Опубликованный ряд недоступен для расчёта.',
    public_snapshot_unavailable: 'Опубликованные данные временно недоступны для расчёта.',
  }
  return (payload?.code && messages[payload.code]) || 'Не удалось выполнить расчёт. Попробуйте ещё раз.'
}

function dispatch(name: string, detail: unknown): void {
  document.dispatchEvent(new CustomEvent(name, { detail }))
}

export function initializePublicIndexCalculator(): boolean {
  const form = document.querySelector<HTMLFormElement>('[data-public-index-calculator]')
  if (!form || typeof window.fetch !== 'function') return false

  const submit = form.querySelector<HTMLButtonElement>('button[type="submit"]')
  const error = document.getElementById('calculation-error')
  const result = document.getElementById('calculation-result')
  const chainBody = result?.querySelector<HTMLTableSectionElement>('[data-result-chain]')
  const start = form.elements.namedItem('start_period') as HTMLSelectElement | null
  const end = form.elements.namedItem('end_period') as HTMLSelectElement | null
  if (!submit || !error || !result || !chainBody || !start || !end) return false

  const maximumMonths = Number(form.dataset.maxRangeMonths || '120')
  let requestSequence = 0

  const calculate = (focusResult: boolean): void => {
    if (!form.reportValidity()) return

    const rangeError = validatePeriodRange(start.value, end.value, maximumMonths)
    if (rangeError) {
      requestSequence += 1
      result.hidden = true
      error.textContent = errorMessage({ code: rangeError })
      error.hidden = false
      submit.disabled = false
      submit.textContent = 'Рассчитать'
      form.removeAttribute('aria-busy')
      dispatch(CALCULATION_FAILED_EVENT, { code: rangeError })
      return
    }

    const fields = new FormData(form)
    const amount = String(fields.get('amount') || '').trim().replace(',', '.')
    const payload: { start_period: string; end_period: string; amount?: string } = {
      start_period: String(fields.get('start_period')),
      end_period: String(fields.get('end_period')),
    }
    if (amount !== '') payload.amount = amount

    const currentRequest = ++requestSequence
    error.hidden = true
    result.hidden = true
    submit.disabled = true
    submit.textContent = 'Выполняется…'
    form.setAttribute('aria-busy', 'true')
    dispatch(CALCULATION_STARTED_EVENT, payload)

    window
      .fetch(form.action, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        credentials: 'omit',
        body: JSON.stringify(payload),
      })
      .then((response) =>
        response
          .json()
          .catch(() => ({}))
          .then((body) => {
            if (!response.ok) throw body
            return body.data as PublicCalculationResult
          }),
      )
      .then((data) => {
        if (currentRequest !== requestSequence) return

        const summary = calculationSummary(data)
        result.querySelector<HTMLElement>('[data-result-period]')!.textContent = summary.period
        result.querySelector<HTMLElement>('[data-result-coefficient]')!.textContent = summary.coefficient
        result.querySelector<HTMLElement>('[data-result-change]')!.textContent = summary.change

        const amountRow = result.querySelector<HTMLElement>('[data-result-amount-row]')!
        if (summary.amount) {
          result.querySelector<HTMLElement>('[data-result-amount]')!.textContent = summary.amount
          amountRow.hidden = false
        } else {
          amountRow.hidden = true
        }

        chainBody.replaceChildren()
        data.chain.forEach((factor) => {
          const row = document.createElement('tr')
          createCell(row, formatPeriod(factor.period))
          createCell(row, formatDecimal(factor.index, 2, true))
          createCell(row, formatDecimal(factor.factor, 12, true))
          createCell(row, formatDecimal(factor.running_coefficient, 12, true))
          chainBody.appendChild(row)
        })

        const { publication, source } = data.provenance
        const classifier = data.page.classifier
        result.querySelector<HTMLElement>('[data-result-provenance]')!.textContent =
          `${data.provenance.provider} · статистический ряд ${classifier.code} — ${data.page.title}` +
          ` · данные по ${formatPeriod(data.provenance.snapshot.period_to)}` +
          ` · публикация ${publication.reference}` +
          ` · источник ${source.filename} · SHA-256 ${source.sha256}`
        result.hidden = false
        if (focusResult) result.focus()
        dispatch(CALCULATION_SUCCEEDED_EVENT, data)
      })
      .catch((payload: { code?: string }) => {
        if (currentRequest !== requestSequence) return
        result.hidden = true
        error.textContent = errorMessage(payload)
        error.hidden = false
        dispatch(CALCULATION_FAILED_EVENT, payload)
      })
      .finally(() => {
        if (currentRequest !== requestSequence) return
        submit.disabled = false
        submit.textContent = 'Рассчитать'
        form.removeAttribute('aria-busy')
      })
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault()
    calculate(true)
  })
  start.addEventListener('change', () => calculate(false))
  end.addEventListener('change', () => calculate(false))

  return true
}

if (typeof document !== 'undefined') initializePublicIndexCalculator()
