/**
 * Labor Works API Client
 * Handles CRUD operations for project labor works
 */

import api from './axios'

export interface LaborWork {
  id?: number
  project_id: number
  position_profile_id?: number | null
  title: string
  basis?: string | null
  hours: number
  hours_source?: string | null
  hours_manual?: number | null
  note?: string | null
  sort_order?: number
  project_profile_rate_id?: number | null
  rate_per_hour?: number | null
  cost_total?: number | null
  rate_snapshot?: any | null
  cost?: number  // Computed: hours * project.normohour_rate or rate_per_hour
  created_at?: string
  updated_at?: string
}

export interface LaborWorksResponse {
  data: LaborWork[]
  success: boolean
}

class LaborWorksApiClient {
  /**
   * Get all labor works for a project
   */
  async getAll(projectId: number): Promise<LaborWork[]> {
    const { data } = await api.get(`/api/projects/${projectId}/labor-works`)
    return Array.isArray(data) ? data : data.data || []
  }

  /**
   * Create a new labor work
   */
  async create(projectId: number, payload: Omit<LaborWork, 'id' | 'created_at' | 'updated_at'>): Promise<LaborWork> {
    const { data } = await api.post(`/api/projects/${projectId}/labor-works`, payload)
    return data.data || data
  }

  /**
   * Get a single labor work
   */
  async getOne(projectId: number, laborWorkId: number): Promise<LaborWork> {
    const { data } = await api.get(`/api/projects/${projectId}/labor-works/${laborWorkId}`)
    return data.data || data
  }

  /**
   * Update a labor work
   */
  async update(projectId: number, laborWorkId: number, payload: Partial<LaborWork>): Promise<LaborWork> {
    const { data } = await api.put(`/api/projects/${projectId}/labor-works/${laborWorkId}`, payload)
    return data.data || data
  }

  /**
   * Delete a labor work
   */
  async delete(projectId: number, laborWorkId: number): Promise<void> {
    await api.delete(`/api/projects/${projectId}/labor-works/${laborWorkId}`)
  }

  /**
   * Reorder labor works (batch update sort order)
   */
  async reorder(projectId: number, order: number[]): Promise<{ message: string }> {
    const { data } = await api.patch(`/api/projects/${projectId}/labor-works/reorder`, { order })
    return data
  }
}

export default new LaborWorksApiClient()
