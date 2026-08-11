<template>
  <v-alert v-if="!diagnostic" type="info" variant="tonal" density="compact">Диагностика доступна после загрузки полного выбранного диапазона.</v-alert>
  <v-alert v-else :type="diagnostic.isContinuous ? 'success' : (diagnostic.duplicatePeriods.length ? 'error' : 'warning')" variant="tonal" density="compact">
    <div class="font-weight-medium">{{ diagnostic.isContinuous ? 'Ряд непрерывный' : summary }}</div>
    <div class="text-body-2">{{ diagnostic.actualCount }} из {{ diagnostic.expectedCount }} месяцев<span v-if="details"> · {{ details }}</span></div>
  </v-alert>
</template>
<script setup lang="ts">
import { computed } from 'vue'
import type { ContinuityDiagnostic } from '../types'
const props = defineProps<{ diagnostic: ContinuityDiagnostic | null }>()
const summary = computed(() => props.diagnostic?.duplicatePeriods.length ? 'Обнаружены дубли периодов' : props.diagnostic?.missingPeriods.length ? `Пропущено месяцев: ${props.diagnostic.missingPeriods.length}` : 'Есть периоды без значения')
const details = computed(() => { const value = props.diagnostic; if (!value) return ''; return [value.missingPeriods.length ? `пропуски: ${value.missingPeriods.join(', ')}` : '', value.nullPeriods.length ? `без значения: ${value.nullPeriods.join(', ')}` : '', value.duplicatePeriods.length ? `дубли: ${value.duplicatePeriods.join(', ')}` : ''].filter(Boolean).join(' · ') })
</script>
