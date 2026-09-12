import { describe, expect, it } from 'vitest'
import { expertSidebarSections } from './navigation'
import { expertProjects, getExpertProject } from './mock/expertMockData'
import { expertRoutes } from './router/routes'

describe('Expert frontend prototype contracts', () => {
  it('declares the dashboard, project list and nested project routes', () => {
    const topLevelPaths = expertRoutes.map((route) => route.path)
    const projectRoute = expertRoutes.find((route) => route.path === 'expert/projects/:projectId')

    expect(topLevelPaths).toEqual([
      'expert',
      'expert/projects',
      'expert/projects/:projectId',
    ])
    expect(projectRoute?.children?.map((route) => route.path)).toEqual([
      '',
      'chat',
      'materials',
      'research',
      'normatives',
      'report',
      'versions',
    ])
  })

  it('keeps both demo profiles on the same project contract with conversations', () => {
    expect(expertProjects.map((project) => project.profile)).toEqual(['commodity', 'construction'])
    expect(expertProjects.every((project) => Array.isArray(project.conversations))).toBe(true)
    expect(getExpertProject('demo-commodity')?.conversations.length).toBeGreaterThan(1)
    expect(getExpertProject('demo-construction')?.conversations.length).toBeGreaterThan(1)
  })

  it('exposes only Expert-level navigation in the global sidebar', () => {
    expect(expertSidebarSections.flatMap((section) => section.items.map((item) => item.routeName))).toEqual([
      'expert-dashboard',
      'expert-projects',
    ])
  })
})
