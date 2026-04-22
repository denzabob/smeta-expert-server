<template>
  <v-dialog :model-value="modelValue" max-width="860" scrollable @update:model-value="$emit('update:modelValue', $event)">
    <v-card>
      <v-card-title class="d-flex align-center">
        <div>
          <div class="text-h6">Детали источника цены</div>
          <div class="text-body-2 text-medium-emphasis">
            Read-only разбор выбранного source и связанных evidence assets.
          </div>
        </div>
        <v-spacer />
        <v-btn icon variant="text" @click="$emit('update:modelValue', false)">
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </v-card-title>

      <v-card-text>
        <v-alert v-if="loading" type="info" variant="tonal" density="compact" class="mb-4">
          Загружаем детали источника…
        </v-alert>

        <v-alert v-else-if="error" type="error" variant="tonal" density="compact" class="mb-4">
          {{ error }}
        </v-alert>

        <template v-else-if="details">
          <v-row dense class="mb-3">
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
              <div class="text-caption text-medium-emphasis">Нормализовано</div>
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

          <v-row dense class="mb-4">
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

          <div class="d-flex align-center mb-2">
            <div class="text-subtitle-2">Evidence assets</div>
            <v-spacer />
            <v-btn size="small" variant="text" class="text-none" disabled>
              Добавление evidence позже
            </v-btn>
          </div>

          <v-alert
            v-if="details.evidence_assets.length === 0"
            type="info"
            density="compact"
            variant="tonal"
          >
            Для этого источника пока нет evidence assets. Просмотр и доступ остаются read-only.
          </v-alert>

          <v-list v-else density="compact" class="border rounded">
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
                <span v-if="asset.mime_type">{{ asset.mime_type }}</span>
                <span v-if="asset.file_size !== null"> · {{ formatFileSize(asset.file_size) }}</span>
                <span v-if="asset.captured_at"> · {{ formatDateTime(asset.captured_at) }}</span>
                <span v-if="asset.content_hash"> · hash: {{ asset.content_hash }}</span>
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

      <v-card-actions>
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
