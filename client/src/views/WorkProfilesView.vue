<template>
  <div class="work-profiles-view">
    <!-- Header -->
    <div class="page-header">
      <div>
        <h1 class="page-title">Профили работ</h1>
        <div class="page-subtitle">Источники нормо-часов и профили должностей</div>
      </div>
    </div>

    <!-- Вкладки -->
    <v-tabs v-model="activeTab" class="page-tabs">
      <v-tab value="sources">
        <v-icon class="mr-2">mdi-chart-box-outline</v-icon>
        Источники нормо-часов
      </v-tab>
      <v-tab value="profiles">
        <v-icon class="mr-2">mdi-briefcase-outline</v-icon>
        Профили должностей
      </v-tab>
    </v-tabs>

    <!-- Вкладка 1: Источники нормо-часов -->
    <v-window v-model="activeTab">
      <v-window-item value="sources">
        <!-- Заголовок и кнопка добавления -->
        <div class="section-header">
          <div>
            <h2 class="section-title">Источники нормо-часов</h2>
            <div class="section-subtitle">Управление источниками ставок и норм</div>
          </div>
          <v-btn
            class="primary-btn"
            color="primary"
            prepend-icon="mdi-plus"
            @click="openDialog"
          >
            Добавить источник
          </v-btn>
        </div>

        <!-- Фильтры и поиск -->
        <v-card class="content-card filters-card" elevation="0" variant="outlined">
          <v-card-text class="filters-row">
            <v-text-field
              v-model="searchQuery"
              placeholder="Поиск по источнику..."
              prepend-inner-icon="mdi-magnify"
              variant="outlined"
              density="compact"
              hide-details
              style="max-width: 250px;"
              @input="applyFilters"
            />
            <v-select
              v-model="selectedRegion"
              :items="regions"
              item-title="region_name"
              item-value="id"
              placeholder="Фильтр по регионам"
              variant="outlined"
              density="compact"
              hide-details
              clearable
              style="max-width: 250px;"
              @update:modelValue="applyFilters"
            />
            <v-checkbox
              v-model="showOnlyActive"
              label="Только активные"
              density="compact"
              hide-details
              @change="applyFilters"
            />
            <v-spacer />
            <v-btn
              icon
              size="small"
              variant="text"
              @click="loadWorkProfiles"
              title="Обновить"
            >
              <v-icon>mdi-refresh</v-icon>
            </v-btn>
          </v-card-text>
        </v-card>

    <!-- Статистика -->
    <v-row class="stats-grid">
      <v-col cols="12" sm="6" md="3">
        <v-card class="content-card stat-card" elevation="0" variant="outlined">
          <v-card-text>
            <div class="text-caption text-grey">Всего источников</div>
            <div class="text-h6">{{ workProfiles.length }}</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card class="content-card stat-card" elevation="0" variant="outlined">
          <v-card-text>
            <div class="text-caption text-grey">Активных</div>
            <div class="text-h6">{{ workProfiles.filter(p => p.is_active).length }}</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card class="content-card stat-card" elevation="0" variant="outlined">
          <v-card-text>
            <div class="text-caption text-grey">Регионов</div>
            <div class="text-h6">{{ uniqueRegions.size }}</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" sm="6" md="3">
        <v-card class="content-card stat-card" elevation="0" variant="outlined">
          <v-card-text>
            <div class="text-caption text-grey">Профилей должностей</div>
            <div class="text-h6">{{ uniqueProfiles.size }}</div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <!-- Таблица источников -->
    <v-card class="content-card data-card" elevation="0" variant="outlined">
      <v-card-title class="section-card-title">Источники нормо-часов</v-card-title>
      
      <v-data-table
        :items="filteredProfiles"
        :headers="headers"
        item-value="id"
        density="comfortable"
        class="data-table"
        :items-per-page="25"
        :loading="loadingSources"
      >
        <!-- Регион -->
        <template #[`item.region_name`]="{ item }">
          <span v-if="item.region_name">{{ item.region_name }}</span>
          <span v-else class="text-grey">—</span>
        </template>

        <!-- Источник -->
        <template #[`item.source`]="{ item }">
          <div class="font-weight-medium">{{ item.source }}</div>
          <div v-if="item.source_date_formatted" class="text-caption text-grey">
            {{ item.source_date_formatted }}
          </div>
        </template>

        <!-- Профиль должности -->
        <template #[`item.position_profile_name`]="{ item }">
          <span v-if="item.position_profile_name">{{ item.position_profile_name }}</span>
          <span v-else class="text-grey">—</span>
        </template>

        <!-- Зарплата -->
        <template #[`item.salary_display`]="{ item }">
          <div class="font-weight-medium">{{ item.salary_display }}</div>
        </template>

        <!-- Ставка в час -->
        <template #[`item.rate_display`]="{ item }">
          <div class="font-weight-medium">{{ item.rate_display }}</div>
        </template>

        <!-- Часы в месяц -->
        <template #[`item.hours_per_month`]="{ item }">
          {{ item.hours_per_month }}
        </template>

        <!-- Ссылка -->
        <template #[`item.link`]="{ item }">
          <v-btn
            v-if="item.link"
            icon
            size="small"
            variant="text"
            :href="item.link"
            target="_blank"
            title="Открыть ссылку"
          >
            <v-icon size="small">mdi-open-in-new</v-icon>
          </v-btn>
          <span v-else class="text-grey">—</span>
        </template>

        <!-- Примечание -->
        <template #[`item.note`]="{ item }">
          <span v-if="item.note" :title="item.note" class="text-truncate">{{ item.note }}</span>
          <span v-else class="text-grey">—</span>
        </template>

        <!-- Активность -->
        <template #[`item.is_active`]="{ item }">
          <v-chip
            :color="item.is_active ? 'success' : 'grey'"
            size="small"
            @click="toggleActive(item)"
            style="cursor: pointer;"
          >
            {{ item.is_active ? 'Активен' : 'Неактивен' }}
          </v-chip>
        </template>

        <!-- Действия -->
        <template #[`item.actions`]="{ item }">
          <div class="d-flex gap-2">
            <v-btn
              icon
              size="small"
              variant="text"
              color="primary"
              @click="editWorkProfile(item)"
              title="Редактировать"
            >
              <v-icon size="small">mdi-pencil</v-icon>
            </v-btn>
            <v-btn
              icon
              size="small"
              variant="text"
              color="error"
              @click="deleteWorkProfile(item)"
              title="Удалить"
            >
              <v-icon size="small">mdi-delete</v-icon>
            </v-btn>
          </div>
        </template>

        <!-- Пусто -->
        <template #loading>
          <div class="text-center py-8">
            <v-progress-circular indeterminate color="primary" size="24" class="mr-2" />
            <span class="text-grey">Загрузка источников...</span>
          </div>
        </template>

        <template #no-data>
          <div v-if="loadingSources" class="py-8" />
          <div v-else class="text-center py-8">
            <div class="text-grey mb-3">Источники не найдены</div>
            <v-btn color="primary" variant="text" @click="openDialog">
              Добавить первый источник
            </v-btn>
          </div>
        </template>
      </v-data-table>
    </v-card>

    <!-- Диалог добавления/редактирования -->
    <v-dialog v-model="dialog" max-width="700" scrollable>
      <v-card class="content-card dialog-card">
        <v-card-title>
          {{ editingId ? 'Редактировать источник нормо-часов' : 'Новый источник нормо-часов' }}
        </v-card-title>

        <v-card-text class="mt-4">
          <v-form ref="formRef" @submit.prevent="saveProfile">
            <!-- Регион (обязательное) -->
            <v-select
              v-model="form.region_id"
              :items="regions"
              item-title="region_name"
              item-value="id"
              label="Регион*"
              variant="outlined"
              density="compact"
              required
              class="mb-4"
              :rules="[v => !!v || 'Регион обязателен']"
            />

            <!-- Профиль должности -->
            <v-select
              v-model="form.position_profile_id"
              :items="positionProfiles"
              item-title="name"
              item-value="id"
              label="Профиль должности"
              variant="outlined"
              density="compact"
              clearable
              class="mb-4"
            />

            <!-- Источник (обязательное) -->
            <v-text-field
              v-model="form.source"
              label="Источник*"
              placeholder="Например: hh.ru"
              variant="outlined"
              density="compact"
              required
              class="mb-4"
              :rules="[v => !!v || 'Источник обязателен']"
            />

            <!-- Тип ставки (radio) -->
            <div class="mb-4">
              <label class="text-subtitle-2 d-block mb-2">Тип ставки*</label>
              <v-radio-group v-model="form.type" inline required>
                <v-radio
                  value="single"
                  label="Одно значение"
                  @change="onTypeChange"
                />
                <v-radio
                  value="range"
                  label="Диапазон (от - до)"
                  @change="onTypeChange"
                />
              </v-radio-group>
            </div>

            <!-- Зарплата (single) -->
            <v-text-field
              v-if="form.type === 'single'"
              v-model.number="form.salary_value"
              label="Зарплата (₽)*"
              placeholder="Например: 100000"
              variant="outlined"
              density="compact"
              type="number"
              required
              class="mb-4"
              :rules="[
                v => !!v || 'Зарплата обязательна',
                v => v > 0 || 'Зарплата должна быть больше 0'
              ]"
              @input="calculateRates"
            />

            <!-- Зарплата (range) -->
            <v-row v-if="form.type === 'range'" class="mb-4">
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model.number="form.salary_value_min"
                  label="От (₽)*"
                  placeholder="Например: 100000"
                  variant="outlined"
                  density="compact"
                  type="number"
                  required
                  :rules="[
                    v => !!v || 'Минимум обязателен',
                    v => v > 0 || 'Должно быть больше 0'
                  ]"
                  @input="calculateRates"
                />
              </v-col>
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model.number="form.salary_value_max"
                  label="До (₽)*"
                  placeholder="Например: 150000"
                  variant="outlined"
                  density="compact"
                  type="number"
                  required
                  :rules="[
                    v => !!v || 'Максимум обязателен',
                    v => v > 0 || 'Должно быть больше 0'
                  ]"
                  @input="calculateRates"
                />
              </v-col>
            </v-row>

            <!-- Часы в месяц -->
            <v-text-field
              v-model.number="form.hours_per_month"
              label="Часы в месяц"
              placeholder="160"
              variant="outlined"
              density="compact"
              type="number"
              class="mb-4"
              :rules="[v => !v || v > 0 || 'Должно быть больше 0']"
              @input="calculateRates"
            />

            <!-- Расчётная ставка в час -->
            <v-alert v-if="calculatedRate" type="info" class="mb-4">
              <template v-if="form.type === 'single'">
                Ставка в час: <strong>{{ calculatedRate }} ₽/ч</strong>
              </template>
              <template v-else>
                Ставка в час: <strong>{{ calculatedRateMin }}–{{ calculatedRateMax }} ₽/ч</strong>
              </template>
            </v-alert>

            <!-- Дата источника -->
            <v-text-field
              v-model="form.source_date"
              label="Дата источника"
              type="date"
              variant="outlined"
              density="compact"
              class="mb-4"
            />

            <!-- Ссылка -->
            <v-text-field
              v-model="form.link"
              label="Ссылка на источник"
              placeholder="https://..."
              variant="outlined"
              density="compact"
              type="url"
              class="mb-4"
            />

            <!-- Примечание -->
            <v-textarea
              v-model="form.note"
              label="Примечание"
              placeholder="Дополнительная информация"
              variant="outlined"
              density="compact"
              rows="2"
              class="mb-4"
            />

            <!-- Активен -->
            <v-checkbox
              v-model="form.is_active"
              label="Активен"
              class="mb-4"
            />
          </v-form>
        </v-card-text>

        <v-card-actions>
          <v-spacer />
          <v-btn @click="dialog = false">Отмена</v-btn>
          <v-btn
            color="primary"
            :loading="saving"
            @click="saveProfile"
          >
            {{ editingId ? 'Сохранить' : 'Добавить' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Диалог подтверждения удаления -->
    <v-dialog v-model="deleteDialog" max-width="400">
      <v-card class="content-card dialog-card">
        <v-card-title>Удалить источник?</v-card-title>
        <v-card-text class="mt-4">
          <p>Вы уверены, что хотите удалить источник <strong>{{ deletingProfile?.source }}</strong>?</p>
          <p class="text-caption text-grey mt-3">Это действие нельзя отменить.</p>
        </v-card-text>

        <v-card-actions>
          <v-spacer />
          <v-btn @click="deleteDialog = false">Отмена</v-btn>
          <v-btn
            color="error"
            :loading="deleting"
            @click="confirmDelete"
          >
            Удалить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
      </v-window-item>

      <!-- Вкладка 2: Профили должностей -->
      <v-window-item value="profiles">
        <div class="section-header">
          <div>
            <h2 class="section-title">Профили должностей</h2>
            <div class="section-subtitle">Справочник должностей для расчётов</div>
          </div>
          <v-btn
            class="primary-btn"
            color="primary"
            prepend-icon="mdi-plus"
            @click="openProfileDialog"
          >
            Добавить профиль
          </v-btn>
        </div>

        <!-- Таблица профилей должностей -->
        <v-card class="content-card data-card" elevation="0" variant="outlined">
          <v-data-table
            :items="positionProfiles"
            :headers="profileHeaders"
            item-value="id"
            density="comfortable"
            :items-per-page="25"
            class="data-table"
            :loading="loadingPositionProfiles"
          >
            <!-- Название -->
            <template #[`item.name`]="{ item }">
              <div class="font-weight-medium">{{ item.name }}</div>
            </template>

            <!-- Описание -->
            <template #[`item.description`]="{ item }">
              <span v-if="item.description" :title="item.description" class="text-truncate">
                {{ item.description }}
              </span>
              <span v-else class="text-grey">—</span>
            </template>

            <!-- Модель ставки -->
            <template #[`item.rate_model`]="{ item }">
              <v-chip
                :color="(item.rate_model || 'labor') === 'contractor' ? 'blue' : 'grey'"
                size="small"
                variant="tonal"
              >
                {{ (item.rate_model || 'labor') === 'contractor' ? 'Подрядная' : 'Трудовая' }}
              </v-chip>
            </template>

            <!-- Порядок сортировки -->
            <template #[`item.sort_order`]="{ item }">
              {{ item.sort_order }}
            </template>

            <!-- Действия -->
            <template #[`item.actions`]="{ item }">
              <div class="d-flex gap-2">
                <v-btn
                  icon
                  size="small"
                  variant="text"
                  color="primary"
                  @click="editProfile(item)"
                  title="Редактировать"
                >
                  <v-icon size="small">mdi-pencil</v-icon>
                </v-btn>
                <v-btn
                  icon
                  size="small"
                  variant="text"
                  color="error"
                  @click="deletePositionProfile(item)"
                  title="Удалить"
                >
                  <v-icon size="small">mdi-delete</v-icon>
                </v-btn>
              </div>
            </template>

            <!-- Пусто -->
            <template #loading>
              <div class="text-center py-8">
                <v-progress-circular indeterminate color="primary" size="24" class="mr-2" />
                <span class="text-grey">Загрузка профилей должностей...</span>
              </div>
            </template>

            <template #no-data>
              <div v-if="loadingPositionProfiles" class="py-8" />
              <div v-else class="text-center py-8">
                <div class="text-grey mb-3">Профили должностей не найдены</div>
                <v-btn color="primary" variant="text" @click="openProfileDialog">
                  Добавить первый профиль
                </v-btn>
              </div>
            </template>
          </v-data-table>
        </v-card>
      </v-window-item>
    </v-window>

    <!-- Диалог добавления/редактирования профиля должности -->
    <v-dialog v-model="profileDialog" max-width="700" scrollable>
      <v-card class="content-card dialog-card">
        <v-card-title>
          {{ editingProfileId ? 'Редактировать профиль должности' : 'Новый профиль должности' }}
        </v-card-title>

        <v-card-text class="mt-4">
          <v-form ref="profileFormRef" @submit.prevent="savePositionProfile">
            <!-- Название -->
            <v-text-field
              v-model="profileForm.name"
              label="Название профиля*"
              placeholder="Например: Сборщик мебели"
              variant="outlined"
              density="compact"
              required
              class="mb-4"
              :rules="[v => !!v || 'Название обязательно']"
            />

            <!-- Описание -->
            <v-textarea
              v-model="profileForm.description"
              label="Описание"
              placeholder="Дополнительная информация о профиле"
              variant="outlined"
              density="compact"
              rows="3"
              class="mb-4"
            />

            <!-- Порядок сортировки -->
            <v-text-field
              v-model.number="profileForm.sort_order"
              label="Порядок сортировки"
              type="number"
              variant="outlined"
              density="compact"
              class="mb-4"
              :rules="[v => v >= 0 || 'Значение должно быть >= 0']"
            />

            <!-- === Модель формирования ставки === -->
            <v-divider class="mb-4" />
            <div class="text-subtitle-2 mb-3">Модель формирования ставки</div>

            <!-- Переключатель модели -->
            <v-radio-group v-model="profileForm.rate_model" inline class="mb-4">
              <v-radio value="labor" label="Стоимость часа труда (labor)" />
              <v-radio value="contractor" label="Подрядная ставка (contractor)" />
            </v-radio-group>

            <!-- Параметры подрядной модели -->
            <v-row class="mb-2">
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model.number="profileForm.employer_contrib_pct"
                  label="Страховые начисления, %"
                  type="number"
                  variant="outlined"
                  density="compact"
                  :disabled="profileForm.rate_model === 'labor'"
                  :rules="[
                    v => v >= 0 || 'Минимум 0',
                    v => v <= 100 || 'Максимум 100'
                  ]"
                  :hint="profileForm.rate_model === 'labor' ? 'Используется только для подрядной модели' : ''"
                  persistent-hint
                />
              </v-col>
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model.number="profileForm.profit_pct"
                  label="Рентабельность, %"
                  type="number"
                  variant="outlined"
                  density="compact"
                  :disabled="profileForm.rate_model === 'labor'"
                  :rules="[
                    v => v >= 0 || 'Минимум 0',
                    v => v <= 100 || 'Максимум 100'
                  ]"
                  :hint="profileForm.rate_model === 'labor' ? 'Используется только для подрядной модели' : ''"
                  persistent-hint
                />
              </v-col>
            </v-row>

            <v-row class="mb-2">
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model.number="profileForm.base_hours_month"
                  label="Рабочих часов/мес"
                  type="number"
                  variant="outlined"
                  density="compact"
                  :disabled="profileForm.rate_model === 'labor'"
                  :rules="[
                    v => v >= 1 || 'Минимум 1',
                    v => v <= 300 || 'Максимум 300'
                  ]"
                  :hint="profileForm.rate_model === 'labor' ? 'Используется только для подрядной модели' : ''"
                  persistent-hint
                />
              </v-col>
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model.number="profileForm.billable_hours_month"
                  label="Оплачиваемых часов/мес"
                  type="number"
                  variant="outlined"
                  density="compact"
                  :disabled="profileForm.rate_model === 'labor'"
                  :rules="[
                    v => v >= 1 || 'Минимум 1',
                    v => v <= 300 || 'Максимум 300',
                    v => v <= (profileForm.base_hours_month || 160) || 'Не может превышать рабочие часы'
                  ]"
                  :hint="profileForm.rate_model === 'labor' ? 'Используется только для подрядной модели' : ''"
                  persistent-hint
                />
              </v-col>
            </v-row>

            <v-select
              v-model="profileForm.rounding_mode"
              :items="roundingOptions"
              label="Округление ставки"
              variant="outlined"
              density="compact"
              class="mb-4"
            />

            <!-- === Превью расчёта ставки === -->
            <template v-if="editingProfileId">
              <v-divider class="mb-4" />
              <div class="text-subtitle-2 mb-3">Превью расчёта ставки</div>

              <v-select
                v-model="previewRegionId"
                :items="regions"
                item-title="region_name"
                item-value="id"
                label="Регион для превью"
                variant="outlined"
                density="compact"
                clearable
                class="mb-3"
                @update:modelValue="loadPreviewBaseRate"
              />

              <v-progress-linear v-if="previewLoading" indeterminate color="primary" class="mb-3" />

              <template v-if="previewBaseRate !== null && previewBaseRate > 0">
                <!-- Labor preview -->
                <v-alert v-if="laborPreview" type="info" variant="tonal" class="mb-3" density="compact">
                  <div>Базовая ставка (медиана): <strong>{{ laborPreview.baseRate.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) }} руб./ч</strong></div>
                  <div>Итоговая ставка: <strong>{{ laborPreview.finalRate.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) }} руб./ч</strong></div>
                </v-alert>

                <!-- Contractor preview -->
                <v-alert v-if="contractorPreview" type="info" variant="tonal" class="mb-3" density="compact">
                  <div>Базовая ставка (медиана): <strong>{{ contractorPreview.baseRate.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) }} руб./ч</strong></div>
                  <div>Страховые начисления ({{ profileForm.employer_contrib_pct }}%): +{{ contractorPreview.contribRate.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) }} руб./ч</div>
                  <div>Нагруженная ставка: {{ contractorPreview.loadedLaborRate.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) }} руб./ч</div>
                  <div>Коэф. загрузки ({{ profileForm.base_hours_month }}/{{ profileForm.billable_hours_month }}): {{ contractorPreview.utilizationK.toFixed(2) }}</div>
                  <div>Себестоимость часа: {{ contractorPreview.costRate.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) }} руб./ч</div>
                  <div>Рентабельность ({{ profileForm.profit_pct }}%): +{{ contractorPreview.profitAmount.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) }} руб./ч</div>
                  <div class="mt-1"><strong>Итоговая ставка: {{ contractorPreview.finalRate.toLocaleString('ru-RU', { minimumFractionDigits: 2 }) }} руб./ч</strong></div>
                </v-alert>
              </template>
              <v-alert v-else-if="previewRegionId && !previewLoading" type="warning" variant="tonal" class="mb-3" density="compact">
                Нет активных источников для данного профиля и региона
              </v-alert>
            </template>
          </v-form>
        </v-card-text>

        <v-card-actions class="pt-0">
          <v-spacer />
          <v-btn color="grey" variant="text" @click="profileDialog = false">
            Отмена
          </v-btn>
          <v-btn
            color="primary"
            variant="elevated"
            :loading="savingProfile"
            @click="savePositionProfile"
          >
            Сохранить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Диалог удаления профиля должности -->
    <v-dialog v-model="deleteProfileDialog" max-width="400">
      <v-card class="content-card dialog-card">
        <v-card-title>Удалить профиль должности?</v-card-title>
        <v-card-text v-if="deletingPositionProfile" class="pt-4">
          Вы уверены, что хотите удалить профиль <strong>{{ deletingPositionProfile.name }}</strong>?
          Это действие нельзя отменить.
        </v-card-text>
        <v-card-actions class="pt-0">
          <v-spacer />
          <v-btn color="grey" variant="text" @click="deleteProfileDialog = false">
            Отмена
          </v-btn>
          <v-btn
            color="error"
            variant="elevated"
            :loading="deletingProfile_"
            @click="confirmDeleteProfile"
          >
            Удалить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import api from '@/api/axios'

// === Интерфейсы ===
interface WorkProfile {
  id: number
  region_id: number
  region_name: string
  position_profile_id?: number | null
  position_profile_name?: string | null
  source: string
  type: 'single' | 'range'
  salary_value: number
  salary_value_min?: number | null
  salary_value_max?: number | null
  salary_display: string
  rate_display: string
  rate_per_hour?: number
  min_rate?: number
  max_rate?: number
  hours_per_month: number
  source_date?: string | null
  source_date_formatted?: string
  link?: string | null
  note?: string | null
  is_active: boolean
  is_range: boolean
}

interface Region {
  id: number
  name: string
}

interface PositionProfile {
  id: number
  name: string
  description?: string | null
  sort_order?: number
  rate_model?: 'labor' | 'contractor'
  employer_contrib_pct?: number
  base_hours_month?: number
  billable_hours_month?: number
  profit_pct?: number
  rounding_mode?: 'none' | 'int' | '10' | '100'
}

// === State ===
const workProfiles = ref<WorkProfile[]>([])
const filteredProfiles = ref<WorkProfile[]>([])
const regions = ref<Region[]>([])
const positionProfiles = ref<PositionProfile[]>([])
const dialog = ref(false)
const deleteDialog = ref(false)
const saving = ref(false)
const deleting = ref(false)
const loadingSources = ref(false)
const loadingPositionProfiles = ref(false)
const editingId = ref<number | null>(null)
const searchQuery = ref('')
const selectedRegion = ref<number | null>(null)
const showOnlyActive = ref(true)
const deletingProfile = ref<WorkProfile | null>(null)
const formRef = ref()

// === State для управления профилями должностей ===
const activeTab = ref('sources')
const profileDialog = ref(false)
const deleteProfileDialog = ref(false)
const savingProfile = ref(false)
const deletingProfile_ = ref(false)
const editingProfileId = ref<number | null>(null)
const deletingPositionProfile = ref<PositionProfile | null>(null)
const profileFormRef = ref()

const profileForm = ref({
  name: '',
  description: '',
  sort_order: 0,
  rate_model: 'labor' as 'labor' | 'contractor',
  employer_contrib_pct: 30,
  base_hours_month: 160,
  billable_hours_month: 120,
  profit_pct: 15,
  rounding_mode: 'none' as 'none' | 'int' | '10' | '100',
})

const form = ref({
  region_id: null as number | null,
  position_profile_id: null as number | null,
  source: '',
  type: 'single' as 'single' | 'range',
  salary_value: null as number | null,
  salary_value_min: null as number | null,
  salary_value_max: null as number | null,
  hours_per_month: 160,
  source_date: new Date().toISOString().split('T')[0],
  link: '',
  note: '',
  is_active: true,
})

// === Таблица ===
const headers = [
  { title: 'Регион', key: 'region_name', width: '120px' },
  { title: 'Источник', key: 'source', width: '150px' },
  { title: 'Профиль должности', key: 'position_profile_name', width: '150px' },
  { title: 'Зарплата', key: 'salary_display', width: '140px' },
  { title: 'Ставка/час', key: 'rate_display', width: '120px' },
  { title: 'Часы/мес', key: 'hours_per_month', width: '100px', align: 'center' as const },
  { title: 'Ссылка', key: 'link', width: '80px', align: 'center' as const, sortable: false },
  { title: 'Примечание', key: 'note', width: '150px' },
  { title: 'Статус', key: 'is_active', width: '100px' },
  { title: '', key: 'actions', width: '80px', sortable: false },
]

// === Таблица профилей должностей ===
const profileHeaders = [
  { title: 'Название', key: 'name', width: '250px' },
  { title: 'Описание', key: 'description', width: '300px' },
  { title: 'Модель ставки', key: 'rate_model', width: '150px' },
  { title: 'Порядок сортировки', key: 'sort_order', width: '120px', align: 'center' as const },
  { title: '', key: 'actions', width: '80px', sortable: false },
]

// === Вычисляемые ===
const uniqueRegions = computed(() => {
  return new Set(workProfiles.value.map(p => p.region_id))
})

const uniqueProfiles = computed(() => {
  return new Set(workProfiles.value
    .filter(p => p.position_profile_id)
    .map(p => p.position_profile_id))
})

const calculatedRate = computed(() => {
  if (form.value.type === 'single' && form.value.salary_value) {
    const hours = form.value.hours_per_month || 160
    return Math.round(form.value.salary_value / hours)
  }
  return null
})

const calculatedRateMin = computed(() => {
  if (form.value.type === 'range' && form.value.salary_value_min) {
    const hours = form.value.hours_per_month || 160
    return Math.round(form.value.salary_value_min / hours)
  }
  return null
})

const calculatedRateMax = computed(() => {
  if (form.value.type === 'range' && form.value.salary_value_max) {
    const hours = form.value.hours_per_month || 160
    return Math.round(form.value.salary_value_max / hours)
  }
  return null
})

// === Contractor rate preview ===
const previewRegionId = ref<number | null>(null)
const previewBaseRate = ref<number | null>(null)
const previewLoading = ref(false)

const contractorPreview = computed(() => {
  const f = profileForm.value
  if (f.rate_model !== 'contractor') return null
  if (previewBaseRate.value === null || previewBaseRate.value <= 0) return null

  const baseRate = previewBaseRate.value
  const contribPct = f.employer_contrib_pct || 30
  const baseHours = f.base_hours_month || 160
  const billableHours = f.billable_hours_month || 120
  const profitPct = f.profit_pct || 15

  const contribRate = baseRate * (contribPct / 100)
  const loadedLaborRate = baseRate + contribRate
  const utilizationK = baseHours / billableHours
  const costRate = loadedLaborRate * utilizationK
  const profitAmount = costRate * (profitPct / 100)
  const contractorRate = costRate + profitAmount

  let finalRate = contractorRate
  const rounding = f.rounding_mode || 'none'
  if (rounding === 'int') finalRate = Math.round(finalRate)
  else if (rounding === '10') finalRate = Math.round(finalRate / 10) * 10
  else if (rounding === '100') finalRate = Math.round(finalRate / 100) * 100
  else finalRate = Math.round(finalRate * 100) / 100

  return {
    baseRate: Math.round(baseRate * 100) / 100,
    contribRate: Math.round(contribRate * 100) / 100,
    loadedLaborRate: Math.round(loadedLaborRate * 100) / 100,
    utilizationK: Math.round(utilizationK * 10000) / 10000,
    costRate: Math.round(costRate * 100) / 100,
    profitAmount: Math.round(profitAmount * 100) / 100,
    contractorRate: Math.round(contractorRate * 100) / 100,
    finalRate,
  }
})

const laborPreview = computed(() => {
  if (profileForm.value.rate_model !== 'labor') return null
  if (previewBaseRate.value === null || previewBaseRate.value <= 0) return null

  let finalRate = previewBaseRate.value
  const rounding = profileForm.value.rounding_mode || 'none'
  if (rounding === 'int') finalRate = Math.round(finalRate)
  else if (rounding === '10') finalRate = Math.round(finalRate / 10) * 10
  else if (rounding === '100') finalRate = Math.round(finalRate / 100) * 100
  else finalRate = Math.round(finalRate * 100) / 100

  return { baseRate: Math.round(previewBaseRate.value * 100) / 100, finalRate }
})

const loadPreviewBaseRate = async () => {
  if (!editingProfileId.value && !previewRegionId.value) {
    previewBaseRate.value = null
    return
  }
  // Нужен хотя бы регион для расчёта
  if (!previewRegionId.value) {
    previewBaseRate.value = null
    return
  }

  previewLoading.value = true
  try {
    // Получить активные источники для текущего профиля и региона
    const profileId = editingProfileId.value
    const sources = workProfiles.value.filter(s =>
      s.is_active &&
      s.position_profile_id === profileId &&
      s.region_id === previewRegionId.value
    )

    if (sources.length === 0) {
      previewBaseRate.value = null
      return
    }

    // Вычислить медиану ставок
    const rates = sources
      .map(s => s.rate_per_hour ?? 0)
      .filter(r => r > 0)
      .sort((a, b) => a - b)

    if (rates.length === 0) {
      previewBaseRate.value = null
      return
    }

    const mid = Math.floor(rates.length / 2)
    previewBaseRate.value = rates.length % 2 === 0
      ? (rates[mid - 1]! + rates[mid]!) / 2
      : rates[mid]!
  } catch (error) {
    console.error('Error loading preview rate:', error)
    previewBaseRate.value = null
  } finally {
    previewLoading.value = false
  }
}

const roundingOptions = [
  { title: 'Без округления', value: 'none' },
  { title: 'До целого рубля', value: 'int' },
  { title: 'До 10 руб.', value: '10' },
  { title: 'До 100 руб.', value: '100' },
]

// === Методы ===

const loadWorkProfiles = async () => {
  loadingSources.value = true
  try {
    const response = await api.get('/api/global-normohour-sources')
    workProfiles.value = response.data.data || []
    applyFilters()
  } catch (error) {
    console.error('Ошибка загрузки профилей работ:', error)
  } finally {
    loadingSources.value = false
  }
}

const loadRegions = async () => {
  try {
    const response = await api.get('/api/regions')
    regions.value = (response.data.data || response.data || []).sort((a: any, b: any) => 
      (a.region_name || '').localeCompare(b.region_name || '')
    )
  } catch (error) {
    console.error('Ошибка загрузки регионов:', error)
  }
}

const loadPositionProfiles = async () => {
  loadingPositionProfiles.value = true
  try {
    const response = await api.get('/api/position-profiles')
    positionProfiles.value = response.data.data || response.data || []
  } catch (error) {
    console.error('Ошибка загрузки профилей должностей:', error)
  } finally {
    loadingPositionProfiles.value = false
  }
}

const applyFilters = () => {
  let filtered = workProfiles.value

  // Фильтр по поиску
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    filtered = filtered.filter(p =>
      p.source.toLowerCase().includes(query) ||
      (p.note && p.note.toLowerCase().includes(query))
    )
  }

  // Фильтр по регионам
  if (selectedRegion.value) {
    filtered = filtered.filter(p => p.region_id === selectedRegion.value)
  }

  // Фильтр по активности
  if (showOnlyActive.value) {
    filtered = filtered.filter(p => p.is_active)
  }

  filteredProfiles.value = filtered
}

const onTypeChange = () => {
  if (form.value.type === 'single') {
    form.value.salary_value_min = null
    form.value.salary_value_max = null
  } else {
    form.value.salary_value = null
  }
  calculateRates()
}

const calculateRates = () => {
  // Фронтенд расчёт для предварительного просмотра
  // Финальный расчёт выполняется на backend
}

// Функция для очистки URL от query параметров
const cleanUrl = (url: string | null | undefined): string | null => {
  if (!url || !url.trim()) return null
  
  try {
    // Убираем всё после символа ?
    const cleanedUrl = url.split('?')[0] ?? url
    return cleanedUrl
  } catch (error) {
    // Если возникла ошибка, возвращаем исходный URL
    return url
  }
}

const openDialog = () => {
  editingId.value = null
  form.value = {
    region_id: null,
    position_profile_id: null,
    source: '',
    type: 'single',
    salary_value: null,
    salary_value_min: null,
    salary_value_max: null,
    hours_per_month: 160,
    source_date: new Date().toISOString().split('T')[0],
    link: '',
    note: '',
    is_active: true,
  }
  dialog.value = true
}

const editWorkProfile = (profile: WorkProfile) => {
  editingId.value = profile.id
  form.value = {
    region_id: profile.region_id,
    position_profile_id: profile.position_profile_id || null,
    source: profile.source,
    type: profile.is_range ? 'range' : 'single',
    salary_value: profile.is_range ? null : (profile.salary_value || null),
    salary_value_min: profile.is_range ? (profile.salary_value_min || null) : null,
    salary_value_max: profile.is_range ? (profile.salary_value_max || null) : null,
    hours_per_month: profile.hours_per_month || 160,
    source_date: profile.source_date || new Date().toISOString().split('T')[0],
    link: profile.link || '',
    note: profile.note || '',
    is_active: profile.is_active,
  }
  dialog.value = true
}

const saveProfile = async () => {
  if (!formRef.value?.validate()) return

  saving.value = true
  try {
    const payload = {
      region_id: form.value.region_id,
      position_profile_id: form.value.position_profile_id || null,
      source: form.value.source,
      type: form.value.type,
      salary_value: form.value.salary_value || null,
      salary_value_min: form.value.salary_value_min || null,
      salary_value_max: form.value.salary_value_max || null,
      hours_per_month: form.value.hours_per_month || 160,
      source_date: form.value.source_date,
      link: cleanUrl(form.value.link),
      note: form.value.note || null,
      is_active: form.value.is_active,
    }

    if (editingId.value) {
      await api.put(`/api/global-normohour-sources/${editingId.value}`, payload)
    } else {
      await api.post('/api/global-normohour-sources', payload)
    }

    dialog.value = false
    await loadWorkProfiles()
  } catch (error) {
    console.error('Ошибка сохранения:', error)
    alert('Ошибка сохранения источника профиля работ')
  } finally {
    saving.value = false
  }
}

const toggleActive = async (profile: WorkProfile) => {
  try {
    await api.get(`/api/global-normohour-sources/${profile.id}/toggle-active`)
    await loadWorkProfiles()
  } catch (error) {
    console.error('Ошибка изменения активности:', error)
  }
}

const deleteWorkProfile = (profile: WorkProfile) => {
  deletingProfile.value = profile
  deleteDialog.value = true
}

const confirmDelete = async () => {
  if (!deletingProfile.value) return

  deleting.value = true
  try {
    await api.delete(`/api/global-normohour-sources/${deletingProfile.value.id}`)
    deleteDialog.value = false
    await loadWorkProfiles()
  } catch (error) {
    console.error('Ошибка удаления:', error)
    alert('Ошибка удаления источника профиля работ')
  } finally {
    deleting.value = false
  }
}

// === Методы для управления профилями должностей ===
const openProfileDialog = () => {
  editingProfileId.value = null
  profileForm.value = {
    name: '',
    description: '',
    sort_order: 0,
    rate_model: 'labor',
    employer_contrib_pct: 30,
    base_hours_month: 160,
    billable_hours_month: 120,
    profit_pct: 15,
    rounding_mode: 'none',
  }
  previewRegionId.value = null
  previewBaseRate.value = null
  profileDialog.value = true
}

const editProfile = (profile: PositionProfile) => {
  editingProfileId.value = profile.id
  profileForm.value = {
    name: profile.name,
    description: profile.description || '',
    sort_order: profile.sort_order || 0,
    rate_model: profile.rate_model || 'labor',
    employer_contrib_pct: profile.employer_contrib_pct ?? 30,
    base_hours_month: profile.base_hours_month ?? 160,
    billable_hours_month: profile.billable_hours_month ?? 120,
    profit_pct: profile.profit_pct ?? 15,
    rounding_mode: profile.rounding_mode || 'none',
  }
  previewRegionId.value = null
  previewBaseRate.value = null
  profileDialog.value = true
}

const savePositionProfile = async () => {
  if (!profileFormRef.value?.validate()) return

  savingProfile.value = true
  try {
    const payload = {
      name: profileForm.value.name,
      description: profileForm.value.description || null,
      sort_order: profileForm.value.sort_order || 0,
      rate_model: profileForm.value.rate_model || 'labor',
      employer_contrib_pct: profileForm.value.employer_contrib_pct ?? 30,
      base_hours_month: profileForm.value.base_hours_month ?? 160,
      billable_hours_month: profileForm.value.billable_hours_month ?? 120,
      profit_pct: profileForm.value.profit_pct ?? 15,
      rounding_mode: profileForm.value.rounding_mode || 'none',
    }

    if (editingProfileId.value) {
      await api.put(`/api/position-profiles/${editingProfileId.value}`, payload)
    } else {
      await api.post('/api/position-profiles', payload)
    }

    profileDialog.value = false
    await loadPositionProfiles()
  } catch (error) {
    console.error('Ошибка сохранения профиля должности:', error)
    alert('Ошибка сохранения профиля должности')
  } finally {
    savingProfile.value = false
  }
}

const deletePositionProfile = (profile: PositionProfile) => {
  deletingPositionProfile.value = profile
  deleteProfileDialog.value = true
}

const confirmDeleteProfile = async () => {
  if (!deletingPositionProfile.value) return

  deletingProfile_.value = true
  try {
    await api.delete(`/api/position-profiles/${deletingPositionProfile.value.id}`)
    deleteProfileDialog.value = false
    await loadPositionProfiles()
  } catch (error) {
    console.error('Ошибка удаления профиля должности:', error)
    alert('Ошибка удаления профиля должности')
  } finally {
    deletingProfile_.value = false
  }
}

// === Lifecycle ===
onMounted(async () => {
  await Promise.all([
    loadWorkProfiles(),
    loadRegions(),
    loadPositionProfiles(),
  ])
})
</script>

<style scoped>
.work-profiles-view {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.page-title {
  font-size: 20px;
  font-weight: 600;
  margin: 0 0 4px;
}

.page-subtitle {
  font-size: 13px;
  color: rgba(var(--v-theme-on-surface), 0.6);
}

.page-tabs {
  margin-bottom: 4px;
}

.section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin: 8px 0 12px;
}

.section-title {
  font-size: 16px;
  font-weight: 600;
  margin: 0 0 4px;
}

.section-subtitle {
  font-size: 13px;
  color: rgba(var(--v-theme-on-surface), 0.6);
}

.primary-btn {
  text-transform: none;
  font-weight: 500;
}

.content-card {
  border-radius: 12px;
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(0, 0, 0, 0.08);
}

.filters-card {
  padding: 4px 4px 0;
}

.filters-row {
  display: flex;
  gap: 12px;
  align-items: center;
  flex-wrap: wrap;
}

.stats-grid {
  margin-top: 4px;
  margin-bottom: 4px;
}

.stat-card :deep(.v-card-text) {
  padding: 14px 16px;
}

.data-card {
  overflow: hidden;
}

.section-card-title {
  font-size: 14px;
  font-weight: 600;
  padding-bottom: 8px;
}

.data-table {
  border-top: 1px solid rgba(0, 0, 0, 0.06);
}

.dialog-card {
  border-radius: 12px;
}

:deep(.v-theme--dark) .page-subtitle,
:deep(.v-theme--dark) .section-subtitle {
  color: rgba(var(--v-theme-on-surface), 0.7);
}

:deep(.v-theme--dark) .content-card {
  border-color: rgba(255, 255, 255, 0.08);
}

:deep(.v-theme--dark) .data-table {
  border-top-color: rgba(255, 255, 255, 0.08);
}

.text-truncate {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
