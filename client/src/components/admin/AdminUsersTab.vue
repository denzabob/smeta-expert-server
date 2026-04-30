<template>
  <div class="admin-users">
    <!-- Metrics Cards -->
    <v-row class="mb-4" dense>
      <v-col cols="12" sm="6" md="3" lg="2" v-for="metric in metricCards" :key="metric.label">
        <v-card variant="outlined" class="pa-3 text-center" :class="{ 'border-primary': metric.active }">
          <div class="text-h5 font-weight-bold" :class="metric.color">{{ metric.value }}</div>
          <div class="text-caption text-medium-emphasis">{{ metric.label }}</div>
        </v-card>
      </v-col>
    </v-row>

    <!-- Toolbar: Search + Filters + Actions -->
    <TableToolbar class="mb-4">
      <template #search>
        <v-text-field
          v-model="search"
          prepend-inner-icon="mdi-magnify"
          label="Поиск по ID, email, имени, телефону"
          variant="outlined"
          density="compact"
          hide-details
          clearable
          style="max-width: 350px; min-width: 200px"
          @keyup.enter="loadUsers"
          @click:clear="search = ''; loadUsers()"
        />
      </template>

      <template #filters>
        <v-select
          v-model="filterStatus"
          :items="statusOptions"
          label="Статус"
          variant="outlined"
          density="compact"
          hide-details
          clearable
          style="max-width: 180px"
          @update:model-value="loadUsers"
        />

        <v-select
          v-model="filterRole"
          :items="roleOptions"
          label="Роль"
          variant="outlined"
          density="compact"
          hide-details
          clearable
          style="max-width: 180px"
          @update:model-value="loadUsers"
        />
      </template>

      <template #actions>
        <v-btn
          v-if="selectedUsers.length > 0"
          color="warning"
          variant="tonal"
          size="small"
          prepend-icon="mdi-selection-multiple"
          @click="showBulkActionDialog = true"
        >
          Действия ({{ selectedUsers.length }})
        </v-btn>

        <v-btn icon="mdi-refresh" variant="text" :loading="loading" @click="loadUsers" />
      </template>
    </TableToolbar>

    <!-- Users Table -->
    <v-card variant="outlined" :loading="loading">
      <v-data-table
        v-model="selectedUsers"
        :headers="headers"
        :items="users"
        :loading="loading"
        :items-per-page="perPage"
        density="comfortable"
        show-select
        class="elevation-0"
        item-value="id"
        @update:options="onTableOptions"
      >
        <!-- User name + avatar -->
        <template #item.name="{ item }">
          <div class="d-flex align-center py-1">
            <v-avatar size="32" class="mr-2" :color="getStatusColor(item)">
              <span class="text-white text-caption">{{ initials(item.name || item.email || '?') }}</span>
            </v-avatar>
            <div>
              <div class="font-weight-medium">{{ item.name || '—' }}</div>
              <div class="text-caption text-medium-emphasis">{{ item.email || item.phone || '—' }}</div>
            </div>
          </div>
        </template>

        <!-- Role -->
        <template #item.role="{ item }">
          <v-chip size="small" :color="getRoleColor(item.role)" variant="tonal">
            {{ getRoleLabel(item.role) }}
          </v-chip>
        </template>

        <!-- Status -->
        <template #item.auth_status="{ item }">
          <v-chip size="small" :color="getStatusChipColor(item)" variant="tonal">
            {{ getStatusLabel(item) }}
          </v-chip>
        </template>

        <!-- Registration date -->
        <template #item.created_at="{ item }">
          <span class="text-medium-emphasis text-body-2">{{ formatDate(item.created_at) }}</span>
        </template>

        <!-- Last login -->
        <template #item.last_login_at="{ item }">
          <span class="text-medium-emphasis text-body-2">{{ formatDate(item.last_login_at) }}</span>
        </template>

        <!-- AI requests -->
        <template #item.ai_requests_count="{ item }">
          <span class="font-weight-medium">{{ item.ai_requests_count || 0 }}</span>
        </template>

        <template #item.billing_plan="{ item }">
          <div class="text-body-2 font-weight-medium">{{ billingPlanLabel(item) }}</div>
          <div class="text-caption text-medium-emphasis">{{ item.billing?.plan_code || '—' }}</div>
        </template>

        <template #item.billing_status="{ item }">
          <v-chip size="small" :color="billingStatusColor(item.billing?.subscription_status)" variant="tonal">
            {{ billingStatusLabel(item.billing?.subscription_status) }}
          </v-chip>
        </template>

        <template #item.billing_period_end="{ item }">
          <span class="text-body-2">{{ item.billing?.current_period_end ? formatDate(item.billing.current_period_end) : 'Бессрочно' }}</span>
        </template>

        <template #item.billing_gate_events="{ item }">
          <div class="text-body-2 font-weight-medium">{{ item.billing?.gate_events_count || 0 }}</div>
          <div class="text-caption text-medium-emphasis">would block: {{ item.billing?.would_block_events_count || 0 }}</div>
        </template>

        <!-- Actions -->
        <template #item.actions="{ item }">
          <div class="d-flex ga-1">
            <v-btn icon="mdi-eye" size="x-small" variant="text" @click="openUserCard(item)" title="Просмотр" />
            <v-menu>
              <template #activator="{ props }">
                <v-btn icon="mdi-dots-vertical" size="x-small" variant="text" v-bind="props" />
              </template>
              <v-list density="compact">
                <v-list-item v-if="!item.deleted_at && item.auth_status !== 'blocked'" @click="openBlockDialog(item)">
                  <template #prepend><v-icon size="small" color="warning">mdi-lock</v-icon></template>
                  <v-list-item-title>Заблокировать</v-list-item-title>
                </v-list-item>
                <v-list-item v-if="item.auth_status === 'blocked'" @click="openUnblockDialog(item)">
                  <template #prepend><v-icon size="small" color="success">mdi-lock-open</v-icon></template>
                  <v-list-item-title>Разблокировать</v-list-item-title>
                </v-list-item>
                <v-divider />
                <v-list-item @click="openAssignPlanDialog(item)">
                  <template #prepend><v-icon size="small" color="primary">mdi-card-account-details-star</v-icon></template>
                  <v-list-item-title>Назначить тариф</v-list-item-title>
                </v-list-item>
                <v-list-item @click="openBillingStats(item)">
                  <template #prepend><v-icon size="small" color="info">mdi-chart-timeline-variant</v-icon></template>
                  <v-list-item-title>Статистика лимитов</v-list-item-title>
                </v-list-item>
                <v-list-item @click="openGateEventsForUser(item)">
                  <template #prepend><v-icon size="small" color="info">mdi-open-in-new</v-icon></template>
                  <v-list-item-title>Открыть события лимитов</v-list-item-title>
                </v-list-item>
                <v-divider />
                <v-list-item v-if="!item.deleted_at" @click="openDeleteDialog(item, 'soft')">
                  <template #prepend><v-icon size="small" color="error">mdi-delete</v-icon></template>
                  <v-list-item-title>Удалить (soft)</v-list-item-title>
                </v-list-item>
                <v-list-item v-if="item.deleted_at" @click="restoreUser(item)">
                  <template #prepend><v-icon size="small" color="info">mdi-restore</v-icon></template>
                  <v-list-item-title>Восстановить</v-list-item-title>
                </v-list-item>
                <v-divider />
                <v-list-item @click="openDeleteDialog(item, 'hard')" class="text-error">
                  <template #prepend><v-icon size="small" color="error">mdi-delete-forever</v-icon></template>
                  <v-list-item-title>Удалить навсегда</v-list-item-title>
                </v-list-item>
              </v-list>
            </v-menu>
          </div>
        </template>

        <!-- Pagination footer -->
        <template #bottom>
          <div class="d-flex justify-center align-center pa-4" v-if="pagination.last_page > 1">
            <v-pagination
              v-model="page"
              :length="pagination.last_page"
              :total-visible="7"
              density="compact"
              @update:model-value="loadUsers"
            />
          </div>
        </template>
      </v-data-table>
    </v-card>

    <!-- LLM Stats (preserved) -->
    <v-card variant="outlined" class="mt-4">
      <v-expansion-panels variant="accordion">
        <v-expansion-panel>
          <v-expansion-panel-title>
            <v-icon class="mr-2">mdi-chart-bar</v-icon>
            Статистика использования LLM
          </v-expansion-panel-title>
          <v-expansion-panel-text>
            <AdminUsersLlmStats />
          </v-expansion-panel-text>
        </v-expansion-panel>
      </v-expansion-panels>
    </v-card>

    <!-- User Card Dialog -->
    <v-dialog v-model="showUserCard" max-width="900" scrollable>
      <v-card v-if="selectedUser">
        <v-card-title class="d-flex align-center">
          <v-avatar size="40" :color="getStatusColor(selectedUser)" class="mr-3">
            <span class="text-white">{{ initials(selectedUser.name || '?') }}</span>
          </v-avatar>
          <div>
            <div>{{ selectedUser.name || '—' }}</div>
            <div class="text-caption text-medium-emphasis">ID: {{ selectedUser.id }} &bull; {{ selectedUser.email || selectedUser.phone || '—' }}</div>
          </div>
          <v-spacer />
          <v-chip :color="getStatusChipColor(selectedUser)" size="small" variant="tonal" class="mr-2">
            {{ getStatusLabel(selectedUser) }}
          </v-chip>
          <v-btn icon="mdi-close" variant="text" @click="showUserCard = false" />
        </v-card-title>

        <v-divider />

        <v-card-text style="max-height: 75vh; overflow-y: auto">
          <v-tabs v-model="cardTab" density="compact" class="mb-4">
            <v-tab value="info">Основные данные</v-tab>
            <v-tab value="billing">Биллинг</v-tab>
            <v-tab value="ai">Статистика ИИ</v-tab>
            <v-tab value="deps">Зависимости</v-tab>
            <v-tab value="audit">Журнал действий</v-tab>
          </v-tabs>

          <v-window v-model="cardTab">
            <!-- Info Tab -->
            <v-window-item value="info">
              <v-table density="comfortable">
                <tbody>
                  <tr><td class="text-medium-emphasis" width="200">ID</td><td>{{ selectedUser.id }}</td></tr>
                  <tr><td class="text-medium-emphasis">Имя</td><td>{{ selectedUser.name || '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis">Полное имя</td><td>{{ selectedUser.full_name || '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis">Email</td><td>{{ selectedUser.email || '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis">Телефон</td><td>{{ selectedUser.phone || '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis">Роль</td>
                    <td>
                      <v-chip :color="getRoleColor(selectedUser.role)" size="small" variant="tonal">{{ getRoleLabel(selectedUser.role) }}</v-chip>
                      <v-btn v-if="selectedUser.id !== currentUserId && selectedUser.role !== 'superadmin'" size="x-small" variant="text" icon="mdi-pencil" class="ml-1" @click="showRoleDialog = true" />
                    </td>
                  </tr>
                  <tr><td class="text-medium-emphasis">Статус</td><td><v-chip :color="getStatusChipColor(selectedUser)" size="small" variant="tonal">{{ getStatusLabel(selectedUser) }}</v-chip></td></tr>
                  <tr v-if="selectedUser.blocked_reason"><td class="text-medium-emphasis">Причина блокировки</td><td class="text-error">{{ selectedUser.blocked_reason }}</td></tr>
                  <tr><td class="text-medium-emphasis">Канал входа</td><td>{{ selectedUser.last_login_channel || '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis">Профиль активности</td><td>{{ selectedUser.activity_profile || '—' }}</td></tr>
                  <tr><td class="text-medium-emphasis">Email подтвержден</td><td>{{ formatDate(selectedUser.email_verified_at) }}</td></tr>
                  <tr><td class="text-medium-emphasis">Телефон подтвержден</td><td>{{ formatDate(selectedUser.phone_verified_at) }}</td></tr>
                  <tr><td class="text-medium-emphasis">Регистрация завершена</td><td>{{ formatDate(selectedUser.registration_completed_at) }}</td></tr>
                  <tr><td class="text-medium-emphasis">Дата регистрации</td><td>{{ formatDate(selectedUser.created_at) }}</td></tr>
                  <tr><td class="text-medium-emphasis">Последний вход</td><td>{{ formatDate(selectedUser.last_login_at) }}</td></tr>
                  <tr><td class="text-medium-emphasis">Токенов (API)</td><td>{{ userDetail?.tokens_count ?? '—' }}</td></tr>
                </tbody>
              </v-table>

              <!-- Social accounts -->
              <div v-if="userDetail?.social_accounts?.length" class="mt-4">
                <div class="text-subtitle-2 mb-2">Привязанные аккаунты</div>
                <v-chip v-for="sa in userDetail.social_accounts" :key="sa.id" class="mr-2" size="small" variant="tonal">
                  {{ sa.provider }}
                </v-chip>
              </div>

              <!-- Settings -->
              <div v-if="userDetail?.settings" class="mt-4">
                <div class="text-subtitle-2 mb-2">Настройки</div>
                <v-table density="compact">
                  <tbody>
                    <tr><td class="text-medium-emphasis" width="200">Регион</td><td>{{ userDetail.settings.region_id || '—' }}</td></tr>
                    <tr><td class="text-medium-emphasis">Имя эксперта</td><td>{{ userDetail.settings.expert_name || '—' }}</td></tr>
                    <tr><td class="text-medium-emphasis">Номер эксперта</td><td>{{ userDetail.settings.expert_number || '—' }}</td></tr>
                  </tbody>
                </v-table>
              </div>
            </v-window-item>

            <v-window-item value="billing">
              <div class="d-flex flex-wrap align-center ga-2 mb-4">
                <v-btn color="primary" variant="tonal" prepend-icon="mdi-card-account-details-star" @click="selectedUser && openAssignPlanDialog(selectedUser)">
                  Назначить тариф
                </v-btn>
                <v-btn variant="outlined" prepend-icon="mdi-open-in-new" @click="selectedUser && openGateEventsForUser(selectedUser)">
                  Открыть события лимитов
                </v-btn>
              </div>

              <v-row dense class="mb-4">
                <v-col cols="12" md="6">
                  <v-card variant="tonal" class="pa-3 h-100">
                    <div class="text-subtitle-2 mb-2">Текущая подписка</div>
                    <v-table density="compact">
                      <tbody>
                        <tr><td class="text-medium-emphasis" width="160">Тариф</td><td>{{ billingDetailPlanName }}</td></tr>
                        <tr><td class="text-medium-emphasis">Код тарифа</td><td>{{ userDetail?.billing?.subscription?.plan_code || userDetail?.billing?.effective_plan_code || '—' }}</td></tr>
                        <tr><td class="text-medium-emphasis">Статус</td><td>{{ billingStatusLabel(userDetail?.billing?.subscription?.status) }}</td></tr>
                        <tr><td class="text-medium-emphasis">Источник</td><td>{{ userDetail?.billing?.subscription?.source || 'fallback' }}</td></tr>
                        <tr><td class="text-medium-emphasis">Действует до</td><td>{{ userDetail?.billing?.subscription?.current_period_end ? formatDate(userDetail.billing.subscription.current_period_end) : 'Бессрочно' }}</td></tr>
                      </tbody>
                    </v-table>
                  </v-card>
                </v-col>
                <v-col cols="12" md="6">
                  <v-card variant="tonal" class="pa-3 h-100">
                    <div class="text-subtitle-2 mb-2">Log-only события</div>
                    <v-row dense>
                      <v-col cols="6"><div class="text-h6">{{ userDetail?.billing?.gate_stats?.total_events || 0 }}</div><div class="text-caption text-medium-emphasis">Всего</div></v-col>
                      <v-col cols="6"><div class="text-h6">{{ userDetail?.billing?.gate_stats?.current_month_events || 0 }}</div><div class="text-caption text-medium-emphasis">За месяц</div></v-col>
                      <v-col cols="6"><div class="text-h6">{{ userDetail?.billing?.gate_stats?.last_7_days_events || 0 }}</div><div class="text-caption text-medium-emphasis">За 7 дней</div></v-col>
                      <v-col cols="6"><div class="text-body-2">{{ formatDate(userDetail?.billing?.gate_stats?.last_event_at || null) }}</div><div class="text-caption text-medium-emphasis">Последнее</div></v-col>
                    </v-row>
                  </v-card>
                </v-col>
              </v-row>

              <div class="text-subtitle-2 mb-2">Самые частые действия</div>
              <div v-if="userDetail?.billing?.gate_stats?.top_actions?.length" class="d-flex flex-wrap ga-2 mb-4">
                <v-chip v-for="action in userDetail.billing.gate_stats.top_actions" :key="action.action" size="small" variant="tonal">
                  {{ billingActionLabel(action.action) }}: {{ action.count }}
                </v-chip>
              </div>
              <v-alert v-else type="info" variant="tonal" density="compact" class="mb-4">
                Log-only событий по пользователю пока нет.
              </v-alert>

              <div class="text-subtitle-2 mb-2">История назначений</div>
              <v-table v-if="userDetail?.billing?.history?.length" density="compact">
                <thead>
                  <tr>
                    <th>Дата</th>
                    <th>Изменение</th>
                    <th>Назначил</th>
                    <th>Срок</th>
                    <th>Комментарий</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="event in userDetail.billing.history" :key="event.id">
                    <td>{{ formatDate(event.created_at) }}</td>
                    <td>{{ event.old_plan_code || '—' }} → {{ event.new_plan_code || '—' }}</td>
                    <td>{{ event.admin_user?.name || event.admin_user?.email || '—' }}</td>
                    <td>{{ event.new_period_end ? `до ${formatDate(event.new_period_end)}` : 'бессрочно' }}</td>
                    <td>{{ event.reason || '—' }}</td>
                  </tr>
                </tbody>
              </v-table>
              <v-alert v-else type="info" variant="tonal" density="compact">
                История назначений пока пуста.
              </v-alert>
            </v-window-item>

            <!-- AI Stats Tab -->
            <v-window-item value="ai">
              <v-row v-if="userDetail?.ai_stats" dense class="mb-4">
                <v-col cols="6" sm="3" v-for="stat in aiStatCards" :key="stat.label">
                  <v-card variant="tonal" class="pa-3 text-center">
                    <div class="text-h6 font-weight-bold">{{ stat.value }}</div>
                    <div class="text-caption text-medium-emphasis">{{ stat.label }}</div>
                  </v-card>
                </v-col>
              </v-row>
              <v-alert v-else type="info" variant="tonal" density="compact">Нет данных об использовании ИИ</v-alert>
            </v-window-item>

            <!-- Dependencies Tab -->
            <v-window-item value="deps">
              <v-alert v-if="loadingDeps" type="info" variant="tonal" density="compact">Загрузка зависимостей...</v-alert>
              <div v-else-if="userDependencies">
                <v-alert v-if="userDependencies.total_records === 0" type="success" variant="tonal" density="compact" class="mb-3">
                  Нет связанных записей — удаление безопасно.
                </v-alert>
                <v-alert v-else type="warning" variant="tonal" density="compact" class="mb-3">
                  Всего связанных записей: <strong>{{ userDependencies.total_records }}</strong>
                </v-alert>

                <v-table density="compact">
                  <thead>
                    <tr>
                      <th>Сущность</th>
                      <th>Количество</th>
                      <th>Стратегия при удалении</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(count, key) in userDependencies.dependencies" :key="key" :class="{ 'text-medium-emphasis': count === 0 }">
                      <td>{{ depLabel(key as string) }}</td>
                      <td><strong v-if="count > 0">{{ count }}</strong><span v-else>0</span></td>
                      <td>
                        <v-chip size="x-small" :color="depStrategyColor(key as string)" variant="tonal">
                          {{ depStrategy(key as string) }}
                        </v-chip>
                      </td>
                    </tr>
                  </tbody>
                </v-table>
              </div>
            </v-window-item>

            <!-- Audit Log Tab -->
            <v-window-item value="audit">
              <v-table v-if="userDetail?.audit_log?.length" density="compact">
                <thead>
                  <tr>
                    <th>Дата</th>
                    <th>Действие</th>
                    <th>Администратор</th>
                    <th>Причина</th>
                    <th>Результат</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="log in userDetail.audit_log" :key="log.id">
                    <td class="text-body-2">{{ formatDate(log.created_at) }}</td>
                    <td><v-chip size="x-small" :color="auditActionColor(log.action)" variant="tonal">{{ auditActionLabel(log.action) }}</v-chip></td>
                    <td class="text-body-2">{{ log.admin?.name || log.admin?.email || '—' }}</td>
                    <td class="text-body-2">{{ log.reason || '—' }}</td>
                    <td><v-chip size="x-small" :color="log.result === 'success' ? 'success' : 'error'" variant="tonal">{{ log.result }}</v-chip></td>
                  </tr>
                </tbody>
              </v-table>
              <v-alert v-else type="info" variant="tonal" density="compact">Нет записей в журнале</v-alert>
            </v-window-item>
          </v-window>
        </v-card-text>

        <v-divider />

        <v-card-actions>
          <v-btn v-if="selectedUser && !selectedUser.deleted_at && selectedUser.auth_status !== 'blocked'" color="warning" variant="tonal" @click="openBlockDialog(selectedUser)">
            <v-icon start>mdi-lock</v-icon>Заблокировать
          </v-btn>
          <v-btn v-if="selectedUser && selectedUser.auth_status === 'blocked'" color="success" variant="tonal" @click="openUnblockDialog(selectedUser)">
            <v-icon start>mdi-lock-open</v-icon>Разблокировать
          </v-btn>
          <v-spacer />
          <v-btn variant="text" @click="showUserCard = false">Закрыть</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Block Dialog -->
    <v-dialog v-model="showBlockDialog" max-width="480">
      <v-card>
        <v-card-title class="text-warning">
          <v-icon class="mr-2">mdi-lock</v-icon>Блокировка пользователя
        </v-card-title>
        <v-card-text>
          <p class="mb-3">Вы собираетесь заблокировать пользователя <strong>{{ actionTarget?.name || actionTarget?.email }}</strong> (ID: {{ actionTarget?.id }}).</p>
          <p class="text-caption text-medium-emphasis mb-3">Пользователь потеряет доступ к системе. Все активные сессии и токены будут отозваны.</p>
          <v-textarea
            v-model="actionReason"
            label="Причина блокировки *"
            variant="outlined"
            density="compact"
            rows="3"
            :rules="[v => !!v || 'Укажите причину']"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showBlockDialog = false">Отмена</v-btn>
          <v-btn color="warning" variant="flat" :loading="actionLoading" :disabled="!actionReason" @click="confirmBlock">Заблокировать</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Unblock Dialog -->
    <v-dialog v-model="showUnblockDialog" max-width="480">
      <v-card>
        <v-card-title class="text-success">
          <v-icon class="mr-2">mdi-lock-open</v-icon>Разблокировка пользователя
        </v-card-title>
        <v-card-text>
          <p class="mb-3">Вы собираетесь разблокировать пользователя <strong>{{ actionTarget?.name || actionTarget?.email }}</strong> (ID: {{ actionTarget?.id }}).</p>
          <p class="text-caption text-medium-emphasis mb-3">Пользователь сможет снова войти в систему.</p>
          <v-textarea
            v-model="actionReason"
            label="Причина разблокировки"
            variant="outlined"
            density="compact"
            rows="2"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showUnblockDialog = false">Отмена</v-btn>
          <v-btn color="success" variant="flat" :loading="actionLoading" @click="confirmUnblock">Разблокировать</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Delete Dialog -->
    <v-dialog v-model="showDeleteDialog" max-width="600" scrollable>
      <v-card>
        <v-card-title class="text-error">
          <v-icon class="mr-2">{{ deleteMode === 'hard' ? 'mdi-delete-forever' : 'mdi-delete' }}</v-icon>
          {{ deleteMode === 'hard' ? 'Полное удаление' : 'Удаление' }} пользователя
        </v-card-title>
        <v-card-text>
          <v-alert v-if="deleteMode === 'hard'" type="error" variant="tonal" density="compact" class="mb-3">
            <strong>ВНИМАНИЕ!</strong> Полное удаление необратимо. Все данные пользователя будут удалены навсегда.
          </v-alert>
          <p class="mb-3">Пользователь: <strong>{{ actionTarget?.name || actionTarget?.email }}</strong> (ID: {{ actionTarget?.id }})</p>

          <!-- Dependencies preview -->
          <div v-if="deleteDependencies" class="mb-3">
            <div class="text-subtitle-2 mb-2">Связанные данные ({{ deleteDependencies.total_records }} записей):</div>
            <div class="d-flex flex-wrap ga-1 mb-2">
              <v-chip v-for="(count, key) in deleteDependencies.dependencies" :key="key" size="x-small" variant="tonal"
                :color="count > 0 ? 'warning' : 'default'" v-show="count > 0">
                {{ depLabel(key as string) }}: {{ count }}
              </v-chip>
            </div>
          </div>
          <v-progress-linear v-else-if="loadingDeleteDeps" indeterminate color="warning" class="mb-3" />

          <v-textarea
            v-model="actionReason"
            :label="deleteMode === 'hard' ? 'Причина удаления *' : 'Причина удаления'"
            variant="outlined"
            density="compact"
            rows="2"
          />

          <v-text-field
            v-if="deleteMode === 'hard'"
            v-model="deleteConfirmation"
            label="Введите DELETE для подтверждения"
            variant="outlined"
            density="compact"
            hide-details
            class="mt-2"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showDeleteDialog = false">Отмена</v-btn>
          <v-btn
            color="error"
            variant="flat"
            :loading="actionLoading"
            :disabled="deleteMode === 'hard' && deleteConfirmation !== 'DELETE'"
            @click="confirmDelete"
          >
            {{ deleteMode === 'hard' ? 'Удалить навсегда' : 'Удалить' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Role Change Dialog -->
    <v-dialog v-model="showRoleDialog" max-width="400">
      <v-card>
        <v-card-title>Изменение роли</v-card-title>
        <v-card-text>
          <p class="mb-3">Пользователь: <strong>{{ selectedUser?.name || selectedUser?.email }}</strong></p>
          <v-select
            v-model="newRole"
            :items="roleOptions"
            label="Новая роль"
            variant="outlined"
            density="compact"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showRoleDialog = false">Отмена</v-btn>
          <v-btn color="primary" variant="flat" :loading="actionLoading" @click="confirmRoleChange">Сохранить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Bulk Action Dialog -->
    <v-dialog v-model="showBulkActionDialog" max-width="480">
      <v-card>
        <v-card-title>Массовая операция</v-card-title>
        <v-card-text>
          <p class="mb-3">Выбрано пользователей: <strong>{{ selectedUsers.length }}</strong></p>
          <v-select
            v-model="bulkAction"
            :items="bulkActionOptions"
            label="Действие"
            variant="outlined"
            density="compact"
          />
          <v-textarea
            v-model="actionReason"
            label="Причина"
            variant="outlined"
            density="compact"
            rows="2"
            class="mt-2"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showBulkActionDialog = false">Отмена</v-btn>
          <v-btn color="warning" variant="flat" :loading="actionLoading" :disabled="!bulkAction" @click="confirmBulkAction">Выполнить</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="showAssignPlanDialog" max-width="560">
      <v-card>
        <v-card-title>
          <v-icon class="mr-2">mdi-card-account-details-star</v-icon>
          Назначить тариф пользователю
        </v-card-title>
        <v-card-text>
          <div class="mb-4">
            <div class="text-caption text-medium-emphasis">Пользователь</div>
            <div class="font-weight-medium">{{ actionTarget?.name || actionTarget?.email || '—' }}</div>
            <div class="text-caption text-medium-emphasis">{{ actionTarget?.email || actionTarget?.phone || `ID ${actionTarget?.id}` }}</div>
          </div>

          <v-alert type="info" variant="tonal" density="compact" class="mb-4">
            Это административное назначение тарифа. Оно не создаёт оплату и не запускает перерасчёты.
          </v-alert>

          <v-table density="compact" class="mb-4">
            <tbody>
              <tr><td class="text-medium-emphasis" width="160">Текущий тариф</td><td>{{ actionTarget ? billingPlanLabel(actionTarget) : '—' }}</td></tr>
              <tr><td class="text-medium-emphasis">Статус</td><td>{{ billingStatusLabel(actionTarget?.billing?.subscription_status) }}</td></tr>
              <tr><td class="text-medium-emphasis">Действует до</td><td>{{ actionTarget?.billing?.current_period_end ? formatDate(actionTarget.billing.current_period_end) : 'Бессрочно' }}</td></tr>
            </tbody>
          </v-table>

          <v-select
            v-model="assignPlanForm.plan_code"
            :items="billingPlanItems"
            :loading="billingPlansLoading"
            label="Новый тариф"
            variant="outlined"
            density="compact"
            class="mb-3"
          />

          <v-select
            v-model="assignPlanForm.term"
            :items="assignTermOptions"
            label="Срок действия"
            variant="outlined"
            density="compact"
            class="mb-3"
          />

          <v-text-field
            v-if="assignPlanForm.term === 'custom_date'"
            v-model="assignPlanForm.ends_at"
            type="date"
            label="Дата окончания"
            variant="outlined"
            density="compact"
            class="mb-3"
          />

          <v-textarea
            v-model="assignPlanForm.reason"
            label="Комментарий администратора"
            placeholder="Тестовый доступ на период MVP"
            variant="outlined"
            density="compact"
            rows="3"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="showAssignPlanDialog = false">Отмена</v-btn>
          <v-btn color="primary" variant="flat" :loading="assignPlanLoading" :disabled="!assignPlanForm.plan_code || (assignPlanForm.term === 'custom_date' && !assignPlanForm.ends_at)" @click="confirmAssignPlan">
            Назначить тариф
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Snackbar -->
    <v-snackbar v-model="snackbar" :color="snackbarColor" :timeout="4000" location="bottom right">
      {{ snackbarText }}
    </v-snackbar>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/api/axios'
import AdminUsersLlmStats from './AdminUsersLlmStats.vue'
import { useAuthStore } from '@/stores/auth'
import TableToolbar from '@/components/layout/TableToolbar.vue'
import {
  assignAdminBillingUserSubscription,
  getAdminBillingPlans,
  type AssignBillingSubscriptionPayload,
} from '@/api/adminBilling'

// ----- Types -----
interface UserItem {
  id: number
  name: string | null
  full_name: string | null
  email: string | null
  phone: string | null
  role: string
  auth_status: string
  blocked_reason: string | null
  blocked_at: string | null
  created_at: string
  last_login_at: string | null
  last_login_channel: string | null
  activity_profile: string | null
  email_verified_at: string | null
  phone_verified_at: string | null
  registration_completed_at: string | null
  deleted_at: string | null
  ai_requests_count: number
  billing?: BillingSummary
}

interface UserDetail {
  user: UserItem
  ai_stats: any
  dependencies: Record<string, number>
  audit_log: any[]
  settings: any
  social_accounts: any[]
  tokens_count: number
  billing?: BillingDetail
}

interface BillingSummary {
  plan_code?: string | null
  plan_name?: string | null
  subscription_status?: string | null
  current_period_end?: string | null
  source?: string | null
  gate_events_count?: number
  would_block_events_count?: number
}

interface BillingDetail {
  subscription: {
    id: number
    plan_code: string
    plan_name?: string | null
    status: string
    source?: string | null
    current_period_start?: string | null
    current_period_end?: string | null
  } | null
  effective_plan_code: string
  gate_stats: {
    total_events: number
    current_month_events: number
    last_7_days_events: number
    last_event_at?: string | null
    top_actions: Array<{ action: string; count: number }>
  }
  history: Array<{
    id: number
    event_type: string
    old_plan_code?: string | null
    new_plan_code?: string | null
    new_period_end?: string | null
    reason?: string | null
    created_at?: string | null
    admin_user?: { id: number; name?: string | null; email?: string | null } | null
  }>
}

interface Pagination {
  total: number
  per_page: number
  current_page: number
  last_page: number
}

interface Metrics {
  total: number
  active: number
  blocked: number
  deleted: number
  total_ai_requests: number
}

// ----- State -----
const authStore = useAuthStore()
const router = useRouter()
const currentUserId = computed(() => authStore.user?.id)

const loading = ref(false)
const users = ref<UserItem[]>([])
const selectedUsers = ref<number[]>([])
const pagination = ref<Pagination>({ total: 0, per_page: 20, current_page: 1, last_page: 1 })
const metrics = ref<Metrics>({ total: 0, active: 0, blocked: 0, deleted: 0, total_ai_requests: 0 })

// Filters
const search = ref('')
const filterStatus = ref<string | null>(null)
const filterRole = ref<string | null>(null)
const page = ref(1)
const perPage = ref(20)
const sortBy = ref('created_at')
const sortDir = ref<'asc' | 'desc'>('desc')

// User card
const showUserCard = ref(false)
const selectedUser = ref<UserItem | null>(null)
const userDetail = ref<UserDetail | null>(null)
const cardTab = ref('info')

// Dependencies
const userDependencies = ref<any>(null)
const loadingDeps = ref(false)
const deleteDependencies = ref<any>(null)
const loadingDeleteDeps = ref(false)

// Action dialogs
const actionTarget = ref<UserItem | null>(null)
const actionReason = ref('')
const actionLoading = ref(false)

const showBlockDialog = ref(false)
const showUnblockDialog = ref(false)
const showDeleteDialog = ref(false)
const deleteMode = ref<'soft' | 'hard'>('soft')
const deleteConfirmation = ref('')
const showRoleDialog = ref(false)
const newRole = ref('user')
const showBulkActionDialog = ref(false)
const bulkAction = ref<string | null>(null)
const showAssignPlanDialog = ref(false)
const billingPlansLoading = ref(false)
const billingPlans = ref<any[]>([])
const assignPlanLoading = ref(false)
const assignPlanForm = ref({
  plan_code: '',
  term: 'forever',
  ends_at: '',
  reason: '',
})

// Snackbar
const snackbar = ref(false)
const snackbarText = ref('')
const snackbarColor = ref('success')

// ----- Options -----
const statusOptions = [
  { title: 'Активные', value: 'active' },
  { title: 'Заблокированные', value: 'blocked' },
  { title: 'Удаленные', value: 'deleted' },
]

const roleOptions = [
  { title: 'Пользователь', value: 'user' },
  { title: 'Администратор', value: 'admin' },
]

const bulkActionOptions = [
  { title: 'Заблокировать', value: 'block' },
  { title: 'Разблокировать', value: 'unblock' },
  { title: 'Удалить (soft)', value: 'soft_delete' },
]

const assignTermOptions = [
  { title: 'Бессрочно', value: 'forever' },
  { title: '7 дней', value: '7' },
  { title: '14 дней', value: '14' },
  { title: '30 дней', value: '30' },
  { title: '60 дней', value: '60' },
  { title: '90 дней', value: '90' },
  { title: 'Указать дату вручную', value: 'custom_date' },
]

const headers = [
  { title: 'ID', key: 'id', sortable: true, width: 70 },
  { title: 'Пользователь', key: 'name', sortable: true },
  { title: 'Роль', key: 'role', sortable: false, width: 130 },
  { title: 'Статус', key: 'auth_status', sortable: false, width: 140 },
  { title: 'Тариф', key: 'billing_plan', sortable: false, width: 170 },
  { title: 'Подписка', key: 'billing_status', sortable: false, width: 120 },
  { title: 'Действует до', key: 'billing_period_end', sortable: false, width: 150 },
  { title: 'Log-only', key: 'billing_gate_events', sortable: false, width: 110 },
  { title: 'Регистрация', key: 'created_at', sortable: true, width: 140 },
  { title: 'Последний вход', key: 'last_login_at', sortable: true, width: 140 },
  { title: 'ИИ запросов', key: 'ai_requests_count', sortable: true, width: 110 },
  { title: '', key: 'actions', sortable: false, width: 80 },
]

// ----- Computed -----
const metricCards = computed(() => [
  { label: 'Всего', value: metrics.value.total, color: '', active: false },
  { label: 'Активные', value: metrics.value.active, color: 'text-success', active: filterStatus.value === 'active' },
  { label: 'Заблокированные', value: metrics.value.blocked, color: 'text-warning', active: filterStatus.value === 'blocked' },
  { label: 'Удаленные', value: metrics.value.deleted, color: 'text-error', active: filterStatus.value === 'deleted' },
  { label: 'ИИ запросов', value: metrics.value.total_ai_requests, color: 'text-info', active: false },
])

const aiStatCards = computed(() => {
  const s = userDetail.value?.ai_stats
  if (!s) return []
  const rate = s.total_requests > 0 ? ((s.successful_requests / s.total_requests) * 100).toFixed(1) : '0'
  return [
    { label: 'Всего запросов', value: s.total_requests || 0 },
    { label: 'Успешность', value: `${rate}%` },
    { label: 'Токенов', value: formatNumber(s.total_tokens || 0) },
    { label: 'Стоимость', value: `$${Number(s.total_cost || 0).toFixed(4)}` },
  ]
})

const billingPlanItems = computed(() => billingPlans.value
  .filter((plan) => plan.is_active !== false)
  .map((plan) => ({
    title: `${plan.name || plan.code} (${plan.code})`,
    value: plan.code,
  })))

const billingDetailPlanName = computed(() => {
  const subscription = userDetail.value?.billing?.subscription
  return subscription?.plan_name || subscription?.plan_code || userDetail.value?.billing?.effective_plan_code || '—'
})

// ----- Methods -----
function notify(text: string, color = 'success') {
  snackbarText.value = text
  snackbarColor.value = color
  snackbar.value = true
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  return new Date(date).toLocaleString('ru-RU', {
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

function formatNumber(n: number): string {
  if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M'
  if (n >= 1000) return (n / 1000).toFixed(1) + 'K'
  return String(n)
}

function initials(name: string): string {
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2) || '?'
}

function getStatusColor(user: UserItem): string {
  if (user.deleted_at) return 'grey'
  if (user.auth_status === 'blocked') return 'warning'
  return 'primary'
}

function getStatusChipColor(user: UserItem): string {
  if (user.deleted_at) return 'error'
  if (user.auth_status === 'blocked') return 'warning'
  return 'success'
}

function getStatusLabel(user: UserItem): string {
  if (user.deleted_at) return 'Удален'
  if (user.auth_status === 'blocked') return 'Заблокирован'
  return 'Активен'
}

function getRoleColor(role: string): string {
  if (role === 'superadmin') return 'error'
  if (role === 'admin') return 'primary'
  return 'default'
}

function getRoleLabel(role: string): string {
  const map: Record<string, string> = { user: 'Пользователь', admin: 'Администратор', superadmin: 'Суперадмин' }
  return map[role] || role
}

function billingPlanLabel(user: UserItem): string {
  return user.billing?.plan_name || user.billing?.plan_code || '—'
}

function billingStatusLabel(status?: string | null): string {
  const map: Record<string, string> = {
    active: 'Активна',
    trialing: 'Тестовая',
    canceled: 'Отменена',
    replaced: 'Заменена',
    expired: 'Истекла',
  }
  return status ? (map[status] || status) : 'Fallback'
}

function billingStatusColor(status?: string | null): string {
  if (status === 'active') return 'success'
  if (status === 'trialing') return 'info'
  if (status === 'canceled' || status === 'expired') return 'warning'
  if (status === 'replaced') return 'grey'
  return 'default'
}

function billingActionLabel(action: string): string {
  const map: Record<string, string> = {
    'project.create': 'Создание проекта',
    'projects.create': 'Создание проекта',
    'pdf.generate': 'PDF',
    'evidence.run': 'Проверка цен',
    'chrome.capture': 'Скриншот из расширения',
  }
  return map[action] || action
}

const depLabels: Record<string, string> = {
  projects: 'Проекты', operations: 'Операции', suppliers: 'Поставщики',
  ai_logs: 'Логи ИИ', import_sessions: 'Импорты', ideas: 'Идеи',
  idea_votes: 'Голоса', idea_comments: 'Комментарии',
  trusted_devices: 'Устройства', social_accounts: 'OAuth аккаунты',
  tokens: 'API токены', notifications: 'Уведомления',
  user_settings: 'Настройки', user_material_library: 'Библиотека материалов',
  operation_groups: 'Группы операций', chrome_ext_logs: 'Логи расширения',
  detail_types: 'Типы деталей',
  revision_runs: 'Запуски ревизий', collect_profiles: 'Профили сбора',
  price_import_sessions: 'Импорт прайсов', project_revisions: 'Ревизии проектов',
}

function depLabel(key: string): string {
  return depLabels[key] || key
}

const nullifyDeps = ['project_revisions', 'ai_logs']

function depStrategy(key: string): string {
  if (key === 'ai_logs') return 'Анонимизация'
  if (nullifyDeps.includes(key)) return 'Отвязка (SET NULL)'
  return 'Каскадное удаление'
}

function depStrategyColor(key: string): string {
  if (key === 'ai_logs') return 'info'
  if (nullifyDeps.includes(key)) return 'warning'
  return 'error'
}

function auditActionColor(action: string): string {
  const map: Record<string, string> = {
    view: 'default', block: 'warning', unblock: 'success',
    soft_delete: 'error', hard_delete: 'error', restore: 'info', role_change: 'primary',
  }
  return map[action] || 'default'
}

function auditActionLabel(action: string): string {
  const map: Record<string, string> = {
    view: 'Просмотр', block: 'Блокировка', unblock: 'Разблокировка',
    soft_delete: 'Удаление', hard_delete: 'Полное удаление', restore: 'Восстановление', role_change: 'Смена роли',
  }
  return map[action] || action
}

function onTableOptions(options: any) {
  if (options.sortBy?.length) {
    const s = options.sortBy[0]
    sortBy.value = s.key
    sortDir.value = s.order
  }
}

// ----- API Calls -----
async function loadUsers() {
  loading.value = true
  try {
    const params: Record<string, any> = {
      page: page.value,
      per_page: perPage.value,
      sort_by: sortBy.value,
      sort_dir: sortDir.value,
    }
    if (search.value) params.search = search.value
    if (filterStatus.value) params.status = filterStatus.value
    if (filterRole.value) params.role = filterRole.value

    const { data } = await api.get('/api/admin/system/users', { params })
    users.value = data.users || []
    pagination.value = data.pagination || { total: 0, per_page: 20, current_page: 1, last_page: 1 }
    metrics.value = data.metrics || metrics.value
  } catch (err: any) {
    console.error('Failed to load users:', err)
    notify('Ошибка загрузки пользователей', 'error')
  } finally {
    loading.value = false
  }
}

async function openUserCard(user: UserItem) {
  selectedUser.value = user
  userDetail.value = null
  userDependencies.value = null
  showUserCard.value = true
  cardTab.value = 'info'

  try {
    const { data } = await api.get(`/api/admin/system/users/${user.id}`)
    selectedUser.value = data.user
    userDetail.value = data
  } catch (err: any) {
    notify('Ошибка загрузки карточки', 'error')
  }
}

async function loadBillingPlansForAssignment() {
  if (billingPlans.value.length > 0) return

  billingPlansLoading.value = true
  try {
    const data = await getAdminBillingPlans()
    billingPlans.value = data.data || []
  } catch (err: any) {
    notify(err.response?.data?.message || 'Не удалось загрузить тарифы', 'error')
  } finally {
    billingPlansLoading.value = false
  }
}

async function openAssignPlanDialog(user: UserItem) {
  actionTarget.value = user
  assignPlanForm.value = {
    plan_code: user.billing?.plan_code || '',
    term: 'forever',
    ends_at: '',
    reason: '',
  }
  showAssignPlanDialog.value = true
  await loadBillingPlansForAssignment()
}

async function openBillingStats(user: UserItem) {
  await openUserCard(user)
  cardTab.value = 'billing'
}

function openGateEventsForUser(user: UserItem) {
  router.push({
    path: '/admin/billing/gate-events',
    query: { user_id: String(user.id) },
  })
}

function resolveAssignPayload(): AssignBillingSubscriptionPayload {
  const payload: AssignBillingSubscriptionPayload = {
    plan_code: assignPlanForm.value.plan_code,
    period: 'custom',
    starts_at: null,
    ends_at: null,
    reason: assignPlanForm.value.reason || null,
  }

  if (assignPlanForm.value.term === 'forever') {
    return payload
  }

  if (assignPlanForm.value.term === 'custom_date') {
    payload.ends_at = assignPlanForm.value.ends_at
      ? new Date(`${assignPlanForm.value.ends_at}T23:59:59`).toISOString()
      : null
    return payload
  }

  const days = Number(assignPlanForm.value.term)
  if (Number.isFinite(days) && days > 0) {
    const date = new Date()
    date.setDate(date.getDate() + days)
    payload.ends_at = date.toISOString()
  }

  return payload
}

async function confirmAssignPlan() {
  if (!actionTarget.value || !assignPlanForm.value.plan_code) return

  assignPlanLoading.value = true
  try {
    await assignAdminBillingUserSubscription(actionTarget.value.id, resolveAssignPayload())
    notify('Тариф назначен')
    showAssignPlanDialog.value = false
    await loadUsers()
    if (showUserCard.value && selectedUser.value?.id === actionTarget.value.id) {
      await openUserCard(actionTarget.value)
      cardTab.value = 'billing'
    }
  } catch (err: any) {
    const errors = err.response?.data?.errors
    const message = errors ? Object.values(errors).flat().join('; ') : err.response?.data?.message
    notify(message || 'Не удалось назначить тариф', 'error')
  } finally {
    assignPlanLoading.value = false
  }
}

async function loadDependencies(userId: number) {
  loadingDeps.value = true
  try {
    const { data } = await api.get(`/api/admin/system/users/${userId}/dependencies`)
    userDependencies.value = data
  } catch (err: any) {
    console.error('Failed to load deps:', err)
  } finally {
    loadingDeps.value = false
  }
}

watch(cardTab, (tab) => {
  if (tab === 'deps' && selectedUser.value && !userDependencies.value) {
    loadDependencies(selectedUser.value.id)
  }
})

// ----- Block / Unblock -----
function openBlockDialog(user: UserItem) {
  actionTarget.value = user
  actionReason.value = ''
  showBlockDialog.value = true
}

function openUnblockDialog(user: UserItem) {
  actionTarget.value = user
  actionReason.value = ''
  showUnblockDialog.value = true
}

async function confirmBlock() {
  if (!actionTarget.value || !actionReason.value) return
  actionLoading.value = true
  try {
    await api.post(`/api/admin/system/users/${actionTarget.value.id}/block`, { reason: actionReason.value })
    notify('Пользователь заблокирован')
    showBlockDialog.value = false
    showUserCard.value = false
    loadUsers()
  } catch (err: any) {
    notify(err.response?.data?.error || 'Ошибка блокировки', 'error')
  } finally {
    actionLoading.value = false
  }
}

async function confirmUnblock() {
  if (!actionTarget.value) return
  actionLoading.value = true
  try {
    await api.post(`/api/admin/system/users/${actionTarget.value.id}/unblock`, { reason: actionReason.value })
    notify('Пользователь разблокирован')
    showUnblockDialog.value = false
    showUserCard.value = false
    loadUsers()
  } catch (err: any) {
    notify(err.response?.data?.error || 'Ошибка разблокировки', 'error')
  } finally {
    actionLoading.value = false
  }
}

// ----- Delete -----
function openDeleteDialog(user: UserItem, mode: 'soft' | 'hard') {
  actionTarget.value = user
  deleteMode.value = mode
  actionReason.value = ''
  deleteConfirmation.value = ''
  deleteDependencies.value = null
  showDeleteDialog.value = true

  // Load dependencies preview
  loadingDeleteDeps.value = true
  api.get(`/api/admin/system/users/${user.id}/dependencies`)
    .then(({ data }) => { deleteDependencies.value = data })
    .catch(() => {})
    .finally(() => { loadingDeleteDeps.value = false })
}

async function confirmDelete() {
  if (!actionTarget.value) return
  actionLoading.value = true
  try {
    if (deleteMode.value === 'hard') {
      await api.delete(`/api/admin/system/users/${actionTarget.value.id}/force`, { data: { reason: actionReason.value } })
      notify('Пользователь полностью удален')
    } else {
      await api.delete(`/api/admin/system/users/${actionTarget.value.id}`, { data: { reason: actionReason.value } })
      notify('Пользователь деактивирован')
    }
    showDeleteDialog.value = false
    showUserCard.value = false
    loadUsers()
  } catch (err: any) {
    notify(err.response?.data?.error || 'Ошибка удаления', 'error')
  } finally {
    actionLoading.value = false
  }
}

async function restoreUser(user: UserItem) {
  try {
    await api.post(`/api/admin/system/users/${user.id}/restore`)
    notify('Пользователь восстановлен')
    loadUsers()
  } catch (err: any) {
    notify(err.response?.data?.error || 'Ошибка восстановления', 'error')
  }
}

// ----- Role Change -----
async function confirmRoleChange() {
  if (!selectedUser.value) return
  actionLoading.value = true
  try {
    const { data } = await api.put(`/api/admin/system/users/${selectedUser.value.id}/role`, { role: newRole.value })
    selectedUser.value = data.user
    notify('Роль изменена')
    showRoleDialog.value = false
    loadUsers()
  } catch (err: any) {
    notify(err.response?.data?.error || 'Ошибка изменения роли', 'error')
  } finally {
    actionLoading.value = false
  }
}

// ----- Bulk Actions -----
async function confirmBulkAction() {
  if (!bulkAction.value || selectedUsers.value.length === 0) return
  actionLoading.value = true
  try {
    const { data } = await api.post('/api/admin/system/users/bulk-action', {
      action: bulkAction.value,
      user_ids: selectedUsers.value,
      reason: actionReason.value || 'Массовая операция',
    })
    notify(data.message)
    showBulkActionDialog.value = false
    selectedUsers.value = []
    loadUsers()
  } catch (err: any) {
    notify(err.response?.data?.error || 'Ошибка массовой операции', 'error')
  } finally {
    actionLoading.value = false
  }
}

// ----- Init -----
onMounted(() => {
  loadUsers()
})
</script>

<style scoped>
.admin-users {
  width: 100%;
}
</style>
