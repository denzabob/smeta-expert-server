<template>
  <PageContainer class="user-settings-page">
    <PageHeader
      title="Настройки проекта по умолчанию"
      subtitle="Эти значения подставляются в новые проекты и могут быть изменены в конкретном проекте."
    />

    <SectionCard
      class="settings-shell"
      subtitle="Значения по умолчанию для новых проектов, коэффициентов, материалов и справочных блоков."
    >
      <div class="usd-body">
        <SettingsShell
          :sections="sections"
          v-model="activeSection"
          :nav-width="240"
        >
            <v-skeleton-loader
              v-if="loading"
              type="article, paragraph, paragraph, paragraph"
            />

            <template v-else>
              <!-- 0. Регион и режим расчёта -->
              <div v-if="activeSection === 0" class="section-content usd-section-content">
                <div class="section-title">Регион и режим расчёта</div>
                <div class="section-hint">Используются при создании новых проектов</div>

                <v-row dense>
                  <v-col cols="12" md="6">
                    <v-select
                      v-model="form.region_id"
                      :items="regions"
                      item-title="name"
                      item-value="id"
                      clearable
                      label="Регион по умолчанию"
                    />
                  </v-col>
                  <v-col cols="12" md="6">
                    <v-switch
                    color="primary"
                      v-model="form.use_area_calc_mode"
                      label="Режим расчёта по площади"
                      hide-details
                    />
                  </v-col>
                </v-row>
              </div>

              <!-- 1. Общие коэффициенты -->
              <div v-else-if="activeSection === 1" class="section-content usd-section-content">
                <div class="section-title">Общие коэффициенты</div>
                <div class="section-hint">Применяются к новым проектам, если клиент явно не передал значения</div>

                <v-row dense>
                  <v-col cols="12" md="6">
                    <v-text-field
                      v-model.number="form.waste_coefficient"
                      type="number"
                      step="0.01"
                      min="0"
                      label="Коэффициент отходов (общий)"
                    />
                  </v-col>
                  <v-col cols="12" md="6">
                    <v-text-field
                      v-model.number="form.repair_coefficient"
                      type="number"
                      step="0.01"
                      min="0"
                      label="Коэффициент ремонтопригодности"
                    />
                  </v-col>
                </v-row>
              </div>

              <!-- 2. Материалы по умолчанию -->
              <div v-else-if="activeSection === 2" class="section-content usd-section-content">
                <div class="section-title">Материалы по умолчанию</div>
                <div class="section-hint">Будут подставляться в новые проекты</div>

                <v-row dense>
                  <v-col cols="12" md="6">
                    <v-select
                      v-model="form.default_plate_material_id"
                      :items="plateMaterials"
                      item-title="name"
                      item-value="id"
                      clearable
                      label="Листовой материал по умолчанию"
                    />
                  </v-col>
                  <v-col cols="12" md="6">
                    <v-select
                      v-model="form.default_edge_material_id"
                      :items="edgeMaterials"
                      item-title="name"
                      item-value="id"
                      clearable
                      label="Кромочный материал по умолчанию"
                    />
                  </v-col>
                </v-row>
              </div>

              <!-- 6. Припуски фасадов -->
              <div v-else-if="activeSection === 6" class="section-content usd-section-content">
                <div class="section-title">Припуски фасадов</div>
                <div class="section-hint">Используются в новых проектах при создании фасадной позиции с включённым вычитанием припуска.</div>

                <v-row dense>
                  <v-col cols="12" md="6">
                    <v-text-field
                      v-model.number="form.facade_width_allowance_mm"
                      type="number"
                      step="1"
                      min="0"
                      max="1000"
                      label="Припуск по ширине"
                      suffix="мм"
                      hint="Например: 4. Ввод 600 станет 596."
                      persistent-hint
                      :error-messages="fieldErrors.facade_width_allowance_mm"
                    />
                  </v-col>
                  <v-col cols="12" md="6">
                    <v-text-field
                      v-model.number="form.facade_height_allowance_mm"
                      type="number"
                      step="1"
                      min="0"
                      max="1000"
                      label="Припуск по высоте"
                      suffix="мм"
                      hint="Для фасадов высота хранится в поле длины позиции."
                      persistent-hint
                      :error-messages="fieldErrors.facade_height_allowance_mm"
                    />
                  </v-col>
                </v-row>
              </div>

              <!-- 3. Отходы -->
              <div v-else-if="activeSection === 3" class="section-content usd-section-content">
                <div class="section-title">Коэффициенты отходов</div>
                <div class="section-hint">Специфичные коэффициенты для каждого типа материала</div>

                <div class="usd-waste-list">
                  <!-- Плитные -->
                      <div class="usd-waste-row">
                        <div class="usd-waste-main">
                          <span class="text-subtitle-2 font-weight-bold usd-waste-label">Плитные</span>
                          <v-text-field
                            v-model.number="form.waste_plate_coefficient"
                            type="number"
                            step="0.01"
                            min="1"
                            density="compact"
                            hide-details
                            class="usd-waste-field"
                            placeholder="1.00"
                            hint="1.00 = без изменения"
                            persistent-hint
                          />
                        </div>
                        <div class="usd-waste-controls">
                          <v-switch
                            v-model="form.apply_waste_to_plate"
                            hide-details
                            density="compact"
                            color="primary"
                            label="Применять"
                            class="usd-waste-toggle"
                          />
                          <v-switch
                            v-model="form.show_waste_plate_description"
                            :disabled="!plateDesc.title && !plateDesc.text"
                            hide-details
                            density="compact"
                            color="primary"
                            label="В отчёте"
                            class="usd-waste-toggle"
                          />
                        </div>
                        <v-btn
                          size="small"
                          variant="tonal"
                          color="primary"
                          @click="showPlateDescDialog = true"
                          title="Редактировать описание"
                          class="usd-waste-action"
                        >
                          <v-icon size="small" class="mr-1">mdi-pencil</v-icon>
                          Описание
                        </v-btn>
                      </div>

                      <!-- Кромка -->
                      <div class="usd-waste-row">
                        <div class="usd-waste-main">
                          <span class="text-subtitle-2 font-weight-bold usd-waste-label">Кромка</span>
                          <v-text-field
                            v-model.number="form.waste_edge_coefficient"
                            type="number"
                            step="0.01"
                            min="1"
                            density="compact"
                            hide-details
                            class="usd-waste-field"
                            placeholder="1.00"
                            hint="1.00 = без изменения"
                            persistent-hint
                          />
                        </div>
                        <div class="usd-waste-controls">
                          <v-switch
                            v-model="form.apply_waste_to_edge"
                            hide-details
                            density="compact"
                            color="primary"
                            label="Применять"
                            class="usd-waste-toggle"
                          />
                          <v-switch
                            v-model="form.show_waste_edge_description"
                            :disabled="!edgeDesc.title && !edgeDesc.text"
                            hide-details
                            density="compact"
                            color="primary"
                            label="В отчёте"
                            class="usd-waste-toggle"
                          />
                        </div>
                        <v-btn
                          size="small"
                          variant="tonal"
                          color="primary"
                          @click="showEdgeDescDialog = true"
                          title="Редактировать описание"
                          class="usd-waste-action"
                        >
                          <v-icon size="small" class="mr-1">mdi-pencil</v-icon>
                          Описание
                        </v-btn>
                      </div>

                      <!-- Операции -->
                      <div class="usd-waste-row">
                        <div class="usd-waste-main">
                          <span class="text-subtitle-2 font-weight-bold usd-waste-label">Операции</span>
                          <v-text-field
                            v-model.number="form.waste_operations_coefficient"
                            type="number"
                            step="0.01"
                            min="1"
                            density="compact"
                            hide-details
                            class="usd-waste-field"
                            placeholder="1.00"
                            hint="1.00 = без изменения"
                            persistent-hint
                          />
                        </div>
                        <div class="usd-waste-controls">
                          <v-switch
                            v-model="form.apply_waste_to_operations"
                            hide-details
                            density="compact"
                            color="primary"
                            label="Применять"
                            class="usd-waste-toggle"
                          />
                          <v-switch
                            v-model="form.show_waste_operations_description"
                            :disabled="!opsDesc.title && !opsDesc.text"
                            hide-details
                            density="compact"
                            color="primary"
                            label="В отчёте"
                            class="usd-waste-toggle"
                          />
                        </div>
                        <v-btn
                          size="small"
                          variant="tonal"
                          color="primary"
                          @click="showOpsDescDialog = true"
                          title="Редактировать описание"
                          class="usd-waste-action"
                        >
                          <v-icon size="small" class="mr-1">mdi-pencil</v-icon>
                          Описание
                        </v-btn>
                      </div>
                </div>

                <!-- Description dialogs -->
                <v-dialog v-model="showPlateDescDialog" max-width="500">
                  <v-card title="Описание плитных материалов">
                    <v-card-text class="usd-dialog-form">
                      <v-text-field v-model="plateDesc.title" label="Заголовок" />
                      <v-textarea v-model="plateDesc.text" label="Текст описания" rows="6" />
                    </v-card-text>
                    <v-card-actions class="usd-dialog-actions">
                      <v-spacer></v-spacer>
                      <v-btn variant="text" @click="showPlateDescDialog = false">Закрыть</v-btn>
                      <v-btn color="primary" variant="flat" @click="showPlateDescDialog = false">Сохранить</v-btn>
                    </v-card-actions>
                  </v-card>
                </v-dialog>

                <v-dialog v-model="showEdgeDescDialog" max-width="500">
                  <v-card title="Описание кромочных материалов">
                    <v-card-text class="usd-dialog-form">
                      <v-text-field v-model="edgeDesc.title" label="Заголовок" />
                      <v-textarea v-model="edgeDesc.text" label="Текст описания" rows="6" />
                    </v-card-text>
                    <v-card-actions class="usd-dialog-actions">
                      <v-spacer></v-spacer>
                      <v-btn variant="text" @click="showEdgeDescDialog = false">Закрыть</v-btn>
                      <v-btn color="primary" variant="flat" @click="showEdgeDescDialog = false">Сохранить</v-btn>
                    </v-card-actions>
                  </v-card>
                </v-dialog>

                <v-dialog v-model="showOpsDescDialog" max-width="500">
                  <v-card title="Описание операций">
                    <v-card-text class="usd-dialog-form">
                      <v-text-field v-model="opsDesc.title" label="Заголовок" />
                      <v-textarea v-model="opsDesc.text" label="Текст описания" rows="6" />
                    </v-card-text>
                    <v-card-actions class="usd-dialog-actions">
                      <v-spacer></v-spacer>
                      <v-btn variant="text" @click="showOpsDescDialog = false">Закрыть</v-btn>
                      <v-btn color="primary" variant="flat" @click="showOpsDescDialog = false">Сохранить</v-btn>
                    </v-card-actions>
                  </v-card>
                </v-dialog>
              </div>

              <!-- 4. Справочные блоки -->
              <div v-else-if="activeSection === 4" class="section-content usd-section-content">
                <div class="section-title">Настройки расчёта нормо-часа</div>
                <div class="section-hint">
                  Эти параметры используются для расчёта стоимости 1 часа подрядных работ и для PDF-обоснования.
                </div>

                <v-row dense>
                  <v-col cols="12" md="6">
                    <v-text-field
                      v-model.number="form.labor_employer_insurance_rate_percent"
                      type="number"
                      step="0.1"
                      min="0"
                      max="100"
                      label="Страховые начисления работодателя"
                      suffix="%"
                      :error-messages="fieldErrors.labor_employer_insurance_rate"
                    />
                  </v-col>
                  <v-col cols="12" md="6">
                    <v-select
                      v-model="form.labor_aggregation_strategy"
                      :items="aggregationStrategyOptions"
                      item-title="title"
                      item-value="value"
                      label="Стратегия агрегации"
                      :error-messages="fieldErrors.labor_aggregation_strategy"
                    />
                  </v-col>
                  <v-col cols="12" md="6">
                    <v-select
                      v-model="form.labor_salary_range_strategy"
                      :items="salaryRangeStrategyOptions"
                      item-title="title"
                      item-value="value"
                      label="Стратегия выбора ставки из диапазона"
                      :error-messages="fieldErrors.labor_salary_range_strategy"
                    />
                  </v-col>

                  <v-col cols="12" md="6">
                    <v-text-field
                      v-model.number="form.labor_load_factor_calendar_hours"
                      type="number"
                      step="1"
                      min="1"
                      label="Календарный фонд часов"
                      :error-messages="fieldErrors.labor_load_factor_calendar_hours"
                    />
                  </v-col>
                  <v-col cols="12" md="6">
                    <v-text-field
                      v-model.number="form.labor_load_factor_productive_hours"
                      type="number"
                      step="1"
                      min="1"
                      label="Производительные часы"
                      :error-messages="fieldErrors.labor_load_factor_productive_hours"
                    />
                  </v-col>

                  <v-col cols="12" md="6">
                    <v-text-field
                      v-model.number="form.labor_planned_profitability_rate_percent"
                      type="number"
                      step="0.1"
                      min="0"
                      max="100"
                      label="Плановая рентабельность"
                      suffix="%"
                      :error-messages="fieldErrors.labor_planned_profitability_rate"
                    />
                  </v-col>
                  <v-col cols="12" md="6">
                    <v-text-field
                      v-model.number="form.labor_rate_rounding_scale"
                      type="number"
                      step="1"
                      min="0"
                      max="6"
                      label="Точность округления"
                      hint="Количество знаков после запятой в расчётах."
                      persistent-hint
                      :error-messages="fieldErrors.labor_rate_rounding_scale"
                    />
                  </v-col>

                  <v-col cols="12">
                    <div class="labor-helper-block">
                      <div class="text-body-2 text-medium-emphasis">
                        Коэффициент загрузки рассчитывается как календарные часы / производительные часы.
                      </div>
                      <div class="labor-preview">
                        {{ loadFactorPreview }}
                      </div>
                    </div>
                  </v-col>
                </v-row>
              </div>

              <!-- 5. Справочные блоки -->
              <div v-else-if="activeSection === 5" class="section-content usd-section-content">
                <div class="section-title">Справочные блоки</div>
                <div class="section-hint">UI как в настройках проекта: список, reorder, enable, edit</div>

                <div class="d-flex align-center justify-space-between mb-3">
                      <div class="text-subtitle-2">Блоки</div>
                      <v-btn size="small" variant="flat" color="primary" @click="addTextBlock" :disabled="(form.text_blocks?.length || 0) >= 10">
                        Добавить блок
                      </v-btn>
                    </div>

                    <div v-if="form.text_blocks.length === 0" class="text-body-2 text-medium-emphasis">
                      Блоков пока нет.
                    </div>

                    <div v-else class="d-flex flex-column gap-3">
                      <v-card
                        v-for="(block, idx) in form.text_blocks"
                        :key="idx"
                        variant="outlined"
                        class="pa-3"
                      >
                        <div class="d-flex align-center justify-space-between mb-3">
                          <div class="d-flex align-center gap-2">
                            <div class="text-subtitle-2">Блок {{ idx + 1 }}</div>
                            <v-switch color="primary" v-model="block.enabled" hide-details density="compact" />
                          </div>
                          <div class="d-flex gap-1">
                            <v-btn icon size="x-small" variant="text" @click="moveTextBlockUp(idx)" :disabled="idx === 0">
                              <v-icon>mdi-arrow-up</v-icon>
                            </v-btn>
                            <v-btn icon size="x-small" variant="text" @click="moveTextBlockDown(idx)" :disabled="idx === (form.text_blocks.length - 1)">
                              <v-icon>mdi-arrow-down</v-icon>
                            </v-btn>
                            <v-btn icon size="x-small" variant="text" color="error" @click="removeTextBlock(idx)">
                              <v-icon>mdi-delete</v-icon>
                            </v-btn>
                          </div>
                        </div>

                        <v-row dense>
                          <v-col cols="12">
                            <v-text-field v-model="block.title" label="Заголовок" />
                          </v-col>
                          <v-col cols="12">
                            <RichTextEditor
                              v-model="block.text"
                              label="Текст"
                              placeholder="Введите текст справочного блока"
                              :disabled="block.enabled === false"
                            />
                          </v-col>
                        </v-row>
                      </v-card>
                    </div>
              </div>

              <!-- 7. Настройки отчетов -->
              <div v-else-if="activeSection === 7" class="section-content usd-section-content">
                <div class="d-flex align-start justify-space-between mb-4 usd-report-header">
                  <div>
                    <div class="section-title">Настройки отчетов</div>
                    <div class="section-hint">
                      Эти настройки применяются к новым проектам. Уже созданные проекты не изменяются автоматически.
                      Для существующего проекта используйте настройки самого проекта или загрузку значений по умолчанию.
                    </div>
                  </div>
                  <v-btn
                    variant="tonal"
                    color="secondary"
                    prepend-icon="mdi-restore"
                    @click="resetReportSettings"
                  >
                    Сбросить настройки отчетов
                  </v-btn>
                </div>

                <div class="usd-report-grid">
                  <v-card variant="outlined" class="usd-report-card">
                    <v-card-text>
                      <div class="usd-report-card-title">Общие подписи</div>
                      <v-row dense>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.common.project_label"
                            label="Название поля проекта"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.common.object_label"
                            label="Название поля объекта"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.common.executor_label"
                            label="Название поля исполнителя"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.common.signature_label"
                            label="Название поля подписи"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.common.date_label"
                            label="Название поля даты"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                      </v-row>
                    </v-card-text>
                  </v-card>

                  <v-card variant="outlined" class="usd-report-card">
                    <v-card-text>
                      <div class="usd-report-card-title">Смета и расчетная часть</div>
                      <v-row dense>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.estimate_report.appendix_label"
                            label="Надпись приложения"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.estimate_report.document_context_label"
                            label="Назначение приложения"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12">
                          <v-text-field
                            v-model="form.report_settings.estimate_report.title"
                            label="Название документа"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.estimate_report.calculation_date_label"
                            label="Название даты расчета"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.estimate_report.summary_title"
                            label="Заголовок сводных итогов"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.estimate_report.final_total_label"
                            label="Итоговая строка"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.estimate_report.amount_in_words_label"
                            label="Сумма прописью"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12">
                          <v-switch
                            v-model="form.report_settings.estimate_report.show_methodology_section"
                            color="primary"
                            label="Показывать методический раздел"
                            hide-details
                          />
                        </v-col>
                      </v-row>
                    </v-card-text>
                  </v-card>

                  <v-card variant="outlined" class="usd-report-card">
                    <v-card-text>
                      <div class="usd-report-card-title">Подтверждение цен</div>
                      <v-row dense>
                        <v-col cols="12">
                          <v-text-field
                            v-model="form.report_settings.price_evidence_report.title"
                            label="Название документа"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12">
                          <v-textarea
                            v-model="form.report_settings.price_evidence_report.subtitle"
                            label="Подзаголовок"
                            rows="3"
                            maxlength="2000"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.price_evidence_report.missing_evidence_section_title"
                            label="Раздел неподтвержденных позиций"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.price_evidence_report.internal_calculation_section_title"
                            label="Раздел внутренних расчетов"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.price_evidence_report.materials_evidence_section_title"
                            label="Раздел материалов и подтверждений"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-textarea
                            v-model="form.report_settings.price_evidence_report.internal_calculation_basis_text"
                            label="Текст основания внутренних расчетов"
                            rows="2"
                            maxlength="2000"
                            counter
                          />
                        </v-col>
                      </v-row>
                    </v-card-text>
                  </v-card>

                  <v-card variant="outlined" class="usd-report-card">
                    <v-card-text>
                      <div class="usd-report-card-title">Причины отсутствия доказательств</div>
                      <v-row dense>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.evidence_reasons.no_linked_evidence"
                            label="Нет связанного подтверждения"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.evidence_reasons.no_screenshot_or_document"
                            label="Нет скриншота или документа"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.evidence_reasons.outdated_price_confirmation"
                            label="Подтверждение устарело"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="form.report_settings.evidence_reasons.no_source_url"
                            label="Нет ссылки на источник"
                            maxlength="255"
                            counter
                          />
                        </v-col>
                      </v-row>
                    </v-card-text>
                  </v-card>
                </div>
              </div>

            </template>

          <template #footer>
            <SettingsShellFooter
              :is-dirty="isDirty"
              :saving="saving"
              @save="onSave"
              @cancel="onCancel"
            />
          </template>
        </SettingsShell>
      </div>
    </SectionCard>

    <v-snackbar v-model="snackbar.show" :timeout="snackbar.timeout" :color="snackbar.color" location="bottom right">
      {{ snackbar.message }}
    </v-snackbar>
  </PageContainer>
</template>

<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import api from '@/api/axios'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import RichTextEditor from '@/components/notifications/RichTextEditor.vue'
import SettingsShell from '@/components/settings/shell/SettingsShell.vue'
import SettingsShellFooter from '@/components/settings/shell/SettingsShellFooter.vue'

interface Region {
  id: number
  name: string
}

interface Material {
  id: number
  name: string
  type: 'plate' | 'edge'
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

interface ReportCommonSettings {
  project_label: string
  object_label: string
  executor_label: string
  signature_label: string
  date_label: string
}

interface ReportEstimateSettings {
  appendix_label: string
  document_context_label: string
  title: string
  calculation_date_label: string
  summary_title: string
  materials_summary_label: string
  operations_summary_label: string
  fittings_summary_label: string
  labor_summary_label: string
  expenses_summary_label: string
  final_total_label: string
  amount_in_words_label: string
  details_section_title: string
  plate_materials_section_title: string
  edge_materials_section_title: string
  final_cost_section_title: string
  show_methodology_section: boolean
}

interface ReportPriceEvidenceSettings {
  title: string
  subtitle: string
  project_label: string
  report_version_label: string
  report_created_at_label: string
  total_items_label: string
  confirmed_items_label: string
  missing_items_label: string
  fixation_period_label: string
  missing_evidence_section_title: string
  internal_calculation_section_title: string
  materials_evidence_section_title: string
  internal_calculation_basis_text: string
}

interface ReportEvidenceReasonSettings {
  no_linked_evidence: string
  no_screenshot_or_document: string
  outdated_price_confirmation: string
  no_source_url: string
  internal_calculation_no_screenshot_required: string
}

interface ReportSettings {
  common: ReportCommonSettings
  estimate_report: ReportEstimateSettings
  price_evidence_report: ReportPriceEvidenceSettings
  evidence_reasons: ReportEvidenceReasonSettings
}

interface UserSettings {
  region_id: number | null
  use_area_calc_mode: boolean

  waste_coefficient: number
  repair_coefficient: number

  default_plate_material_id: number | null
  default_edge_material_id: number | null
  facade_width_allowance_mm: number
  facade_height_allowance_mm: number

  waste_plate_coefficient: number | null
  waste_edge_coefficient: number | null
  waste_operations_coefficient: number | null

  apply_waste_to_plate: boolean
  apply_waste_to_edge: boolean
  apply_waste_to_operations: boolean

  waste_plate_description: CoefficientDescription | null
  waste_edge_description: CoefficientDescription | null
  waste_operations_description: CoefficientDescription | null

  show_waste_plate_description: boolean
  show_waste_edge_description: boolean
  show_waste_operations_description: boolean

  labor_employer_insurance_rate_percent: number
  labor_load_factor_calendar_hours: number
  labor_load_factor_productive_hours: number
  labor_planned_profitability_rate_percent: number
  labor_aggregation_strategy: 'auto' | 'median' | 'mean' | 'min' | 'max'
  labor_salary_range_strategy: 'avg' | 'min' | 'max'
  labor_rate_rounding_scale: number

  text_blocks: TextBlock[]
  report_settings: ReportSettings
}

const sections = [
  { id: 0, title: 'Регион и режим расчёта', icon: 'mdi-map-marker' },
  { id: 1, title: 'Общие коэффициенты', icon: 'mdi-tune' },
  { id: 2, title: 'Материалы по умолчанию', icon: 'mdi-package-variant' },
  { id: 6, title: 'Припуски фасадов', icon: 'mdi-ruler-square' },
  { id: 3, title: 'Отходы', icon: 'mdi-recycle' },
  { id: 4, title: 'Нормо-час', icon: 'mdi-calculator' },
  { id: 5, title: 'Справочные блоки', icon: 'mdi-text-box-outline' },
  { id: 7, title: 'Настройки отчетов', icon: 'mdi-file-document-edit-outline' },
]

const aggregationStrategyOptions = [
  { title: 'Авто', value: 'auto' },
  { title: 'Медиана', value: 'median' },
  { title: 'Среднее', value: 'mean' },
  { title: 'Минимум', value: 'min' },
  { title: 'Максимум', value: 'max' },
] as const

const salaryRangeStrategyOptions = [
  { title: 'Среднее', value: 'avg' },
  { title: 'Минимум', value: 'min' },
  { title: 'Максимум', value: 'max' },
] as const

const defaultReportSettings: ReportSettings = {
  common: {
    project_label: 'Проект (дело)',
    object_label: 'Объект',
    executor_label: 'Эксперт',
    signature_label: 'Подпись',
    date_label: 'Дата',
  },
  estimate_report: {
    appendix_label: 'Приложение № 1',
    document_context_label: 'к экспертному заключению',
    title: 'Расчёт стоимости материалов и работ',
    calculation_date_label: 'Дата расчёта',
    summary_title: 'СВОДНЫЕ ИТОГИ',
    materials_summary_label: 'Материалы (плиты + кромки)',
    operations_summary_label: 'Операции',
    fittings_summary_label: 'Фурнитура/комплектующие',
    labor_summary_label: 'Монтажно-демонтажные работы',
    expenses_summary_label: 'Накладные расходы',
    final_total_label: 'ИТОГО',
    amount_in_words_label: 'Прописью',
    details_section_title: 'Перечень деталей, принятых к расчёту',
    plate_materials_section_title: 'Расчёт плитных материалов',
    edge_materials_section_title: 'Расчёт кромочного материала',
    final_cost_section_title: 'Итоговая стоимость',
    show_methodology_section: true,
  },
  price_evidence_report: {
    title: 'Документ подтверждения цен',
    subtitle: 'Источники, скриншоты и файлы, подтверждающие стоимость позиций сметы.',
    project_label: 'Проект',
    report_version_label: 'Версия отчета',
    report_created_at_label: 'Дата формирования отчета',
    total_items_label: 'Всего позиций',
    confirmed_items_label: 'Подтверждено',
    missing_items_label: 'Без подтверждения',
    fixation_period_label: 'Период фиксации цен',
    missing_evidence_section_title: 'Позиции без подтверждения цены',
    internal_calculation_section_title: 'Позиции, рассчитанные внутренним способом',
    materials_evidence_section_title: 'Материалы и ценовые подтверждения',
    internal_calculation_basis_text: 'внутренний расчет; скриншот не требуется',
  },
  evidence_reasons: {
    no_linked_evidence: 'нет связанного подтверждения цены',
    no_screenshot_or_document: 'нет скриншота или документа',
    outdated_price_confirmation: 'подтверждение цены устарело',
    no_source_url: 'нет ссылки на источник цены',
    internal_calculation_no_screenshot_required: 'внутренний расчет; скриншот не требуется',
  },
}

const cloneReportSettings = (settings: ReportSettings = defaultReportSettings): ReportSettings => {
  return JSON.parse(JSON.stringify(settings)) as ReportSettings
}

const trimOrDefault = (value: unknown, fallback: string, maxLength = 255): string => {
  const trimmed = String(value ?? '').trim()
  return (trimmed || fallback).slice(0, maxLength)
}

const normalizeReportSettings = (value: any = {}): ReportSettings => {
  const source = value && typeof value === 'object' ? value : {}
  const defaults = defaultReportSettings

  return {
    common: {
      project_label: trimOrDefault(source.common?.project_label, defaults.common.project_label),
      object_label: trimOrDefault(source.common?.object_label, defaults.common.object_label),
      executor_label: trimOrDefault(source.common?.executor_label, defaults.common.executor_label),
      signature_label: trimOrDefault(source.common?.signature_label, defaults.common.signature_label),
      date_label: trimOrDefault(source.common?.date_label, defaults.common.date_label),
    },
    estimate_report: {
      appendix_label: trimOrDefault(source.estimate_report?.appendix_label, defaults.estimate_report.appendix_label),
      document_context_label: trimOrDefault(source.estimate_report?.document_context_label, defaults.estimate_report.document_context_label),
      title: trimOrDefault(source.estimate_report?.title, defaults.estimate_report.title),
      calculation_date_label: trimOrDefault(source.estimate_report?.calculation_date_label, defaults.estimate_report.calculation_date_label),
      summary_title: trimOrDefault(source.estimate_report?.summary_title, defaults.estimate_report.summary_title),
      materials_summary_label: trimOrDefault(source.estimate_report?.materials_summary_label, defaults.estimate_report.materials_summary_label),
      operations_summary_label: trimOrDefault(source.estimate_report?.operations_summary_label, defaults.estimate_report.operations_summary_label),
      fittings_summary_label: trimOrDefault(source.estimate_report?.fittings_summary_label, defaults.estimate_report.fittings_summary_label),
      labor_summary_label: trimOrDefault(source.estimate_report?.labor_summary_label, defaults.estimate_report.labor_summary_label),
      expenses_summary_label: trimOrDefault(source.estimate_report?.expenses_summary_label, defaults.estimate_report.expenses_summary_label),
      final_total_label: trimOrDefault(source.estimate_report?.final_total_label, defaults.estimate_report.final_total_label),
      amount_in_words_label: trimOrDefault(source.estimate_report?.amount_in_words_label, defaults.estimate_report.amount_in_words_label),
      details_section_title: trimOrDefault(source.estimate_report?.details_section_title, defaults.estimate_report.details_section_title),
      plate_materials_section_title: trimOrDefault(source.estimate_report?.plate_materials_section_title, defaults.estimate_report.plate_materials_section_title),
      edge_materials_section_title: trimOrDefault(source.estimate_report?.edge_materials_section_title, defaults.estimate_report.edge_materials_section_title),
      final_cost_section_title: trimOrDefault(source.estimate_report?.final_cost_section_title, defaults.estimate_report.final_cost_section_title),
      show_methodology_section: source.estimate_report?.show_methodology_section !== false,
    },
    price_evidence_report: {
      title: trimOrDefault(source.price_evidence_report?.title, defaults.price_evidence_report.title),
      subtitle: trimOrDefault(source.price_evidence_report?.subtitle, defaults.price_evidence_report.subtitle, 2000),
      project_label: trimOrDefault(source.price_evidence_report?.project_label, defaults.price_evidence_report.project_label),
      report_version_label: trimOrDefault(source.price_evidence_report?.report_version_label, defaults.price_evidence_report.report_version_label),
      report_created_at_label: trimOrDefault(source.price_evidence_report?.report_created_at_label, defaults.price_evidence_report.report_created_at_label),
      total_items_label: trimOrDefault(source.price_evidence_report?.total_items_label, defaults.price_evidence_report.total_items_label),
      confirmed_items_label: trimOrDefault(source.price_evidence_report?.confirmed_items_label, defaults.price_evidence_report.confirmed_items_label),
      missing_items_label: trimOrDefault(source.price_evidence_report?.missing_items_label, defaults.price_evidence_report.missing_items_label),
      fixation_period_label: trimOrDefault(source.price_evidence_report?.fixation_period_label, defaults.price_evidence_report.fixation_period_label),
      missing_evidence_section_title: trimOrDefault(source.price_evidence_report?.missing_evidence_section_title, defaults.price_evidence_report.missing_evidence_section_title),
      internal_calculation_section_title: trimOrDefault(source.price_evidence_report?.internal_calculation_section_title, defaults.price_evidence_report.internal_calculation_section_title),
      materials_evidence_section_title: trimOrDefault(source.price_evidence_report?.materials_evidence_section_title, defaults.price_evidence_report.materials_evidence_section_title),
      internal_calculation_basis_text: trimOrDefault(source.price_evidence_report?.internal_calculation_basis_text, defaults.price_evidence_report.internal_calculation_basis_text, 2000),
    },
    evidence_reasons: {
      no_linked_evidence: trimOrDefault(source.evidence_reasons?.no_linked_evidence, defaults.evidence_reasons.no_linked_evidence),
      no_screenshot_or_document: trimOrDefault(source.evidence_reasons?.no_screenshot_or_document, defaults.evidence_reasons.no_screenshot_or_document),
      outdated_price_confirmation: trimOrDefault(source.evidence_reasons?.outdated_price_confirmation, defaults.evidence_reasons.outdated_price_confirmation),
      no_source_url: trimOrDefault(source.evidence_reasons?.no_source_url, defaults.evidence_reasons.no_source_url),
      internal_calculation_no_screenshot_required: trimOrDefault(
        source.evidence_reasons?.internal_calculation_no_screenshot_required,
        defaults.evidence_reasons.internal_calculation_no_screenshot_required,
      ),
    },
  }
}

const activeSection = ref(0)
const loading = ref(true)
const saving = ref(false)

const regions = ref<Region[]>([])
const materials = ref<Material[]>([])

const showPlateDescDialog = ref(false)
const showEdgeDescDialog = ref(false)
const showOpsDescDialog = ref(false)

const form = ref<UserSettings>({
  region_id: null,
  use_area_calc_mode: false,
  waste_coefficient: 1.0,
  repair_coefficient: 1.0,
  default_plate_material_id: null,
  default_edge_material_id: null,
  facade_width_allowance_mm: 0,
  facade_height_allowance_mm: 0,
  waste_plate_coefficient: 1.0,
  waste_edge_coefficient: 1.0,
  waste_operations_coefficient: 1.0,
  apply_waste_to_plate: true,
  apply_waste_to_edge: true,
  apply_waste_to_operations: false,
  waste_plate_description: null,
  waste_edge_description: null,
  waste_operations_description: null,
  show_waste_plate_description: false,
  show_waste_edge_description: false,
  show_waste_operations_description: false,
  labor_employer_insurance_rate_percent: 30,
  labor_load_factor_calendar_hours: 160,
  labor_load_factor_productive_hours: 120,
  labor_planned_profitability_rate_percent: 15,
  labor_aggregation_strategy: 'auto',
  labor_salary_range_strategy: 'avg',
  labor_rate_rounding_scale: 2,
  text_blocks: [] as TextBlock[],
  report_settings: cloneReportSettings(),
})

const original = ref<string>('')

const snackbar = ref({
  show: false,
  message: '',
  color: 'info',
  timeout: 3000
})

const fieldErrors = ref<Record<string, string[]>>({})

const showNotification = (message: string, color: string = 'info', timeout: number = 3000) => {
  snackbar.value = { show: true, message, color, timeout }
}

const plateMaterials = computed(() => materials.value.filter(m => m.type === 'plate'))
const edgeMaterials = computed(() => materials.value.filter(m => m.type === 'edge'))

const normalizeDesc = (value: any): CoefficientDescription => {
  if (value && typeof value === 'object') {
    return {
      title: String(value.title ?? ''),
      text: String(value.text ?? '')
    }
  }
  return { title: '', text: '' }
}

const plateDesc = ref<CoefficientDescription>({ title: '', text: '' })
const edgeDesc = ref<CoefficientDescription>({ title: '', text: '' })
const opsDesc = ref<CoefficientDescription>({ title: '', text: '' })

watch(() => form.value.waste_plate_description, (v) => { plateDesc.value = normalizeDesc(v) }, { immediate: true })
watch(() => form.value.waste_edge_description, (v) => { edgeDesc.value = normalizeDesc(v) }, { immediate: true })
watch(() => form.value.waste_operations_description, (v) => { opsDesc.value = normalizeDesc(v) }, { immediate: true })

const serializeForDirty = (): string => {
  // Сериализуем текущее состояние, включая поля описаний (которые редактируются в отдельных ref)
  const snapshot = {
    ...form.value,
    waste_plate_description: plateDesc.value,
    waste_edge_description: edgeDesc.value,
    waste_operations_description: opsDesc.value,
    text_blocks: form.value.text_blocks ?? []
  }
  return JSON.stringify(snapshot)
}

const isDirty = computed(() => {
  return !loading.value && original.value !== '' && serializeForDirty() !== original.value
})

const loadFactorPreview = computed(() => {
  const calendar = Number(form.value.labor_load_factor_calendar_hours)
  const productive = Number(form.value.labor_load_factor_productive_hours)

  if (!Number.isFinite(calendar) || !Number.isFinite(productive) || calendar <= 0 || productive <= 0) {
    return 'Введите положительные значения часов, чтобы увидеть коэффициент.'
  }

  const factor = calendar / productive
  const factorLabel = new Intl.NumberFormat('ru-RU', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(factor)

  return `${calendar} / ${productive} = ${factorLabel}`
})

const percentToFraction = (value: number | null | undefined): number => {
  const numeric = Number(value)
  if (!Number.isFinite(numeric)) return 0
  return numeric / 100
}

const fractionToPercent = (value: number | null | undefined): number => {
  const numeric = Number(value)
  if (!Number.isFinite(numeric)) return 0
  return numeric * 100
}

const applySettingsToForm = (settingsRes: Record<string, any> = {}) => {
  const {
    text_blocks,
    report_settings,
    labor_employer_insurance_rate,
    labor_planned_profitability_rate,
    labor_load_factor_calendar_hours,
    labor_load_factor_productive_hours,
    labor_aggregation_strategy,
    labor_salary_range_strategy,
    labor_rate_rounding_scale,
    ...otherSettings
  } = settingsRes || {}

  form.value = {
    ...form.value,
    ...otherSettings,
    labor_employer_insurance_rate_percent: fractionToPercent(labor_employer_insurance_rate),
    labor_planned_profitability_rate_percent: fractionToPercent(labor_planned_profitability_rate),
    labor_load_factor_calendar_hours: Number(labor_load_factor_calendar_hours ?? form.value.labor_load_factor_calendar_hours),
    labor_load_factor_productive_hours: Number(labor_load_factor_productive_hours ?? form.value.labor_load_factor_productive_hours),
    labor_aggregation_strategy: labor_aggregation_strategy ?? form.value.labor_aggregation_strategy,
    labor_salary_range_strategy: labor_salary_range_strategy ?? form.value.labor_salary_range_strategy,
    labor_rate_rounding_scale: Number(labor_rate_rounding_scale ?? form.value.labor_rate_rounding_scale),
    text_blocks: text_blocks ?? [],
    report_settings: normalizeReportSettings(report_settings),
  }
}

const validateLaborSettings = (): boolean => {
  const errors: Record<string, string[]> = {}

  const pushError = (key: string, message: string) => {
    errors[key] ??= []
    errors[key].push(message)
  }

  const insurance = Number(form.value.labor_employer_insurance_rate_percent)
  if (!Number.isFinite(insurance) || insurance < 0 || insurance > 100) {
    pushError('labor_employer_insurance_rate', 'Введите значение от 0 до 100%.')
  }

  const profitability = Number(form.value.labor_planned_profitability_rate_percent)
  if (!Number.isFinite(profitability) || profitability < 0 || profitability > 100) {
    pushError('labor_planned_profitability_rate', 'Введите значение от 0 до 100%.')
  }

  const calendar = Number(form.value.labor_load_factor_calendar_hours)
  if (!Number.isFinite(calendar) || calendar <= 0) {
    pushError('labor_load_factor_calendar_hours', 'Введите положительное число часов.')
  }

  const productive = Number(form.value.labor_load_factor_productive_hours)
  if (!Number.isFinite(productive) || productive <= 0) {
    pushError('labor_load_factor_productive_hours', 'Введите положительное число часов.')
  }

  const rounding = Number(form.value.labor_rate_rounding_scale)
  if (!Number.isFinite(rounding) || rounding < 0 || rounding > 6) {
    pushError('labor_rate_rounding_scale', 'Допустимые значения: от 0 до 6.')
  }

  if (!aggregationStrategyOptions.some(option => option.value === form.value.labor_aggregation_strategy)) {
    pushError('labor_aggregation_strategy', 'Выберите корректную стратегию агрегации.')
  }

  if (!salaryRangeStrategyOptions.some(option => option.value === form.value.labor_salary_range_strategy)) {
    pushError('labor_salary_range_strategy', 'Выберите корректную стратегию выбора ставки.')
  }

  fieldErrors.value = errors
  return Object.keys(errors).length === 0
}

const validateFacadeAllowanceSettings = (): boolean => {
  const errors: Record<string, string[]> = { ...fieldErrors.value }

  const validateAllowance = (key: 'facade_width_allowance_mm' | 'facade_height_allowance_mm', label: string) => {
    const value = Number(form.value[key])
    if (!Number.isInteger(value) || value < 0 || value > 1000) {
      errors[key] = [`${label}: введите целое значение от 0 до 1000 мм.`]
      return
    }

    delete errors[key]
  }

  validateAllowance('facade_width_allowance_mm', 'Припуск по ширине')
  validateAllowance('facade_height_allowance_mm', 'Припуск по высоте')

  fieldErrors.value = errors
  return !errors.facade_width_allowance_mm && !errors.facade_height_allowance_mm
}

const buildPayload = (): Record<string, any> => {
  const descOrNull = (d: CoefficientDescription): CoefficientDescription | null => {
    const title = (d.title || '').trim()
    const text = (d.text || '').trim()
    return title || text ? { title, text } : null
  }

  const {
    labor_employer_insurance_rate_percent,
    labor_planned_profitability_rate_percent,
    ...otherForm
  } = form.value

  return {
    ...otherForm,
    facade_width_allowance_mm: Number(form.value.facade_width_allowance_mm) || 0,
    facade_height_allowance_mm: Number(form.value.facade_height_allowance_mm) || 0,
    labor_employer_insurance_rate: percentToFraction(labor_employer_insurance_rate_percent),
    labor_planned_profitability_rate: percentToFraction(labor_planned_profitability_rate_percent),
    waste_plate_description: descOrNull(plateDesc.value),
    waste_edge_description: descOrNull(edgeDesc.value),
    waste_operations_description: descOrNull(opsDesc.value),
    text_blocks: form.value.text_blocks && form.value.text_blocks.length > 0 ? form.value.text_blocks : [],
    report_settings: normalizeReportSettings(form.value.report_settings),
  }
}

const loadAll = async () => {
  loading.value = true
  try {
    const [materialsRes, regionsRes, settingsRes] = await Promise.all([
      api.get('/api/materials').then(r => r.data),
      api.get('/api/regions').then(r => r.data?.data || []),
      api.get('/api/user/settings').then(r => r.data)
    ])

    materials.value = materialsRes || []
    regions.value = regionsRes || []

    applySettingsToForm(settingsRes)
    fieldErrors.value = {}

    plateDesc.value = normalizeDesc(settingsRes?.waste_plate_description)
    edgeDesc.value = normalizeDesc(settingsRes?.waste_edge_description)
    opsDesc.value = normalizeDesc(settingsRes?.waste_operations_description)

    original.value = serializeForDirty()
  } catch (e: any) {
    console.error('Failed to load user settings:', e)
    showNotification(e.response?.data?.message || e.message || 'Не удалось загрузить настройки', 'error')
  } finally {
    loading.value = false
  }
}

const onSave = async () => {
  if (saving.value) return
  if (!validateLaborSettings()) {
    showNotification('Проверьте значения в настройках расчёта нормо-часа', 'error')
    return
  }
  if (!validateFacadeAllowanceSettings()) {
    showNotification('Проверьте значения припусков фасадов', 'error')
    return
  }

  saving.value = true
  try {
    fieldErrors.value = {}
    const payload = buildPayload()
    const { data } = await api.put('/api/user/settings', payload)

    applySettingsToForm(data)
    plateDesc.value = normalizeDesc(data?.waste_plate_description)
    edgeDesc.value = normalizeDesc(data?.waste_edge_description)
    opsDesc.value = normalizeDesc(data?.waste_operations_description)

    original.value = serializeForDirty()
    showNotification('Настройки сохранены', 'success')
  } catch (e: any) {
    console.error('Failed to save user settings:', e)
    fieldErrors.value = e.response?.data?.errors ?? {}
    showNotification(e.response?.data?.message || e.message || 'Ошибка сохранения', 'error')
  } finally {
    saving.value = false
  }
}

const onCancel = async () => {
  if (!isDirty.value) return
  const ok = window.confirm('Отменить несохранённые изменения?')
  if (!ok) return
  await loadAll()
  showNotification('Изменения отменены', 'info')
}

const resetReportSettings = () => {
  const ok = window.confirm('Сбросить только настройки отчетов к значениям по умолчанию?')
  if (!ok) return

  form.value.report_settings = cloneReportSettings()
  showNotification('Настройки отчетов сброшены. Нажмите «Сохранить», чтобы применить изменения.', 'info', 5000)
}

// Text blocks (UI как в проекте)
const ensureTextBlocks = () => {
  if (!form.value.text_blocks) {
    form.value.text_blocks = []
  }
}

const addTextBlock = () => {
  ensureTextBlocks()
  if (form.value.text_blocks.length >= 10) return
  form.value.text_blocks.push({ title: '', text: '', enabled: true })
}

const removeTextBlock = (index: number) => {
  ensureTextBlocks()
  form.value.text_blocks.splice(index, 1)
}

const moveTextBlockUp = (index: number) => {
  if (index <= 0 || !form.value.text_blocks || form.value.text_blocks.length <= index) return
  const blocks = form.value.text_blocks
  const item = blocks[index]
  if (!item) return
  // Remove from current position and insert at previous position
  blocks.splice(index, 1)
  blocks.splice(index - 1, 0, item)
}

const moveTextBlockDown = (index: number) => {
  if (index < 0 || !form.value.text_blocks || index >= form.value.text_blocks.length - 1) return
  const blocks = form.value.text_blocks
  const item = blocks[index]
  if (!item) return
  // Remove from current position and insert at next position
  blocks.splice(index, 1)
  blocks.splice(index + 1, 0, item)
}

// Confirm on leave (dirty detection)
onBeforeRouteLeave((_to, _from, next) => {
  if (!isDirty.value) return next()
  const ok = window.confirm('Есть несохранённые изменения. Уйти со страницы?')
  return ok ? next() : next(false)
})

const beforeUnloadHandler = (event: BeforeUnloadEvent) => {
  if (!isDirty.value) return
  event.preventDefault()
  event.returnValue = ''
}

// ── Email verification redirect handling ──────────────────────────────────

const route = useRoute()
const router = useRouter()

function handleEmailVerifiedRedirect() {
  const param = route.query.email_verified as string | undefined
  if (!param) return

  // Remove param from URL immediately (one-time handling)
  router.replace({ query: { ...route.query, email_verified: undefined } })

  if (param === 'success') {
    showNotification('Почта успешно подтверждена! Теперь доступен сброс пароля через email.', 'success', 7000)
  } else if (param === 'already') {
    showNotification('Почта уже была подтверждена ранее.', 'info', 5000)
  }
}

onMounted(async () => {
  window.addEventListener('beforeunload', beforeUnloadHandler)
  await loadAll()
  handleEmailVerifiedRedirect()
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', beforeUnloadHandler)
})
</script>

<style scoped>
.user-settings-page {
  max-width: 1400px;
}

.settings-shell {
  overflow: hidden;
}

.usd-body {
  height: 70vh;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.usd-section-content {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.section-title {
  font-size: 1.02rem;
  font-weight: 700;
  margin-bottom: 6px;
  letter-spacing: -0.01em;
}

.section-hint {
  font-size: 0.875rem;
  line-height: 1.55;
  opacity: 0.82;
  margin-bottom: 14px;
}

.gap-1 { gap: 4px; }
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }

.settings-shell :deep(.v-card-text) {
  padding-top: 10px !important;
}

.settings-shell :deep(.ss-content) {
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.03), transparent 140px),
    transparent;
}

.settings-shell :deep(.labor-helper-block) {
  padding: 16px 18px;
  border-radius: var(--md-sys-shape-corner-large);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  background: rgba(var(--v-theme-surface-container-low), 0.82);
}

.settings-shell :deep(.labor-preview) {
  margin-top: 8px;
  font-weight: 700;
  color: rgb(var(--v-theme-primary));
}

.usd-report-header {
  gap: 16px;
}

.usd-report-grid {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.usd-report-card {
  border-color: rgba(var(--v-theme-outline-variant), 0.82);
  background: rgba(var(--v-theme-surface-container-lowest), 0.72);
}

.usd-report-card-title {
  font-size: 0.95rem;
  font-weight: 700;
  margin-bottom: 14px;
}

.usd-waste-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.usd-waste-row {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  padding: 14px 16px;
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-16);
  background: rgba(var(--v-theme-surface-container-lowest), 0.8);
}

.usd-waste-main {
  display: flex;
  align-items: center;
  gap: 14px;
  flex: 1 1 240px;
  min-width: 220px;
}

.usd-waste-controls {
  display: flex;
  align-items: center;
  gap: 12px 16px;
  flex-wrap: wrap;
  flex: 1 1 320px;
}

.usd-waste-label {
  min-width: 92px;
}

.usd-waste-field {
  max-width: 110px;
  flex: 0 0 110px;
}

.usd-waste-toggle,
.usd-waste-action {
  flex-shrink: 0;
}

.usd-waste-action {
  margin-left: auto;
}

.usd-dialog-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.usd-dialog-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}

@media (max-width: 960px) {
  .usd-report-header {
    align-items: stretch !important;
    flex-direction: column;
  }

  .usd-report-header :deep(.v-btn) {
    align-self: flex-start;
  }

  .usd-waste-row {
    align-items: stretch;
  }

  .usd-waste-main,
  .usd-waste-controls {
    flex: 1 1 100%;
    min-width: 0;
  }

  .usd-waste-main {
    align-items: flex-start;
    flex-direction: column;
  }

  .usd-waste-field {
    max-width: 100%;
    flex: 1 1 140px;
  }

  .usd-waste-action {
    margin-left: 0;
  }
}

@media (max-width: 960px) {
  .usd-body {
    height: auto;
    min-height: 70vh;
  }
}

@media (max-width: 700px) {
  .user-settings-page {
    max-width: 100%;
  }

  .usd-section-content {
    gap: var(--ds-space-14);
  }

  .section-title {
    font-size: 1rem;
    line-height: 1.35;
  }

  .usd-waste-row,
  .usd-waste-main,
  .usd-waste-controls {
    align-items: stretch;
    flex-direction: column;
  }

  .usd-waste-main,
  .usd-waste-controls {
    width: 100%;
  }

  .usd-waste-field {
    max-width: none;
    flex: 1 1 auto;
  }

  .settings-shell :deep(.ssf) {
    padding-right: 88px;
  }
}

@media (max-width: 760px) {
  .usd-dialog-actions {
    flex-wrap: wrap;
  }

  .usd-dialog-actions > :deep(.v-btn) {
    flex: 1 1 calc(50% - 4px);
  }
}
</style>

