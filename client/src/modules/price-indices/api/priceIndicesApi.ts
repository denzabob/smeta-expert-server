import api from '@/api/axios'
import type { AxiosInstance } from 'axios'
import type {
  PriceIndicesCapabilitiesResponse,
  StatisticalCalculationInput,
  StatisticalCalculationResult,
  StatisticalCalculationResponse,
  UserSeriesSearchFilters,
  UserStatisticalSeries,
  UserStatisticalSeriesSearchResponse,
} from '../types'

export async function fetchPriceIndicesCapabilities(): Promise<PriceIndicesCapabilitiesResponse> {
  const response = await api.get<PriceIndicesCapabilitiesResponse>('/api/indices/capabilities')

  return response.data
}

export function createPriceIndicesApi(client: AxiosInstance = api) {
  return {
    async searchSeries(filters: UserSeriesSearchFilters): Promise<UserStatisticalSeriesSearchResponse> {
      const response = await client.get<UserStatisticalSeriesSearchResponse>('/api/indices/series', {
        params: filters,
      })
      return response.data
    },

    async getSeries(publicId: string): Promise<UserStatisticalSeries> {
      const response = await client.get<{ data: UserStatisticalSeries }>(
        `/api/indices/series/${encodeURIComponent(publicId)}`,
      )
      return response.data.data
    },

    async calculate(input: StatisticalCalculationInput): Promise<StatisticalCalculationResult> {
      const response = await client.post<StatisticalCalculationResponse>('/api/indices/calculate', input)
      return response.data.data
    },
  }
}

export const priceIndicesApi = createPriceIndicesApi()
