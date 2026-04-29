<template>
  <PageContainer>
    <PageHeader
      title="Биллинг"
      subtitle="Скрытый административный модуль для тарифов, подписок, платежей и log-only лимитов."
    >
      <template #actions>
        <v-btn color="primary" variant="tonal" prepend-icon="mdi-refresh" :loading="loading" @click="loadAll">
          Обновить
        </v-btn>
      </template>
    </PageHeader>

    <SectionCard
      title="Текущее состояние"
      variant="flat"
      class="mb-4 billing-status-card"
      :loading="billingCapabilities.loading && !billingCapabilities.loaded"
    >
      <v-alert
        v-if="billingCapabilities.error"
        type="warning"
        variant="tonal"
        density="compact"
        class="mb-4"
      >
        Не удалось получить состояние биллинга от backend. Проверьте API /api/billing/capabilities и авторизацию.
      </v-alert>

      <template v-else>
        <div class="billing-status-hero">
          <div>
            <div class="billing-status-hero__eyebrow">Режим биллинга</div>
            <div class="billing-status-hero__title">{{ billingModeLabel }}</div>
            <p class="billing-status-hero__description">{{ billingModeDescription }}</p>
          </div>
          <StatusChip
            :status="billingCapabilities.billingEnabled ? 'enabled' : 'disabled'"
            :color="billingCapabilities.billingEnabled ? 'success' : 'grey'"
            :label="billingCapabilities.billingEnabled ? 'Включён' : 'Выключен'"
          />
        </div>

        <div class="billing-status-grid">
          <div
            v-for="item in billingStatusItems"
            :key="item.label"
            class="billing-status-item"
          >
            <div class="billing-status-item__top">
              <span>{{ item.label }}</span>
              <StatusChip :status="item.status" :color="item.color" :label="item.badge" size="x-small" />
            </div>
            <div class="billing-status-item__value">{{ item.value }}</div>
          </div>
        </div>
      </template>
    </SectionCard>

    <SectionCard
      v-if="billingCapabilities.loaded && !billingCapabilities.adminUiEnabled"
      title="Биллинг выключен"
      variant="outlined"
      class="mb-4"
    >
      <p class="text-body-2 text-medium-emphasis mb-0">
        Административные действия биллинга недоступны в текущем режиме. Измените BILLING_ENABLED и BILLING_MODE на backend,
        затем очистите Laravel config cache.
      </p>
    </SectionCard>

    <template v-else>
    <AppTabs
      :model-value="activeBillingTab"
      :items="billingTabs"
      density="comfortable"
      class="billing-tabs mb-4"
      @update:model-value="goBillingTab"
    />

    <template v-if="activeBillingTab === 'overview'">
    <SectionCard class="mb-4" variant="outlined">
      <v-row dense>
        <v-col cols="12" md="3" sm="6">
          <v-text-field
            v-model="filters.period_start"
            label="Период с"
            type="date"
            density="compact"
            variant="outlined"
            hide-details
          />
        </v-col>
        <v-col cols="12" md="3" sm="6">
          <v-text-field
            v-model="filters.period_end"
            label="Период по"
            type="date"
            density="compact"
            variant="outlined"
            hide-details
          />
        </v-col>
        <v-col cols="12" md="3" sm="6">
          <v-text-field
            v-model="filters.user_id"
            label="User ID"
            density="compact"
            variant="outlined"
            hide-details
          />
        </v-col>
        <v-col cols="12" md="3" sm="6">
          <v-text-field
            v-model="filters.metric_code"
            label="Metric code"
            density="compact"
            variant="outlined"
            hide-details
          />
        </v-col>
      </v-row>
      <div class="d-flex justify-end mt-3">
        <v-btn color="primary" variant="tonal" prepend-icon="mdi-refresh" :loading="loading" @click="loadAll">
          Обновить
        </v-btn>
      </div>
    </SectionCard>

    <v-alert
      v-if="error"
      type="error"
      variant="tonal"
      density="comfortable"
      class="mb-4"
    >
      {{ error }}
    </v-alert>

    <v-row class="mb-4">
      <v-col
        v-for="card in statCards"
        :key="card.label"
        cols="12"
        sm="6"
        md="4"
        lg="2"
      >
        <SectionCard variant="outlined" class="usage-stat">
          <div class="usage-stat__icon">
            <v-icon :icon="card.icon" size="22" />
          </div>
          <div class="usage-stat__value">{{ card.value }}</div>
          <div class="usage-stat__label">{{ card.label }}</div>
        </SectionCard>
      </v-col>
    </v-row>

    <SectionCard title="Состояние монетизации" variant="flat" class="mb-4" :loading="loading">
      <v-row dense>
        <v-col
          v-for="item in dashboardCards"
          :key="item.title"
          cols="12"
          sm="6"
          md="4"
          lg="3"
        >
          <SectionCard variant="outlined" class="billing-dashboard-card">
            <div class="billing-dashboard-card__top">
              <v-icon :icon="item.icon" size="22" />
              <StatusChip :status="item.status" :color="item.color" :label="item.statusLabel" size="x-small" />
            </div>
            <div class="billing-dashboard-card__value">{{ item.value }}</div>
            <div class="billing-dashboard-card__label">{{ item.title }}</div>
          </SectionCard>
        </v-col>
      </v-row>
    </SectionCard>

    <SectionCard
      v-if="userOverview"
      class="mb-4"
      title="Пользователь"
      variant="outlined"
    >
      <v-row dense>
        <v-col cols="12" md="3">
          <div class="text-caption text-medium-emphasis">User</div>
          <div class="text-body-2">{{ userOverview.user?.name || '—' }}</div>
          <div class="text-caption text-medium-emphasis">{{ userOverview.user?.email || '—' }}</div>
        </v-col>
        <v-col cols="12" md="3">
          <div class="text-caption text-medium-emphasis">Plan</div>
          <div class="text-body-2">{{ userOverview.billing?.plan_code || '—' }}</div>
          <div class="text-caption text-medium-emphasis">{{ userOverview.billing?.subscription_status || '—' }}</div>
        </v-col>
        <v-col cols="12" md="3">
          <div class="text-caption text-medium-emphasis">Projects</div>
          <div class="text-body-2">
            {{ userOverview.projects?.active ?? 0 }} active / {{ userOverview.projects?.total ?? 0 }} total
          </div>
        </v-col>
        <v-col cols="12" md="3">
          <div class="text-caption text-medium-emphasis">Storage</div>
          <div class="text-body-2">{{ formatBytes(userOverview.storage?.bytes_uploaded || 0) }}</div>
          <div class="text-caption text-medium-emphasis">legacy storage not included</div>
        </v-col>
      </v-row>
    </SectionCard>

    <v-row>
      <v-col cols="12" lg="5">
        <SectionCard title="Top metrics" variant="outlined" :loading="loading">
          <v-data-table
            :headers="topMetricHeaders"
            :items="overview?.top_metrics || []"
            density="compact"
            :items-per-page="10"
          >
            <template #item.quantity="{ item }">
              {{ formatNumber(eventQuantity(item)) }}
            </template>
            <template #item.period>
              {{ overview?.period?.start }} — {{ overview?.period?.end }}
            </template>
          </v-data-table>
        </SectionCard>
      </v-col>

      <v-col cols="12" lg="7">
        <SectionCard title="Recent events" variant="outlined" :loading="loading">
          <v-data-table
            :headers="eventHeaders"
            :items="events?.items || overview?.recent_events || []"
            density="compact"
            :items-per-page="10"
          >
            <template #item.occurred_at="{ item }">
              <span class="text-no-wrap">{{ formatDateTime(eventOccurredAt(item)) }}</span>
            </template>
            <template #item.user="{ item }">
              {{ eventUserLabel(item) }}
            </template>
            <template #item.project="{ item }">
              {{ eventProjectLabel(item) }}
            </template>
            <template #item.quantity="{ item }">
              {{ formatNumber(eventQuantity(item)) }} {{ eventUnit(item) }}
            </template>
          </v-data-table>
        </SectionCard>
      </v-col>
    </v-row>
    </template>

    <SectionCard
      v-if="activeBillingTab === 'plans'"
      class="mt-4 mb-4"
      title="Тарифы"
      variant="outlined"
      :loading="plansLoading"
    >
      <v-alert type="info" variant="tonal" density="compact" class="mb-4">
        {{ adminPlansNotice }}
      </v-alert>

      <div class="d-flex justify-end mb-3">
        <v-btn color="primary" variant="tonal" prepend-icon="mdi-plus" @click="openCreatePlan">
          Создать тариф
        </v-btn>
      </div>

      <AppDataTableShell
        :error="plansError"
        :empty="!plansLoading && !billingPlans.length"
        empty-title="Тарифов нет"
        empty-description="Создайте первый тестовый тариф для скрытой админки."
        empty-icon="mdi-credit-card-plus-outline"
      >
      <v-data-table
        :headers="planHeaders"
        :items="billingPlans"
        density="compact"
        :items-per-page="10"
      >
        <template #item.price="{ item }">
          {{ planPriceLabel(item) }}
        </template>
        <template #item.period="{ item }">
          {{ planMetadataValue(item, 'billing_period') || '—' }}
        </template>
        <template #item.is_active="{ item }">
          <StatusChip :status="item.is_active ? 'active' : 'inactive'" :label="item.is_active ? 'Active' : 'Inactive'" />
        </template>
        <template #item.public="{ item }">
          <StatusChip :color="planPublic(item) ? 'success' : 'grey'" :label="planPublic(item) ? 'Публичный' : 'Скрыт'" />
        </template>
        <template #item.system="{ item }">
          <StatusChip :color="planFlag(item, 'system') ? 'error' : 'grey'" :label="planFlag(item, 'system') ? 'System' : 'No'" />
        </template>
        <template #item.sandbox="{ item }">
          <StatusChip :color="planFlag(item, 'sandbox') ? 'info' : 'grey'" :label="planFlag(item, 'sandbox') ? 'Sandbox' : 'No'" />
        </template>
        <template #item.limits="{ item }">
          {{ limitsSummary(item) }}
        </template>
        <template #item.actions="{ item }">
          <AppRowActions
            dense
            :actions="planRowActions"
            @action="(action) => handlePlanRowAction(action, item)"
          />
        </template>
      </v-data-table>
      </AppDataTableShell>
    </SectionCard>

    <SectionCard
      v-if="activeBillingTab === 'subscriptions'"
      class="mt-4 mb-4"
      title="Управление подпиской пользователя"
      variant="outlined"
      :loading="subscriptionLoading"
    >
      <v-alert type="info" variant="tonal" density="compact" class="mb-4">
        {{ adminSubscriptionsNotice }}
      </v-alert>

      <v-row dense>
        <v-col cols="12" md="4">
          <v-text-field
            v-model="subscriptionUserId"
            label="User ID / email"
            density="compact"
            variant="outlined"
            hide-details="auto"
          />
        </v-col>
        <v-col cols="12" md="3" class="d-flex align-center">
          <v-btn color="primary" variant="tonal" prepend-icon="mdi-account-search" :loading="subscriptionLoading" @click="loadUserSubscription">
            Загрузить
          </v-btn>
        </v-col>
      </v-row>

      <v-alert
        v-if="subscriptionError"
        type="error"
        variant="tonal"
        density="comfortable"
        class="mt-4 mb-0"
      >
        {{ subscriptionError }}
      </v-alert>
    </SectionCard>

    <SectionCard
      v-if="activeBillingTab === 'subscriptions' && subscriptionUser"
      class="mb-4"
      title="Текущая подписка"
      variant="outlined"
      :loading="subscriptionLoading"
    >
      <v-row dense>
        <v-col cols="12" md="3">
          <div class="text-caption text-medium-emphasis">Пользователь</div>
          <div class="text-body-2">{{ subscriptionUser.name || '—' }}</div>
          <div class="text-caption text-medium-emphasis">{{ subscriptionUser.email || '—' }}</div>
        </v-col>
        <v-col cols="12" md="2">
          <div class="text-caption text-medium-emphasis">Текущий тариф</div>
          <div class="text-body-2">{{ currentSubscription?.plan_code || subscriptionData?.effective_plan_code || 'legacy_unlimited' }}</div>
        </v-col>
        <v-col cols="12" md="2">
          <div class="text-caption text-medium-emphasis">Статус</div>
          <StatusChip
            :status="currentSubscription?.status || 'none'"
            :color="subscriptionStatusTone(currentSubscription?.status)"
            :label="subscriptionStatusLabel(currentSubscription?.status)"
          />
        </v-col>
        <v-col cols="12" md="2">
          <div class="text-caption text-medium-emphasis">Начало периода</div>
          <div class="text-body-2">{{ formatDateTime(currentSubscription?.current_period_start) }}</div>
        </v-col>
        <v-col cols="12" md="2">
          <div class="text-caption text-medium-emphasis">Конец периода</div>
          <div class="text-body-2">{{ currentSubscription?.current_period_end ? formatDateTime(currentSubscription.current_period_end) : 'Без срока' }}</div>
        </v-col>
        <v-col cols="12" md="1">
          <div class="text-caption text-medium-emphasis">Источник</div>
          <div class="text-body-2">{{ currentSubscription?.source || 'legacy' }}</div>
        </v-col>
      </v-row>
    </SectionCard>

    <v-row v-if="activeBillingTab === 'subscriptions' && subscriptionUser" class="mb-4">
      <v-col cols="12" lg="7">
        <SectionCard title="Назначить тариф" variant="outlined">
          <h3 class="drawer-section-title mb-2">Основное</h3>
          <v-row dense>
            <v-col cols="12" md="4">
              <v-select
                v-model="assignForm.plan_code"
                :items="billingPlanItems"
                label="Тариф"
                density="compact"
                variant="outlined"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="12" md="3">
              <v-select
                v-model="assignForm.period"
                :items="subscriptionPeriodItems"
                label="Период"
                density="compact"
                variant="outlined"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="12" md="5">
              <v-text-field
                v-model="assignForm.reason"
                label="Причина"
                density="compact"
                variant="outlined"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="assignForm.starts_at"
                label="Дата начала"
                type="datetime-local"
                density="compact"
                variant="outlined"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="assignForm.ends_at"
                label="Дата окончания"
                type="datetime-local"
                density="compact"
                variant="outlined"
                hide-details="auto"
              />
            </v-col>
          </v-row>
          <div class="d-flex justify-end mt-3">
            <v-btn color="primary" :loading="subscriptionActionLoading" :disabled="!assignForm.plan_code" @click="assignSubscription">
              Назначить
            </v-btn>
          </div>
        </SectionCard>
      </v-col>

      <v-col cols="12" lg="5">
        <SectionCard title="Продление и действия" variant="outlined">
          <v-row dense>
            <v-col cols="6">
              <v-text-field
                v-model.number="extendForm.months"
                label="Месяцев"
                type="number"
                min="0"
                max="36"
                density="compact"
                variant="outlined"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="6">
              <v-text-field
                v-model.number="extendForm.days"
                label="Дней"
                type="number"
                min="0"
                max="365"
                density="compact"
                variant="outlined"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="12">
              <v-text-field
                v-model="extendForm.reason"
                label="Причина продления"
                density="compact"
                variant="outlined"
                hide-details="auto"
              />
            </v-col>
          </v-row>
          <div class="d-flex flex-wrap justify-end ga-2 mt-3">
            <v-btn variant="tonal" color="primary" :loading="subscriptionActionLoading" @click="extendSubscription">
              Продлить
            </v-btn>
            <v-btn variant="tonal" color="warning" :loading="subscriptionActionLoading" @click="confirmSubscriptionAction('cancel')">
              Отменить подписку
            </v-btn>
            <v-btn variant="tonal" color="info" :loading="subscriptionActionLoading" @click="confirmSubscriptionAction('legacy')">
              Вернуть legacy_unlimited
            </v-btn>
          </div>
        </SectionCard>
      </v-col>
    </v-row>

    <SectionCard
      v-if="activeBillingTab === 'subscriptions' && subscriptionUser"
      title="История подписки"
      variant="outlined"
      class="mb-4"
      :loading="subscriptionLoading"
    >
      <v-data-table
        :headers="subscriptionHistoryHeaders"
        :items="subscriptionHistory"
        density="compact"
        :items-per-page="10"
      >
        <template #item.created_at="{ item }">
          <span class="text-no-wrap">{{ formatDateTime(item.created_at) }}</span>
        </template>
        <template #item.admin_user="{ item }">
          {{ item.admin_user?.email || item.admin_user?.name || '—' }}
        </template>
        <template #item.period="{ item }">
          {{ formatDateTime(item.old_period_end) }} → {{ item.new_period_end ? formatDateTime(item.new_period_end) : 'Без срока' }}
        </template>
        <template #item.new_status="{ item }">
          <StatusChip
            :status="item.new_status || 'none'"
            :color="subscriptionStatusTone(item.new_status)"
            :label="subscriptionStatusLabel(item.new_status)"
          />
        </template>
      </v-data-table>
    </SectionCard>

    <template v-if="activeBillingTab === 'gate-events'">
    <SectionCard
      class="mt-4 mb-4"
      title="Log-only лимиты"
      variant="outlined"
      :loading="gateLoading"
    >
      <v-alert type="info" variant="tonal" density="compact" class="mb-4">
        Log-only лимиты — это события, где система только записывает возможное превышение, но не блокирует пользователя.
      </v-alert>

      <v-row dense>
        <v-col cols="12" md="3" sm="6">
          <v-text-field
            v-model="gateFilters.user_id"
            label="Пользователь / User ID"
            type="number"
            min="1"
            density="compact"
            variant="outlined"
            hide-details
          />
        </v-col>
        <v-col cols="12" md="3" sm="6">
          <v-text-field
            v-model="gateFilters.capability"
            label="Capability"
            density="compact"
            variant="outlined"
            hide-details
          />
        </v-col>
        <v-col cols="12" md="2" sm="6">
          <v-text-field
            v-model="gateFilters.date_from"
            label="Дата с"
            type="date"
            density="compact"
            variant="outlined"
            hide-details
          />
        </v-col>
        <v-col cols="12" md="2" sm="6">
          <v-text-field
            v-model="gateFilters.date_to"
            label="Дата по"
            type="date"
            density="compact"
            variant="outlined"
            hide-details
          />
        </v-col>
        <v-col cols="12" md="2" class="d-flex align-center">
          <v-switch
            v-model="gateFilters.only_would_block"
            label="Только would_block"
            color="primary"
            density="compact"
            hide-details
          />
        </v-col>
      </v-row>

      <div class="d-flex justify-end mt-3">
        <v-btn color="primary" variant="tonal" prepend-icon="mdi-filter-outline" :loading="gateLoading" @click="applyGateFilters">
          Применить
        </v-btn>
      </div>
    </SectionCard>

    <v-row class="mb-4">
      <v-col
        v-for="card in gateStatCards"
        :key="card.label"
        cols="12"
        sm="6"
        md="3"
      >
        <SectionCard variant="outlined" class="gate-stat">
          <div class="usage-stat__icon">
            <v-icon :icon="card.icon" size="22" />
          </div>
          <div class="usage-stat__value gate-stat__value">{{ card.value }}</div>
          <div class="usage-stat__label">{{ card.label }}</div>
        </SectionCard>
      </v-col>
    </v-row>

    <SectionCard title="События log-only лимитов" variant="outlined" :loading="gateLoading">
      <AppDataTableShell
        :error="gateError"
        :empty="!gateLoading && !gateEvents.data.length"
        empty-title="Событий нет"
        empty-description="За выбранный период billing_gate_events не найдены."
        empty-icon="mdi-shield-check-outline"
      >
      <v-data-table
        :headers="gateEventHeaders"
        :items="gateEvents.data"
        density="compact"
        :items-per-page="gateMeta.per_page"
      >
        <template #item.created_at="{ item }">
          <span class="text-no-wrap">{{ formatDateTime(gateEventCreatedAt(item)) }}</span>
        </template>
        <template #item.user="{ item }">
          {{ gateEventUserLabel(item) }}
        </template>
        <template #item.usage_limit="{ item }">
          <span class="text-no-wrap">{{ gateEventUsageLimit(item) }}</span>
        </template>
        <template #item.would_block="{ item }">
          <StatusChip
            :color="gateEventWouldBlock(item) ? 'warning' : 'success'"
            :label="gateEventWouldBlock(item) ? 'Был бы заблокирован' : 'Не превышено'"
          />
        </template>
        <template #item.enforced="{ item }">
          <StatusChip
            :color="gateEventEnforced(item) ? 'error' : 'info'"
            :label="gateEventEnforced(item) ? 'Блокировалось' : 'Не блокировалось'"
          />
        </template>
        <template #item.context="{ item }">
          <v-btn
            size="small"
            variant="text"
            color="primary"
            :disabled="!gateEventHasContext(item)"
            @click="openGateContext(item)"
          >
            Посмотреть context
          </v-btn>
        </template>
        <template #bottom>
          <div class="d-flex align-center justify-space-between flex-wrap ga-3 pa-3">
            <div class="text-caption text-medium-emphasis">
              Всего: {{ formatNumber(gateMeta.total) }}
            </div>
            <v-pagination
              v-model="gateFilters.page"
              :length="gateMeta.last_page"
              density="comfortable"
              rounded="circle"
              :total-visible="5"
              @update:model-value="loadGateEvents"
            />
          </div>
        </template>
      </v-data-table>
      </AppDataTableShell>
    </SectionCard>
    </template>

    <v-navigation-drawer
      v-model="gateContextDrawerModel"
      temporary
      location="right"
      :scrim="true"
      width="560"
      class="billing-overlay-drawer gate-context-drawer"
    >
      <div class="gate-context-drawer__header">
        <div>
          <div class="text-subtitle-1 font-weight-medium">Context события</div>
          <div class="text-caption text-medium-emphasis">
            Read-only данные billing_gate_events
          </div>
        </div>
        <v-btn icon="mdi-close" variant="text" size="small" @click="closeGateContext" />
      </div>
      <v-divider />
      <div class="gate-context-drawer__body">
        <div v-if="selectedGateEvent" class="detail-grid mb-3">
          <span>ID</span><strong>#{{ selectedGateEvent.id }}</strong>
          <span>User</span><strong>{{ gateEventUserLabel(selectedGateEvent) }}</strong>
          <span>Plan</span><strong>{{ selectedGateEvent.plan_code || '—' }}</strong>
          <span>Capability</span><code>{{ selectedGateEvent.capability }}</code>
          <span>Status</span><strong>{{ selectedGateEvent.would_block ? 'Был бы заблокирован' : 'Не превышено' }}</strong>
        </div>
        <div class="drawer-section-heading">
          <h3 class="drawer-section-title">Context JSON</h3>
          <v-btn size="x-small" variant="text" prepend-icon="mdi-content-copy" @click="copyJson(selectedGateEvent?.context_json || {})">
            Copy
          </v-btn>
        </div>
        <pre class="json-block">{{ prettyJson(selectedGateEvent?.context_json || {}) }}</pre>
      </div>
    </v-navigation-drawer>

    <v-navigation-drawer
      v-model="planDrawerModel"
      temporary
      location="right"
      :scrim="true"
      width="640"
      class="billing-overlay-drawer plan-drawer"
    >
      <div class="plan-drawer__header">
        <div>
          <div class="text-subtitle-1 font-weight-medium">{{ planDrawerTitle }}</div>
          <div class="text-caption text-medium-emphasis">Admin-only BillingPlan</div>
        </div>
        <v-btn icon="mdi-close" variant="text" size="small" @click="closePlanDrawer" />
      </div>
      <v-divider />

      <div class="plan-drawer__body">
        <v-alert
          v-if="selectedPlanIsProtected"
          type="warning"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          Системный тариф защищён. Его нельзя сделать платным, отключить или ограничить.
        </v-alert>

        <v-alert
          v-if="planSaveError"
          type="error"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          {{ planSaveError }}
        </v-alert>

        <v-form @submit.prevent="savePlan">
          <v-row dense>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="planForm.code"
                label="Code"
                density="compact"
                variant="outlined"
                :readonly="planDrawerMode !== 'create'"
                :disabled="planDrawerMode === 'view'"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="planForm.name"
                label="Name"
                density="compact"
                variant="outlined"
                :readonly="planDrawerMode === 'view'"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="12" md="4">
              <v-text-field
                v-model.number="planForm.price_minor"
                label="Price, коп."
                type="number"
                min="0"
                density="compact"
                variant="outlined"
                :readonly="planDrawerMode === 'view' || protectedDangerousFieldsDisabled"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="12" md="4">
              <v-select
                v-model="planForm.currency"
                :items="currencyItems"
                label="Currency"
                density="compact"
                variant="outlined"
                :readonly="planDrawerMode === 'view' || protectedDangerousFieldsDisabled"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="12" md="4">
              <v-select
                v-model="planForm.billing_period"
                :items="periodItems"
                label="Period"
                density="compact"
                variant="outlined"
                :readonly="planDrawerMode === 'view' || protectedDangerousFieldsDisabled"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="12" sm="6" md="3">
              <v-switch
                v-model="planForm.is_active"
                label="Active"
                color="primary"
                density="compact"
                :disabled="planDrawerMode === 'view' || protectedDangerousFieldsDisabled"
                hide-details
              />
            </v-col>
            <v-col cols="12" sm="6" md="3">
              <v-switch
                :model-value="!planForm.hidden"
                label="Показывать пользователям"
                color="primary"
                density="compact"
                :disabled="planDrawerMode === 'view'"
                @update:model-value="planForm.hidden = !$event"
                hide-details
              />
            </v-col>
            <v-col cols="12" sm="6" md="3">
              <v-switch
                v-model="planForm.system"
                label="System"
                color="primary"
                density="compact"
                :disabled="planDrawerMode === 'view' || protectedDangerousFieldsDisabled"
                hide-details
              />
            </v-col>
            <v-col cols="12" sm="6" md="3">
              <v-switch
                v-model="planForm.sandbox"
                label="Sandbox"
                color="primary"
                density="compact"
                :disabled="planDrawerMode === 'view' || protectedDangerousFieldsDisabled"
                hide-details
              />
            </v-col>
            <v-col cols="12" md="4">
              <v-text-field
                v-model.number="planForm.sort_order"
                label="Sort order"
                type="number"
                density="compact"
                variant="outlined"
                :readonly="planDrawerMode === 'view'"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="12">
              <h3 class="drawer-section-title mt-2 mb-2">Features</h3>
            </v-col>
            <v-col cols="12">
              <v-textarea
                v-model="planForm.description"
                label="Description"
                rows="3"
                auto-grow
                density="compact"
                variant="outlined"
                :readonly="planDrawerMode === 'view'"
                hide-details="auto"
              />
            </v-col>
            <v-col cols="12">
              <v-textarea
                v-model="planForm.featuresText"
                label="Features, по одной строке"
                rows="4"
                auto-grow
                density="compact"
                variant="outlined"
                :readonly="planDrawerMode === 'view'"
                hide-details="auto"
              />
            </v-col>
          </v-row>

          <h3 class="drawer-section-title mt-4 mb-2">Лимиты</h3>
          <div class="text-caption text-medium-emphasis mb-3">Пустое значение сохраняется как “Без ограничений”.</div>
          <v-row dense>
            <v-col
              v-for="limit in limitDefinitions"
              :key="limit.key"
              cols="12"
              md="6"
            >
              <v-text-field
                v-model="planForm.limits[limit.key]"
                :label="limit.label"
                type="number"
                min="0"
                placeholder="Без ограничений"
                density="compact"
                variant="outlined"
                :readonly="planDrawerMode === 'view' || protectedDangerousFieldsDisabled"
                hide-details="auto"
              />
            </v-col>
          </v-row>

          <div v-if="planDrawerMode !== 'view'" class="d-flex justify-end ga-2 mt-4">
            <v-btn variant="text" @click="closePlanDrawer">Отмена</v-btn>
            <v-btn color="primary" type="submit" :loading="savingPlan">
              Сохранить
            </v-btn>
          </div>
        </v-form>
      </div>
    </v-navigation-drawer>

    <v-dialog v-model="subscriptionConfirmOpen" max-width="460">
      <v-card>
        <v-card-title class="text-subtitle-1">{{ subscriptionConfirmTitle }}</v-card-title>
        <v-card-text>
          <v-textarea
            v-model="subscriptionConfirmReason"
            label="Причина"
            rows="3"
            auto-grow
            density="compact"
            variant="outlined"
            hide-details="auto"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="subscriptionConfirmOpen = false">Отмена</v-btn>
          <v-btn color="primary" :loading="subscriptionActionLoading" @click="runConfirmedSubscriptionAction">
            Подтвердить
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
    </template>
  </PageContainer>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  assignAdminBillingUserSubscription,
  cancelAdminBillingUserSubscription,
  createAdminBillingPlan,
  extendAdminBillingUserSubscription,
  getAdminBillingEvents,
  getAdminBillingGateEvents,
  getAdminBillingGateEventsSummary,
  getAdminBillingOverview,
  getAdminBillingUserSubscription,
  getAdminBillingUserSubscriptionHistory,
  getAdminBillingPlans,
  getAdminBillingUsage,
  getAdminBillingUserOverview,
  searchAdminBillingUsers,
  switchAdminBillingUserSubscriptionToLegacy,
  updateAdminBillingPlan,
  type AdminBillingFilters,
  type AdminBillingGateEventFilters,
  type AdminBillingPlanPayload,
} from '@/api/adminBilling'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import AppDataTableShell from '@/components/layout/AppDataTableShell.vue'
import AppRowActions, { type AppRowAction } from '@/components/layout/AppRowActions.vue'
import AppTabs, { type AppTabItem } from '@/components/layout/AppTabs.vue'
import StatusChip from '@/components/layout/StatusChip.vue'
import { useBillingCapabilitiesStore } from '@/stores/billingCapabilities'

type GateEventUser = {
  id: number
  name?: string | null
  email?: string | null
}

type BillingUserSearchResult = GateEventUser

type BillingGateEvent = {
  id: number
  user_id?: number | null
  user?: GateEventUser | null
  plan_code?: string | null
  capability: string
  limit_value?: number | null
  usage_value?: number | null
  would_block: boolean
  enforced: boolean
  context_json?: Record<string, unknown> | null
  created_at?: string | null
}

type BillingGateEventsResponse = {
  data: BillingGateEvent[]
  meta?: {
    current_page?: number
    per_page?: number
    total?: number
    last_page?: number
  }
}

type BillingGateEventsSummary = {
  total_events: number
  would_block_events: number
  enforced_events: number
  top_capabilities: Array<{ capability: string; count: number }>
  top_users: Array<{ user_id: number; name?: string | null; email?: string | null; count: number }>
}

type BillingPlan = {
  id: number
  code: string
  name: string
  is_active: boolean
  metadata_json?: {
    price_minor?: number | null
    currency?: string | null
    billing_period?: string | null
    hidden?: boolean
    sandbox?: boolean
    system?: boolean
    sort_order?: number | null
    description?: string | null
    features?: string[]
    limits?: Record<string, number | null>
  } | null
  created_at?: string | null
  updated_at?: string | null
}

type PlanDrawerMode = 'view' | 'create' | 'edit'

type BillingSubscriptionSummary = {
  id: number
  plan_code: string
  status: string
  current_period_start?: string | null
  current_period_end?: string | null
  source?: string | null
  created_at?: string | null
  updated_at?: string | null
}

type BillingSubscriptionData = {
  user?: GateEventUser | null
  subscription?: BillingSubscriptionSummary | null
  effective_plan_code?: string | null
}

type BillingSubscriptionEvent = {
  id: number
  subscription_id?: number | null
  event_type: string
  admin_user?: GateEventUser | null
  old_plan_code?: string | null
  new_plan_code?: string | null
  old_status?: string | null
  new_status?: string | null
  old_period_end?: string | null
  new_period_end?: string | null
  reason?: string | null
  created_at?: string | null
}

type SubscriptionConfirmAction = 'cancel' | 'legacy'
type BillingTab = 'overview' | 'plans' | 'subscriptions' | 'payments' | 'webhooks' | 'gate-events'

const route = useRoute()
const router = useRouter()
const billingCapabilities = useBillingCapabilitiesStore()

const billingTabs: Array<AppTabItem & { value: BillingTab; to: string }> = [
  { label: 'Обзор', value: 'overview', to: '/admin/billing' },
  { label: 'Тарифы', value: 'plans', to: '/admin/billing/plans' },
  { label: 'Подписки', value: 'subscriptions', to: '/admin/billing/subscriptions' },
  { label: 'Платежи', value: 'payments', to: '/admin/billing/payments' },
  { label: 'Webhook-события', value: 'webhooks', to: '/admin/billing/webhooks' },
  { label: 'Log-only лимиты', value: 'gate-events', to: '/admin/billing/gate-events' },
]

const activeBillingTab = computed<BillingTab>(() => {
  const path = route.path
  if (path.endsWith('/plans')) return 'plans'
  if (path.endsWith('/subscriptions')) return 'subscriptions'
  if (path.endsWith('/payments')) return 'payments'
  if (path.endsWith('/webhooks')) return 'webhooks'
  if (path.endsWith('/gate-events')) return 'gate-events'
  return 'overview'
})

const loading = ref(false)
const gateLoading = ref(false)
const plansLoading = ref(false)
const subscriptionLoading = ref(false)
const subscriptionActionLoading = ref(false)
const error = ref('')
const gateError = ref('')
const plansError = ref('')
const planSaveError = ref('')
const subscriptionError = ref('')
const overview = ref<any>(null)
const usage = ref<any>(null)
const events = ref<any>(null)
const userOverview = ref<any>(null)
const gateEvents = ref<BillingGateEventsResponse>({ data: [] })
const gateSummary = ref<BillingGateEventsSummary | null>(null)
const gateContextDrawerOpen = ref(false)
const selectedGateEvent = ref<BillingGateEvent | null>(null)
const billingPlans = ref<BillingPlan[]>([])
const planDrawerOpen = ref(false)
const planDrawerMode = ref<PlanDrawerMode>('view')
const selectedPlan = ref<BillingPlan | null>(null)
const savingPlan = ref(false)
const subscriptionUserId = ref('')
const subscriptionData = ref<BillingSubscriptionData | null>(null)
const subscriptionHistory = ref<BillingSubscriptionEvent[]>([])
const subscriptionConfirmOpen = ref(false)
const subscriptionConfirmAction = ref<SubscriptionConfirmAction>('cancel')
const subscriptionConfirmReason = ref('')

const filters = reactive<AdminBillingFilters>({
  period_start: '',
  period_end: '',
  user_id: '',
  metric_code: '',
  limit: 100,
})

const gateFilters = reactive<AdminBillingGateEventFilters & { only_would_block: boolean }>({
  user_id: '',
  capability: '',
  date_from: '',
  date_to: '',
  only_would_block: false,
  per_page: 25,
  page: 1,
})

const planForm = reactive({
  code: '',
  name: '',
  price_minor: 0,
  currency: 'RUB' as 'RUB',
  billing_period: 'month' as 'month' | 'year' | 'one_time' | 'custom',
  is_active: true,
  hidden: true,
  sandbox: false,
  system: false,
  sort_order: null as number | null,
  description: '',
  featuresText: '',
  limits: {} as Record<string, string | number | null>,
})

const assignForm = reactive({
  plan_code: '',
  period: 'month' as 'month' | 'year' | 'custom',
  starts_at: '',
  ends_at: '',
  reason: '',
})

const extendForm = reactive({
  months: 1,
  days: 0,
  reason: '',
})

const currencyItems = ['RUB'] as const
const periodItems = [
  { title: 'month', value: 'month' },
  { title: 'year', value: 'year' },
  { title: 'one_time', value: 'one_time' },
  { title: 'custom', value: 'custom' },
] as const

const subscriptionPeriodItems = [
  { title: 'month', value: 'month' },
  { title: 'year', value: 'year' },
  { title: 'custom', value: 'custom' },
] as const

const limitDefinitions = [
  { key: 'projects.max_active', label: 'Активные проекты' },
  { key: 'pdf_exports.monthly_limit', label: 'PDF в месяц' },
  { key: 'evidence_runs.monthly_limit', label: 'Evidence runs в месяц' },
  { key: 'chrome_captures.monthly_limit', label: 'Chrome-захваты в месяц' },
  { key: 'storage.max_mb', label: 'Хранилище, МБ' },
  { key: 'team_members.max_count', label: 'Пользователи команды' },
] as const

const topMetricHeaders = [
  { title: 'Metric', key: 'metric_code' },
  { title: 'Quantity', key: 'quantity', align: 'end' as const },
  { title: 'Period', key: 'period' },
] as const

const eventHeaders = [
  { title: 'Time', key: 'occurred_at' },
  { title: 'User', key: 'user' },
  { title: 'Project', key: 'project' },
  { title: 'Metric', key: 'metric_code' },
  { title: 'Feature', key: 'feature_code' },
  { title: 'Quantity', key: 'quantity', align: 'end' as const },
  { title: 'Source', key: 'source' },
] as const

const gateEventHeaders = [
  { title: 'Дата', key: 'created_at' },
  { title: 'Пользователь', key: 'user' },
  { title: 'Тариф', key: 'plan_code' },
  { title: 'Capability', key: 'capability' },
  { title: 'Usage / Limit', key: 'usage_limit', align: 'end' as const },
  { title: 'Would block', key: 'would_block' },
  { title: 'Enforced', key: 'enforced' },
  { title: 'Context', key: 'context', align: 'end' as const },
] as const

const planHeaders = [
  { title: 'Code', key: 'code' },
  { title: 'Name', key: 'name' },
  { title: 'Price', key: 'price', align: 'end' as const },
  { title: 'Period', key: 'period' },
  { title: 'Active', key: 'is_active' },
  { title: 'Public', key: 'public' },
  { title: 'System', key: 'system' },
  { title: 'Sandbox', key: 'sandbox' },
  { title: 'Limits summary', key: 'limits' },
  { title: 'Actions', key: 'actions', align: 'end' as const, sortable: false },
] as const

const subscriptionHistoryHeaders = [
  { title: 'Дата', key: 'created_at' },
  { title: 'Event', key: 'event_type' },
  { title: 'Admin', key: 'admin_user' },
  { title: 'Old plan', key: 'old_plan_code' },
  { title: 'New plan', key: 'new_plan_code' },
  { title: 'Status', key: 'new_status' },
  { title: 'Period end', key: 'period' },
  { title: 'Reason', key: 'reason' },
] as const

const planRowActions: AppRowAction[] = [
  { key: 'view', label: 'Просмотр', icon: 'mdi-eye-outline', color: 'primary' },
  { key: 'edit', label: 'Редактировать', icon: 'mdi-pencil-outline' },
]

const billingDiagnostics = computed(() => overview.value?.billing_diagnostics || null)
const billingModeLabel = computed(() => {
  const mode = billingCapabilities.billingMode

  if (mode === 'off') return 'Выключен'
  if (mode === 'admin_only') return 'Только администратор'
  if (mode === 'visible') return 'Видимый пользователям'
  if (mode === 'checkout') return 'Оплата включена'
  if (mode === 'enforced') return 'Полный режим'

  return billingCapabilities.loading ? 'Загрузка' : 'Неизвестный режим'
})

const billingModeDescription = computed(() => {
  const mode = billingCapabilities.billingMode

  if (billingCapabilities.loading && !billingCapabilities.loaded) {
    return 'Загружаем состояние биллинга из backend.'
  }

  if (mode === 'off') {
    return 'Биллинг выключен. Пользователи не видят раздел тарифов, оплата и ограничения отключены.'
  }

  if (mode === 'admin_only') {
    return 'Биллинг доступен только администратору для настройки и диагностики. Пользователи не видят раздел тарифов.'
  }

  if (mode === 'visible') {
    return 'Пользователи видят тариф и лимиты, но оплата ещё отключена.'
  }

  if (mode === 'checkout') {
    return 'Пользователи видят тарифы, оплата включена на уровне режима. Реальное действие checkout может быть disabled до отдельного подключения checkout action.'
  }

  if (mode === 'enforced') {
    return 'Полный режим: пользователи видят тарифы, оплата доступна, лимиты и ограничения применяются.'
  }

  return 'Состояние биллинга загружается.'
})

const billingStatusItems = computed(() => [
  {
    label: 'Состояние',
    value: billingCapabilities.billingEnabled ? 'Биллинг включён' : 'Биллинг выключен',
    badge: billingCapabilities.billingEnabled ? 'enabled' : 'disabled',
    status: billingCapabilities.billingEnabled ? 'enabled' : 'disabled',
    color: billingCapabilities.billingEnabled ? 'success' : 'grey',
  },
  {
    label: 'Пользовательский кабинет',
    value: billingCapabilities.userUiEnabled ? 'Пользователи видят “Тариф и лимиты”' : 'Скрыт для пользователей',
    badge: billingCapabilities.userUiEnabled ? 'включён' : 'выключен',
    status: billingCapabilities.userUiEnabled ? 'active' : 'disabled',
    color: billingCapabilities.userUiEnabled ? 'success' : 'grey',
  },
  {
    label: 'Оплата',
    value: billingCapabilities.checkoutEnabled
      ? 'Включена на уровне режима; frontend action подключается отдельно'
      : 'Выключена для пользователей',
    badge: billingCapabilities.checkoutEnabled ? 'включена' : 'выключена',
    status: billingCapabilities.checkoutEnabled ? 'warning' : 'disabled',
    color: billingCapabilities.checkoutEnabled ? 'warning' : 'grey',
  },
  {
    label: 'Ограничения',
    value: billingCapabilities.enforcementEnabled ? 'Лимиты применяются' : 'Лимиты не блокируют пользователей',
    badge: billingCapabilities.enforcementEnabled ? 'включены' : 'выключены',
    status: billingCapabilities.enforcementEnabled ? 'warning' : 'info',
    color: billingCapabilities.enforcementEnabled ? 'warning' : 'info',
  },
  {
    label: 'Учёт использования',
    value: billingCapabilities.usageTrackingEnabled ? 'Usage собирается' : 'Usage не собирается',
    badge: billingCapabilities.usageTrackingEnabled ? 'включён' : 'выключен',
    status: billingCapabilities.usageTrackingEnabled ? 'active' : 'disabled',
    color: billingCapabilities.usageTrackingEnabled ? 'success' : 'grey',
  },
  {
    label: 'Провайдер',
    value: providerLabel(billingCapabilities.provider),
    badge: billingCapabilities.providerMode,
    status: billingCapabilities.providerMode === 'live' ? 'active' : 'pending',
    color: billingCapabilities.providerMode === 'live' ? 'success' : 'info',
  },
  {
    label: 'План по умолчанию',
    value: billingCapabilities.defaultPlan,
    badge: 'default',
    status: 'active',
    color: 'primary',
  },
])

const adminPlansNotice = computed(() => {
  if (!billingCapabilities.userUiEnabled) {
    return 'Тарифы доступны только администратору для настройки и диагностики. Пользовательский раздел тарифов сейчас скрыт.'
  }

  if (!billingCapabilities.checkoutEnabled) {
    return 'Пользователи видят тарифы и лимиты, но оплата пока выключена.'
  }

  return billingCapabilities.enforcementEnabled
    ? 'Пользовательский раздел тарифов и применение лимитов включены.'
    : 'Пользовательский раздел тарифов и оплата включены. Лимиты пока не блокируют пользователей.'
})

const adminSubscriptionsNotice = computed(() => {
  if (!billingCapabilities.checkoutEnabled) {
    return 'Ручное управление подпиской доступно только администраторам. Пользовательская оплата сейчас выключена.'
  }

  return billingCapabilities.enforcementEnabled
    ? 'Ручное управление подпиской доступно администраторам. Пользовательская оплата и ограничения включены.'
    : 'Ручное управление подпиской доступно администраторам. Пользовательская оплата включена, ограничения пока не применяются.'
})

const dashboardCards = computed(() => {
  const diagnostics = billingDiagnostics.value || {}

  return [
    {
      title: 'Billing status',
      value: billingCapabilities.billingEnabled ? 'Включён' : 'Выключен',
      statusLabel: billingCapabilities.billingMode,
      status: billingCapabilities.billingEnabled ? 'enabled' : 'disabled',
      color: billingCapabilities.billingEnabled ? 'success' : 'grey',
      icon: 'mdi-power',
    },
    {
      title: 'Provider',
      value: providerLabel(billingCapabilities.provider),
      statusLabel: billingCapabilities.providerMode,
      status: billingCapabilities.providerMode === 'live' ? 'active' : 'pending',
      color: billingCapabilities.providerMode === 'live' ? 'success' : 'info',
      icon: 'mdi-credit-card-clock-outline',
    },
    {
      title: 'Оплата',
      value: billingCapabilities.checkoutEnabled ? 'Включена' : 'Выключена',
      statusLabel: billingCapabilities.paymentsEnabled ? 'payments on' : 'payments off',
      status: billingCapabilities.checkoutEnabled ? 'warning' : 'disabled',
      color: billingCapabilities.checkoutEnabled ? 'warning' : 'grey',
      icon: 'mdi-cart-off',
    },
    {
      title: 'Ограничения',
      value: billingCapabilities.enforcementEnabled ? 'Применяются' : 'Выключены',
      statusLabel: billingCapabilities.enforcementEnabled ? 'enforced' : 'not applied',
      status: billingCapabilities.enforcementEnabled ? 'warning' : 'info',
      color: billingCapabilities.enforcementEnabled ? 'warning' : 'info',
      icon: 'mdi-shield-alert-outline',
    },
    {
      title: 'Plans',
      value: formatNumber(diagnostics.plans_count || 0),
      statusLabel: 'plans',
      status: 'active',
      color: 'primary',
      icon: 'mdi-format-list-bulleted',
    },
    {
      title: 'Subscriptions',
      value: formatNumber(diagnostics.active_subscriptions_count || 0),
      statusLabel: 'active',
      status: 'active',
      color: 'success',
      icon: 'mdi-account-credit-card-outline',
    },
    {
      title: 'Payments',
      value: `${formatNumber(diagnostics.invoices_count || 0)} / ${formatNumber(diagnostics.payments_count || 0)}`,
      statusLabel: 'invoices / payments',
      status: 'processing',
      color: 'info',
      icon: 'mdi-receipt-text-outline',
    },
    {
      title: 'Events',
      value: `${formatNumber(diagnostics.webhook_events_count || 0)} / ${formatNumber(diagnostics.would_block_events_count || 0)}`,
      statusLabel: 'webhooks / gates',
      status: 'processing',
      color: 'info',
      icon: 'mdi-timeline-alert-outline',
    },
  ]
})

const statCards = computed(() => {
  const totals = overview.value?.totals || {}
  const pdfTotal =
    Number(totals.pdf_smeta_generated || 0) +
    Number(totals.pdf_price_justification_generated || 0) +
    Number(totals.pdf_evidence_run_generated || 0)

  return [
    { label: 'Users', value: formatNumber(totals.users || 0), icon: 'mdi-account-group-outline' },
    { label: 'Active projects', value: formatNumber(totals.active_projects || 0), icon: 'mdi-folder-outline' },
    { label: 'PDF generated', value: formatNumber(pdfTotal), icon: 'mdi-file-pdf-box' },
    { label: 'Evidence runs', value: formatNumber(totals.evidence_runs_created || 0), icon: 'mdi-shield-search' },
    { label: 'Chrome captures', value: formatNumber(totals.chrome_extract_with_evidence || 0), icon: 'mdi-google-chrome' },
    { label: 'Storage uploaded', value: formatBytes(totals.storage_bytes_uploaded || 0), icon: 'mdi-cloud-upload-outline' },
  ]
})

const gateMeta = computed(() => ({
  current_page: Number(gateEvents.value.meta?.current_page || 1),
  per_page: Number(gateEvents.value.meta?.per_page || gateFilters.per_page || 25),
  total: Number(gateEvents.value.meta?.total || 0),
  last_page: Number(gateEvents.value.meta?.last_page || 1),
}))

const gateStatCards = computed(() => {
  const summary = gateSummary.value
  const topCapability = summary?.top_capabilities?.[0]

  return [
    { label: 'Всего событий', value: formatNumber(summary?.total_events || 0), icon: 'mdi-counter' },
    { label: 'Would block', value: formatNumber(summary?.would_block_events || 0), icon: 'mdi-alert-outline' },
    { label: 'Enforced', value: formatNumber(summary?.enforced_events || 0), icon: 'mdi-lock-alert-outline' },
    {
      label: 'Самый частый лимит',
      value: topCapability ? `${topCapability.capability} (${formatNumber(topCapability.count)})` : '—',
      icon: 'mdi-format-list-bulleted-type',
    },
  ]
})

const planDrawerTitle = computed(() => {
  if (planDrawerMode.value === 'create') return 'Создать тариф'
  if (planDrawerMode.value === 'edit') return `Редактировать ${selectedPlan.value?.code || ''}`
  return `Просмотр ${selectedPlan.value?.code || ''}`
})

const selectedPlanIsProtected = computed(() => {
  if (planDrawerMode.value === 'create') return false
  return selectedPlan.value ? isProtectedPlan(selectedPlan.value) : false
})

const protectedDangerousFieldsDisabled = computed(() => planDrawerMode.value === 'edit' && selectedPlanIsProtected.value)

const billingPlanItems = computed(() => billingPlans.value.map((plan) => ({
  title: `${plan.code} · ${plan.name}`,
  value: plan.code,
})))

const subscriptionUser = computed(() => subscriptionData.value?.user || null)
const currentSubscription = computed(() => subscriptionData.value?.subscription || null)
const subscriptionConfirmTitle = computed(() => (
  subscriptionConfirmAction.value === 'cancel'
    ? 'Отменить подписку?'
    : 'Вернуть пользователя на legacy_unlimited?'
))
const gateContextDrawerModel = computed({
  get: () => activeBillingTab.value === 'gate-events' && gateContextDrawerOpen.value && Boolean(selectedGateEvent.value),
  set: (value: boolean) => {
    if (!value) {
      closeGateContext()
    }
  },
})
const planDrawerModel = computed({
  get: () => activeBillingTab.value === 'plans' && planDrawerOpen.value && (planDrawerMode.value === 'create' || Boolean(selectedPlan.value)),
  set: (value: boolean) => {
    if (!value) {
      closePlanDrawer()
    }
  },
})

function goBillingTab(value: unknown) {
  const tab = billingTabs.find((item) => item.value === value)
  if (tab && tab.value !== activeBillingTab.value) {
    router.push(tab.to)
  }
}

function handlePlanRowAction(action: unknown, plan: BillingPlan) {
  if (action === 'view') {
    openViewPlan(plan)
    return
  }

  if (action === 'edit') {
    openEditPlan(plan)
  }
}

async function loadAll() {
  loading.value = true
  gateLoading.value = true
  plansLoading.value = true
  error.value = ''
  gateError.value = ''
  plansError.value = ''

  try {
    await billingCapabilities.load()

    const params = { ...filters }
    const gateParams = gateEventParams()
    const [overviewData, usageData, eventsData, gateEventsData, gateSummaryData, plansData] = await Promise.all([
      getAdminBillingOverview(params),
      getAdminBillingUsage(params),
      getAdminBillingEvents({
        user_id: filters.user_id,
        metric_code: filters.metric_code,
        from: filters.period_start,
        to: filters.period_end,
        limit: filters.limit,
      }),
      getAdminBillingGateEvents(gateParams),
      getAdminBillingGateEventsSummary(gateParams),
      getAdminBillingPlans(),
    ])

    overview.value = overviewData
    usage.value = usageData
    events.value = eventsData
    gateEvents.value = gateEventsData
    gateSummary.value = gateSummaryData
    billingPlans.value = plansData.data || []
    const defaultPlanCode = billingPlans.value[0]?.code
    if (!assignForm.plan_code && defaultPlanCode) {
      assignForm.plan_code = defaultPlanCode
    }

    userOverview.value = filters.user_id
      ? await getAdminBillingUserOverview(filters.user_id, params)
      : null
  } catch (err: any) {
    error.value = err?.response?.data?.message || 'Не удалось загрузить данные использования'
  } finally {
    loading.value = false
    gateLoading.value = false
    plansLoading.value = false
  }
}

async function loadBillingPlans() {
  plansLoading.value = true
  plansError.value = ''

  try {
    const data = await getAdminBillingPlans()
    billingPlans.value = data.data || []
    const defaultPlanCode = billingPlans.value[0]?.code
    if (!assignForm.plan_code && defaultPlanCode) {
      assignForm.plan_code = defaultPlanCode
    }
  } catch (err: any) {
    plansError.value = err?.response?.data?.message || 'Не удалось загрузить тарифы'
  } finally {
    plansLoading.value = false
  }
}

async function loadGateEvents() {
  gateLoading.value = true
  gateError.value = ''
  closeGateContext()

  try {
    const params = gateEventParams()
    const [eventsData, summaryData] = await Promise.all([
      getAdminBillingGateEvents(params),
      getAdminBillingGateEventsSummary(params),
    ])

    gateEvents.value = eventsData
    gateSummary.value = summaryData
  } catch (err: any) {
    gateError.value = err?.response?.data?.message || 'Не удалось загрузить log-only лимиты'
  } finally {
    gateLoading.value = false
  }
}

function applyGateFilters() {
  gateFilters.page = 1
  loadGateEvents()
}

function gateEventParams(): AdminBillingGateEventFilters {
  return {
    user_id: gateFilters.user_id,
    capability: gateFilters.capability,
    date_from: gateFilters.date_from,
    date_to: gateFilters.date_to,
    would_block: gateFilters.only_would_block ? true : null,
    per_page: gateFilters.per_page,
    page: gateFilters.page,
  }
}

function formatNumber(value: number | string) {
  return new Intl.NumberFormat('ru-RU').format(Number(value || 0))
}

function providerLabel(provider?: string | null) {
  if (provider === 'yookassa') return 'YooKassa'
  if (!provider || provider === '—') return '—'

  return provider
}

function booleanLabel(value: unknown) {
  return value ? 'Да' : 'Нет'
}

function formatBytes(value: number | string) {
  const bytes = Number(value || 0)
  if (bytes < 1024) return `${formatNumber(bytes)} B`
  if (bytes < 1024 * 1024) return `${formatNumber((bytes / 1024).toFixed(1))} KB`
  if (bytes < 1024 * 1024 * 1024) return `${formatNumber((bytes / 1024 / 1024).toFixed(1))} MB`
  return `${formatNumber((bytes / 1024 / 1024 / 1024).toFixed(1))} GB`
}

function formatMoneyMinor(value?: number | string | null, currency = 'RUB') {
  const major = Number(value || 0) / 100
  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  }).format(major)
}

function formatDateTime(value?: string | null) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value))
}

function eventUserLabel(item: unknown) {
  const event = item as any
  return event?.user?.email || event?.user?.name || event?.owner_id || '—'
}

function eventProjectLabel(item: unknown) {
  const event = item as any
  return event?.project?.number || event?.project?.id || '—'
}

function eventQuantity(item: unknown) {
  return (item as any)?.quantity || 0
}

function eventUnit(item: unknown) {
  return (item as any)?.unit || ''
}

function eventOccurredAt(item: unknown) {
  return (item as any)?.occurred_at || null
}

function gateEventCreatedAt(item: unknown) {
  return (item as BillingGateEvent)?.created_at || null
}

function gateEventUserLabel(item: unknown) {
  const event = item as BillingGateEvent
  return event?.user?.email || event?.user?.name || event?.user_id || '—'
}

function gateEventUsageLimit(item: unknown) {
  const event = item as BillingGateEvent
  const usage = event?.usage_value ?? 0
  const limit = event?.limit_value ?? '—'
  return `${formatNumber(usage)} / ${limit === '—' ? limit : formatNumber(limit)}`
}

function gateEventWouldBlock(item: unknown) {
  return Boolean((item as BillingGateEvent)?.would_block)
}

function gateEventEnforced(item: unknown) {
  return Boolean((item as BillingGateEvent)?.enforced)
}

function gateEventHasContext(item: unknown) {
  const context = (item as BillingGateEvent)?.context_json || {}
  return Object.keys(context).length > 0
}

function openGateContext(item: unknown) {
  if (activeBillingTab.value !== 'gate-events' || !item) return
  selectedGateEvent.value = item as BillingGateEvent
  gateContextDrawerOpen.value = true
}

function closeGateContext() {
  gateContextDrawerOpen.value = false
  selectedGateEvent.value = null
}

function prettyJson(value: unknown) {
  return JSON.stringify(sanitizeJson(value || {}), null, 2)
}

function sanitizeJson(value: unknown): unknown {
  const sensitiveKeys = ['authorization', 'cookie', 'token', 'secret', 'password', 'session', 'api_key']

  if (Array.isArray(value)) {
    return value.map((item) => sanitizeJson(item))
  }

  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value as Record<string, unknown>).map(([key, item]) => {
        const normalizedKey = key.toLowerCase()
        const isSensitive = sensitiveKeys.some((sensitiveKey) => normalizedKey.includes(sensitiveKey))
        return [key, isSensitive ? '[hidden]' : sanitizeJson(item)]
      })
    )
  }

  return value
}

async function copyJson(value: unknown) {
  await navigator.clipboard?.writeText(prettyJson(value))
}

function statusColor(status?: string | null) {
  if (status === 'active' || status === 'paid' || status === 'succeeded') return 'success'
  if (status === 'canceled' || status === 'failed') return 'error'
  if (status === 'replaced') return 'warning'
  return 'info'
}

function subscriptionStatusLabel(status?: string | null) {
  if (status === 'active') return 'Подписка активна'
  if (status === 'canceled') return 'Подписка отменена'
  if (status === 'replaced') return 'Подписка заменена'
  return 'Нет активной подписки'
}

function subscriptionStatusTone(status?: string | null) {
  if (status === 'active') return 'success'
  if (status === 'canceled') return 'error'
  if (status === 'replaced') return 'warning'
  return 'grey'
}

function planMetadata(plan: BillingPlan) {
  return plan.metadata_json || {}
}

function planMetadataValue(plan: BillingPlan, key: keyof NonNullable<BillingPlan['metadata_json']>) {
  return planMetadata(plan)?.[key] ?? null
}

function planFlag(plan: BillingPlan, key: 'hidden' | 'system' | 'sandbox') {
  return Boolean(planMetadata(plan)?.[key])
}

function planPublic(plan: BillingPlan) {
  return Boolean(plan.is_active) && !planFlag(plan, 'hidden') && !planFlag(plan, 'sandbox') && !planFlag(plan, 'system') && plan.code !== 'legacy_unlimited'
}

function planPriceLabel(plan: BillingPlan) {
  const metadata = planMetadata(plan)
  const price = metadata.price_minor
  if (price === null || price === undefined) return '—'
  return formatMoneyMinor(price, metadata.currency || 'RUB')
}

function limitsSummary(plan: BillingPlan) {
  const limits = planMetadata(plan).limits || {}
  const limited = limitDefinitions.filter((limit) => limits[limit.key] !== null && limits[limit.key] !== undefined).length
  const unlimited = limitDefinitions.length - limited
  return `${limited} лим., ${unlimited} без огранич.`
}

function isProtectedPlan(plan: BillingPlan) {
  return plan.code === 'legacy_unlimited' || planFlag(plan, 'system')
}

function openCreatePlan() {
  selectedPlan.value = null
  planDrawerMode.value = 'create'
  resetPlanForm()
  planDrawerOpen.value = true
}

function openViewPlan(plan: BillingPlan) {
  selectedPlan.value = plan
  planDrawerMode.value = 'view'
  fillPlanForm(plan)
  planDrawerOpen.value = true
}

function openEditPlan(plan: BillingPlan) {
  selectedPlan.value = plan
  planDrawerMode.value = 'edit'
  fillPlanForm(plan)
  planDrawerOpen.value = true
}

function closePlanDrawer() {
  planDrawerOpen.value = false
  planSaveError.value = ''
  selectedPlan.value = null
  planDrawerMode.value = 'view'
}

function resetPlanForm() {
  planForm.code = ''
  planForm.name = ''
  planForm.price_minor = 0
  planForm.currency = 'RUB'
  planForm.billing_period = 'month'
  planForm.is_active = true
  planForm.hidden = true
  planForm.sandbox = false
  planForm.system = false
  planForm.sort_order = null
  planForm.description = ''
  planForm.featuresText = ''
  planForm.limits = emptyLimitForm()
}

function fillPlanForm(plan: BillingPlan) {
  const metadata = planMetadata(plan)
  planForm.code = plan.code
  planForm.name = plan.name
  planForm.price_minor = Number(metadata.price_minor || 0)
  planForm.currency = 'RUB'
  planForm.billing_period = (metadata.billing_period as typeof planForm.billing_period) || 'month'
  planForm.is_active = Boolean(plan.is_active)
  planForm.hidden = Boolean(metadata.hidden)
  planForm.sandbox = Boolean(metadata.sandbox)
  planForm.system = Boolean(metadata.system)
  planForm.sort_order = metadata.sort_order ?? null
  planForm.description = metadata.description || ''
  planForm.featuresText = (metadata.features || []).join('\n')
  planForm.limits = emptyLimitForm(metadata.limits || {})
  planSaveError.value = ''
}

function emptyLimitForm(values: Record<string, number | null> = {}) {
  return Object.fromEntries(
    limitDefinitions.map((limit) => [limit.key, values[limit.key] ?? ''])
  ) as Record<string, string | number | null>
}

function planPayload(): AdminBillingPlanPayload {
  const features = planForm.featuresText
    .split('\n')
    .map((feature) => feature.trim())
    .filter(Boolean)

  const commonPayload: AdminBillingPlanPayload = {
    name: planForm.name,
    hidden: planForm.hidden,
    sort_order: planForm.sort_order,
    description: planForm.description || null,
    features,
  }

  if (planDrawerMode.value === 'edit' && selectedPlanIsProtected.value) {
    return commonPayload
  }

  return {
    ...commonPayload,
    ...(planDrawerMode.value === 'create' ? { code: planForm.code } : {}),
    is_active: planForm.is_active,
    price_minor: Number(planForm.price_minor || 0),
    currency: planForm.currency,
    billing_period: planForm.billing_period,
    sandbox: planForm.sandbox,
    system: planForm.system,
    limits: { ...planForm.limits },
  }
}

async function savePlan() {
  if (planDrawerMode.value === 'view') return

  savingPlan.value = true
  planSaveError.value = ''

  try {
    if (planDrawerMode.value === 'create') {
      await createAdminBillingPlan(planPayload())
    } else if (selectedPlan.value) {
      await updateAdminBillingPlan(selectedPlan.value.id, planPayload())
    }

    await loadBillingPlans()
    closePlanDrawer()
  } catch (err: any) {
    planSaveError.value = err?.response?.data?.message || 'Не удалось сохранить тариф'
  } finally {
    savingPlan.value = false
  }
}

async function loadUserSubscription() {
  if (!subscriptionUserId.value) {
    subscriptionError.value = 'Укажите User ID'
    return
  }

  subscriptionLoading.value = true
  subscriptionError.value = ''

  try {
    const resolvedUserId = await resolveSubscriptionUserId(subscriptionUserId.value)
    subscriptionUserId.value = String(resolvedUserId)
    const [subscriptionResponse, historyResponse] = await Promise.all([
      getAdminBillingUserSubscription(resolvedUserId),
      getAdminBillingUserSubscriptionHistory(resolvedUserId),
    ])

    subscriptionData.value = subscriptionResponse
    subscriptionHistory.value = historyResponse.data || []
  } catch (err: any) {
    subscriptionError.value = err?.message || err?.response?.data?.message || 'Не удалось загрузить подписку пользователя'
  } finally {
    subscriptionLoading.value = false
  }
}

async function resolveSubscriptionUserId(value: string) {
  const query = value.trim()
  if (/^\d+$/.test(query)) return query

  const users = await searchAdminBillingUsers(query) as BillingUserSearchResult[]
  const user = users.find((item) => item.email?.toLowerCase() === query.toLowerCase()) || users[0]
  if (!user?.id) {
    throw new Error('Пользователь не найден')
  }

  return String(user.id)
}

async function refreshLoadedSubscription() {
  if (!subscriptionUserId.value) return
  await loadUserSubscription()
}

async function assignSubscription() {
  if (!subscriptionUserId.value) {
    subscriptionError.value = 'Укажите User ID'
    return
  }

  subscriptionActionLoading.value = true
  subscriptionError.value = ''

  try {
    await assignAdminBillingUserSubscription(subscriptionUserId.value, {
      plan_code: assignForm.plan_code,
      period: assignForm.period,
      starts_at: assignForm.starts_at || null,
      ends_at: assignForm.ends_at || null,
      reason: assignForm.reason || null,
    })
    await refreshLoadedSubscription()
  } catch (err: any) {
    subscriptionError.value = err?.response?.data?.message || 'Не удалось назначить тариф'
  } finally {
    subscriptionActionLoading.value = false
  }
}

async function extendSubscription() {
  if (!subscriptionUserId.value) {
    subscriptionError.value = 'Укажите User ID'
    return
  }

  subscriptionActionLoading.value = true
  subscriptionError.value = ''

  try {
    await extendAdminBillingUserSubscription(subscriptionUserId.value, {
      months: Number(extendForm.months || 0),
      days: Number(extendForm.days || 0),
      reason: extendForm.reason || null,
    })
    await refreshLoadedSubscription()
  } catch (err: any) {
    subscriptionError.value = err?.response?.data?.message || 'Не удалось продлить подписку'
  } finally {
    subscriptionActionLoading.value = false
  }
}

function confirmSubscriptionAction(action: SubscriptionConfirmAction) {
  subscriptionConfirmAction.value = action
  subscriptionConfirmReason.value = ''
  subscriptionConfirmOpen.value = true
}

async function runConfirmedSubscriptionAction() {
  if (!subscriptionUserId.value) {
    subscriptionError.value = 'Укажите User ID'
    return
  }

  subscriptionActionLoading.value = true
  subscriptionError.value = ''

  try {
    if (subscriptionConfirmAction.value === 'cancel') {
      await cancelAdminBillingUserSubscription(subscriptionUserId.value, {
        reason: subscriptionConfirmReason.value || null,
      })
    } else {
      await switchAdminBillingUserSubscriptionToLegacy(subscriptionUserId.value, {
        reason: subscriptionConfirmReason.value || null,
      })
    }

    subscriptionConfirmOpen.value = false
    await refreshLoadedSubscription()
  } catch (err: any) {
    subscriptionError.value = err?.response?.data?.message || 'Не удалось выполнить действие с подпиской'
  } finally {
    subscriptionActionLoading.value = false
  }
}

function closeBillingDrawers() {
  closeGateContext()
  closePlanDrawer()
}

watch(
  () => route.fullPath,
  () => {
    closeBillingDrawers()
  }
)

onMounted(loadAll)
</script>

<style scoped>
.billing-tabs {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 8px;
  overflow-x: auto;
}

.billing-admin-alert {
  border-radius: 12px;
}

.billing-status-card {
  overflow: hidden;
}

.billing-status-hero {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
}

.billing-status-hero__eyebrow {
  margin-bottom: 4px;
  color: rgb(var(--v-theme-on-surface-variant));
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0;
}

.billing-status-hero__title {
  font-size: 24px;
  font-weight: 700;
  line-height: 1.2;
}

.billing-status-hero__description {
  max-width: 920px;
  margin: 8px 0 0;
  color: rgb(var(--v-theme-on-surface-variant));
}

.billing-status-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 10px;
}

.billing-status-item {
  min-width: 0;
  padding: 12px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.1);
  border-radius: 8px;
  background: rgba(var(--v-theme-surface), 0.72);
}

.billing-status-item__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 8px;
  color: rgb(var(--v-theme-on-surface-variant));
  font-size: 12px;
}

.billing-status-item__value {
  overflow-wrap: anywhere;
  font-size: 14px;
  font-weight: 600;
}

.billing-dashboard-card {
  min-height: 132px;
}

.billing-dashboard-card__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  color: rgb(var(--v-theme-primary));
  margin-bottom: 14px;
}

.billing-dashboard-card__value {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.2;
}

.billing-dashboard-card__label {
  margin-top: 4px;
  color: rgb(var(--v-theme-on-surface-variant));
  font-size: 13px;
}

.usage-stat {
  min-height: 132px;
}

.usage-stat__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 8px;
  color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.12);
  margin-bottom: 12px;
}

.usage-stat__value {
  font-size: 24px;
  line-height: 32px;
  font-weight: 700;
}

.usage-stat__label {
  font-size: 13px;
  color: rgb(var(--v-theme-on-surface-variant));
}

.gate-stat {
  min-height: 132px;
}

.gate-stat__value {
  font-size: 20px;
  overflow-wrap: anywhere;
}

.gate-context-drawer__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px;
}

.gate-context-drawer__body {
  padding: 16px;
  min-width: 0;
  overflow-x: hidden;
}

.plan-drawer__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 16px;
}

.plan-drawer__body {
  padding: 16px;
  min-width: 0;
  overflow-x: hidden;
}

.drawer-section-title {
  font-size: 14px;
  font-weight: 600;
}

.drawer-section-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 8px;
}

.detail-grid {
  display: grid;
  grid-template-columns: 120px minmax(0, 1fr);
  gap: 8px 12px;
  align-items: center;
  font-size: 13px;
}

.detail-grid span {
  color: rgb(var(--v-theme-on-surface-variant));
}

.detail-grid code {
  overflow-wrap: anywhere;
}

.json-block {
  margin: 0;
  padding: 12px;
  border-radius: 8px;
  overflow: auto;
  max-width: 100%;
  max-height: 60vh;
  font-size: 12px;
  line-height: 1.5;
  white-space: pre-wrap;
  word-break: break-word;
  background: rgba(var(--v-theme-on-surface), 0.05);
}

.billing-overlay-drawer {
  position: fixed !important;
  top: 0 !important;
  right: 0 !important;
  height: 100dvh !important;
  max-width: min(640px, 100vw) !important;
  border-left: 1px solid rgba(var(--v-theme-on-surface), 0.12);
}

.billing-overlay-drawer :deep(.v-navigation-drawer__content) {
  min-width: 0;
  overflow-x: hidden;
}

.gate-context-drawer {
  width: min(560px, 100vw) !important;
}

.plan-drawer {
  width: min(640px, 100vw) !important;
}

@media (max-width: 960px) {
  .gate-context-drawer,
  .plan-drawer {
    width: min(100vw, 96vw) !important;
  }
}
</style>
