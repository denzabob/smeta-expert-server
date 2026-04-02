<template>
  <v-table density="compact" class="evidence-items-table">
    <thead>
      <tr>
        <th style="width: 40px">№</th>
        <th>Наименование</th>
        <th style="width: 100px">Компонент</th>
        <th style="width: 100px">Статус</th>
        <th>Источник</th>
        <th style="width: 110px">Цена</th>
        <th style="width: 100px">Связь</th>
        <th style="width: 160px" class="text-right">Действия</th>
      </tr>
    </thead>
    <tbody>
      <tr
        v-for="(item, idx) in items"
        :key="item.id"
        :class="rowClass(item)"
      >
        <td class="text-medium-emphasis text-caption">{{ idx + 1 }}</td>
        <td>{{ item.label || '—' }}</td>
        <td>
          <v-chip
            v-if="item.cost_component"
            size="x-small"
            color="blue-grey"
            variant="tonal"
          >
            {{ componentLabel(item.cost_component) }}
          </v-chip>
          <span v-else class="text-medium-emphasis">—</span>
        </td>
        <td>
          <v-chip
            size="x-small"
            :color="statusColor(item.status)"
            variant="tonal"
          >
            {{ statusLabel(item.status) }}
          </v-chip>
        </td>
        <td>
          <a
            v-if="item.source_url"
            :href="item.source_url"
            target="_blank"
            rel="noopener noreferrer"
            class="text-caption"
          >
            {{ truncateUrl(item.source_url) }}
          </a>
          <span v-else class="text-medium-emphasis">—</span>
        </td>
        <td>
          <template v-if="item.effective_value">
            {{ item.effective_value }} {{ item.currency || '₽' }}
          </template>
          <span v-else class="text-medium-emphasis">—</span>
        </td>
        <td>
          <v-chip
            v-if="item.evidence_record_id"
            size="x-small"
            variant="outlined"
            color="success"
            prepend-icon="mdi-link"
          >
            #{{ item.evidence_record_id }}
          </v-chip>
          <span v-else class="text-medium-emphasis">—</span>
        </td>
        <td class="text-right">
          <template v-if="isActionable(item)">
            <v-btn
              size="x-small"
              color="primary"
              variant="text"
              prepend-icon="mdi-check"
              :disabled="disabled"
              @click="$emit('resolve', item)"
            >
              Подтвердить
            </v-btn>
            <v-btn
              size="x-small"
              color="warning"
              variant="text"
              prepend-icon="mdi-skip-next"
              :disabled="disabled"
              @click="$emit('skip', item)"
            >
              Пропустить
            </v-btn>
            <v-tooltip v-if="item.source_url" location="top">
              <template #activator="{ props: tp }">
                <v-btn
                  v-bind="tp"
                  size="x-small"
                  variant="text"
                  color="blue-grey"
                  icon="mdi-google-chrome"
                  :href="item.source_url"
                  target="_blank"
                  rel="noopener noreferrer"
                  @click="$emit('chrome-click')"
                />
              </template>
              <span>Открыть и захватить через Chrome-расширение</span>
            </v-tooltip>
          </template>
          <template v-else-if="item.status === 'skipped'">
            <v-chip size="x-small" variant="tonal" color="warning">
              {{ skipReason(item) || 'пропущен' }}
            </v-chip>
          </template>
        </td>
      </tr>
      <tr v-if="items.length === 0">
        <td colspan="8" class="text-center py-4 text-medium-emphasis">
          Нет позиций в данном запуске.
        </td>
      </tr>
    </tbody>
  </v-table>
</template>

<script setup lang="ts">
import type { EvidenceItem } from '@/api/evidenceRun'
import {
  ITEM_STATUS_LABELS,
  ITEM_STATUS_COLORS,
  COST_COMPONENT_LABELS,
} from '@/composables/useEvidenceRun'

defineProps<{
  items: EvidenceItem[]
  disabled?: boolean
}>()

defineEmits<{
  resolve: [item: EvidenceItem]
  skip: [item: EvidenceItem]
  'chrome-click': []
}>()

function statusLabel(status: string): string {
  return ITEM_STATUS_LABELS[status as keyof typeof ITEM_STATUS_LABELS] ?? status
}

function statusColor(status: string): string {
  return ITEM_STATUS_COLORS[status as keyof typeof ITEM_STATUS_COLORS] ?? 'grey'
}

function componentLabel(comp: string): string {
  return COST_COMPONENT_LABELS[comp] ?? comp
}

function isActionable(item: EvidenceItem): boolean {
  return item.status === 'pending' || item.status === 'collecting'
}

function rowClass(item: EvidenceItem): string {
  if (item.status === 'skipped') return 'evidence-items-table__row--skipped'
  if (item.status === 'failed') return 'evidence-items-table__row--failed'
  return ''
}

function truncateUrl(url: string): string {
  try {
    const u = new URL(url)
    const path = u.pathname.length > 30 ? u.pathname.substring(0, 30) + '…' : u.pathname
    return u.hostname + path
  } catch {
    return url.length > 50 ? url.substring(0, 50) + '…' : url
  }
}

function skipReason(item: EvidenceItem): string | null {
  return (item.diagnostics_json as { skip_reason?: string } | null)?.skip_reason ?? null
}
</script>

<style scoped>
.evidence-items-table__row--skipped {
  opacity: 0.6;
}

.evidence-items-table__row--failed {
  background: rgba(var(--v-theme-error), 0.04);
}
</style>
