export type ExpertSseEvent = { event: string; data: Record<string, unknown> }

/** Parses SSE independently from network chunk boundaries and UTF-8 boundaries. */
export async function* parseExpertSseStream(stream: ReadableStream<Uint8Array>): AsyncGenerator<ExpertSseEvent> {
  const reader = stream.getReader()
  const decoder = new TextDecoder()
  let buffer = ''
  let event = 'message'
  let data: string[] = []

  const consumeLine = (line: string): ExpertSseEvent | undefined => {
    if (line === '') {
      if (data.length === 0) return undefined
      const raw = data.join('\n')
      data = []
      try {
        const parsed = JSON.parse(raw) as unknown
        return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)
          ? { event, data: parsed as Record<string, unknown> }
          : undefined
      } finally {
        event = 'message'
      }
    }
    if (line.startsWith(':')) return undefined
    if (line.startsWith('event:')) event = line.slice(6).trim() || 'message'
    if (line.startsWith('data:')) data.push(line.slice(5).trimStart())
    return undefined
  }

  try {
    while (true) {
      const { value, done } = await reader.read()
      buffer += decoder.decode(value, { stream: !done })
      let boundary: number
      while ((boundary = buffer.indexOf('\n')) >= 0) {
        const line = buffer.slice(0, boundary).replace(/\r$/, '')
        buffer = buffer.slice(boundary + 1)
        const parsed = consumeLine(line)
        if (parsed) yield parsed
      }
      if (done) break
    }
    if (buffer !== '') {
      const parsed = consumeLine(buffer.replace(/\r$/, ''))
      if (parsed) yield parsed
    }
  } finally {
    reader.releaseLock()
  }
}
