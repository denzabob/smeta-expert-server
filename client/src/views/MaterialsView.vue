<template>
  <v-container fluid class="pa-0">
    <v-sheet class="pa-4" color="surface">
      <div class="d-flex flex-wrap align-center ga-3">
        <div>
          <div class="text-h5 font-weight-medium">{{ pageTitle }}</div>
          <div class="text-medium-emphasis">{{ pageSubtitle }}</div>
        </div>
        <v-spacer />
        <v-btn
            color="primary"
            prepend-icon="mdi-plus"
            class="text-none"
            @click="openCreateDialog"
        >
          Добавить
        </v-btn>
        <v-btn
            color="primary"
            variant="tonal"
            prepend-icon="mdi-file-import"
            class="text-none"
            @click="showImportDialog = true"
        >
          Импорт прайса
        </v-btn>
        <v-btn
            variant="text"
            prepend-icon="mdi-refresh"
            class="text-none"
            :loading="loading"
            @click="fetchMaterials"
        >
          Обновить
        </v-btn>
      </div>
    </v-sheet>

    <v-sheet class="pa-4">
      <v-row class="mb-3" align="center" dense>
        <v-col cols="12" md="4">
          <v-text-field
              v-model="search"
              label="Поиск по названию или артикулу"
              prepend-inner-icon="mdi-magnify"
              hide-details
              clearable
              @click:clear="search = ''"
          />
        </v-col>
        <v-col cols="12" md="3" v-if="!lockType">
          <v-select
              v-model="typeFilter"
              :items="typeOptions"
              item-title="label"
              item-value="value"
              label="Тип"
              clearable
              hide-details
          />
        </v-col>
        <v-col cols="12" md="2">
          <v-select
              v-model="unitFilter"
              :items="unitOptions"
              label="Ед. изм."
              clearable
              hide-details
          />
        </v-col>
        <v-col cols="12" md="3">
          <v-select
              v-model="originFilter"
              :items="originOptions"
              item-title="label"
              item-value="value"
              label="Источник"
              clearable
              hide-details
          />
        </v-col>
      </v-row>

        <v-data-table
          :headers="dynamicHeaders"
          :items="filteredMaterials"
          :loading="loading"
          class="elevation-1"
          item-key="id"
          density="comfortable"
        >
        <!-- Источник -->
        <template #item.origin="{ item }">
          <v-chip
              size="small"
              :color="item.origin === 'parser' ? 'orange' : 'blue'"
              variant="tonal"
              class="text-caption"
          >
            <v-icon
                v-if="item.origin === 'parser'"
                start
                icon="mdi-robot"
                size="small"
            />
            <v-icon v-else start icon="mdi-account" size="small" />
            {{ item.origin === 'parser' ? 'Парсер' : 'Пользователь' }}
          </v-chip>
        </template>

        <!-- Название / Артикул -->
        <template #item.name="{ item }">
          <div class="d-flex flex-column">
            <span class="font-weight-medium">{{ item.name }}</span>
            <span class="text-caption text-medium-emphasis">{{ item.article }}</span>
          </div>
        </template>

        <!-- Тип -->
        <template #item.type="{ item }">
          <v-chip size="small" variant="tonal" color="primary">
            {{ typeLabels[item.type] ?? item.type }}
          </v-chip>
        </template>

        <!-- Facade: Основа -->
        <template #item.base_material="{ item }">
          {{ facadeBaseMaterialLabel(item) }}
        </template>

        <!-- Facade: Толщина -->
        <template #item.thickness_mm="{ item }">
          {{ item.thickness_mm ? item.thickness_mm + ' мм' : '—' }}
        </template>

        <!-- Facade: Декор -->
        <template #item.decor="{ item }">
          {{ facadeDecorLabel(item) }}
        </template>

        <!-- Facade: Коллекция -->
        <template #item.collection="{ item }">
          {{ facadeCollectionLabel(item) }}
        </template>

        <!-- Facade: Группа -->
        <template #item.price_group="{ item }">
          {{ facadePriceGroupLabel(item) }}
        </template>

        <!-- Facade: Тип покрытия -->
        <template #item.finish_type="{ item }">
          {{ facadeFinishLabel(item) }}
        </template>

        <!-- Единица -->
        <template #item.unit="{ item }">
          <v-chip size="small" variant="text">{{ item.unit }}</v-chip>
        </template>

        <!-- Цена -->
        <template #item.price_per_unit="{ item }">
          {{ formatPrice(item.price_per_unit) }}
        </template>

        <!-- Версия -->
        <template #item.version="{ item }">
          <v-chip size="small" variant="outlined">
            v{{ item.version ?? 1 }}
          </v-chip>
        </template>

        <!-- Статус -->
        <template #item.is_active="{ item }">
          <v-chip
              size="small"
              :color="item.is_active ? 'success' : 'grey'"
              variant="tonal"
          >
            {{ item.is_active ? 'Активен' : 'Выключен' }}
          </v-chip>
        </template>

        <!-- Ссылка -->
        <template #item.source_url="{ item }">
          <a
              v-if="item.source_url"
              :href="item.source_url"
              target="_blank"
              rel="noreferrer"
              class="text-primary"
          >
            Ссылка
          </a>
          <span v-else class="text-medium-emphasis">—</span>
        </template>

        <!-- Действия -->
        <template #item.actions="{ item }">
          <div class="d-flex ga-2 justify-end">
            <!-- История цен — для всех -->
            <v-btn
                icon
                size="small"
                variant="text"
                @click="openHistoryDialog(item)"
            >
              <v-icon icon="mdi-history" size="small" />
              <v-tooltip activator="parent" location="top">
                История цен
              </v-tooltip>
            </v-btn>

            <!-- Редактировать — только для user -->
            <v-btn
                v-if="item.origin === 'user'"
                icon
                size="small"
                variant="text"
                color="primary"
                @click="openEditDialog(item)"
            >
              <v-icon icon="mdi-pencil" size="small" />
              <v-tooltip activator="parent" location="top">
                Редактировать
              </v-tooltip>
            </v-btn>

            <!-- Замок для parser -->
            <v-tooltip v-else location="top">
              <template #activator="{ props }">
                <v-icon
                    v-bind="props"
                    icon="mdi-lock-outline"
                    size="small"
                    color="grey-darken-1"
                />
              </template>
              <span>Редактирование недоступно (загружено парсером)</span>
            </v-tooltip>
          </div>
        </template>

        <!-- Нет данных -->
        <template #no-data>
          <div class="text-center pa-8">
            <div class="text-subtitle-1 mb-2">Нет материалов</div>
            <div class="text-medium-emphasis mb-4">
              Добавьте материал вручную или запустите парсер
            </div>
            <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreateDialog">
              Добавить материал
            </v-btn>
          </div>
        </template>
      </v-data-table>
    </v-sheet>

    <!-- Dialog: Create / Edit -->
    <v-dialog v-model="dialog" max-width="640" persistent>
      <v-card>
        <v-card-title class="d-flex align-center">
          <v-icon class="mr-2" :icon="editingId ? 'mdi-pencil' : 'mdi-plus'" />
          <span class="text-h6">
            {{ editingId ? 'Редактировать материал' : 'Новый материал' }}
          </span>
          <v-spacer />
          <v-btn icon variant="text" @click="closeDialog">
            <v-icon icon="mdi-close" />
          </v-btn>
        </v-card-title>
        <v-divider />

        <v-card-text>
          <v-form ref="formRef" v-model="formValid">
            <v-row dense>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.name"
                  label="Название"
                  :rules="[rules.required]"
                  required
                  @blur="parseDimensionsFromName"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.article"
                  label="Артикул"
                  :rules="[rules.required]"
                  required
                  :readonly="form.type === 'facade'"
                  :hint="form.type === 'facade' ? 'Генерируется автоматически' : ''"
                  :persistent-hint="form.type === 'facade'"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-select
                  v-model="form.type"
                  :items="formTypeOptions"
                  item-title="label"
                  item-value="value"
                  label="Тип"
                  :rules="[rules.required]"
                  required
                  :readonly="lockType"
                  :hint="lockType ? 'Тип зафиксирован' : ''"
                  :persistent-hint="lockType"
                  @update:model-value="handleTypeChange"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-select
                  v-model="form.unit"
                  :items="unitOptions"
                  label="Единица"
                  :rules="[rules.required]"
                  required
                  :readonly="form.type === 'plate' || form.type === 'facade'"
                  :hint="form.type === 'plate' ? 'Для плит всегда м²' : form.type === 'facade' ? 'Для фасадов всегда м²' : ''"
                  persistent-hint
                />
              </v-col>
              <!-- Поля размеров для type=plate -->
              <v-col v-if="form.type === 'plate'" cols="12" md="4">
                <v-text-field
                  v-model.number="form.length_mm"
                  label="Длина листа, мм"
                  type="number"
                  min="1"
                  hint="Длина листа в миллиметрах"
                  persistent-hint
                />
              </v-col>
              <v-col v-if="form.type === 'plate'" cols="12" md="4">
                <v-text-field
                  v-model.number="form.width_mm"
                  label="Ширина листа, мм"
                  type="number"
                  min="1"
                  hint="Ширина листа в миллиметрах"
                  persistent-hint
                />
              </v-col>
              <v-col v-if="form.type === 'plate'" cols="12" md="4">
                <v-text-field
                  v-model.number="form.thickness_mm"
                  label="Толщина, мм"
                  type="number"
                  min="0.1"
                  step="0.1"
                  hint="Толщина листа в миллиметрах"
                  persistent-hint
                />
              </v-col>
              <!-- Поля для type=facade — структурированная спецификация -->
              <v-col v-if="form.type === 'facade'" cols="12">
                <v-divider class="mb-2" />
                <div class="text-subtitle-2 mb-2">Спецификация фасада</div>
              </v-col>
              <v-col v-if="form.type === 'facade'" cols="12" md="4">
                <v-select
                  v-model="facadeSpec.base_material"
                  :items="baseMaterialOptions"
                  item-title="label"
                  item-value="value"
                  label="Основа"
                  hint="Базовый материал фасада"
                  persistent-hint
                />
              </v-col>
              <v-col v-if="form.type === 'facade'" cols="12" md="4">
                <v-text-field
                  v-model.number="form.thickness_mm"
                  label="Толщина, мм"
                  type="number"
                  min="1"
                  step="1"
                  hint="Толщина фасада в миллиметрах"
                  persistent-hint
                />
              </v-col>
              <v-col v-if="form.type === 'facade'" cols="12" md="4">
                <v-select
                  v-model="facadeSpec.finish_type"
                  :items="finishTypeOptions"
                  item-title="label"
                  item-value="value"
                  label="Тип покрытия"
                  hint="Метод отделки фасада"
                  persistent-hint
                />
              </v-col>
              <v-col v-if="form.type === 'facade'" cols="12" md="4">
                <v-text-field
                  v-model="facadeSpec.finish_name"
                  label="Название покрытия"
                  placeholder="ПВХ плёнка, Пластик"
                  hint="Человекочитаемое название"
                  persistent-hint
                />
              </v-col>
              <v-col v-if="form.type === 'facade'" cols="12" md="4">
                <v-select
                  v-model="facadeSpec.finish_variant"
                  :items="finishVariantOptions"
                  item-title="label"
                  item-value="value"
                  label="Вид плёнки"
                  clearable
                  hint="Матовая / Глянец / Металлик"
                  persistent-hint
                />
              </v-col>
              <v-col v-if="form.type === 'facade'" cols="12" md="4">
                <v-text-field
                  v-model="facadeSpec.collection"
                  label="Коллекция"
                  placeholder="Standart, Premium"
                  hint="Коллекция производителя"
                  persistent-hint
                />
              </v-col>
              <v-col v-if="form.type === 'facade'" cols="12" md="4">
                <v-text-field
                  v-model="facadeSpec.decor"
                  label="Декор"
                  placeholder="Сантьяго SF 022"
                  hint="Код или название декора"
                  persistent-hint
                />
              </v-col>
              <v-col v-if="form.type === 'facade'" cols="12" md="4">
                <v-select
                  v-model="facadeSpec.price_group"
                  :items="filmGroupOptions"
                  label="Группа цен"
                  clearable
                  hint="Группа плёнки 1–5"
                  persistent-hint
                />
              </v-col>
              <v-col v-if="form.type === 'facade'" cols="12" md="4">
                <v-text-field
                  v-model="facadeSpec.film_article"
                  label="Артикул плёнки (необяз.)"
                  placeholder="SF022-001"
                  hint="Внутренний артикул плёнки"
                  persistent-hint
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model.number="form.price_per_unit"
                  label="Цена за единицу"
                  type="number"
                  min="0"
                  step="0.01"
                  :rules="[rules.required, rules.nonNegative]"
                  required
                  prefix="₽"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.source_url"
                  label="Ссылка на товар"
                  placeholder="https://"
                  type="url"
                  :loading="fetchingByUrl"
                  :append-inner-icon="fetchingByUrl ? 'mdi-loading' : 'mdi-cloud-download'"
                  @click:append-inner="prefillFromUrl"
                  :rules="[rules.url]"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.last_price_screenshot_path"
                  label="Скриншот"
                  placeholder="URL или путь"
                />
              </v-col>
              <v-col cols="12">
                <v-select
                  v-model="form.operation_ids"
                  :items="operations"
                  item-title="name"
                  item-value="id"
                  label="Связанные операции"
                  multiple
                  chips
                  clearable
                  hide-details
                />
              </v-col>
              <v-col cols="12">
                <v-switch
                  v-model="form.is_active"
                  color="primary"
                  label="Материал активен"
                />
              </v-col>
            </v-row>
          </v-form>
        </v-card-text>

        <v-card-actions class="px-4 pb-4">
          <v-spacer />
          <v-btn variant="text" class="text-none" @click="closeDialog">
            Отмена
          </v-btn>
          <v-btn
            color="primary"
            class="text-none"
            :loading="saving"
            :disabled="!formValid"
            @click="saveMaterial"
          >
            Сохранить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Dialog: History -->
    <v-dialog v-model="historyDialog" max-width="720">
      <v-card>
        <v-card-title class="d-flex align-center">
          <div class="flex-grow-1">
            <div class="d-flex align-center">
              <v-icon class="mr-2" icon="mdi-history" />
              <span class="text-h6">История цен</span>
            </div>
            <div class="text-caption text-medium-emphasis mt-1">
              {{ historyMaterial?.name }}
            </div>
          </div>
          <v-btn icon variant="text" @click="historyDialog = false">
            <v-icon icon="mdi-close" />
          </v-btn>
        </v-card-title>
        <v-divider />
        <v-card-text>
          <v-data-table
            :headers="historyHeaders"
            :items="priceHistory"
            density="comfortable"
          >
            <template #item.price_per_unit="{ item }">
              {{ formatPrice(item.price_per_unit) }}
            </template>
            <template #item.valid_from="{ item }">
              {{ new Date(item.valid_from).toLocaleDateString('ru-RU') }}
            </template>
            <template #item.source_url="{ item }">
              <a
                v-if="item.source_url"
                :href="item.source_url"
                target="_blank"
                rel="noreferrer"
              >
                Ссылка
              </a>
              <span v-else>—</span>
            </template>
            <template #no-data>
              <div class="text-center pa-4 text-medium-emphasis">
                Нет записей истории цен
              </div>
            </template>
          </v-data-table>
        </v-card-text>
      </v-card>
    </v-dialog>

    <!-- Диалог импорта прайса -->
    <PriceImportDialog
      v-model="showImportDialog"
      target-type="materials"
      @imported="fetchMaterials"
    />

    <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="3000">
      {{ snackbar.message }}
    </v-snackbar>
  </v-container>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/api/axios'
import PriceImportDialog from '@/components/PriceImportDialog.vue'

const props = withDefaults(defineProps<{
  defaultType?: string
  lockType?: boolean
}>(), {
  defaultType: '',
  lockType: false,
})

const route = useRoute()

type MaterialType = 'plate' | 'edge' | 'hardware' | 'facade'
type MaterialUnit = 'м²' | 'м.п.' | 'шт'
type MaterialOrigin = 'user' | 'parser'

type Material = {
  id: number
  user_id: number | null
  origin: MaterialOrigin
  name: string
  article: string
  type: MaterialType
  unit: MaterialUnit
  price_per_unit: number
  source_url: string | null
  last_price_screenshot_path: string | null
  is_active: boolean
  version: number
  operation_ids?: number[]
  thickness_mm?: number
  length_mm?: number
  width_mm?: number
  metadata?: Record<string, any>
}

type MaterialForm = Omit<Material, 'id' | 'user_id'> & { 
  id?: number
  length_mm?: number
  width_mm?: number
  thickness_mm?: number
  metadata: Record<string, any>
}

type Operation = {
  id: number
  name: string
}

// --- Страница ---
const isFacadeMode = computed(() => props.defaultType === 'facade')
const pageTitle = computed(() => isFacadeMode.value ? 'Готовая продукция' : 'Материалы')
const pageSubtitle = computed(() => isFacadeMode.value ? 'Каталог фасадов' : 'Просмотр и корректировка свойств')

// --- Колонки таблицы ---
const headersDefault = [
  { title: 'Название / Артикул', key: 'name', width: '480px' },
  { title: 'Источник', key: 'origin', width: '140px', align: 'center' as const },
  { title: 'Тип', key: 'type', width: '50px' },
  { title: 'Ед.', key: 'unit', width: '30px', align: 'center' as const },
  { title: 'Цена', key: 'price_per_unit', width: '120px', align: 'end' as const },
  { title: 'Версия', key: 'version', width: '90px', align: 'center' as const },
  { title: 'Статус', key: 'is_active', width: '110px' },
  { title: 'Ссылка', key: 'source_url', width: '100px' },
  { title: '', key: 'actions', width: '120px', sortable: false, align: 'end' as const },
]

const headersFacade = [
  { title: 'Название / Артикул', key: 'name', width: '260px' },
  { title: 'Основа', key: 'base_material', width: '80px' },
  { title: 'Толщина', key: 'thickness_mm', width: '80px', align: 'center' as const },
  { title: 'Покрытие', key: 'finish_type', width: '100px' },
  { title: 'Коллекция', key: 'collection', width: '110px' },
  { title: 'Декор', key: 'decor', width: '140px' },
  { title: 'Группа', key: 'price_group', width: '80px', align: 'center' as const },
  { title: 'Цена за м²', key: 'price_per_unit', width: '110px', align: 'end' as const },
  { title: 'Версия', key: 'version', width: '80px', align: 'center' as const },
  { title: 'Источник', key: 'origin', width: '110px', align: 'center' as const },
  { title: '', key: 'actions', width: '100px', sortable: false, align: 'end' as const },
]

const dynamicHeaders = computed(() => isFacadeMode.value ? headersFacade : headersDefault)
const historyHeaders = [
  { title: 'Дата', key: 'valid_from', width: '140px' },
  { title: 'Версия', key: 'version', width: '100px', align: 'center' as const },
  { title: 'Цена', key: 'price_per_unit', width: '120px', align: 'end' as const },
  { title: 'Ссылка', key: 'source_url', width: '120px' },
]

const typeOptions = [
  { label: 'Плита', value: 'plate' },
  { label: 'Кромка', value: 'edge' },
  { label: 'Фурнитура', value: 'hardware' },
]

const unitOptions: MaterialUnit[] = ['м²', 'м.п.', 'шт']

const originOptions = [
  { label: 'Пользователь', value: 'user' },
  { label: 'Парсер', value: 'parser' },
]

const typeLabels: Record<MaterialType, string> = {
  plate: 'Плита',
  edge: 'Кромка',
  hardware: 'Фурнитура',
  facade: 'Фасад',
}

// Маппинг типа материала к единице измерения
const TYPE_TO_UNIT: Record<MaterialType, MaterialUnit> = {
  plate: 'м²',
  edge: 'м.п.',
  hardware: 'шт',
  facade: 'м²',
}

// Опции типа для формы: в режиме фасадов показываем только facade
const formTypeOptions = computed(() => {
  if (isFacadeMode.value) {
    return [{ label: 'Фасад', value: 'facade' }]
  }
  return typeOptions
})

const materials = ref<Material[]>([])
const operations = ref<Operation[]>([])
const loading = ref(false)
const saving = ref(false)
const fetchingByUrl = ref(false)
const dialog = ref(false)
const showImportDialog = ref(false)
const editingId = ref<number | null>(null)
const formValid = ref(false)
const formRef = ref()
const search = ref('')
const typeFilter = ref<MaterialType | null>(null)
const unitFilter = ref<MaterialUnit | null>(null)
const originFilter = ref<MaterialOrigin | null>(null) // Новый фильтр

const form = reactive<MaterialForm>({
  origin: 'user',
  name: '',
  article: '',
  type: (props.defaultType as MaterialType) || 'plate',
  unit: props.defaultType === 'facade' ? 'м²' : 'м²',
  price_per_unit: 0,
  source_url: '',
  last_price_screenshot_path: '',
  is_active: true,
  version: 1,
  operation_ids: [],
  length_mm: undefined,
  width_mm: undefined,
  thickness_mm: undefined,
  metadata: {},
})

// === Facade Spec — structured fields (canonical format per ticket) ===
const facadeSpec = reactive({
  base_material: 'mdf',
  finish_type: '',
  finish_name: '',
  finish_variant: '',
  collection: '',
  decor: '',
  price_group: '',
  film_article: '',
})

const baseMaterialOptions = [
  { value: 'mdf', label: 'МДФ' },
  { value: 'dsp', label: 'ДСП' },
  { value: 'mdf_aglo', label: 'МДФ-Агло' },
  { value: 'fanera', label: 'Фанера' },
  { value: 'massiv', label: 'Массив' },
]

const finishTypeOptions = [
  { value: 'pvc_film', label: 'ПВХ плёнка' },
  { value: 'plastic', label: 'Пластик' },
  { value: 'enamel', label: 'Эмаль' },
  { value: 'veneer', label: 'Шпон' },
  { value: 'solid_wood', label: 'Массив' },
  { value: 'aluminum_frame', label: 'Алюм. рамка' },
  { value: 'other', label: 'Другое' },
]

const filmGroupOptions = ['1', '2', '3', '4', '5']

const finishVariantOptions = [
  { value: 'matte', label: 'Матовая' },
  { value: 'gloss', label: 'Глянец' },
  { value: 'metallic', label: 'Металлик' },
  { value: 'soft_touch', label: 'Софт тач' },
  { value: 'textured', label: 'Текстурная' },
]

const finishLabelsMap: Record<string, string> = Object.fromEntries(
  finishTypeOptions.map(o => [o.value, o.label])
)
const baseMaterialLabelsMap: Record<string, string> = Object.fromEntries(
  baseMaterialOptions.map(o => [o.value, o.label])
)
const finishVariantLabelsMap: Record<string, string> = Object.fromEntries(
  finishVariantOptions.map(o => [o.value, o.label])
)

// Helpers to read facade fields from metadata (canonical + legacy formats)
function facadeBaseMaterialLabel(item: Material): string {
  const bm = item.metadata?.base?.material ?? item.metadata?.base_material
  return baseMaterialLabelsMap[bm] ?? bm ?? '—'
}

function facadeFinishLabel(item: Material): string {
  const ft = item.metadata?.finish?.type ?? item.metadata?.finish_type
  return finishLabelsMap[ft] ?? ft ?? '—'
}

function facadeDecorLabel(item: Material): string {
  return item.metadata?.decor ?? item.metadata?.finish?.name ?? '—'
}

function facadeCollectionLabel(item: Material): string {
  return item.metadata?.collection ?? '—'
}

function facadePriceGroupLabel(item: Material): string {
  return item.metadata?.price_group ?? '—'
}

// Build facade name client-side (mirrors Material::buildFacadeSpecName)
// Format: "{collection} / Группа {price_group} / {decor} / {thickness_mm}мм"
function buildFacadeSpecName(): string {
  const collection = facadeSpec.collection?.trim()
  const group = facadeSpec.price_group?.trim()
  const decor = facadeSpec.decor?.trim()
  const thickness = form.thickness_mm ?? 16

  if (collection && group && decor) {
    return `${collection} / Группа ${group} / ${decor} / ${thickness}мм`
  }

  // Fallback: list non-empty parts
  const parts: string[] = []
  parts.push(baseMaterialLabelsMap[facadeSpec.base_material] ?? facadeSpec.base_material.toUpperCase())
  parts.push(`${thickness} мм`)
  if (facadeSpec.finish_type) parts.push(finishLabelsMap[facadeSpec.finish_type] ?? facadeSpec.finish_type)
  if (collection) parts.push(collection)
  if (group) parts.push(`группа ${group}`)
  if (decor) parts.push(decor)
  return parts.join(', ')
}

// Sync facade spec into form before save (canonical nested metadata)
function syncFacadeSpecToForm() {
  form.metadata = {
    base: { material: facadeSpec.base_material },
    thickness_mm: form.thickness_mm ?? 16,
    finish: {
      type: facadeSpec.finish_type,
      name: facadeSpec.finish_name || (finishLabelsMap[facadeSpec.finish_type] ?? ''),
      variant: facadeSpec.finish_variant || null,
    },
    collection: facadeSpec.collection || null,
    decor: facadeSpec.decor || null,
    price_group: facadeSpec.price_group || null,
    film_article: facadeSpec.film_article || null,
  }
  // Auto-generate name and article from spec
  form.name = buildFacadeSpecName()
  form.article = 'FACADE:spec' // backend will compute the real MD5
}

// Load facade spec from existing material metadata (handles legacy formats)
function loadFacadeSpecFromMetadata(metadata: Record<string, any>) {
  // Canonical format: base.material, finish.type/name/variant, collection, decor, price_group
  facadeSpec.base_material = metadata?.base?.material ?? metadata?.base_material ?? 'mdf'
  facadeSpec.finish_type = metadata?.finish?.type ?? metadata?.finish_type ?? ''
  facadeSpec.finish_name = metadata?.finish?.name ?? ''
  facadeSpec.finish_variant = metadata?.finish?.variant ?? ''
  facadeSpec.collection = metadata?.collection ?? metadata?.finish?.collection ?? ''
  facadeSpec.decor = metadata?.decor ?? metadata?.finish?.name ?? ''
  facadeSpec.price_group = metadata?.price_group ?? ''
  facadeSpec.film_article = metadata?.film_article ?? metadata?.finish?.code ?? ''
}

function resetFacadeSpec() {
  facadeSpec.base_material = 'mdf'
  facadeSpec.finish_type = ''
  facadeSpec.finish_name = ''
  facadeSpec.finish_variant = ''
  facadeSpec.collection = ''
  facadeSpec.decor = ''
  facadeSpec.price_group = ''
  facadeSpec.film_article = ''
}

const snackbar = reactive({
  show: false,
  message: '',
  color: 'success',
})

const historyDialog = ref(false)
const historyMaterial = ref<Material | null>(null)
const priceHistory = ref<any[]>([])

const rules = {
  required: (v: any) => !!v || 'Обязательное поле',
  nonNegative: (v: number) => v >= 0 || 'Не может быть отрицательной',
  url: (v: string) => !v || /^https?:\/\//.test(v) || 'Должен начинаться с http:// или https://',
}

const filteredMaterials = computed(() => {
  const term = search.value.trim().toLowerCase()
  return materials.value.filter((item) => {
    const matchesTerm =
        !term ||
        item.name.toLowerCase().includes(term) ||
        item.article.toLowerCase().includes(term)
    // В режиме «Готовая продукция» тип уже отфильтрован на сервере,
    // но оставляем клиентский фильтр как fallback
    const matchesType = isFacadeMode.value
        ? item.type === 'facade'
        : item.type !== 'facade' && (!typeFilter.value || item.type === typeFilter.value)
    const matchesUnit = !unitFilter.value || item.unit === unitFilter.value
    const matchesOrigin = !originFilter.value || item.origin === originFilter.value
    return matchesTerm && matchesType && matchesUnit && matchesOrigin
  })
})

const formatPrice = (value: number) =>
    new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' }).format(value || 0)

// Обработчик смены типа материала - автоматически устанавливаем unit
const handleTypeChange = () => {
  form.unit = TYPE_TO_UNIT[form.type as MaterialType] || form.unit
  // При смене типа на не-plate очищаем размеры листа
  if (form.type !== 'plate') {
    form.length_mm = undefined
    form.width_mm = undefined
  }
  // При смене типа на не-plate и не-facade очищаем толщину
  if (form.type !== 'plate' && form.type !== 'facade') {
    form.thickness_mm = undefined
  }
  // При смене на не-facade очищаем metadata фасада
  if (form.type !== 'facade') {
    form.metadata = {}
    resetFacadeSpec()
  } else {
    // Инициализируем facade spec
    if (!form.metadata) form.metadata = {}
    resetFacadeSpec()
  }
}

// Парсинг размеров из названия (debounce)
let parseNameTimeout: ReturnType<typeof setTimeout>
const parseDimensionsFromName = () => {
  clearTimeout(parseNameTimeout)
  parseNameTimeout = setTimeout(() => {
    if (form.type !== 'plate' || !form.name) return

    const dimensions = extractDimensions(form.name)
    if (dimensions) {
      form.length_mm = dimensions[0]
      form.width_mm = dimensions[1]
      form.thickness_mm = dimensions[2] ?? undefined
    }
  }, 300)
}

// Извлечение размеров из текста
// Поддерживаемые форматы: "2800х2070х16", "2800 х 2070 х 16 мм", "2800*2070*16"
const extractDimensions = (text: string): [number, number, number | null] | null => {
  if (!text) return null

  // Нормализуем разделители
  let normalized = text.replace(/[×*xX]/g, 'х')

  // Паттерн 1: число х число х число мм
  const pattern1 = /(\d+)\s*х\s*(\d+)\s*х\s*(\d+)\s*мм/u
  let match = normalized.match(pattern1)
  if (match) {
    return [parseInt(match[1]!), parseInt(match[2]!), parseInt(match[3]!)]
  }

  // Паттерн 2: число х число х число (без мм)
  const pattern2 = /(\d+)\s*х\s*(\d+)\s*х\s*(\d+)(?:\s|$)/u
  match = normalized.match(pattern2)
  if (match) {
    return [parseInt(match[1]!), parseInt(match[2]!), parseInt(match[3]!)]
  }

  // Паттерн 3: число х число (если нет третьего)
  const pattern3 = /(\d+)\s*х\s*(\d+)/u
  match = normalized.match(pattern3)
  if (match) {
    return [parseInt(match[1]!), parseInt(match[2]!), null]
  }

  return null
}

const fetchMaterials = async () => {
  loading.value = true
  try {
    const params: Record<string, string> = {}
    if (isFacadeMode.value) {
      params.type = 'facade'
    }
    const res = await api.get('/api/materials', { params })
    materials.value = isFacadeMode.value
      ? res.data
      : (res.data || []).filter((item: Material) => item.type !== 'facade')
  } catch (error) {
    console.error(error)
    snackbar.message = 'Ошибка загрузки материалов'
    snackbar.color = 'error'
    snackbar.show = true
  } finally {
    loading.value = false
  }
}

const fetchOperations = async () => {
  try {
    const res = await api.get('/api/operations')
    operations.value = res.data
  } catch (error) {
    console.error(error)
    operations.value = []
  }
}

const resetForm = () => {
  const defaultMaterialType = (props.defaultType as MaterialType) || 'plate'
  form.id = undefined
  form.origin = 'user'
  form.name = ''
  form.article = ''
  form.type = defaultMaterialType
  form.unit = TYPE_TO_UNIT[defaultMaterialType] || 'м²'
  form.price_per_unit = 0
  form.source_url = ''
  form.last_price_screenshot_path = ''
  form.is_active = true
  form.version = 1
  form.operation_ids = []
  form.length_mm = undefined
  form.width_mm = undefined
  form.thickness_mm = undefined
  form.metadata = {}
  resetFacadeSpec()
}

const openCreateDialog = () => {
  editingId.value = null
  resetForm()
  dialog.value = true
}

const openEditDialog = (item: Material) => {
  editingId.value = item.id
  Object.assign(form, { ...item, metadata: item.metadata ? { ...item.metadata } : {} })
  // Load facade spec from existing metadata (handles all 3 formats)
  if (item.type === 'facade') {
    loadFacadeSpecFromMetadata(item.metadata || {})
  } else {
    resetFacadeSpec()
  }
  dialog.value = true
}

const openHistoryDialog = async (item: Material) => {
  historyMaterial.value = item
  historyDialog.value = true
  try {
    const res = await api.get(`/api/materials/${item.id}/history`)
    priceHistory.value = res.data
  } catch {
    priceHistory.value = []
  }
}

const closeDialog = () => {
  dialog.value = false
}

const prefillFromUrl = async () => {
  if (!form.source_url) {
    snackbar.message = 'Сначала введите ссылку'
    snackbar.color = 'warning'
    snackbar.show = true
    return
  }

  fetchingByUrl.value = true
  try {
    const res = await api.post('/api/materials/fetch', { source_url: form.source_url })

    const data = res.data

    if (data.material) {
      Object.assign(form, {
        name: data.material.name ?? '',
        article: data.material.article ?? '',
        type: data.material.type ?? form.type,
        unit: data.material.unit ?? form.unit,
        price_per_unit: Number(data.material.price_per_unit ?? form.price_per_unit),
        source_url: data.material.source_url ?? form.source_url,
        last_price_screenshot_path: data.material.last_price_screenshot_path ?? '',
        is_active: data.material.is_active ?? true,
        version: data.material.version ?? form.version,
      })

      snackbar.message = 'Данные подставлены из базы'
      snackbar.color = 'success'
      snackbar.show = true
      return
    }

    if (data.suggested) {
      const suggested = data.suggested
      if (suggested.name) form.name = suggested.name
      if (suggested.article) form.article = suggested.article
      if (suggested.price_per_unit !== null && suggested.price_per_unit !== undefined) {
        form.price_per_unit = Number(suggested.price_per_unit)
      }
      if (suggested.type) form.type = suggested.type
      if (suggested.unit) form.unit = suggested.unit
      if (suggested.source_url) form.source_url = suggested.source_url
      form.last_price_screenshot_path = ''

      // Определяем цвет в зависимости от статуса
      let snackbarColor = 'success'
      if (data.source === 'blocked') {
        snackbarColor = 'error'
      } else if (data.source === 'fetch_failed' || data.source === 'fetched' && !suggested.name) {
        snackbarColor = 'warning'
      }

      snackbar.message = data.message || 'Данные получены со страницы'
      snackbar.color = snackbarColor
      snackbar.show = true
      return
    }

    snackbar.message = 'Не удалось получить данные по ссылке'
    snackbar.color = 'warning'
    snackbar.show = true
  } catch (error) {
    console.error(error)
    snackbar.message = 'Ошибка при запросе ссылки'
    snackbar.color = 'error'
    snackbar.show = true
  } finally {
    fetchingByUrl.value = false
  }
}

const saveMaterial = async () => {
  // Sync facade spec fields into form metadata before validation
  if (form.type === 'facade') {
    syncFacadeSpecToForm()
  }

  const valid = (await formRef.value?.validate())?.valid ?? false
  if (!valid) return

  saving.value = true
  try {
    const url = editingId.value ? `/api/materials/${editingId.value}` : '/api/materials'
    const method = editingId.value ? 'PUT' : 'POST'

    const response = await api({
      method,
      url,
      data: form,
    })

    await fetchMaterials()
    closeDialog()
    snackbar.message = editingId.value ? 'Материал обновлён' : 'Материал создан'
    snackbar.color = 'success'
    snackbar.show = true
  } catch (error: any) {
    console.error('Error saving material:', error)
    snackbar.message = error.response?.data?.message || error.message || 'Ошибка при сохранении'
    snackbar.color = 'error'
    snackbar.show = true
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await fetchMaterials()
  await fetchOperations()
})
</script>

<style scoped>
.text-none {
  text-transform: none;
}
</style>

