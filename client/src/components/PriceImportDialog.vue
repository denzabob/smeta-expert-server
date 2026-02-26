<template>
  <v-dialog v-model="dialog" max-width="1000" persistent scrollable>
    <v-card>
      <v-card-title class="d-flex align-center">
        <v-icon class="mr-2">mdi-file-import</v-icon>
        Импорт прайса {{ targetType === 'operations' ? 'операций' : 'материалов' }}
        <v-spacer></v-spacer>
        <v-chip :color="statusColor" size="small">{{ statusText }}</v-chip>
      </v-card-title>

      <v-card-text class="pa-0">
        <!-- Step 1: Upload / Paste -->
        <v-stepper v-model="step" :items="steps" flat hide-actions>
          <!-- Upload Step -->
          <template v-slot:item.1>
            <!-- Show warning if supplier not selected -->
            <v-alert
              v-if="!selectedSupplierId || !selectedPriceListId"
              type="warning"
              icon="mdi-alert"
              class="mb-4"
            >
              <strong>Сначала выберите поставщика и прайс-лист</strong> для начала импорта
            </v-alert>

            <v-container>
              <v-row>
                <v-col cols="12" md="6">
                  <v-card variant="outlined" class="pa-4">
                    <v-card-title class="text-subtitle-1">
                      <v-icon class="mr-2">mdi-file-upload</v-icon>
                      Загрузить файл
                    </v-card-title>
                    <v-file-input
                      v-model="uploadFile"
                      label="Excel или CSV файл"
                      accept=".xlsx,.xls,.csv"
                      prepend-icon="mdi-paperclip"
                      show-size
                      :disabled="!selectedSupplierId || !selectedPriceListId || isUploadingFile"
                      :loading="isUploadingFile"
                      @update:model-value="onFileSelected"
                    ></v-file-input>
                    <v-text-field
                      v-if="!selectedSupplierId || !selectedPriceListId"
                      readonly
                      color="warning"
                      value="Загрузка недоступна - выберите поставщика и прайс-лист выше"
                      class="mt-2"
                    ></v-text-field>
                  </v-card>
                </v-col>
                <v-col cols="12" md="6">
                  <v-card variant="outlined" class="pa-4">
                    <v-card-title class="text-subtitle-1">
                      <v-icon class="mr-2">mdi-content-paste</v-icon>
                      Вставить из буфера
                    </v-card-title>
                    <v-textarea
                      v-model="pasteContent"
                      label="Вставьте данные из Excel"
                      rows="5"
                      :disabled="!selectedSupplierId || !selectedPriceListId || isUploadingFile"
                      placeholder="Скопируйте таблицу из Excel и вставьте сюда..."
                    ></v-textarea>
                    <v-btn 
                      color="primary" 
                      variant="tonal"
                      :disabled="!pasteContent || !selectedSupplierId || !selectedPriceListId || isUploadingFile"
                      :loading="isUploadingFile"
                      @click="onPasteSubmit"
                    >
                      Загрузить из буфера
                    </v-btn>
                  </v-card>
                </v-col>
              </v-row>

              <!-- Supplier Selection -->
              <v-row class="mt-4">
                <v-col cols="12" md="6">
                  <v-autocomplete
                    v-model="selectedSupplierId"
                    :items="suppliers"
                    :loading="isLoadingSuppliers"
                    :disabled="isLoadingSuppliers"
                    :placeholder="isLoadingSuppliers ? 'Загрузка…' : 'Выберите поставщика'"
                    item-title="name"
                    item-value="id"
                    label="Поставщик *"
                    hint="Выберите поставщика - обязательно для импорта"
                    persistent-hint
                    :rules="[v => !!v || 'Поставщик обязателен']"
                  >
                    <template v-slot:append>
                      <v-btn icon size="small" @click="showNewSupplierDialog = true" :disabled="isLoadingSuppliers">
                        <v-icon>mdi-plus</v-icon>
                      </v-btn>
                    </template>
                  </v-autocomplete>
                </v-col>
                <v-col cols="12" md="6">
                  <v-autocomplete
                    v-model="selectedPriceListId"
                    :items="priceLists"
                    :loading="isLoadingPriceLists"
                    :disabled="!selectedSupplierId || isLoadingPriceLists"
                    :placeholder="isLoadingPriceLists ? 'Загрузка…' : 'Выберите прайс-лист'"
                    item-title="name"
                    item-value="id"
                    label="Прайс-лист *"
                    hint="Выберите или создайте прайс-лист - обязательно"
                    persistent-hint
                    :rules="[v => !!v || 'Прайс-лист обязателен']"
                  >
                    <template v-slot:append>
                      <v-btn icon size="small" :disabled="!selectedSupplierId || isLoadingPriceLists" @click="showNewPriceListDialog = true">
                        <v-icon>mdi-plus</v-icon>
                      </v-btn>
                    </template>
                  </v-autocomplete>
                </v-col>
              </v-row>

              <!-- Pending sessions warning -->
              <v-expand-transition>
                <v-alert 
                  v-if="pendingSessions.length > 0"
                  type="warning"
                  variant="tonal"
                  class="mt-4"
                  closable
                >
                  <v-alert-title>Есть незавершённые импорты ({{ pendingSessions.length }})</v-alert-title>
                  <p class="mb-2">Вы можете продолжить ранее начатый импорт или отменить его:</p>
                  
                  <v-list density="compact" bg-color="transparent">
                    <v-list-item
                      v-for="ps in pendingSessions"
                      :key="ps.id"
                      class="px-0"
                    >
                      <template v-slot:prepend>
                        <v-icon size="small" color="warning">mdi-file-clock</v-icon>
                      </template>
                      <v-list-item-title>
                        {{ ps.original_filename || 'Без имени' }}
                      </v-list-item-title>
                      <v-list-item-subtitle>
                        {{ formatSessionStatus(ps.status) }} • {{ formatDate(ps.created_at) }}
                        <span v-if="ps.price_list_version?.price_list?.name">
                          • {{ ps.price_list_version.price_list.name }}
                        </span>
                      </v-list-item-subtitle>
                      <template v-slot:append>
                        <v-btn
                          size="small"
                          color="primary"
                          variant="tonal"
                          class="mr-1"
                          @click="resumeSession(ps)"
                          :loading="isProcessing"
                        >
                          Продолжить
                        </v-btn>
                        <v-btn
                          size="small"
                          color="error"
                          variant="text"
                          icon
                          @click="deletePendingSession(ps)"
                        >
                          <v-icon>mdi-close</v-icon>
                        </v-btn>
                      </template>
                    </v-list-item>
                  </v-list>
                </v-alert>
              </v-expand-transition>
            </v-container>
          </template>

          <!-- Mapping Step -->
          <template v-slot:item.2>
            <v-container v-if="preview">
              <v-alert type="info" variant="tonal" class="mb-4">
                <strong>Шаг 2:</strong> Укажите какие колонки соответствуют каким полям.
                <div class="mt-1">
                  Обязательные поля: 
                  <v-chip size="x-small" color="primary" class="mx-1">Наименование</v-chip>
                  <v-chip size="x-small" color="success" class="mx-1">{{ targetType === 'operations' ? 'Цена за единицу' : 'Цена' }}</v-chip>
                </div>
              </v-alert>

              <!-- Header row and sheet selection -->
              <v-row class="mb-4">
                <v-col cols="12" md="3" v-if="preview.sheet_count > 1">
                  <v-select
                    v-model="selectedSheetIndex"
                    :items="sheetOptions"
                    label="Лист"
                    variant="outlined"
                    density="compact"
                    @update:model-value="reloadPreview"
                  ></v-select>
                </v-col>
                <v-col cols="12" :md="preview.sheet_count > 1 ? 3 : 4">
                  <v-select
                    v-model="headerRowIndex"
                    :items="headerRowOptions"
                    label="Строка заголовков"
                    variant="outlined"
                    density="compact"
                    @update:model-value="updateHeaderRow"
                  ></v-select>
                </v-col>
                <v-col cols="auto">
                  <v-chip :color="isMappingValid ? 'success' : 'warning'" variant="flat">
                    <v-icon start>{{ isMappingValid ? 'mdi-check' : 'mdi-alert' }}</v-icon>
                    {{ isMappingValid ? 'Готово к анализу' : `Не хватает: ${validationStatus.missing.join(', ')}` }}
                  </v-chip>
                </v-col>
              </v-row>

              <!-- Mapping summary badges -->
              <v-row class="mb-3" dense v-if="Object.keys(columnMapping).length > 0">
                <v-col cols="auto" v-for="(field, colIdx) in columnMapping" :key="colIdx">
                  <v-chip 
                    v-if="field && field !== 'ignore'"
                    :color="getMappingColor(field)"
                    size="small"
                    closable
                    @click:close="columnMapping[Number(colIdx)] = null"
                  >
                    {{ getFieldTitle(field) }} → Кол. {{ Number(colIdx) + 1 }}
                  </v-chip>
                </v-col>
              </v-row>

              <!-- Loading indicator for mapping table -->
              <v-progress-linear 
                v-if="isChangingHeaderRow || isMappingColumn"
                indeterminate
                color="primary"
                class="mb-2"
              ></v-progress-linear>

              <!-- Preview Table with Mapping -->
              <v-sheet
                class="price-preview-table-container"
                border
                rounded="lg"
                elevation="0"
              >
                <v-table density="compact" fixed-header class="price-preview-table">
                  <thead>
                    <!-- Mapping row -->
                    <tr class="mapping-row">
                      <th class="text-center row-number-col">#</th>
                      <th
                        v-for="colIdx in preview.column_count"
                        :key="'map-' + colIdx"
                        class="pa-1 mapping-cell"
                      >
                        <v-select
                          :model-value="columnMapping[colIdx - 1]"
                          @update:model-value="handleMappingChange(colIdx - 1, $event)"
                          :items="getMappingOptions(colIdx - 1)"
                          :disabled="isChangingHeaderRow || isMappingColumn"
                          :loading="isMappingColumn"
                          variant="outlined"
                          density="compact"
                          hide-details
                          placeholder="—"
                          clearable
                          :bg-color="getColumnBgColor(columnMapping[colIdx - 1] ?? null)"
                          class="mapping-select"
                        ></v-select>
                      </th>
                    </tr>
                    <!-- Header row from file -->
                    <tr class="header-row">
                      <th class="text-center text-grey row-number-col">Строка</th>
                      <th
                        v-for="(header, hIdx) in preview.headers"
                        :key="'head-' + hIdx"
                        class="text-caption text-grey-darken-1"
                        :class="getCellClass(Number(hIdx))"
                      >
                        {{ header || `Колонка ${Number(hIdx) + 1}` }}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr 
                      v-for="(row, rowIdx) in displayRows" 
                      :key="rowIdx"
                      :class="{ 'header-source-row': Number(rowIdx) === headerRowIndex }"
                    >
                      <td class="text-center text-grey row-number-col">{{ Number(rowIdx) + 1 }}</td>
                      <td
                        v-for="(cell, cIdx) in row"
                        :key="cIdx"
                        :class="getCellClass(Number(cIdx))"
                      >
                        {{ formatCell(cell) }}
                      </td>
                    </tr>
                  </tbody>
                </v-table>
              </v-sheet>

              <!-- Type hints -->
              <v-row class="mt-4" dense v-if="preview.column_types">
                <v-col cols="12">
                  <div class="text-caption text-grey">
                    <v-icon size="small">mdi-information</v-icon>
                    Автоопределение типов: 
                    <span v-for="(type, idx) in preview.column_types" :key="idx" class="mx-1">
                      <v-chip size="x-small" :color="getTypeColor(type)">
                        Кол.{{ Number(idx) + 1 }}: {{ getTypeName(type) }}
                      </v-chip>
                    </span>
                  </div>
                </v-col>
              </v-row>
            </v-container>
          </template>

          <!-- Resolution Step -->
          <template v-slot:item.3>
            <v-container v-if="resolutionData">
              <!-- Stats -->
              <v-row class="mb-4">
                <v-col cols="auto">
                  <v-chip color="success" variant="flat">
                    <v-icon start>mdi-check</v-icon>
                    Автоматически: {{ resolutionData.stats.auto_matched }}
                  </v-chip>
                </v-col>
                <v-col cols="auto">
                  <v-chip color="warning" variant="flat">
                    <v-icon start>mdi-help-circle</v-icon>
                    Неоднозначные: {{ resolutionData.stats.ambiguous }}
                  </v-chip>
                </v-col>
                <v-col cols="auto">
                  <v-chip color="info" variant="flat">
                    <v-icon start>mdi-plus-circle</v-icon>
                    Новые: {{ resolutionData.stats.new }}
                  </v-chip>
                </v-col>
                <v-col cols="auto">
                  <v-chip color="grey" variant="flat">
                    <v-icon start>mdi-eye-off</v-icon>
                    Пропущено: {{ resolutionData.stats.ignored }}
                  </v-chip>
                </v-col>
              </v-row>

              <!-- Filter tabs -->
              <v-tabs v-model="resolutionFilter" class="mb-4">
                <v-tab v-if="hasPendingItems" value="all">Все ({{ pendingItems.length }})</v-tab>
                <v-tab v-if="hasPendingItems" value="ambiguous">Неоднозначные ({{ ambiguousItems.length }})</v-tab>
                <v-tab v-if="hasPendingItems" value="new">Новые ({{ newItems.length }})</v-tab>
                <v-tab value="auto_matched">
                  <v-icon start>mdi-check</v-icon>
                  Автоматически связанные ({{ autoMatchedItems.length }})
                </v-tab>
              </v-tabs>
              <v-alert v-if="!hasPendingItems && resolutionFilter !== 'auto_matched'" type="success" variant="tonal" class="mb-4">
                Все записи сопоставлены автоматически. Дополнительных действий не требуется.
              </v-alert>

              <!-- Selection info and bulk actions -->
              <v-card v-if="resolutionFilter !== 'auto_matched'" variant="outlined" class="mb-4 pa-3">
                <v-row align="center" dense>
                  <v-col cols="auto">
                    <div class="d-flex align-center ga-2">
                      <v-chip :color="selectedRows.length > 0 ? 'primary' : 'default'" variant="flat">
                        Выбрано: {{ selectedRows.length }} / {{ filteredResolutionItems.length }}
                      </v-chip>
                      <v-btn 
                        size="small" 
                        variant="outlined" 
                        color="primary"
                        @click="selectAllFilteredItems"
                        :disabled="selectedRows.length === filteredResolutionItems.length"
                      >
                        <v-icon start>mdi-checkbox-multiple-marked</v-icon>
                        Выделить все ({{ filteredResolutionItems.length }})
                      </v-btn>
                      <v-btn 
                        size="small" 
                        variant="outlined" 
                        @click="selectedRows = []"
                        :disabled="selectedRows.length === 0"
                      >
                        <v-icon start>mdi-checkbox-multiple-blank-outline</v-icon>
                        Снять выделение
                      </v-btn>
                    </div>
                  </v-col>
                </v-row>
                <v-row v-if="selectedRows.length > 0" align="center" dense>
                  <v-col cols="12">
                    <v-divider class="my-2"></v-divider>
                    <div class="text-caption text-medium-emphasis mb-2">
                      Групповые действия для выбранных ({{ selectedRows.length }}):
                    </div>
                  </v-col>
                  <v-col cols="auto">
                    <v-btn size="small" color="success" variant="tonal" @click="bulkAcceptAsNew">
                      <v-icon start>mdi-plus</v-icon>
                      Создать новые
                    </v-btn>
                  </v-col>
                  <v-col cols="auto">
                    <v-btn size="small" color="grey" variant="tonal" @click="bulkIgnore">
                      <v-icon start>mdi-eye-off</v-icon>
                      Пропустить
                    </v-btn>
                  </v-col>
                </v-row>
              </v-card>

              <!-- Resolution table -->
              <v-alert v-if="hasPendingItems && !hasFilteredItems" type="info" variant="tonal" class="mb-4">
                Для выбранного фильтра нет элементов.
              </v-alert>
              <v-data-table
                v-else-if="hasFilteredItems"
                v-model="selectedRows"
                :headers="resolutionFilter === 'auto_matched' ? autoMatchedHeaders : resolutionHeaders"
                :items="filteredResolutionItems"
                :items-per-page="20"
                :items-per-page-options="[
                  { value: 20, title: '20' },
                  { value: 50, title: '50' },
                  { value: 100, title: '100' },
                  { value: -1, title: 'Все' }
                ]"
                :show-select="resolutionFilter !== 'auto_matched'"
                item-value="row_index"
                density="compact"
                class="resolution-table"
              >
                <!-- Название из прайса -->
                <template #item.raw_data.name="{ item }: { item: ResolutionItem }">
                  <div class="text-body-2 text-truncate" style="max-width: 250px;" :title="item.raw_data.name">
                    {{ item.raw_data.name }}
                  </div>
                  <div class="text-caption text-grey" v-if="item.raw_data.unit">
                    {{ item.raw_data.unit }}
                  </div>
                </template>

                <!-- Цена -->
                <template #item.raw_data.price="{ item }: { item: ResolutionItem }">
                  <span class="text-body-2">{{ formatPrice(item.raw_data.price || item.raw_data.cost_per_unit) }}</span>
                </template>

                <!-- Выбор базовой операции (inline autocomplete) -->
                <template #item.linked_operation="{ item }: { item: ResolutionItem }">
                  <v-chip
                    v-if="item.decision?.action === 'create'"
                    size="small"
                    color="info"
                    variant="tonal"
                  >
                    Создано без привязки
                  </v-chip>
                  <v-chip
                    v-else-if="item.decision?.action === 'ignore'"
                    size="small"
                    color="grey"
                    variant="tonal"
                  >
                    Пропущено
                  </v-chip>
                  <v-autocomplete
                    v-else
                    :model-value="item.decision?.internal_item_id"
                    @update:model-value="(val) => selectOperationForItem(item, val)"
                    :items="getAvailableOperationsForItem(item)"
                    :loading="isSearchingOperations"
                    item-title="name"
                    item-value="id"
                    density="compact"
                    variant="outlined"
                    hide-details
                    :placeholder="item.candidates?.length ? item.candidates[0]?.name?.substring(0, 20) + '...' : 'Найти операцию...'"
                    clearable
                    :disabled="(item.decision?.action as string) === 'ignore' || (item.decision?.action as string) === 'create'"
                    @update:search="onOperationSearchInput"
                    class="operation-autocomplete"
                    :menu-props="{ maxHeight: 300, maxWidth: 400 }"
                  >
                    <template v-slot:item="{ props, item: opItem }">
                      <v-list-item
                        v-bind="props"
                        :disabled="isOperationAlreadyLinked(opItem.raw.id, item.row_index)"
                      >
                        <template v-slot:append v-if="isOperationAlreadyLinked(opItem.raw.id, item.row_index)">
                          <v-icon size="small" color="grey">mdi-link-lock</v-icon>
                        </template>
                        <template v-slot:subtitle>
                          {{ opItem.raw.unit }}{{ opItem.raw.category ? ' • ' + opItem.raw.category : '' }}
                        </template>
                      </v-list-item>
                    </template>
                    <template v-slot:selection="{ item: opItem }">
                      <span class="text-truncate d-inline-block" style="max-width: 180px;">{{ opItem.raw.name }}</span>
                    </template>
                    <template v-slot:prepend-item v-if="item.candidates?.length">
                      <v-list-subheader>Рекомендуемые:</v-list-subheader>
                    </template>
                  </v-autocomplete>
                </template>

                <!-- Действия (компактные) -->
                <template #item.actions="{ item }: { item: ResolutionItem }">
                  <div class="d-flex align-center ga-1">
                    <v-btn-group density="compact" variant="outlined" divided>
                      <v-btn 
                        size="x-small" 
                        :color="item.decision?.action === 'link' ? 'primary' : 'default'"
                        :variant="item.decision?.action === 'link' ? 'flat' : 'outlined'"
                        @click="setItemAction(item, 'link')"
                        title="Связать с операцией"
                      >
                        <v-icon size="small">mdi-link</v-icon>
                      </v-btn>
                      <v-btn 
                        size="x-small" 
                        :color="item.decision?.action === 'create' ? 'success' : 'default'"
                        :variant="item.decision?.action === 'create' ? 'flat' : 'outlined'"
                        @click="setItemAction(item, 'create')"
                        title="Создать новую"
                      >
                        <v-icon size="small">mdi-plus</v-icon>
                      </v-btn>
                      <v-btn 
                        size="x-small" 
                        :color="item.decision?.action === 'ignore' ? 'grey' : 'default'"
                        :variant="item.decision?.action === 'ignore' ? 'flat' : 'outlined'"
                        @click="setItemAction(item, 'ignore')"
                        title="Пропустить"
                      >
                        <v-icon size="small">mdi-eye-off</v-icon>
                      </v-btn>
                    </v-btn-group>
                  </div>
                </template>

                <!-- Templates для автоматически связанных операций -->
                <template #item.matched_operation="{ item }: { item: ResolutionItem }">
                  <div v-if="getAutoMatchedOperation(item)" class="text-truncate" style="max-width: 280px;">
                    <span class="text-body-2">{{ getAutoMatchedOperation(item)?.name }}</span>
                    <span v-if="getAutoMatchedOperation(item)?.unit" class="text-caption text-grey ml-1">({{ getAutoMatchedOperation(item)?.unit }})</span>
                  </div>
                  <span v-else class="text-grey">—</span>
                </template>

                <template #item.match_method="{ item }: { item: ResolutionItem }">
                  <v-chip size="small" variant="tonal" :color="getMatchMethodColor(item)">
                    {{ getMatchMethodText(item) }}
                  </v-chip>
                </template>

                <template #item.auto_actions="{ item }: { item: ResolutionItem }">
                  <v-btn
                    size="x-small"
                    variant="tonal"
                    color="primary"
                    @click="moveAutoMatchToManual(item)"
                    title="Исправить сопоставление"
                  >
                    Исправить
                  </v-btn>
                </template>
              </v-data-table>
            </v-container>
          </template>

          <!-- Complete Step -->
          <template v-slot:item.4>
            <v-container v-if="importResult">
              <v-alert type="success" variant="tonal" class="mb-4">
                <v-alert-title>Импорт завершен!</v-alert-title>
                Версия создана в статусе <strong>inactive</strong>
              </v-alert>

              <!-- Activate Version Card -->
              <v-card v-if="session && session.price_list_version_id" variant="outlined" class="mb-4 pa-4" color="warning">
                <v-card-title class="text-subtitle-1">
                  <v-icon class="mr-2">mdi-alert-circle</v-icon>
                  Версия создана, но не активирована
                </v-card-title>
                <v-card-text>
                  <p>
                    Импортированная версия находится в статусе <strong>inactive</strong>.
                    Чтобы она стала доступной для использования, необходимо активировать её.
                  </p>
                  <p class="text-caption text-medium-emphasis mb-0">
                    При активации текущая активная версия будет автоматически переведена в архив.
                  </p>
                </v-card-text>
                <v-card-actions>
                  <v-btn
                    color="success"
                    prepend-icon="mdi-check-circle"
                    :loading="isActivating"
                    @click="activateImportedVersion"
                  >
                    Активировать версию
                  </v-btn>
                  <v-btn
                    color="primary"
                    variant="text"
                    prepend-icon="mdi-eye"
                    @click="viewVersionDetails"
                  >
                    Просмотреть версию
                  </v-btn>
                </v-card-actions>
              </v-card>

              <v-row>
                <v-col cols="auto">
                  <v-card variant="outlined" class="pa-4 text-center">
                    <div class="text-h4 text-success">{{ importResult.created_items ?? 0 }}</div>
                    <div class="text-caption">Создано записей</div>
                  </v-card>
                </v-col>
                <v-col cols="auto">
                  <v-card variant="outlined" class="pa-4 text-center">
                    <div class="text-h4 text-primary">{{ importResult.updated_prices ?? 0 }}</div>
                    <div class="text-caption">Обновлено цен</div>
                  </v-card>
                </v-col>
                <v-col cols="auto">
                  <v-card variant="outlined" class="pa-4 text-center">
                    <div class="text-h4 text-info">{{ importResult.created_aliases ?? 0 }}</div>
                    <div class="text-caption">Создано алиасов</div>
                  </v-card>
                </v-col>
                <v-col cols="auto">
                  <v-card variant="outlined" class="pa-4 text-center">
                    <div class="text-h4 text-grey">{{ importResult.skipped ?? 0 }}</div>
                    <div class="text-caption">Пропущено</div>
                  </v-card>
                </v-col>
              </v-row>

              <v-alert v-if="importResult.errors && importResult.errors.length > 0" type="warning" variant="tonal" class="mt-4">
                <v-alert-title>Ошибки ({{ importResult.errors.length }})</v-alert-title>
                <div v-for="err in importResult.errors.slice(0, 5)" :key="err.row_index" class="text-caption">
                  Строка {{ err.row_index }}: {{ err.error }}
                </div>
              </v-alert>
            </v-container>
          </template>
        </v-stepper>
      </v-card-text>

      <v-divider></v-divider>

      <v-card-actions>
        <v-btn variant="text" @click="closeDialog">Закрыть</v-btn>
        <v-spacer></v-spacer>
        
        <!-- Hint when import button is disabled -->
        <v-tooltip v-if="step === 3 && !canExecute" location="top">
          <template v-slot:activator="{ props }">
            <v-icon v-bind="props" color="warning" class="mr-2">mdi-information</v-icon>
          </template>
          <div>
            Обработайте все неоднозначные и новые позиции:<br>
            • Выберите действие для каждой позиции<br>
            • Или пропустите ненужные позиции<br>
            <strong>Совет:</strong> используйте кнопку "Выделить все" и групповые действия
          </div>
        </v-tooltip>
        
        <v-btn v-if="step > 1 && step < 4" variant="text" @click="step--" :disabled="isProcessing || isTransitioning">Назад</v-btn>
        
        <!-- Кнопка для автоматической установки "Создать" для позиций без решения -->
        <v-btn 
          v-if="step === 3 && !canExecute" 
          color="info"
          variant="tonal"
          @click="setCreateForRemaining"
          class="mr-2"
        >
          <v-icon start>mdi-plus-circle</v-icon>
          Создать остальные
        </v-btn>
        
        <v-btn 
          v-if="step === 2" 
          color="primary" 
          :loading="isProcessing || isTransitioning"
          :disabled="!canProceedToResolution"
          @click="submitMapping"
        >
          Анализировать
        </v-btn>
        <v-btn 
          v-if="step === 3" 
          color="success" 
          :loading="isProcessing"
          :disabled="!canExecute"
          @click="executeImport"
        >
          Выполнить импорт
        </v-btn>
        <v-btn v-if="step === 4" color="primary" @click="closeAndRefresh">Готово</v-btn>
      </v-card-actions>
    </v-card>

    <!-- Link to existing item dialog -->
    <v-dialog v-model="linkDialog" max-width="600">
      <v-card>
        <v-card-title>Связать с существующей записью</v-card-title>
        <v-card-text>
          <div class="mb-4" v-if="linkDialogItem">
            <strong>Исходная запись:</strong> {{ linkDialogItem.raw_data.name }}
          </div>
          
          <v-autocomplete
            v-model="linkDialogSelectedId"
            :items="searchResults"
            :loading="isSearching"
            item-title="name"
            item-value="id"
            label="Поиск записи"
            @update:search="onSearchInput"
          >
            <template v-slot:item="{ props, item }">
              <v-list-item v-bind="props">
                <v-list-item-subtitle>
                  {{ item.raw.unit }} | {{ item.raw.category }}
                </v-list-item-subtitle>
              </v-list-item>
            </template>
          </v-autocomplete>

          <v-divider class="my-4"></v-divider>

          <v-alert type="info" variant="tonal" density="compact" class="mb-4">
            <strong>Коэффициент пересчета:</strong><br>
            1 [Ед. поставщика] = X [Ед. внутренних]<br>
            Например: "1 упаковка = 1000 штук" → коэффициент = 1000
          </v-alert>

          <v-row>
            <v-col cols="4">
              <v-text-field
                v-model="linkDialogSupplierUnit"
                label="Ед. поставщика"
                density="compact"
              ></v-text-field>
            </v-col>
            <v-col cols="4">
              <v-text-field
                v-model="linkDialogInternalUnit"
                label="Внутренняя ед."
                density="compact"
              ></v-text-field>
            </v-col>
            <v-col cols="4">
              <v-text-field
                v-model.number="linkDialogConversionFactor"
                label="Коэффициент"
                type="number"
                step="0.01"
                min="0.000001"
                density="compact"
              ></v-text-field>
            </v-col>
          </v-row>

          <v-select
            v-model="linkDialogConversionFactor"
            :items="conversionPresets"
            label="Быстрый выбор коэффициента"
            density="compact"
            clearable
          ></v-select>
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn variant="text" @click="linkDialog = false">Отмена</v-btn>
          <v-btn color="primary" :disabled="!linkDialogSelectedId" @click="confirmLink">Связать</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- New Supplier Dialog -->
    <v-dialog v-model="showNewSupplierDialog" max-width="500">
      <v-card>
        <v-card-title>Новый поставщик</v-card-title>
        <v-card-text>
          <v-text-field v-model="newSupplierName" label="Название" required></v-text-field>
          <v-text-field v-model="newSupplierCode" label="Код (опционально)"></v-text-field>
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn variant="text" @click="showNewSupplierDialog = false">Отмена</v-btn>
          <v-btn color="primary" :disabled="!newSupplierName" @click="createSupplier">Создать</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- New Price List Dialog -->
    <v-dialog v-model="showNewPriceListDialog" max-width="500">
      <v-card>
        <v-card-title>Новый прайс-лист</v-card-title>
        <v-card-text>
          <v-text-field v-model="newPriceListName" label="Название" required></v-text-field>
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn variant="text" @click="showNewPriceListDialog = false">Отмена</v-btn>
          <v-btn color="primary" :disabled="!newPriceListName" @click="createPriceList">Создать</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Cancel Confirmation Dialog -->
    <v-dialog v-model="showCancelConfirmDialog" max-width="500" persistent>
      <v-card>
        <v-card-title class="text-h6">
          <v-icon start color="warning">mdi-alert</v-icon>
          Прервать импорт?
        </v-card-title>
        <v-card-text>
          <p class="mb-3">Вы находитесь в процессе импорта прайса. Выберите действие:</p>
          
          <v-alert type="info" variant="tonal" density="compact" class="mb-3">
            <strong>Отменить импорт:</strong> Сессия будет закрыта, пустая версия прайса (если создана) будет удалена.
          </v-alert>
          
          <v-alert type="warning" variant="tonal" density="compact">
            <strong>Продолжить позже:</strong> Закройте окно без отмены. Вы сможете продолжить импорт из списка незавершённых сессий.
          </v-alert>
        </v-card-text>
        <v-card-actions>
          <v-btn variant="text" @click="showCancelConfirmDialog = false">
            Вернуться к импорту
          </v-btn>
          <v-spacer></v-spacer>
          <v-btn variant="text" color="grey" @click="showCancelConfirmDialog = false; forceCloseDialog()">
            Продолжить позже
          </v-btn>
          <v-btn 
            color="error" 
            variant="flat"
            :loading="isCancelling"
            @click="cancelImportAndClose"
          >
            Отменить импорт
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-dialog>

  <!-- Toast Notification -->
  <v-snackbar
    v-model="showToastMessage"
    :timeout="3000"
    color="info"
    location="top"
  >
    {{ toastMessage }}
  </v-snackbar>

  <!-- Global Loading Overlay -->
  <v-overlay 
    :model-value="isUploadingFile || isTransitioning"
    class="align-center justify-center"
    persistent
    contained
  >
    <v-card class="pa-6 text-center" min-width="300">
      <v-progress-circular
        indeterminate
        size="64"
        color="primary"
        class="mb-4"
      ></v-progress-circular>
      <div class="text-h6">{{ loadingMessage }}</div>
    </v-card>
  </v-overlay>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import api from '@/api/axios'

// Типы для resolution
interface ResolutionCandidate {
  id: number
  name: string
  unit: string
  similarity: number
  category?: string
  match_method?: string
}

interface ResolutionDecision {
  action?: 'create' | 'link' | 'ignore'
  internal_item_id?: number
  internal_item_name?: string
  conversion_factor?: number
  supplier_unit?: string
  internal_unit?: string
}

interface ResolutionItem {
  row_index: number
  raw_data: {
    name: string
    price?: number
    cost_per_unit?: number
    unit?: string
    [key: string]: any
  }
  status: 'new' | 'ambiguous' | 'auto_matched' | 'ignored'
  candidates: ResolutionCandidate[]
  decision?: ResolutionDecision
  suggested?: {
    action: string
    conversion_factor: number
  }
}

const props = defineProps<{
  modelValue: boolean
  targetType: 'operations' | 'materials'
}>()

const emit = defineEmits(['update:modelValue', 'imported'])

const dialog = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val)
})

// Step management
const step = ref(1)
const steps = [
  { title: 'Загрузка', value: 1 },
  { title: 'Маппинг', value: 2 },
  { title: 'Сопоставление', value: 3 },
  { title: 'Готово', value: 4 }
]

// State
const isProcessing = ref(false)
const sessionId = ref<string | null>(null)
const session = ref<any>(null)
const preview = ref<any>(null)
const resolutionData = ref<any>(null)
const importResult = ref<any>(null)

// Upload state
const uploadFile = ref<File | File[] | null>(null)
const pasteContent = ref('')

// Supplier/PriceList state
const suppliers = ref<any[]>([])
const priceLists = ref<any[]>([])
const selectedSupplierId = ref<number | null>(null)
const selectedPriceListId = ref<number | null>(null)

// Loading states for UX improvements
const isLoadingSuppliers = ref(false)
const isLoadingPriceLists = ref(false)
const isUploadingFile = ref(false)
const isChangingHeaderRow = ref(false)
const isMappingColumn = ref(false)
const isTransitioning = ref(false)
const loadingMessage = ref('')

// New entity dialogs
const showNewSupplierDialog = ref(false)
const newSupplierName = ref('')
const newSupplierCode = ref('')
const showNewPriceListDialog = ref(false)
const newPriceListName = ref('')

// Mapping state
const headerRowIndex = ref(0)
const columnMapping = ref<Record<number, string | null>>({})
const selectedSheetIndex = ref(0)
const mappingErrors = ref<Set<string>>(new Set())

// Resolution state
const resolutionFilter = ref('all')
const selectedRows = ref<number[]>([])
const bulkConversionFactor = ref<number | null>(null)
const isActivating = ref(false)

// Operations search for inline linking
const isSearchingOperations = ref(false)
const allOperationsCache = ref<any[]>([])
const operationSearchResults = ref<any[]>([])
let operationSearchTimeout: any = null

// Link dialog state
const linkDialog = ref(false)
const linkDialogItem = ref<any>(null)
const linkDialogSelectedId = ref<number | null>(null)
const linkDialogSupplierUnit = ref('')
const linkDialogInternalUnit = ref('')
const linkDialogConversionFactor = ref(1)
const searchResults = ref<any[]>([])
const isSearching = ref(false)
let searchTimeout: any = null

// Cancel confirmation dialog state
const showCancelConfirmDialog = ref(false)
const isCancelling = ref(false)

// Pending sessions state
const pendingSessions = ref<any[]>([])
const isLoadingPendingSessions = ref(false)

// Field options based on target type
const fieldOptions = computed(() => {
  if (props.targetType === 'operations') {
    return [
      { title: 'Наименование *', value: 'name' },
      { title: 'Цена за единицу *', value: 'cost_per_unit' },
      { title: 'Единица измерения', value: 'unit' },
      { title: 'Категория', value: 'category' },
      { title: 'Описание', value: 'description' },
      { title: 'Мин. толщина', value: 'min_thickness' },
      { title: 'Макс. толщина', value: 'max_thickness' },
      { title: 'Группа исключений', value: 'exclusion_group' },
      { title: 'Артикул/SKU', value: 'sku' },
      { title: 'Пропустить', value: 'ignore' }
    ]
  } else {
    return [
      { title: 'Наименование *', value: 'name' },
      { title: 'Цена *', value: 'price' },
      { title: 'Единица измерения', value: 'unit' },
      { title: 'Категория', value: 'category' },
      { title: 'Артикул', value: 'article' },
      { title: 'Описание', value: 'description' },
      { title: 'Толщина', value: 'thickness' },
      { title: 'Пропустить', value: 'ignore' }
    ]
  }
})

const headerRowOptions = computed(() => {
  if (!preview.value?.total_rows) return []
  return Array.from({ length: Math.min(10, preview.value.total_rows) }, (_, i) => ({
    title: `Строка ${i + 1}`,
    value: i
  }))
})

// Sheet options for multi-sheet files
const sheetOptions = computed(() => {
  if (!preview.value?.sheet_names) return []
  return preview.value.sheet_names.map((name: string, idx: number) => ({
    title: name || `Лист ${idx + 1}`,
    value: idx
  }))
})

// Rows to display in preview
const displayRows = computed(() => {
  if (!preview.value?.sample_rows) return []
  // Include header row at the top, then sample rows
  const headerRow = preview.value.headers || []
  return [headerRow, ...preview.value.sample_rows.slice(0, 14)]
})

// Get mapping options for a column (disable already selected)
const getMappingOptions = (columnIndex: number) => {
  const currentValue = columnMapping.value[columnIndex]
  
  return fieldOptions.value.map(option => ({
    ...option,
    disabled: option.value !== 'ignore' && 
              option.value !== null &&
              Object.values(columnMapping.value).includes(option.value) && 
              currentValue !== option.value
  }))
}

// Get background color for mapping select
const getColumnBgColor = (mapping: string | null): string => {
  switch (mapping) {
    case 'name':
      return 'primary-lighten-4'
    case 'price':
    case 'cost_per_unit':
      return 'success-lighten-4'
    case 'unit':
      return 'info-lighten-4'
    case 'category':
      return 'secondary-lighten-4'
    case 'ignore':
      return 'grey-lighten-3'
    default:
      return ''
  }
}

// Get cell class based on mapping
const getCellClass = (columnIndex: number): string => {
  const mapping = columnMapping.value[columnIndex]
  switch (mapping) {
    case 'name':
      return 'bg-primary-lighten-5'
    case 'price':
    case 'cost_per_unit':
      return 'bg-success-lighten-5'
    case 'unit':
      return 'bg-info-lighten-5'
    case 'category':
      return 'bg-secondary-lighten-5'
    case 'ignore':
      return 'text-grey bg-grey-lighten-4'
    default:
      return ''
  }
}

// Format cell value for display
const formatCell = (cell: any): string => {
  if (cell === null || cell === undefined) return ''
  const str = String(cell)
  return str.length > 50 ? str.substring(0, 47) + '...' : str
}

// Get mapping color for chips
const getMappingColor = (field: string): string => {
  switch (field) {
    case 'name': return 'primary'
    case 'price':
    case 'cost_per_unit': return 'success'
    case 'unit': return 'info'
    case 'category': return 'secondary'
    default: return 'grey'
  }
}

// Get field title by value
const getFieldTitle = (value: string): string => {
  const option = fieldOptions.value.find(o => o.value === value)
  return option?.title || value
}

// Get type color for auto-detection hints
const getTypeColor = (type: string): string => {
  switch (type) {
    case 'text': return 'primary'
    case 'numeric': return 'success'
    case 'mixed': return 'warning'
    default: return 'grey'
  }
}

// Get type name for display
const getTypeName = (type: string): string => {
  switch (type) {
    case 'text': return 'текст'
    case 'numeric': return 'число'
    case 'mixed': return 'смешанный'
    default: return type
  }
}

// Format session status for pending sessions list
const formatSessionStatus = (status: string): string => {
  switch (status) {
    case 'created': return 'Создана'
    case 'mapping_required': return 'Требуется маппинг'
    case 'resolution_required': return 'Требуется сопоставление'
    case 'execution_running': return 'Выполняется'
    default: return status
  }
}

// Format date for display
const formatDate = (dateString: string): string => {
  if (!dateString) return ''
  const date = new Date(dateString)
  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

// Update header row - need to reload preview from server
const updateHeaderRow = async () => {
  // When header row changes, we need to reload preview with new header_row_index
  isChangingHeaderRow.value = true
  loadingMessage.value = 'Перестроение таблицы…'
  
  await reloadPreview()
  
  // Minimum display time
  await new Promise(resolve => setTimeout(resolve, 300))
  
  isChangingHeaderRow.value = false
  loadingMessage.value = ''
}

// Reload preview with new sheet/header settings
const reloadPreview = async () => {
  if (!sessionId.value) return
  
  isProcessing.value = true
  try {
    // First update session header_row_index on server
    await api.patch(`/api/price-imports/${sessionId.value}`, {
      header_row_index: headerRowIndex.value,
      sheet_index: selectedSheetIndex.value
    })
    
    // Then get fresh preview
    const response = await api.get(`/api/price-imports/${sessionId.value}`)
    
    preview.value = response.data.preview
    // Re-run auto detect for new settings
    columnMapping.value = {}
    autoDetectMapping()
  } catch (error: any) {
    console.error('Failed to reload preview:', error)
  } finally {
    isProcessing.value = false
  }
}

const conversionPresets = [
  { title: '1 (без пересчета)', value: 1 },
  { title: '10', value: 10 },
  { title: '100', value: 100 },
  { title: '1000', value: 1000 },
  { title: '0.001', value: 0.001 }
]

const resolutionHeaders = [
  { title: 'Из прайса', key: 'raw_data.name', width: '35%' },
  { title: 'Цена', key: 'raw_data.price', width: '8%' },
  { title: 'Базовая операция', key: 'linked_operation', width: '40%' },
  { title: 'Действие', key: 'actions', width: '17%' }
]

const autoMatchedHeaders = [
  { title: 'Из прайса', key: 'raw_data.name', width: '35%' },
  { title: 'Цена', key: 'raw_data.price', width: '8%' },
  { title: 'Связана с операцией', key: 'matched_operation', width: '35%' },
  { title: 'Метод', key: 'match_method', width: '12%' },
  { title: 'Действие', key: 'auto_actions', width: '10%' }
]

// Computed
const statusColor = computed(() => {
  if (!session.value) return 'grey'
  switch (session.value.status) {
    case 'completed': return 'success'
    case 'parsing_failed':
    case 'execution_failed': return 'error'
    case 'resolution_required': return 'warning'
    default: return 'info'
  }
})

const statusText = computed(() => {
  if (!session.value) return 'Ожидание'
  switch (session.value.status) {
    case 'created': return 'Создано'
    case 'parsing_failed': return 'Ошибка парсинга'
    case 'mapping_required': return 'Нужен маппинг'
    case 'resolution_required': return 'Сопоставление'
    case 'execution_running': return 'Выполняется'
    case 'completed': return 'Завершено'
    case 'execution_failed': return 'Ошибка'
    default: return session.value.status
  }
})

const isMappingValid = computed(() => {
  const mapped = Object.values(columnMapping.value).filter(Boolean)
  const hasName = mapped.includes('name')
  const hasPrice = props.targetType === 'operations' 
    ? mapped.includes('cost_per_unit')
    : mapped.includes('price')
  // Supplier and price list are required for multi-supplier architecture (snapshot-prices)
  const hasSupplier = !!selectedSupplierId.value
  const hasPriceList = !!selectedPriceListId.value
  return hasName && hasPrice && hasSupplier && hasPriceList
})

// Detailed validation messages for UI
const validationStatus = computed(() => {
  const mapped = Object.values(columnMapping.value).filter(Boolean)
  const hasName = mapped.includes('name')
  const hasPrice = props.targetType === 'operations' 
    ? mapped.includes('cost_per_unit')
    : mapped.includes('price')
  const hasSupplier = !!selectedSupplierId.value
  const hasPriceList = !!selectedPriceListId.value
  
  const missing: string[] = []
  if (!hasName) missing.push('Наименование')
  if (!hasPrice) missing.push(props.targetType === 'operations' ? 'Цена за единицу' : 'Цена')
  if (!hasSupplier) missing.push('Поставщик')
  if (!hasPriceList) missing.push('Прайс-лист')
  
  return {
    isValid: missing.length === 0,
    missing
  }
})

const pendingItems = computed((): ResolutionItem[] => {
  if (!resolutionData.value?.resolution_queue) return []
  return resolutionData.value.resolution_queue.filter(
    (item: ResolutionItem) => item.status !== 'auto_matched' && item.status !== 'ignored'
  )
})

const hasPendingItems = computed(() => pendingItems.value.length > 0)

const ambiguousItems = computed((): ResolutionItem[] => 
  pendingItems.value.filter((item: ResolutionItem) => item.status === 'ambiguous')
)

const newItems = computed((): ResolutionItem[] => 
  pendingItems.value.filter((item: ResolutionItem) => item.status === 'new')
)

const autoMatchedItems = computed((): ResolutionItem[] => {
  if (!resolutionData.value?.resolution_queue) return []
  return resolutionData.value.resolution_queue.filter(
    (item: ResolutionItem) => item.status === 'auto_matched'
  )
})

const filteredResolutionItems = computed((): ResolutionItem[] => {
  if (!resolutionData.value?.resolution_queue) return []
  
  // Для вкладки auto_matched показываем только автоматически связанные
  if (resolutionFilter.value === 'auto_matched') {
    return autoMatchedItems.value
  }
  
  // Для остальных вкладок исключаем auto_matched и ignored
  let items: ResolutionItem[] = resolutionData.value.resolution_queue.filter(
    (item: ResolutionItem) => item.status !== 'auto_matched' && item.status !== 'ignored'
  )
  
  if (resolutionFilter.value === 'ambiguous') {
    items = items.filter((item: ResolutionItem) => item.status === 'ambiguous')
  } else if (resolutionFilter.value === 'new') {
    items = items.filter((item: ResolutionItem) => item.status === 'new')
  }
  
  // Ensure each item has a decision object
  items.forEach((item: ResolutionItem) => {
    if (!item.decision) {
      item.decision = {
        action: (item.suggested?.action as 'create' | 'link' | 'ignore') || 'create',
        conversion_factor: item.suggested?.conversion_factor || 1
      }
    }
  })
  
  return items
})

const hasFilteredItems = computed(() => filteredResolutionItems.value.length > 0)

const canExecute = computed(() => {
  // Check all non-auto-matched items have decisions
  const pending = pendingItems.value.filter((item: ResolutionItem) => item.status !== 'ignored')
  return pending.every((item: ResolutionItem) => {
    if (!item.decision || !item.decision.action) return false
    // If action is 'link', must have internal_item_id
    if (item.decision.action === 'link' && !item.decision.internal_item_id) return false
    return true
  })
})

// Method to select all filtered items (regardless of pagination)
const selectAllFilteredItems = () => {
  selectedRows.value = filteredResolutionItems.value.map((item: ResolutionItem) => item.row_index)
}

// Watch
watch(selectedSupplierId, async (newVal) => {
  if (newVal) {
    await loadPriceLists()
    await loadPendingSessions()
  } else {
    priceLists.value = []
    selectedPriceListId.value = null
    pendingSessions.value = []
  }
})

watch(headerRowIndex, () => {
  if (preview.value) {
    preview.value.headers = preview.value.rows?.[headerRowIndex.value] || []
  }
})

// Автоматически переключаем на вкладку auto_matched если нет незавершённых задач
watch([hasPendingItems, resolutionData], () => {
  if (resolutionData.value && !hasPendingItems.value && autoMatchedItems.value.length > 0) {
    resolutionFilter.value = 'auto_matched'
  }
})

// Toast notifications
const showToastMessage = ref(false)
const toastMessage = ref('')

const showToast = (message: string) => {
  toastMessage.value = message
  showToastMessage.value = true
}

const ensureSessionPriceListId = (incomingSession: any) => {
  if (!incomingSession) return incomingSession
  const resolvedPriceListId =
    incomingSession.price_list_id ??
    incomingSession.price_list_version?.price_list_id ??
    selectedPriceListId.value ??
    null

  return {
    ...incomingSession,
    price_list_id: resolvedPriceListId
  }
}

// Methods
const loadSuppliers = async () => {
  isLoadingSuppliers.value = true
  try {
    const response = await api.get('/api/suppliers')
    suppliers.value = response.data
  } catch (error) {
    console.error('Failed to load suppliers:', error)
  } finally {
    isLoadingSuppliers.value = false
  }
}

const loadPriceLists = async () => {
  if (!selectedSupplierId.value) {
    priceLists.value = []
    return
  }
  isLoadingPriceLists.value = true
  try {
    const response = await api.get(`/api/suppliers/${selectedSupplierId.value}/price-lists`, {
      params: { type: props.targetType }
    })
    priceLists.value = response.data
  } catch (error) {
    console.error('Failed to load price lists:', error)
  } finally {
    isLoadingPriceLists.value = false
  }
}

const createSupplier = async () => {
  try {
    const response = await api.post('/api/suppliers', {
      name: newSupplierName.value,
      code: newSupplierCode.value || null
    })
    suppliers.value.push(response.data)
    selectedSupplierId.value = response.data.id
    showNewSupplierDialog.value = false
    newSupplierName.value = ''
    newSupplierCode.value = ''
  } catch (error) {
    console.error('Failed to create supplier:', error)
  }
}

const createPriceList = async () => {
  if (!selectedSupplierId.value) return
  try {
    const response = await api.post(`/api/suppliers/${selectedSupplierId.value}/price-lists`, {
      name: newPriceListName.value,
      type: props.targetType
    })
    priceLists.value.push(response.data)
    selectedPriceListId.value = response.data.id
    showNewPriceListDialog.value = false
    newPriceListName.value = ''
  } catch (error) {
    console.error('Failed to create price list:', error)
  }
}

// Загрузка незавершённых сессий импорта
const loadPendingSessions = async () => {
  if (!selectedSupplierId.value) {
    pendingSessions.value = []
    return
  }
  
  isLoadingPendingSessions.value = true
  try {
    const response = await api.get('/api/price-imports', {
      params: {
        supplier_id: selectedSupplierId.value,
        target_type: props.targetType,
        status: 'pending' // Статусы: created, mapping_required, resolution_required
      }
    })
    // Фильтруем только незавершённые сессии для текущего поставщика
    pendingSessions.value = (response.data.data || []).filter((s: any) => 
      s.supplier_id === selectedSupplierId.value &&
      s.target_type === props.targetType &&
      !['completed', 'cancelled', 'execution_failed'].includes(s.status)
    )
  } catch (error) {
    console.error('Failed to load pending sessions:', error)
    pendingSessions.value = []
  } finally {
    isLoadingPendingSessions.value = false
  }
}

// Возобновление незавершённой сессии
const resumeSession = async (pendingSession: any) => {
  try {
    isProcessing.value = true
    
    // Загружаем данные сессии
    const response = await api.get(`/api/price-imports/${pendingSession.id}`)
    session.value = ensureSessionPriceListId(response.data.session)
    sessionId.value = pendingSession.id
    preview.value = response.data.preview
    
    // Восстанавливаем supplier и price list из сессии
    if (session.value?.supplier_id) {
      selectedSupplierId.value = session.value.supplier_id
    }
    // price_list_id получаем из version или из pendingSession
    const priceListId = session.value?.price_list_version?.price_list_id || 
                        pendingSession.price_list_version?.price_list_id
    if (priceListId) {
      selectedPriceListId.value = priceListId
    }
    
    // Определяем на каком шаге остановились
    if (session.value?.status === 'created' || session.value?.status === 'mapping_required') {
      step.value = 2 // Маппинг
      // Восстанавливаем маппинг если есть
      if (session.value?.column_mapping) {
        columnMapping.value = session.value.column_mapping
      }
    } else if (session.value?.status === 'resolution_required') {
      step.value = 3 // Сопоставление
      // Загружаем данные resolution
      const resolutionResponse = await api.get(`/api/price-imports/${pendingSession.id}/resolution`)
      resolutionData.value = resolutionResponse.data
    }
    
    showToast('Сессия восстановлена')
  } catch (error: any) {
    console.error('Failed to resume session:', error)
    alert('Не удалось восстановить сессию: ' + (error.response?.data?.message || error.message))
  } finally {
    isProcessing.value = false
  }
}

// Удаление (отмена) незавершённой сессии из списка
const deletePendingSession = async (pendingSession: any) => {
  const confirmMessage = `Отменить импорт "${pendingSession.original_filename || 'Без имени'}"?\n\n` +
    'При отмене будут удалены:\n' +
    '• Пустая версия прайс-листа (если была создана)\n' +
    '• Данные сессии импорта\n\n' +
    'Загруженный файл сохраняется для возможного повторного использования.\n\n' +
    'Это действие нельзя отменить.'
    
  if (!confirm(confirmMessage)) {
    return
  }
  
  try {
    const response = await api.post(`/api/price-imports/${pendingSession.id}/cancel`)
    pendingSessions.value = pendingSessions.value.filter(s => s.id !== pendingSession.id)
    
    const rollback = response.data?.rollback
    let toastMessage = 'Сессия отменена'
    if (rollback?.version_deleted) {
      toastMessage += ', версия прайса удалена'
    }
    showToast(toastMessage)
  } catch (error: any) {
    console.error('Failed to cancel session:', error)
    alert('Не удалось отменить сессию: ' + (error.response?.data?.message || error.message))
  }
}

const onFileSelected = async (value?: File | File[] | null) => {
  if (value !== undefined) {
    uploadFile.value = value
  }

  const selected = Array.isArray(uploadFile.value)
    ? uploadFile.value[0]
    : uploadFile.value

  if (!selected) return

  // Validate that supplier and price list are selected (required for snapshot-prices)
  if (!selectedSupplierId.value || !selectedPriceListId.value) {
    alert('Ошибка: Поставщик и прайс-лист обязательны для импорта')
    uploadFile.value = null
    return
  }
  
  isUploadingFile.value = true
  loadingMessage.value = 'Загрузка файла и подготовка данных…'
  isProcessing.value = true
  try {
    const formData = new FormData()
    formData.append('file', selected)
    formData.append('target_type', props.targetType)
    formData.append('supplier_id', selectedSupplierId.value.toString())
    formData.append('price_list_id', selectedPriceListId.value.toString())

    const response = await api.post('/api/price-imports/upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    
    sessionId.value = response.data.session.id
    session.value = ensureSessionPriceListId(response.data.session)
    preview.value = response.data.preview
    
    // Initialize column mapping
    columnMapping.value = {}
    autoDetectMapping()
    
    // Transition to step 2 with loader
    await transitionToStep(2)
  } catch (error: any) {
    const data = error.response?.data
    if (data?.error === 'duplicate_import' && data?.existing_session?.id) {
      const confirmReuse = confirm(
        'Этот файл уже импортировался ранее.\n\n' +
        'Создать новую сессию на основе ранее загруженного файла и заново выполнить сопоставление?'
      )

      if (confirmReuse) {
        try {
          const reuseResponse = await api.post('/api/price-imports/reuse', {
            existing_session_id: data.existing_session.id,
            target_type: props.targetType,
            supplier_id: selectedSupplierId.value,
            price_list_id: selectedPriceListId.value
          })

          sessionId.value = reuseResponse.data.session.id
          session.value = ensureSessionPriceListId(reuseResponse.data.session)
          preview.value = reuseResponse.data.preview
          columnMapping.value = {}
          autoDetectMapping()
          await transitionToStep(2)
          showToast('Создана новая сессия на основе ранее загруженного файла')
        } catch (reuseError: any) {
          alert(reuseError.response?.data?.message || 'Не удалось переиспользовать предыдущий импорт')
        }
      }
    } else {
      alert(data?.message || 'Ошибка загрузки файла')
    }
  } finally {
    isProcessing.value = false
    isUploadingFile.value = false
    loadingMessage.value = ''
    uploadFile.value = null
  }
}

const onPasteSubmit = async () => {
  if (!pasteContent.value) return

  // Validate that supplier and price list are selected (required for snapshot-prices)
  if (!selectedSupplierId.value || !selectedPriceListId.value) {
    alert('Ошибка: Поставщик и прайс-лист обязательны для импорта')
    return
  }
  
  isUploadingFile.value = true
  loadingMessage.value = 'Обработка данных из буфера…'
  isProcessing.value = true
  try {
    const response = await api.post('/api/price-imports/paste', {
      content: pasteContent.value,
      target_type: props.targetType,
      supplier_id: selectedSupplierId.value,
      price_list_id: selectedPriceListId.value
    })
    
    sessionId.value = response.data.session.id
    session.value = ensureSessionPriceListId(response.data.session)
    preview.value = response.data.preview
    
    // Initialize column mapping
    columnMapping.value = {}
    autoDetectMapping()
    
    // Transition to step 2 with loader
    await transitionToStep(2)
  } catch (error: any) {
    alert(error.response?.data?.message || 'Ошибка обработки данных')
  } finally {
    isProcessing.value = false
    isUploadingFile.value = false
    loadingMessage.value = ''
  }
}

const autoDetectMapping = () => {
  if (!preview.value?.headers) return
  
  const namePatterns = ['наименование', 'название', 'name', 'имя', 'товар', 'услуга', 'операция']
  const pricePatterns = ['цена', 'price', 'стоимость', 'cost', 'руб', 'тариф']
  const unitPatterns = ['единица', 'unit', 'ед.', 'ед', 'изм']
  const categoryPatterns = ['категория', 'category', 'группа', 'раздел']
  
  preview.value.headers.forEach((header: string, idx: number) => {
    if (!header) return
    const lower = header.toLowerCase()
    
    if (namePatterns.some(p => lower.includes(p))) {
      columnMapping.value[idx] = 'name'
    } else if (pricePatterns.some(p => lower.includes(p))) {
      columnMapping.value[idx] = props.targetType === 'operations' ? 'cost_per_unit' : 'price'
    } else if (unitPatterns.some(p => lower.includes(p))) {
      columnMapping.value[idx] = 'unit'
    } else if (categoryPatterns.some(p => lower.includes(p))) {
      columnMapping.value[idx] = 'category'
    }
  })
}

const submitMapping = async () => {
  if (!sessionId.value) return
  
  isProcessing.value = true
  try {
    const response = await api.post(`/api/price-imports/${sessionId.value}/mapping`, {
      mapping: columnMapping.value,
      header_row_index: headerRowIndex.value
    })
    
    resolutionData.value = response.data
    session.value = { ...session.value, status: 'resolution_required' }
    await transitionToStep(3)
  } catch (error: any) {
    alert(error.response?.data?.message || 'Ошибка анализа')
  } finally {
    isProcessing.value = false
  }
}

const formatPrice = (value: any): string => {
  if (value === null || value === undefined) return '—'
  return new Intl.NumberFormat('ru-RU', { 
    style: 'currency', 
    currency: 'RUB',
    maximumFractionDigits: 2 
  }).format(value)
}

const getStatusColor = (item: ResolutionItem): string => {
  if (item.decision?.action === 'ignore') return 'grey'
  if (item.decision?.action === 'link') {
    // Show error if link action without item selected
    return item.decision.internal_item_id ? 'success' : 'error'
  }
  if (item.decision?.action === 'create') return 'info'
  if (item.status === 'ambiguous') return 'warning'
  if (item.status === 'new') return 'info'
  return 'grey'
}

const getStatusText = (item: ResolutionItem): string => {
  if (item.decision?.action === 'ignore') return 'Пропуск'
  if (item.decision?.action === 'link') {
    // Show warning if link action without item selected
    return item.decision.internal_item_id ? 'Связан' : 'Требуется выбор элемента'
  }
  if (item.decision?.action === 'create') return 'Создать'
  if (item.status === 'ambiguous') return 'Неоднозначно'
  if (item.status === 'new') return 'Новый'
  return item.status
}

const setItemAction = (item: ResolutionItem, action: 'create' | 'link' | 'ignore') => {
  if (!item.decision) {
    item.decision = { conversion_factor: 1 }
  }
  
  // При переключении на link без выбранной операции - оставляем autocomplete активным
  if (action === 'link') {
    item.decision.action = 'link'
    // Если есть кандидат и он не занят - автоматически выбираем первого
    if (!item.decision.internal_item_id && item.candidates?.length) {
      const firstCandidate = item.candidates[0]
      if (firstCandidate && !isOperationAlreadyLinked(firstCandidate.id, item.row_index)) {
        item.decision.internal_item_id = firstCandidate.id
        item.decision.internal_item_name = firstCandidate.name
        item.decision.internal_unit = firstCandidate.unit
      }
    }
  } else if (action === 'create' || action === 'ignore') {
    // При создании или игнорировании - сбрасываем связь
    item.decision.action = action
    item.decision.internal_item_id = undefined
    item.decision.internal_item_name = undefined
    item.decision.internal_unit = undefined
  }
}

const updateItemDecision = (_item: ResolutionItem) => {
  // Already reactive, no need to do anything special
}

const getMatchMethodColor = (item: ResolutionItem): string => {
  if (!item.candidates || item.candidates.length === 0) return 'grey'
  const method = item.candidates[0]?.match_method || ''
  switch (method.toLowerCase()) {
    case 'alias': return 'purple'
    case 'exact': return 'success'
    case 'fuzzy': return 'info'
    default: return 'grey'
  }
}

const getMatchMethodText = (item: ResolutionItem): string => {
  if (!item.candidates || item.candidates.length === 0) return '—'
  const method = item.candidates[0]?.match_method || ''
  switch (method.toLowerCase()) {
    case 'alias': return 'Алиас'
    case 'exact': return 'Точное'
    case 'fuzzy': return 'Нечёткое'
    default: return method || '—'
  }
}

const getAutoMatchedOperation = (item: ResolutionItem): { name: string; unit?: string } | null => {
  if (item.candidates?.length) {
    return {
      name: item.candidates![0]!.name,
      unit: item.candidates![0]!.unit
    }
  }

  const fallbackName = (item as any).matched_item_name
  const fallbackUnit = (item as any).matched_item_unit
  if (fallbackName) {
    return { name: fallbackName, unit: fallbackUnit }
  }

  return null
}

const moveAutoMatchToManual = (item: ResolutionItem) => {
  const matched = getAutoMatchedOperation(item)
  const matchedId = (item as any).matched_item_id ?? item.candidates?.[0]?.id
  const matchedUnit = matched?.unit ?? item.candidates?.[0]?.unit

  item.status = 'ambiguous'
  item.decision = {
    action: 'link',
    internal_item_id: matchedId,
    internal_item_name: matched?.name,
    internal_unit: matchedUnit,
    conversion_factor: 1
  }

  if (!item.candidates || item.candidates.length === 0) {
    item.candidates = matchedId && matched
      ? [{
          id: matchedId,
          name: matched.name,
          unit: matchedUnit || '',
          similarity: 1,
          match_method: 'auto'
        } as ResolutionCandidate]
      : []
  }

  resolutionFilter.value = 'ambiguous'
  showToast('Автосопоставление переведено в ручной режим')
}

// Получить список всех связанных operation_id в текущем resolution (кроме указанной позиции)
const getLinkedOperationIds = (excludeRowIndex?: number): Set<number> => {
  const linkedIds = new Set<number>()
  if (!resolutionData.value?.resolution_queue) return linkedIds
  
  for (const item of resolutionData.value.resolution_queue) {
    if (excludeRowIndex !== undefined && item.row_index === excludeRowIndex) continue
    
    // Учитываем автоматически связанные
    if (item.status === 'auto_matched' && item.candidates?.length) {
      linkedIds.add(item.candidates[0].id)
    }
    // Учитываем выбранные вручную
    if (item.decision?.action === 'link' && item.decision?.internal_item_id) {
      linkedIds.add(item.decision.internal_item_id)
    }
  }
  return linkedIds
}

// Проверка: связана ли операция с другой позицией
const isOperationAlreadyLinked = (operationId: number, currentRowIndex: number): boolean => {
  const linkedIds = getLinkedOperationIds(currentRowIndex)
  return linkedIds.has(operationId)
}

// Получить доступные операции для позиции (кандидаты + результаты поиска + текущая выбранная)
const getAvailableOperationsForItem = (item: ResolutionItem): any[] => {
  const candidates = item.candidates || []
  const searchResults = operationSearchResults.value || []
  
  // Объединяем кандидатов и результаты поиска, убираем дубликаты
  const combined = [...candidates]
  const candidateIds = new Set(candidates.map(c => c.id))
  
  for (const sr of searchResults) {
    if (!candidateIds.has(sr.id)) {
      combined.push(sr)
    }
  }
  
  // Добавляем текущую выбранную операцию, если её нет в списке
  if (item.decision?.internal_item_id && !candidateIds.has(item.decision.internal_item_id)) {
    const selectedInSearch = searchResults.find(sr => sr.id === item.decision!.internal_item_id)
    if (selectedInSearch && !combined.some(c => c.id === selectedInSearch.id)) {
      combined.push(selectedInSearch)
    } else if (item.decision.internal_item_name) {
      // Используем сохранённое название операции
      combined.push({
        id: item.decision.internal_item_id,
        name: item.decision.internal_item_name,
        unit: item.decision.internal_unit || '',
        similarity: 0
      })
    }
  }
  
  return combined
}

// Поиск операций для inline автокомплита
const onOperationSearchInput = (query: string) => {
  if (operationSearchTimeout) clearTimeout(operationSearchTimeout)
  if (!query || query.length < 2) {
    operationSearchResults.value = []
    return
  }
  
  operationSearchTimeout = setTimeout(async () => {
    isSearchingOperations.value = true
    try {
      const endpoint = props.targetType === 'operations' 
        ? '/api/operations/search'
        : '/api/materials/search'
      const response = await api.get(endpoint, { params: { q: query, limit: 20 } })
      operationSearchResults.value = response.data
    } catch (error) {
      console.error('Operation search failed:', error)
    } finally {
      isSearchingOperations.value = false
    }
  }, 300)
}

// Выбор операции для позиции (из inline autocomplete)
const selectOperationForItem = (item: ResolutionItem, operationId: number | null) => {
  if (!item.decision) {
    item.decision = { conversion_factor: 1 }
  }
  
  if (operationId === null) {
    // Снятие связи
    item.decision.action = undefined
    item.decision.internal_item_id = undefined
    item.decision.internal_item_name = undefined
    item.decision.internal_unit = undefined
    return
  }
  
  // Проверка уникальности
  if (isOperationAlreadyLinked(operationId, item.row_index)) {
    alert('Эта операция уже связана с другой позицией в прайсе')
    // Сбрасываем выбор
    item.decision.internal_item_id = undefined
    return
  }
  
  // Находим выбранную операцию
  const availableOps = getAvailableOperationsForItem(item)
  const selectedOp = availableOps.find(op => op.id === operationId)
  
  item.decision.action = 'link'
  item.decision.internal_item_id = operationId
  item.decision.internal_item_name = selectedOp?.name
  item.decision.internal_unit = selectedOp?.unit
}

const linkToCandidate = (item: ResolutionItem, candidate: ResolutionCandidate) => {
  if (isOperationAlreadyLinked(candidate.id, item.row_index)) {
    alert('Эта операция уже связана с другой позицией в прайсе')
    return
  }
  
  if (!item.decision) {
    item.decision = { conversion_factor: 1 }
  }
  item.decision.action = 'link'
  item.decision.internal_item_id = candidate.id
  item.decision.internal_item_name = candidate.name
  item.decision.internal_unit = candidate.unit
}

const openLinkDialog = (item: ResolutionItem) => {
  linkDialogItem.value = item
  linkDialogSelectedId.value = item.decision?.internal_item_id || null
  linkDialogSupplierUnit.value = item.raw_data.unit || ''
  linkDialogInternalUnit.value = item.decision?.internal_unit || ''
  linkDialogConversionFactor.value = item.decision?.conversion_factor || 1
  searchResults.value = item.candidates || []
  linkDialog.value = true
}

const onSearchInput = (query: string) => {
  if (searchTimeout) clearTimeout(searchTimeout)
  if (!query || query.length < 2) return
  
  searchTimeout = setTimeout(async () => {
    isSearching.value = true
    try {
      const endpoint = props.targetType === 'operations' 
        ? '/api/operations/search'
        : '/api/materials/search'
      const response = await api.get(endpoint, { params: { q: query } })
      searchResults.value = response.data
    } catch (error) {
      console.error('Search failed:', error)
    } finally {
      isSearching.value = false
    }
  }, 300)
}

const confirmLink = () => {
  if (!linkDialogItem.value || !linkDialogSelectedId.value) return
  
  if (!linkDialogItem.value.decision) {
    linkDialogItem.value.decision = {}
  }
  
  linkDialogItem.value.decision.action = 'link'
  linkDialogItem.value.decision.internal_item_id = linkDialogSelectedId.value
  linkDialogItem.value.decision.supplier_unit = linkDialogSupplierUnit.value
  linkDialogItem.value.decision.internal_unit = linkDialogInternalUnit.value
  linkDialogItem.value.decision.conversion_factor = linkDialogConversionFactor.value
  
  linkDialog.value = false
}

const bulkAcceptAsNew = async () => {
  if (!sessionId.value || selectedRows.value.length === 0) return
  
  isProcessing.value = true
  try {
    const response = await api.post(`/api/price-imports/${sessionId.value}/bulk-action`, {
      action: 'accept_as_new',
      row_indexes: selectedRows.value,
      params: { conversion_factor: bulkConversionFactor.value || 1 }
    })
    
    resolutionData.value.stats = response.data.stats
    
    // Update local items
    selectedRows.value.forEach(rowIndex => {
      const item = resolutionData.value.resolution_queue.find((i: any) => i.row_index === rowIndex)
      if (item) {
        item.decision = { action: 'create', conversion_factor: bulkConversionFactor.value || 1 }
      }
    })
    
    selectedRows.value = []
  } catch (error: any) {
    alert(error.response?.data?.message || 'Ошибка')
  } finally {
    isProcessing.value = false
  }
}

const bulkIgnore = async () => {
  if (!sessionId.value || selectedRows.value.length === 0) return
  
  isProcessing.value = true
  try {
    const response = await api.post(`/api/price-imports/${sessionId.value}/bulk-action`, {
      action: 'ignore',
      row_indexes: selectedRows.value
    })
    
    resolutionData.value.stats = response.data.stats
    
    // Update local items
    selectedRows.value.forEach(rowIndex => {
      const item = resolutionData.value.resolution_queue.find((i: any) => i.row_index === rowIndex)
      if (item) {
        item.decision = { action: 'ignore' }
        item.status = 'ignored'
      }
    })
    
    selectedRows.value = []
  } catch (error: any) {
    alert(error.response?.data?.message || 'Ошибка')
  } finally {
    isProcessing.value = false
  }
}

const bulkSetConversion = async () => {
  if (!sessionId.value || selectedRows.value.length === 0 || !bulkConversionFactor.value) return
  
  isProcessing.value = true
  try {
    await api.post(`/api/price-imports/${sessionId.value}/bulk-action`, {
      action: 'set_conversion',
      row_indexes: selectedRows.value,
      params: { conversion_factor: bulkConversionFactor.value }
    })
    
    // Update local items
    selectedRows.value.forEach(rowIndex => {
      const item = resolutionData.value.resolution_queue.find((i: any) => i.row_index === rowIndex)
      if (item && item.decision) {
        item.decision.conversion_factor = bulkConversionFactor.value
      }
    })
  } catch (error: any) {
    alert(error.response?.data?.message || 'Ошибка')
  } finally {
    isProcessing.value = false
  }
}

// ============ Bulk Link (for operations) ============

const getItemNameByRowIndex = (rowIndex: number): string => {
  if (!resolutionData.value?.resolution_queue) return ''
  const item = resolutionData.value.resolution_queue.find((i: any) => i.row_index === rowIndex)
  const name = item?.raw_data?.name || ''
  return name.length > 25 ? name.substring(0, 22) + '...' : name
}

const executeImport = async () => {
  if (!sessionId.value) return
  
  // Collect all decisions
  const decisions = resolutionData.value.resolution_queue
    .filter((item: any) => item.status !== 'auto_matched' && item.decision)
    .map((item: any) => ({
      row_index: item.row_index,
      action: item.decision.action,
      internal_item_id: item.decision.internal_item_id,
      conversion_factor: item.decision.conversion_factor || 1,
      supplier_unit: item.decision.supplier_unit,
      internal_unit: item.decision.internal_unit
    }))
  
  isProcessing.value = true
  try {
    const response = await api.post(`/api/price-imports/${sessionId.value}/execute`, {
      decisions
    })
    
    importResult.value = response.data.result
    const refreshed = await api.get(`/api/price-imports/${sessionId.value}`)
    session.value = ensureSessionPriceListId({
      ...refreshed.data.session,
      status: 'completed'
    })
    console.log('Import completed:', { importResult: importResult.value, session: session.value })
    step.value = 4
  } catch (error: any) {
    alert(error.response?.data?.message || 'Ошибка выполнения импорта')
  } finally {
    isProcessing.value = false
  }
}

// Установить "Создать" для всех позиций без решения
const setCreateForRemaining = () => {
  const itemsToUpdate = pendingItems.value.filter((item: ResolutionItem) => {
    if (!item.decision || !item.decision.action) return true
    if (item.decision.action === 'link' && !item.decision.internal_item_id) return true
    return false
  })
  
  if (itemsToUpdate.length === 0) {
    showToast('Все позиции уже имеют решение')
    return
  }
  
  itemsToUpdate.forEach((item: ResolutionItem) => {
    if (!item.decision) {
      item.decision = { conversion_factor: 1 }
    }
    item.decision.action = 'create'
    item.decision.internal_item_id = undefined
    item.decision.internal_item_name = undefined
    item.decision.internal_unit = undefined
  })
  
  showToast(`Установлено "Создать новую" для ${itemsToUpdate.length} позиций`)
}

const closeDialog = () => {
  // Если импорт в процессе (этап 2-3 и есть сессия), показать диалог подтверждения
  if (sessionId.value && step.value >= 2 && step.value < 4) {
    showCancelConfirmDialog.value = true
    return
  }
  
  forceCloseDialog()
}

const forceCloseDialog = () => {
  dialog.value = false
  resetState()
}

const cancelImportAndClose = async () => {
  if (!sessionId.value) {
    forceCloseDialog()
    return
  }

  isCancelling.value = true
  try {
    await api.post(`/api/price-imports/${sessionId.value}/cancel`)
    showToast('Импорт отменён')
  } catch (error: any) {
    console.error('Failed to cancel import:', error)
    // Всё равно закрываем диалог
  } finally {
    isCancelling.value = false
    showCancelConfirmDialog.value = false
    forceCloseDialog()
  }
}

const closeAndRefresh = () => {
  emit('imported')
  forceCloseDialog() // Не показывать подтверждение после успешного импорта
}

const resetState = () => {
  step.value = 1
  sessionId.value = null
  session.value = null
  preview.value = null
  resolutionData.value = null
  importResult.value = null
  uploadFile.value = null
  pasteContent.value = ''
  columnMapping.value = {}
  selectedRows.value = []
  selectedSheetIndex.value = 0
  headerRowIndex.value = 0
  isActivating.value = false
  showCancelConfirmDialog.value = false
  isCancelling.value = false
}

const activateImportedVersion = async () => {
  const priceListId =
    session.value?.price_list_id ??
    session.value?.price_list_version?.price_list_id ??
    selectedPriceListId.value
  const versionId = session.value?.price_list_version_id

  if (!priceListId || !versionId) {
    alert('Не удалось определить версию для активации')
    return
  }

  if (!confirm('Активировать импортированную версию? Текущая активная версия будет переведена в архив.')) {
    return
  }

  isActivating.value = true
  try {
    await api.post(
      `/api/price-lists/${priceListId}/versions/${versionId}/activate`
    )
    alert('Версия успешно активирована!')
    closeAndRefresh()
  } catch (error: any) {
    alert('Ошибка активации версии: ' + (error.response?.data?.message || error.message))
  } finally {
    isActivating.value = false
  }
}

const viewVersionDetails = () => {
  const priceListId =
    session.value?.price_list_id ??
    session.value?.price_list_version?.price_list_id ??
    selectedPriceListId.value
  const versionId = session.value?.price_list_version_id

  if (!priceListId || !versionId) {
    alert('Не удалось определить версию для просмотра')
    return
  }

  // Use supplier_id from session, fallback to selectedSupplierId
  const supplierId = session.value.supplier_id || selectedSupplierId.value
  if (!supplierId) {
    alert('Не удалось определить поставщика')
    return
  }

  const url = `/suppliers/${supplierId}/price-lists/${priceListId}/versions/${versionId}`
  window.open(url, '_blank')
}

// Auto-generate price list name
const generatePriceListName = (): string => {
  const now = new Date()
  const months = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 
                  'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь']
  const month = months[now.getMonth()]
  const year = now.getFullYear()
  
  // Count existing price lists for this month
  const currentMonthLists = priceLists.value.filter(pl => {
    if (!pl.name) return false
    return pl.name.startsWith(`${month}_${year}_`)
  })
  
  const n = currentMonthLists.length + 1
  return `${month}_${year}_${n}`
}

// Transition to step with minimum delay (anti-flicker)
const transitionToStep = async (targetStep: number) => {
  isTransitioning.value = true
  
  const messages: Record<number, string> = {
    2: 'Формирование предпросмотра и структуры колонок…',
    3: 'Анализ данных и подготовка сопоставления…',
    4: 'Завершение импорта…'
  }
  
  loadingMessage.value = messages[targetStep] || 'Обработка…'
  
  // Minimum 300ms display time (anti-flicker)
  await new Promise(resolve => setTimeout(resolve, 300))
  
  step.value = targetStep
  isTransitioning.value = false
  loadingMessage.value = ''
}

// Handle mapping change with duplicate prevention
const handleMappingChange = async (columnIndex: number, newValue: string | null) => {
  const currentValue = columnMapping.value[columnIndex]
  
  // Check for duplicates (except 'ignore' which can be used multiple times)
  if (newValue && newValue !== 'ignore' && newValue !== currentValue) {
    const existingEntry = Object.entries(columnMapping.value).find(
      ([idx, val]) => val === newValue && parseInt(idx) !== columnIndex
    )
    
    if (existingEntry) {
      // Auto-remove from previous column
      const existingColIndex = parseInt(existingEntry[0])
      columnMapping.value[existingColIndex] = null
      showToast(`Поле "${getFieldTitle(newValue)}" было переназначено`)
    }
  }
  
  // Set new mapping
  columnMapping.value[columnIndex] = newValue
  
  // Show brief loading indicator
  isMappingColumn.value = true
  await new Promise(resolve => setTimeout(resolve, 200))
  isMappingColumn.value = false
}

// Check if field is required
const isRequiredField = (fieldValue: string | null): boolean => {
  if (!fieldValue) return false
  return fieldValue === 'name' || 
         (props.targetType === 'operations' && fieldValue === 'cost_per_unit') ||
         (props.targetType === 'materials' && fieldValue === 'price')
}

// Required fields validation
const requiredFieldsMapped = computed(() => {
  const mappedValues = Object.values(columnMapping.value).filter(v => v && v !== 'ignore')
  const hasName = mappedValues.includes('name')
  const hasPrice = props.targetType === 'operations' 
    ? mappedValues.includes('cost_per_unit')
    : mappedValues.includes('price')
  
  return {
    hasName,
    hasPrice,
    isValid: hasName && hasPrice
  }
})

// Can proceed to mapping step
const canProceedToMapping = computed(() => {
  return !!selectedSupplierId.value && 
         !!selectedPriceListId.value && 
         !!preview.value &&
         !isUploadingFile.value &&
         !isTransitioning.value
})

// Can proceed to resolution step
const canProceedToResolution = computed(() => {
  return requiredFieldsMapped.value.isValid &&
         !isChangingHeaderRow.value &&
         !isMappingColumn.value &&
         !isTransitioning.value
})

// Watch for auto-filling price list name when dialog opens
watch(showNewPriceListDialog, (newVal) => {
  if (newVal) {
    newPriceListName.value = generatePriceListName()
  }
})

// Watch for selected supplier change to load price lists
watch(selectedSupplierId, () => {
  loadPriceLists()
})

// Init
loadSuppliers()
</script>

<style scoped>
.price-preview-table-container {
  max-height: 350px;
  overflow: auto;
}

.price-preview-table {
  font-size: 0.85rem;
}

.price-preview-table th,
.price-preview-table td {
  white-space: nowrap;
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
}

.row-number-col {
  width: 50px;
  min-width: 50px;
  max-width: 50px;
}

.mapping-cell {
  min-width: 140px;
}

.mapping-select {
  min-width: 130px;
}

.mapping-row th {
  background-color: #f5f5f5;
  position: sticky;
  top: 0;
  z-index: 4;
  border-bottom: 2px solid #1976d2;
}

.header-row th {
  background-color: #fafafa;
  position: sticky;
  top: 48px;
  z-index: 3;
}

.header-source-row {
  background-color: rgba(25, 118, 210, 0.08);
}

.bg-primary-lighten-5 {
  background-color: rgb(var(--v-theme-primary), 0.08) !important;
}

.bg-success-lighten-5 {
  background-color: rgb(var(--v-theme-success), 0.08) !important;
}

.bg-info-lighten-5 {
  background-color: rgb(var(--v-theme-info), 0.08) !important;
}

.bg-secondary-lighten-5 {
  background-color: rgb(var(--v-theme-secondary), 0.08) !important;
}

.bg-grey-lighten-4 {
  background-color: rgb(var(--v-theme-surface-variant), 0.5) !important;
}

/* Компактные стили для таблицы сопоставления */
.resolution-table :deep(.v-data-table__tr) {
  height: 48px !important;
}

.resolution-table :deep(.v-data-table__td) {
  padding-top: 4px !important;
  padding-bottom: 4px !important;
}

.resolution-table :deep(.v-field__input) {
  min-height: 32px !important;
  padding-top: 4px !important;
  padding-bottom: 4px !important;
}

.resolution-table :deep(.v-autocomplete .v-field) {
  font-size: 0.8125rem;
}

.operation-autocomplete {
  width: 100%;
  min-width: 180px;
  max-width: 280px;
}
</style>
