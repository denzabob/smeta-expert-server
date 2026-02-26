<template>
  <v-dialog :model-value="modelValue" @update:model-value="$emit('update:modelValue', $event)" max-width="780" scrollable>
    <v-card>
      <v-card-title class="d-flex align-center">
        <v-icon class="mr-2" color="primary">mdi-information-outline</v-icon>
        Подробности материала
        <v-spacer />
        <v-btn icon="mdi-close" variant="text" size="small" @click="close" />
      </v-card-title>

      <v-divider />

      <v-card-text v-if="loading" class="d-flex justify-center pa-8">
        <v-progress-circular indeterminate color="primary" />
      </v-card-text>

      <v-card-text v-else-if="detail" class="pa-0">
        <!-- Main info -->
        <v-sheet class="pa-4">
          <div class="d-flex align-start ga-3 mb-3">
            <div class="flex-grow-1">
              <div class="text-h6 font-weight-bold">{{ detail.name }}</div>
              <div v-if="detail.article" class="text-body-2 text-medium-emphasis mt-1">
                Артикул: <span class="font-weight-medium">{{ detail.article }}</span>
              </div>
            </div>
            <v-chip :color="typeColor(detail.type)" variant="tonal" size="small">
              {{ typeLabel(detail.type) }}
            </v-chip>
          </div>

          <!-- Key metrics row -->
          <v-row dense class="mb-2">
            <v-col cols="6" sm="3">
              <div class="text-caption text-medium-emphasis">Цена</div>
              <div class="text-h6 font-weight-bold text-primary">
                {{ detail.price_per_unit ? formatPrice(detail.price_per_unit) + ' ₽' : '—' }}
              </div>
              <div class="text-caption text-medium-emphasis">за {{ detail.unit || '—' }}</div>
            </v-col>
            <v-col cols="6" sm="3">
              <div class="text-caption text-medium-emphasis">Trust Score</div>
              <div class="d-flex align-center ga-1">
                <TrustBadge :score="detail.trust_score" :level="detail.trust_level" />
              </div>
            </v-col>
            <v-col cols="6" sm="3">
              <div class="text-caption text-medium-emphasis">Наблюдений</div>
              <div class="text-h6 font-weight-bold">{{ detail.observation_count }}</div>
            </v-col>
            <v-col cols="6" sm="3">
              <div class="text-caption text-medium-emphasis">Источник</div>
              <div class="font-weight-medium">{{ originLabel(detail.data_origin) }}</div>
            </v-col>
          </v-row>
        </v-sheet>

        <v-divider />

        <!-- Trust Score Breakdown -->
        <v-sheet class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">
            <v-icon size="small" color="primary" class="mr-1">mdi-shield-search</v-icon>
            Разбор Trust Score: {{ detail.trust_score }}/100
          </div>

          <div class="trust-breakdown-list">
            <div
              v-for="(item, idx) in trustBreakdown"
              :key="idx"
              class="trust-breakdown-item d-flex align-center ga-3 py-2"
              :class="{ 'border-b': idx < trustBreakdown.length - 1 }"
            >
              <v-icon
                :icon="item.met ? (item.max > 0 ? 'mdi-check-circle' : 'mdi-check-circle') : (item.max > 0 ? 'mdi-close-circle' : 'mdi-alert-circle')"
                :color="item.met ? 'success' : (item.points < 0 ? 'error' : 'grey')"
                size="20"
              />
              <div class="flex-grow-1">
                <div class="font-weight-medium text-body-2">{{ item.label }}</div>
                <div class="text-caption text-medium-emphasis">{{ item.description }}</div>
              </div>
              <v-chip
                :color="item.points > 0 ? 'success' : item.points < 0 ? 'error' : 'grey'"
                variant="tonal"
                size="x-small"
                class="font-weight-bold"
              >
                {{ item.points > 0 ? '+' : '' }}{{ item.points }}{{ item.max > 0 ? '/' + item.max : '' }}
              </v-chip>
            </div>
          </div>
        </v-sheet>

        <v-divider />

        <!-- Material Properties -->
        <v-sheet class="pa-4">
          <div class="text-subtitle-1 font-weight-bold mb-3">
            <v-icon size="small" color="primary" class="mr-1">mdi-ruler</v-icon>
            Характеристики
          </div>

          <v-row dense>
            <!-- Plate / Facade: all three dimensions -->
            <template v-if="detail.type === 'plate' || detail.type === 'facade'">
              <v-col cols="6" sm="4">
                <div class="text-caption text-medium-emphasis">Толщина</div>
                <div class="font-weight-medium">{{ detail.thickness_mm ? detail.thickness_mm + ' мм' : '—' }}</div>
              </v-col>
              <v-col cols="6" sm="4">
                <div class="text-caption text-medium-emphasis">Длина</div>
                <div class="font-weight-medium">{{ detail.length_mm ? detail.length_mm + ' мм' : '—' }}</div>
              </v-col>
              <v-col cols="6" sm="4">
                <div class="text-caption text-medium-emphasis">Ширина</div>
                <div class="font-weight-medium">{{ detail.width_mm ? detail.width_mm + ' мм' : '—' }}</div>
              </v-col>
            </template>
            <!-- Edge: width (length_mm) and thickness (width_mm) -->
            <template v-else-if="detail.type === 'edge'">
              <v-col cols="6" sm="4">
                <div class="text-caption text-medium-emphasis">Ширина кромки</div>
                <div class="font-weight-medium">{{ detail.length_mm ? detail.length_mm + ' мм' : '—' }}</div>
              </v-col>
              <v-col cols="6" sm="4">
                <div class="text-caption text-medium-emphasis">Толщина кромки</div>
                <div class="font-weight-medium">
                  {{ detail.thickness ? detail.thickness + ' мм' : detail.width_mm ? detail.width_mm + ' мм' : '—' }}
                </div>
              </v-col>
            </template>
            <!-- Hardware: no dimensions -->
            <template v-else-if="detail.type === 'hardware'">
              <v-col cols="12">
                <div class="text-body-2 text-medium-emphasis font-italic">
                  Для фурнитуры размеры не применяются
                </div>
              </v-col>
            </template>
            <!-- Fallback: show all -->
            <template v-else>
              <v-col cols="6" sm="4">
                <div class="text-caption text-medium-emphasis">Толщина</div>
                <div class="font-weight-medium">{{ detail.thickness_mm ? detail.thickness_mm + ' мм' : '—' }}</div>
              </v-col>
              <v-col cols="6" sm="4">
                <div class="text-caption text-medium-emphasis">Длина</div>
                <div class="font-weight-medium">{{ detail.length_mm ? detail.length_mm + ' мм' : '—' }}</div>
              </v-col>
              <v-col cols="6" sm="4">
                <div class="text-caption text-medium-emphasis">Ширина</div>
                <div class="font-weight-medium">{{ detail.width_mm ? detail.width_mm + ' мм' : '—' }}</div>
              </v-col>
            </template>

            <v-col v-if="detail.type !== 'hardware'" cols="6" sm="4">
              <div class="text-caption text-medium-emphasis">Коэф. отхода</div>
              <div class="font-weight-medium">{{ detail.waste_factor ?? '—' }}</div>
            </v-col>
            <v-col cols="6" sm="4">
              <div class="text-caption text-medium-emphasis">Тег</div>
              <div class="font-weight-medium">{{ detail.material_tag || '—' }}</div>
            </v-col>
            <v-col cols="6" sm="4">
              <div class="text-caption text-medium-emphasis">Видимость</div>
              <div class="font-weight-medium">{{ visibilityLabel(detail.visibility) }}</div>
            </v-col>
          </v-row>
        </v-sheet>

        <v-divider />

        <v-sheet class="pa-4">
          <v-expansion-panels variant="accordion">
            <v-expansion-panel>
              <v-expansion-panel-title class="text-subtitle-1 font-weight-bold">
                <v-icon size="small" color="primary" class="mr-1">mdi-cog-outline</v-icon>
                Техническая информация
              </v-expansion-panel-title>
              <v-expansion-panel-text>
                <v-row dense>
                  <v-col cols="6" sm="4">
                    <div class="text-caption text-medium-emphasis">ID</div>
                    <div class="font-weight-medium">#{{ detail.id }}</div>
                  </v-col>
                  <v-col cols="6" sm="4">
                    <div class="text-caption text-medium-emphasis">Создан</div>
                    <div class="font-weight-medium">{{ formatDateTime(detail.created_at) }}</div>
                  </v-col>
                  <v-col cols="6" sm="4">
                    <div class="text-caption text-medium-emphasis">Обновлён</div>
                    <div class="font-weight-medium">{{ formatDateTime(detail.updated_at) }}</div>
                  </v-col>
                  <v-col cols="6" sm="4">
                    <div class="text-caption text-medium-emphasis">Цена проверена</div>
                    <div class="font-weight-medium">{{ formatDateTime(detail.price_checked_at) }}</div>
                  </v-col>
                  <v-col cols="6" sm="4">
                    <div class="text-caption text-medium-emphasis">Последний парсинг</div>
                    <div class="font-weight-medium">{{ formatDateTime(detail.last_parsed_at) }}</div>
                  </v-col>
                  <v-col cols="6" sm="4">
                    <div class="text-caption text-medium-emphasis">Статус парсинга</div>
                    <div class="font-weight-medium">
                      <v-chip
                        v-if="detail.last_parse_status"
                        :color="parseStatusColor(detail.last_parse_status)"
                        variant="tonal"
                        size="x-small"
                      >
                        {{ parseStatusLabel(detail.last_parse_status) }}
                      </v-chip>
                      <span v-else>—</span>
                    </div>
                  </v-col>
                  <v-col v-if="detail.source_url" cols="12">
                    <div class="text-caption text-medium-emphasis">URL источника</div>
                    <a :href="detail.source_url" target="_blank" class="text-primary text-body-2 text-decoration-none">
                      {{ detail.source_url }}
                      <v-icon size="x-small" class="ml-1">mdi-open-in-new</v-icon>
                    </a>
                  </v-col>
                  <v-col v-if="detail.last_parse_error" cols="12">
                    <div class="text-caption text-medium-emphasis">Ошибка парсинга</div>
                    <div class="text-error text-body-2">{{ detail.last_parse_error }}</div>
                  </v-col>
                  <v-col v-if="detail.metadata && Object.keys(detail.metadata).length > 0" cols="12">
                    <div class="text-caption text-medium-emphasis">Метаданные</div>
                    <v-expansion-panels variant="accordion" class="mt-1">
                      <v-expansion-panel>
                        <v-expansion-panel-title class="py-1 text-body-2">
                          Показать метаданные ({{ Object.keys(detail.metadata).length }} полей)
                        </v-expansion-panel-title>
                        <v-expansion-panel-text>
                          <pre class="text-caption" style="white-space: pre-wrap; word-break: break-all;">{{ JSON.stringify(detail.metadata, null, 2) }}</pre>
                        </v-expansion-panel-text>
                      </v-expansion-panel>
                    </v-expansion-panels>
                  </v-col>
                </v-row>
              </v-expansion-panel-text>
            </v-expansion-panel>
          </v-expansion-panels>
        </v-sheet>
      </v-card-text>

      <v-card-text v-else class="text-center pa-8 text-medium-emphasis">
        Не удалось загрузить данные
      </v-card-text>

      <v-divider />

      <v-card-actions>
        <v-btn v-if="detail && isOwner" color="primary" variant="tonal" prepend-icon="mdi-pencil" @click="$emit('edit', detail)">
          Редактировать
        </v-btn>
        <v-spacer />
        <v-btn variant="text" @click="close">Закрыть</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { fetchMaterialDetail } from '@/api/materialCatalog'
import type { MaterialDetail, TrustBreakdownItem } from '@/api/materialCatalog'
import TrustBadge from './TrustBadge.vue'

const props = defineProps<{
  modelValue: boolean
  materialId: number | null
}>()

const emit = defineEmits<{
  'update:modelValue': [val: boolean]
  edit: [material: MaterialDetail]
}>()

const loading = ref(false)
const detail = ref<MaterialDetail | null>(null)
const trustBreakdown = ref<TrustBreakdownItem[]>([])

const isOwner = ref(false)

watch(() => [props.modelValue, props.materialId], async ([open, id]) => {
  if (open && typeof id === 'number') {
    await loadDetail(id)
  } else if (!open) {
    detail.value = null
    trustBreakdown.value = []
  }
})

async function loadDetail(id: number) {
  loading.value = true
  try {
    const res = await fetchMaterialDetail(id)
    detail.value = res.data.material
    trustBreakdown.value = res.data.trust_breakdown
    // Simple owner check: user_id is present (server already checks access)
    isOwner.value = !!detail.value?.user_id
  } catch (e) {
    detail.value = null
    trustBreakdown.value = []
  } finally {
    loading.value = false
  }
}

function close() {
  emit('update:modelValue', false)
}

function formatPrice(v: number | string): string {
  const num = typeof v === 'string' ? parseFloat(v) : v
  return num.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function formatDateTime(d: string | null): string {
  if (!d) return '—'
  return new Date(d).toLocaleString('ru-RU', {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

function typeLabel(t: string): string {
  const map: Record<string, string> = { plate: 'Плита', edge: 'Кромка', facade: 'Фасад', hardware: 'Фурнитура' }
  return map[t] || t
}

function typeColor(t: string): string {
  const map: Record<string, string> = { plate: 'blue', edge: 'orange', facade: 'purple', hardware: 'teal' }
  return map[t] || 'grey'
}

function originLabel(o: string): string {
  const map: Record<string, string> = {
    manual: 'Вручную',
    url_parse: 'Парсинг URL',
    price_list: 'Прайс-лист',
    chrome_ext: 'Chrome расширение',
  }
  return map[o] || o
}

function visibilityLabel(v: string): string {
  const map: Record<string, string> = { private: 'Приватный', public: 'Публичный', curated: 'Кураторский' }
  return map[v] || v
}

function parseStatusLabel(s: string): string {
  const map: Record<string, string> = { ok: 'Успешно', failed: 'Ошибка', blocked: 'Заблокирован', unsupported: 'Не поддерживается' }
  return map[s] || s
}

function parseStatusColor(s: string): string {
  const map: Record<string, string> = { ok: 'success', failed: 'error', blocked: 'warning', unsupported: 'grey' }
  return map[s] || 'grey'
}
</script>

<style scoped>
.trust-breakdown-item {
  min-height: 48px;
}
.border-b {
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
</style>
