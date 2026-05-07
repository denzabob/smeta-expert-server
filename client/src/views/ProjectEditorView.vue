<template>
  <div class="workspace-root">
    <!-- Workspace Header -->
    <WorkspaceHeader
      :title="`Проект #${project.number}`"
      :positions-count="positions.length"
      :total-sum="projectTotalSum"
      :warnings-count="healthIssues.length"
      :latest-revision="latestRevision ? { number: latestRevision.number, status: latestRevision.status } : null"
      :loading="!loadingReady"
    >
      <template #actions>
        <div class="toolbar-actions">
          <v-btn
            size="small"
            color="primary"
            prepend-icon="mdi-file-document-multiple-outline"
            :disabled="estimateHardInvalid"
            :title="estimateHardInvalid ? 'Смета содержит ошибки и не может быть использована' : 'Документы проекта'"
            @click="activeModule = 'documents'"
          >
            Документы
          </v-btn>
          <v-btn
            size="small"
            color="secondary"
            prepend-icon="mdi-refresh"
            :loading="refreshing"
            :disabled="refreshing"
            @click="refreshAll"
          >
            Обновить
          </v-btn>
          <v-btn
            size="small"
            prepend-icon="mdi-cog"
            :disabled="isProjectReadOnly"
            :title="readOnlyActionTitle || 'Настройки'"
            @click="activeModule = 'settings'"
          >
            Настройки
          </v-btn>
        </div>
      </template>
    </WorkspaceHeader>

    <!-- Health / Issues Bar -->
    <ProjectHealthBar
      :issues="healthIssues"
      @navigate="handleHealthNavigate"
    />

    <v-alert
      v-if="estimateHardInvalid"
      type="error"
      variant="tonal"
      density="compact"
      class="ma-4 mb-0"
    >
      Смета содержит ошибки и не может быть использована
    </v-alert>

    <v-alert
      v-if="isProjectReadOnly"
      type="info"
      variant="tonal"
      density="comfortable"
      class="ma-4 mb-0"
      icon="mdi-lock-outline"
    >
      <div class="project-read-only-alert">
        <div>{{ projectReadOnlyMessage }}</div>
        <v-btn
          size="small"
          color="primary"
          variant="tonal"
          prepend-icon="mdi-credit-card-outline"
          @click="router.push('/settings/billing')"
        >
          Перейти к тарифам
        </v-btn>
      </div>
    </v-alert>

    <div class="workspace-body">
      <!-- Sidebar (desktop only — hidden on mobile via CSS) -->
      <WorkspaceSidebar
        class="workspace-sidebar-desktop"
        :modules="sidebarModules"
        :active-module="activeModule"
        :loading="!loadingReady"
        @update:active-module="activeModule = $event"
      />

      <!-- Mobile module tab bar (replaces sidebar on compact screens) -->
      <div v-if="compactLayout" class="workspace-mobile-tabs">
        <div class="workspace-mobile-tab-scroll">
          <button
            v-for="mod in sidebarModules"
            :key="mod.key"
            class="wmt-btn"
            :class="{ 'wmt-btn--active': activeModule === mod.key }"
            @click="activeModule = mod.key"
          >
            <v-icon size="18">{{ mod.icon }}</v-icon>
            <span class="wmt-label">{{ mod.label }}</span>
            <span v-if="mod.warnings && mod.warnings > 0" class="wmt-badge wmt-badge--warn">{{ mod.warnings }}</span>
            <span v-else-if="mod.count != null && mod.count > 0" class="wmt-badge">{{ mod.count > 99 ? '99+' : mod.count }}</span>
          </button>
        </div>
      </div>

      <!-- Main module area -->
      <div class="workspace-module-area">

    <!-- Loading overlay -->
    <Transition name="loading-fade">
      <div v-if="!loadingReady" class="project-loading-overlay">
        <div class="project-loading-card">
          <div class="project-loading-icon">
            <v-icon size="48" color="primary" class="project-loading-pulse">mdi-briefcase-outline</v-icon>
          </div>
          <p class="project-loading-message">{{ loadingMessage }}</p>
          <div class="project-loading-dots">
            <span class="dot" /><span class="dot" /><span class="dot" />
          </div>
          <div class="project-loading-skeletons">
            <div class="skeleton-row"><div class="skeleton-block" /><div class="skeleton-block skeleton-block--short" /></div>
            <div class="skeleton-row"><div class="skeleton-block skeleton-block--wide" /><div class="skeleton-block" /></div>
            <div class="skeleton-row"><div class="skeleton-block skeleton-block--short" /><div class="skeleton-block skeleton-block--wide" /></div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Компонент настроек проекта -->
    <ProjectSettingsModal
      :model-value="settingsDrawer"
      :project="project"
      :regions="regions"
      :materials="materials"
      @update:model-value="settingsDrawer = $event"
      @saved="handleSettingsSaved"
      @cancelled="handleSettingsDrawerClosed"
      @error="handleSettingsError"
    />

    <!-- Диалог импорта позиций -->
    <ImportPositionsDialog
      v-model="importDialog"
      :project-id="project.id"
      @imported="handlePositionsImported"
    />

    <!-- ======================== MODULE: POSITIONS ======================== -->
    <div v-show="activeModule === 'positions'" class="module-content">
      <section class="dense-module-shell dense-module-shell--positions">
      <!-- Positions Summary -->
      <div class="module-summary">
        <v-chip size="small" variant="tonal" color="primary">Всего: {{ positions.length }}</v-chip>
        <v-chip size="small" variant="tonal">Панели: {{ positions.filter(p => p.kind === 'panel').length }}</v-chip>
        <v-chip size="small" variant="tonal" color="deep-purple">Фасады: {{ positions.filter(p => p.kind === 'facade').length }}</v-chip>
        <v-chip v-if="positionsWithoutMaterial > 0" size="small" variant="tonal" color="warning">Без материала: {{ positionsWithoutMaterial }}</v-chip>
        <v-chip v-if="positionsWithPriceIssues > 0" size="small" variant="tonal" color="error">Без цен: {{ positionsWithPriceIssues }}</v-chip>
      </div>

        <!-- Sticky sentinel: becomes unobsered when toolbar sticks -->
        <div ref="toolbarSentinelRef" class="toolbar-sentinel" />

        <!-- Unified Positions Toolbar -->
        <div class="positions-unified-toolbar" ref="positionsToolbarRef">
          <!-- Default mode: no rows selected -->
          <template v-if="selectedPositionIds.length === 0">
            <div class="toolbar-zone toolbar-zone--left">
              <v-btn
                size="small"
                prepend-icon="mdi-plus"
                :loading="positionDialogOpening"
                :disabled="isProjectReadOnly || positionDialogOpening"
                :title="readOnlyActionTitle || 'Добавить позицию'"
                @click="openPositionDialog"
              >
                Добавить позицию
              </v-btn>
              <v-btn size="small" prepend-icon="mdi-file-import" variant="outlined" class="excel-import-btn" :disabled="isProjectReadOnly" :title="readOnlyActionTitle || 'Импорт из Excel'" @click="importDialog = true">Импорт из Excel</v-btn>
            </div>
            <div class="toolbar-zone toolbar-zone--middle">
              <v-btn size="x-small" variant="tonal" prepend-icon="mdi-checkbox-multiple-outline" @click="selectPositionsByKind('panel')" :disabled="positions.filter(p => p.kind === 'panel').length === 0">Выбрать панели</v-btn>
              <v-btn size="x-small" variant="tonal" color="deep-purple" prepend-icon="mdi-checkbox-multiple-outline" @click="selectPositionsByKind('facade')" :disabled="positions.filter(p => p.kind === 'facade').length === 0">Выбрать фасады</v-btn>
            </div>
            <div class="toolbar-zone toolbar-zone--right">
              <v-btn-toggle
                v-model="columnPreset"
                mandatory
                density="compact"
                color="primary"
                variant="outlined"
                class="position-toolbar-toggle"
              >
                <v-btn v-for="preset in columnPresets" :key="preset.value" :value="preset.value" size="small">
                  <v-icon start size="small">{{ preset.icon }}</v-icon>
                  {{ preset.label }}
                </v-btn>
              </v-btn-toggle>
            </div>
          </template>

          <!-- Bulk mode: rows selected -->
          <template v-else>
            <div class="toolbar-zone toolbar-zone--left">
              <v-chip size="small" color="primary" variant="tonal">
                Выбрано: {{ selectedPositionIds.length }}
              </v-chip>
              <v-btn size="small" variant="text" @click="selectAllVisiblePositions">Выбрать все</v-btn>
              <v-btn size="small" variant="text" @click="clearSelection">Снять выбор</v-btn>
            </div>
            <div class="toolbar-zone toolbar-zone--right bulk-actions-row">
              <v-select
                v-model="bulkAction"
                :items="bulkActionItems"
                label="Действия"
                density="compact"
                variant="outlined"
                hide-details
              />
              <v-btn-toggle
                v-model="bulkApplyMode"
                density="compact"
                mandatory
                color="primary"
                variant="outlined"
              >
                <v-btn value="strict" size="small">Строго</v-btn>
                <v-btn value="partial" size="small">Частично</v-btn>
              </v-btn-toggle>
              <template v-if="bulkAction === 'replace_material'">
                <v-autocomplete
                  v-model="bulkMaterialId"
                  :items="materialsPlate"
                  item-title="name"
                  item-value="id"
                  label="Материал"
                  density="compact"
                  variant="outlined"
                  hide-details
                />
              </template>
              <template v-else-if="bulkAction === 'replace_edge'">
                <v-autocomplete
                  v-model="bulkEdgeMaterialId"
                  :items="materialsEdge"
                  item-title="name"
                  item-value="id"
                  label="Кромка"
                  density="compact"
                  variant="outlined"
                  hide-details
                />
              </template>
              <template v-else-if="bulkAction === 'set_edge_scheme'">
                <v-select
                  v-model="bulkEdgeScheme"
                  :items="edgeSchemeOptions"
                  item-title="label"
                  item-value="value"
                  label="Обработка торцов"
                  density="compact"
                  variant="outlined"
                  hide-details
                />
              </template>
              <template v-else-if="bulkAction === 'clear_field'">
                <v-select
                  v-model="bulkClearField"
                  :items="bulkClearFieldItems"
                  label="Поле"
                  density="compact"
                  variant="outlined"
                  hide-details
                />
              </template>
              <template v-else-if="bulkAction === 'replace_facade_material'">
                <v-autocomplete
                  v-model="bulkFacadeSpecificationId"
                  :items="facadeSpecifications"
                  item-title="name"
                  item-value="id"
                  label="Спецификация фасада"
                  density="compact"
                  variant="outlined"
                  hide-details
                  :loading="loadingFacades"
                  @update:search="onFacadeSearch"
                  no-filter
                  >
                  <template #item="{ props: itemProps, item }">
                    <v-list-item v-bind="itemProps">
                      <v-list-item-subtitle>
                        {{ item.raw.thickness_mm }}мм | {{ item.raw.finish_name || '—' }}
                        <span v-if="item.raw.price_per_m2" class="text-green"> | {{ formatNumber(item.raw.price_per_m2, 2) }} ₽/м²</span>
                      </v-list-item-subtitle>
                    </v-list-item>
                  </template>
                </v-autocomplete>
              </template>
              <v-chip
                v-if="bulkAction"
                size="small"
                :color="bulkSkippedCount > 0 ? (bulkApplyMode === 'strict' ? 'warning' : 'info') : 'success'"
                variant="tonal"
              >
                Доступно: {{ bulkApplicableCount }} / {{ selectedPositionIds.length }}
              </v-chip>
              <v-btn
                color="primary"
                size="small"
                :disabled="isProjectReadOnly || !bulkActionReady || bulkApplicableCount === 0 || (bulkApplyMode === 'strict' && bulkSkippedCount > 0)"
                @click="confirmBulkDialog = true"
              >
                Применить
              </v-btn>
            </div>
          </template>

          <!-- Bulk mode: skip info -->
          <div v-if="selectedPositionIds.length > 0 && bulkAction && bulkSkippedCount > 0" class="toolbar-bulk-hint text-caption text-medium-emphasis">
            {{ bulkApplyMode === 'strict'
              ? `В строгом режиме операция заблокирована: несовместимых позиций ${bulkSkippedCount}.`
              : `В частичном режиме несовместимые позиции будут пропущены: ${bulkSkippedCount}.` }}
          </div>
        </div>
        <div
          class="positions-table-wrap"
          :class="{ 'density-comfortable': tableDensity === 'comfortable' }"
          ref="positionsTableWrap"
        >
          <!-- Skeleton только при первой загрузке (positions пустой) -->
          <v-skeleton-loader v-if="loadingStates.positions && positions.length === 0" type="table" />
          <!-- Единая таблица для всех данных -->
          <v-data-table
            v-else
            :items="positions"
            :headers="currentPositionHeaders"
            item-value="id"
            show-select
            :model-value="selectedPositionsRaw"
            @update:model-value="onPositionSelectionChange"
            :density="tableDensity"
            class="positions-table positions-table-hover"
            :loading="loadingStates.positions"

            :items-per-page="-1"
            :hide-default-footer="true"
          >
            <template v-slot:item="{ item, index }">
              <tr 
                class="position-row"
                :data-row-id="item.id"
                :class="{ 'row-selected': selectedPositionsRaw.includes(item.id!), 'row-hovered': hoveredPositionId === item.id, 'row-highlighted': highlightedPositionId === item.id }"
                @mouseenter="hoveredPositionId = item.id"
                @mouseleave="hoveredPositionId = null"
                @click="handlePositionRowClick($event, { item })"
              >
                <td>
                  <v-checkbox-btn
                    :model-value="selectedPositionsRaw.includes(item.id!)"
                    @click.stop.prevent="handleCheckboxClick($event, item.id!, index)"
                  />
                </td>
                <td class="cell-with-actions">
                  <div class="cell-name-column">
                    <div class="cell-ellipsis cell-name">{{ item.custom_name || '—' }}</div>
                    <RowHoverActions
                      :row-id="item.id!"
                      :quick-actions="getQuickActions(item)"
                      :menu-actions="getMenuActions(item)"
                      :visible="isActionsVisible(item.id)"
                      :loading="processingPositionId === item.id"
                      dense
                      @action="handleRowAction"
                    />
                  </div>
                </td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'detail_type')">
                  <div class="cell-ellipsis">{{ getDetailTypeName(item.detail_type_id) }}</div>
                </td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'kind')">
                  <v-chip :color="item.kind === 'facade' ? 'deep-purple' : 'blue-grey'" size="x-small" variant="tonal">
                    {{ item.kind === 'facade' ? 'Фасад' : 'Панель' }}
                  </v-chip>
                </td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'material_short')">
                  <div
                    class="cell-ellipsis"
                    :class="{ 'text-error': hasMissingMainMaterialPrice(item) || hasMissingMainMaterialSheetSize(item) }"
                    :title="item.kind === 'facade' ? getFacadePositionName(item) : getMaterialName(item.material_id)"
                  >
                    {{ item.kind === 'facade' ? getFacadePositionName(item) : (getMaterialName(item.material_id) || '—') }}
                  </div>
                </td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'edge_material_short')">
                  <div
                    v-if="item.kind !== 'facade'"
                    class="cell-ellipsis"
                    :class="{ 'text-error': hasMissingEdgeMaterialPrice(item) }"
                    :title="getMaterialName(item.edge_material_id)"
                  >
                    {{ getMaterialName(item.edge_material_id) || '—' }}
                  </div>
                  <span v-else class="text-grey">—</span>
                </td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'base_material')">{{ item.base_material_label || '—' }}</td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'thickness')">{{ item.thickness_mm ? `${item.thickness_mm} мм` : '—' }}</td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'decor_label')">
                  <div class="cell-ellipsis" :title="item.decor_label || undefined">{{ item.decor_label || '—' }}</div>
                </td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'size')">{{ formatPositionSize(item) }}</td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'width')">{{ item.width ?? '—' }}</td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'length')">{{ item.length ?? '—' }}</td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'quantity')">{{ item.quantity ?? '—' }}</td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'edge_scheme')">
                  <span v-if="item.kind !== 'facade'">{{ getEdgeSchemeName(item.edge_scheme) }}</span>
                  <span v-else class="text-grey">—</span>
                </td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'area_total')">{{ formatNumber(getPositionAreaTotal(item), 4) }}</td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'price_per_m2')">
                  <template v-if="getPositionPricePerM2Display(item)">
                    {{ formatNumber(getPositionPricePerM2Display(item)!.value, 2) }} ₽
                    <v-chip
                      v-if="getPositionPricePerM2Display(item)!.kind === 'derived'"
                      size="x-small"
                      variant="text"
                      class="ml-1 text-disabled"
                    >
                      расч.
                    </v-chip>
                  </template>
                  <span v-else>—</span>
                </td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'unit_price')">{{ formatNumber(item.unit_price || 0, 2) }}</td>
                <td v-if="currentPositionHeaders.some(h => h.key === 'total_price')">
                  {{ formatNumber(getPositionTotalPrice(item), 2) }} ₽
                </td>
              </tr>
            </template>
          </v-data-table>
        </div>
      </section>

        <v-dialog
          v-model="positionDrawer"
          max-width="720"
          scrollable
          class="position-drawer-dialog"
        >
          <v-card class="position-sheet position-sheet--drawer">
          <v-toolbar flat class="position-sheet-toolbar">
            <v-toolbar-title>Детали позиции</v-toolbar-title>
            <v-spacer />
            <v-btn icon="mdi-close" variant="text" @click="positionDrawer = false" />
          </v-toolbar>
          <v-divider />
          <v-card-text class="position-sheet-content" style="max-height: 80vh; overflow-y: auto;">
          <v-container v-if="selectedPosition" class="pa-4 position-drawer">
            <div class="position-form-stack">
            <section class="position-form-section">
              <div class="position-form-section__header">
                <div class="position-form-section__title">Основные данные</div>
                <div class="position-form-section__text">Название и базовые атрибуты позиции.</div>
              </div>
            <v-text-field
              v-model="selectedPosition.custom_name"
              label="Название"
              density="comfortable"
              @blur="() => updatePositionField(selectedPosition!, 'custom_name', selectedPosition!.custom_name)"
            />

            <!-- Panel-specific fields -->
            <template v-if="selectedPosition.kind !== 'facade'">
              <v-autocomplete
                :model-value="selectedPosition.detail_type_id"
                :items="detailTypes"
                item-title="name"
                item-value="id"
                label="Тип детали"
                clearable
                density="comfortable"
                :loading="isApplyingDetailType(selectedPosition.id)"
                :disabled="isApplyingDetailType(selectedPosition.id)"
                @update:model-value="(v) => applyDetailTypeToPosition(selectedPosition!, v)"
                class="mb-2"
              />

              <v-autocomplete
                v-model="selectedPosition.material_id"
                :items="materialsPlate"
                item-title="name"
                item-value="id"
                label="Материал"
                density="comfortable"
                :loading="materialSearchLoading.plate"
                no-filter
                no-data-text="Введите минимум 2 символа для поиска"
                @update:search="onMaterialSearch('plate', $event)"
                @update:model-value="(v) => updatePositionField(selectedPosition!, 'material_id', v)"
                class="mb-2"
              />
            </template>

            <!-- Facade-specific fields -->
            <template v-if="selectedPosition.kind === 'facade'">
              <v-autocomplete
                v-model="selectedPosition.finished_product_specification_id"
                :items="facadeSpecifications"
                item-title="name"
                item-value="id"
                label="Спецификация фасада"
                density="comfortable"
                :loading="loadingFacades"
                @update:search="onFacadeSearch"
                @update:model-value="(v) => updatePositionField(selectedPosition!, 'finished_product_specification_id', v)"
                no-filter
                class="mb-2"
              >
                <template #item="{ props: itemProps, item }">
                  <v-list-item v-bind="itemProps">
                    <v-list-item-subtitle>
                      {{ item.raw.thickness_mm }}мм | {{ item.raw.finish_name || '—' }}
                      <span v-if="item.raw.price_per_m2" class="text-green"> | {{ formatNumber(item.raw.price_per_m2, 2) }} ₽/м²</span>
                    </v-list-item-subtitle>
                  </v-list-item>
                </template>
              </v-autocomplete>

              <!-- Read-only facade info -->
              <div v-if="selectedPosition.finished_product_specification_id" class="position-support-card mb-2">
              <v-row dense class="mb-0">
                <v-col cols="6">
                  <v-text-field
                    :model-value="selectedPosition.base_material_label || '—'"
                    label="Основа"
                    readonly
                    density="compact"
                    variant="outlined"
                  />
                </v-col>
                <v-col cols="6">
                  <v-text-field
                    :model-value="selectedPosition.thickness_mm ? `${selectedPosition.thickness_mm} мм` : '—'"
                    label="Толщина"
                    readonly
                    density="compact"
                    variant="outlined"
                  />
                </v-col>
              </v-row>
              <v-text-field
                v-if="selectedPosition.finished_product_specification_id"
                :model-value="selectedPosition.price_per_m2 ? `${formatNumber(selectedPosition.price_per_m2, 2)} ₽/м²` : '—'"
                label="Цена за м²"
                readonly
                density="compact"
                variant="outlined"
                class="mb-2"
              />
              <div class="text-caption text-medium-emphasis">
                Снимок цены:
                {{ formatFacadePriceMethodLabel(selectedPosition.finished_product_pricing_snapshot?.pricing?.aggregation_method || selectedPosition.price_method) }}
                <span v-if="(selectedPosition.finished_product_pricing_snapshot?.pricing?.source_count ?? selectedPosition.price_sources_count) != null">
                  · источников: {{ selectedPosition.finished_product_pricing_snapshot?.pricing?.source_count ?? selectedPosition.price_sources_count }}
                </span>
              </div>
              <div v-if="selectedPosition.finished_product_pricing_snapshot?.captured_at" class="text-caption text-grey mt-1">
                Зафиксировано: {{ new Date(selectedPosition.finished_product_pricing_snapshot.captured_at).toLocaleString('ru-RU') }}
              </div>
              <div v-if="selectedPosition.price_min != null && selectedPosition.price_max != null" class="text-caption text-grey mt-1">
                Диапазон: {{ formatNumber(selectedPosition.price_min, 2) }} — {{ formatNumber(selectedPosition.price_max, 2) }} ₽/м²
              </div>
              </div>
            </template>
            </section>

            <section class="position-form-section">
              <div class="position-form-section__header">
                <div class="position-form-section__title">Размеры и количество</div>
                <div class="position-form-section__text">Рабочие размеры с поддержкой выражений.</div>
              </div>
            <v-row class="position-dimensions-row">
              <v-col cols="12" sm="5" class="position-dimensions-field">
                <div class="dimension-field-stack">
                <v-text-field
                  :model-value="Math.round(selectedPosition.width || 0)"
                  :label="selectedPosition.kind === 'facade' ? 'Ширина, мм' : 'Ширина, мм'"
                  density="comfortable"
                  @input="sanitizeDimensionExpressionInput"
                  @keyup.enter="(e: any) => handleDimensionInput(selectedPosition!, 'width', (e.target as HTMLInputElement).value)"
                  @blur="(e: any) => handleDimensionInput(selectedPosition!, 'width', (e.target as HTMLInputElement).value)"
                />
                <div class="dimension-feedback-slot">
                  <div class="dimension-help text-caption text-medium-emphasis">
                    Допустимо: 600, 600-32, 2400/2
                  </div>
                  <div v-if="drawerDimensionCalc.width.expr" class="dimension-calc-line text-caption">
                    <span class="text-medium-emphasis">Введено:</span>
                    <span class="font-weight-medium">{{ drawerDimensionCalc.width.expr }}</span>
                    <span v-if="drawerDimensionCalc.width.error" class="text-error">→ {{ drawerDimensionCalc.width.error }}</span>
                    <span v-else class="text-primary">→ {{ drawerDimensionCalc.width.result }} мм</span>
                  </div>
                </div>
                </div>
              </v-col>
              <v-col cols="12" sm="2" class="dimension-swap-col dimension-swap-col--details">
                <v-btn
                  icon="mdi-swap-horizontal"
                  size="small"
                  color="primary"
                  variant="tonal"
                  class="dimension-swap-btn"
                  title="Поменять ширину и длину местами"
                  @click="toggleSelectedPositionDimensions"
                />
              </v-col>
              <v-col cols="12" sm="5" class="position-dimensions-field">
                <div class="dimension-field-stack">
                <v-text-field
                  :model-value="Math.round(selectedPosition.length || 0)"
                  :label="selectedPosition.kind === 'facade' ? 'Высота, мм' : 'Длина, мм'"
                  density="comfortable"
                  @input="sanitizeDimensionExpressionInput"
                  @keyup.enter="(e: any) => handleDimensionInput(selectedPosition!, 'length', (e.target as HTMLInputElement).value)"
                  @blur="(e: any) => handleDimensionInput(selectedPosition!, 'length', (e.target as HTMLInputElement).value)"
                />
                <div class="dimension-feedback-slot">
                  <div class="dimension-help text-caption text-medium-emphasis">
                    Допустимо: 600, 600-32, 2400/2
                  </div>
                  <div v-if="drawerDimensionCalc.length.expr" class="dimension-calc-line text-caption">
                    <span class="text-medium-emphasis">Введено:</span>
                    <span class="font-weight-medium">{{ drawerDimensionCalc.length.expr }}</span>
                    <span v-if="drawerDimensionCalc.length.error" class="text-error">→ {{ drawerDimensionCalc.length.error }}</span>
                    <span v-else class="text-primary">→ {{ drawerDimensionCalc.length.result }} мм</span>
                  </div>
                </div>
                </div>
              </v-col>
            </v-row>

            <v-text-field
              v-model.number="selectedPosition.quantity"
              label="Кол-во"
              type="number"
              density="comfortable"
              @blur="() => updatePositionField(selectedPosition!, 'quantity', selectedPosition!.quantity)"
            />
            </section>

            <!-- Edge processing — only for panels -->
            <template v-if="selectedPosition.kind !== 'facade'">
              <section class="position-form-section">
                <div class="position-form-section__header">
                  <div class="position-form-section__title">Кромка и обработка</div>
                  <div class="position-form-section__text">Схема торцов и материал кромки для панели.</div>
                </div>
              <v-select
                v-model="selectedPosition.edge_scheme"
                :items="edgeSchemeOptions"
                item-title="label"
                item-value="value"
                label="Обработка торцов"
                density="comfortable"
                class="mb-2"
                @update:model-value="(v) => handleDrawerEdgeSchemeChange(selectedPosition!, v)"
              />
              <div class="edge-preview-block mb-2">
                <div class="text-caption text-medium-emphasis mb-1">Визуализация кромки</div>
                <div class="edge-preview-diagram">
                  <div class="edge-preview-length-label">{{ formatEdgeDimension(selectedPosition.length, 'Длина') }}</div>
                  <div class="edge-preview-row">
                    <div class="edge-preview-width-label">{{ formatEdgeDimension(selectedPosition.width, 'Ширина') }}</div>
                    <div class="edge-preview-box" :style="getEdgePreviewStyle(selectedPosition.width, selectedPosition.length)">
                  <div class="edge-side top" :class="{ active: isEdgeSideActive(selectedPosition.edge_scheme, 'top') }"></div>
                  <div class="edge-side right" :class="{ active: isEdgeSideActive(selectedPosition.edge_scheme, 'right') }"></div>
                  <div class="edge-side bottom" :class="{ active: isEdgeSideActive(selectedPosition.edge_scheme, 'bottom') }"></div>
                  <div class="edge-side left" :class="{ active: isEdgeSideActive(selectedPosition.edge_scheme, 'left') }"></div>
                  <div class="edge-center-label">Деталь</div>
                    </div>
                  </div>
                </div>
                <div class="text-caption mt-1">{{ getEdgeSchemeSummary(selectedPosition.edge_scheme) }}</div>
              </div>
              <v-alert
                v-if="selectedPosition.edge_scheme && selectedPosition.edge_scheme !== 'none' && !selectedPosition.edge_material_id"
                type="warning"
                variant="tonal"
                density="compact"
                class="mb-2"
              >
                Для выбранной схемы назначьте материал кромки.
              </v-alert>

              <v-autocomplete
                v-if="selectedPosition.edge_scheme && selectedPosition.edge_scheme !== 'none'"
                v-model="selectedPosition.edge_material_id"
                :items="materialsEdge"
                item-title="name"
                item-value="id"
                label="Кромка"
                density="comfortable"
                :loading="materialSearchLoading.edge"
                no-filter
                no-data-text="Введите минимум 2 символа для поиска"
                @update:search="onMaterialSearch('edge', $event)"
                @update:model-value="(v) => updatePositionField(selectedPosition!, 'edge_material_id', v)"
              />
              </section>
            </template>

            <section class="position-form-section position-form-section--muted">
              <div class="position-form-section__header">
                <div class="position-form-section__title">История</div>
                <div class="position-form-section__text">Технический блок для будущего журнала изменений.</div>
              </div>
            <v-divider class="my-4" />
            <div class="text-subtitle-2 mb-2">История изменений</div>
            <v-alert type="info" variant="tonal" density="compact">
              История изменений пока недоступна.
            </v-alert>
            </section>
            </div>
          </v-container>
          <v-container v-else class="pa-4">
            <v-alert type="info" variant="tonal">Выберите позицию в таблице.</v-alert>
          </v-container>
          </v-card-text>
          </v-card>
        </v-dialog>

        <v-dialog v-model="confirmBulkDialog" max-width="480">
          <v-card>
            <v-card-title>Подтверждение</v-card-title>
            <v-card-text>
              Применить действие к {{ selectedPositionIds.length }} позициям?
            </v-card-text>
            <v-card-actions>
              <v-spacer />
              <v-btn variant="text" @click="confirmBulkDialog = false">Отмена</v-btn>
              <v-btn color="primary" @click="applyBulkAction">Подтвердить</v-btn>
            </v-card-actions>
          </v-card>
        </v-dialog>
    </div><!-- /MODULE: POSITIONS -->

    <!-- ======================== MODULE: MATERIALS ======================== -->
    <div v-show="activeModule === 'materials'" class="module-content">
      <!-- Materials Summary -->
      <div class="module-summary">
        <v-chip size="small" variant="tonal" color="primary">Плитных: {{ plateData.length }}</v-chip>
        <v-chip size="small" variant="tonal">Кромка: {{ edgeData.length }}</v-chip>
        <v-chip size="small" variant="tonal" color="success" prepend-icon="mdi-currency-rub">Итого: {{ materialsTotalCost.toFixed(2) }} ₽</v-chip>
      </div>
      <section class="dense-module-shell">
        <div class="dense-module-hero">
          <div class="dense-module-hero__content">
            <div class="dense-module-hero__eyebrow">Материалы проекта</div>
            <div class="dense-module-hero__title-row">
              <div class="dense-module-hero__title">Материалы</div>
            </div>
          </div>
        </div>

        <section class="dense-module-surface">
          <div class="dense-module-section-bar">
            <div class="dense-module-section-bar__content">
              <div class="dense-module-section-bar__title">Плитные материалы</div>
            </div>
            <div class="dense-module-section-bar__meta">
              <v-chip size="small" variant="tonal" color="primary">
                {{ plateData.length }} поз.
              </v-chip>
              <v-chip
                v-if="!project.apply_waste_to_plate"
                size="small"
                variant="tonal"
                color="warning"
              >
                Коэффициент отключен
              </v-chip>
              <v-chip size="small" variant="outlined" color="info">
                {{ project.use_area_calc_mode ? 'Расчёт по площади' : 'Расчёт по листам' }}
              </v-chip>
            </div>
          </div>
          <v-skeleton-loader v-if="loadingStates.materials && plateData.length === 0" type="table" />
          <div v-else-if="plateData.length > 0" class="dense-module-table-wrap">
            <v-data-table :items="plateData" :headers="plateHeaders" density="comfortable" class="dense-module-table" show-expand>
          <template v-slot:item.name="{ item }">
            <span :class="{ 'text-error': hasPlateMaterialIssue(item) }">
              {{ item.name }}
            </span>
          </template>
          <template v-slot:header.price_per_m2="{ column }">
            <v-tooltip text="Цена рассчитана из цены листа">
              <template v-slot:activator="{ props }">
                <span v-bind="props" class="cursor-help">
                  {{ column.title }}
                  <v-chip size="x-small" variant="text" color="grey-darken-1">расч.</v-chip>
                </span>
              </template>
            </v-tooltip>
          </template>
          <template v-slot:item.area_details="{ item }">
            {{ item.area_details.toFixed(2) }}
          </template>
          <template v-slot:item.waste_coeff="{ item }">
            {{ (Number(item.waste_coeff) || 1.0).toFixed(2) }}
          </template>
          <template v-slot:item.area_with_waste="{ item }">
            {{ item.area_with_waste.toFixed(2) }}
          </template>
          <template v-slot:item.sheet_area="{ item }">
            <v-tooltip v-if="!project.use_area_calc_mode && item.sheet_area > 0" :text="`${(item.sheet_area * 1_000_000).toFixed(0)} мм²`">
              <template v-slot:activator="{ props }">
                <span v-bind="props">{{ item.sheet_area.toFixed(4) }}</span>
              </template>
            </v-tooltip>
            <span v-else-if="!project.use_area_calc_mode">—</span>
          </template>
          <template v-slot:item.sheets_count="{ item }">
            <span v-if="!project.use_area_calc_mode">{{ item.sheets_count }}</span>
            <span v-else>{{ item.area_with_waste.toFixed(2) }} м²</span>
          </template>
          <template v-slot:item.price_per_sheet="{ item }">
            <span v-if="!project.use_area_calc_mode">
              {{ (Number(item.price_per_sheet) || 0).toFixed(2) }} ₽
            </span>
          </template>
          <template v-slot:item.price_per_m2="{ item }">
            <span v-if="project.use_area_calc_mode">
              {{ (Number(item.price_per_m2) || 0).toFixed(2) }} ₽/м²
            </span>
          </template>
          <template v-slot:item.total_price="{ item }">
            <span v-if="!project.use_area_calc_mode">
              {{ (item.sheets_count * (Number(item.price_per_sheet) || 0)).toFixed(2) }} ₽
            </span>
            <span v-else>
              {{ (item.area_with_waste * (Number(item.price_per_m2) || 0)).toFixed(2) }} ₽
            </span>
          </template>
          
          <!-- Раскрытие с деталями расчёта -->
          <template v-slot:expanded-row="{ columns, item }">
            <tr>
              <td :colspan="columns.length" class="pa-4">
                <v-container class="pa-0">
                  <v-row>
                    <v-col cols="12">
                      <div class="text-subtitle-2 mb-2"><strong>Формула расчёта:</strong></div>
                    </v-col>
                  </v-row>
                  <v-row>
                    <v-col cols="12" md="6">
                      <div class="text-body-2">
                        <div>Площадь деталей: <strong>{{ item.area_details.toFixed(2) }} м²</strong></div>
                        <div>Коэф. отходов: <strong>{{ (Number(item.waste_coeff) || 1.0).toFixed(2) }}</strong></div>
                        <div>Площадь с отходами: <strong>{{ item.area_with_waste.toFixed(2) }} м²</strong></div>
                        
                        <template v-if="!project.use_area_calc_mode">
                          <div>Площадь листа: <strong>{{ item.sheet_area.toFixed(4) }} м²</strong></div>
                          <div class="mt-2">Листов: <strong>ceil({{ item.area_with_waste.toFixed(2) }} / {{ item.sheet_area.toFixed(4) }}) = {{ item.sheets_count }}</strong></div>
                          <div class="mt-2">Цена за м²: <strong>{{ item.price_per_m2.toFixed(2) }} ₽/м²</strong></div>
                          <div class="mt-2">Итого: <strong>{{ item.sheets_count }} × {{ (Number(item.price_per_sheet) || 0).toFixed(2) }} = {{ (item.sheets_count * (Number(item.price_per_sheet) || 0)).toFixed(2) }} ₽</strong></div>
                        </template>
                        
                        <template v-else>
                          <div class="mb-3 pb-3 border-b">
                            <div class="text-subtitle-2 mb-2"><strong>Основной расчёт:</strong></div>
                            <div>Площадь к оплате: <strong>{{ item.area_with_waste.toFixed(2) }} м²</strong></div>
                            <div>Цена за м² (расч.): <strong>{{ item.price_per_m2.toFixed(2) }} ₽/м²</strong></div>
                            <div class="mt-2">Итого: <strong>{{ item.area_with_waste.toFixed(2) }} × {{ item.price_per_m2.toFixed(2) }} = {{ (item.area_with_waste * item.price_per_m2).toFixed(2) }} ₽</strong></div>
                          </div>
                          <div class="text-caption text-grey">
                            <div class="mb-2"><strong>Справочные данные:</strong></div>
                            <div>Размер листа: {{ item.sheet_area.toFixed(4) }} м²</div>
                            <div>Цена за лист: {{ (Number(item.price_per_sheet) || 0).toFixed(2) }} ₽</div>
                            <div class="mt-2">Цена за м² получена расчётным путём из цены листа:</div>
                            <div>{{ (Number(item.price_per_sheet) || 0).toFixed(2) }} ₽ / {{ item.sheet_area.toFixed(4) }} м² = {{ item.price_per_m2.toFixed(2) }} ₽/м²</div>
                          </div>
                        </template>
                      </div>
                    </v-col>
                    <v-col cols="12" md="6" v-if="item.updated_at">
                      <div class="text-subtitle-2 mb-2"><strong>Источник цены:</strong></div>
                      <div class="text-body-2">
                        <div>Дата обновления: <strong>{{ new Date(item.updated_at).toLocaleDateString('ru-RU') }}</strong></div>
                        <div v-if="item.source_url" class="mt-2">
                          <v-btn
                            :href="item.source_url"
                            target="_blank"
                            size="small"
                            variant="text"
                            color="primary"
                            prepend-icon="mdi-link-variant"
                          >
                            Перейти на страницу материала
                          </v-btn>
                        </div>
                        <div class="text-caption text-grey mt-2">Цена актуальна на указанную дату</div>
                      </div>
                    </v-col>
                  </v-row>
                </v-container>
              </td>
            </tr>
          </template>
            </v-data-table>
          </div>
        </section>

        <section class="dense-module-surface">
          <div class="dense-module-section-bar">
            <div class="dense-module-section-bar__content">
              <div class="dense-module-section-bar__title">Кромка</div>
            </div>
            <div class="dense-module-section-bar__meta">
              <v-chip size="small" variant="tonal">
                {{ edgeData.length }} поз.
              </v-chip>
              <v-chip
                v-if="!project.apply_waste_to_edge"
                size="small"
                variant="tonal"
                color="warning"
              >
                Коэффициент отключен
              </v-chip>
            </div>
          </div>
          <v-skeleton-loader v-if="loadingStates.materials && edgeData.length === 0" type="table" />
          <div v-else-if="edgeData.length > 0" class="dense-module-table-wrap">
            <v-data-table :items="edgeData" :headers="edgeHeaders" density="comfortable" class="dense-module-table" show-expand>
          <template v-slot:item.length_linear="{ item }">
            {{ item.length_linear.toFixed(2) }}
          </template>
          <template v-slot:item.waste_coeff="{ item }">
            {{ (Number(item.waste_coeff) || 1.0).toFixed(2) }}
          </template>
          <template v-slot:item.length_with_waste="{ item }">
            {{ item.length_with_waste.toFixed(2) }}
          </template>
          <template v-slot:item.price_per_unit="{ item }">
            {{ (Number(item.price_per_unit) || 0).toFixed(2) }} ₽
          </template>
          <template v-slot:item.total_price="{ item }">
            {{ (item.length_with_waste * (Number(item.price_per_unit) || 0)).toFixed(2) }} ₽
          </template>

          <!-- Раскрытие с деталями расчёта кромки -->
          <template v-slot:expanded-row="{ columns, item }">
            <tr>
              <td :colspan="columns.length" class="pa-4">
                <v-container class="pa-0">
                  <v-row>
                    <v-col cols="12">
                      <div class="text-subtitle-2 mb-3"><strong>Расчёт длины кромки:</strong></div>
                    </v-col>
                  </v-row>
                  <v-row>
                    <v-col cols="12">
                      <v-table class="border-table">
                        <thead>
                          <tr>
                            <th>Позиция</th>
                            <th>Размер детали (мм)</th>
                            <th>Обработка торцов</th>
                            <th>Периметр одной (м)</th>
                            <th>Количество</th>
                            <th>Итого длина (м)</th>
                          </tr>
                        </thead>
                        <tbody>
                          <tr v-for="detail in getEdgeDetailsForMaterial(item.id)" :key="detail.position_id">
                            <td>{{ detail.position_name }}</td>
                            <td>{{ detail.width }} × {{ detail.length }}</td>
                            <td>{{ detail.edge_scheme_label }}</td>
                            <td>{{ detail.perimeter_one.toFixed(4) }}</td>
                            <td>{{ detail.quantity }}</td>
                            <td><strong>{{ detail.total_length.toFixed(2) }}</strong></td>
                          </tr>
                        </tbody>
                        <tfoot>
                          <tr>
                            <td colspan="5"><strong>Всего:</strong></td>
                            <td><strong>{{ item.length_linear.toFixed(2) }} м</strong></td>
                          </tr>
                        </tfoot>
                      </v-table>
                    </v-col>
                  </v-row>
                  <v-row class="mt-4">
                    <v-col cols="12" md="6">
                      <div class="text-body-2">
                        <div>Коэффициент отходов: <strong>{{ (Number(item.waste_coeff) || 1.0).toFixed(2) }}</strong></div>
                        <div class="mt-2">Расчёт: <strong>{{ item.length_linear.toFixed(2) }} × {{ (Number(item.waste_coeff) || 1.0).toFixed(2) }} = {{ item.length_with_waste.toFixed(2) }} м</strong></div>
                        <div class="text-caption">
                          <strong>Примечание:</strong> Коэффициент применяется к материалу и операции кромкооблицовки. Стоимость операции "Кромкооблицовка" будет рассчитана с использованием этого же коэффициента.
                        </div>
                      </div>
                    </v-col>
                  </v-row>
                </v-container>
              </td>
            </tr>
          </template>
            </v-data-table>
          </div>
        </section>
      </section>
    </div><!-- /MODULE: MATERIALS -->

    <!-- ======================== MODULE: OPERATIONS ======================== -->
    <div v-show="activeModule === 'operations'" class="module-content">
      <!-- Operations Summary -->
      <div class="module-summary">
        <v-chip size="small" variant="tonal" color="success" prepend-icon="mdi-currency-rub">Итого авто: {{ autoOperationsTotal.toFixed(2) }} ₽</v-chip>
        <v-chip size="small" variant="tonal" color="primary">Ручных: {{ manualOperationsCount }}</v-chip>
        <v-chip size="small" variant="tonal" color="info" prepend-icon="mdi-currency-rub">Всего: {{ operationsTotal.toFixed(2) }} ₽</v-chip>
      </div>
      <section class="dense-module-shell">
        <div class="dense-module-hero">
          <div class="dense-module-hero__content">
            <div class="dense-module-hero__eyebrow">Операционный контур</div>
            <div class="dense-module-hero__title-row">
              <div class="dense-module-hero__title">Операции</div>
            </div>
          </div>
        </div>

        <v-alert
          v-if="!operationsTotalIsValid"
          type="warning"
          variant="tonal"
          density="compact"
          class="dense-module-inline-alert"
        >
          В смете есть некорректные операции
        </v-alert>

        <section class="dense-module-surface">
          <div class="dense-module-actions-bar">
            <div class="dense-module-actions-bar__actions">
              <v-btn prepend-icon="mdi-plus" :disabled="isProjectReadOnly" :title="readOnlyActionTitle || 'Добавить операцию'" @click="openOperationDialog">Добавить операцию</v-btn>
            </div>
          </div>
          <v-skeleton-loader v-if="loadingStates.operations" type="table" />
          <div v-else class="dense-module-table-wrap">
            <v-data-table 
          :expanded="expandedOperation != null ? [String(expandedOperation)] : []"
          :items="operations" 
          :headers="(operationHeaders as any)" 
          density="comfortable" 
          class="dense-module-table"
          show-expand
          item-value="id"
          @click:row="(_: any, { item, expand }: any) => {
            if (expand) {
              expandedOperation = expandedOperation === item.id ? null : item.id
            }
          }"
        >
          <template v-slot:item.quantity_display="{ item }">
            {{ (typeof item.quantity === 'number' ? item.quantity : parseFloat(item.quantity || 0)).toFixed(2) }}
          </template>
          <template v-slot:item.name="{ item }">
            <div class="d-flex flex-column ga-1">
              <div class="d-flex align-center ga-1">
                <span>{{ item.name }}</span>
                <v-icon v-if="!item.is_manual" size="18" color="blue">mdi-robot</v-icon>
              </div>
              <v-chip
                v-if="item.unit_mismatch"
                size="x-small"
                color="warning"
                variant="tonal"
                prepend-icon="mdi-alert"
                class="align-self-start"
              >
                Несовпадение единицы тарифа и расчёта
              </v-chip>
            </div>
          </template>

          <template v-slot:item.cost_per_unit="{ item }">
            <div>{{ parseFloat(item.cost_per_unit || 0).toFixed(2) }} ₽</div>
          </template>

          <template v-slot:item.total_cost="{ item }">
            <span v-if="item.total_cost === null || item.total_cost === undefined">—</span>
            <span v-else>{{ parseFloat(item.total_cost).toFixed(2) }} ₽</span>
          </template>

          <template v-slot:item.source="{ item }">
            <v-chip
              v-if="!item.is_manual && item.price_list_version_id && item.price_list_id && item.supplier_id"
              size="small"
              color="success"
              variant="tonal"
              style="cursor:pointer"
              title="Перейти к версии прайс-листа"
              @click="router.push({ name: 'price-list-version-show', params: { supplierId: item.supplier_id, priceListId: item.price_list_id, versionId: item.price_list_version_id } })"
            >
              Авто
            </v-chip>
            <v-chip
              v-else-if="!item.is_manual"
              size="small"
              color="success"
              variant="flat"
            >
              Авто
            </v-chip>
            <v-chip
              v-else
              size="small"
              color="primary"
              variant="flat"
            >
              Ручная
            </v-chip>
          </template>
          <template v-slot:item.actions="{ item }">
            <v-icon
              v-if="item.is_manual"
              @click="editOperation(item)"
              class="me-2"
              :color="isProjectReadOnly ? 'disabled' : 'primary'"
              :title="readOnlyActionTitle || 'Изменить'"
            >
              mdi-pencil
            </v-icon>
            <v-icon
              v-if="item.is_manual"
              @click="deleteOperation(item)"
              :color="isProjectReadOnly ? 'disabled' : 'error'"
              :title="readOnlyActionTitle || 'Удалить'"
              icon="mdi-delete"
            ></v-icon>
          </template>

          <!-- Раскрытие с деталями расчёта операции -->
          <template v-slot:expanded-row="{ columns, item }">
            <tr>
              <td :colspan="columns.length" class="pa-4">
                <v-container class="pa-0">
                  <v-row>
                    <v-col cols="12">
                      <div class="text-subtitle-2 mb-3"><strong>Расчёт операции "{{ item.name }}":</strong></div>
                    </v-col>
                  </v-row>

                  <!-- Кромкооблицовка -->
                  <template v-if="getOperationDetails(item).type === 'edging'">
                    <v-row>
                      <v-col cols="12">
                        <v-table class="border-table">
                          <thead>
                            <tr>
                              <th>Позиция / Материал</th>
                              <th>Длина (м)</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr v-for="detail in getOperationDetails(item).details" :key="`${detail.position_name}-${detail.material_name}`">
                              <td>
                                <div>{{ detail.position_name }}</div>
                                <div class="text-caption text-grey">{{ detail.material_name }}</div>
                              </td>
                              <td>{{ detail.length.toFixed(2) }}</td>
                            </tr>
                          </tbody>
                          <tfoot>
                            <tr>
                              <td><strong>Итого:</strong></td>
                              <td><strong>{{ getOperationDetails(item).total_length.toFixed(2) }} м</strong></td>
                            </tr>
                          </tfoot>
                        </v-table>
                      </v-col>
                    </v-row>
                    <v-row class="mt-3">
                      <v-col cols="12">
                        <div class="text-body-2">
                          <div>Коэффициент проекта: <strong>{{ (Number(getOperationDetails(item).waste_coeff) || 1.0).toFixed(2) }}</strong></div>
                          <div class="mt-2">К оплате: <strong>{{ getOperationDetails(item).total_with_waste.toFixed(2) }} м.п.</strong></div>
                          <div class="text-caption">
                            ({{ getOperationDetails(item).total_length.toFixed(2) }} × {{ (Number(getOperationDetails(item).waste_coeff) || 1.0).toFixed(2) }} = {{ getOperationDetails(item).total_with_waste.toFixed(2) }} м.п.)
                          </div>
                        </div>
                      </v-col>
                    </v-row>
                  </template>

                  <!-- Распиловка -->
                  <template v-else-if="getOperationDetails(item).type === 'sawing'">
                    <v-row>
                      <v-col cols="12">
                        <div class="text-caption mb-3">{{ getOperationDetails(item).note }}</div>
                        <v-table class="border-table">
                          <thead>
                            <tr>
                              <th>Материал</th>
                              <th>Площадь (м²)</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr v-for="detail in getOperationDetails(item).details" :key="detail.material_name">
                              <td>{{ detail.material_name }}</td>
                              <td>{{ detail.area.toFixed(2) }}</td>
                            </tr>
                          </tbody>
                          <tfoot>
                            <tr>
                              <td><strong>Итого:</strong></td>
                              <td><strong>{{ getOperationDetails(item).total_area.toFixed(2) }} м²</strong></td>
                            </tr>
                          </tfoot>
                        </v-table>
                      </v-col>
                    </v-row>
                  </template>

                  <!-- Сверление -->
                  <template v-else-if="getOperationDetails(item).type === 'drilling'">
                    <v-row>
                      <v-col cols="12">
                        <v-table class="border-table" v-if="getOperationDetails(item).details && getOperationDetails(item).details.length > 0">
                          <thead>
                            <tr>
                              <th>Тип детали</th>
                              <th>Отверстий на деталь</th>
                              <th>Количество деталей</th>
                              <th>Всего отверстий</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr v-for="detail in getOperationDetails(item).details" :key="detail.detail_type_name">
                              <td>{{ detail.detail_type_name }}</td>
                              <td>{{ detail.holes_per_piece }}</td>
                              <td>{{ detail.quantity }}</td>
                              <td>{{ detail.total_holes }}</td>
                            </tr>
                          </tbody>
                          <tfoot>
                            <tr>
                              <td colspan="3"><strong>Итого:</strong></td>
                              <td><strong>{{ getOperationDetails(item).total_holes }} шт</strong></td>
                            </tr>
                          </tfoot>
                        </v-table>
                        <v-alert v-else type="info" variant="tonal">
                          {{ getOperationDetails(item).message }}
                        </v-alert>
                      </v-col>
                    </v-row>
                  </template>

                  <!-- Ручная операция или прочее -->
                  <template v-else>
                    <v-row>
                      <v-col cols="12">
                        <v-alert type="info" variant="tonal">
                          {{ getOperationDetails(item).message }}
                        </v-alert>
                      </v-col>
                    </v-row>
                  </template>
                </v-container>
              </td>
            </tr>
          </template>
            </v-data-table>
          </div>
        </section>
      </section>
    </div><!-- /MODULE: OPERATIONS -->

    <!-- ======================== MODULE: FITTINGS ======================== -->
    <div v-show="activeModule === 'fittings'" class="module-content">
      <!-- Fittings Summary -->
      <div class="module-summary">
        <v-chip size="small" variant="tonal" color="primary">Позиций: {{ fittings.length }}</v-chip>
        <v-chip size="small" variant="tonal" color="success" prepend-icon="mdi-currency-rub">
          Итого: {{ fittings.reduce((sum, f) => sum + (f.quantity * f.unit_price), 0).toFixed(2) }} ₽
        </v-chip>
      </div>
      <section class="dense-module-shell">
        <div class="dense-module-hero">
          <div class="dense-module-hero__content">
            <div class="dense-module-hero__eyebrow">Комплектующие и метизы</div>
            <div class="dense-module-hero__title-row">
              <div class="dense-module-hero__title">Фурнитура</div>
            </div>
          </div>
        </div>

        <section class="dense-module-surface">
          <div class="dense-module-actions-bar">
            <div class="dense-module-actions-bar__actions">
              <v-btn prepend-icon="mdi-plus" :disabled="isProjectReadOnly" :title="readOnlyActionTitle || 'Добавить фурнитуру'" @click="openFittingDialog">Добавить фурнитуру</v-btn>
            </div>
          </div>

          <v-skeleton-loader v-if="loadingStates.fittings" type="table" />
          <div v-else class="dense-module-table-wrap">
            <v-data-table :items="fittings" :headers="fittingHeaders" class="dense-module-table">
              <template v-slot:item.actions="{ item }">
                <div class="module-row-actions">
                  <v-btn
                    size="x-small"
                    variant="text"
                    icon="mdi-pencil"
                    :disabled="isProjectReadOnly"
                    :title="readOnlyActionTitle || 'Изменить'"
                    @click.stop="editFitting(item)"
                  />
                  <v-btn
                    size="x-small"
                    variant="text"
                    color="error"
                    icon="mdi-delete"
                    :disabled="isProjectReadOnly"
                    :title="readOnlyActionTitle || 'Удалить'"
                    @click.stop="deleteFitting(item)"
                  />
                </div>
              </template>
            </v-data-table>
          </div>
        </section>
      </section>
    </div><!-- /MODULE: FITTINGS -->

    <!-- ======================== MODULE: LABOR ======================== -->
    <div v-show="activeModule === 'labor'" class="module-content">
      <!-- Labor Summary -->
      <div class="module-summary">
        <v-chip size="small" variant="tonal" color="primary">Работ: {{ laborWorks.length }}</v-chip>
        <v-chip size="small" variant="tonal" color="success" prepend-icon="mdi-currency-rub">Итого: {{ (typeof laborWorksTotal === 'number' ? laborWorksTotal : 0).toFixed(2) }} ₽</v-chip>
      </div>
      <section class="dense-module-shell">
        <div class="dense-module-hero">
          <div class="dense-module-hero__content">
            <div class="dense-module-hero__eyebrow">Труд и нормо-часы</div>
            <div class="dense-module-hero__title-row">
              <div class="dense-module-hero__title">Нормируемые работы</div>
            </div>
          </div>
        </div>

        <section class="dense-module-surface">
          <div class="dense-module-actions-bar">
            <div class="dense-module-actions-bar__actions">
              <v-btn
                variant="tonal"
                color="primary"
                prepend-icon="mdi-calculator-variant-outline"
                @click="openLaborCalculationDialog"
              >
                Расчёт нормо-часа
              </v-btn>
              <v-btn prepend-icon="mdi-plus" :disabled="isProjectReadOnly" :title="readOnlyActionTitle || 'Добавить работу'" @click="openLaborWorkDialog">Добавить работу</v-btn>
            </div>
          </div>

          <div class="dense-module-support-grid">
            <div class="dense-module-support-card">
              <ProjectLaborEvidencePanel
                :project-id="projectApiId"
                @sources-changed="handleLaborEvidenceSourcesChanged"
              />
            </div>
          </div>

          <v-skeleton-loader v-if="loadingStates.laborWorks" type="table" />
          <div v-else class="labor-works-table dense-module-table-wrap">
            <table class="lw-table">
            <thead>
              <tr>
                <th class="lw-th lw-th-drag" style="width: 40px"></th>
                <th class="lw-th">Наименование</th>
                <th class="lw-th">Основание</th>
                <th class="lw-th lw-th-right">Норма, ч</th>
                <th class="lw-th lw-th-right">Ставка, ₽/ч</th>
                <th class="lw-th lw-th-right">Сумма, ₽</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(item, idx) in laborWorks"
                :key="item.id"
                class="lw-row"
                :class="{
                  'lw-row-dragging': draggedLaborWorkId === item.id,
                  'lw-row-over': dragOverLaborWorkId === item.id && draggedLaborWorkId !== item.id
                }"
                draggable="true"
                @dragstart="onLaborDragStart($event, item)"
                @dragend="onLaborDragEnd"
                @dragover.prevent="dragOverLaborWorkId = item.id!"
                @dragleave="dragOverLaborWorkId = null"
                @drop.prevent="onLaborDrop(item)"
              >
                <td class="lw-td lw-td-drag">
                  <v-icon class="lw-drag-handle" size="18">mdi-drag</v-icon>
                </td>
                <td class="lw-td">
                  <div
                    class="cell-with-actions"
                    @mouseenter="hoveredLaborWorkId = item.id!"
                    @mouseleave="hoveredLaborWorkId = null"
                  >
                    <div class="cell-name-column">
                      <div class="cell-ellipsis">{{ item.title || '—' }}</div>
                      <div v-if="item.labor_profile_name" class="text-caption text-medium-emphasis mt-1">
                        {{ item.labor_profile_name }}
                      </div>
                      <RowHoverActions
                        :row-id="item.id!"
                        :quick-actions="getLaborQuickActions(item)"
                        :menu-actions="getLaborMenuActions(item)"
                        :visible="isLaborActionsVisible(item.id!)"
                        :loading-key="laborStepsLoadingId === item.id ? 'details' : null"
                        dense
                        @action="handleLaborRowAction"
                      />
                    </div>
                  </div>
                </td>
                <td class="lw-td">{{ item.basis || '—' }}</td>
                <td class="lw-td lw-td-right">{{ typeof item.hours === 'number' ? item.hours.toFixed(2) : parseFloat(String(item.hours) || '0').toFixed(2) }}</td>
                <td class="lw-td lw-td-right">{{ (parseFloat(String(item.rate_per_hour)) || 0).toFixed(2) }}</td>
                <td class="lw-td lw-td-right">
                  <strong>{{ (parseFloat(String(item.cost_total)) || 0).toFixed(2) }}</strong>
                </td>
              </tr>
              <tr v-if="laborWorks.length === 0">
                <td colspan="6" class="text-center py-8 text-grey">
                  Монтажно-сборочные работы не добавлены
                </td>
              </tr>
            </tbody>
          </table>
          </div>
        </section>
      </section>

    </div><!-- /MODULE: LABOR -->

    <!-- ======================== MODULE: EXPENSES ======================== -->
    <div v-show="activeModule === 'expenses'" class="module-content">
      <!-- Expenses Summary -->
      <div class="module-summary">
        <v-chip size="small" variant="tonal" color="primary">Расходов: {{ expenses.length }}</v-chip>
        <v-chip size="small" variant="tonal" color="success" prepend-icon="mdi-currency-rub">
          Итого: {{ (Array.isArray(expenses) ? expenses.reduce((sum, exp) => sum + (parseFloat(exp.amount) || 0), 0) : 0).toFixed(2) }} ₽
        </v-chip>
      </div>
      <section class="dense-module-shell">
        <div class="dense-module-hero">
          <div class="dense-module-hero__content">
            <div class="dense-module-hero__eyebrow">Сопутствующие затраты</div>
            <div class="dense-module-hero__title-row">
              <div class="dense-module-hero__title">Накладные расходы</div>
            </div>
          </div>
        </div>

        <section class="dense-module-surface">
          <div class="dense-module-actions-bar">
            <div class="dense-module-actions-bar__actions">
              <v-btn prepend-icon="mdi-plus" :disabled="isProjectReadOnly" :title="readOnlyActionTitle || 'Добавить расход'" @click="openExpenseDialog">Добавить расход</v-btn>
            </div>
          </div>

          <v-skeleton-loader v-if="loadingStates.expenses" type="table" />
          <div v-else class="dense-module-table-wrap">
            <v-data-table :items="expenses" :headers="expenseHeaders" class="dense-module-table">
              <template v-slot:item.amount="{ item }">
                {{ Number(item.amount || 0).toFixed(2) }} ₽
              </template>
              <template v-slot:item.actions="{ item }">
                <div class="module-row-actions">
                  <v-btn
                    size="x-small"
                    variant="text"
                    icon="mdi-pencil"
                    :disabled="isProjectReadOnly"
                    :title="readOnlyActionTitle || 'Изменить'"
                    @click.stop="editExpense(item)"
                  />
                  <v-btn
                    size="x-small"
                    variant="text"
                    color="error"
                    icon="mdi-delete"
                    :disabled="isProjectReadOnly"
                    :title="readOnlyActionTitle || 'Удалить'"
                    @click.stop="deleteExpense(item)"
                  />
                </div>
              </template>
            </v-data-table>
          </div>
        </section>
      </section>
    </div><!-- /MODULE: EXPENSES -->

    <!-- ======================== MODULE: REVISIONS ======================== -->
    <div v-show="activeModule === 'revisions'" class="module-content">
      <!-- Revisions Summary -->
      <div class="module-summary">
        <v-chip v-if="latestRevision" size="small" variant="tonal" :color="getRevisionStatusColor(latestRevision.status)" prepend-icon="mdi-check-circle">
          Последняя: #{{ latestRevision.number }} — {{ formatRevisionStatus(latestRevision.status) }}
        </v-chip>
        <v-chip v-else size="small" variant="tonal" color="grey">Нет ревизий</v-chip>
        <v-chip v-if="activeRevisionRun" size="small" variant="tonal" color="info" prepend-icon="mdi-progress-clock">
          Сессия #{{ activeRevisionRun.id }}: {{ formatRunStatus(activeRevisionRun.status) }}
        </v-chip>
      </div>
      <section class="dense-module-shell">
        <div class="dense-module-hero">
          <div class="dense-module-hero__content">
            <div class="dense-module-hero__eyebrow">Контроль версий и публикация</div>
            <div class="dense-module-hero__title-row">
              <div class="dense-module-hero__title">Ревизии</div>
              <v-chip
                v-if="latestRevision"
                size="small"
                variant="tonal"
                :color="getRevisionStatusColor(latestRevision.status)"
                prepend-icon="mdi-check-circle"
              >
                #{{ latestRevision.number }} — {{ formatRevisionStatus(latestRevision.status) }}
              </v-chip>
              <v-chip v-else size="small" variant="tonal" color="grey">Нет ревизий</v-chip>
            </div>
          </div>
        </div>

        <section v-if="activeRevisionRun" class="dense-module-surface">
          <div class="dense-module-section-bar">
            <div class="dense-module-section-bar__content">
              <div class="dense-module-section-bar__title">Активная сессия ревизии</div>
            </div>
          </div>

          <div class="dense-module-support-card revision-surface-card">
            <v-card
              variant="flat"
              class="revision-run-card"
            >
              <v-card-title class="d-flex align-center flex-wrap ga-2">
            <span>Сессия ревизии #{{ activeRevisionRun.id }}</span>
            <v-chip size="small" :color="getRunStatusColor(activeRevisionRun.status)" variant="tonal">
              <v-progress-circular
                v-if="activeRevisionRun.status === 'IN_PROGRESS' || activeRevisionRun.status === 'PENDING'"
                indeterminate
                size="12"
                width="2"
                class="mr-1"
              />
              {{ formatRunStatus(activeRevisionRun.status) }}
            </v-chip>
            <v-chip size="small" variant="outlined">
              OK: {{ activeRevisionRun.ok_items || 0 }} / {{ activeRevisionRun.total_items || 0 }}
            </v-chip>
            <v-chip v-if="(activeRevisionRun.failed_items || 0) > 0" size="small" variant="outlined" color="warning">
              Проблемных: {{ activeRevisionRun.failed_items || 0 }}
            </v-chip>
            <v-chip
              v-if="evidenceCoverage.total > 0"
              size="small"
              variant="tonal"
              :color="evidenceCoverage.withEvidence === evidenceCoverage.total ? 'success' : undefined"
              prepend-icon="mdi-camera"
            >
              Обоснования: {{ evidenceCoverage.withEvidence }} / {{ evidenceCoverage.total }}
            </v-chip>
            <v-chip
              v-if="Object.keys(resolutionBreakdown).length > 0"
              size="small"
              variant="outlined"
            >
              <template v-for="(count, src) in resolutionBreakdown" :key="src">
                <v-icon size="14" class="mr-1" :class="{ 'ml-2': src !== Object.keys(resolutionBreakdown)[0] }">{{ getResolutionIcon(src as string) }}</v-icon>{{ count }}
              </template>
            </v-chip>
            <v-spacer />
            <v-btn
              v-if="activeRevisionRun.status !== 'IN_PROGRESS' && activeRevisionRun.status !== 'PENDING'"
              size="small"
              variant="outlined"
              prepend-icon="mdi-refresh"
              :loading="revisionRunLoading"
              @click="refreshRevisionRun"
            >
              Обновить статус
            </v-btn>
            <v-btn
              size="small"
              color="warning"
              variant="outlined"
              prepend-icon="mdi-reload"
              :loading="revisionRunRetryLoading"
              :disabled="isProjectReadOnly || !canRetryRevisionRun"
              @click="retryRevisionRun"
            >
              Повторить
            </v-btn>
            <v-btn
              size="small"
              color="success"
              prepend-icon="mdi-check-bold"
              :loading="revisionRunFinalizeLoading"
              :disabled="isProjectReadOnly || !canFinalizeRevisionRun"
              @click="finalizeRevisionRun"
            >
              Завершить
            </v-btn>
          </v-card-title>
          <v-card-text class="pt-0">
            <!-- Progress bar while processing -->
            <v-progress-linear
              v-if="activeRevisionRun.status === 'IN_PROGRESS' || activeRevisionRun.status === 'PENDING'"
              :model-value="activeRevisionRun.total_items > 0 ? Math.round(((activeRevisionRun.ok_items || 0) + (activeRevisionRun.failed_items || 0)) / activeRevisionRun.total_items * 100) : 0"
              color="primary"
              height="6"
              rounded
              class="mb-3"
              striped
            >
              <template #default="{ value }">
                <span class="text-caption">{{ value }}%</span>
              </template>
            </v-progress-linear>

            <v-alert
              v-if="activeRevisionRun.status === 'IN_PROGRESS' || activeRevisionRun.status === 'PENDING'"
              type="info"
              variant="tonal"
              density="compact"
              class="mb-3"
              :icon="false"
            >
              <div class="d-flex align-center ga-2">
                <v-progress-circular indeterminate size="16" width="2" color="info" />
                <span>Идёт сбор данных: обработано {{ (activeRevisionRun.ok_items || 0) + (activeRevisionRun.failed_items || 0) }} из {{ activeRevisionRun.total_items || 0 }} позиций</span>
              </div>
            </v-alert>

            <v-alert
              v-if="activeRevisionRun.status === 'NEEDS_MANUAL'"
              type="warning"
              variant="tonal"
              density="compact"
              class="mb-3"
            >
              Есть позиции без подтверждения цены. Закройте их вручную или повторите проверку.
            </v-alert>

            <v-table density="compact">
              <thead>
                <tr>
                  <th style="width:40px">№</th>
                  <th>Материал</th>
                  <th style="width:60px">Тип</th>
                  <th>Статус</th>
                  <th>Сообщение</th>
                  <th>Источник</th>
                  <th>Карточка</th>
                  <th class="text-right">Действия</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(runItem, idx) in revisionRunItems" :key="runItem.id">
                  <td class="text-medium-emphasis text-caption">{{ idx + 1 }}</td>
                  <td>{{ getRevisionRunItemName(runItem) }}</td>
                  <td>
                    <v-chip v-if="runItem.cost_driver_type" size="x-small" color="blue-grey" variant="tonal">
                      {{ formatCostDriverType(runItem.cost_driver_type) }}
                    </v-chip>
                    <span v-else class="text-medium-emphasis">—</span>
                  </td>
                  <td>
                    <div class="d-flex align-center ga-1">
                      <template v-if="runItem.status === 'PENDING'">
                        <v-progress-circular indeterminate size="14" width="2" color="primary" />
                        <span class="text-caption text-medium-emphasis">обработка...</span>
                      </template>
                      <template v-else>
                        <v-chip size="x-small" :color="getRunItemStatusColor(runItem.status)" variant="tonal">
                          {{ formatRunItemStatus(runItem.status) }}
                        </v-chip>
                        <v-tooltip v-if="runItem.resolved_capture_source" location="top">
                          <template #activator="{ props }">
                            <v-icon v-bind="props" :icon="getResolutionIcon(runItem.resolved_capture_source)" size="14" />
                          </template>
                          {{ getResolutionLabel(runItem.resolved_capture_source) }}
                        </v-tooltip>
                        <v-tooltip v-if="runItem.has_evidence" location="top">
                          <template #activator="{ props }">
                            <v-icon
                              v-bind="props"
                              icon="mdi-camera"
                              size="14"
                              color="success"
                              style="cursor:pointer"
                              @click.stop="openEvidenceDetail(runItem)"
                            />
                          </template>
                          Показать обоснование
                        </v-tooltip>
                      </template>
                    </div>
                  </td>
                  <td>{{ runItem.message || '—' }}</td>
                  <td>
                    <a
                      v-if="runItem.source_url"
                      :href="runItem.source_url"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      ссылка
                    </a>
                    <span v-else>—</span>
                  </td>
                  <td>
                    <a
                      v-if="runItem.material_id"
                      :href="getMaterialCatalogLink(runItem.material_id)"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      материал
                    </a>
                    <span v-else>—</span>
                  </td>
                  <td class="text-right">
                    <v-btn
                      v-if="isFacadeRevisionRunItem(runItem)"
                      size="x-small"
                      color="primary"
                      variant="tonal"
                      prepend-icon="mdi-folder-image"
                      class="mr-1"
                      @click="openFacadePricingFromRunItem(runItem)"
                    >
                      Доказательства фасада
                    </v-btn>
                    <v-btn
                      size="x-small"
                      color="primary"
                      variant="text"
                      prepend-icon="mdi-pencil"
                      :disabled="isProjectReadOnly || runItem.status === 'OK'"
                      @click="openManualCloseDialog(runItem)"
                    >
                      Ручное закрытие
                    </v-btn>
                  </td>
                </tr>
                <tr v-if="revisionRunItems.length === 0">
                  <td colspan="8" class="text-center py-4 text-medium-emphasis">
                    Нет позиций, требующих обоснования.
                  </td>
                </tr>
              </tbody>
            </v-table>

            <div v-if="revisionRunPdfLinks" class="mt-3 d-flex align-center flex-wrap ga-2">
              <v-chip size="small" color="success" variant="tonal">Ревизия создана</v-chip>
              <v-btn
                size="small"
                variant="outlined"
                prepend-icon="mdi-file-pdf-box"
                @click="openPdfLink(revisionRunPdfLinks.smeta)"
              >
                Сметный PDF
              </v-btn>
              <v-btn
                size="small"
                variant="outlined"
                prepend-icon="mdi-file-document-outline"
                @click="openPdfLink(revisionRunPdfLinks.price_justification)"
              >
                PDF обоснований
              </v-btn>
            </div>
              </v-card-text>
            </v-card>
          </div>
        </section>

        <section class="dense-module-surface">
          <div class="dense-module-actions-bar">
            <div class="dense-module-actions-bar__actions">
              <v-btn color="secondary" @click="generatePdf" :loading="pdfLoading" :disabled="isProjectReadOnly || pdfLoading || estimateHardInvalid">
                Генерировать PDF
              </v-btn>
            </div>
          </div>

          <v-skeleton-loader v-if="revisionsLoading" type="table" />
          <div v-else class="dense-module-table-wrap">
            <v-data-table
              :items="revisions"
              :headers="revisionHeaders"
              item-key="id"
              class="dense-module-table"
            >
              <template v-slot:item.status="{ item }">
                <v-chip size="small" :color="getRevisionStatusColor(item.status)" variant="outlined">
                  {{ formatRevisionStatus(item.status) }}
                </v-chip>
              </template>
              <template v-slot:item.created_at="{ item }">
                {{ formatRevisionDate(item.created_at) }}
              </template>
              <template v-slot:item.created_by="{ item }">
                <span v-if="item.created_by?.name">{{ item.created_by.name }}</span>
                <span v-else-if="item.createdBy?.name">{{ item.createdBy.name }}</span>
                <span v-else>—</span>
              </template>
              <template v-slot:item.actions="{ item }">
                <div class="module-row-actions module-row-actions--wide">
                  <v-btn
                    size="x-small"
                    variant="text"
                    icon="mdi-eye"
                    title="Просмотр"
                    @click="openRevisionView(item)"
                  />
                  <v-btn
                    size="x-small"
                    variant="text"
                    icon="mdi-file-pdf-box"
                    title="PDF сметы"
                    :loading="revisionPdfLoading.has(item.number)"
                    :disabled="estimateHardInvalid || item.status === 'stale' || revisionPdfLoading.has(item.number)"
                    @click="downloadRevisionPdf(item)"
                  />
                  <v-btn
                    size="x-small"
                    variant="text"
                    icon="mdi-file-document-outline"
                    title="PDF обоснований цен"
                    :loading="revisionJustPdfLoading.has(item.number)"
                    :disabled="estimateHardInvalid || item.status === 'stale' || revisionJustPdfLoading.has(item.number)"
                    @click="downloadPriceJustificationPdf(item)"
                  />
                  <v-btn
                    size="x-small"
                    variant="text"
                    icon="mdi-cloud-upload"
                    title="Опубликовать"
                    :disabled="!canPublishRevision(item)"
                    @click="publishRevision(item)"
                  />
                  <v-btn
                    size="x-small"
                    variant="text"
                    icon="mdi-cloud-off-outline"
                    title="Снять публикацию"
                    :disabled="!canUnpublishRevision(item)"
                    @click="unpublishRevision(item)"
                  />
                </div>
              </template>
              <template v-slot:no-data>
                <div class="text-center py-6 text-grey">
                  Ревизии не найдены
                </div>
              </template>
            </v-data-table>
          </div>

          <div class="d-flex justify-end mt-2" v-if="revisionsPagination.lastPage > 1">
            <v-pagination
              v-model="revisionsPagination.page"
              :length="revisionsPagination.lastPage"
              @update:model-value="fetchRevisions"
            />
          </div>
        </section>
      </section>
    </div><!-- /MODULE: REVISIONS -->

    <!-- ======================== MODULE: EVIDENCE ======================== -->
    <div v-show="activeModule === 'evidence'" class="module-content">
      <div class="module-summary">
        <v-chip size="small" variant="tonal" color="primary" prepend-icon="mdi-shield-search">Контур доказательств</v-chip>
      </div>
      <section class="dense-module-shell">
        <div class="dense-module-hero">
          <div class="dense-module-hero__content">
            <div class="dense-module-hero__eyebrow">Подтверждения и трассировка</div>
            <div class="dense-module-hero__title-row">
              <div class="dense-module-hero__title">Доказательства</div>
            </div>
          </div>
        </div>

        <section class="dense-module-surface">
          <div class="dense-module-support-card">
            <EvidenceRunPanel :project-id="project.id" />
          </div>
        </section>
      </section>
    </div><!-- /MODULE: EVIDENCE -->

    <!-- ======================== MODULE: SETTINGS ======================== -->
    <div v-show="activeModule === 'settings'" class="module-content">
      <!-- Empty module area by design: entering this module opens ProjectSettingsModal immediately. -->
    </div><!-- /MODULE: SETTINGS -->

      </div><!-- /workspace-module-area -->
    </div><!-- /workspace-body -->

    <!-- Диалог просмотра ревизии -->
    <v-dialog v-model="revisionDialog" max-width="900">
      <v-card>
        <v-card-title>
          Ревизия #{{ selectedRevision?.number || '—' }}
        </v-card-title>
        <v-card-text>
          <div class="d-flex flex-wrap gap-3 mb-4">
            <v-chip size="small" :color="getRevisionStatusColor(selectedRevision?.status)" variant="outlined">
              {{ formatRevisionStatus(selectedRevision?.status) }}
            </v-chip>
            <v-chip size="small" variant="outlined">
              {{ formatRevisionDate(selectedRevision?.created_at) }}
            </v-chip>
            <v-chip size="small" variant="outlined">
              {{ selectedRevision?.snapshot_hash ? formatSnapshotHash(selectedRevision.snapshot_hash) : '—' }}
            </v-chip>
          </div>

          <div class="text-subtitle-2 mb-2">Снимок (только просмотр)</div>
          <v-expansion-panels variant="accordion">
            <v-expansion-panel>
              <v-expansion-panel-title>Показать JSON</v-expansion-panel-title>
              <v-expansion-panel-text>
                <pre>{{ selectedRevisionSnapshot }}</pre>
              </v-expansion-panel-text>
            </v-expansion-panel>
          </v-expansion-panels>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="revisionDialog = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="manualCloseDialog" max-width="640">
      <v-card>
        <v-card-title>Ручное закрытие позиции</v-card-title>
        <v-card-text>
          <div class="text-body-2 mb-3">
            Позиция: <strong>{{ manualCloseItem ? getRevisionRunItemName(manualCloseItem) : '—' }}</strong>
          </div>
          <v-alert
            v-if="manualCloseItem && isFacadeRevisionRunItem(manualCloseItem)"
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-3"
          >
            Для фасада доказательства добавляются в карточке источников цены фасада. Если подтверждение недоступно,
            откройте цены фасада и прикрепите файл, скриншот или ссылку к источнику.
            <div class="mt-2">
              <v-btn
                size="small"
                color="primary"
                variant="tonal"
                prepend-icon="mdi-folder-image"
                @click="openFacadePricingFromRunItem(manualCloseItem)"
              >
                Перейти к источникам и доказательствам
              </v-btn>
            </div>
          </v-alert>
          <v-text-field
            v-model.number="manualCloseForm.price_per_unit"
            type="number"
            min="0.01"
            step="0.01"
            label="Цена за единицу"
            variant="outlined"
            density="compact"
            class="mb-2"
          />
          <v-alert
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-2"
          >
            Подставлена последняя цена из расчётов. Обязательно проверьте актуальность цены перед сохранением.
          </v-alert>
          <v-text-field
            v-model="manualCloseForm.currency"
            label="Валюта"
            variant="outlined"
            density="compact"
            class="mb-2"
          />
          <v-text-field
            v-model="manualCloseForm.source_url"
            label="Ссылка на источник (опционально)"
            variant="outlined"
            density="compact"
            class="mb-2"
          />
          <div
            ref="manualScreenshotDropzone"
            class="manual-screenshot-dropzone mb-2"
            :class="{ 'manual-screenshot-dropzone--active': manualScreenshotDropActive }"
            tabindex="0"
            @dragenter.prevent="onManualScreenshotDragEnter"
            @dragover.prevent="onManualScreenshotDragOver"
            @dragleave.prevent="onManualScreenshotDragLeave"
            @drop.prevent="onManualScreenshotDrop"
            @paste="onManualScreenshotPaste"
          >
            <v-file-input
              v-model="manualCloseForm.screenshot_file"
              accept="image/*"
              label="Скриншот (обязательно)"
              variant="outlined"
              density="compact"
              prepend-icon="mdi-camera"
              show-size
              hide-details="auto"
              class="mb-1"
            />
            <div class="d-flex align-center justify-space-between ga-2 text-caption text-medium-emphasis">
              <span>Перетащите файл сюда или вставьте изображение из буфера (Ctrl+V)</span>
              <v-btn
                v-if="getManualScreenshotFile()"
                size="x-small"
                variant="text"
                color="warning"
                prepend-icon="mdi-close"
                @click="clearManualScreenshotFile"
              >
                Очистить
              </v-btn>
            </div>
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="manualCloseLoading" @click="manualCloseDialog = false">Отмена</v-btn>
          <v-btn color="primary" :loading="manualCloseLoading" @click="submitManualClose">
            Сохранить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Диалог деталей обоснования (Block D2) -->
    <v-dialog v-model="evidenceDetailDialog" max-width="700">
      <v-card v-if="evidenceDetailItem">
        <v-card-title class="d-flex align-center">
          <v-icon icon="mdi-shield-check" class="mr-2" />
          Детали обоснования
        </v-card-title>
        <v-card-text>
          <div class="text-body-2 mb-3">
            <strong>{{ getRevisionRunItemName(evidenceDetailItem) }}</strong>
            <v-chip v-if="evidenceDetailItem.cost_driver_type" size="x-small" color="blue-grey" variant="tonal" class="ml-2">
              {{ formatCostDriverType(evidenceDetailItem.cost_driver_type) }}
            </v-chip>
            <v-chip size="x-small" :color="getRunItemStatusColor(evidenceDetailItem.status)" variant="tonal" class="ml-1">
              {{ formatRunItemStatus(evidenceDetailItem.status) }}
            </v-chip>
          </div>

          <template v-if="evidenceDetailArtifact">
            <div v-if="evidenceDetailItem.resolved_capture_source" class="text-body-2 mb-2">
              <v-icon :icon="getResolutionIcon(evidenceDetailItem.resolved_capture_source)" size="16" class="mr-1" />
              {{ getResolutionLabel(evidenceDetailItem.resolved_capture_source) }}
            </div>

            <!-- Screenshot preview -->
            <template v-for="asset in evidenceDetailArtifact.assets" :key="asset.id">
              <div v-if="asset.mime_type && asset.mime_type.startsWith('image/')" class="mb-3">
                <div class="text-caption text-medium-emphasis mb-1">{{ asset.asset_type === 'document' ? 'Документ' : 'Скриншот' }}</div>
                <img
                  :src="evidenceAssetFileUrl(asset.id)"
                  :alt="asset.original_filename || 'screenshot'"
                  style="max-width:100%;max-height:400px;border:1px solid rgba(var(--v-border-color),var(--v-border-opacity));border-radius:4px"
                />
                <div class="text-caption text-medium-emphasis mt-1">
                  {{ asset.original_filename }}
                  <span v-if="asset.file_size">({{ Math.round(asset.file_size / 1024) }} КБ)</span>
                </div>
              </div>
              <div v-else-if="asset.asset_type === 'document'" class="mb-3">
                <div class="text-caption text-medium-emphasis mb-1">Документ</div>
                <v-chip
                  :href="evidenceAssetFileUrl(asset.id)"
                  target="_blank"
                  color="primary"
                  variant="tonal"
                  size="small"
                  prepend-icon="mdi-file-document-outline"
                >
                  {{ asset.original_filename || 'document' }}
                  <span v-if="asset.file_size" class="ml-1 text-caption">({{ Math.round(asset.file_size / 1024) }} КБ)</span>
                </v-chip>
              </div>
            </template>

            <!-- Price -->
            <div v-if="evidenceDetailArtifact.extracted_price" class="text-body-2 mb-2">
              <strong>Цена:</strong> {{ evidenceDetailArtifact.extracted_price }} {{ evidenceDetailArtifact.currency || '' }}
            </div>

            <!-- Trust score badge -->
            <div v-if="evidenceDetailArtifact.trust_score != null" class="text-body-2 mb-2">
              <v-chip
                size="small"
                variant="tonal"
                :color="evidenceDetailArtifact.trust_score >= 90 ? 'success' : evidenceDetailArtifact.trust_score >= 70 ? 'info' : evidenceDetailArtifact.trust_score >= 50 ? 'warning' : 'error'"
                :prepend-icon="evidenceDetailArtifact.trust_score >= 70 ? 'mdi-shield-check' : 'mdi-shield-alert'"
              >
                Достоверность: {{ evidenceDetailArtifact.trust_score }}%
              </v-chip>
            </div>

            <!-- Source URL -->
            <div v-if="evidenceDetailArtifact.source_url_raw" class="text-body-2 mb-2">
              <strong>Источник:</strong>
              <a :href="evidenceDetailArtifact.source_url_raw" target="_blank" rel="noopener noreferrer">
                {{ evidenceDetailArtifact.source_domain || evidenceDetailArtifact.source_url_raw }}
                <v-icon icon="mdi-open-in-new" size="12" />
              </a>
            </div>

            <!-- Timestamps -->
            <div v-if="evidenceDetailArtifact.captured_at" class="text-caption text-medium-emphasis mb-1">
              Дата захвата: {{ new Date(evidenceDetailArtifact.captured_at).toLocaleString('ru-RU') }}
            </div>
            <div v-if="evidenceDetailArtifact.created_at" class="text-caption text-medium-emphasis">
              Создано: {{ new Date(evidenceDetailArtifact.created_at).toLocaleString('ru-RU') }}
            </div>
          </template>

          <v-alert v-else type="info" variant="tonal" density="compact">
            Нет данных обоснования для этой позиции.
          </v-alert>

          <!-- Block C2: Expense document upload -->
          <template v-if="evidenceDetailItem?.cost_driver_type === 'expense' && activeRevisionRun && activeRevisionRun.status !== 'FINALIZED'">
            <v-divider class="my-3" />
            <div class="text-body-2 font-weight-medium mb-2">Прикрепить документ / чек</div>
            <div class="d-flex align-center ga-2">
              <v-file-input
                v-model="expenseDocFile"
                accept="image/jpeg,image/png,image/webp,application/pdf"
                label="Файл (JPEG, PNG, WebP, PDF)"
                variant="outlined"
                density="compact"
                prepend-icon="mdi-paperclip"
                show-size
                hide-details="auto"
                style="flex:1"
                :disabled="expenseDocLoading"
              />
              <v-btn
                color="primary"
                size="small"
                :loading="expenseDocLoading"
                :disabled="!expenseDocFile || (Array.isArray(expenseDocFile) && expenseDocFile.length === 0)"
                @click="submitExpenseDocument"
              >
                Загрузить
              </v-btn>
            </div>
          </template>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="evidenceDetailDialog = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Диалог позиции -->
    <v-dialog v-model="positionDialog" max-width="700" class="position-editor-dialog">
      <v-card class="position-sheet position-sheet--editor">
        <v-card-title class="position-sheet-title">{{ editingPosition ? 'Редактировать позицию' : 'Новая позиция' }}</v-card-title>
        <v-progress-linear
          v-if="positionDialogOpening"
          color="primary"
          indeterminate
          height="3"
        />
        <v-card-text class="position-sheet-content">
          <v-form ref="positionForm" class="position-form-stack">
            <!-- Переключатель типа позиции -->
            <section class="position-form-section position-form-section--hero">
              <div class="position-form-section__header">
                <div class="position-form-section__title">Тип позиции</div>
                <div class="position-form-section__text">Выберите контур редактирования: панель или фасад.</div>
              </div>
            <v-btn-toggle
              v-model="positionFormModel.kind"
              mandatory
              density="comfortable"
              color="primary"
              class="mb-4 position-kind-toggle"
            >
              <v-btn value="panel">
                <v-icon start>mdi-texture-box</v-icon>
                Панель
              </v-btn>
              <v-btn value="facade">
                <v-icon start>mdi-door</v-icon>
                Фасад
              </v-btn>
            </v-btn-toggle>
            </section>

            <!-- === PANEL MODE === -->
            <template v-if="positionFormModel.kind === 'panel'">
              <section class="position-form-section">
                <div class="position-form-section__header">
                  <div class="position-form-section__title">Материал панели</div>
                  <div class="position-form-section__text">Тип детали и основной плитный материал.</div>
                </div>
              <!-- Тип детали -->
              <v-autocomplete
                v-model="positionFormModel.detail_type_id"
                :items="detailTypes"
                item-title="name"
                item-value="id"
                label="Тип детали"
                clearable
                density="comfortable"
                @update:model-value="onDetailTypeChange"
                class="mb-3"
              >
                <template #append-inner>
                  <v-btn
                    icon="mdi-plus"
                    size="x-small"
                    variant="tonal"
                    color="primary"
                    @click.stop="openDetailTypesInNewTab"
                    title="Создать новый тип детали"
                  />
                </template>
                <template #append-item>
                  <v-list-item @click="$router.push('/detail-types')">
                    <v-list-item-title>Управление типами</v-list-item-title>
                  </v-list-item>
                </template>
              </v-autocomplete>

              <!-- Плитный материал -->
              <v-autocomplete
                v-model="positionFormModel.material_id"
                :items="materialsPlate"
                item-title="name"
                item-value="id"
                label="Плитный материал"
                :rules="[v => !!v || 'Обязательно']"
                density="comfortable"
                class="mb-3"
                :loading="positionDialogOpening || materialSearchLoading.plate"
                no-filter
                no-data-text="Введите минимум 2 символа для поиска"
                @update:search="onMaterialSearch('plate', $event)"
                @update:model-value="onPositionMaterialChange"
              >
                <template #item="{ props, item }">
                  <v-list-item v-bind="props">
                    <v-list-item-subtitle v-if="item.raw.origin === 'parser'" class="text-blue">
                      Системный (спарсено)
                    </v-list-item-subtitle>
                    <v-list-item-subtitle v-else class="text-grey">
                      Пользовательский
                    </v-list-item-subtitle>
                  </v-list-item>
                </template>
              </v-autocomplete>
              </section>
            </template>

            <!-- === FACADE MODE === -->
            <template v-if="positionFormModel.kind === 'facade'">
              <section class="position-form-section">
                <div class="position-form-section__header">
                  <div class="position-form-section__title">Материал фасада</div>
                  <div class="position-form-section__text">Выбор спецификации фасада и фиксация текущей расчетной цены.</div>
                </div>
              <!-- Facade specification selector -->
              <v-autocomplete
                v-model="positionFormModel.finished_product_specification_id"
                :items="facadeSpecifications"
                item-title="name"
                item-value="id"
                label="Спецификация фасада"
                :rules="[v => !!v || 'Выберите фасад']"
                density="comfortable"
                class="mb-3"
                :loading="loadingFacades"
                @update:search="onFacadeSearch"
                @update:model-value="onFacadeSpecificationChange"
                no-filter
              >
                <template #item="{ props, item }">
                  <v-list-item v-bind="props">
                    <v-list-item-subtitle>
                      {{ item.raw.thickness_mm }}мм | {{ item.raw.finish_name || '—' }} |
                      <span v-if="item.raw.price_per_m2" class="text-green">{{ formatNumber(item.raw.price_per_m2, 2) }} ₽/м²</span>
                      <span v-else class="text-red">Нет цены</span>
                    </v-list-item-subtitle>
                  </v-list-item>
                </template>
              </v-autocomplete>

              <!-- Auto-filled facade info (read-only) -->
              <div v-if="positionFormModel.finished_product_specification_id" class="position-support-card mb-3">
              <v-row class="mb-0">
                <v-col cols="4">
                  <v-text-field
                    :model-value="positionFormModel.base_material_label || '—'"
                    label="Основа"
                    readonly
                    density="comfortable"
                    variant="outlined"
                  />
                </v-col>
                <v-col cols="4">
                  <v-text-field
                    :model-value="positionFormModel.thickness_mm ? `${positionFormModel.thickness_mm} мм` : '—'"
                    label="Толщина"
                    readonly
                    density="comfortable"
                    variant="outlined"
                  />
                </v-col>
                <v-col cols="4">
                  <v-text-field
                    :model-value="positionFormModel.price_per_m2 ? `${formatNumber(positionFormModel.price_per_m2, 2)} ₽/м²` : '—'"
                    label="Цена за м²"
                    readonly
                    density="comfortable"
                    variant="outlined"
                  />
                </v-col>
              </v-row>
              <v-text-field
                v-if="positionFormModel.finished_product_specification_id"
                :model-value="positionFormModel.decor_label || '—'"
                label="Декор"
                readonly
                density="comfortable"
                variant="outlined"
                class="mb-3"
              />
              <div class="text-caption text-medium-emphasis">
                Текущая расчетная цена:
                {{ formatFacadePriceMethodLabel(positionFormModel.price_method) }}
                <span v-if="positionFormModel.price_sources_count != null">
                  · источников: {{ positionFormModel.price_sources_count }}
                </span>
                <span v-if="positionFormModel.price_min != null && positionFormModel.price_max != null">
                  · диапазон: {{ formatNumber(positionFormModel.price_min, 2) }} — {{ formatNumber(positionFormModel.price_max, 2) }} ₽/м²
                </span>
              </div>
              </div>
              </section>
            </template>

            <!-- Размеры (common for both types) -->
            <section class="position-form-section">
              <div class="position-form-section__header">
                <div class="position-form-section__title">Размеры и количество</div>
                <div class="position-form-section__text">Геометрия детали, быстрый выбор количества и контроль площади.</div>
              </div>
              <div
                v-if="!editingPosition && positionFormModel.kind === 'facade'"
                class="facade-allowance-row"
              >
                <v-switch
                  v-model="applyFacadeAllowance"
                  color="primary"
                  density="compact"
                  hide-details
                  label="Учитывать припуск"
                  :disabled="!hasFacadeAllowance"
                />
                <div class="text-caption text-medium-emphasis">
                  {{ hasFacadeAllowance ? facadeAllowanceHint : 'Припуски не заданы в настройках проекта.' }}
                </div>
              </div>
            <v-row class="position-dimensions-row">
              <v-col cols="12" sm="6" class="position-dimensions-field">
                <div class="dimension-field-stack">
                <v-text-field
                  :model-value="Math.round(positionFormModel.width || 0)"
                  :label="positionFormModel.kind === 'facade' ? 'Ширина, мм' : 'Ширина, мм'"
                  type="text"
                  :class="getEdgeSchemeHintClass('width')"
                  placeholder="Например: 600 или 600-32"
                  :rules="[v => !!v || 'Обязательно', v => (v >= 10 || typeof v === 'string') || 'Должно быть >= 10']"
                  density="comfortable"
                  @input="handleDialogDimensionTyped('width', $event)"
                  @keyup.enter="handleDialogDimensionInput('width', ($event.target as HTMLInputElement).value)"
                  @blur="handleDialogDimensionInput('width', ($event.target as HTMLInputElement).value)"
                />
                <div class="dimension-feedback-slot">
                  <div class="dimension-help text-caption text-medium-emphasis">
                    Допустимо: 600, 600-32, 2400/2
                  </div>
                  <div v-if="dialogDimensionCalc.width.expr" class="dimension-calc-line text-caption">
                    <span class="text-medium-emphasis">Введено:</span>
                    <span class="font-weight-medium">{{ dialogDimensionCalc.width.expr }}</span>
                    <span v-if="dialogDimensionCalc.width.error" class="text-error">→ {{ dialogDimensionCalc.width.error }}</span>
                    <span v-else class="text-primary">→ {{ dialogDimensionCalc.width.result }} мм</span>
                  </div>
                </div>
                </div>
              </v-col>
              <v-col cols="12" sm="6" class="position-dimensions-field">
                <div class="dimension-field-stack">
                <v-text-field
                  :model-value="Math.round(positionFormModel.length || 0)"
                  :label="positionFormModel.kind === 'facade' ? 'Высота, мм' : 'Длина, мм'"
                  type="text"
                  :class="getEdgeSchemeHintClass('length')"
                  placeholder="Например: 2400 или 2400/2"
                  :rules="[v => !!v || 'Обязательно', v => (v >=10 || typeof v === 'string') || 'Должно быть >= 10']"
                  density="comfortable"
                  @input="handleDialogDimensionTyped('length', $event)"
                  @keyup.enter="handleDialogDimensionInput('length', ($event.target as HTMLInputElement).value)"
                  @blur="handleDialogDimensionInput('length', ($event.target as HTMLInputElement).value)"
                />
                <div class="dimension-feedback-slot">
                  <div class="dimension-help text-caption text-medium-emphasis">
                    Допустимо: 600, 600-32, 2400/2
                  </div>
                  <div v-if="dialogDimensionCalc.length.expr" class="dimension-calc-line text-caption">
                    <span class="text-medium-emphasis">Введено:</span>
                    <span class="font-weight-medium">{{ dialogDimensionCalc.length.expr }}</span>
                    <span v-if="dialogDimensionCalc.length.error" class="text-error">→ {{ dialogDimensionCalc.length.error }}</span>
                    <span v-else class="text-primary">→ {{ dialogDimensionCalc.length.result }} мм</span>
                  </div>
                </div>
                </div>
              </v-col>
            </v-row>

            <v-row class="mb-3 align-center">
              <v-col cols="12" sm="3">
                <v-text-field
                  v-model.number="positionFormModel.quantity"
                  label="Количество"
                  type="number"
                  :min="1"
                  :rules="[v => v >= 1 || 'Минимум 1']"
                  density="comfortable"
                  hide-details="auto"
                />
              </v-col>
              <v-col cols="12" sm="9">
                <div class="quick-quantity-group-wrap">
                  <v-btn-group color="primary" variant="outlined">
                    <v-btn
                      v-for="qty in quickQuantityValues"
                      :key="qty"
                      :variant="Number(positionFormModel.quantity) === qty ? 'flat' : 'outlined'"
                      @click="setQuickQuantity(qty)"
                    >
                      {{ qty }}
                    </v-btn>
                  </v-btn-group>
                </div>
              </v-col>
            </v-row>

            <!-- Facade area preview -->
            <v-alert
              v-if="positionFormModel.kind === 'facade' && positionFormModel.width > 0 && positionFormModel.length > 0"
              type="info"
              variant="tonal"
              density="compact"
              class="mb-3"
            >
              Площадь: {{ formatNumber(((positionFormModel.width || 0) / 1000) * ((positionFormModel.length || 0) / 1000) * (positionFormModel.quantity || 0), 4) }} м²
              <template v-if="positionFormModel.price_per_m2">
                | Сумма: {{ formatNumber(((positionFormModel.width || 0) / 1000) * ((positionFormModel.length || 0) / 1000) * (positionFormModel.quantity || 0) * (positionFormModel.price_per_m2 || 0), 2) }} ₽
              </template>
            </v-alert>
            </section>

            <!-- Edge processing: only for panels -->
            <template v-if="positionFormModel.kind === 'panel'">
              <section class="position-form-section">
                <div class="position-form-section__header">
                  <div class="position-form-section__title">Кромка и схема торцов</div>
                  <div class="position-form-section__text">Назначение обработки торцов и материала кромки.</div>
                </div>
              <!-- Обработка торцов: отключена, если выбран тип -->
              <v-autocomplete
                v-model="positionFormModel.edge_scheme"
                :items="edgeSchemeOptions"
                item-title="label"
                item-value="value"
                label="Обработка торцов"
                :disabled="!!positionFormModel.detail_type_id"
                density="comfortable"
                class="mb-3"
                @update:model-value="onPositionEdgeSchemeChange"
              >
                <template #selection="{ item }">
                  <v-chip size="small">
                    <v-icon start size="16">{{ item.raw.icon }}</v-icon>
                    {{ item.raw.label }}
                  </v-chip>
                </template>
                <template #item="{ props, item }">
                  <v-list-item v-bind="props">
                    <template #prepend>
                      <v-icon>{{ item.raw.icon }}</v-icon>
                    </template>
                  </v-list-item>
                </template>
              </v-autocomplete>
              <div class="edge-preview-block mb-2">
                <div class="text-caption text-medium-emphasis mb-1">Визуализация кромки</div>
                <div class="edge-preview-diagram">
                  <div class="edge-preview-length-label">{{ formatEdgeDimension(positionFormModel.length, 'Длина') }}</div>
                  <div class="edge-preview-row">
                    <div class="edge-preview-width-label">{{ formatEdgeDimension(positionFormModel.width, 'Ширина') }}</div>
                    <div class="edge-preview-box" :style="getEdgePreviewStyle(positionFormModel.width, positionFormModel.length)">
                  <div class="edge-side top" :class="{ active: isEdgeSideActive(positionFormModel.edge_scheme, 'top') }"></div>
                  <div class="edge-side right" :class="{ active: isEdgeSideActive(positionFormModel.edge_scheme, 'right') }"></div>
                  <div class="edge-side bottom" :class="{ active: isEdgeSideActive(positionFormModel.edge_scheme, 'bottom') }"></div>
                  <div class="edge-side left" :class="{ active: isEdgeSideActive(positionFormModel.edge_scheme, 'left') }"></div>
                  <div class="edge-center-label">Деталь</div>
                    </div>
                  </div>
                </div>
                <div class="text-caption mt-1">{{ getEdgeSchemeSummary(positionFormModel.edge_scheme) }}</div>
              </div>
              <v-alert
                v-if="positionFormModel.edge_scheme && positionFormModel.edge_scheme !== 'none' && !positionFormModel.edge_material_id"
                type="warning"
                variant="tonal"
                density="compact"
                class="mb-3"
              >
                Для выбранной схемы назначьте материал кромки.
              </v-alert>

              <!-- Материал кромки (только если схема ≠ none) -->
              <v-autocomplete
                v-if="positionFormModel.edge_scheme && positionFormModel.edge_scheme !== 'none'"
                v-model="positionFormModel.edge_material_id"
                :items="materialsEdge"
                item-title="name"
                item-value="id"
                label="Материал кромки"
                :rules="[v => !!v || 'Обязательно']"
                density="comfortable"
                class="mb-3"
                :loading="positionDialogOpening || materialSearchLoading.edge"
                no-filter
                no-data-text="Введите минимум 2 символа для поиска"
                @update:search="onMaterialSearch('edge', $event)"
                @update:model-value="onPositionEdgeMaterialChange"
              />
              </section>
            </template>

            <!-- Название (опционально) -->
            <section class="position-form-section position-form-section--muted">
              <div class="position-form-section__header">
                <div class="position-form-section__title">Идентификация</div>
                <div class="position-form-section__text">Необязательное имя для удобства в таблице и деталях.</div>
              </div>
            <v-text-field
              v-model="positionFormModel.custom_name"
              label="Название детали (опционально)"
              hint="Например: «Полка левая»"
              persistent-hint
              density="comfortable"
            />
            </section>
          </v-form>
        </v-card-text>
        <v-card-actions class="position-sheet-actions">
          <v-spacer />
          <v-btn @click="positionDialog = false" :disabled="positionSaving">Отмена</v-btn>
          <v-btn
            color="primary"
            @click="savePosition"
            :loading="positionSaving"
            :disabled="isProjectReadOnly || positionSaving || (positionFormModel.kind === 'facade' && (!positionFormModel.finished_product_specification_id || !(Number(positionFormModel.price_per_m2) > 0)))"
          >Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Диалог фурнитуры (ручной ввод) -->
    <v-dialog v-model="fittingDialog" max-width="600">
      <v-card>
        <v-card-title>{{ editingFitting ? 'Редактировать фурнитуру' : 'Новая фурнитура' }}</v-card-title>
        <v-card-text>
          <v-autocomplete
            :model-value="fittingForm.material_id"
            :items="hardwareMaterials"
            item-title="name"
            item-value="id"
            label="Материал фурнитуры"
            density="comfortable"
            clearable
            required
            :loading="hardwareMaterialsLoading"
            no-filter
            @update:search="onFittingSearch"
            @update:model-value="onFittingMaterialChange"
          />
          <v-text-field v-model="fittingForm.article" label="Артикул" readonly />
          <v-autocomplete
            v-model="fittingForm.unit"
            :items="units"
            label="Ед. изм."
            :rules="[v => !!v || 'Обязательно']"
            density="comfortable"
            class="mb-3"
            readonly
          />
          <v-text-field v-model.number="fittingForm.quantity" label="Количество" type="number" />
          <v-text-field v-model.number="fittingForm.unit_price" label="Цена за шт, ₽" type="number" readonly />
          <v-text-field :model-value="selectedFittingMaterial?.source_url || '—'" label="Источник" readonly />
          <v-textarea v-model="fittingForm.note" label="Примечание (опционально)" rows="2" auto-grow />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="fittingDialog = false" :disabled="fittingSaving">Отмена</v-btn>
          <v-btn color="primary" @click="saveFitting" :loading="fittingSaving" :disabled="isProjectReadOnly || fittingSaving">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Диалог расхода (накладные расходы) -->
    <v-dialog v-model="expenseDialog" max-width="600">
      <v-card>
        <v-card-title>{{ editingExpense ? 'Редактировать расход' : 'Новый расход' }}</v-card-title>
        <v-card-text>
          <v-text-field 
            v-model="expenseForm.name" 
            label="Название" 
            placeholder="Например: Доставка, Монтаж, Консультация"
            required
            class="mb-3"
          />
          <v-textarea 
            v-model="expenseForm.description" 
            label="Описание" 
            placeholder="Детали расхода"
            rows="3"
            class="mb-3"
          />
          <v-text-field 
            v-model.number="expenseForm.amount" 
            label="Сумма, ₽" 
            type="number"
            step="0.01"
            min="0"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="expenseDialog = false" :disabled="expenseSaving">Отмена</v-btn>
          <v-btn color="primary" @click="saveExpense" :loading="expenseSaving" :disabled="isProjectReadOnly || expenseSaving">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Диалог редактирования источника нормо-часа -->
    <v-dialog v-model="normohourSourceDialog" max-width="700">
      <v-card>
        <v-card-title>
          {{ editingNormohourSource ? 'Редактировать источник' : 'Добавить источник ставки' }}
        </v-card-title>
        <v-card-text class="mt-4">
          <v-text-field
            v-model="normohourSourceForm.source"
            label="Источник *"
            variant="outlined"
            density="compact"
            placeholder="Например: hh.ru, Avito, Индекс зарплат, Опрос подрядчиков"
            counter="255"
            maxlength="255"
            :error="!!normohourSourceValidation.source"
            :error-messages="normohourSourceValidation.source ? [normohourSourceValidation.source] : []"
            class="mb-3"
          />
          <v-text-field
            v-model="normohourSourceForm.position_profile"
            label="Профиль должности"
            variant="outlined"
            density="compact"
            placeholder="Например: Сборщик мебели, Плотник категории 3, Монтажник"
            counter="255"
            maxlength="255"
            class="mb-3"
          />
          <v-text-field
            v-model="normohourSourceForm.salary_range"
            label="Зарплата/вилка"
            variant="outlined"
            density="compact"
            placeholder="Например: 1000-1200 ₽/ч или 75000-85000 ₽/месяц"
            counter="255"
            maxlength="255"
            class="mb-3"
          />
          <v-text-field
            v-model="normohourSourceForm.period"
            label="Период"
            variant="outlined"
            density="compact"
            placeholder="Например: января 2026, квартал 4 2025"
            counter="50"
            maxlength="50"
            class="mb-3"
          />
          <v-text-field
            v-model="normohourSourceForm.link"
            label="Ссылка на источник"
            variant="outlined"
            density="compact"
            placeholder="https://hh.ru/..."
            counter="1000"
            maxlength="1000"
            @blur="normohourSourceForm.link = normohourSourceForm.link ? normohourSourceForm.link.trim() : null"
            :error="!!normohourSourceValidation.link"
            :error-messages="normohourSourceValidation.link ? [normohourSourceValidation.link] : []"
            class="mb-3"
          />
          <v-textarea
            v-model="normohourSourceForm.note"
            label="Примечание"
            variant="outlined"
            rows="2"
            placeholder="Дополнительная информация о источнике"
            counter="1000"
            maxlength="1000"
            class="mb-2"
          />
          <div v-if="normohourSourceForm.note" class="text-caption text-grey mb-3">
            Символов: {{ normohourSourceForm.note.length }}/1000
          </div>
          <div class="text-caption text-orange">
            * — поле обязательно
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="closeNormohourSourceDialog" :disabled="normohourSourceSaving">Отмена</v-btn>
          <v-btn color="primary" @click="saveNormohourSource" :loading="normohourSourceSaving" :disabled="isProjectReadOnly || normohourSourceSaving">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="laborCalculationDialog" max-width="1120" scrollable class="labor-calculation-dialog">
      <v-card class="labor-calculation-dialog-card">
        <v-toolbar flat class="labor-calculation-dialog-toolbar">
          <v-toolbar-title>Расчёт нормо-часа</v-toolbar-title>
          <v-spacer />
          <v-btn icon="mdi-close" variant="text" @click="laborCalculationDialog = false" />
        </v-toolbar>
        <v-divider />
        <v-card-text class="labor-calculation-dialog-content">
          <ProjectLaborCalculationPanel
            ref="laborCalculationPanelRef"
            :project-id="project.id"
          />
        </v-card-text>
      </v-card>
    </v-dialog>
  </div><!-- /workspace-root -->

  <!-- Диалог ручной операции -->
  <v-dialog v-model="operationDialog" max-width="600">
  <v-card>
    <v-card-title>{{ editingOperation ? 'Редактировать операцию' : 'Новая операция' }}</v-card-title>
    <v-card-text>
      <v-autocomplete
        v-model="operationForm.operation_id"
        :items="allOperations"
        item-title="name"
        item-value="id"
        label="Операция"
        :rules="[v => !!v || 'Обязательно']"
        density="comfortable"
        return-object
        class="mb-3"
      >
        <template #item="{ props, item }">
          <v-list-item v-bind="props">
            <v-list-item-subtitle>{{ item.raw.category }}</v-list-item-subtitle>
          </v-list-item>
        </template>
      </v-autocomplete>

      <v-text-field
        v-model.number="operationForm.quantity"
        label="Количество"
        type="number"
        min="0.01"
        step="0.01"
        density="comfortable"
        class="mb-3"
      />

      <v-text-field
        v-model="operationForm.note"
        label="Примечание (опционально)"
        density="comfortable"
      />
    </v-card-text>
    <v-card-actions>
      <v-spacer />
      <v-btn @click="operationDialog = false" :disabled="operationSaving">Отмена</v-btn>
      <v-btn color="primary" @click="saveOperation" :loading="operationSaving" :disabled="isProjectReadOnly || operationSaving">Сохранить</v-btn>
    </v-card-actions>
  </v-card>
</v-dialog>

<!-- Диалог добавления/редактирования монтажно-сборочной работы -->
<v-dialog v-model="laborWorkDialog" max-width="600">
  <v-card>
    <v-card-title>{{ editingLaborWork ? 'Редактировать работу' : 'Добавить работу' }}</v-card-title>
    <v-card-text>
      <v-form ref="laborWorkFormRef" @submit.prevent="saveLaborWork">
        <!-- Наименование -->
        <v-text-field
          v-model="laborWorkForm.title"
          label="Наименование работы"
          :rules="[
            v => !!v || 'Обязательно',
            v => (v && v.length <= 255) || 'Максимум 255 символов'
          ]"
          density="comfortable"
          class="mb-3"
        />

        <!-- Основание (ГОСТ/ТУ) -->
        <v-text-field
          v-model="laborWorkForm.basis"
          label="Основание (ГОСТ/ТУ)"
          placeholder="Например: п. 5.2.4 ГОСТ 16371-2014"
          :rules="[
            v => !v || (v.length <= 500) || 'Максимум 500 символов'
          ]"
          density="comfortable"
          class="mb-3"
        />

        <!-- Норма часов -->
        <v-text-field
          v-model.number="laborWorkForm.hours"
          label="Норма, ч"
          type="number"
          min="0"
          step="0.25"
          :readonly="editingLaborWork && editingLaborWork.hours_source === 'from_steps'"
          :rules="[
            v => v !== null && v !== undefined && v !== '' || 'Обязательно',
            v => v >= 0 || 'Не может быть меньше 0',
            v => v <= 999.99 || 'Не может быть больше 999.99'
          ]"
          density="comfortable"
          class="mb-3"
        >
          <template v-slot:message v-if="editingLaborWork && editingLaborWork.hours_source === 'from_steps'">
            ⚠️ Поле заблокировано. Часы рассчитываются из подопераций. Отредактируйте их в "Детализации".
          </template>
        </v-text-field>

        <v-alert
          v-if="laborWorkSubmitError"
          type="error"
          variant="tonal"
          class="mb-3"
        >
          {{ laborWorkSubmitError }}
        </v-alert>

        <v-alert
          v-if="laborProfiles.length === 0"
          type="warning"
          variant="tonal"
          class="mb-3"
        >
          Создайте профиль работ в разделе Pricing → Labor
        </v-alert>

        <!-- Профиль работ -->
        <v-select
          v-model="laborWorkForm.labor_profile_id"
          label="Профиль работ *"
          :items="laborProfiles"
          item-title="title"
          item-value="id"
          placeholder="Выберите профиль работ..."
          density="comfortable"
          class="mb-3"
          :error-messages="laborWorkProfileErrors"
          :rules="[
            v => !!v || 'Выберите профиль работ'
          ]"
        />

        <!-- Примечание -->
        <v-textarea
          v-model="laborWorkForm.note"
          label="Примечание (опционально)"
          placeholder="Что включено в работу, дополнительные условия..."
          hint="💡 Используется AI при генерации этапов — чем подробнее описание, тем точнее декомпозиция"
          persistent-hint
          :rules="[
            v => !v || (v.length <= 5000) || 'Максимум 5000 символов'
          ]"
          rows="3"
          density="comfortable"
          class="mb-3"
        />

        <v-alert v-if="laborWorkForm.hours" type="info" variant="tonal" class="mb-3">
          Фактическая сумма будет рассчитана после назначения профиля и применения ставки по этому профилю.
        </v-alert>
      </v-form>
    </v-card-text>
    <v-card-actions>
      <v-spacer />
      <v-btn @click="laborWorkDialog = false" :disabled="laborWorkSaving">Отмена</v-btn>
      <v-btn color="primary" @click="saveLaborWork" :loading="laborWorkSaving" :disabled="isProjectReadOnly || laborWorkSaving">Сохранить</v-btn>
    </v-card-actions>
  </v-card>
</v-dialog>

<!-- Улучшенная модалка для подопераций (Детализация) -->
<v-dialog
  v-model="stepsDialog"
  max-width="1000"
  scrollable
  transition="dialog-bottom-transition"
>
  <v-card class="steps-dialog-card">
    <!-- Header -->
    <div class="steps-dialog-header">
      <div class="header-info">
        <div class="header-title">
          <v-icon size="20" class="mr-2">mdi-format-list-checks</v-icon>
          Детализация работы
        </div>
        <div class="header-subtitle">{{ selectedLaborWork?.title }}</div>
      </div>
      
      <div class="header-stats">
        <div class="stat-item">
          <span class="stat-value">{{ filteredSteps.length }}</span>
          <span class="stat-label">{{ filteredSteps.length === 1 ? 'этап' : 'этапов' }}</span>
        </div>
        <div class="stat-divider"></div>
        <div class="stat-item primary">
          <span class="stat-value">{{ totalStepsHours.toFixed(2) }}</span>
          <span class="stat-label">часов</span>
        </div>
      </div>

      <v-btn icon variant="text" @click="closeStepsDialog" class="close-btn">
        <v-icon>mdi-close</v-icon>
      </v-btn>
    </div>

    <v-divider />

    <!-- Main content: split layout -->
    <div class="steps-dialog-body">
      <!-- Left: List -->
      <div class="steps-list-panel">
        <!-- Search -->
        <div class="list-toolbar">
          <v-text-field
            v-model="stepsSearch"
            placeholder="Поиск..."
            prepend-inner-icon="mdi-magnify"
            density="compact"
            variant="solo-filled"
            flat
            hide-details
            clearable
            class="search-field"
          />
        </div>

        <!-- List content -->
        <div class="list-content">
          <div v-if="filteredSteps.length === 0 && !stepsSearch" class="empty-state">
            <v-icon size="48" color="grey-lighten-1">mdi-clipboard-list-outline</v-icon>
            <div class="empty-title">Этапов пока нет</div>
            <div class="empty-text">Добавьте первый этап работы, чтобы детализировать нормо-часы</div>
          </div>

          <div v-else-if="filteredSteps.length === 0 && stepsSearch" class="empty-state">
            <v-icon size="48" color="grey-lighten-1">mdi-magnify</v-icon>
            <div class="empty-title">Ничего не найдено</div>
            <div class="empty-text">Попробуйте изменить запрос</div>
          </div>

          <template v-else>
            <!-- Steps list with drag-and-drop (compact) -->
            <div class="steps-compact-list">
              <div
                v-for="(step, idx) in filteredSteps"
                :key="step.id"
                class="step-item"
                :class="{ 
                  'step-dragging': draggedStepId === step.id,
                  'step-editing': editingStepId === step.id 
                }"
                draggable="true"
                @dragstart="onDragStart(step)"
                @dragend="onDragEnd"
                @dragover.prevent
                @drop="onDrop(step)"
                @click="editStep(step)"
              >
                <div class="step-index">
                  <v-icon class="drag-handle" size="16">mdi-drag</v-icon>
                  <span class="step-num">{{ idx + 1 }}</span>
                </div>

                <div class="step-content">
                  <div class="step-title">{{ step.title }}</div>
                  <div class="step-meta" v-if="step.input_data || step.basis">
                    <span v-if="step.input_data" class="meta-item" :title="step.input_data">
                      <v-icon size="12">mdi-cube-outline</v-icon>
                      {{ step.input_data }}
                    </span>
                    <span v-if="step.basis" class="meta-item" :title="step.basis">
                      <v-icon size="12">mdi-book-open-page-variant-outline</v-icon>
                      {{ step.basis }}
                    </span>
                  </div>
                </div>

                <div class="step-hours">
                  {{ toHours(step.hours) }} ч
                </div>

                <div class="step-actions">
                  <v-btn
                    icon
                    variant="text"
                    density="compact"
                    size="small"
                    color="error"
                    @click.stop="deleteStep(step)"
                    title="Удалить"
                  >
                    <v-icon size="18">mdi-delete-outline</v-icon>
                  </v-btn>
                </div>
              </div>
            </div>
          </template>
        </div>
      </div>

      <!-- Right: Form -->
      <div class="steps-form-panel">
        <!-- AI Assistant Block -->
        <div class="ai-assistant-block">
          <div class="form-header ai-header">
            <v-icon size="20" class="mr-2" color="purple">mdi-robot-outline</v-icon>
            <span>AI помощник</span>
            <v-spacer />
            <v-chip 
              v-if="aiSuggestion && aiSuggestion.status" 
              size="x-small" 
              :color="aiSuggestion.status === 'verified' ? 'success' : aiSuggestion.status === 'candidate' ? 'info' : 'warning'"
              variant="flat"
            >
              {{ aiSuggestion.status === 'verified' ? 'Проверено' : aiSuggestion.status === 'candidate' ? 'Рекомендовано' : 'Черновик AI' }}
            </v-chip>
          </div>

          <!-- Context selects -->
          <div class="ai-context-grid">
            <v-select
              v-model="aiContext.domain"
              :items="aiDomainOptions"
              label="Область"
              density="compact"
              variant="outlined"
              hide-details
              clearable
            />
            <v-select
              v-model="aiContext.action_type"
              :items="aiActionTypeOptions"
              label="Тип действия"
              density="compact"
              variant="outlined"
              hide-details
              clearable
            />
            <v-select
              v-model="aiContext.constraints"
              :items="aiConstraintsOptions"
              label="Условия"
              density="compact"
              variant="outlined"
              hide-details
              clearable
            />
            <v-select
              v-model="aiContext.site_state"
              :items="aiSiteStateOptions"
              label="Состояние объекта"
              density="compact"
              variant="outlined"
              hide-details
              clearable
            />
          </div>

          <!-- Optional fields toggle -->
          <v-expand-transition>
            <div v-if="showAiOptionalFields" class="ai-optional-fields">
              <v-text-field
                v-model="aiContext.material"
                label="Материал"
                density="compact"
                variant="outlined"
                hide-details
                placeholder="ЛДСП, МДФ..."
              />
              <v-text-field
                v-model="aiContext.object_type"
                label="Тип объекта"
                density="compact"
                variant="outlined"
                hide-details
                placeholder="Шкаф, кухня..."
              />
            </div>
          </v-expand-transition>

          <div class="ai-options-row">
            <v-btn
              variant="text"
              size="x-small"
              @click="showAiOptionalFields = !showAiOptionalFields"
            >
              {{ showAiOptionalFields ? 'Скрыть' : 'Больше опций' }}
            </v-btn>
            <v-spacer />
            <v-text-field
              v-model.number="aiDesiredHours"
              type="number"
              min="0"
              step="0.5"
              label="Желаемые часы"
              density="compact"
              variant="outlined"
              hide-details
              style="max-width: 120px"
            />
          </div>

          <v-btn
            color="purple"
            variant="flat"
            block
            prepend-icon="mdi-auto-fix"
            :loading="aiLoading"
            :disabled="isProjectReadOnly || !selectedLaborWork?.title"
            :title="readOnlyActionTitle || 'Сгенерировать этапы'"
            @click="generateAiSteps"
          >
            Сгенерировать этапы (AI)
          </v-btn>

          <!-- AI Preview Panel -->
          <v-expand-transition>
            <div v-if="aiSuggestion && aiSuggestion.steps && !aiLoading" class="ai-preview-panel">
              <div class="ai-preview-header">
                <div class="ai-preview-header-left">
                  <v-checkbox
                    :model-value="aiAllSelected"
                    :indeterminate="aiSelectedCount > 0 && !aiAllSelected"
                    density="compact"
                    hide-details
                    class="ai-select-all-cb"
                    @update:model-value="toggleAiSelectAll"
                  />
                  <span class="ai-preview-title">Предложение AI</span>
                </div>
                <div class="ai-preview-header-right">
                  <v-chip size="x-small" :color="aiSelectedCount > 0 ? 'primary' : 'default'" variant="tonal">
                    {{ aiSelectedCount }}/{{ aiSuggestion.steps.length }} — {{ aiSelectedHours.toFixed(2) }} ч
                  </v-chip>
                </div>
              </div>
              
              <div class="ai-preview-list">
                <div 
                  v-for="(step, idx) in aiSuggestion.steps" 
                  :key="idx" 
                  class="ai-preview-item"
                  :class="{ 'ai-preview-item-unselected': !aiSelectedSteps.has(idx) }"
                  @click="toggleAiStepSelection(idx)"
                >
                  <v-checkbox
                    :model-value="aiSelectedSteps.has(idx)"
                    density="compact"
                    hide-details
                    class="ai-step-cb"
                    @click.stop
                    @update:model-value="toggleAiStepSelection(idx)"
                  />
                  <span class="ai-preview-num">{{ idx + 1 }}.</span>
                  <span class="ai-preview-text">{{ step.title }}</span>
                  <span class="ai-preview-hours">{{ step.hours }} ч</span>
                </div>
              </div>

              <div class="ai-preview-actions">
                <v-btn
                  color="success"
                  variant="flat"
                  size="small"
                  prepend-icon="mdi-swap-horizontal"
                  :loading="aiApplying"
                  :disabled="isProjectReadOnly || aiSelectedCount === 0"
                  :title="readOnlyActionTitle || 'Заменить этапы'"
                  @click="applyAiSteps('replace')"
                >
                  {{ aiSelectedCount === aiSuggestion.steps.length ? 'Заменить всё' : `Заменить (${aiSelectedCount})` }}
                </v-btn>
                <v-btn
                  color="primary"
                  variant="outlined"
                  size="small"
                  prepend-icon="mdi-plus"
                  :loading="aiApplying"
                  :disabled="isProjectReadOnly || aiSelectedCount === 0"
                  :title="readOnlyActionTitle || 'Добавить этапы'"
                  @click="applyAiSteps('append')"
                >
                  {{ aiSelectedCount === aiSuggestion.steps.length ? 'Добавить все' : `Добавить (${aiSelectedCount})` }}
                </v-btn>
                <v-btn
                  variant="text"
                  size="small"
                  @click="aiSuggestion = null"
                >
                  Отмена
                </v-btn>
              </div>
            </div>
          </v-expand-transition>
        </div>

        <v-divider class="my-3" />

        <div class="form-header">
          <v-icon size="20" class="mr-2" :color="editingStepId ? 'warning' : 'success'">
            {{ editingStepId ? 'mdi-pencil' : 'mdi-plus-circle' }}
          </v-icon>
          <span>{{ editingStepId ? 'Редактирование' : 'Новый этап' }}</span>
          <v-spacer />
          <v-btn
            v-if="editingStepId"
            variant="text"
            size="small"
            @click="cancelEdit"
          >
            Отменить
          </v-btn>
        </div>

        <v-form @submit.prevent="saveStep" ref="stepFormRef" class="step-form">
          <!-- Title - главное поле -->
          <div class="form-field">
            <label class="field-label required">Наименование этапа</label>
            <v-textarea
              v-model="stepForm.title"
              placeholder="Например: Демонтаж холодильника"
              :rules="[v => !!v || 'Обязательно']"
              variant="outlined"
              density="compact"
              rows="2"
              auto-grow
              hide-details="auto"
              autofocus
            />
          </div>

          <!-- Hours + Input data row -->
          <div class="form-row">
            <div class="form-field time-field">
              <label class="field-label required">Время</label>
              <v-text-field
                v-model.number="stepForm.hours"
                type="number"
                min="0"
                step="0.25"
                inputmode="decimal"
                suffix="ч"
                :rules="[
                  v => v !== null && v !== undefined && v !== '' || 'Обязательно',
                  v => Number(v) > 0 || 'Больше 0'
                ]"
                variant="outlined"
                density="compact"
                hide-details="auto"
              />
            </div>

            <div class="form-field flex-grow">
              <label class="field-label">Объём / входные данные</label>
              <v-text-field
                v-model="stepForm.input_data"
                placeholder="1 шт., 6 модулей, 2.5 м²..."
                variant="outlined"
                density="compact"
                hide-details
              />
            </div>
          </div>

          <!-- Basis -->
          <div class="form-field">
            <label class="field-label">Основание</label>
            <v-text-field
              v-model="stepForm.basis"
              placeholder="ГОСТ, СНиП, методика..."
              variant="outlined"
              density="compact"
              hide-details
            />
          </div>

          <!-- Note - collapsible -->
          <v-expand-transition>
            <div class="form-field" v-show="showNoteField || stepForm.note">
              <label class="field-label">Примечание</label>
              <v-textarea
                v-model="stepForm.note"
                placeholder="Особые условия, ограничения..."
                rows="2"
                auto-grow
                variant="outlined"
                density="compact"
                hide-details
              />
            </div>
          </v-expand-transition>

          <div class="form-toggle" v-if="!showNoteField && !stepForm.note">
            <v-btn
              variant="text"
              size="small"
              prepend-icon="mdi-plus"
              @click="showNoteField = true"
            >
              Добавить примечание
            </v-btn>
          </div>

          <!-- Actions -->
          <div class="form-actions">
            <v-btn
              variant="text"
              size="small"
              @click="resetStepForm"
              :disabled="savingStep"
            >
              Очистить
            </v-btn>

            <v-btn
              type="submit"
              :color="editingStepId ? 'warning' : 'success'"
              :prepend-icon="editingStepId ? 'mdi-check' : 'mdi-plus'"
              :loading="savingStep"
              variant="flat"
            >
              {{ editingStepId ? 'Сохранить' : 'Добавить' }}
            </v-btn>
          </div>
        </v-form>
      </div>
    </div>

    <!-- Footer -->
    <v-divider />
    <div class="steps-dialog-footer">
      <div class="footer-summary">
        <v-icon color="primary" size="20">mdi-sigma</v-icon>
        <span class="summary-label">Итого:</span>
        <span class="summary-value">{{ totalStepsHours.toFixed(2) }} ч</span>
        <span class="summary-hint" v-if="selectedLaborWork?.hours !== totalStepsHours">
          (в смете: {{ selectedLaborWork?.hours }} ч)
        </span>
      </div>

      <v-spacer />

      <v-btn variant="outlined" @click="closeStepsDialog">
        Закрыть
      </v-btn>
    </div>
  </v-card>
</v-dialog>

    <!-- Confirm delete dialog -->
    <v-dialog v-model="deleteDialog" max-width="420">
      <v-card>
        <v-card-title>Удалить подоперацию?</v-card-title>
        <v-card-text class="text-medium-emphasis">
          {{ stepToDelete?.title }}
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="deleteDialog = false">Отмена</v-btn>
          <v-btn color="error" @click="deleteStepConfirmed">Удалить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>


<!-- Snackbar для уведомлений -->
<v-snackbar
  v-model="snackbar.show"
  :timeout="snackbar.timeout"
  :color="snackbar.color"
  location="bottom right"
>
  {{ snackbar.message }}
</v-snackbar>
</template>


<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, computed, watch, reactive, nextTick } from 'vue'
import { useDisplay } from 'vuetify'
import { useRoute, useRouter } from 'vue-router'
import api from '@/api/axios'
import { finishedProductSpecificationsApi, type FinishedProductSpecification } from '@/api/finishedProductSpecifications'
import laborWorksApi, { type LaborWork } from '@/api/laborWorks'
import { revisionRunApi, evidenceAssetFileUrl, type RevisionRun, type RevisionRunItem, type EvidenceArtifactDetail } from '@/api/revisionRun'
import { consumePrefetchedProject, setProjectsFlashMessage } from '@/router/projectAccess'
import ProjectSettingsModal from '@/components/ProjectSettingsModal.vue'
import ImportPositionsDialog from '@/components/ImportPositionsDialog.vue'
import RowHoverActions, { type RowAction } from '@/components/RowHoverActions.vue'
import WorkspaceHeader from '@/components/workspace/WorkspaceHeader.vue'
import WorkspaceSidebar, { type SidebarModule } from '@/components/workspace/WorkspaceSidebar.vue'
import ProjectHealthBar, { type HealthIssue } from '@/components/workspace/ProjectHealthBar.vue'
import EvidenceRunPanel from '@/components/evidence/EvidenceRunPanel.vue'
import ProjectLaborEvidencePanel from '@/components/project/ProjectLaborEvidencePanel.vue'
import ProjectLaborCalculationPanel from '@/components/project/ProjectLaborCalculationPanel.vue'

const { smAndDown } = useDisplay()
const compactLayout = computed(() => smAndDown.value)

// === Workspace Module Navigation ===
const activeModule = ref<string>(
  localStorage.getItem('editor_active_module') || 'positions'
)
watch(activeModule, (val) => {
  localStorage.setItem('editor_active_module', val)
  // Open settings drawer when navigating to settings module
  if (val === 'settings') {
    settingsDrawer.value = true
  }
})

// Forward-declared refs for workspace computeds (actual declarations below)
// Vue <script setup> hoists all declarations, computed callbacks are lazy

const handleHealthNavigate = (target: string) => {
  activeModule.value = target
}

// === Типы ===
interface Project { 
  id: number
  public_id?: string | null
  number: string
  expert_name: string
  address: string
  region_id?: number | null
  waste_coefficient: number
  repair_coefficient: number
  use_area_calc_mode?: boolean
  waste_plate_coefficient?: number | null
  waste_edge_coefficient?: number | null
  waste_operations_coefficient?: number | null
  apply_waste_to_plate?: boolean
  apply_waste_to_edge?: boolean
  apply_waste_to_operations?: boolean
  default_plate_material_id?: number | null
  default_edge_material_id?: number | null
  facade_width_allowance_mm?: number | null
  facade_height_allowance_mm?: number | null
  text_blocks?: TextBlock[]
  waste_plate_description?: CoefficientDescription | null
  show_waste_plate_description?: boolean
  waste_edge_description?: CoefficientDescription | null
  show_waste_edge_description?: boolean
  waste_operations_description?: CoefficientDescription | null
  show_waste_operations_description?: boolean
  normohour_rate?: number | null
  normohour_region?: string | null
  normohour_date?: string | null
  normohour_method?: string | null
  normohour_justification?: string | null
  billing_workspace?: {
    read_only: boolean
    reason?: string | null
    owned_projects?: number
    active_projects?: number
    limit?: number | null
    message?: string | null
  }
}
interface CoefficientDescription {
  title: string
  text: string
}
interface TextBlock {
  title: string
  text: string
  enabled?: boolean
}

interface NormohourSource {
  id?: number
  project_id?: number
  source: string
  position_profile?: string | null
  salary_range?: string | null
  period?: string | null
  link?: string | null
  note?: string | null
}
interface Position {
  id: number | null
  project_id: string
  kind: 'panel' | 'facade'
  detail_type_id: number | null
  material_id: number | null
  facade_material_id: number | null
  finished_product_specification_id: number | null
  material_price_id: number | null
  edge_material_id: number | null
  edge_scheme: string
  width: number
  length: number
  quantity: number
  custom_name: string | null
  unit_price?: number | null
  // Facade fields
  decor_label?: string | null
  thickness_mm?: number | null
  base_material_label?: string | null
  finish_type?: string | null
  finish_name?: string | null
  price_per_m2?: number | null
  area_m2?: number | null
  total_price?: number | null
  calculated_area_m2?: number | null
  calculated_total?: number | null
  // Price aggregation fields
  price_method?: string | null
  price_sources_count?: number | null
  price_min?: number | null
  price_max?: number | null
  finished_product_pricing_snapshot?: {
    contract_version?: number
    captured_at?: string | null
    product_type?: string | null
    specification?: {
      id?: number | null
      name?: string | null
      article?: string | null
      facade_class?: string | null
      base_type?: string | null
      thickness_mm?: number | null
      covering?: string | null
      cover_type?: string | null
      collection?: string | null
      decor_label?: string | null
      price_group_label?: string | null
    } | null
    pricing?: {
      computed_price_per_m2?: number | null
      aggregation_method?: string | null
      source_count?: number | null
      min_price?: number | null
      max_price?: number | null
      computed_at?: string | null
    } | null
  } | null
  // Relations
  facade_material?: any | null
  finished_product_specification?: FinishedProductSpecification | null
  price_quotes?: any[] | null
  material_price?: any | null
  material?: any | null
}
interface Fitting {
  id: number
  project_id: string
  material_id?: number | null
  name: string
  article: string
  unit: string
  quantity: number
  unit_price: number
  source_url?: string | null
  note?: string | null
}
interface DetailType { id: number; name: string; edge_processing: string }
interface Material {
  id: number
  name: string
  type: 'plate' | 'edge' | 'facade' | 'hardware'
  article?: string | null
  source_url?: string | null
  origin?: 'parser' | 'user' | 'import' | string
  price_per_unit?: number
  length_mm?: number
  width_mm?: number
  thickness_mm?: number
  unit?: string
  updated_at?: string
  metadata?: any
}

// === Состояния ===
const router = useRouter()
const route = useRoute()
const projectRouteIdentifier = String(route.params.projectPublicId ?? route.params.id ?? '').trim()
const projectApiId = ref(projectRouteIdentifier)
let projectId = projectRouteIdentifier
const laborCalculationPanelRef = ref<{ reload?: () => Promise<void> | void } | null>(null)

function handleLaborEvidenceSourcesChanged() {
  void laborCalculationPanelRef.value?.reload?.()
}

const openLaborCalculationDialog = () => {
  laborCalculationDialog.value = true
  nextTick(() => {
    void laborCalculationPanelRef.value?.reload?.()
  })
}

const isMissingProjectError = (error: any): boolean => error?.response?.status === 404

const redirectToProjectsWithMissingMessage = () => {
  setProjectsFlashMessage('Проект не существует')
  void router.replace({ name: 'projects' })
}

const project = ref<Project>({
  id: 0,
  public_id: null,
  number: '',
  expert_name: '',
  address: '',
  region_id: null,
  waste_coefficient: 1.0,
  repair_coefficient: 1.0,
  facade_width_allowance_mm: 0,
  facade_height_allowance_mm: 0,
  text_blocks: [],
  normohour_rate: null,
  normohour_region: null,
  normohour_date: null,
  normohour_method: null,
  normohour_justification: null,
  billing_workspace: {
    read_only: false,
    reason: null,
    owned_projects: 0,
    active_projects: 0,
    limit: null,
    message: null,
  },
})
const userDefaultFacadeAllowances = ref({
  width: 0,
  height: 0,
})

const billingWorkspace = computed(() => project.value.billing_workspace)
const isProjectReadOnly = computed(() => billingWorkspace.value?.read_only === true)
const projectReadOnlyMessage = computed(() => {
  if (billingWorkspace.value?.message) return billingWorkspace.value.message

  const limit = billingWorkspace.value?.limit ?? 0
  const ownedProjects = billingWorkspace.value?.owned_projects ?? billingWorkspace.value?.active_projects ?? 0
  return `Превышен лимит тарифа. На текущем тарифе доступно проектов: ${limit}. Сейчас в аккаунте: ${ownedProjects}. Проекты доступны для просмотра, но редактирование временно ограничено. Выберите подходящий тариф, чтобы продолжить работу.`
})
const readOnlyActionTitle = computed(() => isProjectReadOnly.value ? projectReadOnlyMessage.value : '')

// Функция для очистки текста от HTML и форматирования
// ВАЖНО: сохраняет переносы строк!
const cleanText = (text: string): string => {
  // Удаляем HTML теги
  let cleaned = text.replace(/<[^>]*>/g, '')
  // Заменяем HTML entities
  cleaned = cleaned
    .replace(/&nbsp;/g, ' ')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&amp;/g, '&')
  
  // Очищаем лишние пробелы в строках, но СОХРАНЯЕМ переносы строк
  cleaned = cleaned
    .split('\n')  // разбиваем по переносам
    .map(line => line.replace(/[ \t]+/g, ' ').trim())  // очищаем пробелы в каждой строке
    .filter(line => line.length > 0)  // удаляем пустые строки
    .join('\n')  // собираем обратно
  
  return cleaned
}

const normalizeRichTextValue = (html: string): string => {
  const normalized = String(html || '').replace(/\u00A0/g, ' ').trim()
  return normalized === '<p></p>' ? '' : normalized
}

// Функция для нормализации текста при вставке (paste)
// Сохраняет переносы строк и структуру текста
const normalizeText = (input: string): string => {
  return input
    .replace(/\r\n/g, '\n')          // единый перенос строк
    .replace(/\n{3,}/g, '\n\n')      // максимум 1 пустая строка между абзацами
    .replace(/\u00A0/g, ' ')         // неразрывные пробелы → обычные
    .split('\n')                     // разбиваем по строкам
    .map(line => line.replace(/[ \t]+/g, ' ').trim())  // очищаем пробелы в каждой строке и обрезаем
    .filter(line => line.length > 0)  // убираем полностью пустые строки
    .join('\n')                      // собираем обратно
    .trim()                          // обрезаем полностью в начале и конце
}

// Функция для очистки HTML от лишних классов, стилей и тегов
// Конвертирует в plain text со сохранением структуры
const cleanHtmlToText = (html: string): string => {
  try {
    // Простое преобразование: заменяем теги на текст
    let text = html
    
    // Заменяем блочные элементы на переносы строк
    text = text.replace(/<\/?(p|div|br\s*\/?|li|ul|ol|h[1-6])>/gi, '\n')
    
    // Заменяем ссылки на их текст (удаляем тег но оставляем содержимое)
    text = text.replace(/<a[^>]*>(.*?)<\/a>/gi, '$1')
    
    // Удаляем остальные HTML теги
    text = text.replace(/<[^>]*>/g, '')
    
    // Декодируем HTML entities
    const textarea = document.createElement('textarea')
    textarea.innerHTML = text
    text = textarea.value
    
    // Нормализуем переносы строк
    text = text
      .replace(/\r\n/g, '\n')
      .replace(/\n{3,}/g, '\n\n')
      .replace(/[ \t]+/g, ' ')
      .trim()
    
    return text
  } catch (e) {
    console.error('Error cleaning HTML:', e)
    // Если что-то пошло не так, просто удаляем теги
    return html.replace(/<[^>]*>/g, '').trim()
  }
}

// Функция для обработки вставки текста (paste)
const onPasteText = (block: TextBlock, event: ClipboardEvent) => {
  try {
    event.preventDefault()
    
    const clipboard = event.clipboardData
    if (!clipboard) {
      console.warn('Clipboard data not available')
      return
    }
    
    let pasted = ''
    
    // Сначала пытаемся получить HTML
    const htmlData = clipboard.getData('text/html')
    
    if (htmlData && htmlData.trim().length > 0) {
      // Если есть HTML - конвертируем в текст со структурой
      pasted = cleanHtmlToText(htmlData)
      console.log('📋 Pasted from HTML')
    } else {
      // Если нет HTML - берём plain text
      pasted = clipboard.getData('text/plain') || clipboard.getData('text') || ''
      console.log('📋 Pasted from plain text')
    }
    
    if (!pasted.trim()) {
      console.warn('No text to paste')
      return
    }
    
    // Нормализуем текст
    const normalized = normalizeText(pasted)
    
    // Если в блоке уже есть текст, добавляем двойной перевод строки (новый абзац)
    block.text = (block.text ? block.text + '\n\n' : '') + normalized
    
    // Финальная очистка от HTML и форматирования
    block.text = cleanText(block.text)
    
    console.log('✅ Paste successful. Text length:', block.text.length)
  } catch (e) {
    console.error('❌ Error during paste:', e)
    // Если произойдёт ошибка, попытаемся получить plain text напрямую
    try {
      const plainText = event.clipboardData?.getData('text/plain') || ''
      if (plainText.trim()) {
        const normalized = normalizeText(plainText)
        block.text = (block.text ? block.text + '\n\n' : '') + normalized
        block.text = cleanText(block.text)
        console.log('✅ Paste (fallback) successful. Text length:', block.text.length)
      }
    } catch (fallbackError) {
      console.error('Fallback paste also failed:', fallbackError)
    }
  }
}

// Функция для валидации текста
const validateTextBlock = (block: TextBlock): boolean => {
  const title = block.title?.trim() || ''
  const text = block.text?.trim() || ''
  return title.length > 0 && text.length > 0 && (block.enabled !== false)
}
const positions = ref<Position[]>([])
const fittings = ref<Fitting[]>([])
const expenses = ref<any[]>([])
const refreshing = ref(false)
const settingsDrawer = ref(false) // ← Drawer с настройками проекта
const importDialog = ref(false) // ← Диалог импорта позиций
const positionDrawer = ref(false)

// Mobile overlay exclusivity: only one primary overlay at a time
// (placed after declarations to avoid TDZ errors during setup execution)
watch(settingsDrawer, (opened) => {
  if (opened && compactLayout.value) {
    positionDrawer.value = false
  }
})
watch(positionDrawer, (opened, wasOpen) => {
  if (opened && compactLayout.value) {
    settingsDrawer.value = false
  }
  if (!opened && wasOpen) {
    showNotification('Все изменения сохранены', 'success', 3000)
  }
})

const selectedPosition = ref<Position | null>(null)
// Guard: close position drawer if selection is cleared while drawer is open
watch(selectedPosition, (pos) => {
  if (!pos) positionDrawer.value = false
})
const selectedPositionsRaw = ref<number[]>([])
const selectedPositionIds = computed(() => selectedPositionsRaw.value)
type DimensionCalcState = { expr: string; result: number | null; error: string | null }
const emptyDimensionCalcState = (): DimensionCalcState => ({ expr: '', result: null, error: null })
const dialogDimensionCalc = ref<{ width: DimensionCalcState; length: DimensionCalcState }>({
  width: emptyDimensionCalcState(),
  length: emptyDimensionCalcState(),
})
const applyFacadeAllowance = ref(false)
const dialogDimensionInputDirty = ref<{ width: boolean; length: boolean }>({
  width: false,
  length: false,
})
const drawerDimensionCalc = ref<{ width: DimensionCalcState; length: DimensionCalcState }>({
  width: emptyDimensionCalcState(),
  length: emptyDimensionCalcState(),
})

const positionsTableWrap = ref<HTMLElement | null>(null)
const positionsToolbarRef = ref<HTMLElement | null>(null)
const toolbarSentinelRef = ref<HTMLElement | null>(null)
let toolbarSentinelObserver: IntersectionObserver | null = null



// === Пресеты колонок и настройки отображения ===

// Плотность отображения (сохраняется в localStorage)
const tableDensity = ref<'compact' | 'comfortable'>(
  (localStorage.getItem('positions_table_density') as 'compact' | 'comfortable') || 'compact'
)
watch(tableDensity, (val) => {
  localStorage.setItem('positions_table_density', val)
})

// Текущий пресет колонок (сохраняется в localStorage)
const columnPreset = ref<string>(
  localStorage.getItem('positions_column_preset') || 'basic'
)
watch(columnPreset, (val) => {
  localStorage.setItem('positions_column_preset', val)
})

// Определения пресетов
const columnPresets = [
  { value: 'basic', label: 'Базовые', icon: 'mdi-view-list' },
  { value: 'materials', label: 'Материалы', icon: 'mdi-texture-box' },
  { value: 'sizes', label: 'Размеры', icon: 'mdi-ruler-square' },
  { value: 'edges', label: 'Кромка', icon: 'mdi-border-all' },
  { value: 'facades', label: 'Фасады', icon: 'mdi-door' },
  { value: 'totals', label: 'Итоги', icon: 'mdi-calculator' }
]

// Все возможные колонки
const allPositionColumns = {
  custom_name: { title: 'Название', key: 'custom_name', width: 200, cellClass: 'cell-with-actions' },
  kind: { title: 'Тип', key: 'kind', width: 90 },
  detail_type: { title: 'Тип детали', key: 'detail_type', width: 130 },
  material_short: { title: 'Материал', key: 'material_short', width: 180 },
  edge_material_short: { title: 'Кромка', key: 'edge_material_short', width: 150 },
  base_material: { title: 'Основа', key: 'base_material', width: 80 },
  thickness: { title: 'Толщина', key: 'thickness', width: 80 },
  decor_label: { title: 'Декор', key: 'decor_label', width: 160 },
  size: { title: 'Размеры (Ш×В)', key: 'size', width: 120 },
  width: { title: 'Ширина', key: 'width', width: 80 },
  length: { title: 'Длина', key: 'length', width: 80 },
  quantity: { title: 'Кол-во', key: 'quantity', width: 70 },
  edge_scheme: { title: 'Схема кромки', key: 'edge_scheme', width: 130 },
  area_total: { title: 'Площадь (м²)', key: 'area_total', width: 110 },
  price_per_m2: { title: 'Цена за м²', key: 'price_per_m2', width: 110 },
  unit_price: { title: 'Цена за ед.', key: 'unit_price', width: 100 },
  total_price: { title: 'Сумма', key: 'total_price', width: 100 }
}

// Наборы колонок для каждого пресета (без actions - теперь inline)
const presetColumns: Record<string, string[]> = {
  basic: ['custom_name', 'kind', 'detail_type', 'material_short', 'size', 'quantity'],
  materials: ['custom_name', 'kind', 'material_short', 'edge_material_short', 'quantity'],
  sizes: ['custom_name', 'kind', 'width', 'length', 'quantity', 'area_total'],
  edges: ['custom_name', 'material_short', 'edge_material_short', 'edge_scheme'],
  facades: ['custom_name', 'base_material', 'thickness', 'decor_label', 'size', 'quantity', 'area_total', 'price_per_m2', 'total_price'],
  totals: ['custom_name', 'kind', 'material_short', 'quantity', 'area_total', 'price_per_m2', 'total_price']
}

// Computed: текущие заголовки на основе пресета
const currentPositionHeaders = computed(() => {
  const preset = columnPreset.value
  const columns = presetColumns[preset] ?? presetColumns.basic ?? []
  return columns.map(key => allPositionColumns[key as keyof typeof allPositionColumns]).filter(Boolean)
})

// === Inline Row Actions (hover-панель действий) ===
const hoveredPositionId = ref<number | null>(null)
const highlightedPositionId = ref<number | null>(null)
const lastSelectedIndex = ref<number | null>(null)

// Toggle выбора позиции
const togglePositionSelection = (id: number) => {
  const idx = selectedPositionsRaw.value.indexOf(id)
  if (idx === -1) {
    selectedPositionsRaw.value.push(id)
  } else {
    selectedPositionsRaw.value.splice(idx, 1)
  }
}

const handleCheckboxClick = (event: MouseEvent, id: number, index: number) => {
  if (!Number.isFinite(index)) {
    togglePositionSelection(id)
    lastSelectedIndex.value = index
    return
  }

  if (event.shiftKey && lastSelectedIndex.value !== null) {
    const start = Math.min(lastSelectedIndex.value, index)
    const end = Math.max(lastSelectedIndex.value, index)
    const rangeIds = positions.value
      .slice(start, end + 1)
      .map((p) => p.id)
      .filter((pid): pid is number => pid !== null && pid !== undefined)

    const set = new Set(selectedPositionsRaw.value)
    rangeIds.forEach((pid) => set.add(pid))
    selectedPositionsRaw.value = Array.from(set)
  } else {
    togglePositionSelection(id)
  }

  lastSelectedIndex.value = index
}

// Быстрые действия для строки
const getQuickActions = (item: Position): RowAction[] => [
  { 
    key: 'edit', 
    icon: 'mdi-pencil', 
    label: 'Изменить',
    tooltip: readOnlyActionTitle.value || 'Изменить',
    disabled: isProjectReadOnly.value || processingPositionId.value !== null
  },
  { 
    key: 'duplicate', 
    icon: 'mdi-content-duplicate', 
    label: 'Дублировать',
    tooltip: readOnlyActionTitle.value || 'Дублировать',
    disabled: isProjectReadOnly.value || processingPositionId.value !== null
  },
  { 
    key: 'delete', 
    icon: 'mdi-delete', 
    label: 'Удалить',
    tooltip: readOnlyActionTitle.value || 'Удалить',
    disabled: isProjectReadOnly.value || processingPositionId.value !== null,
    color: 'error'
  }
]

// Действия в меню "..."
const getMenuActions = (item: Position): RowAction[] => [
  { 
    key: 'view_details', 
    icon: 'mdi-information-outline', 
    label: 'Детали позиции'
  },
  { 
    key: 'copy_to_clipboard', 
    icon: 'mdi-content-copy', 
    label: 'Скопировать данные'
  }
]

// Обработчик действий
const handleRowAction = (payload: { rowId: number | string, actionKey: string }) => {
  const item = positions.value.find(p => p.id === payload.rowId)
  if (!item) return

  if (['edit', 'duplicate', 'delete'].includes(payload.actionKey) && guardReadOnlyAction()) {
    return
  }
  
  switch (payload.actionKey) {
    case 'edit':
      openPositionDrawer(item)
      break
    case 'duplicate':
      clonePosition(item)
      break
    case 'delete':
      deletePosition(item)
      break
    case 'view_details':
      openPositionDrawer(item)
      break
    case 'copy_to_clipboard':
      copyPositionToClipboard(item)
      break
  }
}

// Копирование данных позиции в буфер
const copyPositionToClipboard = async (item: Position) => {
  const data = {
    name: item.custom_name,
    material: getMaterialName(item.material_id),
    edge: getMaterialName(item.edge_material_id),
    size: `${item.width}×${item.length}`,
    quantity: item.quantity
  }
  try {
    await navigator.clipboard.writeText(JSON.stringify(data, null, 2))
    showNotification('Данные скопированы в буфер', 'success')
  } catch {
    showNotification('Не удалось скопировать данные', 'error')
  }
}

// Видимость панели действий
const isActionsVisible = (itemId: number | null): boolean => {
  if (!itemId) return false
  return hoveredPositionId.value === itemId
}

const scrollToPositionRow = (id: number) => {
  nextTick(() => {
    const wrap = positionsTableWrap.value
    if (!wrap) return
    const row = wrap.querySelector(`tr[data-row-id="${id}"]`) as HTMLElement | null
    if (row && typeof row.scrollIntoView === 'function') {
      row.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }
  })
}

// Функция получения названия схемы кромки
const getEdgeSchemeName = (scheme: string | null | undefined): string => {
  if (!scheme) return '—'
  const option = edgeSchemeOptions.find(o => o.value === scheme)
  return option?.label || scheme
}

// DEBUG: отслеживаем изменения выбора
watch(selectedPositionsRaw, (newVal) => {
  console.log('🔵 selectedPositionsRaw changed:', newVal, 'length:', newVal.length)
}, { deep: true })

const bulkAction = ref<string | null>(null)
const bulkApplyMode = ref<'strict' | 'partial'>('strict')
const bulkMaterialId = ref<number | null>(null)
const bulkEdgeMaterialId = ref<number | null>(null)
const bulkEdgeScheme = ref<string | null>(null)
const bulkClearField = ref<string | null>(null)
const bulkFacadeSpecificationId = ref<number | null>(null)
const confirmBulkDialog = ref(false)

const autoSaveTimer = ref<number | null>(null)
const autoSavePending = ref(false)
const autoSaveInProgress = ref(false)
const suppressAutoSave = ref(false)
const isProjectLoaded = ref(false)

// === Loading state для дружелюбного прогресса ===
const loadingMessage = ref('Собираем данные проекта…')
const loadingReady = ref(false) // true после финального fade-out

// === Snackbar уведомления ===
const snackbar = ref({
  show: false,
  message: '',
  color: 'info',
  timeout: 3000
})

// Функция для показа уведомлений
const showNotification = (message: string, color: string = 'info', timeout: number = 3000) => {
  snackbar.value = {
    show: true,
    message,
    color,
    timeout
  }
}

const isBillingLockedError = (error: any): boolean => {
  const status = error?.response?.status
  return (status === 423 || status === 403) && error?.response?.data?.billing?.read_only === true
}

const showBillingLockedError = (error: any): boolean => {
  if (!isBillingLockedError(error)) return false

  project.value.billing_workspace = {
    ...(project.value.billing_workspace || {}),
    ...error.response.data.billing,
    message: error.response.data.message || projectReadOnlyMessage.value,
  }
  showNotification(project.value.billing_workspace?.message || projectReadOnlyMessage.value, 'warning', 8000)

  return true
}

const guardReadOnlyAction = (): boolean => {
  if (!isProjectReadOnly.value) return false

  showNotification(projectReadOnlyMessage.value, 'warning', 8000)
  return true
}

// === Утилиты ===
const toHours = (value: any): string => {
  const num = parseFloat(value) || 0
  return num.toFixed(1)
}

const formatNumber = (num: number, decimals: number = 2): string => {
  const rounded = Math.round(num * Math.pow(10, decimals)) / Math.pow(10, decimals)
  return rounded.toString()
}

const evaluateExpression = (expr: string): number | null => {
  if (!expr || typeof expr !== 'string') return null
  
  const trimmed = expr.trim()
  if (!trimmed) return null
  if (/^0/.test(trimmed)) return null
  
  // Если это просто число, возвращаем его
  const numValue = parseFloat(trimmed)
  if (!isNaN(numValue) && trimmed === numValue.toString()) {
    return numValue
  }
  
  // Проверяем, что выражение содержит только допустимые символы и операции
  if (!/^[\d+\-*/.().\s]+$/.test(trimmed)) {
    return null
  }
  
  try {
    // Используем Function вместо eval для безопасности (всё равно ограничено символами выше)
    const result = Function('"use strict"; return (' + trimmed + ')')()
    
    // Проверяем, что результат - число
    if (typeof result === 'number' && !isNaN(result) && isFinite(result)) {
      return result
    }
    return null
  } catch (e) {
    return null
  }
}

const sanitizeDimensionExpressionInput = (event: Event) => {
  const input = event.target as HTMLInputElement | null
  if (!input) return

  let nextValue = input.value.replace(/[^0-9+\-*/().\s]/g, '')
  const leftSpaces = nextValue.match(/^\s*/)?.[0] || ''
  const withoutLeftSpaces = nextValue.slice(leftSpaces.length)

  if (/^0/.test(withoutLeftSpaces)) {
    nextValue = leftSpaces + withoutLeftSpaces.replace(/^0+/, '')
  }

  if (input.value !== nextValue) {
    input.value = nextValue
  }
}

const handleDialogDimensionTyped = (field: 'width' | 'length', event: Event) => {
  dialogDimensionInputDirty.value[field] = true
  sanitizeDimensionExpressionInput(event)
}

const getFacadeAllowanceForField = (field: 'width' | 'length'): number => {
  const raw = field === 'width'
    ? project.value?.facade_width_allowance_mm
    : project.value?.facade_height_allowance_mm
  const numeric = Number(raw)

  if (Number.isFinite(numeric) && numeric > 0) {
    return Math.round(numeric)
  }

  const fallback = field === 'width'
    ? userDefaultFacadeAllowances.value.width
    : userDefaultFacadeAllowances.value.height

  return Number.isFinite(fallback) && fallback > 0 ? Math.round(fallback) : 0
}

const hasFacadeAllowance = computed(() => (
  getFacadeAllowanceForField('width') > 0 || getFacadeAllowanceForField('length') > 0
))

const facadeAllowanceHint = computed(() => (
  `Ширина: ${getFacadeAllowanceForField('width')} мм, высота: ${getFacadeAllowanceForField('length')} мм`
))

const handleDimensionInput = (item: Position, field: 'width' | 'length', inputValue: string) => {
  const trimmed = inputValue.trim()
  drawerDimensionCalc.value[field] = { expr: trimmed, result: null, error: null }
  
  // Если пусто, устанавливаем 0
  if (!trimmed) {
    drawerDimensionCalc.value[field] = { expr: '', result: null, error: null }
    item[field] = 0
    updatePositionField(item, field, 0)
    return
  }
  
  // Пытаемся вычислить выражение
  const result = evaluateExpression(trimmed)
  
  if (result === null) {
    // Если не удалось вычислить, оставляем как было
    drawerDimensionCalc.value[field] = { expr: trimmed, result: null, error: 'Ошибка выражения' }
    showNotification(`Ошибка в выражении: "${trimmed}". Используйте числа и операции (+, -, *, /), без начального 0`, 'warning')
    return
  }
  
  if (result < 0) {
    // Если результат отрицательный
    drawerDimensionCalc.value[field] = { expr: trimmed, result: 0, error: 'Отрицательное значение' }
    showNotification(`Результат выражения "${trimmed}" = ${result}. Установлено значение 0`, 'warning')
    item[field] = 0
    updatePositionField(item, field, 0)
    return
  }
  
  // Округляем до целого
  const roundedResult = Math.round(result)
  
  // Если результат изменился, показываем сообщение
  if (trimmed !== roundedResult.toString()) {
    console.log(`📐 Выражение "${trimmed}" = ${roundedResult}`)
  }
  
  item[field] = roundedResult
  drawerDimensionCalc.value[field] = { expr: trimmed, result: roundedResult, error: null }
  updatePositionField(item, field, roundedResult)
}

const handleDialogDimensionInput = (field: 'width' | 'length', inputValue: any) => {
  const strValue = String(inputValue).trim()
  dialogDimensionCalc.value[field] = { expr: strValue, result: null, error: null }
  
  // Если пусто, устанавливаем 0
  if (!strValue) {
    dialogDimensionCalc.value[field] = { expr: '', result: null, error: null }
    positionFormModel.value[field] = 0
    dialogDimensionInputDirty.value[field] = false
    return
  }
  
  // Пытаемся вычислить выражение
  const result = evaluateExpression(strValue)
  
  if (result === null) {
    // Если не удалось вычислить, оставляем как было
    dialogDimensionCalc.value[field] = { expr: strValue, result: null, error: 'Ошибка выражения' }
    dialogDimensionInputDirty.value[field] = false
    showNotification(`Ошибка в выражении: "${strValue}". Используйте числа и операции (+, -, *, /), без начального 0`, 'warning')
    return
  }
  
  if (result < 0) {
    // Если результат отрицательный
    dialogDimensionCalc.value[field] = { expr: strValue, result: 0, error: 'Отрицательное значение' }
    showNotification(`Результат выражения "${strValue}" = ${result}. Установлено значение 0`, 'warning')
    positionFormModel.value[field] = 0
    dialogDimensionInputDirty.value[field] = false
    return
  }
  
  let roundedResult = Math.round(result)
  const allowance = (
    !editingPosition.value
    && positionFormModel.value.kind === 'facade'
    && applyFacadeAllowance.value
    && dialogDimensionInputDirty.value[field]
  )
    ? getFacadeAllowanceForField(field)
    : 0

  if (allowance > 0) {
    roundedResult = Math.max(0, roundedResult - allowance)
  }
  dialogDimensionInputDirty.value[field] = false
  
  // Если результат изменился, показываем сообщение
  if (strValue !== roundedResult.toString()) {
    console.log(`📐 Выражение "${strValue}" = ${roundedResult}`)
  }
  
  positionFormModel.value[field] = roundedResult
  dialogDimensionCalc.value[field] = { expr: strValue, result: roundedResult, error: null }
}
// === Loading states для skeletons ===
const loadingStates = ref({
  positions: false,
  materials: false,
  fittings: false,
  expenses: false,
  normohourSources: false,
  laborWorks: false,
  operations: false
})

// === Обработчики для ProjectSettingsModal компонента ===
const handleSettingsSaved = async (updatedProject: any) => {
  try {
    // Обновляем локальный project из компонента
    Object.assign(project.value, updatedProject)
    // Сохраняем на сервер
    await updateProject()
  } catch (err: any) {
    console.error('Error saving settings:', err)
    showNotification('Ошибка сохранения настроек', 'error')
    throw err
  }
}

const handleSettingsDrawerClosed = () => {
  settingsDrawer.value = false
  if (activeModule.value === 'settings') {
    activeModule.value = 'positions'
  }
  // Показываем уведомление об применении всех изменений
  showNotification('Все изменения в настройках применены', 'success')
}

const handleSettingsError = (error: string) => {
  console.error('Settings error:', error)
  showNotification(error, 'error')
}

// === Обработчик импорта позиций ===
const handlePositionsImported = async (result: { created_count: number; skipped_count: number; errors_count: number }) => {
  showNotification(`Импортировано ${result.created_count} позиций`, 'success')
  // Перезагрузить список позиций
  try {
    loadingStates.value.positions = true
    positions.value = (await api.get(`/api/projects/${projectId}/positions`)).data
  } catch (err) {
    console.error('Error reloading positions:', err)
  } finally {
    loadingStates.value.positions = false
  }
}

const scheduleAutoSave = () => {
  if (suppressAutoSave.value || !isProjectLoaded.value) return
  autoSavePending.value = true
  if (autoSaveTimer.value) {
    window.clearTimeout(autoSaveTimer.value)
  }
  autoSaveTimer.value = window.setTimeout(async () => {
    if (autoSaveInProgress.value) return
    autoSaveInProgress.value = true
    try {
      await updateProject()
      autoSavePending.value = false
    } finally {
      autoSaveInProgress.value = false
    }
  }, 800)
}

const flushAutoSave = async () => {
  if (autoSaveTimer.value) {
    window.clearTimeout(autoSaveTimer.value)
    autoSaveTimer.value = null
  }
  if (!autoSavePending.value || autoSaveInProgress.value || suppressAutoSave.value || !isProjectLoaded.value) {
    return
  }
  autoSaveInProgress.value = true
  try {
    await updateProject()
    autoSavePending.value = false
  } finally {
    autoSaveInProgress.value = false
  }
}

// === Справочники ===
const detailTypes = ref<DetailType[]>([])
const materials = ref<Material[]>([])
const units = ref<string[]>([])
const regions = ref<any[]>([])

const MATERIALS_CACHE_TTL_MS = 25_000
const MATERIAL_SEARCH_MIN_LENGTH = 2
const MATERIAL_SEARCH_DEBOUNCE_MS = 350
const selectableMaterialsCache = ref<{ expiresAt: number; data: Material[] } | null>(null)

const materialsPlate = computed(() => materials.value.filter(m => m.type === 'plate'))
const materialsEdge = computed(() => materials.value.filter(m => m.type === 'edge'))
const materialSearchLoading = reactive<Record<'plate' | 'edge', boolean>>({
  plate: false,
  edge: false,
})
let materialSearchTimeouts: Partial<Record<'plate' | 'edge', ReturnType<typeof setTimeout>>> = {}
let materialSearchRequestIds: Record<'plate' | 'edge', number> = {
  plate: 0,
  edge: 0,
}

// === Фасады ===
const loadingFacades = ref(false)
let facadeSearchTimeout: ReturnType<typeof setTimeout> | null = null

type FacadeSpecificationOption = FinishedProductSpecification & {
  base_material_label: string | null
  finish_name: string | null
  price_per_m2: number | null
}

const facadeSpecifications = ref<FacadeSpecificationOption[]>([])

function formatFacadePriceMethodLabel(method?: string | null): string {
  if (method === 'mean') return 'Средняя'
  if (method === 'median') return 'Медиана'
  if (method === 'trimmed_mean') return 'Усечённая средняя'
  if (method === 'single') return 'Один источник'
  return '—'
}

function buildFacadeSpecificationLabel(specification: Partial<FinishedProductSpecification>): string {
  const parts = [
    specification.base_type ? specification.base_type.toUpperCase() : null,
    specification.thickness_mm ? `${specification.thickness_mm}мм` : null,
    specification.covering || null,
    specification.facade_class || null,
    specification.decor_label || null,
    specification.collection || null,
  ].filter(Boolean)

  return parts.length > 0
    ? parts.join(' / ')
    : (specification.name || specification.article || 'Фасад')
}

function toFacadeSpecificationOption(specification: FinishedProductSpecification): FacadeSpecificationOption {
  return {
    ...specification,
    name: specification.name || buildFacadeSpecificationLabel(specification),
    base_material_label: specification.base_type ? specification.base_type.toUpperCase() : null,
    finish_name: specification.cover_type || specification.covering || null,
    price_per_m2: specification.computed_price_summary?.computed_price_per_m2 ?? null,
  }
}

async function loadFacadeSpecifications(search = '') {
  loadingFacades.value = true
  try {
    const response = await finishedProductSpecificationsApi.list({
      product_type: 'facade',
      search: search || undefined,
      per_page: 100,
    })

    facadeSpecifications.value = response.data.data.map(toFacadeSpecificationOption)
  } catch (error) {
    console.error('Failed to load facade specifications', error)
    facadeSpecifications.value = []
  } finally {
    loadingFacades.value = false
  }
}

async function ensureFacadeSpecificationLoaded(id: number | null | undefined) {
  if (!id || facadeSpecifications.value.some(item => item.id === id)) {
    return
  }

  try {
    const response = await finishedProductSpecificationsApi.get(id)
    facadeSpecifications.value = [
      toFacadeSpecificationOption(response.data.data),
      ...facadeSpecifications.value,
    ]
  } catch (error) {
    console.error('Failed to load selected facade specification', error)
  }
}

function clearFacadePricingPreview(target: Position) {
  target.base_material_label = null
  target.thickness_mm = null
  target.finish_type = null
  target.finish_name = null
  target.decor_label = null
  target.price_per_m2 = null
  target.price_method = null
  target.price_sources_count = null
  target.price_min = null
  target.price_max = null
}

function applyFacadeSpecificationPreview(target: Position, specificationId: number | null) {
  target.finished_product_specification_id = specificationId

  if (!specificationId) {
    clearFacadePricingPreview(target)
    return
  }

  const specification = facadeSpecifications.value.find(item => item.id === specificationId)
  if (!specification) {
    clearFacadePricingPreview(target)
    return
  }

  target.base_material_label = specification.base_material_label
  target.thickness_mm = specification.thickness_mm
  target.finish_type = specification.covering ?? null
  target.finish_name = specification.finish_name
  target.decor_label = specification.decor_label ?? specification.finish_name ?? null
  target.price_per_m2 = specification.price_per_m2
  target.price_method = specification.aggregation_method ?? specification.computed_price_summary?.method ?? null
  target.price_sources_count = specification.source_count ?? 0
  target.price_min = specification.computed_price_summary?.min_price ?? null
  target.price_max = specification.computed_price_summary?.max_price ?? null
}

function onFacadeSearch(val: string) {
  if (facadeSearchTimeout) clearTimeout(facadeSearchTimeout)
  facadeSearchTimeout = setTimeout(() => {
    loadFacadeSpecifications(val || '')
  }, 300)
}

function onFacadeSpecificationChange(specificationId: number | null) {
  applyFacadeSpecificationPreview(positionFormModel.value, specificationId)
}

// === Заголовки ===
// positionHeaders теперь computed: currentPositionHeaders (выше)

const fittingHeaders = [
  { title: 'Название', key: 'name' },
  { title: 'Кол-во', key: 'quantity' },
  { title: 'Цена', key: 'unit_price' },
  { title: 'Действия', key: 'actions', sortable: false }
]

const materialsHeaders = [
  { title: 'Материал', key: 'name' },
  { title: 'Объем', key: 'volume' },
  { title: 'Ед. изм.', key: 'unit' },
  { title: 'Цена за лист', key: 'price_per_sheet' },
  { title: 'Цена за ед.', key: 'price_per_unit' },
  { title: 'Дата актуальности', key: 'updated_at' },
  { title: 'Сумма', key: 'total_price' }
]

const plateHeadersBase = [
  { title: '', key: 'data-table-expand' },
  { title: 'Материал', key: 'name' },
  { title: 'Площадь деталей (м²)', key: 'area_details' },
  { title: 'Коэфф.', key: 'waste_coeff' },
  { title: 'Площадь с отходами (м²)', key: 'area_with_waste' },
  { title: 'Размер листа (м²)', key: 'sheet_area' },
  { title: 'Листов', key: 'sheets_count' },
  { title: 'Цена за лист', key: 'price_per_sheet' },
  { title: 'Цена за м² (расч.)', key: 'price_per_m2', render: (item: any) => 'Цена за м² (расчётная)' },
  { title: 'Сумма', key: 'total_price' }
]

const plateHeaders = computed(() => {
  const isAreaMode = project.value?.use_area_calc_mode === true
  if (isAreaMode) {
    // В режиме площади: скрываем "Размер листа", "Цена за лист"
    // переименовываем "Листов" → "Площадь к оплате (м²)"
    return plateHeadersBase
      .filter(h => h.key !== 'sheet_area' && h.key !== 'price_per_sheet')
      .map(h => h.key === 'sheets_count' ? { ...h, title: 'Площадь к оплате (м²)' } : h)
  }
  // В режиме листов: скрываем "Цена за м²"
  return plateHeadersBase.filter(h => h.key !== 'price_per_m2')
})

// === Коэффициенты отходов с fallback ===
const getWasteCoefficientForPlate = () => {
  const apply = project.value?.apply_waste_to_plate !== false
  if (!apply) {
    console.log('getWasteCoefficientForPlate: DISABLED → returning 1.0')
    return 1.0
  }
  
  // Используем специфичный коэффициент плит, если задан, иначе общий
  const specificCoeff = project.value?.waste_plate_coefficient
  const generalCoeff = project.value?.waste_coefficient || 1.0
  const coeff = specificCoeff ? Number(specificCoeff) : generalCoeff
  
  const source = specificCoeff ? 'plate-specific' : 'general'
  console.log(`getWasteCoefficientForPlate: ENABLED → ${coeff} (${source})`)
  return coeff
}

const getWasteCoefficientForEdge = () => {
  const apply = project.value?.apply_waste_to_edge !== false
  if (!apply) {
    console.log('getWasteCoefficientForEdge: DISABLED → returning 1.0')
    return 1.0
  }
  
  // Используем специфичный коэффициент кромки, если задан, иначе общий
  const specificCoeff = project.value?.waste_edge_coefficient
  const generalCoeff = project.value?.waste_coefficient || 1.0
  const coeff = specificCoeff ? Number(specificCoeff) : generalCoeff
  
  const source = specificCoeff ? 'edge-specific' : 'general'
  console.log(`getWasteCoefficientForEdge: ENABLED → ${coeff} (${source})`)
  return coeff
}

const getWasteCoefficientForOperations = () => {
  const apply = project.value?.apply_waste_to_operations !== false
  if (!apply) {
    console.log('getWasteCoefficientForOperations: DISABLED → returning 1.0')
    return 1.0
  }
  
  // Используем специфичный коэффициент операций, если задан, иначе общий
  const specificCoeff = project.value?.waste_operations_coefficient
  const generalCoeff = project.value?.waste_coefficient || 1.0
  const coeff = specificCoeff ? Number(specificCoeff) : generalCoeff
  
  const source = specificCoeff ? 'operations-specific' : 'general'
  console.log(`getWasteCoefficientForOperations: ENABLED → ${coeff} (${source})`)
  return coeff
}

const edgeHeaders = [
  { title: '', key: 'data-table-expand' },
  { title: 'Материал кромки', key: 'name' },
  { title: 'Длина (м.п.)', key: 'length_linear' },
  { title: 'Коэфф.', key: 'waste_coeff' },
  { title: 'Длина с отходами (м.п.)', key: 'length_with_waste' },
  { title: 'Цена за м.п.', key: 'price_per_unit' },
  { title: 'Сумма', key: 'total_price' }
]

const expenseHeaders = [
  { title: 'Название', key: 'name' },
  { title: 'Описание', key: 'description' },
  { title: 'Сумма', key: 'amount' },
  { title: 'Действия', key: 'actions', sortable: false }
]

const revisionHeaders = [
  { title: '№', key: 'number' },
  { title: 'Статус', key: 'status' },
  { title: 'Создана', key: 'created_at' },
  { title: 'Автор', key: 'created_by' },
  { title: 'Действия', key: 'actions', sortable: false }
]

// === Схемы кромки ===
const edgeSchemeOptions = [
  { value: 'none', label: 'Без кромки', icon: 'mdi-minus' },
  { value: 'O', label: 'Вкруг', icon: 'mdi-crop-square' },
  { value: '=', label: 'Две длинные стороны', icon: 'mdi-arrow-left-right' },
  { value: '||', label: 'Две короткие стороны', icon: 'mdi-arrow-up-down' },
  { value: 'long_one', label: 'Одна длинная сторона', icon: 'mdi-arrow-right' },
  { value: 'short_one', label: 'Одна короткая сторона', icon: 'mdi-arrow-down' },
  { value: 'L', label: 'Г-образно', icon: 'mdi-vector-square' },
  { value: 'П', label: 'П-образно', icon: 'mdi-alpha-p-box-outline' },
]

// === Диалоги ===
const positionDialog = ref(false)
const fittingDialog = ref(false)
const expenseDialog = ref(false) // ← добавлено!
const laborCalculationDialog = ref(false)
const positionDialogOpening = ref(false)
const positionSaving = ref(false)
const fittingSaving = ref(false)
const expenseSaving = ref(false)
const normohourSourceSaving = ref(false)
const operationSaving = ref(false)
const pdfLoading = ref(false) // ← loader для PDF
const snapshotLoading = ref(false) // ← loader для snapshot
const revisionPdfLoading = ref<Set<number>>(new Set())
const revisionJustPdfLoading = ref<Set<number>>(new Set())
const latestRevision = ref<any>(null) // ← последняя ревизия
const revisions = ref<any[]>([])
const revisionsLoading = ref(false)
const revisionsPagination = reactive({ page: 1, perPage: 10, total: 0, lastPage: 1 })
const revisionDialog = ref(false)
const selectedRevision = ref<any | null>(null)
const selectedRevisionSnapshot = ref('')
const hasRevisions = computed(() => Boolean(latestRevision.value) || revisions.value.length > 0)
const activeRevisionRun = ref<RevisionRun | null>(null)
const revisionRunItems = ref<RevisionRunItem[]>([])
const revisionRunLoading = ref(false)
const revisionRunRetryLoading = ref(false)
const revisionRunFinalizeLoading = ref(false)
const revisionRunPdfLinks = ref<{ smeta: string; price_justification: string } | null>(null)
const revisionRunPollTimer = ref<number | null>(null)
const lastKnownRunStatus = ref<string | null>(null)
const evidenceDetailDialog = ref(false)
const evidenceDetailItem = ref<RevisionRunItem | null>(null)
const evidenceDetailArtifact = computed<EvidenceArtifactDetail | null>(() => {
  const artifacts = evidenceDetailItem.value?.evidence_artifacts
  return artifacts?.[0] ?? null
})
const expenseDocFile = ref<File[] | File | null>(null)
const expenseDocLoading = ref(false)
const manualCloseDialog = ref(false)
const manualCloseLoading = ref(false)
const manualCloseItem = ref<RevisionRunItem | null>(null)
const manualScreenshotDropzone = ref<HTMLElement | null>(null)
const manualScreenshotDropActive = ref(false)
const manualCloseForm = reactive<{
  price_per_unit: number
  currency: string
  source_url: string
  screenshot_file: File[] | File | null
}>({
  price_per_unit: 0,
  currency: 'RUB',
  source_url: '',
  screenshot_file: null,
})
const canRetryRevisionRun = computed(() => {
  if (!activeRevisionRun.value) return false
  return (activeRevisionRun.value.failed_items || 0) > 0 || activeRevisionRun.value.status === 'NEEDS_MANUAL'
})
const canFinalizeRevisionRun = computed(() => {
  if (!activeRevisionRun.value) return false
  return activeRevisionRun.value.status === 'READY' && !estimateHardInvalid.value
})
const normohourSourceDialog = ref(false) // ← диалог для редактирования источника нормо-часа

const editingPosition = ref(false)
const editingFitting = ref(false)
const editingExpense = ref(false)
const editingNormohourSource = ref(false) // ← флаг редактирования источника

// Состояние для источников нормо-часа
const normohourSources = ref<NormohourSource[]>([])
const normohourSourceForm = ref<NormohourSource>({
  source: '',
  position_profile: null,
  salary_range: null,
  period: null,
  link: null,
  note: null
})
const normohourSourceValidation = ref<Record<string, string>>({})

const positionForm = ref()
const positionFormModel = ref<Position>({
  id: null, project_id: projectId,
  kind: 'panel',
  detail_type_id: null,
  material_id: null,
  facade_material_id: null,
  finished_product_specification_id: null,
  material_price_id: null,
  edge_material_id: null,
  edge_scheme: 'none',
  width: 0,
  length: 0,
  quantity: 1,
  custom_name: null,
})

watch(() => positionFormModel.value.kind, (kind) => {
  dialogDimensionInputDirty.value = { width: false, length: false }
  if (editingPosition.value || kind !== 'facade') {
    applyFacadeAllowance.value = false
  }
})

const fittingForm = ref<Fitting>({
  id: 0, project_id: projectId,
  material_id: null, name: '', article: '', unit: 'шт', quantity: 1, unit_price: 0, source_url: null, note: ''
})

const hardwareMaterials = ref<Material[]>([])
const hardwareMaterialsLoading = ref(false)
const fittingSearchQuery = ref('')
let fittingSearchTimeout: ReturnType<typeof setTimeout> | null = null
let fittingSearchRequestId = 0

const mergedHardwareMaterials = computed(() => {
  const byId = new Map<number, Material>()
  for (const material of materials.value) {
    if (material.type === 'hardware') byId.set(material.id, material)
  }
  for (const material of hardwareMaterials.value) {
    byId.set(material.id, material)
  }

  return Array.from(byId.values()).sort((a, b) => a.name.localeCompare(b.name, 'ru'))
})

const fetchHardwareModeMaterials = async (
  mode: 'my' | 'public',
  search: string
): Promise<any[]> => {
  const rows: any[] = []
  let page = 1
  let lastPage = 1

  do {
    const response = await api.get('/api/materials/catalog', {
      params: {
        mode,
        type: 'hardware',
        search: search || undefined,
        per_page: 100,
        page,
      },
    })

    const pageRows = response?.data?.data
    if (Array.isArray(pageRows)) {
      rows.push(...pageRows)
    }

    lastPage = Number(response?.data?.meta?.last_page || 1)
    page += 1
  } while (page <= lastPage)

  return rows
}

const refreshHardwareMaterials = async (searchRaw = '') => {
  const search = String(searchRaw || '').trim()
  const requestId = ++fittingSearchRequestId
  hardwareMaterialsLoading.value = true

  try {
    const byId = new Map<number, Material>()
    const modes: Array<'my' | 'public'> = ['my', 'public']

    for (const mode of modes) {
      try {
        const rows = await fetchHardwareModeMaterials(mode, search)
        mergeMaterialOptions(byId, rows)
      } catch (error) {
        console.warn(`Failed to load hardware materials mode: ${mode}`, error)
      }
    }

    if (requestId !== fittingSearchRequestId) return

    hardwareMaterials.value = Array.from(byId.values()).sort((a, b) => a.name.localeCompare(b.name, 'ru'))
  } catch (error) {
    if (requestId === fittingSearchRequestId) {
      showNotification('Не удалось загрузить список фурнитуры', 'error')
    }
    console.error('Failed to refresh hardware materials', error)
  } finally {
    if (requestId === fittingSearchRequestId) {
      hardwareMaterialsLoading.value = false
    }
  }
}

const onFittingSearch = (value: string) => {
  fittingSearchQuery.value = value || ''
  if (fittingSearchTimeout) {
    clearTimeout(fittingSearchTimeout)
  }

  fittingSearchTimeout = setTimeout(() => {
    refreshHardwareMaterials(fittingSearchQuery.value)
  }, 350)
}

const selectedFittingMaterial = computed(() => {
  if (!fittingForm.value.material_id) {
    return null
  }

  return mergedHardwareMaterials.value.find((material) => material.id === fittingForm.value.material_id) || null
})

const resolveFittingMaterialId = (item: Fitting): number | null => {
  if (item.material_id) {
    return Number(item.material_id)
  }

  const byArticle = mergedHardwareMaterials.value.find((material) => {
    return !!item.article && !!material.article && String(material.article).trim() === String(item.article).trim()
  })
  if (byArticle) {
    return byArticle.id
  }

  const byName = mergedHardwareMaterials.value.find((material) => String(material.name).trim() === String(item.name).trim())
  return byName?.id || null
}

const onFittingMaterialChange = (materialId: number | string | null) => {
  const normalizedMaterialId = materialId == null ? null : Number(materialId)
  fittingForm.value.material_id = Number.isFinite(normalizedMaterialId as number)
    ? normalizedMaterialId
    : null

  const material = mergedHardwareMaterials.value.find((entry) => entry.id === fittingForm.value.material_id)
  if (!material) {
    fittingForm.value.name = ''
    fittingForm.value.article = ''
    fittingForm.value.unit_price = 0
    fittingForm.value.unit = units.value[0] || 'шт'
    fittingForm.value.source_url = null
    return
  }

  fittingForm.value.name = material.name
  fittingForm.value.article = material.article || ''
  fittingForm.value.unit_price = Number(material.price_per_unit) || 0
  fittingForm.value.unit = material.unit || units.value[0] || 'шт'
  fittingForm.value.source_url = material.source_url || null
}

const expenseForm = ref<any>({
  id: 0, project_id: projectId, type: '', cost: 0
})
const quickQuantityValues = [2, 4, 6, 8, 10]
const setQuickQuantity = (qty: number) => {
  positionFormModel.value.quantity = qty
}

// === Вспомогательные функции ===
const getMaterialName = (id: number | null) => {
  if (!id) return '—'
  const mat = materials.value.find(m => m.id === id)
  return mat ? mat.name : `Материал #${id}`
}

const getFacadePositionName = (item: Position) => {
  return item.finished_product_pricing_snapshot?.specification?.name
    || item.finished_product_specification?.name
    || item.facade_material?.name
    || item.decor_label
    || '—'
}

const hasMissingMainMaterialPrice = (item: Position): boolean => {
  if (item.kind === 'facade') {
    return !!item.finished_product_specification_id && !(Number(item.price_per_m2) > 0)
  }
  if (!item.material_id) return false
  const mat = materials.value.find(m => m.id === item.material_id)
  const result = !mat || !(Number(mat.price_per_unit) > 0)
  if (item.material_id === 691) {
    console.warn('DEBUG hasMissingMainMaterialPrice for ID 691:', {
      found: !!mat,
      price: mat?.price_per_unit,
      price_number: Number(mat?.price_per_unit),
      result
    })
  }
  return result
}

const hasMissingMainMaterialSheetSize = (item: Position): boolean => {
  if (item.kind === 'facade' || !item.material_id) return false
  const mat = materials.value.find(m => m.id === item.material_id)
  if (!mat || mat.type !== 'plate') return false
  const result = !(Number(mat.length_mm) > 0 && Number(mat.width_mm) > 0)
  if (item.material_id === 691) {
    console.warn('DEBUG hasMissingMainMaterialSheetSize for ID 691:', {
      found: !!mat,
      type: mat?.type,
      length_mm: mat?.length_mm,
      width_mm: mat?.width_mm,
      result
    })
  }
  return result
}

const hasMissingEdgeMaterialPrice = (item: Position): boolean => {
  if (item.kind === 'facade' || !item.edge_material_id) return false
  const mat = materials.value.find(m => m.id === item.edge_material_id)
  return !mat || !(Number(mat.price_per_unit) > 0)
}

const getDetailTypeName = (id: number | null) => {
  if (!id) return '—'
  const dt = detailTypes.value.find(d => d.id === id)
  return dt ? dt.name : `Тип #${id}`
}

const getEdgeLabel = (value: string) => {
  if (!value) return '—'
  return edgeSchemeOptions.find(s => s.value === value)?.label || value
}

const getPositionEdgeLabel = (item: any) => {
  if (item.edge_scheme) return getEdgeLabel(item.edge_scheme)
  if (item.detail_type_id) {
    const dt = detailTypes.value.find(d => d.id === item.detail_type_id)
    if (dt && dt.edge_processing) return getEdgeLabel(dt.edge_processing)
  }
  return '—'
}

// === Детали расчёта кромки ===
const getEdgeDetailsForMaterial = (edgeMaterialId: number) => {
  const details: any[] = []
  
  positions.value.forEach(pos => {
    if (!pos.edge_material_id || pos.edge_material_id !== edgeMaterialId) return
    if (!pos.edge_scheme || pos.edge_scheme === 'none') return
    
    const widthM = (pos.width || 0) / 1000
    const lengthM = (pos.length || 0) / 1000
    
    // Рассчитываем периметр одной детали
    let perimeterOne = 0
    switch (pos.edge_scheme) {
      case 'O': perimeterOne = 2 * (widthM + lengthM); break
      case '=': perimeterOne = 2 * lengthM; break
      case '||': perimeterOne = 2 * widthM; break
      case 'L': perimeterOne = widthM + lengthM; break
      case 'П': perimeterOne = 2 * widthM + lengthM; break
      case 'long_one': perimeterOne = lengthM; break
      case 'short_one': perimeterOne = widthM; break
    }
    
    const totalLength = perimeterOne * (pos.quantity || 0)
    
    details.push({
      position_id: pos.id,
      position_name: pos.custom_name || getDetailTypeName(pos.detail_type_id) || `Позиция ${pos.id}`,
      width: pos.width,
      length: pos.length,
      edge_scheme_label: getEdgeLabel(pos.edge_scheme),
      perimeter_one: perimeterOne,
      quantity: pos.quantity,
      total_length: totalLength
    })
  })
  
  return details
}

const onDetailTypeChange = (val: number | null) => {
  if (!val) {
    positionFormModel.value.edge_scheme = 'none'
    return
  }
  const dt = detailTypes.value.find(d => d.id === val)
  if (dt && dt.edge_processing) {
    positionFormModel.value.edge_scheme = dt.edge_processing
  }
  scheduleRecalc()
}

// === Детали операций для раскрытия ===
const getOperationDetails = (operation: any) => {
  // Если уже кэшировано в операции, используем кэш
  if (operation._cached_details) {
    return operation._cached_details
  }
  
  const opName = operation.name || ''
  
  // Определяем тип операции по названию
  if (opName.toLowerCase().includes('кромк')) {
    return getEdgingOperationDetails(operation)
  } else if (opName.toLowerCase().includes('пил') || opName.toLowerCase().includes('распил')) {
    return getSawingOperationDetails(operation)
  } else if (opName.toLowerCase().includes('сверл') || opName.toLowerCase().includes('отверст')) {
    return getDrillingOperationDetails(operation)
  }
  
  // Для ручных операций или неизвестных типов
  return {
    type: 'manual',
    is_manual: true,
    message: 'Введено вручную экспертом'
  }
}

const getEdgingOperationDetails = (operation: any) => {
  // Для ручных операций просто сообщение
  if (operation.type === 'manual') {
    return {
      type: 'edging',
      is_manual: true,
      message: 'Введено вручную экспертом'
    }
  }
  
  const details: any[] = []
  let totalLength = 0
  
  // Собираем все кромки по материалам
  edgeData.value.forEach(edge => {
    // Для каждой кромки находим все позиции где она была применена
    const edgeDetails = getEdgeDetailsForMaterial(edge.id)
    edgeDetails.forEach(detail => {
      details.push({
        position_name: detail.position_name,
        material_name: edge.name,
        length: detail.total_length
      })
      totalLength += detail.total_length
    })
  })
  
  const wasteCoeff = getWasteCoefficientForEdge()
  const totalWithWaste = totalLength * wasteCoeff
  
  return {
    type: 'edging',
    details: details,
    total_length: totalLength,
    waste_coeff: wasteCoeff,
    total_with_waste: totalWithWaste,
    unit: 'м.п.'
  }
}

const getSawingOperationDetails = (operation: any) => {
  // Для ручных операций просто сообщение
  if (operation.type === 'manual') {
    return {
      type: 'sawing',
      is_manual: true,
      message: 'Введено вручную экспертом'
    }
  }
  
  const details: any[] = []
  let totalArea = 0
  
  // Объём распиловки считается от площади деталей плитных материалов
  plateData.value.forEach(plate => {
    const areaDetails = plate.area_details
    if (areaDetails > 0) {
      details.push({
        material_name: plate.name,
        area: areaDetails
      })
      totalArea += areaDetails
    }
  })
  
  return {
    type: 'sawing',
    details: details,
    total_area: totalArea,
    unit: 'м²',
    note: 'Объём распиловки считается от площади деталей плитных материалов'
  }
}

const getDrillingOperationDetails = (operation: any) => {
  console.log('getDrillingOperationDetails called:', { 
    name: operation.name, 
    type: operation.type,
    quantity_from_api: operation.quantity,
    source: operation.source,
    operation_id: operation.operation_id,
    positions: positions.value.length,
    detail_types: detailTypes.value.length
  })
  
  // Для ручных операций просто сообщение
  // Проверяем тип операции: если type === 'manual'
  if (operation.type === 'manual') {
    console.log('  -> operation is manual, returning manual message')
    return {
      type: 'drilling',
      is_manual: true,
      message: 'Введено вручную экспертом'
    }
  }
  
  // Для автоматических операций группируем по типам деталей
  const detailTypeMap = new Map<number, any>()
  
  positions.value.forEach(pos => {
    // Получаем материал для проверки что это плитный материал
    const material = materials.value.find(m => m.id === pos.material_id)
    
    console.log('  checking position:', { 
      material_id: pos.material_id,
      material_type: material?.type,
      detail_type_id: pos.detail_type_id,
      quantity: pos.quantity 
    })
    
    // Проверяем что это плитный материал И есть тип детали
    if (material?.type === 'plate' && pos.detail_type_id) {
      const detailType = detailTypes.value.find(dt => dt.id === pos.detail_type_id)
      if (detailType) {
        console.log('    -> found detailType:', detailType.name)
        if (!detailTypeMap.has(pos.detail_type_id)) {
          detailTypeMap.set(pos.detail_type_id, {
            detail_type_name: detailType.name,
            total_quantity: 0,
            holes_per_piece: 8
          })
        }
        const entry = detailTypeMap.get(pos.detail_type_id)!
        entry.total_quantity += (pos.quantity || 1)
      }
    }
  })
  
  const details = Array.from(detailTypeMap.values()).map(item => ({
    detail_type_name: item.detail_type_name,
    holes_per_piece: item.holes_per_piece,
    quantity: item.total_quantity,
    total_holes: item.holes_per_piece * item.total_quantity
  }))
  
  console.log('  drilling details:', details)
  
  let totalHoles = 0
  details.forEach(d => {
    totalHoles += d.total_holes
  })
  
  console.log('  SUMMARY:', {
    calculated_total: totalHoles,
    api_quantity: operation.quantity,
    difference: operation.quantity - totalHoles,
    details_count: details.length
  })
  
  return {
    type: 'drilling',
    is_manual: false,
    details: details,
    total_holes: totalHoles,
    unit: 'шт.',
    message: 'Расчёт по позициям с плитными материалами'
  }
}


// --- Recalc helpers ---
let _recalcTimer: any = null
const scheduleRecalc = () => {
  if (_recalcTimer) clearTimeout(_recalcTimer)
  _recalcTimer = setTimeout(() => recalcOperations(), 350)
}

const recalcOperations = async () => {
  try {
    loadingStates.value.operations = true
    const res = await api.get(`/api/projects/${projectId}/operations`)
    const data = res.data || []
    if (Array.isArray(data)) {
      _operations.value = data
    } else {
      console.warn('Operations data is not an array:', data)
      _operations.value = []
    }
  } catch (e) {
    console.warn('Recalc operations failed', e)
    _operations.value = []
  } finally {
    loadingStates.value.operations = false
  }
}

const onPositionMaterialChange = (val: number | null) => {
  positionFormModel.value.material_id = val
  scheduleRecalc()
}

const onPositionEdgeSchemeChange = (val: string | null) => {
  positionFormModel.value.edge_scheme = val || 'none'
  scheduleRecalc()
}

const onPositionEdgeMaterialChange = (val: number | null) => {
  positionFormModel.value.edge_material_id = val
  scheduleRecalc()
}

const normalizeMaterialOption = (raw: any): Material | null => {
  if (!raw || !raw.id || !raw.name || !raw.type) return null
  if (!['plate', 'edge', 'facade', 'hardware'].includes(raw.type)) return null

  return {
    id: Number(raw.id),
    name: String(raw.name),
    type: raw.type,
    article: raw.article || null,
    source_url: raw.source_url || null,
    origin: (raw.origin || (raw.user_id ? 'user' : 'parser')) as Material['origin'],
    price_per_unit: raw.price_per_unit != null ? Number(raw.price_per_unit) : undefined,
    length_mm: raw.length_mm != null ? Number(raw.length_mm) : undefined,
    width_mm: raw.width_mm != null ? Number(raw.width_mm) : undefined,
    thickness_mm: raw.thickness_mm != null ? Number(raw.thickness_mm) : undefined,
    unit: raw.unit || undefined,
    updated_at: raw.updated_at || undefined,
    metadata: raw.metadata || undefined,
  }
}

const mergeMaterialOptions = (target: Map<number, Material>, rows: any[]) => {
  for (const row of rows || []) {
    const normalized = normalizeMaterialOption(row)
    if (!normalized) continue
    target.set(normalized.id, normalized)
  }
}

const mergeMaterialsIntoState = (rows: any[]) => {
  if (!rows || rows.length === 0) return

  const byId = new Map<number, Material>()
  mergeMaterialOptions(byId, materials.value)
  mergeMaterialOptions(byId, rows)
  materials.value = Array.from(byId.values()).sort((a, b) => a.name.localeCompare(b.name, 'ru'))
}

type CatalogMaterialMode = 'my' | 'public'

const fetchCatalogModeMaterials = async (
  mode: CatalogMaterialMode,
  options: {
    type?: 'plate' | 'edge' | 'hardware'
    search?: string
    perPage?: number
    maxPages?: number
  } = {}
): Promise<any[]> => {
  const allRows: any[] = []
  let page = 1
  let lastPage = 1
  const perPage = options.perPage || 200
  const maxPages = options.maxPages || Number.POSITIVE_INFINITY

  do {
    const response = await api.get('/api/materials/catalog', {
      params: {
        mode,
        type: options.type,
        search: options.search || undefined,
        per_page: perPage,
        page,
      },
    })

    const rows = response?.data?.data
    if (Array.isArray(rows)) {
      allRows.push(...rows)
    }

    lastPage = Number(response?.data?.meta?.last_page || 1)
    page += 1
  } while (page <= lastPage && page <= maxPages)

  return allRows
}

const loadSelectableMaterials = async (force = false): Promise<Material[]> => {
  const now = Date.now()
  if (!force && selectableMaterialsCache.value && selectableMaterialsCache.value.expiresAt > now) {
    return selectableMaterialsCache.value.data
  }

  const byId = new Map<number, Material>()
  const preloadTypes: Array<'plate' | 'edge' | 'hardware'> = ['plate', 'edge', 'hardware']
  const preloadRequests = preloadTypes.flatMap((type) =>
    (['my', 'public'] as CatalogMaterialMode[]).map(async (mode) => {
      try {
        const rows = await fetchCatalogModeMaterials(mode, { type })
        mergeMaterialOptions(byId, rows)
      } catch (error) {
        console.warn(`Failed to load catalog materials mode: ${mode}, type: ${type}`, error)
      }
    })
  )

  await Promise.all(preloadRequests)

  const merged = Array.from(byId.values()).sort((a, b) => a.name.localeCompare(b.name, 'ru'))
  selectableMaterialsCache.value = {
    data: merged,
    expiresAt: Date.now() + MATERIALS_CACHE_TTL_MS,
  }

  return merged
}

const ensureProjectMaterialsLoaded = async () => {
  const ids = new Set<number>()

  const addId = (value?: number | string | null) => {
    const id = Number(value)
    if (Number.isFinite(id) && id > 0) ids.add(id)
  }

  addId(project.value?.default_plate_material_id)
  addId(project.value?.default_edge_material_id)

  positions.value.forEach((position) => {
    addId(position.material_id)
    addId(position.edge_material_id)
  })
  fittings.value.forEach((fitting) => addId(fitting.material_id))

  const missingIds = Array.from(ids).filter((id) => !materials.value.some((material) => material.id === id))
  if (missingIds.length === 0) return

  const loadedRows = await Promise.all(
    missingIds.map(async (id) => {
      try {
        const response = await api.get(`/api/materials/${id}`)
        return response.data
      } catch (error) {
        console.warn(`Failed to load project material #${id}`, error)
        return null
      }
    })
  )

  mergeMaterialsIntoState(loadedRows.filter(Boolean))
}

const searchMaterialOptions = async (type: 'plate' | 'edge', searchRaw: string) => {
  const search = String(searchRaw || '').trim()
  const requestId = ++materialSearchRequestIds[type]

  if (search.length < MATERIAL_SEARCH_MIN_LENGTH) {
    materialSearchLoading[type] = false
    return
  }

  materialSearchLoading[type] = true

  try {
    const modes: CatalogMaterialMode[] = ['my', 'public']
    const results = await Promise.all(
      modes.map(async (mode) => {
        try {
          return await fetchCatalogModeMaterials(mode, {
            type,
            search,
            perPage: 50,
            maxPages: 1,
          })
        } catch (error) {
          console.warn(`Failed to search materials mode: ${mode}, type: ${type}`, error)
          return []
        }
      })
    )

    if (requestId === materialSearchRequestIds[type]) {
      mergeMaterialsIntoState(results.flat())
    }
  } finally {
    if (requestId === materialSearchRequestIds[type]) {
      materialSearchLoading[type] = false
    }
  }
}

const onMaterialSearch = (type: 'plate' | 'edge', value: string) => {
  if (materialSearchTimeouts[type]) {
    clearTimeout(materialSearchTimeouts[type])
  }

  materialSearchTimeouts[type] = setTimeout(() => {
    searchMaterialOptions(type, value)
  }, MATERIAL_SEARCH_DEBOUNCE_MS)
}

const loadUserDefaultFacadeAllowances = async () => {
  try {
    const settings = await api.get('/api/user/settings').then(r => r.data || {})
    userDefaultFacadeAllowances.value = {
      width: Number(settings.facade_width_allowance_mm) || 0,
      height: Number(settings.facade_height_allowance_mm) || 0,
    }
  } catch (error) {
    console.warn('Failed to load user facade allowances', error)
  }
}

// === Загрузка данных ===
const loadReferences = async () => {
  try {
    loadingMessage.value = 'Подтягиваем материалы и справочники…'
    loadingStates.value.materials = true
    const [types, mats, ops, unitsList, regionsData, profiles] = await Promise.all([
      api.get('/api/detail-types').then(r => r.data),
      loadSelectableMaterials(),
      api.get('/api/operations').then(r => r.data),
      api.get('/api/units').then(r => r.data),
      api.get('/api/regions').then(r => r.data?.data || []),
      api.get('/api/pricing/labor/profiles').then(r => r.data?.data || [])
    ])
    detailTypes.value = types
    materials.value = mats
    console.log('Materials loaded:', mats.length, 'samples:', mats.slice(0, 2))
    allOperations.value = ops
    units.value = unitsList || []
    regions.value = regionsData
    laborProfiles.value = profiles
    console.log('Labor profiles loaded:', profiles.length, 'profiles:', profiles.map((p: any) => ({ id: p.id, title: p.title })))
    console.log('Regions loaded:', regionsData.length, 'samples:', regionsData.slice(0, 3))
  } finally {
    loadingStates.value.materials = false
  }
}

const fetchData = async (): Promise<boolean> => {
  try {
    suppressAutoSave.value = true
    isProjectLoaded.value = false
    loadingMessage.value = 'Загружаем проект…'
    loadingStates.value.positions = true
    loadingStates.value.fittings = true
    loadingStates.value.expenses = true
    
    const prefetchedProject = consumePrefetchedProject(projectRouteIdentifier)
    project.value = prefetchedProject || (await api.get(`/api/projects/${encodeURIComponent(projectRouteIdentifier)}`)).data
    await loadUserDefaultFacadeAllowances()
    projectId = String(project.value.id || projectRouteIdentifier)
    projectApiId.value = projectId

    if (/^\d+$/.test(projectRouteIdentifier) && project.value.public_id) {
      await router.replace({
        name: 'ProjectEditorView',
        params: { projectPublicId: project.value.public_id },
      })
    }
    const pr = (project.value as any).profileRates
    console.log('✅ Project loaded:', {
      id: project.value.id,
      name: project.value.number,
      hasProfileRates: !!pr,
      profileRatesCount: Array.isArray(pr) ? pr.length : 0,
      profileRatesType: typeof pr
    })
    if (pr && pr.length > 0) {
      console.log('📊 First profileRate structure:', JSON.stringify(pr[0], null, 2))
    }
    
    // Явно преобразуем use_area_calc_mode в boolean (исправляем undefined)
    project.value.use_area_calc_mode = (project.value.use_area_calc_mode as any) === true || (project.value.use_area_calc_mode as any) === 1
    
    // Явно преобразуем флаги применения коэффициентов в boolean (по умолчанию true)
    project.value.apply_waste_to_plate = (project.value.apply_waste_to_plate as any) !== false && (project.value.apply_waste_to_plate as any) !== 0
    project.value.apply_waste_to_edge = (project.value.apply_waste_to_edge as any) !== false && (project.value.apply_waste_to_edge as any) !== 0
    project.value.apply_waste_to_operations = (project.value.apply_waste_to_operations as any) !== false && (project.value.apply_waste_to_operations as any) !== 0
    
    // Гарантируем что normohour_rate всегда число
    project.value.normohour_rate = Number(project.value.normohour_rate) || 0
    
    // Коэффициенты: общий = 1.0 по умолчанию, специфичные = null (fallback)
    project.value.waste_coefficient = Number(project.value.waste_coefficient) || 1.0
    project.value.waste_plate_coefficient = project.value.waste_plate_coefficient ? Number(project.value.waste_plate_coefficient) : null
    project.value.waste_edge_coefficient = project.value.waste_edge_coefficient ? Number(project.value.waste_edge_coefficient) : null
    project.value.waste_operations_coefficient = project.value.waste_operations_coefficient ? Number(project.value.waste_operations_coefficient) : null
    
    // Ремонтный коэффициент
    project.value.repair_coefficient = Number(project.value.repair_coefficient) || 1.0
    
    // Гарантируем что boolean флаги всегда boolean (конвертируем 0/1 в false/true)
    project.value.show_waste_plate_description = (project.value.show_waste_plate_description as any) === true || (project.value.show_waste_plate_description as any) === 1
    project.value.show_waste_edge_description = (project.value.show_waste_edge_description as any) === true || (project.value.show_waste_edge_description as any) === 1
    project.value.show_waste_operations_description = (project.value.show_waste_operations_description as any) === true || (project.value.show_waste_operations_description as any) === 1
    
    // Текстовые блоки: парсим и конвертируем в новый формат если нужно
    if (!project.value.text_blocks) {
      project.value.text_blocks = []
    } else if (typeof project.value.text_blocks === 'string') {
      try {
        const parsed = JSON.parse(project.value.text_blocks)
        // Если это массив строк (старый формат), конвертируем в новый формат
        if (Array.isArray(parsed) && parsed.length > 0 && typeof parsed[0] === 'string') {
          project.value.text_blocks = parsed.map((text: string) => ({
            title: '',
            text: text || '',
            enabled: true
          }))
        } else if (Array.isArray(parsed)) {
          // Конвертируем enabled поле в boolean
          project.value.text_blocks = parsed.map((block: any) => ({
            ...block,
            enabled: block.enabled === true || block.enabled === 1
          }))
        } else {
          project.value.text_blocks = []
        }
      } catch {
        project.value.text_blocks = []
      }
    } else if (Array.isArray(project.value.text_blocks) && project.value.text_blocks.length > 0) {
      // Если это массив объектов, убеждаемся что это правильный формат
      if (typeof project.value.text_blocks[0] === 'string') {
        project.value.text_blocks = (project.value.text_blocks as any[]).map((text: string) => ({
          title: '',
          text: text || '',
          enabled: true
        }))
      }
    }
    
    loadingMessage.value = 'Считаем позиции и суммы…'
    positions.value = (await api.get(`/api/projects/${projectId}/positions`)).data
    fittings.value = (await api.get(`/api/projects/${projectId}/fittings`)).data
    expenses.value = (await api.get(`/api/projects/${projectId}/expenses`)).data
    await ensureProjectMaterialsLoaded()
    
    console.log('Loaded positions:', positions.value)
    console.log('Loaded expenses:', expenses.value)
    if (expenses.value && expenses.value.length > 0) {
      console.log('First expense structure:', JSON.stringify(expenses.value[0], null, 2))
    }
    console.log('Available materials:', materials.value.slice(0, 3))
    console.log('Project coefficients:', {
      waste_coefficient: project.value.waste_coefficient,
      waste_plate_coefficient: project.value.waste_plate_coefficient,
      waste_edge_coefficient: project.value.waste_edge_coefficient,
      waste_operations_coefficient: project.value.waste_operations_coefficient
    })
    console.log('📝 Text blocks loaded:', project.value.text_blocks)

    loadingMessage.value = 'Подтягиваем операции и работы…'
    // Загрузка операций через встроенную функцию
    await recalcOperations()
    
    // Загрузка монтажно-сборочных работ
    await loadLaborWorks()
    
    // Финальная проверка profileRates
    const finalPr = (project.value as any).profileRates
    console.log('✅ fetchData completed, final profileRates check:', {
      hasProfileRates: !!finalPr,
      count: Array.isArray(finalPr) ? finalPr.length : 0,
      rates: finalPr ? (Array.isArray(finalPr) ? finalPr.map((r: any) => ({ id: r.id, is_locked: r.is_locked, method: r.calculation_method })) : []) : []
    })
    return true
  } catch (error: any) {
    console.error('Ошибка загрузки данных проекта:', error)
    if (isMissingProjectError(error)) {
      redirectToProjectsWithMissingMessage()
      return false
    }

    showNotification(`Не удалось загрузить проект: ${error.response?.data?.message || error.message}`, 'error')
    return false
  } finally {
    loadingStates.value.positions = false
    loadingStates.value.fittings = false
    loadingStates.value.expenses = false
    suppressAutoSave.value = false
    isProjectLoaded.value = true
  }
}

const refreshAll = async () => {
  refreshing.value = true
  try {
    await Promise.all([loadReferences(), fetchData(), fetchLatestRevision(), fetchRevisions(1)])
    if (activeRevisionRun.value) {
      await refreshRevisionRun(true)
    }
  } catch (e) {
    console.error('Refresh failed', e)
    showNotification('Не удалось обновить данные', 'error')
  } finally {
    refreshing.value = false
  }
}

// === Позиции ===
const openPositionDialog = async () => {
  if (positionDialogOpening.value) return
  if (guardReadOnlyAction()) return

  positionDialogOpening.value = true
  await loadUserDefaultFacadeAllowances()

  editingPosition.value = false
  dialogDimensionCalc.value = { width: emptyDimensionCalcState(), length: emptyDimensionCalcState() }
  dialogDimensionInputDirty.value = { width: false, length: false }
  applyFacadeAllowance.value = false
  positionFormModel.value = {
    id: null, project_id: projectId,
    kind: 'panel',
    detail_type_id: null, 
    material_id: project.value?.default_plate_material_id || null,
    facade_material_id: null,
    finished_product_specification_id: null,
    material_price_id: null,
    edge_material_id: project.value?.default_edge_material_id || null,
    edge_scheme: 'none', width: 0, length: 0, quantity: 1, custom_name: null,
    decor_label: null, thickness_mm: null, base_material_label: null,
    finish_type: null, finish_name: null, price_per_m2: null,
  }
  loadFacadeSpecifications()
  positionDialog.value = true

  // Refresh materials in the background so the dialog gives immediate feedback.
  try {
    mergeMaterialsIntoState(await loadSelectableMaterials())
  } catch (error) {
    console.warn('Failed to refresh selectable materials after opening position dialog', error)
  } finally {
    positionDialogOpening.value = false
  }
}

const editPosition = (item: Position) => {
  if (guardReadOnlyAction()) return

  editingPosition.value = true
  dialogDimensionCalc.value = { width: emptyDimensionCalcState(), length: emptyDimensionCalcState() }
  dialogDimensionInputDirty.value = { width: false, length: false }
  applyFacadeAllowance.value = false
  positionFormModel.value = { ...item }
  // if position has a detail type and no explicit edge_scheme, inherit from detail type
  if (!positionFormModel.value.edge_scheme && positionFormModel.value.detail_type_id) {
    const dt = detailTypes.value.find(d => d.id === positionFormModel.value.detail_type_id)
    if (dt && dt.edge_processing) positionFormModel.value.edge_scheme = dt.edge_processing
  }
  if (item.kind === 'facade') {
    loadFacadeSpecifications()
    ensureFacadeSpecificationLoaded(item.finished_product_specification_id)
  }
  positionDialog.value = true
}

const savePosition = async () => {
  if (positionSaving.value) return
  if (guardReadOnlyAction()) return

  positionSaving.value = true

  const { valid } = await positionForm.value.validate()
  if (!valid) {
    positionSaving.value = false
    return
  }

  try {
    // Для фасадов — очистить поля панели перед сохранением
    if (positionFormModel.value.kind === 'facade') {
      positionFormModel.value.material_id = null
      positionFormModel.value.edge_material_id = null
      positionFormModel.value.edge_scheme = 'none'
      positionFormModel.value.detail_type_id = null
      positionFormModel.value.facade_material_id = null
      positionFormModel.value.material_price_id = null
    } else {
      // Для панелей — очистить поля фасада
      positionFormModel.value.facade_material_id = null
      positionFormModel.value.finished_product_specification_id = null
      positionFormModel.value.material_price_id = null
      positionFormModel.value.decor_label = null
      positionFormModel.value.thickness_mm = null
      positionFormModel.value.base_material_label = null
      positionFormModel.value.finish_type = null
      positionFormModel.value.finish_name = null
      positionFormModel.value.price_per_m2 = null
      positionFormModel.value.finished_product_pricing_snapshot = null
    }

    // Проверка на размер детали больше листа (только для панелей)
    if (positionFormModel.value.kind === 'panel' && positionFormModel.value.material_id) {
      const material = materials.value.find(m => m.id === positionFormModel.value.material_id)
      
      if (material && material.type === 'plate' && (material.width_mm || material.length_mm)) {
        const detailWidth = positionFormModel.value.width || 0
        const detailLength = positionFormModel.value.length || 0
        const materialWidth = material.width_mm || 0
        const materialLength = material.length_mm || 0
        
        // Проверка: любой размер детали не должен превышать размер листа
        // Позволяем развороты детали (width может быть <= length листа и наоборот)
        const maxMaterialDim = Math.max(materialWidth, materialLength)
        const minMaterialDim = Math.min(materialWidth, materialLength)
        
        const detailExceedsBounds = 
          (detailWidth > maxMaterialDim || detailLength > maxMaterialDim) ||
          (Math.min(detailWidth, detailLength) > minMaterialDim && Math.max(detailWidth, detailLength) > maxMaterialDim)
        
        if (detailExceedsBounds && material.width_mm && material.length_mm) {
          const confirmed = confirm(
            `⚠️ ВНИМАНИЕ!\n\nРазмер детали (${detailWidth}×${detailLength} мм) превышает размер листа материала (${materialWidth}×${materialLength} мм).\n\nПроверьте корректность введённых данных.\n\nПродолжить сохранение?`
          )
          if (!confirmed) {
            console.log('Сохранение отменено пользователем из-за превышения размера')
            return
          }
        }
      }
    }

    // Sanitize payload — extract IDs from possible return-object values
    const payload = { ...positionFormModel.value }
    if (payload.material_id && typeof payload.material_id === 'object') {
      payload.material_id = (payload.material_id as any).id
    }
    if (payload.edge_material_id && typeof payload.edge_material_id === 'object') {
      payload.edge_material_id = (payload.edge_material_id as any).id
    }
    // Ensure numeric types for dimensions
    if (payload.thickness_mm != null) payload.thickness_mm = Number(payload.thickness_mm) || null

    // Sanitize finish_type — must be one of backend enum values or null
    const ALLOWED_FINISH_TYPES = ['pvc_film', 'plastic', 'enamel', 'veneer', 'solid_wood', 'aluminum_frame', 'other']
    if (payload.finish_type && !ALLOWED_FINISH_TYPES.includes(payload.finish_type)) {
      // Free-text value (e.g. Russian "Эмаль") — move to finish_name, clear finish_type
      if (!payload.finish_name) {
        payload.finish_name = payload.finish_type
      }
      payload.finish_type = null
    }

    const payloadAny = payload as any
    if (payload.kind === 'facade') {
      payloadAny.facade_material_id = null
      payloadAny.material_price_id = null
    }
    // Strip relation data if it came from editing
    delete payloadAny.price_quotes
    delete payloadAny.facade_material
    delete payloadAny.finished_product_specification
    delete payloadAny.material_price
    delete payloadAny.material

    if (editingPosition.value) {
      await api.put(`/api/project-positions/${payload.id}`, payload)
    } else {
      await api.post(`/api/projects/${projectId}/positions`, payload)
    }
    positionDialog.value = false
    await fetchData()
  } catch (e: any) {
    if (showBillingLockedError(e)) return

    console.error(e)
    const validationErrors = e.response?.data?.errors
    if (validationErrors) {
      const msgs = Object.values(validationErrors).flat().join('; ')
      console.error('Validation errors:', validationErrors)
      showNotification(`Ошибка валидации: ${msgs}`, 'error')
    } else {
      showNotification('Ошибка сохранения позиции', 'error')
    }
  } finally {
    positionSaving.value = false
  }
}

const deletePosition = async (item: Position) => {
  if (guardReadOnlyAction()) return

  if (confirm('Удалить позицию?')) {
    try {
      await api.delete(`/api/project-positions/${item.id}`)
      await fetchData()
    } catch (e: any) {
      if (showBillingLockedError(e)) return
      showNotification(`Ошибка удаления позиции: ${e.response?.data?.message || e.message}`, 'error')
    }
  }
}

const clonePosition = async (item: Position) => {
  if (guardReadOnlyAction()) return

  try {
    // Устанавливаем индикатор обработки
    processingPositionId.value = item.id || null
    
    // Копируем все важные поля позиции
    const clonedData: any = {
      project_id: projectId,
      kind: item.kind || 'panel',
      detail_type_id: item.detail_type_id,
      material_id: item.material_id,
      edge_material_id: item.edge_material_id,
      edge_scheme: item.edge_scheme,
      width: item.width,
      length: item.length,
      quantity: item.quantity,
      custom_name: item.custom_name,
      // Facade fields
      facade_material_id: item.facade_material_id,
      finished_product_specification_id: item.finished_product_specification_id,
      material_price_id: item.material_price_id,
      decor_label: item.decor_label,
      thickness_mm: item.thickness_mm,
      base_material_label: item.base_material_label,
      finish_type: item.finish_type,
      finish_name: item.finish_name,
      price_per_m2: item.price_per_m2,
    }
    
    console.log('🔄 Cloning position:', clonedData)
    const response = await api.post(`/api/projects/${projectId}/positions`, clonedData)
    console.log('✅ Position cloned successfully')
    
    await fetchData()

    const newId = response?.data?.id || response?.data?.data?.id
    if (newId) {
      highlightedPositionId.value = newId
      scrollToPositionRow(newId)
      window.setTimeout(() => {
        if (highlightedPositionId.value === newId) {
          highlightedPositionId.value = null
        }
      }, 2000)
    }
  } catch (e: any) {
    if (showBillingLockedError(e)) return

    console.error('❌ Error cloning position:', e)
    showNotification(`Ошибка при клонировании: ${e.response?.data?.message || e.message}`, 'error')
  } finally {
    // Очищаем индикатор обработки
    processingPositionId.value = null
  }
}

const updatePositionField = async (item: Position, field: string, value: any) => {
  if (guardReadOnlyAction()) return

  if (!item.id) {
    showNotification('Сначала сохраните позицию через диалог', 'warning')
    return
  }

  const payload: any = {}
  if (field === 'width' || field === 'length') {
    payload[field] = parseFloat(value) || 0
    ;(item as any)[field] = payload[field]
  } else if (field === 'quantity') {
    payload[field] = parseInt(value as any) || 1
    ;(item as any)[field] = payload[field]
  } else {
    payload[field] = value
    ;(item as any)[field] = value
  }

  try {
    const response = await api.put(`/api/project-positions/${item.id}`, payload)
    if (response?.data && typeof response.data === 'object') {
      Object.assign(item, response.data)
    }
    // trigger operations recalculation after position change
    scheduleRecalc()
  } catch (e: any) {
    if (showBillingLockedError(e)) return

    console.error(e)
    showNotification('Ошибка сохранения позиции', 'error')
    await fetchData()
  }
}

const applyingDetailTypePositionIds = ref<Set<number>>(new Set())

const setApplyingDetailType = (positionId: number, isApplying: boolean) => {
  const next = new Set(applyingDetailTypePositionIds.value)
  if (isApplying) {
    next.add(positionId)
  } else {
    next.delete(positionId)
  }
  applyingDetailTypePositionIds.value = next
}

const isApplyingDetailType = (positionId?: number | null) => {
  return typeof positionId === 'number' && applyingDetailTypePositionIds.value.has(positionId)
}

const mergeUpdatedPosition = (updatedPosition: Position) => {
  if (!updatedPosition?.id) return

  const index = positions.value.findIndex((position) => position.id === updatedPosition.id)
  if (index !== -1) {
    Object.assign(positions.value[index], updatedPosition)
  }

  if (selectedPosition.value?.id === updatedPosition.id) {
    Object.assign(selectedPosition.value, updatedPosition)
  }
}

const applyDetailTypeToPosition = async (item: Position, rawDetailTypeId: unknown) => {
  if (guardReadOnlyAction()) return

  if (!item.id) {
    showNotification('Сначала сохраните позицию через диалог', 'warning')
    return
  }

  const detailTypeId = rawDetailTypeId === null || rawDetailTypeId === ''
    ? null
    : Number(rawDetailTypeId)

  if (!detailTypeId) {
    await updatePositionField(item, 'detail_type_id', null)
    return
  }

  if (isApplyingDetailType(item.id)) return

  setApplyingDetailType(item.id, true)

  try {
    const response = await api.post(`/api/projects/${projectId}/positions/${item.id}/apply-detail-type`, {
      detail_type_id: detailTypeId,
      mode: 'missing_only',
    })

    const updatedPosition = response?.data?.position || response?.data?.data?.position
    if (updatedPosition && typeof updatedPosition === 'object') {
      mergeUpdatedPosition(updatedPosition as Position)
    }

    const applied = Array.isArray(response?.data?.applied) ? response.data.applied : []
    showNotification(
      applied.includes('edge_scheme')
        ? 'Настройки типа детали применены'
        : 'Тип детали назначен. Текущие настройки сохранены.',
      'success'
    )

    scheduleRecalc()
  } catch (e: any) {
    if (showBillingLockedError(e)) return

    console.error(e)
    showNotification('Не удалось применить тип детали', 'error')
    await fetchData()
  } finally {
    setApplyingDetailType(item.id, false)
  }
}

const formatPositionSize = (item: Position) => {
  const w = Math.round(item.width || 0)
  const l = Math.round(item.length || 0)
  if (!w && !l) return '—'
  return `${w}×${l}`
}

const getPositionAreaTotal = (item: Position): number => {
  return ((item.width || 0) / 1000) * ((item.length || 0) / 1000) * (item.quantity || 0)
}

const getPositionTotalPrice = (item: Position): number => {
  const areaTotal = getPositionAreaTotal(item)
  if (item.kind === 'facade' && (item.price_per_m2 || 0) > 0) {
    return areaTotal * (item.price_per_m2 || 0)
  }
  return (item.unit_price || 0) * (item.quantity || 0)
}

const getPositionPricePerM2Display = (item: Position): { value: number; kind: 'direct' | 'derived' } | null => {
  if ((item.price_per_m2 || 0) > 0) {
    return { value: item.price_per_m2 || 0, kind: 'direct' }
  }

  const areaTotal = getPositionAreaTotal(item)
  const totalPrice = getPositionTotalPrice(item)
  if (areaTotal > 0 && totalPrice > 0) {
    return { value: totalPrice / areaTotal, kind: 'derived' }
  }

  return null
}

const getEdgeSchemeHintClass = (dimension: 'width' | 'length') => {
  if (positionFormModel.value.kind !== 'panel') return ''

  const scheme = positionFormModel.value.edge_scheme
  if (!scheme || scheme === 'none') return ''

  if (scheme === 'O') {
    return dimension === 'width' ? 'edge-hint edge-hint-width-tb' : 'edge-hint edge-hint-length-lr'
  }

  if (scheme === '=') {
    return dimension === 'width' ? 'edge-hint edge-hint-width-tb' : ''
  }

  if (scheme === '||') {
    return dimension === 'length' ? 'edge-hint edge-hint-length-lr' : ''
  }

  if (scheme === 'long_one') {
    return dimension === 'width' ? 'edge-hint edge-hint-width-bottom' : ''
  }

  if (scheme === 'short_one') {
    return dimension === 'length' ? 'edge-hint edge-hint-length-right' : ''
  }

  if (scheme === 'L') {
    return dimension === 'width' ? 'edge-hint edge-hint-width-top' : 'edge-hint edge-hint-length-left'
  }

  if (scheme === 'П') {
    return dimension === 'width' ? 'edge-hint edge-hint-width-top' : 'edge-hint edge-hint-length-lr'
  }

  return ''
}

const isEdgeSideActive = (scheme?: string | null, side?: 'top' | 'right' | 'bottom' | 'left') => {
  if (!scheme || scheme === 'none' || !side) return false

  if (scheme === 'O') return true
  if (scheme === '=') return side === 'top' || side === 'bottom'
  if (scheme === '||') return side === 'left' || side === 'right'
  if (scheme === 'long_one') return side === 'bottom'
  if (scheme === 'short_one') return side === 'right'
  if (scheme === 'L') return side === 'top' || side === 'left'
  if (scheme === 'П') return side === 'top' || side === 'left' || side === 'right'

  return false
}

const getEdgeSchemeSummary = (scheme?: string | null) => {
  if (!scheme || scheme === 'none') return 'Кромка не применяется'
  if (scheme === 'O') return 'Кромка по периметру: верх, низ, левая и правая стороны'
  if (scheme === '=') return 'Кромка по двум длинным сторонам детали'
  if (scheme === '||') return 'Кромка по двум коротким сторонам детали'
  if (scheme === 'long_one') return 'Кромка по одной длинной стороне детали'
  if (scheme === 'short_one') return 'Кромка по одной короткой стороне детали'
  if (scheme === 'L') return 'Кромка по схеме L: верх и левая сторона'
  if (scheme === 'П') return 'Кромка по схеме П: верх, левая и правая стороны'
  return 'Схема не распознана'
}

const formatEdgeDimension = (value: unknown, fallback: string) => {
  const numeric = Number(value)
  return Number.isFinite(numeric) && numeric > 0 ? `${Math.round(numeric)} мм` : fallback
}

const clampNumber = (value: number, min: number, max: number) => Math.min(max, Math.max(min, value))

const getEdgePreviewStyle = (widthMm?: number | string | null, lengthMm?: number | string | null) => {
  const width = Number(widthMm) > 0 ? Number(widthMm) : 500
  const length = Number(lengthMm) > 0 ? Number(lengthMm) : 800
  const aspect = clampNumber(length / width, 0.65, 2.6)
  const maxWidth = 136
  const maxHeight = 92
  const minWidth = 68
  const minHeight = 48

  if (aspect >= 1) {
    return {
      width: `${maxWidth}px`,
      height: `${clampNumber(maxWidth / aspect, minHeight, maxHeight)}px`,
    }
  }

  return {
    width: `${clampNumber(maxHeight * aspect, minWidth, maxWidth)}px`,
    height: `${maxHeight}px`,
  }
}

const openPositionDrawer = (item: Position) => {
  selectedPosition.value = item
  drawerDimensionCalc.value = { width: emptyDimensionCalcState(), length: emptyDimensionCalcState() }
  if (item.kind === 'facade') {
    loadFacadeSpecifications()
    ensureFacadeSpecificationLoaded(item.finished_product_specification_id)
  }
  positionDrawer.value = true
}

const handleDrawerEdgeSchemeChange = (item: Position, value: string | null) => {
  updatePositionField(item, 'edge_scheme', value)
  if (!value || value === 'none') {
    updatePositionField(item, 'edge_material_id', null)
  }
}

const toggleSelectedPositionDimensions = async () => {
  if (!selectedPosition.value) return

  const width = selectedPosition.value.width || 0
  const length = selectedPosition.value.length || 0

  selectedPosition.value.width = length
  selectedPosition.value.length = width

  await Promise.all([
    updatePositionField(selectedPosition.value, 'width', selectedPosition.value.width),
    updatePositionField(selectedPosition.value, 'length', selectedPosition.value.length),
  ])
}

const handlePositionRowClick = (event: MouseEvent, row: { item: Position }) => {
  const target = event.target as HTMLElement
  if (
    target.closest('.v-selection-control') ||
    target.closest('button') ||
    target.closest('a') ||
    target.closest('.v-icon')
  ) {
    return
  }
  openPositionDrawer(row.item)
}

const bulkActionCatalog = [
  { title: 'Заменить материал основы', value: 'replace_material' },
  { title: 'Заменить материал кромки', value: 'replace_edge' },
  { title: 'Заменить фасад', value: 'replace_facade_material' },
  { title: 'Установить обработку торцов', value: 'set_edge_scheme' },
  { title: 'Очистить выбранное поле', value: 'clear_field' },
  { title: 'Удалить позиции', value: 'delete' },
]

const bulkClearFieldCatalog = [
  { title: 'Материал основы', value: 'material_id' },
  { title: 'Материал кромки', value: 'edge_material_id' },
  { title: 'Обработка торцов', value: 'edge_scheme' },
  { title: 'Название', value: 'custom_name' },
  { title: 'Спецификация фасада', value: 'finished_product_specification_id' },
]

const selectedBulkPositions = computed(() => {
  if (selectedPositionIds.value.length === 0) return []
  return positions.value.filter(p => p.id && selectedPositionIds.value.includes(p.id))
})

const selectedPanelPositions = computed(() =>
  selectedBulkPositions.value.filter(p => p.kind === 'panel')
)

const getBulkInapplicableReason = (
  position: Position,
  action: string,
  options: { checkEdgeMaterial?: boolean } = {}
): string | null => {
  const checkEdgeMaterial = options.checkEdgeMaterial !== false

  if (action === 'delete' || action === 'clear_field') {
    return null
  }

  if (action === 'replace_material' || action === 'replace_edge') {
    return position.kind === 'panel' ? null : 'requires_panel'
  }

  if (action === 'replace_facade_material') {
    return position.kind === 'facade' ? null : 'requires_facade'
  }

  if (action === 'set_edge_scheme') {
    if (position.kind !== 'panel') return 'requires_panel'
    if (checkEdgeMaterial && !position.edge_material_id) return 'missing_edge_material'
    return null
  }

  return null
}

const getClearFieldInapplicableReason = (position: Position, field: string): string | null => {
  if (field === 'custom_name') return null
  if (field === 'facade_material_id' || field === 'finished_product_specification_id') return position.kind === 'facade' ? null : 'requires_facade'
  if (field === 'material_id' || field === 'edge_material_id' || field === 'edge_scheme') {
    return position.kind === 'panel' ? null : 'requires_panel'
  }
  return null
}

const bulkClearFieldItems = computed(() => {
  const selected = selectedBulkPositions.value
  if (selected.length === 0) return bulkClearFieldCatalog
  return bulkClearFieldCatalog.filter((item) =>
    selected.every((pos) => !getClearFieldInapplicableReason(pos, item.value))
  )
})

const bulkActionItems = computed(() => {
  const selected = selectedBulkPositions.value
  if (selected.length === 0) {
    return bulkActionCatalog
  }
  return bulkActionCatalog.filter((item) => {
    if (item.value === 'clear_field') {
      return bulkClearFieldItems.value.length > 0
    }
    return selected.every((pos) => !getBulkInapplicableReason(pos, item.value, { checkEdgeMaterial: false }))
  })
})

const bulkActionReady = computed(() => {
  if (!bulkAction.value) return false
  if (bulkAction.value === 'replace_material') return !!bulkMaterialId.value
  if (bulkAction.value === 'replace_edge') return !!bulkEdgeMaterialId.value
  if (bulkAction.value === 'replace_facade_material') return !!bulkFacadeSpecificationId.value
  if (bulkAction.value === 'set_edge_scheme') return !!bulkEdgeScheme.value
  if (bulkAction.value === 'clear_field') return !!bulkClearField.value
  return true
})

const bulkApplicableCount = computed(() => {
  if (!bulkAction.value) return 0
  const selected = selectedBulkPositions.value
  if (selected.length === 0) return 0

  if (bulkAction.value === 'clear_field') {
    if (!bulkClearField.value) return 0
    return selected.filter(pos => !getClearFieldInapplicableReason(pos, bulkClearField.value!)).length
  }

  return selected.filter(pos => !getBulkInapplicableReason(pos, bulkAction.value!)).length
})

const bulkSkippedCount = computed(() => {
  if (!bulkAction.value) return 0
  return Math.max(0, selectedPositionIds.value.length - bulkApplicableCount.value)
})

const onPositionSelectionChange = (val: any) => {
  console.log('🟢 onPositionSelectionChange:', val, typeof val, Array.isArray(val))
  if (Array.isArray(val)) {
    selectedPositionsRaw.value = val
  }
}

const selectAllVisiblePositions = () => {
  selectedPositionsRaw.value = positions.value.map(p => p.id).filter((id): id is number => id !== null)
}

const clearSelection = () => {
  selectedPositionsRaw.value = []
}

const selectPositionsByKind = (kind: 'panel' | 'facade') => {
  selectedPositionsRaw.value = positions.value
    .filter(p => p.kind === kind && p.id != null)
    .map(p => p.id!)
}

// Auto-load facade specifications when bulk action requires them
watch(bulkAction, (val) => {
  if (val === 'replace_facade_material') {
    loadFacadeSpecifications()
  }
})

watch(bulkActionItems, (items) => {
  if (!bulkAction.value) return
  const allowed = items.some(item => item.value === bulkAction.value)
  if (!allowed) {
    bulkAction.value = null
  }
})

watch(bulkClearFieldItems, (items) => {
  if (!bulkClearField.value) return
  const allowed = items.some(item => item.value === bulkClearField.value)
  if (!allowed) {
    bulkClearField.value = null
  }
})

// Проверка, что у всех выбранных позиций назначен кромочный материал
const canSetEdgeScheme = computed(() => {
  if (selectedPanelPositions.value.length === 0) return false
  return selectedPanelPositions.value.every(p => p.edge_material_id !== null && p.edge_material_id !== undefined)
})

// Позиции без кромки (для сообщения об ошибке)
const positionsWithoutEdge = computed(() => {
  return selectedPanelPositions.value.filter(p => !p.edge_material_id)
})

const applyBulkAction = async () => {
  if (!bulkAction.value) return
  if (guardReadOnlyAction()) return

  if (bulkApplicableCount.value === 0) {
    showNotification('Для выбранного действия нет подходящих позиций', 'warning')
    return
  }

  if (bulkApplyMode.value === 'strict' && bulkSkippedCount.value > 0) {
    showNotification(`Операция недоступна: несовместимых позиций ${bulkSkippedCount.value}. Переключите режим на "Частично".`, 'warning')
    return
  }
  
  // Валидация для операции "Обработка торцов"
  if (bulkAction.value === 'set_edge_scheme') {
    if (bulkApplyMode.value === 'strict' && !canSetEdgeScheme.value) {
      const count = positionsWithoutEdge.value.length
      const names = positionsWithoutEdge.value.slice(0, 3).map(p => p.custom_name || `ID ${p.id}`).join(', ')
      const suffix = count > 3 ? ` и ещё ${count - 3}...` : ''
      showNotification(
        `Невозможно установить схему кромки: у ${count} поз. не назначен кромочный материал (${names}${suffix})`,
        'warning'
      )
      return
    }
  }
  
  confirmBulkDialog.value = false

  try {
    const payload: any = {
      action: bulkAction.value === 'delete' ? 'delete' : 'update',
      ids: selectedPositionIds.value,
      select_all: false,
      mode: bulkApplyMode.value,
    }

    if (bulkAction.value === 'replace_material') {
      payload.updates = { material_id: bulkMaterialId.value }
    } else if (bulkAction.value === 'replace_edge') {
      payload.updates = { edge_material_id: bulkEdgeMaterialId.value }
    } else if (bulkAction.value === 'replace_facade_material') {
      payload.updates = { finished_product_specification_id: bulkFacadeSpecificationId.value }
    } else if (bulkAction.value === 'set_edge_scheme') {
      payload.updates = { edge_scheme: bulkEdgeScheme.value }
    } else if (bulkAction.value === 'clear_field') {
      payload.clear_field = bulkClearField.value
    }

    const response = await api.post(`/api/projects/${projectId}/positions/bulk`, payload)
    const updated = Number(response?.data?.updated ?? 0)
    const skipped = Number(response?.data?.skipped ?? 0)
    const summary = skipped > 0
      ? `Массовая операция выполнена: обновлено ${updated}, пропущено ${skipped}`
      : `Массовая операция выполнена: обновлено ${updated}`
    showNotification(summary, 'success')
    clearSelection()
    bulkAction.value = null
    bulkApplyMode.value = 'strict'
    bulkMaterialId.value = null
    bulkEdgeMaterialId.value = null
    bulkEdgeScheme.value = null
    bulkClearField.value = null
    bulkFacadeSpecificationId.value = null
    await fetchData()
    scheduleRecalc()
  } catch (e: any) {
    if (showBillingLockedError(e)) return

    console.error(e)
    showNotification(e.response?.data?.message || 'Ошибка массовой операции', 'error')
  }
}

// === Фурнитура ===
const openFittingDialog = () => {
  if (guardReadOnlyAction()) return

  fittingSearchQuery.value = ''
  refreshHardwareMaterials('')
  editingFitting.value = false
  const defaultUnit = units.value[0] || 'шт'
  fittingForm.value = {
    id: 0,
    project_id: projectId,
    material_id: null,
    name: '',
    article: '',
    unit: defaultUnit,
    quantity: 1,
    unit_price: 0,
    source_url: null,
    note: '',
  }
  fittingDialog.value = true
}

const editFitting = (item: Fitting) => {
  if (guardReadOnlyAction()) return

  fittingSearchQuery.value = ''
  refreshHardwareMaterials('')
  editingFitting.value = true
  const defaultUnit = units.value[0] || 'шт'
  const materialId = resolveFittingMaterialId(item)
  fittingForm.value = {
    ...item,
    material_id: materialId,
    unit: item.unit || defaultUnit,
    source_url: item.source_url || null,
    note: item.note ?? '',
  }
  if (materialId) {
    onFittingMaterialChange(materialId)
  }
  fittingDialog.value = true
}

const saveFitting = async () => {
  if (fittingSaving.value) return
  if (guardReadOnlyAction()) return

  if (!fittingForm.value.material_id) {
    showNotification('Выберите материал фурнитуры', 'warning')
    return
  }

  fittingSaving.value = true

  try {
    const material = mergedHardwareMaterials.value.find((entry) => entry.id === fittingForm.value.material_id)
    const payload = {
      material_id: fittingForm.value.material_id,
      name: material?.name || fittingForm.value.name,
      article: material?.article || fittingForm.value.article,
      unit: material?.unit || fittingForm.value.unit || units.value[0] || 'шт',
      quantity: Number(fittingForm.value.quantity) || 0,
      unit_price: Number(material?.price_per_unit ?? fittingForm.value.unit_price) || 0,
      source_url: material?.source_url || fittingForm.value.source_url || null,
      note: fittingForm.value.note || null,
    }

    if (editingFitting.value) {
      await api.put(`/api/project-fittings/${fittingForm.value.id}`, payload)
    } else {
      await api.post(`/api/projects/${projectId}/fittings`, payload)
    }
    fittingDialog.value = false
    await fetchData()
  } catch (e) {
    if (showBillingLockedError(e)) return

    const err: any = e
    const validation = err?.response?.data?.errors
    const message = validation
      ? Object.values(validation).flat().join('\n')
      : err?.response?.data?.message || err?.message || 'Ошибка сохранения фурнитуры'
    showNotification(message, 'error')
  } finally {
    fittingSaving.value = false
  }
}

const deleteFitting = async (item: Fitting) => {
  if (guardReadOnlyAction()) return

  if (confirm('Удалить фурнитуру?')) {
    try {
      await api.delete(`/api/project-fittings/${item.id}`)
      await fetchData()
    } catch (e: any) {
      if (showBillingLockedError(e)) return
      showNotification(`Ошибка удаления фурнитуры: ${e.response?.data?.message || e.message}`, 'error')
    }
  }
}

// === Проект ===
const updateProject = async () => {
  if (guardReadOnlyAction()) return

  try {
    // Убеждаемся что все коэффициенты числовые и не отрицательные
    const waste_coeff_value = Number(project.value.waste_coefficient)
    const waste_plate_value = project.value.waste_plate_coefficient ? Number(project.value.waste_plate_coefficient) : null
    const waste_edge_value = project.value.waste_edge_coefficient ? Number(project.value.waste_edge_coefficient) : null
    const waste_operations_value = project.value.waste_operations_coefficient ? Number(project.value.waste_operations_coefficient) : null
    
    // Валидация на отрицательные значения
    if (waste_coeff_value < 0) {
      showNotification('Коэффициент обрезков не может быть отрицательным', 'warning')
      return
    }
    if (waste_plate_value !== null && waste_plate_value < 0) {
      showNotification('Коэффициент для плитных материалов не может быть отрицательным', 'warning')
      return
    }
    if (waste_edge_value !== null && waste_edge_value < 0) {
      showNotification('Коэффициент для кромки не может быть отрицательным', 'warning')
      return
    }
    if (waste_operations_value !== null && waste_operations_value < 0) {
      showNotification('Коэффициент для операций не может быть отрицательным', 'warning')
      return
    }
    
    const dataToSave = {
      ...project.value,
      // Явно конвертируем коэффициенты
      waste_coefficient: Math.max(0, waste_coeff_value) || 1.0,
      waste_plate_coefficient: waste_plate_value !== null ? Math.max(0, waste_plate_value) : null,
      waste_edge_coefficient: waste_edge_value !== null ? Math.max(0, waste_edge_value) : null,
      waste_operations_coefficient: waste_operations_value !== null ? Math.max(0, waste_operations_value) : null,
      // Явно конвертируем boolean флаги
      apply_waste_to_plate: project.value.apply_waste_to_plate === true,
      apply_waste_to_edge: project.value.apply_waste_to_edge === true,
      apply_waste_to_operations: project.value.apply_waste_to_operations === true,
      use_area_calc_mode: project.value.use_area_calc_mode === true,
      // Остальные коэффициенты
      repair_coefficient: Math.max(0, Number(project.value.repair_coefficient) || 1.0),
      // ID региона для расчета ставок
      region_id: project.value.region_id ? Number(project.value.region_id) : null,
      // Текстовые блоки - сохраняем rich-text разметку
      text_blocks: project.value.text_blocks && Array.isArray(project.value.text_blocks)
        ? project.value.text_blocks
            .map((block: TextBlock) => ({
              title: (block.title || '').trim(),
              text: normalizeRichTextValue(block.text || '').substring(0, 10000),
              enabled: block.enabled !== false
            }))
        : [],
      // Описания коэффициентов отходов
      waste_plate_description: project.value.waste_plate_description ? {
        title: project.value.waste_plate_description.title.trim(),
        text: cleanText(project.value.waste_plate_description.text).substring(0, 3000)
      } : null,
      show_waste_plate_description: project.value.show_waste_plate_description === true,
      waste_edge_description: project.value.waste_edge_description ? {
        title: project.value.waste_edge_description.title.trim(),
        text: cleanText(project.value.waste_edge_description.text).substring(0, 3000)
      } : null,
      show_waste_edge_description: project.value.show_waste_edge_description === true,
      waste_operations_description: project.value.waste_operations_description ? {
        title: project.value.waste_operations_description.title.trim(),
        text: cleanText(project.value.waste_operations_description.text).substring(0, 3000)
      } : null,
      show_waste_operations_description: project.value.show_waste_operations_description === true,
      // Нормо-час монтажно-сборочных работ
      normohour_rate: project.value.normohour_rate ? Number(project.value.normohour_rate) : null,
      normohour_region: project.value.normohour_region ? project.value.normohour_region.trim() : null,
      normohour_date: project.value.normohour_date || null,
      normohour_method: project.value.normohour_method || null,
      normohour_justification: project.value.normohour_justification ? cleanText(project.value.normohour_justification).substring(0, 5000) : null
    }
    
    console.log('💾 Before save - project values in memory:', {
      waste_coefficient: project.value.waste_coefficient,
      waste_plate_coefficient: project.value.waste_plate_coefficient,
      waste_edge_coefficient: project.value.waste_edge_coefficient,
      waste_operations_coefficient: project.value.waste_operations_coefficient,
      apply_waste_to_plate: project.value.apply_waste_to_plate,
      apply_waste_to_edge: project.value.apply_waste_to_edge,
      apply_waste_to_operations: project.value.apply_waste_to_operations,
      text_blocks: project.value.text_blocks
    })
    
    console.log('💾 Saving project with full data:', {
      waste_coefficient: dataToSave.waste_coefficient,
      waste_plate_coefficient: dataToSave.waste_plate_coefficient,
      waste_edge_coefficient: dataToSave.waste_edge_coefficient,
      waste_operations_coefficient: dataToSave.waste_operations_coefficient,
      apply_waste_to_plate: dataToSave.apply_waste_to_plate,
      apply_waste_to_edge: dataToSave.apply_waste_to_edge,
      apply_waste_to_operations: dataToSave.apply_waste_to_operations,
      use_area_calc_mode: dataToSave.use_area_calc_mode,
      repair_coefficient: dataToSave.repair_coefficient,
      text_blocks: dataToSave.text_blocks,
      show_waste_plate_description: dataToSave.show_waste_plate_description,
      show_waste_edge_description: dataToSave.show_waste_edge_description,
      show_waste_operations_description: dataToSave.show_waste_operations_description,
      normohour_rate: dataToSave.normohour_rate,
      normohour_region: dataToSave.normohour_region,
      normohour_date: dataToSave.normohour_date,
      normohour_method: dataToSave.normohour_method
    })
    
    const response = await api.put(`/api/projects/${projectId}`, dataToSave)
    console.log('✅ Project saved successfully, response data:', response.data)
    
    // Явно обновляем project.value с ответом сервера
    if (response.data) {
      console.log('📥 Updating project from server response...')
      suppressAutoSave.value = true
      project.value = response.data
      
      // Убеждаемся что все значения правильного типа после обновления
      project.value.use_area_calc_mode = (project.value.use_area_calc_mode as any) === true || (project.value.use_area_calc_mode as any) === 1
      project.value.apply_waste_to_plate = (project.value.apply_waste_to_plate as any) !== false && (project.value.apply_waste_to_plate as any) !== 0
      project.value.apply_waste_to_edge = (project.value.apply_waste_to_edge as any) !== false && (project.value.apply_waste_to_edge as any) !== 0
      project.value.apply_waste_to_operations = (project.value.apply_waste_to_operations as any) !== false && (project.value.apply_waste_to_operations as any) !== 0
      project.value.waste_coefficient = Number(project.value.waste_coefficient) || 1.0
      project.value.waste_plate_coefficient = project.value.waste_plate_coefficient ? Number(project.value.waste_plate_coefficient) : null
      project.value.waste_edge_coefficient = project.value.waste_edge_coefficient ? Number(project.value.waste_edge_coefficient) : null
      project.value.waste_operations_coefficient = project.value.waste_operations_coefficient ? Number(project.value.waste_operations_coefficient) : null
      project.value.repair_coefficient = Number(project.value.repair_coefficient) || 1.0
      
      // Инициализируем текстовые блоки
      if (!project.value.text_blocks) {
        project.value.text_blocks = []
      } else if (typeof project.value.text_blocks === 'string') {
        try {
          project.value.text_blocks = JSON.parse(project.value.text_blocks)
          if (!Array.isArray(project.value.text_blocks)) {
            project.value.text_blocks = []
          }
        } catch (e) {
          project.value.text_blocks = []
        }
      }
      
      console.log('✅ Project values after update:', {
        waste_coefficient: project.value.waste_coefficient,
        waste_plate_coefficient: project.value.waste_plate_coefficient,
        waste_edge_coefficient: project.value.waste_edge_coefficient,
        waste_operations_coefficient: project.value.waste_operations_coefficient,
        text_blocks: project.value.text_blocks
      })
      suppressAutoSave.value = false
    } else {
      // Если ответ не содержит данные, перезагружаем всё с сервера
      console.log('⚠️ Server response empty, reloading from server...')
      await fetchData()
    }
    } catch (error: any) {
      if (showBillingLockedError(error)) return

      console.error('❌ Error saving project:', error)
      const validationErrors = error.response?.data?.errors
      const message = validationErrors
        ? Object.values(validationErrors).flat().join('; ')
        : error.response?.data?.message || error.message
      showNotification(`Ошибка сохранения: ${message}`, 'error')
    }
  }

// === Управление источниками нормо-часов ===
const loadNormohourSources = async () => {
  try {
    loadingStates.value.normohourSources = true
    const response = await api.get(`/api/projects/${projectId}/normohour-sources`)
    normohourSources.value = response.data || []
    console.log('📊 Normohour sources loaded:', normohourSources.value)
  } catch (error: any) {
    console.error('❌ Error loading normohour sources:', error)
    normohourSources.value = []
  } finally {
    loadingStates.value.normohourSources = false
  }
}

const openNormohourSourceDialog = (source?: NormohourSource) => {
  editingNormohourSource.value = !!source
  normohourSourceValidation.value = {}
  
  if (source) {
    normohourSourceForm.value = { ...source }
  } else {
    normohourSourceForm.value = {
      source: '',
      position_profile: null,
      salary_range: null,
      period: null,
      link: null,
      note: null
    }
  }
  
  normohourSourceDialog.value = true
}

const closeNormohourSourceDialog = () => {
  normohourSourceDialog.value = false
  editingNormohourSource.value = false
  normohourSourceForm.value = {
    source: '',
    position_profile: null,
    salary_range: null,
    period: null,
    link: null,
    note: null
  }
  normohourSourceValidation.value = {}
}

const validateNormohourSourceForm = (): boolean => {
  normohourSourceValidation.value = {}
  
  if (!normohourSourceForm.value.source || !normohourSourceForm.value.source.trim()) {
    normohourSourceValidation.value.source = 'Источник обязателен'
  }
  
  if (normohourSourceForm.value.link && normohourSourceForm.value.link.trim()) {
    // Простая валидация URL
    const urlPattern = /^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/
    if (!urlPattern.test(normohourSourceForm.value.link.trim())) {
      normohourSourceValidation.value.link = 'Некорректная ссылка'
    }
  }
  
  return Object.keys(normohourSourceValidation.value).length === 0
}

const saveNormohourSource = async () => {
  if (normohourSourceSaving.value) return
  if (guardReadOnlyAction()) return

  normohourSourceSaving.value = true

  if (!validateNormohourSourceForm()) {
    normohourSourceSaving.value = false
    return
  }
  
  try {
    const dataToSave = {
      source: normohourSourceForm.value.source.trim(),
      position_profile: normohourSourceForm.value.position_profile ? normohourSourceForm.value.position_profile.trim() : null,
      salary_range: normohourSourceForm.value.salary_range ? normohourSourceForm.value.salary_range.trim() : null,
      period: normohourSourceForm.value.period ? normohourSourceForm.value.period.trim() : null,
      link: normohourSourceForm.value.link ? normohourSourceForm.value.link.trim() : null,
      note: normohourSourceForm.value.note ? normohourSourceForm.value.note.trim() : null
    }
    
    if (editingNormohourSource.value && normohourSourceForm.value.id) {
      // Update
      await api.put(`/api/projects/${projectId}/normohour-sources/${normohourSourceForm.value.id}`, dataToSave)
      console.log('✅ Normohour source updated')
    } else {
      // Create
      if (normohourSources.value.length >= 20) {
        showNotification('Максимум 20 источников', 'warning')
        return
      }
      await api.post(`/api/projects/${projectId}/normohour-sources`, dataToSave)
      console.log('✅ Normohour source created')
    }
    
    await loadNormohourSources()
    closeNormohourSourceDialog()
  } catch (error: any) {
    if (showBillingLockedError(error)) return

    console.error('❌ Error saving normohour source:', error)
    const message = error.response?.data?.message || error.message
    showNotification(`Ошибка: ${message}`, 'error')
  } finally {
    normohourSourceSaving.value = false
  }
}

const deleteNormohourSource = async (id: number) => {
  if (guardReadOnlyAction()) return

  if (!confirm('Вы уверены, что хотите удалить этот источник?')) {
    return
  }
  
  try {
    await api.delete(`/api/projects/${projectId}/normohour-sources/${id}`)
    console.log('✅ Normohour source deleted')
    await loadNormohourSources()
  } catch (error: any) {
    if (showBillingLockedError(error)) return

    console.error('❌ Error deleting normohour source:', error)
    showNotification(`Ошибка удаления: ${error.response?.data?.message || error.message}`, 'error')
  }
}

// === Расчёт и PDF ===
const calculate = async () => {
  const res = await api.post('/api/smeta/calculate', { project_id: projectId })
  console.log('Смета:', res.data)
}

const generatePdf = async () => {
  if (guardReadOnlyAction()) return

  if (estimateHardInvalid.value) {
    showNotification('Смета содержит ошибки и не может быть использована', 'error')
    return
  }

  if (!hasRevisions.value) {
    showNotification('Сначала создайте ревизию проекта, затем можно сформировать PDF', 'warning')
    return
  }

  pdfLoading.value = true
  try {
    const res = await api.get(`/api/smeta/pdf/${projectId}`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = `smeta_${projectId}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  } catch (error: any) {
    if (showBillingLockedError(error)) return

    console.error('❌ PDF generation error:', error)
    showNotification(`Ошибка генерации PDF: ${error.response?.data?.message || error.message}`, 'error')
  } finally {
    pdfLoading.value = false
  }
}

const getStoredRevisionRunId = (): number | null => {
  const raw = localStorage.getItem(`project:${projectId}:activeRevisionRunId`)
  if (!raw) return null
  const num = Number(raw)
  return Number.isFinite(num) && num > 0 ? num : null
}

const setStoredRevisionRunId = (runId: number | null) => {
  const key = `project:${projectId}:activeRevisionRunId`
  if (!runId) {
    localStorage.removeItem(key)
    return
  }
  localStorage.setItem(key, String(runId))
}

const formatRunStatus = (status?: string) => {
  switch (status) {
    case 'PENDING':
      return 'в очереди'
    case 'IN_PROGRESS':
      return 'выполняется'
    case 'NEEDS_MANUAL':
      return 'требуется ручное закрытие'
    case 'READY':
      return 'готово к финализации'
    case 'FINALIZED':
      return 'финализировано'
    case 'FAILED':
      return 'ошибка'
    default:
      return status || '—'
  }
}

const getRunStatusColor = (status?: string) => {
  switch (status) {
    case 'PENDING':
      return 'grey'
    case 'IN_PROGRESS':
      return 'primary'
    case 'NEEDS_MANUAL':
      return 'warning'
    case 'READY':
      return 'success'
    case 'FINALIZED':
      return 'success'
    case 'FAILED':
      return 'error'
    default:
      return 'grey'
  }
}

const formatRunItemStatus = (status?: string) => {
  switch (status) {
    case 'PENDING':
      return 'в обработке'
    case 'OK':
      return 'OK'
    case 'OK_NO_PRICE':
      return 'OK без цены'
    case 'BLOCKED':
      return 'blocked'
    case 'TIMEOUT':
      return 'timeout'
    case 'PARSE_ERROR':
      return 'parse error'
    case 'NO_TEMPLATE':
      return 'no template'
    case 'NEEDS_MANUAL':
      return 'manual'
    default:
      return status || '—'
  }
}

const getRunItemStatusColor = (status?: string) => {
  switch (status) {
    case 'PENDING':
      return 'blue-grey'
    case 'OK':
      return 'success'
    case 'OK_NO_PRICE':
      return 'warning'
    case 'BLOCKED':
      return 'error'
    case 'TIMEOUT':
      return 'warning'
    case 'PARSE_ERROR':
      return 'error'
    case 'NO_TEMPLATE':
      return 'grey'
    case 'NEEDS_MANUAL':
      return 'warning'
    default:
      return 'grey'
  }
}

const getRevisionRunItemName = (item: RevisionRunItem | any): string => {
  const material = item?.material || {}
  const position = item?.position || {}
  const fitting = item?.projectFitting || {}
  return material.name
    || fitting.name
    || position.custom_name
    || position.name
    || `Материал #${item.material_id || item.project_fitting_id || item.project_position_id || '—'}`
}

const costDriverLabels: Record<string, string> = {
  plate: 'Плита',
  edge: 'Кромка',
  facade: 'Фасад',
  fitting: 'Фурн.',
  operation: 'Опер.',
  labor_work: 'Раб.',
  expense: 'Расх.',
}

const formatCostDriverType = (type?: string | null): string => {
  return (type && costDriverLabels[type]) || type || '—'
}

const resolutionIcons: Record<string, string> = {
  auto: 'mdi-robot',
  manual: 'mdi-pencil',
  chrome_ext: 'mdi-puzzle-outline',
  internal: 'mdi-cog',
}

const resolutionLabels: Record<string, string> = {
  auto: 'Автоматически',
  manual: 'Вручную',
  chrome_ext: 'Chrome расширение',
  internal: 'Внутренний',
}

const getResolutionIcon = (source?: string | null): string => {
  return (source && resolutionIcons[source]) || 'mdi-help-circle-outline'
}

const getResolutionLabel = (source?: string | null): string => {
  return (source && resolutionLabels[source]) || source || '—'
}

const evidenceCoverage = computed(() => {
  const total = revisionRunItems.value.length
  const withEvidence = revisionRunItems.value.filter(i => i.has_evidence).length
  return { total, withEvidence }
})

const resolutionBreakdown = computed(() => {
  const counts: Record<string, number> = {}
  for (const item of revisionRunItems.value) {
    const src = item.resolved_capture_source
    if (src) counts[src] = (counts[src] || 0) + 1
  }
  return counts
})

const openEvidenceDetail = (item: RevisionRunItem) => {
  evidenceDetailItem.value = item
  expenseDocFile.value = null
  evidenceDetailDialog.value = true
}

const submitExpenseDocument = async () => {
  if (!activeRevisionRun.value || !evidenceDetailItem.value) return

  const file = Array.isArray(expenseDocFile.value) ? expenseDocFile.value[0] : expenseDocFile.value
  if (!file) return

  expenseDocLoading.value = true
  try {
    await revisionRunApi.attachDocument(activeRevisionRun.value.id, evidenceDetailItem.value.id, file)
    showNotification('Документ прикреплён', 'success')
    expenseDocFile.value = null
    await refreshRevisionRun(true)
    // Update dialog item from refreshed data
    const updated = revisionRunItems.value.find(i => i.id === evidenceDetailItem.value?.id)
    if (updated) evidenceDetailItem.value = updated
  } catch (error: any) {
    const msg = error.response?.data?.error || error.response?.data?.message || error.message
    showNotification(`Ошибка загрузки документа: ${msg}`, 'error')
  } finally {
    expenseDocLoading.value = false
  }
}

const getMaterialCatalogLink = (materialId?: number | string | null): string => {
  const id = Number(materialId)
  if (!Number.isFinite(id) || id <= 0) {
    return '/materials/catalog'
  }

  const query: Record<string, string> = { material_id: String(id) }
  if (project.value?.id) {
    query.project_id = String(project.value.id)
  }

  const resolved = router.resolve({
    name: 'catalog',
    query,
  })

  return resolved.href
}

const getFacadeSpecificationIdFromRunItem = (item: RevisionRunItem | any): number | null => {
  const position = item?.position || {}
  const rawId = position.finished_product_specification_id
    ?? item?.finished_product_specification_id
    ?? item?.finishedProductSpecificationId
    ?? null
  const id = Number(rawId)

  return Number.isFinite(id) && id > 0 ? id : null
}

const isFacadeRevisionRunItem = (item: RevisionRunItem | any): boolean => {
  return item?.cost_driver_type === 'facade' || getFacadeSpecificationIdFromRunItem(item) !== null
}

const openFacadePricingFromRunItem = (item: RevisionRunItem | any) => {
  const specificationId = getFacadeSpecificationIdFromRunItem(item)
  if (!specificationId) {
    showNotification('Не удалось определить фасад для перехода к доказательствам', 'warning')
    return
  }

  const resolved = router.resolve({
    name: 'finished-product-pricing',
    params: { id: specificationId },
  })
  window.open(resolved.href, '_blank', 'noopener,noreferrer')
}

const stopRevisionRunPolling = () => {
  if (revisionRunPollTimer.value) {
    clearInterval(revisionRunPollTimer.value)
    revisionRunPollTimer.value = null
  }
}

const startRevisionRunPolling = () => {
  if (!activeRevisionRun.value) return
  if (revisionRunPollTimer.value) return
  revisionRunPollTimer.value = window.setInterval(() => {
    void refreshRevisionRun(true)
  }, 2500)
}

const refreshRevisionRun = async (silent = false) => {
  if (!activeRevisionRun.value) return

  if (!silent) {
    revisionRunLoading.value = true
  }

  try {
    const data = await revisionRunApi.show(projectId, activeRevisionRun.value.id)
    const prevStatus = activeRevisionRun.value.status
    activeRevisionRun.value = data.run
    revisionRunItems.value = data.items || []
    setStoredRevisionRunId(data.run?.id || null)

    if (lastKnownRunStatus.value && lastKnownRunStatus.value !== data.run.status) {
      if (data.run.status === 'NEEDS_MANUAL') {
        showNotification('Ревизия требует ручного закрытия проблемных позиций', 'warning')
      }
      if (data.run.status === 'READY' && prevStatus !== 'READY') {
        // Auto-finalize when all items are OK
        stopRevisionRunPolling()
        lastKnownRunStatus.value = data.run.status
        void finalizeRevisionRun()
        return
      }
    }
    lastKnownRunStatus.value = data.run.status

    if (data.run.status === 'FINALIZED') {
      stopRevisionRunPolling()
      setStoredRevisionRunId(null)
      activeRevisionRun.value = null
      revisionRunItems.value = []
      return
    }

    if (data.run.status === 'PENDING' || data.run.status === 'IN_PROGRESS') {
      startRevisionRunPolling()
    } else {
      // Safety net: keep polling if any item is still PENDING (unprocessed)
      const hasPending = (data.items || []).some((i: any) => i.status === 'PENDING')
      if (hasPending) {
        startRevisionRunPolling()
      } else {
        stopRevisionRunPolling()
      }
    }
  } catch (error: any) {
    if (error?.response?.status === 404) {
      setStoredRevisionRunId(null)
      stopRevisionRunPolling()
      activeRevisionRun.value = null
      revisionRunItems.value = []
      return
    }
    if (!silent) {
      showNotification(`Ошибка загрузки статуса ревизии: ${error.response?.data?.message || error.message}`, 'error')
    }
  } finally {
    if (!silent) {
      revisionRunLoading.value = false
    }
  }
}

const createSnapshot = async () => {
  if (guardReadOnlyAction()) return

  if (estimateHardInvalid.value) {
    showNotification('Смета содержит ошибки и не может быть использована', 'error')
    return
  }

  snapshotLoading.value = true
  try {
    revisionRunPdfLinks.value = null
    const res = await revisionRunApi.start(projectId)
    activeRevisionRun.value = {
      id: res.run_id,
      project_id: Number(projectId),
      status: res.status,
      total_items: res.total_items,
      ok_items: 0,
      failed_items: 0,
    }
    revisionRunItems.value = []
    setStoredRevisionRunId(res.run_id)
    lastKnownRunStatus.value = res.status
    showNotification(`Сессия ревизии #${res.run_id} запущена`, 'success')
    await refreshRevisionRun()
  } catch (error: any) {
    if (showBillingLockedError(error)) return

    console.error('❌ Revision run start error:', error)
    showNotification(`Ошибка запуска ревизии: ${error.response?.data?.message || error.message}`, 'error')
  } finally {
    snapshotLoading.value = false
  }
}

const fetchLatestRevision = async () => {
  try {
    const res = await api.get(`/api/projects/${projectId}/revisions/latest`)
    if (res.data.success && res.data.revision) {
      latestRevision.value = res.data.revision
    }
  } catch (error: any) {
    console.error('❌ Failed to fetch latest revision:', error)
    if (isMissingProjectError(error)) {
      redirectToProjectsWithMissingMessage()
    }
  }
}

const fetchRevisions = async (page = revisionsPagination.page) => {
  revisionsLoading.value = true
  try {
    const res = await api.get(`/api/projects/${projectId}/revisions`, {
      params: {
        page,
        per_page: revisionsPagination.perPage
      }
    })
    if (res.data.success) {
      revisions.value = res.data.revisions || []
      const pagination = res.data.pagination || {}
      revisionsPagination.page = pagination.current_page || page
      revisionsPagination.lastPage = pagination.last_page || 1
      revisionsPagination.total = pagination.total || revisions.value.length
    }
  } catch (error: any) {
    console.error('❌ Failed to fetch revisions:', error)
    if (isMissingProjectError(error)) {
      redirectToProjectsWithMissingMessage()
      return
    }

    showNotification(`Ошибка загрузки ревизий: ${error.response?.data?.message || error.message}`, 'error')
  } finally {
    revisionsLoading.value = false
  }
}

const formatRevisionStatus = (status?: string) => {
  switch (status) {
    case 'locked':
      return 'зафиксирована'
    case 'published':
      return 'опубликована'
    case 'stale':
      return 'устарела'
    default:
      return status || '—'
  }
}

const getRevisionStatusColor = (status?: string) => {
  switch (status) {
    case 'published':
      return 'success'
    case 'locked':
      return 'primary'
    case 'stale':
      return 'grey'
    default:
      return 'grey'
  }
}

const formatRevisionDate = (value?: string) => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleString('ru-RU')
}

const formatSnapshotHash = (hash: string) => {
  if (!hash || hash.length < 16) return hash
  return `${hash.slice(0, 8)}…${hash.slice(-8)}`
}

const canPublishRevision = (rev: any) => rev?.status === 'locked'
const canUnpublishRevision = (rev: any) => rev?.status === 'published'

const openRevisionView = async (rev: any) => {
  try {
    const res = await api.get(`/api/projects/${projectId}/revisions/${rev.number}`)
    const revision = res.data.revision || res.data
    selectedRevision.value = revision
    const snapshotJson = revision.snapshot_json
    if (snapshotJson) {
      let parsed = typeof snapshotJson === 'string' ? JSON.parse(snapshotJson) : snapshotJson
      if (typeof parsed === 'string') {
        parsed = JSON.parse(parsed)
      }
      selectedRevisionSnapshot.value = JSON.stringify(parsed, null, 2)
    } else {
      selectedRevisionSnapshot.value = ''
    }
    revisionDialog.value = true
  } catch (error: any) {
    console.error('❌ Failed to load revision:', error)
    showNotification(`Ошибка загрузки ревизии: ${error.response?.data?.message || error.message}`, 'error')
  }
}

const downloadRevisionPdf = async (rev: any) => {
  const num = rev.number as number
  revisionPdfLoading.value.add(num)
  revisionPdfLoading.value = new Set(revisionPdfLoading.value)
  try {
    const res = await api.get(`/api/projects/${projectId}/revisions/${rev.number}/pdf`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = `smeta_${projectId}_rev_${rev.number}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  } catch (error: any) {
    console.error('❌ Revision PDF error:', error)
    const serverError = error.response?.data?.error || error.response?.data?.details
    showNotification(`Ошибка PDF из ревизии: ${serverError || error.response?.data?.message || error.message}`, 'error')
  } finally {
    revisionPdfLoading.value.delete(num)
    revisionPdfLoading.value = new Set(revisionPdfLoading.value)
  }
}

const downloadPriceJustificationPdf = async (rev: any) => {
  const num = rev.number as number
  revisionJustPdfLoading.value.add(num)
  revisionJustPdfLoading.value = new Set(revisionJustPdfLoading.value)
  try {
    const res = await api.get(`/api/projects/${projectId}/revisions/${rev.number}/price-justification.pdf`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = url
    a.download = `price_justification_${projectId}_rev_${rev.number}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  } catch (error: any) {
    console.error('❌ Revision price justification PDF error:', error)
    showNotification(`Ошибка PDF обоснований: ${error.response?.data?.message || error.message}`, 'error')
  } finally {
    revisionJustPdfLoading.value.delete(num)
    revisionJustPdfLoading.value = new Set(revisionJustPdfLoading.value)
  }
}

const publishRevision = async (rev: any) => {
  try {
    const res = await api.post(`/api/projects/${projectId}/revisions/${rev.number}/publish`)
    const publicUrl = res.data?.publication?.public_url
    if (publicUrl) {
      showNotification(`Ревизия #${rev.number} опубликована. Ссылка: ${publicUrl}`, 'success')
    } else {
      showNotification(`Ревизия #${rev.number} опубликована`, 'success')
    }
    await fetchRevisions(revisionsPagination.page)
    await fetchLatestRevision()
  } catch (error: any) {
    console.error('❌ Publish revision error:', error)
    showNotification(`Ошибка публикации: ${error.response?.data?.message || error.message}`, 'error')
  }
}

const unpublishRevision = async (rev: any) => {
  try {
    await api.post(`/api/projects/${projectId}/revisions/${rev.number}/unpublish`)
    showNotification(`Публикация отозвана для ревизии #${rev.number}`, 'success')
    await fetchRevisions(revisionsPagination.page)
    await fetchLatestRevision()
  } catch (error: any) {
    console.error('❌ Unpublish revision error:', error)
    showNotification(`Ошибка снятия публикации: ${error.response?.data?.message || error.message}`, 'error')
  }
}

const retryRevisionRun = async () => {
  if (!activeRevisionRun.value) return
  if (guardReadOnlyAction()) return

  revisionRunRetryLoading.value = true
  try {
    await revisionRunApi.retry(projectId, activeRevisionRun.value.id)
      showNotification('Повторная проверка запущена для проблемных позиций', 'success')
    await refreshRevisionRun()
  } catch (error: any) {
    if (showBillingLockedError(error)) return

    showNotification(`Ошибка retry: ${error.response?.data?.message || error.message}`, 'error')
  } finally {
    revisionRunRetryLoading.value = false
  }
}

const openManualCloseDialog = (item: RevisionRunItem) => {
  if (guardReadOnlyAction()) return

  manualCloseItem.value = item
  manualCloseForm.price_per_unit = getSuggestedManualClosePrice(item)
  manualCloseForm.currency = 'RUB'
  manualCloseForm.source_url = item.source_url || ''
  manualCloseForm.screenshot_file = null
  manualScreenshotDropActive.value = false
  manualCloseDialog.value = true
}

const focusManualScreenshotDropzone = () => {
  nextTick(() => {
    window.setTimeout(() => {
      manualScreenshotDropzone.value?.focus()
    }, 50)
  })
}

const getSuggestedManualClosePrice = (item: RevisionRunItem | any): number => {
  const position = item?.position || {}
  const material = item?.material || {}
  const priceHistory = item?.priceHistory || item?.price_history || {}

  const candidates = [
    Number(position.unit_price),
    Number(material.price_per_unit),
    Number(priceHistory.price_per_unit),
  ]

  const found = candidates.find((value) => Number.isFinite(value) && value > 0)
  if (!found) {
    return 0
  }

  return Math.round(found * 100) / 100
}

const setManualScreenshotFile = (file: File | null): boolean => {
  if (!file) {
    return false
  }

  if (!file.type || !file.type.startsWith('image/')) {
    showNotification('Поддерживаются только изображения', 'warning')
    return false
  }

  manualCloseForm.screenshot_file = file
  return true
}

const clearManualScreenshotFile = () => {
  manualCloseForm.screenshot_file = null
}

const onManualScreenshotDragEnter = () => {
  manualScreenshotDropActive.value = true
}

const onManualScreenshotDragOver = () => {
  manualScreenshotDropActive.value = true
}

const onManualScreenshotDragLeave = (event: DragEvent) => {
  const currentTarget = event.currentTarget as HTMLElement | null
  const relatedTarget = event.relatedTarget as Node | null
  if (currentTarget && relatedTarget && currentTarget.contains(relatedTarget)) {
    return
  }
  manualScreenshotDropActive.value = false
}

const onManualScreenshotDrop = (event: DragEvent) => {
  manualScreenshotDropActive.value = false
  const dropped = event.dataTransfer?.files?.[0] || null
  if (!dropped) {
    return
  }
  if (setManualScreenshotFile(dropped)) {
    showNotification('Скриншот добавлен', 'success')
  }
}

const applyManualScreenshotFromClipboard = (event: ClipboardEvent): boolean => {
  const items = event.clipboardData?.items
  if (!items?.length) {
    return false
  }

  for (const item of items) {
    if (item.kind !== 'file' || !item.type.startsWith('image/')) {
      continue
    }

    const pasted = item.getAsFile()
    if (pasted && setManualScreenshotFile(pasted)) {
      return true
    }
    return false
  }

  return false
}

const onManualScreenshotPaste = (event: ClipboardEvent) => {
  if (applyManualScreenshotFromClipboard(event)) {
    showNotification('Скриншот вставлен из буфера', 'success')
    event.preventDefault()
  }
}

const onWindowPasteForManualClose = (event: ClipboardEvent) => {
  if (!manualCloseDialog.value || event.defaultPrevented) {
    return
  }

  if (applyManualScreenshotFromClipboard(event)) {
    showNotification('Скриншот вставлен из буфера', 'success')
    event.preventDefault()
  }
}

const getManualScreenshotFile = (): File | null => {
  const value = manualCloseForm.screenshot_file
  if (!value) return null
  if (Array.isArray(value)) {
    return value[0] || null
  }
  return value as File
}

const submitManualClose = async () => {
  if (!activeRevisionRun.value || !manualCloseItem.value) return
  if (guardReadOnlyAction()) return

  const screenshotFile = getManualScreenshotFile()
  if (!screenshotFile) {
    showNotification('Добавьте файл скриншота', 'warning')
    return
  }
  if (!(manualCloseForm.price_per_unit > 0)) {
    showNotification('Цена должна быть больше 0', 'warning')
    return
  }

  manualCloseLoading.value = true
  try {
    await revisionRunApi.manual(activeRevisionRun.value.id, manualCloseItem.value.id, {
      price_per_unit: manualCloseForm.price_per_unit,
      currency: (manualCloseForm.currency || 'RUB').toUpperCase(),
      source_url: manualCloseForm.source_url || undefined,
      region_id: project.value?.region_id || undefined,
      screenshot_file: screenshotFile,
    })
    showNotification('Позиция закрыта вручную', 'success')
    manualCloseDialog.value = false
    await refreshRevisionRun()
  } catch (error: any) {
    if (showBillingLockedError(error)) return

    showNotification(`Ошибка ручного закрытия: ${error.response?.data?.message || error.message}`, 'error')
  } finally {
    manualCloseLoading.value = false
  }
}

const finalizeRevisionRun = async () => {
  if (!activeRevisionRun.value) return
  if (guardReadOnlyAction()) return

  if (estimateHardInvalid.value) {
    showNotification('Смета содержит ошибки и не может быть использована', 'error')
    return
  }

  revisionRunFinalizeLoading.value = true
  try {
    const data = await revisionRunApi.finalize(projectId, activeRevisionRun.value.id)
    revisionRunPdfLinks.value = data.pdf
    showNotification(`Ревизия #${data.revision.number} создана`, 'success')
    setStoredRevisionRunId(null)
    stopRevisionRunPolling()
    activeRevisionRun.value = null
    revisionRunItems.value = []
    await fetchLatestRevision()
    await fetchRevisions(1)
  } catch (error: any) {
    if (showBillingLockedError(error)) return

    if (error.response?.status === 409) {
      showNotification('Есть незакрытые позиции. Закройте все и повторите завершение', 'warning')
      await refreshRevisionRun()
    } else {
      showNotification(`Ошибка финализации: ${error.response?.data?.message || error.message}`, 'error')
    }
  } finally {
    revisionRunFinalizeLoading.value = false
  }
}

const openPdfLink = (url?: string) => {
  if (!url) return
  window.open(url, '_blank', 'noopener,noreferrer')
}


const openExpenseDialog = () => {
  if (guardReadOnlyAction()) return

  editingExpense.value = false
  expenseForm.value = { id: 0, project_id: projectId, name: '', description: '', amount: 0 }
  expenseDialog.value = true
}

const editExpense = (item: any) => {
  if (guardReadOnlyAction()) return

  editingExpense.value = true
  expenseForm.value = { ...item }
  expenseDialog.value = true
}

const saveExpense = async () => {
  if (expenseSaving.value) return
  if (guardReadOnlyAction()) return

  expenseSaving.value = true

  try {
    const payload = {
      name: expenseForm.value.name,
      description: expenseForm.value.description,
      amount: Number(expenseForm.value.amount) || 0,
    }

    if (editingExpense.value) {
      await api.put(`/api/projects/${projectId}/expenses/${expenseForm.value.id}`, payload)
    } else {
      await api.post(`/api/projects/${projectId}/expenses`, payload)
    }
    expenseDialog.value = false
    await fetchData()
  } catch (e) {
    if (showBillingLockedError(e)) return

    const err: any = e
    const validation = err?.response?.data?.errors
    const message = validation
      ? Object.values(validation).flat().join('\n')
      : err?.response?.data?.message || err?.message || 'Ошибка сохранения расхода'
    showNotification(message, 'error')
  } finally {
    expenseSaving.value = false
  }
}

const deleteExpense = async (item: any) => {
  if (guardReadOnlyAction()) return

  if (confirm('Удалить расход?')) {
    try {
      await api.delete(`/api/projects/${projectId}/expenses/${item.id}`)
      await fetchData()
    } catch (e: any) {
      if (showBillingLockedError(e)) return
      showNotification(`Ошибка удаления расхода: ${e.response?.data?.message || e.message}`, 'error')
    }
  }
}

// === Операции ===
const _operations = ref<any[]>([]) // внутреннее хранилище
const expandedOperation = ref<number | null>(null) // Управление раскрытием операции
const processingPositionId = ref<number | null>(null) // ID позиции которая обрабатывается

// Операции как computed для правильной работы expand
const operations = computed(() => {
  const ops = _operations.value.map(o => {
    const opName = o.name || ''
    const isDrilling = opName.toLowerCase().includes('сверл') || opName.toLowerCase().includes('отверст')
    const isAutomatic = o.type !== 'manual' && !o.source?.includes('manual')
    
    // Для автоматических операций сверления, используем рассчитанное значение вместо API
    let finalQuantity = o.quantity
    if (isDrilling && isAutomatic && o.source === 'detail_type') {
      const details = getDrillingOperationDetails(o)
      if (details.total_holes !== undefined) {
        finalQuantity = details.total_holes
      }
    }
    
    return {
      ...o,
      quantity: finalQuantity, // переписываем quantity на рассчитанное
      is_manual: o.type === 'manual' || o.source === 'manual' || !!o.is_manual,
      total_cost: o.is_valid === false ? null : (finalQuantity ?? 0) * (o.cost_per_unit ?? 0),
      _cached_details: getOperationDetails(o) // кэшируем детали здесь
    }
  })
  return ops
})

const allOperations = ref<any[]>([]) // справочник
const operationDialog = ref(false)
const editingOperation = ref(false)
const operationForm = ref({
  id: 0,
  project_id: projectId,
  operation_id: null,
  quantity: 1,
  note: ''
})

const operationHeaders = [
  { title: '', key: 'data-table-expand' },
  { title: 'Наименование', key: 'name' },
  { title: 'Категория', key: 'category' },
  { title: 'Цена за ед.', key: 'cost_per_unit', align: 'end' as const },
  { title: 'Кол-во', key: 'quantity_display' },
  { title: 'Ед. изм.', key: 'unit' },
  { title: 'Стоимость', key: 'total_cost', align: 'end' as const },
  { title: 'Источник', key: 'source' },
  { title: 'Действия', key: 'actions', sortable: false }
]

// === Монтажно-сборочные работы (нормо-час) ===
const laborWorks = ref<LaborWork[]>([])
const laborProfiles = ref<any[]>([])
const laborWorkDialog = ref(false)
const laborWorkSaving = ref(false)
const laborWorkFormRef = ref<any>(null)
const editingLaborWork = ref<LaborWork | null>(null)
const laborWorkSubmitError = ref('')
const laborWorkFieldErrors = ref<Record<string, string[]>>({})
const laborWorkForm = ref<Partial<LaborWork>>({
  title: '',
  basis: '',
  hours: 0,
  note: '',
  labor_profile_id: null
})

// === Подоперации (Steps) ===
const stepsDialog = ref(false)
const selectedLaborWork = ref<LaborWork | null>(null)
const laborWorkSteps = ref<any[]>([])
const savingStep = ref(false)
const editingStepId = ref<number | null>(null)
const stepFormRef = ref<any>(null)
const stepForm = ref({
  title: '',
  basis: '',
  input_data: '',
  hours: 0,
  note: ''
})

// Steps modal state
const stepsModal = ref(false)
const stepsSearch = ref('')
const sortMode = ref('sort')
const deleteDialog = ref(false)
const stepToDelete = ref<any>(null)
const showNoteField = ref(false)
const laborStepsLoadingId = ref<number | null>(null)

const deleteStepConfirmed = async () => {
  if (!stepToDelete.value) return
  deleteDialog.value = false
  await deleteStep(stepToDelete.value)
  stepToDelete.value = null
}

// === AI Decomposition State ===
import { decompose as aiDecompose, feedback as aiFeedback, makeFingerprint, type DecomposeContext, type DecomposeResponse } from '@/api/workDecomposition'

const aiContext = ref<DecomposeContext>({
  domain: undefined,
  action_type: undefined,
  constraints: 'normal',
  site_state: undefined,
  material: undefined,
  object_type: undefined
})
const aiDesiredHours = ref<number | undefined>(undefined)
const aiLoading = ref(false)
const aiApplying = ref(false)
const aiSuggestion = ref<DecomposeResponse | null>(null)
const aiSelectedSteps = ref<Set<number>>(new Set())
const showAiOptionalFields = ref(false)
const aiAppliedSource = ref<'ai' | 'manual' | null>(null)
const feedbackSentFingerprint = ref<string | null>(null)

// AI Context options
const aiDomainOptions = [
  { title: 'Мебель', value: 'furniture' },
  { title: 'Строительство', value: 'construction' },
  { title: 'Электрика', value: 'electrical' },
  { title: 'Сантехника', value: 'plumbing' },
  { title: 'Клининг', value: 'cleaning' }
]
const aiActionTypeOptions = [
  { title: 'Установка', value: 'install' },
  { title: 'Демонтаж', value: 'dismantle' },
  { title: 'Ремонт', value: 'repair' },
  { title: 'Регулировка', value: 'adjust' }
]
const aiConstraintsOptions = [
  { title: 'Обычные', value: 'normal' },
  { title: 'Стеснённые', value: 'cramped' }
]
const aiSiteStateOptions = [
  { title: 'Черновая', value: 'rough' },
  { title: 'Жилое', value: 'living' },
  { title: 'Аварийное', value: 'emergency' }
]

const totalStepsHours = computed(() => {
  return laborWorkSteps.value.reduce((sum, step) => sum + (parseFloat(step.hours) || 0), 0)
})

const filteredSteps = computed(() => {
  // Сортировка по sort_order
  const sorted = [...laborWorkSteps.value].sort((a: any, b: any) => 
    (a.sort_order ?? 0) - (b.sort_order ?? 0)
  )
  
  if (!stepsSearch.value) {
    return sorted
  }
  const query = stepsSearch.value.toLowerCase()
  return sorted.filter((step: any) => 
    step.title?.toLowerCase().includes(query) ||
    step.basis?.toLowerCase().includes(query) ||
    step.note?.toLowerCase().includes(query)
  )
})

const hoveredLaborWorkId = ref<number | null>(null)

const getLaborQuickActions = (item: LaborWork): RowAction[] => [
  {
    key: 'details',
    icon: 'mdi-clipboard-list',
    label: 'Детализация'
    ,
    disabled: laborStepsLoadingId.value === item.id
  },
  {
    key: 'edit',
    icon: 'mdi-pencil',
    label: 'Изменить',
    tooltip: readOnlyActionTitle.value || 'Изменить',
    disabled: isProjectReadOnly.value
  },
  {
    key: 'delete',
    icon: 'mdi-delete',
    label: 'Удалить',
    tooltip: readOnlyActionTitle.value || 'Удалить',
    disabled: isProjectReadOnly.value,
    color: 'error'
  }
]

const getLaborMenuActions = (_item: LaborWork): RowAction[] => []

const handleLaborRowAction = (payload: { rowId: number | string, actionKey: string }) => {
  const item = laborWorks.value.find(w => w.id === payload.rowId)
  if (!item) return

  if (['edit', 'delete'].includes(payload.actionKey) && guardReadOnlyAction()) {
    return
  }

  switch (payload.actionKey) {
    case 'details':
      openStepsModal(item)
      break
    case 'edit':
      editLaborWork(item)
      break
    case 'delete':
      deleteLaborWork(item)
      break
  }
}

const isLaborActionsVisible = (itemId: number | null): boolean => {
  if (!itemId) return false
  return hoveredLaborWorkId.value === itemId
}

// Drag-and-drop для нормируемых работ
const draggedLaborWorkId = ref<number | null>(null)
const dragOverLaborWorkId = ref<number | null>(null)

const onLaborDragStart = (event: DragEvent, item: any) => {
  draggedLaborWorkId.value = item.id
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move'
  }
}

const onLaborDragEnd = () => {
  draggedLaborWorkId.value = null
  dragOverLaborWorkId.value = null
}

const onLaborDrop = async (targetItem: any) => {
  if (!draggedLaborWorkId.value || draggedLaborWorkId.value === targetItem.id) {
    dragOverLaborWorkId.value = null
    return
  }

  const items = [...laborWorks.value]
  const draggedIndex = items.findIndex(w => w.id === draggedLaborWorkId.value)
  const targetIndex = items.findIndex(w => w.id === targetItem.id)

  if (draggedIndex === -1 || targetIndex === -1) return

  const [draggedItem] = items.splice(draggedIndex, 1)
  items.splice(targetIndex, 0, draggedItem!)

  // Оптимистичное обновление UI
  laborWorks.value = items.map((w, i) => ({ ...w, sort_order: i }))

  draggedLaborWorkId.value = null
  dragOverLaborWorkId.value = null

  // Отправить на сервер
  try {
    const order = items.map(w => w.id!)
    await laborWorksApi.reorder(Number(projectId), order)
  } catch (err: any) {
    console.error('Error reordering labor works:', err)
    await loadLaborWorks()
    showNotification('Ошибка сортировки работ', 'error')
  }
}

const laborWorkHeaders = [
  { title: 'Наименование', key: 'title' },
  { title: 'Основание', key: 'basis' },
  { title: 'Норма, ч', key: 'hours', align: 'end' },
  { title: 'Ставка, ₽/ч', key: 'rate', align: 'end' },
  { title: 'Сумма, ₽', key: 'cost', align: 'end' }
]

const hasMissingLaborRates = ref(false)
const recalculatingRates = ref(false)
const lockingRates = ref(false)

const laborWorksTotal = computed(() => {
  if (!Array.isArray(laborWorks.value)) {
    return 0
  }
  return laborWorks.value.reduce((sum, work) => sum + (parseFloat(String(work.cost_total)) || 0), 0)
})

const laborWorkProfileErrors = computed(() => laborWorkFieldErrors.value.labor_profile_id || [])

// Проверить статус блокировки ставок
const ratesLocked = computed(() => {
  const pr = (project.value as any).profileRates
  
  // Log every time this is computed (only if debugged)
  if (typeof pr !== 'undefined') {
    console.log('🔐 ratesLocked computed:', {
      type: Array.isArray(pr) ? 'array' : typeof pr,
      value: pr,
      length: Array.isArray(pr) ? pr.length : (pr && typeof pr === 'object' ? Object.keys(pr).length : 0)
    })
  }
  
  if (!pr) {
    return false
  }
  
  const rates = Array.isArray(pr) ? pr : Object.values(pr || {})
  
  if (rates.length === 0) {
    return false
  }
  
  const result = rates.every((r: any) => r.is_locked === true)
  console.log('🔐 All rates locked?', result, 'out of', rates.length)
  
  return result
})

// Watch profileRates для отслеживания изменений статуса блокировки
watch(() => (project.value as any).profileRates, (newRates) => {
  console.log('👀 profileRates changed:', {
    count: newRates ? (Array.isArray(newRates) ? newRates.length : Object.keys(newRates).length) : 0,
    rates: newRates ? (Array.isArray(newRates) ? newRates.map((r: any) => ({ id: r.id, is_locked: r.is_locked })) : Object.values(newRates).map((r: any) => ({ id: r.id, is_locked: r.is_locked }))) : []
  })
}, { deep: true })

// === Материалы (агрегированный список) ===
const materialsData = computed(() => {
  try {
    if (!positions.value || positions.value.length === 0) return []
    
    const materialMap = new Map<number, { id: number; name: string; volume: number; unit: string; price_per_unit: number; price_per_sheet: number; updated_at?: string }>()
    
    // Считаем объем каждого материала из позиций
    positions.value.forEach(pos => {
      if (!pos.material_id) return
      
      const material = materials.value.find(m => m.id === pos.material_id)
      if (!material) return
      
      // Вычисляем площадь (ширина × длина в м², если размеры в мм)
      const area = ((pos.width || 0) / 1000) * ((pos.length || 0) / 1000) * (pos.quantity || 0)
      
      if (!materialMap.has(pos.material_id)) {
        // Сохраняем оригинальную цену за лист
        const pricePerSheet = material.price_per_unit || 0
        
        // Рассчитываем цену за единицу измерения
        let pricePerUnit = pricePerSheet
        
        // Если это пластина (plate) с известными размерами листа, рассчитываем цену за м²
        if (material.type === 'plate' && material.length_mm && material.width_mm) {
          const sheetAreaM2 = (material.length_mm * material.width_mm) / 1_000_000
          if (sheetAreaM2 > 0) {
            pricePerUnit = pricePerSheet / sheetAreaM2 // Цена за м²
          }
        }
        
        materialMap.set(pos.material_id, {
          id: pos.material_id,
          name: material.name,
          volume: 0,
          unit: material.unit || 'м²',
          price_per_unit: pricePerUnit,
          price_per_sheet: pricePerSheet,
          updated_at: material.updated_at
        })
      }
      const entry = materialMap.get(pos.material_id)!
      entry.volume += area
    })
    
    return Array.from(materialMap.values())
  } catch (error) {
    console.error('Ошибка вычисления данных материалов:', error)
    return []
  }
})

// === Плитные материалы ===
const plateData = computed(() => {
  try {
    if (!positions.value || positions.value.length === 0) {
      console.log('plateData: no positions')
      return []
    }
    
    const wasteCoeff = getWasteCoefficientForPlate()
    const plateMap = new Map<number, any>()
    
    // Сначала собираем все площади по материалам
    positions.value.forEach(pos => {
      if (!pos.material_id) return
      const material = materials.value.find(m => m.id === pos.material_id)
      if (!material) {
        console.warn('Material not found:', pos.material_id)
        return
      }
      console.log('Material check:', { id: material.id, name: material.name, type: material.type, isPlate: material.type === 'plate' })
      if (material.type !== 'plate') {
        console.log('Skipping non-plate material:', material.name, 'type:', material.type)
        return
      }
      
      const area = ((pos.width || 0) / 1000) * ((pos.length || 0) / 1000) * (pos.quantity || 0)
      
      if (!plateMap.has(pos.material_id)) {
        const sheetAreaM2 = material.length_mm && material.width_mm 
          ? (material.length_mm * material.width_mm) / 1_000_000
          : 0
        
        plateMap.set(pos.material_id, {
          id: pos.material_id,
          name: material.name,
          area_details: 0,
          waste_coeff: wasteCoeff,
          area_with_waste: 0,
          sheet_area: sheetAreaM2,
          sheets_count: 0,
          price_per_sheet: material.price_per_unit || 0,
          price_per_m2: material.price_per_unit && sheetAreaM2 > 0
            ? (material.price_per_unit / sheetAreaM2)
            : 0,
          updated_at: material.updated_at,
          source_url: (material as any).source_url
        })
      }
      
      const entry = plateMap.get(pos.material_id)
      entry.area_details += area
    })
    
    // Теперь пересчитываем с отходами
    const isAreaMode = project.value?.use_area_calc_mode === true
    plateMap.forEach(entry => {
      entry.area_with_waste = entry.area_details * wasteCoeff
      
      if (!isAreaMode) {
        // Расчёт по листам (по умолчанию)
        entry.sheets_count = entry.sheet_area > 0 ? Math.ceil(entry.area_with_waste / entry.sheet_area) : 0
      } else {
        // Расчёт по площади (просто выводим площадь с отходами как есть)
        entry.sheets_count = 0 // не используется в режиме площади
      }
    })
    
    console.log('plateData result:', Array.from(plateMap.values()), 'area mode:', isAreaMode)
    return Array.from(plateMap.values())
  } catch (error) {
    console.error('Ошибка вычисления плитных материалов:', error)
    return []
  }
})

const hasPlateMaterialIssue = (item: any): boolean => {
  const missingSheetSize = !(Number(item?.sheet_area) > 0)
  const missingSheetPrice = !(Number(item?.price_per_sheet) > 0)
  return missingSheetSize || missingSheetPrice
}

// === Кромка ===
const edgeData = computed(() => {
  try {
    if (!positions.value || positions.value.length === 0) return []
    
    const wasteCoeff = getWasteCoefficientForEdge()
    const edgeMap = new Map<number, any>()
    
    positions.value.forEach(pos => {
      if (!pos.edge_material_id || !pos.edge_scheme || pos.edge_scheme === 'none') return
      
      const material = materials.value.find(m => m.id === pos.edge_material_id)
      if (!material || material.type !== 'edge') return
      
      // Рассчитываем периметр для обработки (в зависимости от схемы)
      let perimeterMeters = 0
      const widthM = (pos.width || 0) / 1000
      const lengthM = (pos.length || 0) / 1000
      const qty = pos.quantity || 0
      
      switch (pos.edge_scheme) {
        case 'O': // Вкруг
          perimeterMeters = 2 * (widthM + lengthM) * qty
          break
        case '=': // Параллельно длине (две длинные стороны)
          perimeterMeters = 2 * lengthM * qty
          break
        case '||': // Параллельно ширине (две короткие стороны)
          perimeterMeters = 2 * widthM * qty
          break
        case 'L': // Г-образно (две смежные стороны)
          perimeterMeters = (widthM + lengthM) * qty
          break
        case 'П': // П-образно (три стороны)
          perimeterMeters = (2 * widthM + lengthM) * qty
          break
        case 'long_one': // Одна длинная сторона
          perimeterMeters = lengthM * qty
          break
        case 'short_one': // Одна короткая сторона
          perimeterMeters = widthM * qty
          break
        default:
          perimeterMeters = 0
      }
      
      if (!edgeMap.has(pos.edge_material_id)) {
        edgeMap.set(pos.edge_material_id, {
          id: pos.edge_material_id,
          name: material.name,
          length_linear: 0,
          waste_coeff: wasteCoeff,
          length_with_waste: 0,
          price_per_unit: material.price_per_unit || 0,
          updated_at: material.updated_at
        })
      }
      
      const entry = edgeMap.get(pos.edge_material_id)!
      entry.length_linear += perimeterMeters
    })
    
    // Добавляем расчет длины с отходами
    edgeMap.forEach(entry => {
      entry.length_with_waste = entry.length_linear * wasteCoeff
    })
    
    return Array.from(edgeMap.values())
  } catch (error) {
    console.error('Ошибка вычисления кромки:', error)
    return []
  }
})

const plateTotalCost = computed(() => {
  if (!plateData.value || plateData.value.length === 0) return 0
  const isAreaMode = project.value?.use_area_calc_mode === true
  return plateData.value.reduce((sum, p) => {
    const cost = isAreaMode 
      ? (p.area_with_waste * (Number(p.price_per_m2) || 0))
      : (p.sheets_count * (Number(p.price_per_sheet) || 0))
    return sum + cost
  }, 0)
})

const edgeTotalCost = computed(() => {
  if (!edgeData.value || edgeData.value.length === 0) return 0
  return edgeData.value.reduce((sum, e) => sum + (e.length_with_waste * (Number(e.price_per_unit) || 0)), 0)
})

const materialsTotalCost = computed(() => {
  return (plateTotalCost.value || 0) + (edgeTotalCost.value || 0)
})

const operationsTotal = computed(() => {
  if (!operations.value || operations.value.length === 0) return 0
  return operations.value.reduce((sum, o) => {
    if (o.is_valid === false) return sum
    const cost = parseFloat(o.total_cost ?? ((o.quantity || 0) * (o.cost_per_unit || 0))) || 0
    return sum + cost
  }, 0)
})

const invalidOperationsCount = computed(() => {
  if (!operations.value) return 0
  return operations.value.filter(o => o.is_valid === false || !!o.unit_mismatch).length
})

const operationsTotalIsValid = computed(() => invalidOperationsCount.value === 0)

const estimateHardInvalid = computed(() => !operationsTotalIsValid.value)

// === Workspace computeds ===
const positionsWithoutMaterial = computed(() => {
  return positions.value.filter(p => {
    if (p.kind === 'facade') return !p.finished_product_specification_id
    return !p.material_id
  }).length
})

const positionsWithPriceIssues = computed(() => {
  return positions.value.filter(p => hasMissingMainMaterialPrice(p)).length
})

const autoOperationsTotal = computed(() => {
  if (!operations.value) return 0
  return operations.value
    .filter(o => !o.is_manual && o.is_valid !== false)
    .reduce((sum, o) => sum + (parseFloat(o.total_cost) || 0), 0)
})

const manualOperationsCount = computed(() => {
  if (!operations.value) return 0
  return operations.value.filter(o => o.is_manual).length
})

const facadesTotalCost = computed(() => {
  return positions.value
    .filter((position) => position.kind === 'facade')
    .reduce((sum, position) => sum + getPositionTotalPrice(position), 0)
})

const projectTotalSum = computed(() => {
  if (estimateHardInvalid.value) return null

  const matCost = materialsTotalCost.value || 0
  const opsCost = operationsTotal.value || 0
  const facCost = facadesTotalCost.value || 0
  const fitCost = fittings.value.reduce((s, f) => s + (f.quantity * f.unit_price), 0)
  const labCost = typeof laborWorksTotal.value === 'number' ? laborWorksTotal.value : 0
  const expCost = Array.isArray(expenses.value) ? expenses.value.reduce((s, e) => s + (parseFloat(e.amount) || 0), 0) : 0
  return matCost + opsCost + facCost + fitCost + labCost + expCost
})

const sidebarModules = computed<SidebarModule[]>(() => [
  {
    key: 'positions',
    label: 'Позиции',
    icon: 'mdi-table',
    count: positions.value.length,
    warnings: positionsWithoutMaterial.value + positionsWithPriceIssues.value
  },
  {
    key: 'materials',
    label: 'Материалы',
    icon: 'mdi-package-variant',
    count: plateData.value.length + edgeData.value.length,
    warnings: plateData.value.filter(p => hasPlateMaterialIssue(p)).length
  },
  {
    key: 'operations',
    label: 'Операции',
    icon: 'mdi-cog-outline',
    count: operations.value.length,
    warnings: invalidOperationsCount.value,
  },
  {
    key: 'fittings',
    label: 'Фурнитура',
    icon: 'mdi-screw-machine-flat-top',
    count: fittings.value.length,
  },
  {
    key: 'labor',
    label: 'Работы',
    icon: 'mdi-hammer-wrench',
    count: laborWorks.value.length,
    warnings: hasMissingLaborRates.value ? 1 : 0
  },
  {
    key: 'expenses',
    label: 'Расходы',
    icon: 'mdi-cash-multiple',
    count: expenses.value.length,
  },
  {
    key: 'revisions',
    label: 'Ревизии',
    icon: 'mdi-shield-check-outline',
    count: revisions.value.length,
    warnings: activeRevisionRun.value && (activeRevisionRun.value.status === 'NEEDS_MANUAL' || activeRevisionRun.value.status === 'FAILED') ? 1 : 0
  },
  {
    key: 'evidence',
    label: 'Доказательства',
    icon: 'mdi-shield-search',
  },
  {
    key: 'settings',
    label: 'Настройки',
    icon: 'mdi-cog',
  },
])

const healthIssues = computed<HealthIssue[]>(() => {
  const issues: HealthIssue[] = []
  
  const noMat = positionsWithoutMaterial.value
  if (noMat > 0) {
    issues.push({ severity: 'warning', message: `${noMat} позиций без назначенного материала`, action: 'positions', actionLabel: 'Позиции' })
  }
  
  const noPrice = positionsWithPriceIssues.value
  if (noPrice > 0) {
    issues.push({ severity: 'warning', message: `${noPrice} позиций без цены материала`, action: 'materials', actionLabel: 'Материалы' })
  }

  const problemPlates = plateData.value.filter(p => hasPlateMaterialIssue(p)).length
  if (problemPlates > 0) {
    issues.push({ severity: 'warning', message: `${problemPlates} плитных материалов с проблемами (цена/лист)`, action: 'materials', actionLabel: 'Материалы' })
  }

  if (hasMissingLaborRates.value) {
    issues.push({ severity: 'warning', message: 'Не все профили имеют установленные ставки', action: 'labor', actionLabel: 'Работы' })
  }

  if (estimateHardInvalid.value) {
    issues.push({ severity: 'error', message: 'Смета содержит ошибки и не может быть использована', action: 'operations', actionLabel: 'Операции' })
  }

  if (activeRevisionRun.value?.status === 'NEEDS_MANUAL') {
    issues.push({ severity: 'info', message: 'Ревизия: есть позиции без подтверждения цены', action: 'revisions', actionLabel: 'Ревизии' })
  }

  return issues
})

// === Ручные операции ===
const openOperationDialog = () => {
  if (guardReadOnlyAction()) return

  editingOperation.value = false
  operationForm.value = { id: 0, project_id: projectId, operation_id: null, quantity: 1, note: '' }
  operationDialog.value = true
}

const saveOperation = async () => {
  if (operationSaving.value) return
  if (guardReadOnlyAction()) return

  operationSaving.value = true

  try {
    const payload: any = {
      quantity: Number(operationForm.value.quantity) || 0,
      note: operationForm.value.note || null,
    }
    // operation_id may be an object (return-object) or an id
    const opField = operationForm.value.operation_id
    payload.operation_id = opField && typeof opField === 'object' ? (opField as any).id : opField

    if (editingOperation.value) {
      // For editing we expect operationForm.value.id to be project_manual_operation id
      await api.put(`/api/project-operations/${operationForm.value.id}`, payload)
    } else {
      await api.post(`/api/projects/${projectId}/operations`, payload)
    }
    operationDialog.value = false
    await fetchData()
  } catch (e: any) {
    if (showBillingLockedError(e)) return

    console.error('saveOperation error', e)
    showNotification('Ошибка сохранения операции: ' + (e.response?.data?.message || e.message), 'error')
  } finally {
    operationSaving.value = false
  }
}

const editOperation = (item: any) => {
  if (guardReadOnlyAction()) return

  editingOperation.value = true
  operationForm.value = { ...item }
  operationDialog.value = true
}

const deleteOperation = async (item: any) => {
  if (guardReadOnlyAction()) return

  if (confirm('Удалить ручную операцию?')) {
    try {
      await api.delete(`/api/project-operations/${item.id}`)
      await fetchData()
    } catch (e: any) {
      if (showBillingLockedError(e)) return
      showNotification('Ошибка удаления операции', 'error')
    }
  }
}

// === Монтажно-сборочные работы (нормо-час) ===
const recalculateLaborRates = async () => {
  if (guardReadOnlyAction()) return

  recalculatingRates.value = true
  try {
    // Использовать новый endpoint для фиксации ставок
    const response = await api.post(`/api/projects/${projectId}/profile-rates/recalculate-and-fix`, {
      method: 'median',
      only_if_missing: false  // Пересчитать все ставки
    })
    
    if (response.data.success) {
      // Перезагрузить данные работ с обновленными ставками
      await loadLaborWorks()
      // После фиксирования ставок не должно быть missing rates (если не было ошибок)
      hasMissingLaborRates.value = false
      console.log('✅ Ставки успешно пересчитаны и зафиксированы', response.data.data)
      const createdCount = response.data.data.created_rate_ids?.length ?? 0
      showNotification(`Ставки успешно пересчитаны и зафиксированы (${createdCount} ставок создано)`, 'success')
    } else {
      console.error('❌ Ошибка при пересчете ставок:', response.data.message)
      showNotification('Ошибка при пересчете ставок: ' + response.data.message, 'error')
    }
  } catch (error: any) {
    if (showBillingLockedError(error)) return

    console.error('❌ Ошибка запроса пересчета ставок:', error)
    // Попытаться перезагрузить работы несмотря на ошибку
    try {
      await loadLaborWorks()
      showNotification('Данные перезагружены, но фиксация ставок не прошла', 'warning')
    } catch (e) {
      showNotification('Ошибка при пересчете ставок: ' + (error.response?.data?.message || error.message), 'error')
    }
  } finally {
    recalculatingRates.value = false
  }
}

const lockLaborRates = async () => {
  if (guardReadOnlyAction()) return

  lockingRates.value = true
  try {
    const isCurrentlyLocked = ratesLocked.value
    
    if (isCurrentlyLocked) {
      // Разблокировать ставки
      console.log('🔓 Разблокирование ставок...')
      const response = await api.post(`/api/projects/${projectId}/profile-rates/unlock`, {})
      
      if (response.data.success) {
        console.log('� Перезагрузка данных проекта для обновления статуса...')
        await fetchData()
        console.log('✅ Ставки успешно разблокированы', response.data.data)
        const unlockedCount = response.data.data.unlocked_count ?? 0
        showNotification(`Ставки успешно разблокированы (${unlockedCount} ставок разблокировано)`, 'success')
      } else {
        console.error('❌ Ошибка при разблокировке ставок:', response.data.message)
        showNotification('Ошибка при разблокировке ставок: ' + response.data.message, 'error')
      }
    } else {
      // Заблокировать ставки
      // Сначала пересчитать ставки в режиме fix (создать их если не существуют)
      console.log('📌 Создание/фиксирование ставок перед блокировкой...')
      const fixResponse = await api.post(`/api/projects/${projectId}/profile-rates/recalculate-and-fix`, {
        method: 'median',
        only_if_missing: false  // Пересчитать все ставки
      })
      
      if (!fixResponse.data.success) {
        showNotification('Ошибка при подготовке ставок к блокировке: ' + fixResponse.data.message, 'error')
        return
      }
      
      console.log('✅ Ставки подготовлены к блокировке')
      
      // Теперь заблокировать их
      console.log('🔒 Блокирование ставок...')
      const response = await api.post(`/api/projects/${projectId}/profile-rates/lock`, {})
      
      if (response.data.success) {
        // Перезагрузить все данные включая profileRates чтобы обновить статус блокировки
        console.log('🔄 Перезагрузка данных проекта для обновления статуса блокировки...')
        await fetchData()
        console.log('✅ Ставки успешно заблокированы', response.data.data)
        const lockedCount = response.data.data.locked_count ?? 0
        showNotification(`Ставки успешно заблокированы (${lockedCount} ставок заблокировано)`, 'success')
      } else {
        console.error('❌ Ошибка при блокировке ставок:', response.data.message)
        showNotification('Ошибка при блокировке ставок: ' + response.data.message, 'error')
      }
    }
  } catch (error: any) {
    if (showBillingLockedError(error)) return

    console.error('❌ Ошибка при изменении статуса блокировки ставок:', error)
    // Попытаться перезагрузить работы несмотря на ошибку
    try {
      await loadLaborWorks()
      showNotification('Данные перезагружены, но операция не прошла: ' + (error.response?.data?.message || error.message), 'warning')
    } catch (e) {
      showNotification('Ошибка при изменении статуса блокировки ставок: ' + (error.response?.data?.message || error.message), 'error')
    }
  } finally {
    lockingRates.value = false
  }
}

const openLaborWorkDialog = () => {
  if (guardReadOnlyAction()) return

  editingLaborWork.value = null
  laborWorkSubmitError.value = ''
  laborWorkFieldErrors.value = {}
  laborWorkForm.value = {
    title: '',
    basis: '',
    hours: 0,
    note: '',
    labor_profile_id: null
  }
  laborWorkDialog.value = true
}

const saveLaborWork = async () => {
  if (laborWorkSaving.value) return
  if (guardReadOnlyAction()) return

  laborWorkSaving.value = true

  try {
    laborWorkSubmitError.value = ''
    laborWorkFieldErrors.value = {}

    if (!laborWorkFormRef.value?.validate()) {
      laborWorkSaving.value = false
      return
    }

    const payload: Partial<LaborWork> = {
      title: laborWorkForm.value.title || '',
      basis: laborWorkForm.value.basis || null,
      hours: parseFloat(String(laborWorkForm.value.hours)) || 0,
      note: laborWorkForm.value.note || null,
      labor_profile_id: laborWorkForm.value.labor_profile_id || null,
      hours_source: editingLaborWork.value?.hours_source || 'manual',
      hours_manual: editingLaborWork.value?.hours_manual || parseFloat(String(laborWorkForm.value.hours)) || 0
    }

    if (editingLaborWork.value?.id) {
      // Update
      await laborWorksApi.update(Number(projectId), editingLaborWork.value.id, payload)
    } else {
      // Create
      await laborWorksApi.create(Number(projectId), {
        project_id: Number(projectId),
        ...payload
      } as any)
    }

    laborWorkDialog.value = false
    await loadLaborWorks()
  } catch (e: any) {
    if (showBillingLockedError(e)) return

    console.error('saveLaborWork error', e)
    const responseData = e.response?.data
    laborWorkFieldErrors.value = responseData?.errors || {}
    laborWorkSubmitError.value = responseData?.message || 'Не удалось сохранить работу'
    showNotification('Ошибка сохранения работы: ' + (responseData?.message || e.message), 'error')
  } finally {
    laborWorkSaving.value = false
  }
}

const editLaborWork = (item: LaborWork) => {
  if (guardReadOnlyAction()) return

  editingLaborWork.value = item
  laborWorkSubmitError.value = ''
  laborWorkFieldErrors.value = {}
  laborWorkForm.value = {
    title: item.title,
    basis: item.basis || '',
    hours: item.hours,
    note: item.note || '',
    labor_profile_id: item.labor_profile_id || null
  }
  laborWorkDialog.value = true
}

const deleteLaborWork = async (item: LaborWork) => {
  if (guardReadOnlyAction()) return

  if (!confirm(`Удалить работу "${item.title}"?`)) {
    return
  }

  try {
    await laborWorksApi.delete(Number(projectId), item.id!)
    await loadLaborWorks()
  } catch (e: any) {
    if (showBillingLockedError(e)) return

    console.error('deleteLaborWork error', e)
    showNotification('Ошибка удаления работы: ' + (e.response?.data?.message || e.message), 'error')
  }
}

// === Функции для работы с подоперациями ===

const toggleSortMode = () => {
  sortMode.value = sortMode.value === 'sort' ? 'drag' : 'sort'
}

const openCreateStep = () => {
  if (guardReadOnlyAction()) return

  stepForm.value = {
    title: '',
    basis: '',
    input_data: '',
    hours: 0,
    note: ''
  }
}

const openStepsModal = async (laborWork: LaborWork) => {
  selectedLaborWork.value = laborWork
  laborWorkSteps.value = []
  stepForm.value = {
    title: '',
    basis: '',
    input_data: '',
    hours: 0,
    note: ''
  }
  laborStepsLoadingId.value = laborWork.id ?? null
  
  // Reset AI state
  aiSuggestion.value = null
  aiAppliedSource.value = null
  feedbackSentFingerprint.value = null
  aiDesiredHours.value = laborWork.hours || undefined
  
  try {
    // Загрузить подоперации для выбранной работы
    await loadSteps(laborWork.id!)
    stepsDialog.value = true
  } catch (e: any) {
    console.error('Error opening steps modal:', e)
    showNotification('Ошибка загрузки подопераций', 'error')
  } finally {
    laborStepsLoadingId.value = null
  }
}

// === AI Decomposition Functions ===

const generateAiSteps = async () => {
  if (guardReadOnlyAction()) return

  if (!selectedLaborWork.value?.title) {
    showNotification('Не выбрана работа для детализации', 'error')
    return
  }

  aiLoading.value = true
  aiSuggestion.value = null
  aiSelectedSteps.value = new Set()

  try {
    // Filter out undefined values from context
    const cleanContext: DecomposeContext = {}
    if (aiContext.value.domain) cleanContext.domain = aiContext.value.domain
    if (aiContext.value.action_type) cleanContext.action_type = aiContext.value.action_type
    if (aiContext.value.constraints) cleanContext.constraints = aiContext.value.constraints
    if (aiContext.value.site_state) cleanContext.site_state = aiContext.value.site_state
    if (aiContext.value.material) cleanContext.material = aiContext.value.material
    if (aiContext.value.object_type) cleanContext.object_type = aiContext.value.object_type

    // Передаём примечание из работы для лучшего понимания контекста AI
    const workNote = selectedLaborWork.value.note || undefined

    const result = await aiDecompose(
      selectedLaborWork.value.title,
      cleanContext,
      aiDesiredHours.value || totalStepsHours.value || undefined,
      workNote
    )

    aiSuggestion.value = result
    // Auto-select all generated steps
    aiSelectedSteps.value = new Set(result.steps.map((_, i) => i))
  } catch (e: any) {
    if (showBillingLockedError(e)) return

    console.error('AI decomposition error:', e)
    const message = e.response?.data?.message || e.message || 'Ошибка AI генерации'
    showNotification(message, 'error')
  } finally {
    aiLoading.value = false
  }
}

const aiSelectedCount = computed(() => aiSelectedSteps.value.size)
const aiSelectedHours = computed(() => {
  if (!aiSuggestion.value) return 0
  return Array.from(aiSelectedSteps.value).reduce((sum, idx) => {
    return sum + (aiSuggestion.value!.steps[idx]?.hours || 0)
  }, 0)
})
const aiAllSelected = computed(() => {
  if (!aiSuggestion.value) return false
  return aiSelectedSteps.value.size === aiSuggestion.value.steps.length
})

const toggleAiStepSelection = (idx: number) => {
  const s = new Set(aiSelectedSteps.value)
  if (s.has(idx)) s.delete(idx); else s.add(idx)
  aiSelectedSteps.value = s
}

const toggleAiSelectAll = () => {
  if (!aiSuggestion.value) return
  if (aiAllSelected.value) {
    aiSelectedSteps.value = new Set()
  } else {
    aiSelectedSteps.value = new Set(aiSuggestion.value.steps.map((_, i) => i))
  }
}

const applyAiSteps = async (mode: 'replace' | 'append') => {
  if (!aiSuggestion.value || !selectedLaborWork.value) return
  if (guardReadOnlyAction()) return

  if (aiSelectedSteps.value.size === 0) {
    showNotification('Выберите хотя бы один этап', 'warning')
    return
  }

  aiApplying.value = true

  try {
    const selectedIndices = aiSelectedSteps.value
    const stepsPayload = aiSuggestion.value.steps
      .filter((_, idx) => selectedIndices.has(idx))
      .map(s => ({
        title: s.title,
        basis: s.basis,
        hours: s.hours,
        input_data: s.input_data || null,
        note: null
      }))

    if (mode === 'replace') {
      await api.put(
        `/api/projects/${projectId}/labor-works/${selectedLaborWork.value.id}/steps:replace`,
        { steps: stepsPayload }
      )
    } else {
      await api.post(
        `/api/projects/${projectId}/labor-works/${selectedLaborWork.value.id}/steps:append`,
        { steps: stepsPayload }
      )
    }

    // Reload steps
    await loadSteps(selectedLaborWork.value.id!)
    
    // Mark as AI applied for feedback
    aiAppliedSource.value = 'ai'
    
    showNotification(
      mode === 'replace' ? 'Этапы заменены' : 'Этапы добавлены',
      'success'
    )

    // Clear suggestion after apply
    aiSuggestion.value = null

    // Reload labor works in background
    loadLaborWorks().catch(e => console.warn('Background reload failed:', e))

  } catch (e: any) {
    if (showBillingLockedError(e)) return

    console.error('Apply AI steps error:', e)
    showNotification(e.response?.data?.message || 'Ошибка применения этапов', 'error')
  } finally {
    aiApplying.value = false
  }
}

const sendFeedbackOnClose = async () => {
  // Only send feedback if we have steps and a title
  if (!selectedLaborWork.value?.title || laborWorkSteps.value.length === 0) {
    return
  }

  // Calculate fingerprint
  const currentFingerprint = makeFingerprint(
    selectedLaborWork.value.title,
    laborWorkSteps.value.map(s => ({ title: s.title, hours: s.hours }))
  )

  // Anti-spam: don't send if fingerprint unchanged
  if (feedbackSentFingerprint.value === currentFingerprint) {
    return
  }

  // Determine source
  const source = aiAppliedSource.value || 'manual'

  try {
    // Filter context for feedback
    const cleanContext: DecomposeContext = {}
    if (aiContext.value.domain) cleanContext.domain = aiContext.value.domain
    if (aiContext.value.action_type) cleanContext.action_type = aiContext.value.action_type
    if (aiContext.value.constraints) cleanContext.constraints = aiContext.value.constraints
    if (aiContext.value.site_state) cleanContext.site_state = aiContext.value.site_state

    await aiFeedback({
      title: selectedLaborWork.value.title,
      context: cleanContext,
      steps: laborWorkSteps.value.map(s => ({
        title: s.title,
        basis: s.basis || '',
        hours: Number(s.hours),
        input_data: s.input_data || undefined
      })),
      source
    })

    feedbackSentFingerprint.value = currentFingerprint
    console.log('Feedback sent successfully')
  } catch (e) {
    // Silent fail - don't bother user with feedback errors
    console.warn('Feedback send failed (silent):', e)
  }
}

const closeStepsDialog = () => {
  // Send feedback in background (non-blocking)
  sendFeedbackOnClose()
  stepsDialog.value = false
}

const resetStepForm = () => {
  stepFormRef.value?.resetValidation?.()
  editingStepId.value = null
  showNoteField.value = false
  stepForm.value = {
    title: '',
    basis: '',
    input_data: '',
    hours: 0,
    note: ''
  }
}

const loadSteps = async (laborWorkId: number) => {
  try {
    const response = await api.get(
      `/api/projects/${projectId}/labor-works/${laborWorkId}/steps`
    )
    laborWorkSteps.value = response.data || []
  } catch (e: any) {
    console.error('Error loading steps:', e)
    showNotification('Ошибка загрузки подопераций', 'error')
  }
}

const saveStep = async () => {
  if (guardReadOnlyAction()) return

  if (!stepForm.value.title.trim()) {
    showNotification('Заполните наименование подоперации', 'error')
    return
  }

  if (stepForm.value.hours <= 0) {
    showNotification('Часы должны быть больше 0', 'error')
    return
  }

  savingStep.value = true

  try {
    // Найти оригинальный sort_order при редактировании
    let sortOrder = laborWorkSteps.value.length
    if (editingStepId.value) {
      const existingStep = laborWorkSteps.value.find((s: any) => s.id === editingStepId.value)
      if (existingStep) {
        sortOrder = existingStep.sort_order ?? sortOrder
      } else {
        // Шаг с editingStepId не найден — возможно удалён, сбросим режим редактирования
        console.warn('Editing step not found, switching to create mode', { editingStepId: editingStepId.value })
        editingStepId.value = null
      }
    }

    const payload = {
      title: stepForm.value.title.trim(),
      basis: stepForm.value.basis?.trim() || null,
      input_data: stepForm.value.input_data?.trim() || null,
      hours: parseFloat(String(stepForm.value.hours)) || 0,
      note: stepForm.value.note?.trim() || null,
      sort_order: sortOrder
    }

    if (editingStepId.value) {
      await api.put(
        `/api/projects/${projectId}/labor-works/${selectedLaborWork.value?.id}/steps/${editingStepId.value}`,
        payload,
        { timeout: 60000 }
      )
    } else {
      await api.post(
        `/api/projects/${projectId}/labor-works/${selectedLaborWork.value?.id}/steps`,
        payload,
        { timeout: 60000 }
      )
    }

    // Всегда перезагружаем список после успеха
    await loadSteps(selectedLaborWork.value?.id!)

    resetStepForm()

    showNotification(editingStepId.value ? 'Подоперация обновлена' : 'Подоперация добавлена', 'success')
    
    // Перезагрузить работы в фоне для обновления часов
    loadLaborWorks().catch((e: any) => {
      console.warn('Background labor works reload failed:', e)
    })

  } catch (err: any) {
    if (showBillingLockedError(err)) return

    console.error('Error saving step:', err)

    // ВАЖНО: даже при 500 можно попытаться обновить список,
    // потому что БД всё равно меняется
    try {
      await loadSteps(selectedLaborWork.value?.id!)
    } catch (e) {
      console.warn('Failed to reload steps after save error:', e)
    }

    const message = err.response?.data?.message || err.message || 'Ошибка сохранения подоперации'
    showNotification(message, 'error')

  } finally {
    savingStep.value = false
  }
}

const editStep = (step: any) => {
  if (guardReadOnlyAction()) return

  editingStepId.value = step.id
  stepForm.value = {
    title: step.title || '',
    basis: step.basis || '',
    input_data: step.input_data || '',
    hours: step.hours || 0,
    note: step.note || ''
  }
}

const cancelEdit = () => {
  resetStepForm()
}

// Drag-and-drop для подопераций
const draggedStepId = ref<number | null>(null)

const onDragStart = (step: any) => {
  draggedStepId.value = step.id
}

const onDragEnd = () => {
  draggedStepId.value = null
}

const onDrop = async (targetStep: any) => {
  if (guardReadOnlyAction()) return

  if (!draggedStepId.value || draggedStepId.value === targetStep.id) {
    return
  }

  const steps = [...laborWorkSteps.value]
  const draggedIndex = steps.findIndex((s: any) => s.id === draggedStepId.value)
  const targetIndex = steps.findIndex((s: any) => s.id === targetStep.id)

  if (draggedIndex === -1 || targetIndex === -1) return

  // Переместить элемент
  const [draggedItem] = steps.splice(draggedIndex, 1)
  steps.splice(targetIndex, 0, draggedItem)

  // Обновить sort_order для всех элементов
  const reorderedSteps = steps.map((step: any, index: number) => ({
    id: step.id,
    sort_order: index
  }))

  // Оптимистично обновить UI
  laborWorkSteps.value = steps.map((step: any, index: number) => ({
    ...step,
    sort_order: index
  }))

  // Отправить на сервер
  try {
    await api.patch(
      `/api/projects/${projectId}/labor-works/${selectedLaborWork.value?.id}/steps/reorder`,
      { steps: reorderedSteps }
    )
    showNotification('Порядок подопераций обновлён', 'success')
  } catch (err: any) {
    if (showBillingLockedError(err)) return

    console.error('Error reordering steps:', err)
    // Перезагрузить при ошибке
    await loadSteps(selectedLaborWork.value?.id!)
    showNotification('Ошибка сортировки', 'error')
  }
}

const deleteStep = async (step: any) => {
  if (guardReadOnlyAction()) return

  if (!confirm(`Удалить подоперацию "${step.title}"?`)) {
    return
  }

  try {
    await api.delete(
      `/api/projects/${projectId}/labor-works/${selectedLaborWork.value?.id}/steps/${step.id}`,
      { timeout: 60000 }
    )

    await loadSteps(selectedLaborWork.value?.id!)

    // Сбросить форму если удалили редактируемый шаг
    if (editingStepId.value === step.id) {
      resetStepForm()
    }

    showNotification('Подоперация удалена', 'success')
    
    // Перезагрузить работы в фоне
    loadLaborWorks().catch((e: any) => {
      console.warn('Background labor works reload failed:', e)
    })

  } catch (err: any) {
    if (showBillingLockedError(err)) return

    console.error('Error deleting step:', err)
    
    // По твоей ситуации — удаление могло случиться, но ответ 500
    try {
      await loadSteps(selectedLaborWork.value?.id!)
      
      // Сбросить форму даже при ошибке, если пытались удалить редактируемый шаг
      if (editingStepId.value === step.id) {
        resetStepForm()
      }
    } catch (e) {
      console.warn('Failed to reload steps after delete error:', e)
    }

    const message = err.response?.data?.message || err.message || 'Ошибка удаления подоперации'
    showNotification(message, 'error')
  }
}

const loadLaborWorks = async () => {
  try {
    loadingStates.value.laborWorks = true
    const works = await laborWorksApi.getAll(Number(projectId))
    laborWorks.value = (works || [])
      .map(work => ({
        ...work,
        cost: work.cost_total ?? (work.hours * (work.rate_per_hour || 0))
      }))
      .sort((a: any, b: any) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
    hasMissingLaborRates.value = false
  } catch (e) {
    console.error('loadLaborWorks error', e)
    hasMissingLaborRates.value = false
    laborWorks.value = []
  } finally {
    loadingStates.value.laborWorks = false
  }
}

// === Инициализация ===
const openDetailTypesInNewTab = () => {
  window.open('/detail-types', '_blank')
  // Периодически обновляем список типов детали, пока окно открыто
  const checkInterval = setInterval(async () => {
    try {
      const types = await api.get('/api/detail-types').then(r => r.data)
      detailTypes.value = types
    } catch (e) {
      console.error('Ошибка обновления типов детали:', e)
    }
  }, 2000) // Обновляем каждые 2 секунды
  
  // Остановим проверку через 5 минут
  setTimeout(() => clearInterval(checkInterval), 5 * 60 * 1000)
}

onMounted(async () => {
  // Сворачиваем основной sidebar приложения для фокуса на редакторе
  window.dispatchEvent(new CustomEvent('app-sidebar:request-rail'))

  await loadReferences()
  const projectLoaded = await fetchData()
  if (!projectLoaded) {
    return
  }

  loadingMessage.value = 'Почти готово…'
  await fetchLatestRevision()
  await fetchRevisions(1)

  const storedRunId = getStoredRevisionRunId()
  if (storedRunId) {
    activeRevisionRun.value = {
      id: storedRunId,
      project_id: Number(projectId),
      status: 'PENDING',
      total_items: 0,
      ok_items: 0,
      failed_items: 0,
    }
    await refreshRevisionRun(true)
    // If restored run is already finalized, clear it
    if (activeRevisionRun.value?.status === 'FINALIZED') {
      setStoredRevisionRunId(null)
      activeRevisionRun.value = null
      revisionRunItems.value = []
    }
  }

  // Плавно показываем готовый интерфейс
  loadingReady.value = true

  // ResizeObserver & IntersectionObserver for toolbar
  nextTick(() => {
    const el = positionsToolbarRef.value

    // IntersectionObserver on sentinel to toggle shadow when toolbar is stuck
    const sentinel = toolbarSentinelRef.value
    if (sentinel && el) {
      toolbarSentinelObserver = new IntersectionObserver(
        ([entry]) => {
          if (!entry) return

          el.classList.toggle('is-stuck', !entry.isIntersecting)
        },
        { root: sentinel.closest('.workspace-module-area'), threshold: 0 }
      )
      toolbarSentinelObserver.observe(sentinel)
    }
  })
})

watch(manualCloseDialog, (isOpen) => {
  if (!isOpen) {
    window.removeEventListener('paste', onWindowPasteForManualClose)
    manualScreenshotDropActive.value = false
    return
  }

  window.addEventListener('paste', onWindowPasteForManualClose)
  focusManualScreenshotDropzone()
})

onBeforeUnmount(() => {
  // Восстанавливаем сохранённое состояние sidebar
  window.dispatchEvent(new CustomEvent('app-sidebar:restore'))

  if (toolbarSentinelObserver) {
    toolbarSentinelObserver.disconnect()
    toolbarSentinelObserver = null
  }
  if (fittingSearchTimeout) {
    clearTimeout(fittingSearchTimeout)
  }
  Object.values(materialSearchTimeouts).forEach((timeout) => {
    if (timeout) clearTimeout(timeout)
  })
  window.removeEventListener('paste', onWindowPasteForManualClose)
  stopRevisionRunPolling()
})
</script>

<style scoped>
.project-read-only-alert {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

@media (max-width: 600px) {
  .project-read-only-alert {
    align-items: flex-start;
    flex-direction: column;
  }
}

/* === Workspace Layout === */
.workspace-root {
  display: flex;
  flex-direction: column;
  gap: 16px;
  height: calc(100dvh - 48px);
  min-height: 0;
  overflow: hidden;
}

.workspace-body {
  display: flex;
  flex: 1;
  min-width: 0;
  overflow: hidden;
}

/* Hide sidebar in compact layout; it is replaced by the horizontal module tabs. */
@media (max-width: 959px) {
  .workspace-sidebar-desktop {
    display: none !important;
  }
  .workspace-body {
    flex-direction: column;
  }
}

/* === Mobile Module Tab Bar === */
.workspace-mobile-tabs {
  flex-shrink: 0;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgb(var(--v-theme-surface));
}

.toolbar-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  min-width: 0;
  max-width: 100%;
  row-gap: 10px;
  padding-inline-end: 2px;
  box-sizing: border-box;
}

.workspace-mobile-tab-scroll {
  display: flex;
  overflow-x: auto;
  gap: 2px;
  padding: 4px 8px;
  scrollbar-width: none;
}
.workspace-mobile-tab-scroll::-webkit-scrollbar { display: none; }

.wmt-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border: none;
  background: transparent;
  border-radius: 20px;
  cursor: pointer;
  white-space: nowrap;
  font-size: 0.8rem;
  color: rgba(var(--v-theme-on-surface), 0.7);
  transition: background 0.15s, color 0.15s;
  position: relative;
}
.wmt-btn:hover {
  background: rgba(var(--v-theme-primary), 0.06);
  color: rgba(var(--v-theme-on-surface), 0.9);
}
.wmt-btn--active {
  background: rgba(var(--v-theme-primary), 0.12);
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
}

.wmt-label {
  font-size: 0.8rem;
}

.wmt-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 16px;
  height: 16px;
  padding: 0 4px;
  border-radius: 8px;
  background: rgba(var(--v-theme-on-surface), 0.15);
  color: rgba(var(--v-theme-on-surface), 0.8);
  font-size: 0.65rem;
  font-weight: 600;
}
.wmt-badge--warn {
  background: rgb(var(--v-theme-warning));
  color: #fff;
}

/* === Project Loading Overlay === */
.project-loading-overlay {
  position: absolute;
  inset: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgb(var(--v-theme-background));
}

.project-loading-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  padding: 40px 48px;
  max-width: 380px;
  text-align: center;
}

.project-loading-icon {
  margin-bottom: 4px;
}

.project-loading-pulse {
  animation: lp-pulse 1.8s ease-in-out infinite;
}

@keyframes lp-pulse {
  0%, 100% { opacity: 0.4; transform: scale(0.92); }
  50% { opacity: 1; transform: scale(1); }
}

.project-loading-message {
  font-size: 1rem;
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface), 0.8);
  margin: 0;
  min-height: 1.5em;
}

.project-loading-dots {
  display: flex;
  gap: 6px;
}

.project-loading-dots .dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: rgb(var(--v-theme-primary));
  opacity: 0.3;
  animation: lp-dot 1.4s ease-in-out infinite;
}
.project-loading-dots .dot:nth-child(2) { animation-delay: 0.2s; }
.project-loading-dots .dot:nth-child(3) { animation-delay: 0.4s; }

@keyframes lp-dot {
  0%, 80%, 100% { opacity: 0.3; transform: scale(0.8); }
  40% { opacity: 1; transform: scale(1.1); }
}

.project-loading-skeletons {
  display: flex;
  flex-direction: column;
  gap: 10px;
  width: 100%;
  margin-top: 12px;
}

.skeleton-row {
  display: flex;
  gap: 10px;
}

.skeleton-block {
  flex: 1;
  height: 14px;
  border-radius: 7px;
  background: linear-gradient(90deg,
    rgba(var(--v-theme-on-surface), 0.05) 25%,
    rgba(var(--v-theme-on-surface), 0.10) 50%,
    rgba(var(--v-theme-on-surface), 0.05) 75%
  );
  background-size: 200% 100%;
  animation: lp-shimmer 1.5s ease-in-out infinite;
}
.skeleton-block--short { max-width: 30%; }
.skeleton-block--wide  { max-width: 65%; }

@keyframes lp-shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

/* Fade transition for overlay */
.loading-fade-enter-active { transition: opacity 0.3s ease; }
.loading-fade-leave-active { transition: opacity 0.45s ease; }
.loading-fade-enter-from,
.loading-fade-leave-to { opacity: 0; }

.workspace-module-area {
  flex: 1;
  min-height: 0;
  min-width: 0;
  overflow-y: auto;
  overflow-x: auto;
  padding: 16px 20px 20px;
  position: relative;
}

@media (max-width: 959px) {
  .workspace-module-area {
    padding: 12px 12px;
  }
}

.module-content {
  width: 100%;
  max-width: none;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 22px;
}

.module-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 0;
  padding: 0;
  border: 0;
  background: transparent;
}

.dense-module-shell {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 20px;
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.46);
  border-radius: var(--md-sys-shape-corner-extra-large);
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.018), transparent 180px),
    rgba(var(--v-theme-surface-container-lowest), 0.96);
}

.dense-module-shell > .module-summary {
  padding: 0 0 12px;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.32);
}

.dense-module-hero {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 0 0 12px;
  border: 0;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.32);
  border-radius: 0;
  background: transparent;
}

.dense-module-hero__content,
.dense-module-actions-bar__info,
.dense-module-section-bar__content {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.dense-module-hero__eyebrow {
  font-size: 0.7rem;
  line-height: 1.2;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface-variant), 0.82);
}

.dense-module-hero__title-row,
.dense-module-section-bar,
.dense-module-actions-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.dense-module-hero__title {
  font-size: 1.05rem;
  line-height: 1.25;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 1);
}

.dense-module-surface {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 0;
  border: 0;
  border-radius: 0;
  background: transparent;
}

.dense-module-surface + .dense-module-surface {
  padding-top: 18px;
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.3);
}

.dense-module-section-bar,
.dense-module-actions-bar {
  padding: 0;
  border-bottom: 0;
}

.dense-module-section-bar--compact {
  padding-top: 2px;
}

.dense-module-section-bar__title,
.dense-module-actions-bar__title {
  font-size: 0.95rem;
  line-height: 1.25;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 1);
}

.dense-module-section-bar__meta,
.dense-module-actions-bar__actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.dense-module-inline-alert {
  margin: 0;
}

.dense-module-table-wrap {
  min-width: 0;
  overflow: auto;
  border: 0;
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.3);
  border-radius: 0;
  background: transparent;
}

.dense-module-table :deep(table) {
  border-collapse: separate;
  border-spacing: 0;
}

.dense-module-table :deep(thead th) {
  height: 44px !important;
  padding: 0 14px !important;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.34) !important;
  background: rgba(var(--v-theme-surface-container-low), 0.54) !important;
  color: rgba(var(--v-theme-on-surface-variant), 1) !important;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.03em;
  text-transform: uppercase;
}

.dense-module-table :deep(tbody td) {
  padding: 10px 14px !important;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.24) !important;
  vertical-align: middle;
}

.dense-module-table :deep(tbody tr:last-child td) {
  border-bottom: none !important;
}

.dense-module-support-grid {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.dense-module-support-card {
  min-width: 0;
  padding: 0;
  border: 0;
  border-radius: 0;
  background: transparent;
  overflow: visible;
}

.dense-module-support-card > :deep(*) {
  margin: 0 !important;
}

.dense-module-support-card > :deep(.v-card),
.dense-module-support-card > :deep(.v-sheet),
.dense-module-support-card > :deep(.saas-section-card) {
  border: none !important;
  border-radius: 0 !important;
  box-shadow: none !important;
  background: transparent !important;
}

.module-row-actions {
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  gap: 2px;
}

.module-row-actions--wide {
  flex-wrap: wrap;
  justify-content: flex-end;
}

.revision-surface-card {
  overflow: hidden;
  background: transparent;
}

.revision-run-card :deep(.v-card-title) {
  padding: 0 0 12px;
}

.revision-run-card :deep(.v-card-text) {
  padding: 0;
}

.revision-run-card :deep(.v-table) {
  border: 0;
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.3);
  border-radius: 0;
  background: transparent;
}

.revision-run-card :deep(.v-table th) {
  height: 40px;
  padding: 0 12px;
  background: rgba(var(--v-theme-surface-container), 0.54);
  color: rgba(var(--v-theme-on-surface-variant), 0.96);
  font-size: 0.73rem;
  font-weight: 700;
  letter-spacing: 0.03em;
  text-transform: uppercase;
}

.revision-run-card :deep(.v-table td) {
  padding: 9px 12px;
  vertical-align: middle;
}

.toolbar-sentinel {
  height: 1px;
  margin: 0;
  pointer-events: none;
}

/* === Unified Positions Toolbar === */
.positions-unified-toolbar {
  position: sticky;
  top: 0;
  z-index: 102;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px 12px;
  padding: 2px 0 0;
  background: transparent;
  border: 0;
  border-radius: 0;
  margin-bottom: 0;
  box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0);
  transition: box-shadow 0.25s ease;
}

.positions-unified-toolbar.is-stuck {
  padding: 10px 12px;
  border-radius: var(--md-sys-shape-corner-large);
  background: rgba(var(--v-theme-surface-container-low), 0.9);
  box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
  outline: 1px solid rgba(var(--v-theme-outline-variant), 0.36);
}

.toolbar-zone {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
  flex-wrap: wrap;
}

.toolbar-zone--left {
  flex-shrink: 0;
}

.toolbar-zone--middle {
  flex-shrink: 0;
}

.toolbar-zone--right {
  margin-left: auto;
}

.toolbar-bulk-hint {
  width: 100%;
  padding: 4px 2px 0;
}

.positions-table-wrap {
  border: 0;
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.3);
  border-radius: 0;
  background: transparent;
  padding: 0;
  overflow: auto;
}

.position-sheet {
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  border-radius: var(--md-sys-shape-corner-extra-large) !important;
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.03), transparent 140px),
    rgba(var(--v-theme-surface-container-low), 0.98);
  overflow: hidden;
}

.position-sheet-toolbar,
.position-sheet-title {
  padding-inline: 20px;
  padding-top: 18px;
  padding-bottom: 14px;
  font-size: 1.125rem;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 1);
}

.position-sheet-content {
  padding: 20px !important;
}

.position-sheet-actions {
  padding: 14px 20px 18px !important;
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.56);
  background: rgba(var(--v-theme-surface-container-low), 0.9);
}

.labor-calculation-dialog-card {
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  border-radius: var(--md-sys-shape-corner-extra-large) !important;
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.03), transparent 140px),
    rgba(var(--v-theme-surface-container-low), 0.98);
  overflow: hidden;
}

.labor-calculation-dialog-toolbar {
  padding-inline: 20px;
  padding-top: 14px;
  padding-bottom: 12px;
}

.labor-calculation-dialog-toolbar :deep(.v-toolbar-title) {
  font-size: 1.05rem;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 1);
}

.labor-calculation-dialog-content {
  padding: 18px !important;
  background: transparent;
}

.position-form-stack {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.position-form-section {
  padding: 16px;
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.66);
  border-radius: var(--md-sys-shape-corner-large);
  background: rgba(var(--v-theme-surface), 0.94);
}

.position-form-section--hero {
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.06), transparent 100px),
    rgba(var(--v-theme-surface), 0.98);
}

.position-form-section--muted {
  background: rgba(var(--v-theme-surface-container-low), 0.88);
}

.position-form-section__header {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 12px;
}

.position-form-section__title {
  font-size: 0.95rem;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 1);
}

.position-form-section__text {
  font-size: 0.8125rem;
  line-height: 1.45;
  color: rgba(var(--v-theme-on-surface-variant), 1);
}

.facade-allowance-row {
  display: flex;
  align-items: center;
  gap: 12px 16px;
  flex-wrap: wrap;
  margin-bottom: 12px;
  padding: 10px 12px;
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.58);
  border-radius: var(--md-sys-shape-corner-medium);
  background: rgba(var(--v-theme-surface-container-low), 0.78);
}

.position-support-card {
  padding: 12px;
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.58);
  border-radius: var(--md-sys-shape-corner-large);
  background: rgba(var(--v-theme-surface-container-low), 0.78);
}

.position-support-card--quotes {
  padding-bottom: 10px;
}

.positions-table-wrap :deep(.v-table__wrapper) {
  border-radius: 0;
  background: transparent;
  overflow-y: hidden !important;
  max-height: none !important;
  scrollbar-width: none;
}

.positions-table-wrap :deep(.v-table__wrapper::-webkit-scrollbar) {
  width: 0;
  height: 0;
}

.positions-table :deep(table) {
  border-collapse: separate;
  border-spacing: 0;
}

.positions-table :deep(thead th) {
  height: 44px !important;
  padding: 0 12px !important;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.34) !important;
  background: rgba(var(--v-theme-surface-container-low), 0.54) !important;
  color: rgba(var(--v-theme-on-surface-variant), 1) !important;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.03em;
  text-transform: uppercase;
}

.positions-table :deep(tbody td) {
  padding: 8px 12px !important;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.24) !important;
  background: transparent !important;
  vertical-align: middle;
}

.positions-table :deep(tbody tr:last-child td) {
  border-bottom: none !important;
}

.position-row {
  cursor: pointer;
  transition:
    background-color 0.18s ease,
    box-shadow 0.18s ease;
}

.position-row td:first-child {
  width: 44px;
  padding-inline-start: 8px !important;
  padding-inline-end: 8px !important;
}

.position-row.row-hovered td,
.position-row:hover td {
  background: rgba(var(--v-theme-primary), 0.05) !important;
}

.position-row.row-selected td {
  background: rgba(var(--v-theme-primary), 0.09) !important;
}

.position-row.row-selected td:first-child {
  box-shadow: inset 3px 0 0 rgba(var(--v-theme-primary), 1);
}

.position-row.row-highlighted td {
  background:
    linear-gradient(0deg, rgba(var(--v-theme-tertiary), 0.08), rgba(var(--v-theme-tertiary), 0.08)),
    rgba(var(--v-theme-surface), 1) !important;
}

.cell-with-actions {
  min-width: 0;
}

.position-row .cell-with-actions {
  padding-top: 6px;
  padding-bottom: 6px;
}

.cell-name-column {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  gap: 2px;
  min-width: 0;
  min-height: 36px;
}

.cell-name {
  font-weight: 600;
  line-height: 1.25;
  color: rgba(var(--v-theme-on-surface), 1);
}

.cell-ellipsis {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.positions-table :deep(tbody td .v-chip) {
  vertical-align: middle;
}

.positions-table :deep(tbody td .v-selection-control) {
  align-items: center;
}

.positions-table :deep(tbody td .v-checkbox-btn) {
  justify-content: center;
}

.positions-table :deep(.row-hover-actions--dense) {
  align-self: flex-start;
}

.positions-table :deep(.row-hover-actions--dense .action-icon-btn) {
  margin-top: 1px;
}



@media (max-width: 960px) {
  .workspace-module-area {
    padding: 12px 12px;
  }

  .toolbar-actions {
    justify-content: flex-start;
    padding-inline-end: 0;
  }

  .toolbar-zone--right {
    margin-left: 0;
  }
}

@media (max-width: 760px) {
  .workspace-root {
    gap: 12px;
    height: calc(100dvh - 32px);
  }

  .toolbar-actions {
    width: 100%;
  }

  .toolbar-actions :deep(.v-btn) {
    flex: 1 1 calc(50% - 4px);
    min-width: 0;
  }

  .module-content {
    gap: 14px;
  }

  .dense-module-shell {
    gap: 14px;
    padding: 14px;
    border-radius: var(--md-sys-shape-corner-large);
  }

  .dense-module-hero {
    padding-bottom: 10px;
  }

  .dense-module-actions-bar__actions,
  .dense-module-section-bar__meta {
    width: 100%;
  }

  .dense-module-actions-bar__actions .v-btn,
  .toolbar-zone .v-btn {
    flex: 1 1 auto;
  }

  .positions-unified-toolbar {
    padding: 10px 12px;
  }

  .toolbar-zone {
    width: 100%;
  }

  .positions-table-wrap {
    padding: 0;
    border-radius: 0;
  }

  .positions-table :deep(thead th),
  .positions-table :deep(tbody td) {
    padding-inline: 10px !important;
  }

  .position-sheet-content {
    padding: 14px !important;
  }

  .position-sheet-toolbar,
  .position-sheet-title,
  .position-sheet-actions {
    padding-inline: 14px !important;
  }

  .labor-calculation-dialog-toolbar {
    padding-inline: 14px;
  }

  .labor-calculation-dialog-content {
    padding: 14px !important;
  }

  .position-form-section {
    padding: 12px;
  }

  .dimension-swap-col--details {
    align-items: center;
    padding-top: 0;
  }

  .dimension-feedback-slot {
    min-height: 0;
  }
}

/* === Original styles below === */
.steps-dialog-card {
  display: flex;
  flex-direction: column;
  height: 80vh;
}

.manual-screenshot-dropzone {
  border: 1px dashed rgba(var(--v-theme-primary), 0.45);
  border-radius: 10px;
  padding: 10px;
  background: rgba(var(--v-theme-primary), 0.03);
  transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
}

.manual-screenshot-dropzone:focus {
  outline: none;
  box-shadow: 0 0 0 2px rgba(var(--v-theme-primary), 0.22);
}

.manual-screenshot-dropzone--active {
  border-color: rgba(var(--v-theme-primary), 0.95);
  background: rgba(var(--v-theme-primary), 0.08);
}

.steps-dialog-header {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 16px;
}

.header-info {
  flex: 1;
  min-width: 0;
}

.header-title {
  display: flex;
  align-items: center;
  font-weight: 600;
}

.header-subtitle {
  color: rgba(var(--v-theme-on-surface), 0.7);
  font-size: 0.875rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.header-stats {
  display: flex;
  align-items: center;
  gap: 12px;
}

.stat-item {
  text-align: center;
}

.stat-item.primary .stat-value {
  color: rgb(var(--v-theme-primary));
}

.stat-value {
  font-weight: 600;
}

.stat-label {
  font-size: 0.75rem;
  color: rgba(var(--v-theme-on-surface), 0.6);
}

.stat-divider {
  width: 1px;
  height: 24px;
  background: rgba(var(--v-theme-on-surface), 0.12);
}

.close-btn {
  margin-left: auto;
}

.steps-dialog-body {
  display: flex;
  gap: 16px;
  padding: 16px;
  overflow: hidden;
}

.steps-list-panel,
.steps-form-panel {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.steps-list-panel {
  flex: 1 1 55%;
}

.steps-form-panel {
  flex: 1 1 45%;
}

.list-toolbar {
  margin-bottom: 12px;
}

.list-content {
  flex: 1;
  overflow: auto;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 8px;
  padding: 12px;
}

.steps-compact-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.step-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 10px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 8px;
  cursor: pointer;
}

.step-item:hover {
  background: rgba(var(--v-theme-on-surface), 0.04);
}

.step-dragging {
  opacity: 0.6;
}

/* Drag-and-drop таблица нормируемых работ */
.labor-works-table {
  margin-bottom: 0;
}

.lw-table {
  width: 100%;
  border-collapse: collapse;
}

.lw-table {
  width: 100%;
  border-collapse: collapse;
  background: rgb(var(--v-theme-surface));
  border: none;
  border-radius: 0;
  overflow: hidden;
}

.lw-th {
  text-align: left;
  padding: 0 var(--ds-space-16);
  height: 44px;
  min-height: 44px;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface-variant), 1);
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.62);
  background: rgba(var(--v-theme-surface-container), 0.88);
  vertical-align: middle;
}

.lw-th-drag {
  padding: 0 var(--ds-space-8);
  width: 40px;
}

.lw-th-right {
  text-align: right;
}

.lw-row {
  transition: background 0.15s;
  cursor: grab;
  height: 52px;
}

.lw-row:hover {
  background: var(--ds-hover-bg);
}

.lw-row-dragging {
  opacity: 0.5;
  background: rgba(var(--v-theme-primary), 0.05);
}

.lw-row-over {
  border-top: 2px solid rgb(var(--v-theme-primary));
}

.lw-td {
  padding: 10px var(--ds-space-16);
  font-size: 0.8125rem;
  line-height: 1.35;
  min-height: 52px;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.4);
  vertical-align: middle;
}

.lw-td-drag {
  padding: 0 var(--ds-space-8);
  width: 40px;
}

.lw-td-right {
  text-align: right;
}

.lw-drag-handle {
  cursor: grab;
  color: rgba(var(--v-theme-on-surface), 0.3);
  transition: color 0.15s;
}

.lw-row:hover .lw-drag-handle {
  color: rgba(var(--v-theme-on-surface), 0.7);
}

.step-editing {
  border-color: rgba(var(--v-theme-warning), 0.7);
  background: rgba(var(--v-theme-warning), 0.08);
}

.step-index {
  display: flex;
  align-items: center;
  gap: 6px;
  color: rgba(var(--v-theme-on-surface), 0.6);
}

.drag-handle {
  cursor: grab;
}

.step-content {
  flex: 1;
  min-width: 0;
}

.step-title {
  font-weight: 500;
}

.step-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 0.75rem;
  color: rgba(var(--v-theme-on-surface), 0.6);
}

.meta-item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  max-width: 220px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.step-hours {
  font-weight: 600;
  white-space: nowrap;
}

.step-actions {
  display: flex;
  align-items: center;
}

.empty-state {
  text-align: center;
  padding: 32px 8px;
  color: rgba(var(--v-theme-on-surface), 0.6);
}

.empty-title {
  font-weight: 600;
  margin-top: 8px;
}

.empty-text {
  font-size: 0.875rem;
}

.form-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.step-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.form-row {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}

.time-field {
  flex: 0 0 140px;
}

.flex-grow {
  flex: 1 1 auto;
  min-width: 200px;
}

.field-label {
  display: block;
  font-size: 0.75rem;
  color: rgba(var(--v-theme-on-surface), 0.6);
  margin-bottom: 4px;
}

.field-label.required::after {
  content: '*';
  color: rgb(var(--v-theme-error));
  margin-left: 4px;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}

.steps-dialog-footer {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
}

.footer-summary {
  display: flex;
  align-items: center;
  gap: 8px;
}

.summary-label {
  color: rgba(var(--v-theme-on-surface), 0.6);
}

/* AI Assistant Block Styles */
.ai-assistant-block {
  background: rgba(var(--v-theme-purple), 0.04);
  border: 1px solid rgba(var(--v-theme-purple), 0.2);
  border-radius: 8px;
  padding: 12px;
}

.ai-header {
  margin-bottom: 12px;
}

.ai-context-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-bottom: 12px;
}

.ai-optional-fields {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-bottom: 12px;
}

.ai-options-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.ai-preview-panel {
  margin-top: 12px;
  padding: 12px;
  background: rgba(var(--v-theme-surface-variant), 0.5);
  border-radius: 8px;
}

.ai-preview-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}

.ai-preview-header-left {
  display: flex;
  align-items: center;
  gap: 4px;
}

.ai-preview-header-right {
  display: flex;
  align-items: center;
}

.ai-select-all-cb {
  flex-shrink: 0;
}

.ai-select-all-cb :deep(.v-selection-control) {
  min-height: 24px;
}

.ai-preview-title {
  font-weight: 600;
  font-size: 0.875rem;
}

.ai-preview-list {
  max-height: 200px;
  overflow-y: auto;
  margin-bottom: 12px;
}

.ai-preview-item {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 2px 0;
  font-size: 0.875rem;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  cursor: pointer;
  border-radius: 4px;
  transition: background 0.15s, opacity 0.2s;
}

.ai-preview-item:hover {
  background: rgba(var(--v-theme-on-surface), 0.04);
}

.ai-preview-item-unselected {
  opacity: 0.45;
}

.ai-preview-item-unselected .ai-preview-text {
  text-decoration: line-through;
}

.ai-step-cb {
  flex-shrink: 0;
}

.ai-step-cb :deep(.v-selection-control) {
  min-height: 24px;
}

.ai-preview-item:last-child {
  border-bottom: none;
}

.ai-preview-num {
  flex-shrink: 0;
  color: rgba(var(--v-theme-on-surface), 0.5);
  min-width: 20px;
}

.ai-preview-text {
  flex: 1;
  min-width: 0;
}

.ai-preview-hours {
  flex-shrink: 0;
  font-weight: 500;
  color: rgba(var(--v-theme-primary), 1);
}

.ai-preview-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.dimension-swap-col {
  display: flex;
  align-items: center;
  justify-content: center;
}

.position-dimensions-row {
  align-items: start;
}

.position-dimensions-field {
  display: flex;
}

.dimension-field-stack {
  display: flex;
  flex-direction: column;
  width: 100%;
}

.dimension-feedback-slot {
  min-height: 74px;
}

.dimension-swap-col--details {
  align-items: flex-start;
  padding-top: 18px;
}

.dimension-swap-btn {
  transform: none;
}

.edge-hint :deep(.v-field) {
  border-radius: 0 !important;
  transition: box-shadow 0.16s ease;
}

.edge-hint-width-tb :deep(.v-field) {
  box-shadow:
    inset 0 2px 0 rgba(var(--v-theme-primary), 1),
    inset 0 -2px 0 rgba(var(--v-theme-primary), 1);
}

.edge-hint-length-lr :deep(.v-field) {
  box-shadow:
    inset 2px 0 0 rgba(var(--v-theme-primary), 1),
    inset -2px 0 0 rgba(var(--v-theme-primary), 1);
}

.edge-hint-width-top :deep(.v-field) {
  box-shadow: inset 0 2px 0 rgba(var(--v-theme-primary), 1);
}

.edge-hint-width-bottom :deep(.v-field) {
  box-shadow: inset 0 -2px 0 rgba(var(--v-theme-primary), 1);
}

.edge-hint-length-left :deep(.v-field) {
  box-shadow: inset 2px 0 0 rgba(var(--v-theme-primary), 1);
}

.edge-hint-length-right :deep(.v-field) {
  box-shadow: inset -2px 0 0 rgba(var(--v-theme-primary), 1);
}

.quick-quantity-group-wrap {
  overflow-x: auto;
  padding-bottom: 2px;
}

.position-drawer-fixed :deep(.v-navigation-drawer__content) {
  height: 100%;
  overflow-y: auto;
}

.project-editor-page {
  max-width: 100%;
  overflow-x: visible;
}

.drawer-quotes-table {
  font-size: 12px;
}
.drawer-quotes-table :deep(th),
.drawer-quotes-table :deep(td) {
  padding: 6px 8px !important;
  height: auto !important;
}

.bulk-actions-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  column-gap: 10px;
  row-gap: 8px;
}

.bulk-actions-row :deep(.v-input) {
  min-width: 180px;
}

.excel-import-btn {
  color: #117e43 !important;
  border-color: #117e43 !important;
}

.position-toolbar-toggle {
  height: auto !important;
  overflow: visible !important;
}

.position-toolbar-toggle :deep(.v-btn) {
  border-radius: 0 !important;
  border-inline-start-width: 1px !important;
  border-inline-end-width: 0 !important;
  box-shadow: none !important;
  margin: 0 !important;
}

.position-toolbar-toggle :deep(.v-btn:first-child) {
  border-radius: 8px 0 0 8px !important;
}

.position-toolbar-toggle :deep(.v-btn:last-child) {
  border-radius: 0 8px 8px 0 !important;
  border-inline-end-width: 1px !important;
}

.position-toolbar-toggle :deep(.v-btn:only-child) {
  border-radius: 8px !important;
  border-inline-end-width: 1px !important;
}

.position-kind-toggle {
  height: auto !important;
  overflow: visible !important;
}

.position-kind-toggle :deep(.v-btn) {
  margin-right: 8px;
  margin-bottom: 8px;
  border-radius: var(--md-sys-shape-corner-large) !important;
  border-inline-start-width: 1px !important;
  border-inline-end-width: 1px !important;
}

.dimension-help {
  margin-top: -2px;
  margin-bottom: 6px;
  color: rgba(var(--v-theme-on-surface-variant), 0.92);
}

.dimension-calc-line {
  margin-top: 4px;
  margin-bottom: 6px;
  padding: 8px 10px;
  border-radius: var(--md-sys-shape-corner-medium);
  background: rgba(var(--v-theme-primary), 0.06);
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
}

.edge-preview-block {
  padding: 12px;
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.62);
  border-radius: var(--md-sys-shape-corner-large);
  background: rgba(var(--v-theme-surface-container-low), 0.82);
}

.edge-preview-diagram {
  display: grid;
  justify-items: center;
  gap: 4px;
  min-height: 126px;
}

.edge-preview-row {
  display: grid;
  grid-template-columns: minmax(38px, max-content) minmax(68px, 136px);
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
}

.edge-preview-length-label,
.edge-preview-width-label {
  font-size: 11px;
  line-height: 1.2;
  color: rgba(var(--v-theme-on-surface), 0.64);
}

.edge-preview-width-label {
  justify-self: end;
  text-align: right;
  max-width: 54px;
}

.edge-preview-box {
  position: relative;
  border-radius: var(--md-sys-shape-corner-small);
  margin: 0 auto;
  background: rgba(var(--v-theme-surface), 1);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.68);
  min-width: 68px;
  min-height: 48px;
  max-width: 136px;
  max-height: 92px;
}

.edge-side {
  position: absolute;
  background: rgba(var(--v-theme-on-surface), 0.16);
  transition: background-color 0.18s ease, box-shadow 0.18s ease;
}

.edge-side.top,
.edge-side.bottom {
  height: 3px;
  left: 6px;
  right: 6px;
}

.edge-side.top {
  top: 5px;
}

.edge-side.bottom {
  bottom: 5px;
}

.edge-side.left,
.edge-side.right {
  width: 3px;
  top: 6px;
  bottom: 6px;
}

.edge-side.left {
  left: 5px;
}

.edge-side.right {
  right: 5px;
}

.edge-side.active {
  background: rgba(var(--v-theme-primary), 1);
  box-shadow: 0 0 0 1px rgba(var(--v-theme-primary), 0.18);
}

.edge-center-label {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  color: rgba(var(--v-theme-on-surface), 0.62);
}

@media (max-width: 600px) {
  .project-toolbar:deep(.v-toolbar__content) {
    height: auto !important;
    min-height: 56px;
    align-items: stretch;
    flex-wrap: wrap;
    gap: 8px;
    padding-top: 8px;
    padding-bottom: 8px;
  }

  .project-toolbar--compact:deep(.v-toolbar-title) {
    flex: 1 1 100%;
    min-width: 0;
    white-space: normal;
    line-height: 1.25;
    margin-inline-end: 0;
  }

  .project-toolbar--compact:deep(.v-spacer) {
    display: none;
  }

  .project-toolbar--compact:deep(.v-btn) {
    flex: 1 1 calc(50% - 8px);
    min-width: 0;
    margin: 0 !important;
  }

  .position-drawer-fixed--compact {
    width: 100vw !important;
    max-width: 100vw !important;
  }
}
</style>
