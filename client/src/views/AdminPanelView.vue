<template>
  <v-container fluid>
    <v-row align="center" class="mb-6">
      <v-col cols="12" md="8">
        <div class="text-h5 font-weight-medium">Админ‑панель</div>
        <div class="text-body-2 text-medium-emphasis">
          Настройки приложения, статистика и управление пользователями.
        </div>
      </v-col>
    </v-row>

    <v-tabs v-model="activeTab" color="primary" class="mb-6" align-tabs="start">
      <v-tab value="llm" prepend-icon="mdi-robot">LLM Провайдеры</v-tab>
      <v-tab value="llm-prompts" prepend-icon="mdi-text-box-edit">Промпты</v-tab>
      <v-tab value="llm-stats" prepend-icon="mdi-chart-line">LLM Статистика</v-tab>
      <v-tab value="settings" prepend-icon="mdi-cog">Настройки</v-tab>
      <v-tab value="users" prepend-icon="mdi-account-group">Пользователи</v-tab>
      <v-tab value="notifications" prepend-icon="mdi-bell-outline">Уведомления</v-tab>
    </v-tabs>

    <v-window v-model="activeTab">
      <!-- LLM Settings Tab -->
      <v-window-item value="llm">
        <v-row>
          <v-col cols="12">
            <v-card :loading="llmLoading">
              <v-card-title class="d-flex align-center">
                <v-icon class="mr-2">mdi-robot</v-icon>
                Настройки LLM провайдеров
                <v-spacer />
                <v-btn
                  color="primary"
                  variant="tonal"
                  size="small"
                  :loading="llmTesting"
                  @click="testProviders"
                >
                  <v-icon start>mdi-connection</v-icon>
                  Тест подключения
                </v-btn>
              </v-card-title>

              <v-card-text>
                <!-- Mode Toggle -->
                <v-row class="mb-4">
                  <v-col cols="12" md="6">
                    <v-select
                      v-model="llmSettings.mode"
                      :items="modeOptions"
                      label="Режим работы"
                      variant="outlined"
                      density="comfortable"
                      hint="Auto = автоматический failover при ошибках"
                      persistent-hint
                    />
                  </v-col>
                  <v-col cols="12" md="6">
                    <v-select
                      v-model="llmSettings.primary_provider"
                      :items="providerOptions"
                      label="Primary провайдер"
                      variant="outlined"
                      density="comfortable"
                    />
                  </v-col>
                </v-row>

                <!-- Fallback Providers -->
                <v-row class="mb-4">
                  <v-col cols="12" md="6">
                    <v-select
                      v-model="llmSettings.fallback_providers"
                      :items="fallbackProviderOptions"
                      label="Fallback провайдеры (по приоритету)"
                      variant="outlined"
                      density="comfortable"
                      multiple
                      chips
                      closable-chips
                      hint="Будут использованы при недоступности primary"
                      persistent-hint
                    />
                  </v-col>
                </v-row>

                <v-divider class="my-4" />

                <!-- Provider Settings -->
                <div class="text-subtitle-1 font-weight-medium mb-3">Настройки провайдеров</div>

                <v-expansion-panels variant="accordion">
                  <!-- Динамическая отрисовка провайдеров -->
                  <v-expansion-panel v-for="provider in availableProviders" :key="provider.value">
                    <v-expansion-panel-title>
                      <v-icon class="mr-2">{{ provider.icon }}</v-icon>
                      {{ provider.title }}
                      <v-chip
                        v-if="llmSettings.providers?.[provider.value]?.api_key_set"
                        color="success"
                        size="x-small"
                        class="ml-2"
                      >
                        Настроен
                      </v-chip>
                      <v-chip
                        v-else-if="llmSettings.providers?.[provider.value]?.is_env_fallback"
                        color="warning"
                        size="x-small"
                        class="ml-2"
                      >
                        ENV
                      </v-chip>
                      <v-chip v-else color="error" size="x-small" class="ml-2">
                        Не настроен
                      </v-chip>
                      <v-spacer />
                      <a
                        v-if="provider.docs_url"
                        :href="provider.docs_url"
                        target="_blank"
                        class="text-caption mr-2"
                        @click.stop
                      >
                        Документация
                      </a>
                    </v-expansion-panel-title>
                    <v-expansion-panel-text>
                      <div class="text-body-2 text-medium-emphasis mb-3">
                        {{ provider.description }}
                      </div>
                      <v-row v-if="providerForms[provider.value]">
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="providerForms[provider.value]!.api_key"
                            label="API Key"
                            variant="outlined"
                            density="comfortable"
                            type="password"
                            :placeholder="llmSettings.providers?.[provider.value]?.api_key_masked || 'Введите ключ'"
                            hint="Оставьте пустым, чтобы сохранить текущий"
                            persistent-hint
                          />
                        </v-col>
                        <v-col cols="12" md="6">
                          <v-text-field
                            v-model="providerForms[provider.value]!.model"
                            label="Модель"
                            variant="outlined"
                            density="comfortable"
                            :placeholder="llmSettings.providers?.[provider.value]?.model"
                          />
                        </v-col>
                        <v-col cols="12">
                          <v-text-field
                            v-model="providerForms[provider.value]!.base_url"
                            label="Base URL"
                            variant="outlined"
                            density="comfortable"
                            :placeholder="llmSettings.providers?.[provider.value]?.base_url"
                          />
                        </v-col>
                      </v-row>

                      <!-- Circuit Breaker Status -->
                      <v-alert
                        v-if="llmSettings.circuit_breaker?.[provider.value]?.status === 'down'"
                        type="error"
                        variant="tonal"
                        class="mt-3"
                      >
                        <div class="d-flex align-center justify-space-between">
                          <div>
                            <strong>Circuit Breaker OPEN</strong><br />
                            Провайдер временно отключен до {{ llmSettings.circuit_breaker?.[provider.value]?.down_until }}
                          </div>
                          <v-btn
                            color="error"
                            variant="outlined"
                            size="small"
                            @click="resetCircuit(provider.value)"
                          >
                            Сбросить
                          </v-btn>
                        </div>
                      </v-alert>
                    </v-expansion-panel-text>
                  </v-expansion-panel>
                </v-expansion-panels>

                <!-- Test Results -->
                <v-alert
                  v-if="testResults"
                  :type="testResultsType"
                  variant="tonal"
                  class="mt-4"
                  closable
                  @click:close="testResults = null"
                >
                  <div class="text-subtitle-2 mb-2">Результаты теста ({{ testResults.tested_at }})</div>
                  <div v-for="(result, name) in testResults.results" :key="name" class="mb-1">
                    <v-icon :color="result.available ? 'success' : 'error'" size="small">
                      {{ result.available ? 'mdi-check-circle' : 'mdi-alert-circle' }}
                    </v-icon>
                    <strong>{{ name }}</strong>:
                    {{ result.available ? `OK (${result.latency_ms}ms)` : result.error }}
                  </div>
                </v-alert>
              </v-card-text>

              <v-card-actions>
                <v-spacer />
                <v-btn color="primary" variant="elevated" :loading="llmSaving" @click="saveLLMSettings">
                  <v-icon start>mdi-content-save</v-icon>
                  Сохранить
                </v-btn>
              </v-card-actions>
            </v-card>
          </v-col>
        </v-row>
      </v-window-item>

      <!-- LLM Prompts Tab -->
      <v-window-item value="llm-prompts">
        <v-row>
          <v-col cols="12">
            <v-card :loading="promptsLoading">
              <v-card-title class="d-flex align-center">
                <v-icon class="mr-2">mdi-text-box-edit</v-icon>
                Настройка промптов для LLM
                <v-spacer />
                <v-btn
                  color="warning"
                  variant="tonal"
                  size="small"
                  :loading="promptsResetting"
                  @click="resetPrompts"
                >
                  <v-icon start>mdi-restore</v-icon>
                  Сбросить по умолчанию
                </v-btn>
              </v-card-title>

              <v-card-text>
                <!-- System Prompt -->
                <div class="text-subtitle-1 font-weight-medium mb-2">
                  Системный промпт
                </div>
                <div class="text-body-2 text-medium-emphasis mb-2">
                  Задаёт роль и поведение модели. Этот промпт отправляется в начале каждого запроса.
                </div>
                <v-textarea
                  v-model="promptsForm.system_prompt"
                  variant="outlined"
                  rows="8"
                  placeholder="Вы - эксперт по декомпозиции работ..."
                  auto-grow
                  counter
                  maxlength="10000"
                />

                <v-divider class="my-6" />

                <!-- User Prompt Template -->
                <div class="text-subtitle-1 font-weight-medium mb-2">
                  Шаблон пользовательского промпта
                </div>
                <div class="text-body-2 text-medium-emphasis mb-2">
                  Шаблон запроса пользователя. Используйте переменные для динамических данных.
                </div>

                <!-- Available Variables -->
                <v-alert type="info" variant="tonal" class="mb-3" density="compact">
                  <div class="text-subtitle-2 mb-1">Доступные переменные:</div>
                  <div class="d-flex flex-wrap ga-2">
                    <v-chip
                      v-for="(desc, variable) in availableVariables"
                      :key="variable"
                      size="small"
                      color="info"
                      variant="outlined"
                      @click="insertVariable(variable)"
                    >
                      <code>{{ variable }}</code>
                      <v-tooltip activator="parent" location="top">{{ desc }}</v-tooltip>
                    </v-chip>
                  </div>
                </v-alert>

                <v-textarea
                  v-model="promptsForm.user_prompt_template"
                  variant="outlined"
                  rows="6"
                  placeholder="Разбей работу {title} на подзадачи..."
                  auto-grow
                  counter
                  maxlength="5000"
                />

                <v-divider class="my-6" />

                <!-- Preview Section -->
                <div class="text-subtitle-1 font-weight-medium mb-2">
                  <v-icon class="mr-1">mdi-eye</v-icon>
                  Предпросмотр
                </div>
                <div class="text-body-2 text-medium-emphasis mb-3">
                  Посмотрите, как будет выглядеть промпт с примерными данными.
                </div>

                <v-row class="mb-3">
                  <v-col cols="12" md="4">
                    <v-text-field
                      v-model="previewData.title"
                      label="Пример: название работы"
                      variant="outlined"
                      density="compact"
                    />
                  </v-col>
                  <v-col cols="12" md="4">
                    <v-text-field
                      v-model="previewData.context"
                      label="Пример: контекст"
                      variant="outlined"
                      density="compact"
                    />
                  </v-col>
                  <v-col cols="12" md="2">
                    <v-text-field
                      v-model.number="previewData.desired_hours"
                      label="Часов"
                      variant="outlined"
                      density="compact"
                      type="number"
                      min="0.1"
                      max="1000"
                    />
                  </v-col>
                  <v-col cols="12" md="2" class="d-flex align-center">
                    <v-btn
                      color="secondary"
                      variant="tonal"
                      :loading="previewLoading"
                      @click="loadPreview"
                      block
                    >
                      <v-icon start>mdi-eye-refresh</v-icon>
                      Показать
                    </v-btn>
                  </v-col>
                </v-row>

                <v-expand-transition>
                  <div v-if="promptPreview">
                    <v-card variant="outlined" color="grey-lighten-4" class="pa-3">
                      <div class="text-caption text-medium-emphasis mb-1">
                        Результат рендеринга шаблона:
                      </div>
                      <pre class="preview-text">{{ promptPreview }}</pre>
                    </v-card>
                  </div>
                </v-expand-transition>

                <!-- Success/Error Alert -->
                <v-alert
                  v-if="promptsMessage"
                  :type="promptsMessageType"
                  variant="tonal"
                  class="mt-4"
                  closable
                  @click:close="promptsMessage = ''"
                >
                  {{ promptsMessage }}
                </v-alert>
              </v-card-text>

              <v-card-actions>
                <v-spacer />
                <v-btn
                  color="primary"
                  variant="elevated"
                  :loading="promptsSaving"
                  @click="savePrompts"
                >
                  <v-icon start>mdi-content-save</v-icon>
                  Сохранить промпты
                </v-btn>
              </v-card-actions>
            </v-card>
          </v-col>
        </v-row>
      </v-window-item>

      <!-- LLM Statistics Tab -->
      <v-window-item value="llm-stats">
        <v-row class="mb-4">
          <v-col cols="12" class="d-flex align-center">
            <v-btn-toggle v-model="statsPeriod" mandatory color="primary" density="comfortable">
              <v-btn value="24h">24 часа</v-btn>
              <v-btn value="7d">7 дней</v-btn>
              <v-btn value="30d">30 дней</v-btn>
              <v-btn value="90d">90 дней</v-btn>
            </v-btn-toggle>
            <v-spacer />
            <v-btn
              color="primary"
              variant="tonal"
              size="small"
              :loading="statsLoading"
              @click="loadStats"
            >
              <v-icon start>mdi-refresh</v-icon>
              Обновить
            </v-btn>
          </v-col>
        </v-row>

        <!-- Summary Cards -->
        <v-row class="mb-4">
          <v-col cols="12" sm="6" md="3">
            <v-card color="primary" variant="tonal">
              <v-card-text class="d-flex align-center">
                <v-avatar color="primary" class="mr-4">
                  <v-icon>mdi-message-processing</v-icon>
                </v-avatar>
                <div>
                  <div class="text-h5 font-weight-bold">{{ stats.totals.total_requests }}</div>
                  <div class="text-body-2">Всего запросов</div>
                </div>
              </v-card-text>
            </v-card>
          </v-col>
          <v-col cols="12" sm="6" md="3">
            <v-card color="success" variant="tonal">
              <v-card-text class="d-flex align-center">
                <v-avatar color="success" class="mr-4">
                  <v-icon>mdi-check-circle</v-icon>
                </v-avatar>
                <div>
                  <div class="text-h5 font-weight-bold">{{ stats.totals.success_rate }}%</div>
                  <div class="text-body-2">Успешность</div>
                </div>
              </v-card-text>
            </v-card>
          </v-col>
          <v-col cols="12" sm="6" md="3">
            <v-card color="warning" variant="tonal">
              <v-card-text class="d-flex align-center">
                <v-avatar color="warning" class="mr-4">
                  <v-icon>mdi-currency-usd</v-icon>
                </v-avatar>
                <div>
                  <div class="text-h5 font-weight-bold">${{ stats.totals.total_cost }}</div>
                  <div class="text-body-2">Затраты</div>
                </div>
              </v-card-text>
            </v-card>
          </v-col>
          <v-col cols="12" sm="6" md="3">
            <v-card color="info" variant="tonal">
              <v-card-text class="d-flex align-center">
                <v-avatar color="info" class="mr-4">
                  <v-icon>mdi-account-multiple</v-icon>
                </v-avatar>
                <div>
                  <div class="text-h5 font-weight-bold">{{ stats.totals.unique_users }}</div>
                  <div class="text-body-2">Активных пользователей</div>
                </div>
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>

        <v-row>
          <!-- Daily Activity Chart -->
          <v-col cols="12" lg="8">
            <v-card :loading="statsLoading">
              <v-card-title>
                <v-icon class="mr-2">mdi-chart-line</v-icon>
                Активность по дням
              </v-card-title>
              <v-card-text>
                <div class="activity-chart">
                  <div
                    v-for="day in stats.daily_activity"
                    :key="day.date"
                    class="activity-bar-container"
                  >
                    <div class="activity-bar-wrapper">
                      <div
                        class="activity-bar activity-bar-success"
                        :style="{ height: getBarHeight(day.successful) }"
                        :title="`Успешных: ${day.successful}`"
                      />
                      <div
                        class="activity-bar activity-bar-error"
                        :style="{ height: getBarHeight(day.failed) }"
                        :title="`Ошибок: ${day.failed}`"
                      />
                    </div>
                    <div class="activity-label">{{ formatDateShort(day.date) }}</div>
                  </div>
                </div>
                <div class="d-flex justify-center mt-2">
                  <v-chip size="small" color="success" variant="flat" class="mr-2">
                    <v-icon start size="small">mdi-circle</v-icon>
                    Успешных
                  </v-chip>
                  <v-chip size="small" color="error" variant="flat">
                    <v-icon start size="small">mdi-circle</v-icon>
                    Ошибок
                  </v-chip>
                </div>
              </v-card-text>
            </v-card>
          </v-col>

          <!-- Hourly Activity Today -->
          <v-col cols="12" lg="4">
            <v-card :loading="statsLoading">
              <v-card-title>
                <v-icon class="mr-2">mdi-clock-outline</v-icon>
                Сегодня по часам
              </v-card-title>
              <v-card-text>
                <div class="hourly-chart">
                  <div
                    v-for="hour in hourlyChartData"
                    :key="hour.hour"
                    class="hourly-bar-container"
                  >
                    <div
                      class="hourly-bar"
                      :style="{ height: getHourlyBarHeight(hour.count) }"
                      :title="`${hour.hour}:00 - ${hour.count} запросов`"
                    />
                    <div class="hourly-label" v-if="hour.hour % 4 === 0">{{ hour.hour }}</div>
                  </div>
                </div>
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>

        <v-row class="mt-4">
          <!-- Providers Stats -->
          <v-col cols="12" md="6">
            <v-card :loading="statsLoading">
              <v-card-title>
                <v-icon class="mr-2">mdi-server</v-icon>
                Статистика провайдеров
              </v-card-title>
              <v-card-text>
                <v-list density="compact">
                  <v-list-item
                    v-for="(data, name) in stats.by_provider"
                    :key="name"
                    class="px-0"
                  >
                    <template #prepend>
                      <v-avatar size="40" :color="getProviderColor(name as string)">
                        <v-icon>{{ getProviderIcon(name as string) }}</v-icon>
                      </v-avatar>
                    </template>
                    <v-list-item-title class="font-weight-medium">
                      {{ getProviderName(name as string) }}
                    </v-list-item-title>
                    <v-list-item-subtitle>
                      {{ data.count }} запросов · ${{ data.cost }} · {{ data.avg_latency_ms }}ms
                    </v-list-item-subtitle>
                    <template #append>
                      <v-progress-circular
                        :model-value="getProviderShare(data.count)"
                        :size="40"
                        :width="4"
                        :color="getProviderColor(name as string)"
                      >
                        <span class="text-caption">{{ getProviderShare(data.count) }}%</span>
                      </v-progress-circular>
                    </template>
                  </v-list-item>
                </v-list>

                <!-- Provider Distribution Pie -->
                <div class="provider-pie-chart mt-4">
                  <div class="pie-chart">
                    <svg viewBox="0 0 100 100">
                      <circle
                        v-for="(segment, idx) in providerPieSegments"
                        :key="idx"
                        cx="50"
                        cy="50"
                        r="40"
                        fill="transparent"
                        :stroke="segment.color"
                        stroke-width="20"
                        :stroke-dasharray="segment.dasharray"
                        :stroke-dashoffset="segment.offset"
                        transform="rotate(-90 50 50)"
                      />
                    </svg>
                    <div class="pie-center">
                      <div class="text-h6">{{ stats.totals.total_requests }}</div>
                      <div class="text-caption">запросов</div>
                    </div>
                  </div>
                </div>
              </v-card-text>
            </v-card>
          </v-col>

          <!-- Top Users -->
          <v-col cols="12" md="6">
            <v-card :loading="statsLoading">
              <v-card-title>
                <v-icon class="mr-2">mdi-account-star</v-icon>
                Топ пользователей
              </v-card-title>
              <v-card-text>
                <v-list density="compact">
                  <v-list-item
                    v-for="(user, index) in stats.by_user"
                    :key="user.user_id"
                    class="px-0"
                  >
                    <template #prepend>
                      <v-avatar size="40" :color="getUserColor(index)">
                        <span class="text-white font-weight-bold">{{ index + 1 }}</span>
                      </v-avatar>
                    </template>
                    <v-list-item-title class="font-weight-medium">
                      {{ user.name }}
                    </v-list-item-title>
                    <v-list-item-subtitle>
                      {{ user.count }} запросов · ${{ user.cost }}
                    </v-list-item-subtitle>
                    <template #append>
                      <v-progress-linear
                        :model-value="getUserShare(user.count)"
                        height="8"
                        rounded
                        :color="getUserColor(index)"
                        class="ml-4"
                        style="width: 80px"
                      />
                    </template>
                  </v-list-item>
                </v-list>

                <v-alert
                  v-if="stats.by_user.length === 0"
                  type="info"
                  variant="tonal"
                  class="mt-2"
                >
                  Нет данных за выбранный период
                </v-alert>
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>

        <!-- Error Types -->
        <v-row class="mt-4" v-if="stats.errors_by_type.length > 0">
          <v-col cols="12">
            <v-card :loading="statsLoading">
              <v-card-title>
                <v-icon class="mr-2" color="error">mdi-alert-circle</v-icon>
                Ошибки по типам
              </v-card-title>
              <v-card-text>
                <v-row>
                  <v-col
                    v-for="error in stats.errors_by_type"
                    :key="error.type"
                    cols="12"
                    sm="6"
                    md="4"
                    lg="3"
                  >
                    <v-card variant="outlined" color="error">
                      <v-card-text class="d-flex align-center">
                        <v-icon color="error" class="mr-3">{{ getErrorIcon(error.type) }}</v-icon>
                        <div>
                          <div class="text-h6 font-weight-bold">{{ error.count }}</div>
                          <div class="text-body-2">{{ getErrorLabel(error.type) }}</div>
                        </div>
                      </v-card-text>
                    </v-card>
                  </v-col>
                </v-row>
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>
      </v-window-item>

      <v-window-item value="settings">
        <v-row>
          <v-col cols="12" md="6">
            <v-card class="h-100">
              <v-card-title>Настройки приложения</v-card-title>
              <v-card-text class="text-body-2 text-medium-emphasis">
                Здесь будут глобальные параметры, системные флаги и конфигурация модулей.
              </v-card-text>
              <v-card-actions>
                <v-btn color="primary" variant="tonal" disabled>Открыть</v-btn>
              </v-card-actions>
            </v-card>
          </v-col>
          <v-col cols="12" md="6">
            <v-card class="h-100">
              <v-card-title>Безопасность</v-card-title>
              <v-card-text class="text-body-2 text-medium-emphasis">
                Политики доступа, журналы действий и ограничения по ролям.
              </v-card-text>
              <v-card-actions>
                <v-btn color="primary" variant="tonal" disabled>Настроить</v-btn>
              </v-card-actions>
            </v-card>
          </v-col>
        </v-row>
      </v-window-item>

      <v-window-item value="users">
        <v-card>
          <v-card-title>Управление пользователями</v-card-title>
          <v-card-text class="text-body-2 text-medium-emphasis">
            Таблица пользователей, роли, блокировки и сброс паролей будут здесь.
          </v-card-text>
          <v-card-actions>
            <v-btn color="primary" variant="tonal" disabled>Добавить пользователя</v-btn>
          </v-card-actions>
        </v-card>
      </v-window-item>

      <!-- Notifications Tab -->
      <v-window-item value="notifications">
        <AdminNotificationsTab />
      </v-window-item>
    </v-window>
  </v-container>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import api from '@/api/axios'
import AdminNotificationsTab from '@/components/notifications/AdminNotificationsTab.vue'

type TabType = 'llm' | 'llm-prompts' | 'llm-stats' | 'settings' | 'users' | 'notifications'

const activeTab = ref<TabType>('llm')

// ==================== LLM Settings State ====================
const llmLoading = ref(false)
const llmSaving = ref(false)
const llmTesting = ref(false)

interface LLMSettings {
  mode: 'manual' | 'auto'
  primary_provider: string
  fallback_providers: string[]
  providers: Record<string, {
    api_key_set: boolean
    api_key_masked: string
    base_url: string
    model: string
    is_env_fallback: boolean
  }>
  circuit_breaker: Record<string, {
    status: 'healthy' | 'down'
    fail_count: number
    down_until: string | null
  }>
  available_providers?: ProviderInfo[]
}

interface ProviderInfo {
  value: string
  title: string
  description: string
  icon: string
  docs_url: string
}

const llmSettings = ref<LLMSettings>({
  mode: 'auto',
  primary_provider: 'openrouter',
  fallback_providers: ['deepseek'],
  providers: {},
  circuit_breaker: {},
  available_providers: [],
})

const providerForms = ref<Record<string, { api_key: string; base_url: string; model: string }>>({})
const availableProviders = computed(() => llmSettings.value.available_providers || [])

interface TestResult {
  available: boolean
  latency_ms?: number
  error?: string
}

interface TestResults {
  results: Record<string, TestResult>
  tested_at: string
}

const testResults = ref<TestResults | null>(null)

const modeOptions = [
  { title: 'Auto (с failover)', value: 'auto' },
  { title: 'Manual (только primary)', value: 'manual' },
]

const providerOptions = computed(() => availableProviders.value.map((p) => ({
  title: p.title,
  value: p.value,
})))

const fallbackProviderOptions = computed(() => {
  return providerOptions.value.filter((p) => p.value !== llmSettings.value.primary_provider)
})

const testResultsType = computed(() => {
  if (!testResults.value) return 'info'
  const allOk = Object.values(testResults.value.results).every((r) => r.available)
  return allOk ? 'success' : 'warning'
})

async function loadLLMSettings() {
  llmLoading.value = true
  try {
    const { data } = await api.get('/api/admin/llm-settings')
    llmSettings.value = data
    
    const providers = data.available_providers || []
    const newForms: Record<string, { api_key: string; base_url: string; model: string }> = {}
    for (const p of providers) {
      newForms[p.value] = { api_key: '', base_url: '', model: '' }
    }
    providerForms.value = newForms
  } catch (e: any) {
    console.error('Failed to load LLM settings', e)
  } finally {
    llmLoading.value = false
  }
}

async function saveLLMSettings() {
  llmSaving.value = true
  try {
    const payload: any = {
      mode: llmSettings.value.mode,
      primary_provider: llmSettings.value.primary_provider,
      fallback_providers: llmSettings.value.fallback_providers,
      providers: {},
    }

    for (const [name, form] of Object.entries(providerForms.value)) {
      const providerData: any = {}
      if (form.api_key) providerData.api_key = form.api_key
      if (form.base_url) providerData.base_url = form.base_url
      if (form.model) providerData.model = form.model
      if (Object.keys(providerData).length > 0) {
        payload.providers[name] = providerData
      }
    }

    await api.put('/api/admin/llm-settings', payload)
    await loadLLMSettings()
  } catch (e: any) {
    console.error('Failed to save LLM settings', e)
  } finally {
    llmSaving.value = false
  }
}

async function testProviders() {
  llmTesting.value = true
  testResults.value = null
  try {
    const { data } = await api.post('/api/admin/llm-test')
    testResults.value = data
  } catch (e: any) {
    console.error('Failed to test providers', e)
  } finally {
    llmTesting.value = false
  }
}

async function resetCircuit(provider: string) {
  try {
    await api.post('/api/admin/llm-reset-circuit', { provider })
    await loadLLMSettings()
  } catch (e: any) {
    console.error('Failed to reset circuit breaker', e)
  }
}

// ==================== LLM Statistics State ====================
const statsLoading = ref(false)
const statsPeriod = ref('7d')

interface StatsData {
  totals: {
    total_requests: number
    successful_requests: number
    failed_requests: number
    success_rate: number
    total_cost: number
    total_tokens: number
    avg_latency_ms: number
    unique_users: number
  }
  by_provider: Record<string, { count: number; cost: number; avg_latency_ms: number }>
  by_user: Array<{ user_id: number; name: string; count: number; cost: number }>
  daily_activity: Array<{ date: string; count: number; successful: number; failed: number }>
  hourly_activity: Array<{ hour: number; count: number }>
  errors_by_type: Array<{ type: string; count: number }>
}

const stats = ref<StatsData>({
  totals: {
    total_requests: 0,
    successful_requests: 0,
    failed_requests: 0,
    success_rate: 0,
    total_cost: 0,
    total_tokens: 0,
    avg_latency_ms: 0,
    unique_users: 0,
  },
  by_provider: {},
  by_user: [],
  daily_activity: [],
  hourly_activity: [],
  errors_by_type: [],
})

async function loadStats() {
  statsLoading.value = true
  try {
    const { data } = await api.get('/api/admin/llm-stats', {
      params: { period: statsPeriod.value },
    })
    stats.value = data
  } catch (e: any) {
    console.error('Failed to load stats', e)
  } finally {
    statsLoading.value = false
  }
}

// Watch period changes
watch(statsPeriod, () => {
  loadStats()
})

// Chart helpers
const maxDailyCount = computed(() => {
  return Math.max(1, ...stats.value.daily_activity.map((d) => d.count))
})

const maxHourlyCount = computed(() => {
  return Math.max(1, ...stats.value.hourly_activity.map((h) => h.count))
})

function getBarHeight(value: number): string {
  const percent = (value / maxDailyCount.value) * 100
  return `${Math.max(2, percent)}%`
}

function getHourlyBarHeight(value: number): string {
  const percent = (value / maxHourlyCount.value) * 100
  return `${Math.max(2, percent)}%`
}

// Fill missing hours for today
const hourlyChartData = computed(() => {
  const data = []
  for (let h = 0; h < 24; h++) {
    const found = stats.value.hourly_activity.find((x) => x.hour === h)
    data.push({ hour: h, count: found?.count || 0 })
  }
  return data
})

function formatDateShort(dateStr: string): string {
  const d = new Date(dateStr)
  return `${d.getDate()}/${d.getMonth() + 1}`
}

// Provider helpers
const providerColors: Record<string, string> = {
  openrouter: 'purple',
  deepseek: 'blue',
  mistral: 'orange',
}

const providerIcons: Record<string, string> = {
  openrouter: 'mdi-cloud',
  deepseek: 'mdi-brain',
  mistral: 'mdi-weather-windy',
}

function getProviderColor(name: string): string {
  return providerColors[name] || 'grey'
}

function getProviderIcon(name: string): string {
  return providerIcons[name] || 'mdi-robot'
}

function getProviderName(name: string): string {
  const p = availableProviders.value.find((x) => x.value === name)
  return p?.title || name
}

function getProviderShare(count: number): number {
  if (stats.value.totals.total_requests === 0) return 0
  return Math.round((count / stats.value.totals.total_requests) * 100)
}

// Pie chart segments
const providerPieSegments = computed(() => {
  const total = stats.value.totals.total_requests
  if (total === 0) return []

  const circumference = 2 * Math.PI * 40
  let offset = 0
  const segments = []

  for (const [name, data] of Object.entries(stats.value.by_provider)) {
    const percent = data.count / total
    const dashLength = circumference * percent
    segments.push({
      color: getProviderColorHex(name),
      dasharray: `${dashLength} ${circumference - dashLength}`,
      offset: -offset,
    })
    offset += dashLength
  }

  return segments
})

function getProviderColorHex(name: string): string {
  const colors: Record<string, string> = {
    openrouter: '#9c27b0',
    deepseek: '#2196f3',
    mistral: '#ff9800',
  }
  return colors[name] || '#9e9e9e'
}

// User helpers
const userColors: string[] = ['primary', 'secondary', 'success', 'warning', 'info', 'error']

function getUserColor(index: number): string {
  return userColors[index % userColors.length] || 'primary'
}

function getUserShare(count: number): number {
  const maxCount = Math.max(1, ...stats.value.by_user.map((u) => u.count))
  return (count / maxCount) * 100
}

// Error helpers
const errorIcons: Record<string, string> = {
  timeout: 'mdi-clock-alert',
  http_429: 'mdi-speedometer',
  http_5xx: 'mdi-server-off',
  invalid_json: 'mdi-code-json',
  auth: 'mdi-key-alert',
  network: 'mdi-wifi-off',
  unknown: 'mdi-help-circle',
}

const errorLabels: Record<string, string> = {
  timeout: 'Таймаут',
  http_429: 'Rate Limit',
  http_5xx: 'Ошибка сервера',
  invalid_json: 'Невалидный JSON',
  auth: 'Ошибка авторизации',
  network: 'Сетевая ошибка',
  unknown: 'Неизвестная ошибка',
}

function getErrorIcon(type: string): string {
  return errorIcons[type] || 'mdi-alert'
}

function getErrorLabel(type: string): string {
  return errorLabels[type] || type
}

// ==================== LLM Prompts State ====================
const promptsLoading = ref(false)
const promptsSaving = ref(false)
const promptsResetting = ref(false)
const previewLoading = ref(false)
const promptsMessage = ref('')
const promptsMessageType = ref<'success' | 'error' | 'info' | 'warning'>('success')
const promptPreview = ref('')

const promptsForm = ref({
  system_prompt: '',
  user_prompt_template: '',
})

const availableVariables = ref<Record<string, string>>({
  '{title}': 'Название позиции сметы',
  '{context}': 'Контекст (родительские элементы)',
  '{desired_hours}': 'Желаемое количество часов',
})

const previewData = ref({
  title: 'Монтаж электропроводки в офисном помещении',
  context: 'Раздел: Электромонтажные работы > Подраздел: Внутренние сети',
  desired_hours: 16,
})

async function loadPrompts() {
  promptsLoading.value = true
  try {
    const { data } = await api.get('/api/admin/llm-prompts')
    if (data.success) {
      promptsForm.value.system_prompt = data.prompts.system_prompt
      promptsForm.value.user_prompt_template = data.prompts.user_prompt_template
      availableVariables.value = data.available_variables || {}
    }
  } catch (e: any) {
    console.error('Failed to load prompts', e)
    promptsMessage.value = 'Ошибка загрузки промптов'
    promptsMessageType.value = 'error'
  } finally {
    promptsLoading.value = false
  }
}

async function savePrompts() {
  promptsSaving.value = true
  promptsMessage.value = ''
  try {
    const { data } = await api.put('/api/admin/llm-prompts', {
      system_prompt: promptsForm.value.system_prompt,
      user_prompt_template: promptsForm.value.user_prompt_template,
    })
    if (data.success) {
      promptsMessage.value = 'Промпты успешно сохранены'
      promptsMessageType.value = 'success'
    } else {
      promptsMessage.value = data.message || 'Ошибка сохранения'
      promptsMessageType.value = 'error'
    }
  } catch (e: any) {
    console.error('Failed to save prompts', e)
    promptsMessage.value = e.response?.data?.message || 'Ошибка сохранения промптов'
    promptsMessageType.value = 'error'
  } finally {
    promptsSaving.value = false
  }
}

async function resetPrompts() {
  if (!confirm('Вы уверены, что хотите сбросить промпты к значениям по умолчанию?')) {
    return
  }

  promptsResetting.value = true
  promptsMessage.value = ''
  try {
    const { data } = await api.post('/api/admin/llm-prompts/reset')
    if (data.success) {
      promptsForm.value.system_prompt = data.prompts.system_prompt
      promptsForm.value.user_prompt_template = data.prompts.user_prompt_template
      promptsMessage.value = 'Промпты сброшены к значениям по умолчанию'
      promptsMessageType.value = 'success'
    }
  } catch (e: any) {
    console.error('Failed to reset prompts', e)
    promptsMessage.value = 'Ошибка сброса промптов'
    promptsMessageType.value = 'error'
  } finally {
    promptsResetting.value = false
  }
}

async function loadPreview() {
  previewLoading.value = true
  try {
    const { data } = await api.post('/api/admin/llm-prompts/preview', {
      user_prompt_template: promptsForm.value.user_prompt_template,
      title: previewData.value.title,
      context: previewData.value.context,
      desired_hours: previewData.value.desired_hours,
    })
    if (data.success) {
      promptPreview.value = data.preview
    }
  } catch (e: any) {
    console.error('Failed to load preview', e)
  } finally {
    previewLoading.value = false
  }
}

function insertVariable(variable: string) {
  // Добавляем переменную в конец шаблона
  promptsForm.value.user_prompt_template += ` ${variable}`
}

// ==================== Lifecycle ====================
onMounted(() => {
  loadLLMSettings()
  loadStats()
  loadPrompts()
})
</script>

<style scoped>
.activity-chart {
  display: flex;
  align-items: flex-end;
  height: 200px;
  gap: 4px;
  padding: 0 8px;
}

.activity-bar-container {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  height: 100%;
}

.activity-bar-wrapper {
  flex: 1;
  width: 100%;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  gap: 2px;
}

.activity-bar {
  width: 100%;
  border-radius: 4px 4px 0 0;
  transition: height 0.3s ease;
}

.activity-bar-success {
  background: linear-gradient(180deg, #4caf50 0%, #2e7d32 100%);
}

.activity-bar-error {
  background: linear-gradient(180deg, #f44336 0%, #c62828 100%);
}

.activity-label {
  font-size: 10px;
  color: rgba(var(--v-theme-on-surface), 0.6);
  margin-top: 4px;
}

.hourly-chart {
  display: flex;
  align-items: flex-end;
  height: 120px;
  gap: 2px;
}

.hourly-bar-container {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  height: 100%;
}

.hourly-bar {
  width: 100%;
  background: linear-gradient(180deg, #2196f3 0%, #1565c0 100%);
  border-radius: 2px 2px 0 0;
  transition: height 0.3s ease;
}

.hourly-label {
  font-size: 9px;
  color: rgba(var(--v-theme-on-surface), 0.6);
  margin-top: 2px;
}

.provider-pie-chart {
  display: flex;
  justify-content: center;
}

.pie-chart {
  position: relative;
  width: 150px;
  height: 150px;
}

.pie-chart svg {
  width: 100%;
  height: 100%;
}

.pie-center {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  text-align: center;
}

.preview-text {
  white-space: pre-wrap;
  word-break: break-word;
  font-family: 'Roboto Mono', monospace;
  font-size: 13px;
  line-height: 1.5;
  color: rgba(var(--v-theme-on-surface), 0.87);
  margin: 0;
  max-height: 300px;
  overflow-y: auto;
}
</style>
