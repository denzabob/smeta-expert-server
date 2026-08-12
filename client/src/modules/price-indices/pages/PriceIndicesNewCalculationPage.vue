<template>
  <PageContainer max-width="1040px">
    <PageHeader
      title="Новый расчёт"
      subtitle="Рассчитайте изменение стоимости по опубликованным официальным статистическим индексам."
    />

    <div class="calculator-stack">
      <v-alert v-if="pageError" type="error" variant="tonal" closable @click:close="pageError = ''">
        {{ pageError }}
      </v-alert>

      <SeriesSearchField
        v-if="!selectedSeries"
        v-model:query="searchQuery"
        :items="searchItems"
        :total="searchTotal"
        :loading="searchLoading || seriesDetailLoading"
        :searched="searchPerformed"
        :error="searchError"
        @select="selectSeries"
        @load-more="loadMoreSeries"
      />
      <SelectedUserSeriesCard v-else :item="selectedSeries" @change="changeSeries" />

      <template v-if="selectedSeries">
        <CalculationPeriodFields
          v-model:start="startPeriod"
          v-model:end="endPeriod"
          :available-from="selectedSeries.period.from"
          :available-to="selectedSeries.period.to"
          :start-error="startPeriodError"
          :end-error="endPeriodError"
        />

        <CalculationAmountField v-model="amountInput" :error="amountError" />

        <div class="calculate-actions">
          <div class="text-body-2 text-medium-emphasis">
            Расчёт выполняется на сервере по текущей опубликованной версии данных.
          </div>
          <v-btn
            color="primary"
            size="large"
            prepend-icon="mdi-calculator-variant-outline"
            :loading="calculationLoading"
            :disabled="!canCalculate || calculationLoading"
            @click="calculate"
          >
            Рассчитать
          </v-btn>
        </div>
      </template>

      <v-alert v-if="calculationError" type="error" variant="tonal" role="alert">
        <div>{{ calculationError.message }}</div>
        <div v-if="calculationError.missingPeriods.length" class="mt-2">
          <strong>Отсутствуют периоды:</strong>
          {{ calculationError.missingPeriods.map((period) => formatMonth(period, true)).join(', ') }}
        </div>
      </v-alert>

      <CalculationResultCard
        v-if="calculationResult"
        :result="calculationResult"
        @show-source="openSource"
      />

      <SectionCard v-else-if="selectedSeries && !calculationLoading" variant="outlined">
        <div class="d-flex align-center ga-3 py-2 text-medium-emphasis">
          <v-icon icon="mdi-chart-timeline-variant" size="28" />
          <div>
            <div class="text-body-1 font-weight-medium">Результат появится после расчёта</div>
            <div class="text-body-2">Выберите периоды, при необходимости укажите стоимость и нажмите «Рассчитать».</div>
          </div>
        </div>
      </SectionCard>
    </div>

    <CalculationSourceDrawer
      v-if="calculationResult"
      v-model="sourceDrawerOpen"
      :item="selectedChainItem"
      :result="calculationResult"
    />
  </PageContainer>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import { priceIndicesApi } from '../api/priceIndicesApi'
import {
  buildCalculatorQuery,
  createLatestRequestGuard,
  formatMonth,
  isPeriodWithinAvailability,
  normalizeAmountInput,
  readCalculatorQuery,
  resolveUserSeriesSearchFilters,
} from '../calculator'
import { getPriceIndicesUserError, type PriceIndicesUserError } from '../errors'
import type {
  StatisticalCalculationChainItem,
  StatisticalCalculationInput,
  StatisticalCalculationResult,
  UserStatisticalSeries,
} from '../types'
import CalculationAmountField from '../components/CalculationAmountField.vue'
import CalculationPeriodFields from '../components/CalculationPeriodFields.vue'
import CalculationResultCard from '../components/CalculationResultCard.vue'
import CalculationSourceDrawer from '../components/CalculationSourceDrawer.vue'
import SelectedUserSeriesCard from '../components/SelectedUserSeriesCard.vue'
import SeriesSearchField from '../components/SeriesSearchField.vue'

const route = useRoute()
const router = useRouter()
const searchGuard = createLatestRequestGuard()
const detailGuard = createLatestRequestGuard()
const calculationGuard = createLatestRequestGuard()

const searchQuery = ref('')
const searchItems = ref<UserStatisticalSeries[]>([])
const searchTotal = ref(0)
const searchPage = ref(1)
const searchLoading = ref(false)
const searchPerformed = ref(false)
const searchError = ref('')
const seriesDetailLoading = ref(false)
const selectedSeries = ref<UserStatisticalSeries | null>(null)
const startPeriod = ref('')
const endPeriod = ref('')
const amountInput = ref('')
const submitAttempted = ref(false)
const calculationLoading = ref(false)
const calculationResult = ref<StatisticalCalculationResult | null>(null)
const calculationError = ref<PriceIndicesUserError | null>(null)
const pageError = ref('')
const sourceDrawerOpen = ref(false)
const selectedChainItem = ref<StatisticalCalculationChainItem | null>(null)
const restoringQuery = ref(true)
let searchTimer: number | undefined

const normalizedAmount = computed(() => normalizeAmountInput(amountInput.value))
const amountError = computed(() => amountInput.value.trim() ? (normalizedAmount.value.error ?? '') : '')
const startPeriodError = computed(() => {
  if (!submitAttempted.value || !selectedSeries.value) return ''
  if (!startPeriod.value) return 'Выберите начальный период.'
  if (!isPeriodWithinAvailability(startPeriod.value, selectedSeries.value.period.from, selectedSeries.value.period.to)) {
    return 'Период находится вне доступного диапазона.'
  }
  return ''
})
const endPeriodError = computed(() => {
  if (startPeriod.value && endPeriod.value && startPeriod.value > endPeriod.value) {
    return 'Конечный период не может быть раньше начального.'
  }
  if (!submitAttempted.value || !selectedSeries.value) return ''
  if (!endPeriod.value) return 'Выберите конечный период.'
  if (!isPeriodWithinAvailability(endPeriod.value, selectedSeries.value.period.from, selectedSeries.value.period.to)) {
    return 'Период находится вне доступного диапазона.'
  }
  return ''
})
const canCalculate = computed(() => {
  const series = selectedSeries.value
  if (!series || !startPeriod.value || !endPeriod.value || normalizedAmount.value.error) return false
  return isPeriodWithinAvailability(startPeriod.value, series.period.from, series.period.to)
    && isPeriodWithinAvailability(endPeriod.value, series.period.from, series.period.to)
    && startPeriod.value <= endPeriod.value
})

watch(searchQuery, () => {
  if (searchTimer !== undefined) window.clearTimeout(searchTimer)
  searchGuard.invalidate()
  searchLoading.value = false
  searchItems.value = []
  searchTotal.value = 0
  searchPage.value = 1
  searchPerformed.value = false
  searchError.value = ''
  const filters = resolveUserSeriesSearchFilters(searchQuery.value)
  if (!filters) return
  searchTimer = window.setTimeout(() => { void performSearch(1, false) }, 300)
})

watch([() => selectedSeries.value?.public_id, startPeriod, endPeriod, amountInput], () => {
  calculationGuard.invalidate()
  calculationLoading.value = false
  calculationResult.value = null
  calculationError.value = null
  sourceDrawerOpen.value = false
  selectedChainItem.value = null
})

watch([() => selectedSeries.value?.public_id, startPeriod, endPeriod], () => {
  if (restoringQuery.value) return
  void router.replace({
    query: buildCalculatorQuery({
      series: selectedSeries.value?.public_id ?? null,
      start: startPeriod.value,
      end: endPeriod.value,
    }),
  })
})

onMounted(async () => {
  const recovered = readCalculatorQuery(route.query)
  if (recovered.series) {
    await restoreSeries(recovered.series, recovered.start, recovered.end)
  }
  restoringQuery.value = false
})

onBeforeUnmount(() => {
  if (searchTimer !== undefined) window.clearTimeout(searchTimer)
  searchGuard.invalidate()
  detailGuard.invalidate()
  calculationGuard.invalidate()
})

async function performSearch(page: number, append: boolean) {
  const filters = resolveUserSeriesSearchFilters(searchQuery.value, page)
  if (!filters) return
  const token = searchGuard.next()
  searchLoading.value = true
  searchError.value = ''
  try {
    const response = await priceIndicesApi.searchSeries(filters)
    if (!searchGuard.isCurrent(token)) return
    searchItems.value = append ? [...searchItems.value, ...response.data] : response.data
    searchTotal.value = response.meta.total
    searchPage.value = page
    searchPerformed.value = true
  } catch (error) {
    if (!searchGuard.isCurrent(token)) return
    searchError.value = getPriceIndicesUserError(error, 'Не удалось выполнить поиск статистических рядов.').message
    searchPerformed.value = true
  } finally {
    if (searchGuard.isCurrent(token)) searchLoading.value = false
  }
}

function loadMoreSeries() {
  if (!searchLoading.value && searchItems.value.length < searchTotal.value) {
    void performSearch(searchPage.value + 1, true)
  }
}

async function selectSeries(item: UserStatisticalSeries) {
  await loadSeriesDetail(item.public_id)
  if (selectedSeries.value) {
    searchQuery.value = ''
    searchItems.value = []
    startPeriod.value = ''
    endPeriod.value = ''
    submitAttempted.value = false
  }
}

async function restoreSeries(publicId: string, queryStart: string, queryEnd: string) {
  await loadSeriesDetail(publicId)
  const series = selectedSeries.value
  if (!series) return
  startPeriod.value = isPeriodWithinAvailability(queryStart, series.period.from, series.period.to) ? queryStart : ''
  endPeriod.value = isPeriodWithinAvailability(queryEnd, series.period.from, series.period.to) ? queryEnd : ''
}

async function loadSeriesDetail(publicId: string) {
  const token = detailGuard.next()
  seriesDetailLoading.value = true
  pageError.value = ''
  try {
    const detail = await priceIndicesApi.getSeries(publicId)
    if (detailGuard.isCurrent(token)) selectedSeries.value = detail
  } catch (error) {
    if (!detailGuard.isCurrent(token)) return
    selectedSeries.value = null
    pageError.value = getPriceIndicesUserError(error, 'Не удалось загрузить выбранный статистический ряд.').message
  } finally {
    if (detailGuard.isCurrent(token)) seriesDetailLoading.value = false
  }
}

function changeSeries() {
  detailGuard.invalidate()
  selectedSeries.value = null
  startPeriod.value = ''
  endPeriod.value = ''
  searchQuery.value = ''
  submitAttempted.value = false
  pageError.value = ''
}

async function calculate() {
  submitAttempted.value = true
  if (!canCalculate.value || !selectedSeries.value || calculationLoading.value) return
  const payload: StatisticalCalculationInput = {
    series_public_id: selectedSeries.value.public_id,
    start_period: startPeriod.value,
    end_period: endPeriod.value,
  }
  if (normalizedAmount.value.value !== null) payload.base_amount = normalizedAmount.value.value

  const token = calculationGuard.next()
  calculationLoading.value = true
  calculationError.value = null
  try {
    const response = await priceIndicesApi.calculate(payload)
    if (calculationGuard.isCurrent(token)) calculationResult.value = response
  } catch (error) {
    if (!calculationGuard.isCurrent(token)) return
    const mapped = getPriceIndicesUserError(error, 'Не удалось выполнить расчёт.')
    calculationResult.value = null
    calculationError.value = mapped
  } finally {
    if (calculationGuard.isCurrent(token)) calculationLoading.value = false
  }
}

function openSource(item: StatisticalCalculationChainItem) {
  selectedChainItem.value = item
  sourceDrawerOpen.value = true
}
</script>

<style scoped>
.calculator-stack {
  display: flex;
  flex-direction: column;
  gap: var(--ds-space-16);
}
.calculate-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: var(--ds-space-16);
  flex-wrap: wrap;
}
@media (max-width: 620px) {
  .calculate-actions { align-items: stretch; flex-direction: column; }
  .calculate-actions .v-btn { width: 100%; }
}
</style>
