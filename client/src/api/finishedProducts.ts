import {
  facadesApi,
  type Facade as FinishedProduct,
  type FacadeQuote as FinishedProductQuote,
  type SimilarQuote as SimilarFinishedProductQuote,
  type FacadeListParams as FinishedProductListParams,
  type FacadeCreateData as FinishedProductCreateData,
  type QuoteCreateData as FinishedProductQuoteCreateData,
  type FacadeFilterOptions as FinishedProductFilterOptions,
} from '@/api/facades'

export type {
  FinishedProduct,
  FinishedProductQuote,
  SimilarFinishedProductQuote,
  FinishedProductListParams,
  FinishedProductCreateData,
  FinishedProductQuoteCreateData,
  FinishedProductFilterOptions,
}

/**
 * Canonical API alias for finished products.
 * Current implementation routes facade subtype via facadesApi.
 */
export const finishedProductsApi = facadesApi

export default finishedProductsApi

