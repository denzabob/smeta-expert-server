const timeFormatter = new Intl.DateTimeFormat('ru-RU', {
  hour: '2-digit',
  minute: '2-digit',
})

const dateFormatter = new Intl.DateTimeFormat('ru-RU', {
  day: 'numeric',
  month: 'long',
})

function isSameCalendarDay(left: Date, right: Date): boolean {
  return left.getFullYear() === right.getFullYear()
    && left.getMonth() === right.getMonth()
    && left.getDate() === right.getDate()
}

export function formatExpertMessageTimestamp(value: string, now = new Date()): string {
  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return '—'
  }

  const time = timeFormatter.format(date)

  if (isSameCalendarDay(date, now)) {
    return time
  }

  const yesterday = new Date(now)
  yesterday.setDate(now.getDate() - 1)

  if (isSameCalendarDay(date, yesterday)) {
    return `Вчера, ${time}`
  }

  if (date.getFullYear() === now.getFullYear()) {
    return `${dateFormatter.format(date)}, ${time}`
  }

  return `${dateFormatter.format(date)} ${date.getFullYear()}, ${time}`
}
