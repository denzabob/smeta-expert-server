<template>
  <SectionCard class="labor-calculation-panel">
    <template #title>Расчёт нормо-часа</template>

    <div class="panel-head">
      <div class="panel-copy">
        <div class="panel-copy__title">Расчёт по профилям работ</div>
        <div class="panel-copy__text">
          Ставка считается только по привязанным к проекту источникам и показывается отдельно по каждому профилю.
        </div>
      </div>
      <v-btn variant="text" prepend-icon="mdi-refresh" :loading="loading" @click="loadCalculation">
        Обновить
      </v-btn>
    </div>

    <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-4" />

    <v-alert
      v-for="warning in globalWarnings"
      :key="warning"
      type="warning"
      variant="tonal"
      class="mb-3"
    >
      {{ warningLabel(warning) }}
    </v-alert>

    <v-alert v-if="errorMessage" type="error" variant="tonal" class="mb-4">
      <div class="d-flex flex-wrap align-center justify-space-between ga-3">
        <span>{{ errorMessage }}</span>
        <v-btn size="small" variant="flat" color="error" @click="loadCalculation">
          Повторить
        </v-btn>
      </div>
    </v-alert>

    <EmptyState
      v-if="!loading && !errorMessage && profiles.length === 0"
      icon="mdi-chart-timeline-variant"
      title="Нет данных для расчёта нормо-часа"
      description="Привяжите к проекту источники труда с заполненным профилем и данными по ставке."
    />

    <div v-else-if="!loading && !errorMessage" class="calculation-grid">
      <v-card
        v-for="profile in profiles"
        :key="profile.labor_profile_id"
        variant="outlined"
        class="profile-card"
      >
        <v-card-text class="pa-4">
          <div class="profile-card__head">
            <div>
              <div class="profile-card__title">{{ profile.labor_profile_name || 'Профиль без названия' }}</div>
              <div class="profile-card__meta">
                Источников: {{ profile.sources.used_count }}
                <span v-if="profile.sources.skipped_count"> · Пропущено: {{ profile.sources.skipped_count }}</span>
              </div>
            </div>
            <v-chip size="small" variant="tonal" color="primary">
              {{ profile.sources.used_count }} шт.
            </v-chip>
          </div>

          <div class="profile-metrics">
            <div class="metric-card">
              <div class="metric-card__label">Рынок</div>
              <div class="metric-card__value">{{ marketRangeLabel(profile) }}</div>
              <div class="metric-card__hint">
                База: {{ rateLabel(profile.aggregation?.base_rate) }}
              </div>
            </div>

            <div class="metric-card metric-card--accent">
              <div class="metric-card__label">Итог</div>
              <div class="metric-card__value">{{ rateLabel(profile.model?.final_rate) }}</div>
              <div class="metric-card__hint">
                Метод: {{ aggregationLabel(profile.aggregation?.method) }}
              </div>
            </div>
          </div>

          <div class="breakdown-grid">
            <div class="breakdown-row">
              <span class="breakdown-label">Нагрузка с взносами</span>
              <span class="breakdown-value">{{ rateLabel(profile.model?.loaded_rate) }}</span>
            </div>
            <div class="breakdown-row">
              <span class="breakdown-label">Коэффициент загрузки</span>
              <span class="breakdown-value">{{ factorLabel(profile.model?.load_factor) }}</span>
            </div>
            <div class="breakdown-row">
              <span class="breakdown-label">Себестоимость</span>
              <span class="breakdown-value">{{ rateLabel(profile.model?.cost_rate) }}</span>
            </div>
          </div>

          <v-alert
            v-for="warning in profile.warnings"
            :key="`${profile.labor_profile_id}-${warning}`"
            type="warning"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            {{ warningLabel(warning) }}
          </v-alert>
        </v-card-text>
      </v-card>
    </div>
  </SectionCard>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import EmptyState from '@/components/layout/EmptyState.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import { laborEvidenceApi, type ProjectLaborCostProfile, type ProjectLaborCostResponse } from '@/api/laborEvidence'

const props = defineProps<{
  projectId: number | string
}>()

const loading = ref(false)
const errorMessage = ref('')
const data = ref<ProjectLaborCostResponse | null>(null)

const profiles = computed(() => data.value?.profiles || [])
const globalWarnings = computed(() => data.value?.warnings || [])

onMounted(() => {
  void loadCalculation()
})

watch(() => props.projectId, () => {
  void loadCalculation()
})

async function loadCalculation() {
  loading.value = true
  errorMessage.value = ''

  try {
    data.value = await laborEvidenceApi.getProjectLaborCost(props.projectId)
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message || 'Не удалось загрузить расчёт нормо-часа.'
  } finally {
    loading.value = false
  }
}

function rateLabel(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  return `${formatNumber(value)} ₽/ч`
}

function factorLabel(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  return formatNumber(value, 4)
}

function formatNumber(value: number, maximumFractionDigits = 2): string {
  return new Intl.NumberFormat('ru-RU', {
    minimumFractionDigits: 0,
    maximumFractionDigits,
  }).format(value)
}

function marketRangeLabel(profile: ProjectLaborCostProfile): string {
  if (!profile.normalized_rates.length) return 'Нет данных'

  const sorted = [...profile.normalized_rates].sort((a, b) => a - b)
  const min = sorted[0]
  const max = sorted[sorted.length - 1]

  if (min === max) {
    return rateLabel(min)
  }

  return `${formatNumber(min)}–${formatNumber(max)} ₽/ч`
}

function aggregationLabel(method: string | null | undefined): string {
  return ({
    single: 'одно значение',
    mean: 'среднее',
    median: 'медиана',
    min: 'минимум',
    max: 'максимум',
    none: 'нет данных',
  } as Record<string, string>)[method || 'none'] || method || '—'
}

function warningLabel(warning: string): string {
  return ({
    no_valid_labor_sources: 'Нет валидных источников труда для расчёта.',
    multiple_profiles_present_project_level_rate_deprecated: 'Проектная единая ставка больше не используется. Показывается расчёт по каждому профилю отдельно.',
    unassigned_sources_skipped_due_to_missing_labor_profile: 'Часть источников пропущена, потому что у них не указан профиль работ.',
    no_valid_labor_sources_for_profile: 'Для этого профиля нет валидных источников труда.',
    profile_calculation_not_performed_due_to_no_valid_sources: 'Расчёт по этому профилю не выполнен: нет подходящих источников.',
  } as Record<string, string>)[warning] || warning
}

defineExpose({
  reload: loadCalculation,
})
</script>

<style scoped>
.panel-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 16px;
}

.panel-copy__title {
  font-size: 15px;
  font-weight: 600;
}

.panel-copy__text {
  margin-top: 4px;
  color: rgba(0, 0, 0, 0.6);
  max-width: 760px;
}

.calculation-grid {
  display: grid;
  gap: 16px;
}

.profile-card {
  border-radius: 18px;
}

.profile-card__head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}

.profile-card__title {
  font-size: 16px;
  font-weight: 700;
  line-height: 1.3;
}

.profile-card__meta {
  margin-top: 4px;
  font-size: 13px;
  color: rgba(0, 0, 0, 0.58);
}

.profile-metrics {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  margin-top: 16px;
}

.metric-card {
  padding: 14px 16px;
  border-radius: 16px;
  background: #f6f7f8;
}

.metric-card--accent {
  background: linear-gradient(135deg, #f5f8ef 0%, #ebf5e3 100%);
}

.metric-card__label {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgba(0, 0, 0, 0.54);
}

.metric-card__value {
  margin-top: 8px;
  font-size: 22px;
  font-weight: 700;
  line-height: 1.1;
}

.metric-card__hint {
  margin-top: 6px;
  font-size: 13px;
  color: rgba(0, 0, 0, 0.6);
}

.breakdown-grid {
  display: grid;
  gap: 10px;
  margin-top: 16px;
}

.breakdown-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding-top: 10px;
  border-top: 1px solid rgba(0, 0, 0, 0.08);
}

.breakdown-label {
  color: rgba(0, 0, 0, 0.62);
}

.breakdown-value {
  font-weight: 600;
  text-align: right;
}

@media (max-width: 760px) {
  .panel-head {
    flex-direction: column;
  }

  .profile-metrics {
    grid-template-columns: 1fr;
  }

  .breakdown-row {
    flex-direction: column;
  }

  .breakdown-value {
    text-align: left;
  }
}
</style>
