import { afterEach, describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
import { usePollingTask } from './usePollingTask'

afterEach(() => vi.useRealTimers())

describe('usePollingTask', () => {
  it('polls immediately through pending/running/ready and stops on terminal state', async () => {
    vi.useFakeTimers(); const states = ['pending', 'running', 'ready']; const seen: string[] = []; const fetcher = vi.fn(async () => states.shift()!)
    const scope = effectScope(); const poller = scope.run(() => usePollingTask({ fetcher, isTerminal: (value) => value === 'ready', onData: (value) => { seen.push(value) }, intervalMs: 100 }))!
    poller.start(); await vi.advanceTimersByTimeAsync(0); await vi.advanceTimersByTimeAsync(100); await vi.advanceTimersByTimeAsync(100)
    expect(seen).toEqual(['pending', 'running', 'ready']); expect(fetcher).toHaveBeenCalledTimes(3); expect(poller.active.value).toBe(false)
    scope.stop()
  })

  it('does not overlap requests and scope cleanup cancels the next poll', async () => {
    vi.useFakeTimers(); let resolve!: (value: string) => void; const fetcher = vi.fn(() => new Promise<string>((done) => { resolve = done }))
    const scope = effectScope(); const poller = scope.run(() => usePollingTask({ fetcher, isTerminal: () => false, intervalMs: 50 }))!
    poller.start(); await vi.advanceTimersByTimeAsync(500); expect(fetcher).toHaveBeenCalledTimes(1)
    resolve('running'); await vi.advanceTimersByTimeAsync(0); scope.stop(); await vi.advanceTimersByTimeAsync(100)
    expect(fetcher).toHaveBeenCalledTimes(1); expect(poller.active.value).toBe(false)
  })

  it('tolerates a network error and retries until terminal state', async () => {
    vi.useFakeTimers(); const onError = vi.fn(); const fetcher = vi.fn().mockRejectedValueOnce(new Error('network')).mockResolvedValueOnce('ready')
    const scope = effectScope(); const poller = scope.run(() => usePollingTask({ fetcher, isTerminal: (value) => value === 'ready', onError, intervalMs: 100 }))!
    poller.start(); await vi.advanceTimersByTimeAsync(0); await vi.advanceTimersByTimeAsync(100)
    expect(onError).toHaveBeenCalledTimes(1); expect(fetcher).toHaveBeenCalledTimes(2); expect(poller.active.value).toBe(false); scope.stop()
  })
})
