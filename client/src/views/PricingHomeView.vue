<template>
  <PageContainer>
    <PageHeader
      title="Цены"
      subtitle="Управление ценообразованием по типам объектов"
    />

    <div v-if="selectedOperation" class="selected-operation-card">
      <div class="selected-operation-card__body">
        <div class="selected-operation-card__label">Операция</div>
        <div class="selected-operation-card__name">{{ selectedOperation.name }}</div>
      </div>
      <v-btn
        variant="text"
        size="small"
        color="primary"
        class="selected-operation-card__action"
        @click="showOperationPicker = true"
      >
        Сменить
      </v-btn>
    </div>

    <div class="pricing-home-actions">
      <v-btn
        color="primary"
        variant="flat"
        prepend-icon="mdi-plus"
        @click="showTypeSelector = true"
      >
        + Добавить цену
      </v-btn>
    </div>

    <div class="pricing-home-grid">
      <div
        v-for="section in sections"
        :key="section.key"
        class="pricing-section-card"
        :class="{ 'pricing-section-card--available': section.available }"
        @click="section.available ? $router.push(section.to) : undefined"
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
          <v-chip
            v-if="section.available"
            size="small"
            color="primary"
            variant="tonal"
          >
            Открыть
          </v-chip>
          <v-chip v-else size="small" variant="outlined" color="grey">
            Скоро
          </v-chip>
        </div>
      </div>
    </div>

    <v-dialog v-model="showTypeSelector" max-width="520">
      <v-card>
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
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'

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
.pricing-home-actions {
  display: flex;
  align-items: center;
  margin-top: 12px;
  margin-bottom: 16px;
}

.selected-operation-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-top: 12px;
  padding: 14px 16px;
  border: 1px solid rgba(var(--v-theme-primary), 0.14);
  border-radius: 12px;
  background: rgba(var(--v-theme-primary), 0.05);
}

.selected-operation-card__body {
  min-width: 0;
}

.selected-operation-card__label {
  font-size: 11px;
  line-height: 1.3;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgba(0, 0, 0, 0.5);
}

.selected-operation-card__name {
  margin-top: 4px;
  font-size: 15px;
  line-height: 1.35;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.84);
  overflow-wrap: anywhere;
}

.selected-operation-card__action {
  flex-shrink: 0;
}

.pricing-home-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 16px;
}

.pricing-section-card {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 20px;
  border: 1px solid rgba(0, 0, 0, 0.1);
  border-radius: 8px;
  background: white;
  transition: box-shadow 0.15s ease, border-color 0.15s ease;
}

.pricing-section-card--available {
  cursor: pointer;
}

.pricing-section-card--available:hover {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
  border-color: rgba(var(--v-theme-primary), 0.4);
}

.pricing-section-icon {
  flex-shrink: 0;
}

.pricing-section-body {
  flex: 1;
  min-width: 0;
}

.pricing-section-title {
  font-size: 15px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.85);
  margin-bottom: 2px;
}

.pricing-section-desc {
  font-size: 13px;
  color: rgba(0, 0, 0, 0.5);
}

.pricing-section-action {
  flex-shrink: 0;
}

.type-selector-subtitle {
  margin-top: 4px;
  font-size: 13px;
  color: rgba(0, 0, 0, 0.5);
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
  padding: 14px 16px;
  border: 1px solid rgba(0, 0, 0, 0.08);
  border-radius: 10px;
  background: #fff;
  text-align: left;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}

.type-option-card:hover {
  border-color: rgba(var(--v-theme-primary), 0.3);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
  background: rgba(var(--v-theme-primary), 0.02);
}

.type-option-body {
  flex: 1;
  min-width: 0;
}

.type-option-title {
  font-size: 14px;
  font-weight: 600;
  color: rgba(0, 0, 0, 0.84);
}

.type-option-desc {
  margin-top: 2px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.5);
}

@media (max-width: 640px) {
  .selected-operation-card {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
