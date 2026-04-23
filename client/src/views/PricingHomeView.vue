<template>
  <PageContainer>
    <PageHeader
      title="Цены"
      subtitle="Управление ценообразованием по типам объектов"
    >
      <template #actions>
        <v-btn
          color="primary"
          variant="flat"
          prepend-icon="mdi-plus"
          @click="showTypeSelector = true"
        >
          Добавить цену
        </v-btn>
      </template>
    </PageHeader>

    <SectionCard
      v-if="selectedOperation"
      class="pricing-selected-card"
      title="Выбранная операция"
      subtitle="Источник цены будет добавлен к выбранной технологической операции."
    >
      <AppDetailMetaGrid
        :items="[
          { label: 'Операция', value: selectedOperation.name },
          { label: 'Категория', value: selectedOperation.category || '—' },
          { label: 'Единица', value: selectedOperation.unit || '—' },
        ]"
      />
      <template #actions>
        <AppActionFooter>
          <v-btn
            variant="text"
            color="primary"
            @click="showOperationPicker = true"
          >
            Сменить
          </v-btn>
        </AppActionFooter>
      </template>
    </SectionCard>

    <SectionCard
      class="pricing-home-card"
      title="Разделы ценообразования"
      subtitle="Основные направления работы с ценами и источниками."
    >
      <div class="pricing-home-grid">
        <button
          v-for="section in sections"
          :key="section.key"
          type="button"
          class="pricing-section-card"
          :class="{ 'pricing-section-card--available': section.available }"
          :disabled="!section.available"
          @click="section.available ? router.push(section.to) : undefined"
      >
        <div class="pricing-section-icon">
          <v-icon size="32" :color="section.available ? 'primary' : 'grey-lighten-1'">
            {{ section.icon }}
          </v-icon>
        </div>
        <div class="pricing-section-body">
          <div class="pricing-section-title">{{ section.title }}</div>
          <div class="pricing-section-desc">{{ section.description }}</div>
        </div>
        <div class="pricing-section-action">
          <StatusChip
            v-if="section.available"
            size="small"
            color="primary"
            variant="tonal"
          >
            Открыть
          </StatusChip>
          <StatusChip v-else size="small" variant="outlined" color="grey">
            Скоро
          </StatusChip>
        </div>
        </button>
      </div>
    </SectionCard>

    <v-dialog v-model="showTypeSelector" max-width="520">
      <v-card class="pricing-dialog-card">
        <v-card-title class="pa-4 pb-2">
          <div class="text-subtitle-1 font-weight-medium">Добавить цену</div>
          <div class="type-selector-subtitle">
            Выберите, для чего вы хотите задать цену
          </div>
        </v-card-title>

        <v-card-text class="pa-4 pt-2">
          <div class="type-selector-grid">
            <button
              v-for="option in typeOptions"
              :key="option.key"
              type="button"
              class="type-option-card"
              @click="handleTypeSelection(option.key)"
            >
              <v-icon size="24" color="primary">{{ option.icon }}</v-icon>
              <div class="type-option-body">
                <div class="type-option-title">{{ option.title }}</div>
                <div class="type-option-desc">{{ option.description }}</div>
              </div>
              <v-icon size="18" color="grey">mdi-chevron-right</v-icon>
            </button>
          </div>
        </v-card-text>
        <AppActionFooter>
          <v-btn variant="text" @click="showTypeSelector = false">Отмена</v-btn>
        </AppActionFooter>
      </v-card>
    </v-dialog>

    <PricingOperationPicker
      v-model="showOperationPicker"
      @selected="handleOperationSelected"
    />

    <EvidenceCreateModal
      v-if="selectedOperation"
      v-model="showCreatePriceModal"
      target-type="operation"
      :target-id="selectedOperation.id"
      :unit="selectedOperation.unit"
      :title="`Добавить цену: ${selectedOperation.name}`"
      :operation-name="selectedOperation.name"
      @created="handlePriceCreated"
    />

    <v-snackbar
      v-model="feedback.show"
      color="success"
      timeout="3200"
      location="bottom right"
    >
      {{ feedback.message }}
    </v-snackbar>
  </PageContainer>
</template>

<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { useRouter } from 'vue-router'
import EvidenceCreateModal from '@/components/evidence/EvidenceCreateModal.vue'
import PricingOperationPicker from '@/components/pricing/PricingOperationPicker.vue'
import AppActionFooter from '@/components/layout/AppActionFooter.vue'
import AppDetailMetaGrid from '@/components/layout/AppDetailMetaGrid.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import StatusChip from '@/components/layout/StatusChip.vue'

type PricingTargetType = 'operation' | 'material' | 'labor' | 'product'

interface OperationRow {
  id: number
  name: string
  category?: string
  unit?: string
}

const router = useRouter()

const showTypeSelector = ref(false)
const showOperationPicker = ref(false)
const showCreatePriceModal = ref(false)
const selectedOperation = ref<OperationRow | null>(null)
const feedback = ref({
  show: false,
  message: '',
})

const sections = [
  {
    key: 'operations',
    title: 'Операции',
    description: 'Цены на технологические операции',
    icon: 'mdi-cog-outline',
    to: '/pricing/operations',
    available: true,
  },
  {
    key: 'labor',
    title: 'Труд',
    description: 'Нормочасовые ставки и профили работ',
    icon: 'mdi-account-hard-hat-outline',
    to: '/pricing/labor',
    available: true,
  },
  {
    key: 'materials',
    title: 'Материалы',
    description: 'Цены на материалы и комплектующие',
    icon: 'mdi-package-variant-closed',
    to: '/pricing/materials',
    available: false,
  },
  {
    key: 'products',
    title: 'Изделия',
    description: 'Котировки на готовые изделия',
    icon: 'mdi-door',
    to: '/pricing/products',
    available: false,
  },
]

const typeOptions = [
  {
    key: 'operation' as PricingTargetType,
    title: 'Операция',
    description: 'Технологические операции и их источники цены',
    icon: 'mdi-cog-outline',
  },
  {
    key: 'material' as PricingTargetType,
    title: 'Материал',
    description: 'Материалы и комплектующие',
    icon: 'mdi-package-variant-closed',
  },
  {
    key: 'labor' as PricingTargetType,
    title: 'Работа',
    description: 'Источники нормо-часов и ставки',
    icon: 'mdi-account-hard-hat-outline',
  },
  {
    key: 'product' as PricingTargetType,
    title: 'Изделие',
    description: 'Готовые изделия и котировки',
    icon: 'mdi-door',
  },
]

function handleTypeSelection(type: PricingTargetType) {
  showTypeSelector.value = false

  if (type === 'operation') {
    showOperationPicker.value = true
    return
  }

  const targetRoute = {
    material: '/materials',
    labor: '/pricing/labor',
    product: '/products',
  }[type]

  void router.push(targetRoute)
}

async function handleOperationSelected(operation: OperationRow) {
  showOperationPicker.value = false
  selectedOperation.value = operation
  await nextTick()
  showCreatePriceModal.value = true
}

function handlePriceCreated() {
  showCreatePriceModal.value = false

  if (!selectedOperation.value) return

  feedback.value = {
    show: true,
    message: `Цена добавлена для операции ${selectedOperation.value.name}`,
  }
}
</script>

<style scoped>
.pricing-selected-card,
.pricing-home-card {
  background: color-mix(in srgb, var(--md-sys-color-surface-container-low) 94%, transparent);
}

.pricing-selected-card :deep(.md3-section-card__actions) {
  padding: 0;
}

.pricing-home-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 18px;
}

.pricing-section-card {
  appearance: none;
  display: flex;
  align-items: center;
  gap: 16px;
  width: 100%;
  padding: 22px 20px;
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  border-radius: var(--ds-radius-16);
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.04), transparent 120px),
    var(--ds-surface-card);
  text-align: left;
  transition: box-shadow 0.15s ease, border-color 0.15s ease, transform 0.15s ease;
}

.pricing-section-card--available {
  cursor: pointer;
}

.pricing-section-card--available:hover {
  box-shadow: var(--ds-shadow-dropdown);
  border-color: rgba(var(--v-theme-primary), 0.38);
  transform: translateY(-1px);
}

.pricing-section-card:disabled {
  cursor: default;
  opacity: 0.78;
}

.pricing-section-icon {
  flex-shrink: 0;
}

.pricing-section-body {
  flex: 1;
  min-width: 0;
}

.pricing-section-title {
  font-size: 0.98rem;
  font-weight: 700;
  color: rgb(var(--v-theme-on-surface));
  margin-bottom: 4px;
}

.pricing-section-desc {
  font-size: 0.84rem;
  line-height: 1.55;
  color: rgba(var(--v-theme-on-surface-variant), 0.92);
}

.pricing-section-action {
  flex-shrink: 0;
}

.pricing-dialog-card {
  border-radius: var(--md-sys-shape-corner-extra-large);
  overflow: hidden;
}

.type-selector-subtitle {
  margin-top: 4px;
  font-size: 0.84rem;
  color: rgba(var(--v-theme-on-surface-variant), 0.92);
}

.type-selector-grid {
  display: grid;
  gap: 10px;
}

.type-option-card {
  display: flex;
  align-items: center;
  gap: 14px;
  width: 100%;
  min-height: 72px;
  padding: 16px 16px;
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  border-radius: var(--md-sys-shape-corner-large);
  background: rgba(var(--v-theme-surface-container-low), 0.92);
  text-align: left;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}

.type-option-card:hover {
  border-color: rgba(var(--v-theme-primary), 0.3);
  box-shadow: var(--ds-shadow-dropdown);
  background: rgba(var(--v-theme-secondary-container), 0.52);
}

.type-option-body {
  flex: 1;
  min-width: 0;
}

.type-option-title {
  font-size: 0.92rem;
  font-weight: 700;
  color: rgb(var(--v-theme-on-surface));
}

.type-option-desc {
  margin-top: 2px;
  font-size: 0.8rem;
  line-height: 1.5;
  color: rgba(var(--v-theme-on-surface-variant), 0.9);
}

</style>
