<template>
  <div>
    <v-card class="mb-4">
      <v-card-text>
        <v-row align="center" dense>
          <v-col cols="12" sm="3">
            <v-text-field
              v-model="search"
              prepend-inner-icon="mdi-magnify"
              label="Поиск правил"
              hide-details
              density="compact"
              clearable
              @update:model-value="debouncedLoad"
            />
          </v-col>
          <v-col cols="6" sm="2">
            <v-select
              v-model="materialTypeFilter"
              :items="materialTypeOptions"
              label="Тип материала"
              hide-details
              density="compact"
              clearable
              @update:model-value="loadList"
            />
          </v-col>
          <v-col cols="6" sm="2">
            <v-select
              v-model="statusFilter"
              :items="statusOptions"
              label="Статус"
              hide-details
              density="compact"
              clearable
              @update:model-value="loadList"
            />
          </v-col>
          <v-col cols="12" sm="3">
            <v-text-field
              v-model="sourceFilter"
              label="Источник"
              hide-details
              density="compact"
              clearable
              @update:model-value="debouncedLoad"
            />
          </v-col>
          <v-col cols="12" sm="2" class="text-right">
            <v-btn color="primary" prepend-icon="mdi-plus" @click="openCreateWithPreset()">
              Новое правило
            </v-btn>
          </v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <v-card :loading="loading">
      <v-data-table-server
        :headers="headers"
        :items="items"
        :items-length="total"
        :loading="loading"
        :page="page"
        :items-per-page="perPage"
        density="compact"
        no-data-text="Нет правил"
        @update:page="page = $event; loadList()"
        @update:items-per-page="perPage = $event; loadList()"
      >
        <template #item.is_active="{ item }">
          <v-chip :color="item.is_active ? 'success' : 'grey'" size="small" variant="tonal">
            {{ item.is_active ? 'Активно' : 'Выключено' }}
          </v-chip>
        </template>

        <template #item.scope="{ item }">
          <div class="text-body-2">
            <div>{{ materialTypeLabel(item.material_type) }}</div>
            <div class="text-caption text-medium-emphasis">{{ item.source || 'Любой источник' }}</div>
          </div>
        </template>

        <template #item.mapping="{ item }">
          <div class="text-caption mapping-col">
            {{ mappingSummary(item) }}
          </div>
        </template>

        <template #item.updated_at="{ item }">
          {{ formatDate(item.updated_at) }}
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex ga-1">
            <v-btn size="x-small" variant="text" icon="mdi-pencil-outline" @click="openEdit(item)" />
            <v-btn
              size="x-small"
              variant="text"
              :icon="item.is_active ? 'mdi-toggle-switch' : 'mdi-toggle-switch-off-outline'"
              :color="item.is_active ? 'success' : 'grey'"
              :loading="togglingId === item.id"
              @click="toggleActive(item)"
            />
            <v-btn
              size="x-small"
              variant="text"
              icon="mdi-delete-outline"
              color="error"
              :loading="deletingId === item.id"
              @click="removeRule(item)"
            />
          </div>
        </template>
      </v-data-table-server>
    </v-card>

    <v-dialog v-model="dialog" max-width="980" persistent>
      <v-card>
        <v-card-title class="d-flex align-center">
          {{ editingId ? 'Редактировать managed rule' : 'Создание managed rule' }}
          <v-spacer />
          <v-chip color="primary" size="small" variant="tonal">Простой режим</v-chip>
        </v-card-title>

        <v-card-text>
          <v-alert type="info" variant="tonal" class="mb-3">
            <div class="text-subtitle-2 mb-1">Как добавить правило</div>
            <ol class="guide-list">
              <li>Введите пример названия материала.</li>
              <li>Нажмите "Разобрать строку" и назначьте блоки как длина/ширина/толщина.</li>
              <li>Система автоматически заполнит технические поля.</li>
              <li>Проверьте правило на кейсе.</li>
              <li>Сохраните правило.</li>
            </ol>
          </v-alert>

          <v-alert
            v-if="isPrefilledFromFailure"
            type="warning"
            variant="tonal"
            class="mb-3"
          >
            <div class="d-flex align-center">
              <div>
                <div class="text-subtitle-2">Форма предзаполнена на основе неразобранного кейса</div>
                <div class="text-body-2">Можно отредактировать данные перед сохранением.</div>
              </div>
              <v-spacer />
              <v-btn size="small" variant="outlined" color="warning" @click="clearPrefilledValues">
                Очистить предзаполнение
              </v-btn>
            </div>
          </v-alert>

          <v-alert
            v-if="formError"
            type="error"
            variant="tonal"
            class="mb-3"
            closable
            @click:close="formError = ''"
          >
            {{ formError }}
          </v-alert>

          <div class="section-block">
            <div class="section-title">Основные поля</div>
            <v-row>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.name"
                  label="Название правила *"
                  density="compact"
                  variant="outlined"
                  placeholder="plate_auto_rule_01"
                  :bg-color="fieldBg('name')"
                />
              </v-col>
              <v-col cols="12" md="3">
                <v-select
                  v-model="form.material_type"
                  :items="materialTypeOptions"
                  label="Тип материала"
                  density="compact"
                  variant="outlined"
                  clearable
                  :bg-color="fieldBg('material_type')"
                />
              </v-col>
              <v-col cols="12" md="3">
                <v-text-field
                  v-model="form.source"
                  label="Источник"
                  density="compact"
                  variant="outlined"
                  placeholder="chrome_ext"
                  :bg-color="fieldBg('source')"
                />
              </v-col>
              <v-col cols="12" md="8">
                <v-textarea
                  v-model="form.description"
                  label="Описание"
                  density="compact"
                  variant="outlined"
                  rows="2"
                  auto-grow
                  placeholder="Для строк с размерами в формате 2750*1830 и толщиной в мм"
                  :bg-color="fieldBg('description')"
                />
              </v-col>
              <v-col cols="12" md="4" class="d-flex align-center">
                <v-switch
                  v-model="form.is_active"
                  label="Правило активно"
                  density="compact"
                  color="success"
                  hide-details
                />
              </v-col>
            </v-row>
          </div>

          <div class="section-block">
            <div class="section-title">Пример строки и назначение значений</div>
            <div class="section-help">
                Введите пример, разберите его на числовые блоки и назначьте каждому роль. В простом режиме шаблон строится универсально по размерной структуре, без жесткой привязки к бренду/серии.
            </div>

            <v-row>
              <v-col cols="12" md="9">
                <v-text-field
                  v-model="form.example_input"
                  label="Пример названия материала *"
                  density="compact"
                  variant="outlined"
                  placeholder="Тэффи 594 КМ 2750*1830 16 мм СФ"
                  :bg-color="fieldBg('example_input')"
                  @blur="analyzeExampleIfNeeded"
                />
              </v-col>
              <v-col cols="12" md="3" class="d-flex align-center">
                <v-btn color="primary" variant="tonal" block @click="analyzeExample">
                  <v-icon start>mdi-auto-fix</v-icon>
                  Разобрать строку
                </v-btn>
              </v-col>
            </v-row>

            <v-alert v-if="numericBlocks.length === 0" type="info" variant="tonal" class="mt-2">
              Добавьте пример строки и нажмите "Разобрать строку", чтобы назначить length/width/thickness.
            </v-alert>

            <div v-if="numericBlocks.length > 0" class="mt-3">
              <div class="text-subtitle-2 mb-2">Назначение блоков</div>
              <v-row v-for="block in numericBlocks" :key="block.id" class="mb-1" align="center">
                <v-col cols="12" md="5">
                  <v-chip size="small" color="indigo" variant="outlined" class="mr-2">{{ block.raw }}</v-chip>
                  <span class="text-caption text-medium-emphasis">значение: {{ block.value }}</span>
                </v-col>
                <v-col cols="12" md="4">
                  <v-select
                    v-model="block.role"
                    :items="roleOptions"
                    label="Назначение"
                    density="compact"
                    variant="outlined"
                    hide-details
                    @update:model-value="syncTechnicalFromSimple"
                  />
                </v-col>
                <v-col cols="12" md="3">
                  <span class="text-caption text-medium-emphasis">блок #{{ block.index + 1 }}</span>
                </v-col>
              </v-row>
            </div>

            <v-divider class="my-3" />

            <div class="text-subtitle-2 mb-2">Результат автозаполнения</div>
            <div class="text-caption text-medium-emphasis mb-2">
              Система формирует технические параметры на основе назначенных блоков. При необходимости их можно поправить ниже в расширенных настройках.
            </div>
            <v-sheet border rounded class="pa-2 mb-2">
              <div class="text-caption text-medium-emphasis">Универсальный regex (simple mode)</div>
              <div class="text-body-2 preview-block">{{ form.pattern || '—' }}</div>
            </v-sheet>
            <v-row>
              <v-col cols="4">
                <v-sheet border rounded class="pa-2">
                  <div class="text-caption text-medium-emphasis">length_mm</div>
                  <div class="text-body-2">{{ previewValue(form.expected_length_mm) }}</div>
                </v-sheet>
              </v-col>
              <v-col cols="4">
                <v-sheet border rounded class="pa-2">
                  <div class="text-caption text-medium-emphasis">width_mm</div>
                  <div class="text-body-2">{{ previewValue(form.expected_width_mm) }}</div>
                </v-sheet>
              </v-col>
              <v-col cols="4">
                <v-sheet border rounded class="pa-2">
                  <div class="text-caption text-medium-emphasis">thickness_mm</div>
                  <div class="text-body-2">{{ previewValue(form.expected_thickness_mm) }}</div>
                </v-sheet>
              </v-col>
            </v-row>
          </div>

          <div class="section-block">
            <div class="section-title">Проверка правила</div>
            <v-row>
              <v-col cols="12">
                <v-textarea
                  v-model="previewTestText"
                  label="Тестовый кейс для проверки"
                  density="compact"
                  variant="outlined"
                  rows="2"
                  auto-grow
                  placeholder="Если пусто, будет использован пример строки выше"
                  :bg-color="fieldBg('preview_test_text')"
                />
              </v-col>
            </v-row>

            <v-alert
              v-if="previewError"
              type="error"
              variant="tonal"
              class="mb-3"
              closable
              @click:close="previewError = ''"
            >
              {{ previewError }}
            </v-alert>

            <v-card v-if="previewResult" variant="outlined" class="mb-2">
              <v-card-text>
                <div class="d-flex align-center mb-2">
                  <div class="text-subtitle-2">Результат preview</div>
                  <v-spacer />
                  <v-chip :color="previewResult.success ? 'success' : 'error'" size="small" variant="tonal">
                    {{ previewResult.success ? 'SUCCESS' : 'FAIL' }}
                  </v-chip>
                </div>

                <v-row>
                  <v-col cols="12" md="4">
                    <div class="text-caption text-medium-emphasis">length_mm</div>
                    <div class="text-body-2">{{ previewValue(previewResult.length_mm) }}</div>
                  </v-col>
                  <v-col cols="12" md="4">
                    <div class="text-caption text-medium-emphasis">width_mm</div>
                    <div class="text-body-2">{{ previewValue(previewResult.width_mm) }}</div>
                  </v-col>
                  <v-col cols="12" md="4">
                    <div class="text-caption text-medium-emphasis">thickness_mm</div>
                    <div class="text-body-2">{{ previewValue(previewResult.thickness_mm) }}</div>
                  </v-col>

                  <v-col cols="12" md="4">
                    <div class="text-caption text-medium-emphasis">confidence</div>
                    <div class="text-body-2">{{ previewResult.confidence }}</div>
                  </v-col>
                  <v-col cols="12" md="4">
                    <div class="text-caption text-medium-emphasis">strategy / rule</div>
                    <div class="text-body-2">{{ previewResult.strategy_name || '—' }}</div>
                  </v-col>
                  <v-col cols="12" md="4">
                    <div class="text-caption text-medium-emphasis">rule_type / source</div>
                    <div class="text-body-2">{{ previewResult.rule_type || '—' }} / {{ previewResult.source }}</div>
                  </v-col>

                  <v-col cols="12">
                    <div class="text-caption text-medium-emphasis">normalized text</div>
                    <v-sheet border rounded class="pa-2 text-body-2 preview-block">{{ previewResult.normalized_text }}</v-sheet>
                  </v-col>

                  <v-col v-if="!previewResult.success" cols="12">
                    <div class="text-caption text-medium-emphasis">Причина fail</div>
                    <div class="text-body-2 text-error">{{ previewResult.error_reason || 'unknown' }}</div>
                  </v-col>
                </v-row>
              </v-card-text>
            </v-card>
          </div>

          <v-expansion-panels v-model="advancedOpenPanels" variant="accordion" class="mb-2">
            <v-expansion-panel value="advanced">
              <v-expansion-panel-title>
                <v-icon start size="small">mdi-tune-variant</v-icon>
                Расширенные настройки (технические параметры)
              </v-expansion-panel-title>
              <v-expansion-panel-text>
                <v-row>
                  <v-col cols="6" md="3">
                    <v-text-field
                      v-model.number="form.priority"
                      label="Priority"
                      density="compact"
                      variant="outlined"
                      type="number"
                      min="1"
                      placeholder="500"
                      hint="Порядок применения: меньше = раньше."
                      persistent-hint
                     />
                   </v-col>
                   <v-col cols="6" md="3">
                     <v-text-field
                       v-model.number="form.confidence"
                       label="Confidence"
                       density="compact"
                       variant="outlined"
                       type="number"
                       min="0"
                       max="1"
                       step="0.01"
                       placeholder="0.80"
                       hint="Оценка достоверности (0..1)."
                       persistent-hint
                     />
                   </v-col>
                   <v-col cols="12" md="6" class="d-flex align-center">
                     <v-switch
                       v-model="form.use_normalized_text"
                       label="Использовать нормализованный текст"
                     />
                   </v-col>
                   <v-col cols="12">
                     <v-text-field
                       v-model="form.pattern"
                       label="Regex pattern"
                       density="compact"
                       variant="outlined"
                       placeholder="(\\d{3,5})\\s*(?:x|х|/|\\*)\\s*(\\d{3,5})..."
                       hint="Основной шаблон поиска размеров."
                       persistent-hint
                     />
                   </v-col>
                   <v-col cols="4">
                     <v-text-field
                       v-model.number="form.capture_length_mm"
                       label="Capture length_mm"
                       density="compact"
                       variant="outlined"
                       type="number"
                       min="1"
                       placeholder="1"
                     />
                   </v-col>
                   <v-col cols="4">
                     <v-text-field
                       v-model.number="form.capture_width_mm"
                       label="Capture width_mm"
                       density="compact"
                       variant="outlined"
                       type="number"
                       min="1"
                       placeholder="2"
                     />
                   </v-col>
                   <v-col cols="4">
                     <v-text-field
                       v-model.number="form.capture_thickness_mm"
                       label="Capture thickness_mm"
                       density="compact"
                       variant="outlined"
                       type="number"
                       min="1"
                       placeholder="3"
                     />
                   </v-col>
                 </v-row>
               </v-expansion-panel-text>
             </v-expansion-panel>
           </v-expansion-panels>
         </v-card-text>

         <v-card-actions>
           <div class="text-caption text-medium-emphasis">* Обязательные поля: название, пример строки, regex pattern (формируется автоматически).</div>
           <v-spacer />
           <v-btn color="secondary" variant="tonal" :loading="previewLoading" @click="previewRule">
             Проверить
           </v-btn>
           <v-btn variant="text" @click="dialog = false">Отмена</v-btn>
           <v-btn color="primary" variant="tonal" :loading="saving" @click="saveRule">
             {{ editingId ? 'Сохранить' : 'Сохранить правило' }}
           </v-btn>
         </v-card-actions>
       </v-card>
     </v-dialog>

    <v-dialog v-model="duplicateDialog" max-width="980">
      <v-card>
        <v-card-title class="d-flex align-center">
          Возможные дубли managed rule
          <v-spacer />
          <v-chip color="warning" size="small" variant="tonal">{{ duplicateCandidates.length }}</v-chip>
        </v-card-title>
        <v-card-text>
          <v-alert type="warning" variant="tonal" class="mb-3">
            Найдены существующие правила с совпадающим или очень похожим шаблоном. Чтобы не создать дубль, откройте существующее правило или вернитесь к редактированию.
          </v-alert>

          <v-list lines="three" density="comfortable" class="duplicate-list">
            <v-list-item v-for="candidate in duplicateCandidates" :key="candidate.rule.id">
              <template #prepend>
                <v-chip size="x-small" color="warning" variant="outlined">{{ duplicateReasonLabel(candidate.reason) }}</v-chip>
              </template>

              <v-list-item-title>
                #{{ candidate.rule.id }} {{ candidate.rule.name }}
              </v-list-item-title>
              <v-list-item-subtitle>
                {{ materialTypeLabel(candidate.rule.material_type) }} / {{ candidate.rule.source || 'Любой источник' }}
              </v-list-item-subtitle>
              <v-list-item-subtitle class="mt-1 text-caption preview-block">
                {{ candidate.rule.config.pattern }}
              </v-list-item-subtitle>
              <v-list-item-subtitle class="text-caption text-medium-emphasis">
                {{ candidate.details }}
              </v-list-item-subtitle>

              <template #append>
                <v-btn
                  size="small"
                  variant="outlined"
                  color="primary"
                  @click="openExistingFromDuplicate(candidate.rule)"
                >
                  Открыть правило
                </v-btn>
              </template>
            </v-list-item>
          </v-list>
        </v-card-text>
        <v-card-actions>
          <v-btn variant="text" @click="closeDuplicateDialog">
            Вернуться к редактированию
          </v-btn>
          <v-spacer />
          <v-btn color="warning" variant="tonal" :loading="saving" @click="saveDespiteDuplicates">
            Сохранить всё равно
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
   </div>
 </template>
 
 <script setup lang="ts">
 import { onMounted, ref } from 'vue'
 import {
   adminMaterialDimensionsApi,
   type MaterialDimensionMaterialType,
   type MaterialDimensionParsePreviewResult,
   type MaterialDimensionRule,
   type MaterialDimensionRulePreset,
   type UpsertMaterialDimensionRulePayload,
 } from '@/api/materialDimensions'
 
 type NumericRole = 'length' | 'width' | 'thickness' | 'ignore'
 
 interface NumericBlock {
   id: string
   index: number
   raw: string
   value: number
   role: NumericRole
 }

type DuplicateReason = 'exact_pattern' | 'exact_scope_pattern' | 'similar_coverage'

interface DuplicateCandidate {
  rule: MaterialDimensionRule
  reason: DuplicateReason
  details: string
}
 
 interface RuleFormState {
   name: string
   description: string
   is_active: boolean
   priority: number
   material_type: MaterialDimensionMaterialType | null
   source: string
   pattern: string
   flags: string
   use_normalized_text: boolean
   capture_length_mm: number | null
   capture_width_mm: number | null
   capture_thickness_mm: number | null
   fixed_length_mm: number | null
   fixed_width_mm: number | null
   fixed_thickness_mm: number | null
   example_input: string
   expected_length_mm: number | null
   expected_width_mm: number | null
   expected_thickness_mm: number | null
   confidence: number
 }
 
 const materialTypeOptions = [
   { title: 'Плита (plate)', value: 'plate' },
   { title: 'Кромка (edge)', value: 'edge' },
   { title: 'Фурнитура (hardware)', value: 'hardware' },
   { title: 'Фасад (facade)', value: 'facade' },
   { title: 'Комплектующая (fitting)', value: 'fitting' },
 ]
 
 const statusOptions = [
   { title: 'Активные', value: 'active' },
   { title: 'Выключенные', value: 'inactive' },
 ]
 
 const roleOptions = [
   { title: 'Длина (length)', value: 'length' },
   { title: 'Ширина (width)', value: 'width' },
   { title: 'Толщина (thickness)', value: 'thickness' },
   { title: 'Игнорировать', value: 'ignore' },
 ]
 
 const headers = [
   { title: 'Правило', key: 'name', sortable: false, width: '24%' },
   { title: 'Scope', key: 'scope', sortable: false, width: '18%' },
   { title: 'Mapping', key: 'mapping', sortable: false, width: '26%' },
   { title: 'Priority', key: 'priority', sortable: false, width: '8%' },
   { title: 'Статус', key: 'is_active', sortable: false, width: '10%' },
   { title: 'Обновлено', key: 'updated_at', sortable: false, width: '8%' },
   { title: '', key: 'actions', sortable: false, width: '6%' },
 ]
 
 const loading = ref(false)
 const saving = ref(false)
 const togglingId = ref<number | null>(null)
 const deletingId = ref<number | null>(null)
 const items = ref<MaterialDimensionRule[]>([])
 const total = ref(0)
 const page = ref(1)
 const perPage = ref(25)
 
 const search = ref('')
 const materialTypeFilter = ref<MaterialDimensionMaterialType | null>(null)
 const statusFilter = ref<'active' | 'inactive' | null>(null)
 const sourceFilter = ref('')
 
 const dialog = ref(false)
 const editingId = ref<number | null>(null)
 const formError = ref('')
 const previewError = ref('')
 const previewLoading = ref(false)
 const previewResult = ref<MaterialDimensionParsePreviewResult | null>(null)
 const previewTestText = ref('')
 
 const advancedOpenPanels = ref<string[]>([])
 const numericBlocks = ref<NumericBlock[]>([])
 const isPrefilledFromFailure = ref(false)
 const prefilledFields = ref<string[]>([])
const duplicateDialog = ref(false)
const duplicateCandidates = ref<DuplicateCandidate[]>([])
const pendingPayload = ref<UpsertMaterialDimensionRulePayload | null>(null)
 
 const form = ref<RuleFormState>(newForm())
 
 let searchTimeout: ReturnType<typeof setTimeout> | null = null
 
 function newForm(): RuleFormState {
   return {
     name: '',
     description: '',
     is_active: true,
     priority: 500,
     material_type: null,
     source: '',
     pattern: '',
     flags: 'u',
     use_normalized_text: true,
     capture_length_mm: null,
     capture_width_mm: null,
     capture_thickness_mm: null,
     fixed_length_mm: null,
     fixed_width_mm: null,
     fixed_thickness_mm: null,
     example_input: '',
     expected_length_mm: null,
     expected_width_mm: null,
     expected_thickness_mm: null,
     confidence: 0.8,
   }
 }
 
 function debouncedLoad() {
   if (searchTimeout) {
     clearTimeout(searchTimeout)
   }
   searchTimeout = setTimeout(() => loadList(), 350)
 }
 
 async function loadList() {
   loading.value = true
   try {
     const params: {
       search?: string
       material_type?: MaterialDimensionMaterialType
       source?: string
       is_active?: boolean
       page: number
       per_page: number
     } = {
       page: page.value,
       per_page: perPage.value,
     }
 
     if (search.value.trim()) {
       params.search = search.value.trim()
     }
     if (materialTypeFilter.value) {
       params.material_type = materialTypeFilter.value
     }
     if (statusFilter.value === 'active') {
       params.is_active = true
     }
     if (statusFilter.value === 'inactive') {
       params.is_active = false
     }
     if (sourceFilter.value.trim()) {
       params.source = sourceFilter.value.trim()
     }
 
     const response = await adminMaterialDimensionsApi.listRules(params)
     items.value = response.data
     total.value = response.meta.total
   } catch (error) {
     console.error('Failed to load material dimension rules', error)
   } finally {
     loading.value = false
   }
 }
 
 function materialTypeLabel(type: MaterialDimensionMaterialType | null): string {
   if (!type) return 'Любой тип'
 
   const found = materialTypeOptions.find((item) => item.value === type)
   return found?.title || type
 }
 
 function formatDate(value: string | null): string {
   if (!value) return '—'
   return new Date(value).toLocaleString('ru-RU')
 }
 
 function previewValue(value: number | null): string {
   return value === null ? '—' : String(value)
 }
 
 function mappingSummary(rule: MaterialDimensionRule): string {
   const chunks: string[] = []
   const captures = rule.config.captures || {}
   const fixed = rule.config.fixed || {}
 
   if (captures.length_mm) chunks.push(`L=#${captures.length_mm}`)
   if (captures.width_mm) chunks.push(`W=#${captures.width_mm}`)
   if (captures.thickness_mm) chunks.push(`T=#${captures.thickness_mm}`)
   if (fixed.length_mm) chunks.push(`L=${fixed.length_mm}`)
   if (fixed.width_mm) chunks.push(`W=${fixed.width_mm}`)
   if (fixed.thickness_mm) chunks.push(`T=${fixed.thickness_mm}`)
 
   return chunks.length > 0 ? chunks.join(' | ') : 'Нет mapping'
 }
 
 function toNullableNumber(value: number | null): number | null {
   if (value === null || Number.isNaN(value)) {
     return null
   }
   return Number(value)
 }
 
 function isFieldPrefilled(fieldName: string): boolean {
   return prefilledFields.value.includes(fieldName)
 }
 
 function fieldBg(fieldName: string): string | undefined {
   return isFieldPrefilled(fieldName) ? 'amber-lighten-5' : undefined
 }
 
 function markPrefilledField(fieldName: string) {
   if (!prefilledFields.value.includes(fieldName)) {
     prefilledFields.value.push(fieldName)
   }
 }
 
 function clearPrefilledValues() {
   form.value = newForm()
   numericBlocks.value = []
   previewTestText.value = ''
   previewResult.value = null
   previewError.value = ''
   isPrefilledFromFailure.value = false
   prefilledFields.value = []
 }

function normalizeString(value: string | null | undefined): string {
  return (value || '').trim().toLowerCase()
}
 
 function applySuggestedRoles(blocks: NumericBlock[]): NumericBlock[] {
   if (blocks.length === 0) {
     return blocks
   }
 
   const output = blocks.map((block) => ({ ...block, role: 'ignore' as NumericRole }))
   const valueIndexed = output.map((block, idx) => ({ idx, value: block.value }))
 
   const sortedDesc = valueIndexed.sort((a, b) => b.value - a.value)
   if (sortedDesc.length >= 2) {
    const first = sortedDesc[0]
    const second = sortedDesc[1]
    if (first && second) {
      const pair = [first.idx, second.idx].sort((a, b) => a - b)
      const leftIdx = pair[0]
      const rightIdx = pair[1]
      if (leftIdx !== undefined && output[leftIdx]) {
        output[leftIdx].role = 'length'
      }
      if (rightIdx !== undefined && output[rightIdx]) {
        output[rightIdx].role = 'width'
      }
    }
   } else if (sortedDesc.length === 1) {
    const first = sortedDesc[0]
    const target = first ? output[first.idx] : undefined
    if (target) {
      target.role = 'length'
    }
   }
 
   const thicknessCandidate = output.find(
     (block) => block.role === 'ignore' && block.value > 0 && block.value <= 100,
   )
   if (thicknessCandidate) {
     thicknessCandidate.role = 'thickness'
   }
 
   return output
 }
 
 function extractNumericBlocks(text: string): NumericBlock[] {
   const matches = Array.from(text.matchAll(/\d+(?:[.,]\d+)?/g))
 
   return matches.map((match, index) => ({
     id: `${index}:${match[0]}`,
     index,
     raw: match[0],
     value: Number(match[0].replace(',', '.')),
     role: 'ignore',
   }))
 }
 
 function analyzeExampleIfNeeded() {
   if (numericBlocks.value.length === 0 && form.value.example_input.trim()) {
     analyzeExample()
   }
 }
 
 function analyzeExample() {
   const text = form.value.example_input.trim()
   if (!text) {
     numericBlocks.value = []
     return
   }
 
   const blocks = extractNumericBlocks(text)
   numericBlocks.value = applySuggestedRoles(blocks)
   syncTechnicalFromSimple()
 
   if (previewTestText.value.trim() === '') {
     previewTestText.value = text
   }
 }
 
function universalSeparatorPart(segment: string): string {
  if (/[*xх×/]/i.test(segment)) {
    return '\\s*(?:x|х|\\*|/|×)\\s*'
  }

  if (/(?:мм|mm)/i.test(segment)) {
    return '\\s*(?:мм|mm)\\s*'
  }

  if (/[\-–]/.test(segment)) {
    return '\\s*(?:-|–)?\\s*'
  }

  if (/\s+/.test(segment)) {
    return '\\s+'
  }

  if (segment.trim() === '') {
    return '\\s*'
  }

  // Keep it universal in simple mode: allow short non-digit connectors between numeric parts.
  return '\\s*\\D{0,12}?\\s*'
}

function generateUniversalPatternFromExample(example: string): {
   pattern: string
   captures: { length_mm?: number; width_mm?: number; thickness_mm?: number }
 } {
   const matches = Array.from(example.matchAll(/\d+(?:[.,]\d+)?/g))
   if (matches.length === 0) {
     return { pattern: '', captures: {} }
   }
 
   const captures: { length_mm?: number; width_mm?: number; thickness_mm?: number } = {}
  const seenCaptureRoles = new Set<NumericRole>()
  const firstRoleIndex = new Map<NumericRole, number>()

  for (const block of numericBlocks.value) {
    if (block.role === 'ignore') {
      continue
    }
    if (!firstRoleIndex.has(block.role)) {
      firstRoleIndex.set(block.role, block.index)
    }
  }

  if (firstRoleIndex.size === 0) {
    return { pattern: '', captures: {} }
  }

  const usedIndexes = Array.from(firstRoleIndex.values()).sort((a, b) => a - b)
  const startIndex = usedIndexes[0]
  const endIndex = usedIndexes[usedIndexes.length - 1]

  if (startIndex === undefined || endIndex === undefined) {
    return { pattern: '', captures: {} }
  }

  if (!matches[startIndex] || !matches[endIndex]) {
    return { pattern: '', captures: {} }
  }
 
   let groupCounter = 1
   let pattern = ''
 
  for (let i = startIndex; i <= endIndex; i++) {
     const match = matches[i]
    if (!match) {
      continue
    }
    const start = match.index ?? 0
     const role = numericBlocks.value[i]?.role || 'ignore'
 
    if (i > startIndex) {
      const prev = matches[i - 1]
      if (!prev) {
        continue
      }
      const prevStart = prev.index ?? 0
      const prevEnd = prevStart + prev[0].length
      pattern += universalSeparatorPart(example.slice(prevEnd, start))
    }
 
    if (role !== 'ignore' && !seenCaptureRoles.has(role)) {
       pattern += '(\\d{1,5}(?:[.,]\\d+)?)'
       if (role === 'length') captures.length_mm = groupCounter
       if (role === 'width') captures.width_mm = groupCounter
       if (role === 'thickness') captures.thickness_mm = groupCounter
      seenCaptureRoles.add(role)
       groupCounter += 1
     } else {
       pattern += '\\d{1,5}(?:[.,]\\d+)?'
     }
   }
 
  const endMatch = matches[endIndex]
  if (!endMatch) {
    return { pattern, captures }
  }
  const endStart = endMatch.index ?? 0
  const endCursor = endStart + endMatch[0].length
  const rightContext = example.slice(endCursor, endCursor + 12)
  if (/(?:мм|mm)/i.test(rightContext)) {
    pattern += '\\s*(?:мм|mm)?'
  }
 
   return { pattern, captures }
 }
 
 function syncTechnicalFromSimple() {
   const roleMap: Record<NumericRole, number | null> = {
     length: null,
     width: null,
     thickness: null,
     ignore: null,
   }
 
   for (const block of numericBlocks.value) {
     if (block.role !== 'ignore' && roleMap[block.role] === null) {
       roleMap[block.role] = block.value
     }
   }
 
   form.value.expected_length_mm = roleMap.length
   form.value.expected_width_mm = roleMap.width
   form.value.expected_thickness_mm = roleMap.thickness
 
  const generated = generateUniversalPatternFromExample(form.value.example_input)
   if (generated.pattern) {
     form.value.pattern = generated.pattern
   }
   form.value.capture_length_mm = generated.captures.length_mm || null
   form.value.capture_width_mm = generated.captures.width_mm || null
   form.value.capture_thickness_mm = generated.captures.thickness_mm || null
 
   // Simple mode prefers extraction from captures by default; fixed values remain optional in advanced mode.
   form.value.fixed_length_mm = null
   form.value.fixed_width_mm = null
   form.value.fixed_thickness_mm = null
 }
 
 function validateCaptureIndex(value: number | null, fieldLabel: string): string | null {
   const normalized = toNullableNumber(value)
   if (normalized === null) {
     return null
   }
 
   if (!Number.isInteger(normalized) || normalized < 1 || normalized > 32) {
     return `${fieldLabel}: укажите целый номер группы от 1 до 32.`
   }
 
   return null
 }
 
 function hasMapping(capture: number | null, fixed: number | null): boolean {
   return toNullableNumber(capture) !== null || toNullableNumber(fixed) !== null
 }
 
 function buildPayload(): UpsertMaterialDimensionRulePayload | null {
   const trimmedName = form.value.name.trim()
   const trimmedPattern = form.value.pattern.trim()
   const example = form.value.example_input.trim()
   const priority = Number(form.value.priority)
   const confidence = Number(form.value.confidence)
 
   if (!trimmedName) {
     formError.value = 'Укажите название правила.'
     return null
   }
   if (!example) {
     formError.value = 'Укажите пример названия материала.'
     return null
   }
 
   if (!trimmedPattern) {
     formError.value = 'Шаблон не сформирован. Разберите строку и назначьте блоки.'
     return null
   }
 
   if (!Number.isInteger(priority) || priority < 1) {
     formError.value = 'Priority должен быть целым числом больше 0.'
     return null
   }
 
   if (!Number.isFinite(confidence) || confidence < 0 || confidence > 1) {
     formError.value = 'Confidence должен быть в диапазоне от 0 до 1.'
     return null
   }
 
   const captureErrors = [
     validateCaptureIndex(form.value.capture_length_mm, 'Capture для длины'),
     validateCaptureIndex(form.value.capture_width_mm, 'Capture для ширины'),
     validateCaptureIndex(form.value.capture_thickness_mm, 'Capture для толщины'),
   ].filter(Boolean)
 
   if (captureErrors.length > 0) {
     formError.value = captureErrors[0] as string
     return null
   }
 
   const captures: Record<string, number> = {}
   if (toNullableNumber(form.value.capture_length_mm) !== null) captures.length_mm = Number(form.value.capture_length_mm)
   if (toNullableNumber(form.value.capture_width_mm) !== null) captures.width_mm = Number(form.value.capture_width_mm)
   if (toNullableNumber(form.value.capture_thickness_mm) !== null) captures.thickness_mm = Number(form.value.capture_thickness_mm)
 
   const fixed: Record<string, number> = {}
   if (toNullableNumber(form.value.fixed_length_mm) !== null) fixed.length_mm = Number(form.value.fixed_length_mm)
   if (toNullableNumber(form.value.fixed_width_mm) !== null) fixed.width_mm = Number(form.value.fixed_width_mm)
   if (toNullableNumber(form.value.fixed_thickness_mm) !== null) fixed.thickness_mm = Number(form.value.fixed_thickness_mm)
 
   const hasLength = hasMapping(form.value.capture_length_mm, form.value.fixed_length_mm)
   const hasWidth = hasMapping(form.value.capture_width_mm, form.value.fixed_width_mm)
   const hasThickness = hasMapping(form.value.capture_thickness_mm, form.value.fixed_thickness_mm)
 
   if (!hasLength && !hasWidth && !hasThickness) {
     formError.value = 'Назначьте хотя бы одно значение: length/width/thickness.'
     return null
   }
 
   if (form.value.material_type && ['plate', 'edge'].includes(form.value.material_type) && (!hasLength || !hasWidth)) {
     formError.value = 'Для plate/edge обязательны длина и ширина.'
     return null
   }
 
   const config: {
     pattern: string
     flags: string
     use_normalized_text: boolean
     captures?: Record<string, number>
     fixed?: Record<string, number>
   } = {
     pattern: trimmedPattern,
     flags: form.value.flags.trim() || 'u',
     use_normalized_text: form.value.use_normalized_text,
   }
 
   if (Object.keys(captures).length > 0) {
     config.captures = captures
   }
   if (Object.keys(fixed).length > 0) {
     config.fixed = fixed
   }
 
   const expectedResult: Record<string, number> = {}
   if (toNullableNumber(form.value.expected_length_mm) !== null) expectedResult.length_mm = Number(form.value.expected_length_mm)
   if (toNullableNumber(form.value.expected_width_mm) !== null) expectedResult.width_mm = Number(form.value.expected_width_mm)
   if (toNullableNumber(form.value.expected_thickness_mm) !== null) expectedResult.thickness_mm = Number(form.value.expected_thickness_mm)
 
   return {
     name: trimmedName,
     description: form.value.description.trim() || null,
     is_active: form.value.is_active,
     priority,
     material_type: form.value.material_type,
     source: form.value.source.trim() || null,
     rule_type: 'regex',
     config,
     example_input: example,
     expected_result: Object.keys(expectedResult).length > 0 ? expectedResult : null,
     confidence,
   }
 }
 
 function applyRuleToForm(rule: MaterialDimensionRule) {
   form.value = {
     name: rule.name,
     description: rule.description || '',
     is_active: rule.is_active,
     priority: rule.priority,
     material_type: rule.material_type,
     source: rule.source || '',
     pattern: rule.config.pattern || '',
     flags: rule.config.flags || 'u',
     use_normalized_text: rule.config.use_normalized_text !== false,
     capture_length_mm: rule.config.captures?.length_mm || null,
     capture_width_mm: rule.config.captures?.width_mm || null,
     capture_thickness_mm: rule.config.captures?.thickness_mm || null,
     fixed_length_mm: rule.config.fixed?.length_mm || null,
     fixed_width_mm: rule.config.fixed?.width_mm || null,
     fixed_thickness_mm: rule.config.fixed?.thickness_mm || null,
     example_input: rule.example_input || '',
     expected_length_mm: rule.expected_result?.length_mm || null,
     expected_width_mm: rule.expected_result?.width_mm || null,
     expected_thickness_mm: rule.expected_result?.thickness_mm || null,
     confidence: rule.confidence,
   }
}

function duplicateReasonLabel(reason: DuplicateReason): string {
  if (reason === 'exact_scope_pattern') return 'Совпадение scope+pattern'
  if (reason === 'exact_pattern') return 'Полное совпадение pattern'
  return 'Похожее покрытие'
}

function sanitizeJsRegexFlags(flags: string | undefined): string {
  const cleaned = (flags || 'u').replace(/[^dgimsuvy]/g, '').replace(/g/g, '')
  const unique = Array.from(new Set(cleaned.split(''))).join('')
  return unique || 'u'
}

function patternMatchesSamples(pattern: string, flags: string | undefined, samples: string[]): boolean {
  try {
    const regex = new RegExp(pattern, sanitizeJsRegexFlags(flags))
    return samples.every((sample) => regex.test(sample))
  } catch {
    return false
  }
}

function scopeCompatible(payload: UpsertMaterialDimensionRulePayload, rule: MaterialDimensionRule): boolean {
  const payloadType = payload.material_type || null
  const payloadSource = normalizeString(payload.source || null)
  const ruleType = rule.material_type || null
  const ruleSource = normalizeString(rule.source)

  const typeCompatible = payloadType === null || ruleType === null || payloadType === ruleType
  const sourceCompatible = payloadSource === '' || ruleSource === '' || payloadSource === ruleSource

  return typeCompatible && sourceCompatible
}

function hasThicknessMapping(payload: UpsertMaterialDimensionRulePayload): boolean {
  return Boolean(payload.config.captures?.thickness_mm || payload.config.fixed?.thickness_mm)
}

function buildSimilaritySamples(payload: UpsertMaterialDimensionRulePayload): string[] {
  const length = Number(payload.expected_result?.length_mm || form.value.expected_length_mm || 2750)
  const width = Number(payload.expected_result?.width_mm || form.value.expected_width_mm || 1830)
  const thickness = Number(payload.expected_result?.thickness_mm || form.value.expected_thickness_mm || 16)

  const withThickness = hasThicknessMapping(payload)
  const basePairs = [
    `${length}*${width}`,
    `${length}x${width}`,
    `${length}х${width}`,
  ]

  if (!withThickness) {
    return basePairs.map((pair) => `Материал ${pair} ламинированный`)
  }

  return [
    `Материал ${length}*${width} ${thickness} мм белый`,
    `Плита ${length}x${width} ${thickness} мм`,
    `ЛДСП ${length}х${width} ${thickness} мм сорт 1`,
  ]
}

async function fetchRulesForDuplicateCheck(): Promise<MaterialDimensionRule[]> {
  const allRules: MaterialDimensionRule[] = []
  let currentPage = 1
  let lastPage = 1

  do {
    const response = await adminMaterialDimensionsApi.listRules({ page: currentPage, per_page: 100 })
    allRules.push(...response.data)
    lastPage = response.meta.last_page || 1
    currentPage += 1
  } while (currentPage <= lastPage)

  return allRules
}

async function findDuplicateCandidates(payload: UpsertMaterialDimensionRulePayload): Promise<DuplicateCandidate[]> {
  const rules = await fetchRulesForDuplicateCheck()
  const normalizedPattern = payload.config.pattern.trim()
  const normalizedMaterialType = payload.material_type || null
  const normalizedSource = normalizeString(payload.source || null)
  const samples = buildSimilaritySamples(payload)
  const newRuleMatchesSamples = patternMatchesSamples(payload.config.pattern, payload.config.flags, samples)
  const candidates: DuplicateCandidate[] = []

  for (const rule of rules) {
    if (editingId.value && rule.id === editingId.value) {
      continue
    }

    const rulePattern = (rule.config.pattern || '').trim()
    const samePattern = rulePattern !== '' && rulePattern === normalizedPattern
    const sameScope =
      (rule.material_type || null) === normalizedMaterialType &&
      normalizeString(rule.source) === normalizedSource

    if (samePattern && sameScope) {
      candidates.push({
        rule,
        reason: 'exact_scope_pattern',
        details: 'Совпадает pattern и scope (material_type + source).',
      })
      continue
    }

    if (samePattern) {
      candidates.push({
        rule,
        reason: 'exact_pattern',
        details: 'Совпадает regex pattern.',
      })
      continue
    }

    if (!scopeCompatible(payload, rule) || !newRuleMatchesSamples) {
      continue
    }

    const existingMatchesSamples = patternMatchesSamples(rule.config.pattern || '', rule.config.flags, samples)
    if (existingMatchesSamples) {
      candidates.push({
        rule,
        reason: 'similar_coverage',
        details: 'Шаблон покрывает те же тестовые размерные кейсы.',
      })
    }
  }

  return candidates.slice(0, 8)
}

function closeDuplicateDialog() {
  duplicateDialog.value = false
  pendingPayload.value = null
  duplicateCandidates.value = []
}

function openExistingFromDuplicate(rule: MaterialDimensionRule) {
  closeDuplicateDialog()
  openEdit(rule)
}

async function executeSave(payload: UpsertMaterialDimensionRulePayload) {
  if (editingId.value) {
    await adminMaterialDimensionsApi.updateRule(editingId.value, payload)
  } else {
    await adminMaterialDimensionsApi.createRule(payload)
    page.value = 1
  }

  duplicateDialog.value = false
  pendingPayload.value = null
  duplicateCandidates.value = []
  dialog.value = false
  await loadList()
}
 
 function applyPreset(preset: MaterialDimensionRulePreset) {
   isPrefilledFromFailure.value = preset.from_failed_case === true
   prefilledFields.value = []
 
   if (preset.name) {
     form.value.name = preset.name
     markPrefilledField('name')
   }
   if (preset.description) {
     form.value.description = preset.description
     markPrefilledField('description')
   }
   if (preset.material_type !== undefined) {
     form.value.material_type = preset.material_type
     markPrefilledField('material_type')
   }
   if (preset.source) {
     form.value.source = preset.source
     markPrefilledField('source')
   }
   if (preset.example_input) {
     form.value.example_input = preset.example_input
     markPrefilledField('example_input')
   }
   if (preset.pattern) {
     form.value.pattern = preset.pattern
     markPrefilledField('pattern')
   }
 
   if (preset.captures?.length_mm !== undefined) {
     form.value.capture_length_mm = preset.captures.length_mm
     markPrefilledField('capture_length_mm')
   }
   if (preset.captures?.width_mm !== undefined) {
     form.value.capture_width_mm = preset.captures.width_mm
     markPrefilledField('capture_width_mm')
   }
   if (preset.captures?.thickness_mm !== undefined) {
     form.value.capture_thickness_mm = preset.captures.thickness_mm
     markPrefilledField('capture_thickness_mm')
   }
 
   if (preset.fixed?.length_mm !== undefined) {
     form.value.fixed_length_mm = preset.fixed.length_mm
     markPrefilledField('fixed_length_mm')
   }
   if (preset.fixed?.width_mm !== undefined) {
     form.value.fixed_width_mm = preset.fixed.width_mm
     markPrefilledField('fixed_width_mm')
   }
   if (preset.fixed?.thickness_mm !== undefined) {
     form.value.fixed_thickness_mm = preset.fixed.thickness_mm
     markPrefilledField('fixed_thickness_mm')
   }
 
   if (preset.expected_length_mm !== undefined) {
     form.value.expected_length_mm = preset.expected_length_mm
     markPrefilledField('expected_length_mm')
   }
   if (preset.expected_width_mm !== undefined) {
     form.value.expected_width_mm = preset.expected_width_mm
     markPrefilledField('expected_width_mm')
   }
   if (preset.expected_thickness_mm !== undefined) {
     form.value.expected_thickness_mm = preset.expected_thickness_mm
     markPrefilledField('expected_thickness_mm')
   }
 
   if (Array.isArray(preset.prefilled_fields)) {
     for (const fieldName of preset.prefilled_fields) {
       markPrefilledField(fieldName)
     }
   }
 }
 
 function openEdit(rule: MaterialDimensionRule) {
   editingId.value = rule.id
   formError.value = ''
   previewError.value = ''
   previewResult.value = null
   advancedOpenPanels.value = []
   isPrefilledFromFailure.value = false
   prefilledFields.value = []
  duplicateDialog.value = false
  duplicateCandidates.value = []
  pendingPayload.value = null
 
   applyRuleToForm(rule)
   previewTestText.value = rule.example_input || ''
   analyzeExampleIfNeeded()
   dialog.value = true
 }
 
 function openCreateWithPreset(preset?: MaterialDimensionRulePreset) {
   editingId.value = null
   formError.value = ''
   previewError.value = ''
   previewResult.value = null
   advancedOpenPanels.value = []
   form.value = newForm()
   previewTestText.value = ''
   numericBlocks.value = []
   isPrefilledFromFailure.value = false
   prefilledFields.value = []
  duplicateDialog.value = false
  duplicateCandidates.value = []
  pendingPayload.value = null
 
   if (preset) {
     applyPreset(preset)
     previewTestText.value = preset.example_input || ''
     if (preset.example_input) {
       markPrefilledField('preview_test_text')
       analyzeExample()
     }
   }
 
   dialog.value = true
 }
 
 async function previewRule() {
   formError.value = ''
   previewError.value = ''
 
   if (form.value.pattern.trim() === '' && form.value.example_input.trim() !== '') {
     analyzeExample()
   }
 
   const payload = buildPayload()
   if (!payload) {
     return
   }
 
   const testText = previewTestText.value.trim() || form.value.example_input.trim()
   if (!testText) {
     previewError.value = 'Добавьте тестовый кейс для проверки правила.'
     return
   }
 
   previewLoading.value = true
   try {
     const response = await adminMaterialDimensionsApi.previewRule(payload, testText)
     previewResult.value = response.parse_result
   } catch (error: any) {
     previewError.value = error?.response?.data?.message || 'Не удалось выполнить проверку. Исправьте поля и попробуйте снова.'
     console.error('Failed to preview managed rule', error)
   } finally {
     previewLoading.value = false
   }
 }
 
 async function saveRule() {
   formError.value = ''
 
   if (form.value.pattern.trim() === '' && form.value.example_input.trim() !== '') {
     analyzeExample()
   }
 
   const payload = buildPayload()
   if (!payload) {
     return
   }
 
  saving.value = true
   try {
    const duplicates = await findDuplicateCandidates(payload)
    if (duplicates.length > 0) {
      duplicateCandidates.value = duplicates
      pendingPayload.value = payload
      duplicateDialog.value = true
      return
     }
 
    await executeSave(payload)
   } catch (error: any) {
     formError.value = error?.response?.data?.message || 'Не удалось сохранить правило. Проверьте заполнение полей.'
     console.error('Failed to save material dimension rule', error)
   } finally {
     saving.value = false
   }
 }

async function saveDespiteDuplicates() {
  if (!pendingPayload.value) {
    duplicateDialog.value = false
    return
  }

  saving.value = true
  formError.value = ''
  try {
    await executeSave(pendingPayload.value)
  } catch (error: any) {
    formError.value = error?.response?.data?.message || 'Не удалось сохранить правило. Проверьте заполнение полей.'
    console.error('Failed to force save material dimension rule', error)
  } finally {
    saving.value = false
  }
}
 
 async function toggleActive(rule: MaterialDimensionRule) {
   togglingId.value = rule.id
   try {
     await adminMaterialDimensionsApi.updateRule(rule.id, {
       name: rule.name,
       description: rule.description,
       is_active: !rule.is_active,
       priority: rule.priority,
       material_type: rule.material_type,
       source: rule.source,
       rule_type: 'regex',
       config: rule.config,
       example_input: rule.example_input,
       expected_result: rule.expected_result,
       confidence: rule.confidence,
     })
     await loadList()
   } catch (error) {
     console.error('Failed to toggle rule state', error)
   } finally {
     togglingId.value = null
   }
 }
 
 async function removeRule(rule: MaterialDimensionRule) {
   if (!confirm(`Удалить правило "${rule.name}"?`)) {
     return
   }
 
   deletingId.value = rule.id
   try {
     await adminMaterialDimensionsApi.deleteRule(rule.id)
     await loadList()
   } catch (error) {
     console.error('Failed to delete rule', error)
   } finally {
     deletingId.value = null
   }
 }
 
 defineExpose<{ openCreateWithPreset: (preset?: MaterialDimensionRulePreset) => void }>({
   openCreateWithPreset,
 })
 
 onMounted(() => {
   loadList()
 })
 </script>
 
 <style scoped>
 .mapping-col {
   white-space: normal;
   line-height: 1.3;
 }
 
 .guide-list {
   margin: 0;
   padding-left: 20px;
 }
 
 .section-block {
   border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
   border-radius: 8px;
   padding: 12px;
   margin-bottom: 12px;
 }
 
 .section-title {
   font-size: 14px;
   font-weight: 600;
   margin-bottom: 4px;
 }
 
 .section-help {
   font-size: 12px;
   color: rgba(var(--v-theme-on-surface), 0.7);
   margin-bottom: 10px;
 }
 
 .preview-block {
   white-space: pre-wrap;
   word-break: break-word;
 }

.duplicate-list {
  max-height: 380px;
  overflow: auto;
}
 </style>
