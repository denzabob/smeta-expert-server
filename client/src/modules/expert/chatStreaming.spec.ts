import { describe, expect, it } from 'vitest'
import { parseExpertSseStream } from './chatStreaming'

function streamFromChunks(chunks: string[]): ReadableStream<Uint8Array> {
  const encoder = new TextEncoder()
  return new ReadableStream({
    start(controller) {
      chunks.forEach((chunk) => controller.enqueue(encoder.encode(chunk)))
      controller.close()
    },
  })
}

describe('Expert SSE parser', () => {
  it('preserves fragmented UTF-8 payloads, multiple events and unknown events', async () => {
    const bytes = new TextEncoder().encode('event: delta\ndata: {"seq":1,"text":"Привет"}\n\nevent: heartbeat\ndata: {"version":1}\n\nevent: future\ndata: {"x":1}\n\n')
    const stream = new ReadableStream<Uint8Array>({
      start(controller) {
        controller.enqueue(bytes.slice(0, 37))
        controller.enqueue(bytes.slice(37, 46))
        controller.enqueue(bytes.slice(46))
        controller.close()
      },
    })
    const events = []
    for await (const event of parseExpertSseStream(stream)) events.push(event)

    expect(events).toEqual([
      { event: 'delta', data: { seq: 1, text: 'Привет' } },
      { event: 'heartbeat', data: { version: 1 } },
      { event: 'future', data: { x: 1 } },
    ])
  })

  it('accepts several frames in one network chunk and ignores comments', async () => {
    const events = []
    for await (const event of parseExpertSseStream(streamFromChunks([': keepalive\n\nevent: cancelled\ndata: {"version":1}\n\nevent: done\ndata: {"version":1}\n\n']))) events.push(event)
    expect(events.map((event) => event.event)).toEqual(['cancelled', 'done'])
  })
})
