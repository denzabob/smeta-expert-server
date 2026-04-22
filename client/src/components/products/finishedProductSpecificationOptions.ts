export const facadeClassOptions = [
  { value: 'STANDARD', label: 'Стандарт' },
  { value: 'PREMIUM', label: 'Премиум' },
  { value: 'GEOMETRY', label: 'Геометрия' },
  { value: 'RADIUS', label: 'Радиус' },
  { value: 'VITRINA', label: 'Витрина' },
  { value: 'RESHETKA', label: 'Решетка' },
  { value: 'AKRIL', label: 'Акрил' },
  { value: 'ALUMINIUM', label: 'Алюминий' },
  { value: 'MASSIV', label: 'Массив' },
  { value: 'ECONOMY', label: 'Эконом' },
]

export const baseTypeOptions = [
  { value: 'mdf', label: 'МДФ' },
  { value: 'chipboard', label: 'ЛДСП' },
  { value: 'solid_wood', label: 'Массив' },
  { value: 'aluminium', label: 'Алюминий' },
  { value: 'glass', label: 'Стекло' },
  { value: 'composite', label: 'Композит' },
]

export const coveringOptions = [
  { value: 'pvc_film', label: 'ПВХ-пленка' },
  { value: 'paint', label: 'Краска' },
  { value: 'enamel', label: 'Эмаль' },
  { value: 'acrylic', label: 'Акрил' },
  { value: 'veneer', label: 'Шпон' },
  { value: 'laminate', label: 'Ламинат' },
  { value: 'glass', label: 'Стекло' },
]

export const coverTypeOptions = [
  { value: 'matte', label: 'Матовый' },
  { value: 'gloss', label: 'Глянец' },
  { value: 'soft_touch', label: 'Soft-touch' },
  { value: 'textured', label: 'Текстурный' },
]

export function labelFromOptions(
  value: string | null | undefined,
  options: Array<{ value: string; label: string }>,
) {
  if (!value) return '—'
  return options.find((item) => item.value === value)?.label ?? value
}

export function pricingMethodLabel(method: string | null | undefined) {
  if (method === 'median') return 'Медиана'
  if (method === 'mean') return 'Среднее'
  return '—'
}

export function formatPrice(value: number | null | undefined) {
  if (value === null || value === undefined) return '—'
  return new Intl.NumberFormat('ru-RU', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value)
}
