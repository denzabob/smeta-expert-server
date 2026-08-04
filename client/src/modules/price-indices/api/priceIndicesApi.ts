import api from '@/api/axios'
import type { PriceIndicesCapabilitiesResponse } from '../types'

export async function fetchPriceIndicesCapabilities(): Promise<PriceIndicesCapabilitiesResponse> {
  const response = await api.get<PriceIndicesCapabilitiesResponse>('/api/indices/capabilities')

  return response.data
}
