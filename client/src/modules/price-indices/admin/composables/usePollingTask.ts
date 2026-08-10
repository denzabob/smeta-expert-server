import { getCurrentScope, onScopeDispose, readonly, ref } from 'vue'

export interface PollingTaskOptions<T> {
  fetcher: () => Promise<T>
  isTerminal: (value: T) => boolean
  onData?: (value: T) => void | Promise<void>
  onError?: (error: unknown) => void
  intervalMs?: number
  timeoutMs?: number
}

export function usePollingTask<T>(options: PollingTaskOptions<T>) {
  const active = ref(false)
  const inFlight = ref(false)
  const timedOut = ref(false)
  let timer: ReturnType<typeof setTimeout> | null = null
  let generation = 0
  let startedAt = 0

  function stop() {
    active.value = false
    generation += 1
    if (timer) clearTimeout(timer)
    timer = null
  }

  async function tick(currentGeneration: number): Promise<void> {
    if (!active.value || currentGeneration !== generation || inFlight.value) return
    if (options.timeoutMs && Date.now() - startedAt >= options.timeoutMs) {
      timedOut.value = true
      stop()
      return
    }
    inFlight.value = true
    try {
      const value = await options.fetcher()
      if (!active.value || currentGeneration !== generation) return
      await options.onData?.(value)
      if (options.isTerminal(value)) {
        stop()
        return
      }
    } catch (error) {
      if (active.value && currentGeneration === generation) options.onError?.(error)
    } finally {
      inFlight.value = false
    }
    if (active.value && currentGeneration === generation) {
      timer = setTimeout(() => void tick(currentGeneration), options.intervalMs ?? 2500)
    }
  }

  function start(immediate = true) {
    stop()
    active.value = true
    timedOut.value = false
    startedAt = Date.now()
    const currentGeneration = generation
    if (immediate) void tick(currentGeneration)
    else timer = setTimeout(() => void tick(currentGeneration), options.intervalMs ?? 2500)
  }

  if (getCurrentScope()) onScopeDispose(stop)

  return { active: readonly(active), inFlight: readonly(inFlight), timedOut: readonly(timedOut), start, stop }
}
