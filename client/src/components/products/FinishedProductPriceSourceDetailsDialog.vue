<template>
  <v-dialog :model-value="modelValue" max-width="860" scrollable @update:model-value="$emit('update:modelValue', $event)">
    <v-card class="fp-details-dialog-card">
      <v-card-title class="fp-details-dialog-card__header">
        <div>
          <div class="fp-details-dialog-card__title">Детали источника цены</div>
          <div class="fp-details-dialog-card__subtitle">
            Цена, поставщик и подтверждающие материалы по выбранному источнику.
          </div>
        </div>
        <v-spacer />
        <v-btn icon variant="text" @click="$emit('update:modelValue', false)">
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </v-card-title>

      <v-card-text class="fp-details-dialog-card__content">
        <v-alert v-if="loading" type="info" variant="tonal" density="compact" class="mb-4">
          Загружаем детали источника…
        </v-alert>

        <v-alert v-else-if="error" type="error" variant="tonal" density="compact" class="mb-4">
          {{ error }}
        </v-alert>

        <template v-else-if="details">
          <v-row dense class="fp-details-grid">
            <v-col cols="12" md="6">
              <div class="text-caption text-medium-emphasis">Поставщик</div>
              <div class="font-weight-medium">{{ details.source.supplier.name || '—' }}</div>
            </v-col>
            <v-col cols="12" md="6">
              <div class="text-caption text-medium-emphasis">Тип источника</div>
              <div>{{ pricingSourceKindLabel(details.source.source_kind) }}</div>
            </v-col>
            <v-col cols="12" md="4">
              <div class="text-caption text-medium-emphasis">Цена источника</div>
              <div>{{ formatPrice(details.source.source_price) }} {{ details.source.source_unit || '' }}</div>
            </v-col>
            <v-col cols="12" md="4">
              <div class="text-caption text-medium-emphasis">Цена за м²</div>
              <div class="font-weight-medium">{{ formatPrice(details.source.price_per_m2_normalized) }} ₽/м²</div>
            </v-col>
            <v-col cols="12" md="4">
              <div class="text-caption text-medium-emphasis">Статус</div>
              <v-chip size="small" :color="pricingSourceStatusColor(details.source.status)" variant="tonal">
                {{ pricingSourceStatusLabel(details.source.status) }}
              </v-chip>
            </v-col>
            <v-col cols="12" md="4">
              <div class="text-caption text-medium-emphasis">Дата актуальности</div>
              <div>{{ formatDate(details.source.effective_date) }}</div>
            </v-col>
            <v-col cols="12" md="4">
              <div class="text-caption text-medium-emphasis">Дата фиксации</div>
              <div>{{ formatDateTime(details.source.captured_at) }}</div>
            </v-col>
            <v-col cols="12" md="4">
              <div class="text-caption text-medium-emphasis">Коэффициент к м²</div>
              <div>{{ details.source.conversion_factor_to_m2 ?? '—' }}</div>
            </v-col>
          </v-row>

          <v-alert
            v-if="details.source.stale_reason"
            type="warning"
            density="compact"
            variant="tonal"
            class="mb-4"
          >
            {{ details.source.stale_reason }}
          </v-alert>

          <v-row dense class="fp-details-grid fp-details-grid--secondary">
            <v-col cols="12" md="6" v-if="details.source.article">
              <div class="text-caption text-medium-emphasis">Артикул</div>
              <div>{{ details.source.article }}</div>
            </v-col>
            <v-col cols="12" md="6" v-if="details.source.category">
              <div class="text-caption text-medium-emphasis">Категория</div>
              <div>{{ details.source.category }}</div>
            </v-col>
            <v-col cols="12" v-if="details.source.description">
              <div class="text-caption text-medium-emphasis">Описание</div>
              <div>{{ details.source.description }}</div>
            </v-col>
            <v-col cols="12" v-if="details.source.notes">
              <div class="text-caption text-medium-emphasis">Примечания</div>
              <div>{{ details.source.notes }}</div>
            </v-col>
          </v-row>

          <div class="fp-details-section-title">
            <div class="text-subtitle-2">Доказательства</div>
          </div>

          <v-alert
            v-if="details.evidence_assets.length === 0"
            type="warning"
            density="compact"
            variant="tonal"
          >
            Доказательства не добавлены. Перед формированием итогового отчёта рекомендуется прикрепить файл, скриншот или ссылку.
          </v-alert>

          <v-list v-else density="compact" class="fp-details-list">
            <v-list-item
              v-for="asset in details.evidence_assets"
              :key="asset.id"
              class="py-2"
            >
              <template #prepend>
                <v-icon size="small">{{ pricingEvidenceIcon(asset.asset_type) }}</v-icon>
              </template>
              <v-list-item-title>
                {{ pricingEvidenceLabel(asset.asset_type) }}
                <span v-if="asset.original_name"> · {{ asset.original_name }}</span>
              </v-list-item-title>
              <v-list-item-subtitle>
                <span v-if="asset.file_size !== null">{{ formatFileSize(asset.file_size) }}</span>
                <span v-if="asset.captured_at"> · {{ formatDateTime(asset.captured_at) }}</span>
              </v-list-item-subtitle>
              <template #append>
                <div class="d-flex align-center ga-1">
                  <v-btn
                    v-if="asset.can_preview && asset.preview_url"
                    :href="asset.preview_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    size="x-small"
                    variant="text"
                    class="text-none"
                  >
                    Предпросмотр
                  </v-btn>
                  <v-btn
                    v-if="asset.can_download && asset.download_url"
                    :href="asset.download_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    size="x-small"
                    variant="text"
                    class="text-none"
                  >
                    Скачать
                  </v-btn>
                  <v-btn
                    v-else-if="asset.open_url && asset.access_kind === 'external'"
                    :href="asset.open_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    size="x-small"
                    variant="text"
                    class="text-none"
                  >
                    Открыть
                  </v-btn>
                </div>
              </template>
            </v-list-item>
          </v-list>
        </template>
      </v-card-text>

      <v-card-actions class="fp-details-dialog-card__actions">
        <v-spacer />
        <v-btn @click="$emit('update:modelValue', false)">Закрыть</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import type { FinishedProductPriceSourceDetails } from '@/api/finishedProductPricing'
import {
  formatDate,
  formatDateTime,
  formatFileSize,
  formatPrice,
  pricingEvidenceIcon,
  pricingEvidenceLabel,
  pricingSourceKindLabel,
  pricingSourceStatusColor,
  pricingSourceStatusLabel,
} from './finishedProductPricingOptions'

defineProps<{
  modelValue: boolean
  loading: boolean
  error: string | null
  details: FinishedProductPriceSourceDetails | null
}>()

defineEmits<{
  (e: 'update:modelValue', value: boolean): void
}>()
</script>

<style scoped>
.fp-details-dialog-card {
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.76);
  border-radius: var(--md-sys-shape-corner-extra-large) !important;
  background: rgba(var(--v-theme-surface-container-low), 0.98);
}

.fp-details-dialog-card__header {
  display: flex;
  align-items: flex-start;
  gap: var(--ds-space-12);
  padding: 18px 20px 14px;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.6);
}

.fp-details-dialog-card__title {
  font-size: 1.125rem;
  line-height: 1.3;
  font-weight: 700;
  color: var(--ds-text-primary);
}

.fp-details-dialog-card__subtitle {
  margin-top: 4px;
  color: var(--ds-text-secondary);
}

.fp-details-dialog-card__content {
  display: grid;
  gap: var(--ds-space-14);
  padding: 20px !important;
}

.fp-details-grid {
  margin: 0 !important;
  padding: var(--ds-space-12);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.68);
  border-radius: var(--ds-radius-12);
  background: rgba(var(--v-theme-surface), 0.96);
}

.fp-details-grid--secondary {
  background: rgba(var(--v-theme-surface-container-lowest), 0.72);
}

.fp-details-section-title {
  display: flex;
  align-items: center;
}

.fp-details-list {
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.68);
  border-radius: var(--ds-radius-12);
  background: rgba(var(--v-theme-surface), 0.96);
}

.fp-details-dialog-card__actions {
  padding: 12px 20px 18px !important;
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.56);
}
</style>
