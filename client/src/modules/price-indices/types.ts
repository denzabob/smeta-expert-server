export type PriceIndicesCapabilityStatus =
  | 'idle'
  | 'loading'
  | 'available'
  | 'forbidden'
  | 'disabled'
  | 'error'

export interface PriceIndicesCapabilities {
  application: 'price_indices'
  enabled: boolean
  access: boolean
  admin_only: boolean
  stage: 'skeleton'
}

export interface PriceIndicesCapabilitiesResponse {
  data: PriceIndicesCapabilities
}

export type ApplicationId = 'estimates' | 'price_indices' | 'admin' | 'parser'

export interface ApplicationMenuItem {
  id: ApplicationId
  label: string
  icon: string
  iconClass: string
  routeName: string
}

export type PriceIndicesGuardDecision = 'allow' | 'forbidden' | 'unavailable'

export interface UserStatisticalSeries {
  public_id: string
  classifier_item: {
    public_id: string
    classifier_code: string
    item_code: string
    item_name: string
    provider_code_kind: 'numeric' | 'rosstat_local_ag' | string
  }
  indicator: { code: string; name: string }
  territory: { code: string; name: string }
  frequency: string
  comparison_basis: string
  unit: string
  period: {
    from: string
    to: string
    observations_count: number
  }
  active_import?: {
    public_id: string
    importer_code: string
    importer_version: string
    published_at: string | null
  }
}

export interface PaginatedMeta {
  current_page: number
  from: number | null
  last_page: number
  path: string
  per_page: number
  to: number | null
  total: number
}

export interface UserStatisticalSeriesSearchResponse {
  data: UserStatisticalSeries[]
  links?: Record<string, unknown>
  meta: PaginatedMeta
}

export interface UserSeriesSearchFilters {
  dataset_public_id?: string
  item_code?: string
  item_code_prefix?: string
  item_name?: string
  page?: number
  per_page?: number
}

export interface StatisticalCalculationInput {
  series_public_id: string
  start_period: string
  end_period: string
  base_amount?: string
}

export interface StatisticalCalculationChainItem {
  period: string
  index: string
  factor: string
  running_coefficient: string
  source: {
    sheet: string
    row: number
    column: string
    cell: string | null
    raw_value: string | null
    footnote_marker: string | null
  }
}

export interface StatisticalCalculationProvenance {
  dataset: { public_id: string; code: string; name: string }
  import: {
    public_id: string
    importer_code: string
    importer_version: string
    published_at: string | null
  }
  source_file: {
    public_id: string
    original_filename: string
    sha256: string
  }
  series: { public_id: string }
}

export interface StatisticalCalculationResult {
  series: {
    public_id: string
    classifier_item: {
      public_id: string
      item_code: string
      item_name: string
    }
    indicator: { code: string; name: string }
    territory: { code: string; name: string }
    frequency: string
    comparison_basis: string
    unit: string
  }
  period: {
    start: string
    end: string
    interval_semantics: '(start,end]' | string
    factors_count: number
  }
  coefficient_raw: string
  coefficient: string
  amount: {
    base: string
    adjusted_raw: string
    adjusted: string
  } | null
  chain: StatisticalCalculationChainItem[]
  provenance: StatisticalCalculationProvenance
}

export interface StatisticalCalculationResponse {
  data: StatisticalCalculationResult
}
