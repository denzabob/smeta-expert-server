type FlashPayload = {
  message: string
  color: string
}

const PROJECTS_FLASH_KEY = 'projects_flash_notification'

let prefetchedProject: { id: string; data: any } | null = null

export const setProjectsFlashMessage = (message: string, color = 'warning') => {
  if (typeof window === 'undefined') return

  const payload: FlashPayload = { message, color }
  sessionStorage.setItem(PROJECTS_FLASH_KEY, JSON.stringify(payload))
}

export const consumeProjectsFlashMessage = (): FlashPayload | null => {
  if (typeof window === 'undefined') return null

  const raw = sessionStorage.getItem(PROJECTS_FLASH_KEY)
  if (!raw) return null

  sessionStorage.removeItem(PROJECTS_FLASH_KEY)

  try {
    const parsed = JSON.parse(raw)
    if (!parsed?.message) return null

    return {
      message: String(parsed.message),
      color: String(parsed.color || 'warning')
    }
  } catch {
    return {
      message: 'Проект не существует',
      color: 'warning'
    }
  }
}

export const storePrefetchedProject = (projectId: string | number, data: any) => {
  prefetchedProject = {
    id: String(projectId),
    data
  }
}

export const consumePrefetchedProject = (projectId: string | number) => {
  if (!prefetchedProject) return null
  if (prefetchedProject.id !== String(projectId)) return null

  const data = prefetchedProject.data
  prefetchedProject = null
  return data
}
