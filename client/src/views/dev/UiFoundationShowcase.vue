<template>
  <PageContainer max-width="1280px">
    <PageHeader
      title="UI Foundation Showcase"
      subtitle="Изолированная проверка foundation-компонентов без изменения рабочих экранов."
    >
      <template #actions>
        <ButtonGroup>
          <v-btn color="primary" variant="flat" prepend-icon="mdi-eye" @click="detailOpen = true">
            Открыть drawer
          </v-btn>
          <v-btn variant="tonal" prepend-icon="mdi-refresh" @click="loading = !loading">
            Loading
          </v-btn>
        </ButtonGroup>
      </template>
    </PageHeader>

    <SectionCard
      title="Table Shell"
      subtitle="Toolbar, status chips, row actions and table state examples."
    >
      <AppDataTableShell
        :empty="tableMode === 'empty'"
        :error="tableMode === 'error' ? 'Не удалось загрузить демонстрационные данные.' : null"
        empty-title="Данных пока нет"
        empty-description="Так выглядит пустое состояние таблицы."
      >
        <template #search>
          <v-text-field
            v-model="search"
            prepend-inner-icon="mdi-magnify"
            placeholder="Поиск по названию"
            hide-details
            clearable
          />
        </template>

        <template #filters>
          <v-select
            v-model="statusFilter"
            :items="statusFilterItems"
            label="Статус"
            hide-details
            clearable
          />
          <AppTabs v-model="tableMode" :items="tableModeTabs" />
        </template>

        <template #actions>
          <v-btn variant="tonal" prepend-icon="mdi-plus">Добавить</v-btn>
        </template>

        <template #emptyActions>
          <v-btn color="primary" variant="flat" prepend-icon="mdi-plus">Создать запись</v-btn>
        </template>

        <v-data-table
          :headers="headers"
          :items="filteredRows"
          :loading="loading"
          item-value="id"
          hover
        >
          <template #item.status="{ item }">
            <StatusChip :status="item.status" :label="item.statusLabel" />
          </template>

          <template #item.actions="{ item }">
            <AppRowActions
              :actions="rowActions"
              @action="selectedAction = `${item.name}: ${$event}`"
            />
          </template>
        </v-data-table>
      </AppDataTableShell>

      <template #actions>
        <div class="ui-foundation-note">
          Last action: {{ selectedAction || '—' }}
        </div>
      </template>
    </SectionCard>

    <SectionCard
      title="States"
      subtitle="Compact state blocks for loading, empty, warning and error contexts."
    >
      <div class="ui-foundation-grid">
        <AppStateBlock title="Загрузка" description="Данные подготавливаются." loading />
        <AppStateBlock
          title="Пусто"
          description="Добавьте первую запись, чтобы продолжить."
          icon="mdi-inbox-outline"
        />
        <AppStateBlock
          title="Требует внимания"
          description="Часть данных нуждается в проверке."
          icon="mdi-alert-outline"
          tone="warning"
        />
      </div>
    </SectionCard>

    <SectionCard
      title="Form Sections"
      subtitle="Секции формы и action footer для long-form/settings сценариев."
    >
      <div class="ui-foundation-form">
        <AppFormSection
          title="Основные параметры"
          description="Короткая группа полей с общей смысловой задачей."
        >
          <v-row dense>
            <v-col cols="12" md="6">
              <v-text-field v-model="form.title" label="Название" />
            </v-col>
            <v-col cols="12" md="6">
              <v-select v-model="form.mode" :items="modeItems" label="Режим" />
            </v-col>
          </v-row>
        </AppFormSection>

        <AppFormSection
          title="Дополнительные настройки"
          description="Необязательные параметры, которые не должны перегружать первый экран."
          compact
        >
          <v-switch v-model="form.enabled" label="Активно" />
          <v-alert type="info" variant="tonal" density="compact">
            Это dev-пример. Сохранение не отправляет запросы.
          </v-alert>
        </AppFormSection>

        <AppActionFooter status-text="Есть несохранённые изменения">
          <v-btn variant="text">Отмена</v-btn>
          <v-btn color="primary" variant="flat">Сохранить</v-btn>
        </AppActionFooter>
      </div>
    </SectionCard>

    <AppDetailDrawer
      v-model="detailOpen"
      title="Детали записи"
      subtitle="Пример правой панели без бизнес-логики."
    >
      <AppDetailMetaGrid :items="metaItems" />

      <div class="ui-foundation-drawer-section">
        <div class="ui-foundation-section-title">Статусы</div>
        <div class="ui-foundation-chip-row">
          <StatusChip status="active" label="Активно" />
          <StatusChip status="stale" label="Устарело" />
          <StatusChip status="rejected" label="Отклонено" />
        </div>
      </div>

      <AppFormSection title="Комментарий" compact>
        <v-textarea label="Текст" rows="4" />
      </AppFormSection>

      <template #actions>
        <v-btn variant="text" @click="detailOpen = false">Закрыть</v-btn>
        <v-btn color="primary" variant="flat">Применить</v-btn>
      </template>
    </AppDetailDrawer>
  </PageContainer>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import AppActionFooter from '@/components/layout/AppActionFooter.vue'
import AppDataTableShell from '@/components/layout/AppDataTableShell.vue'
import AppDetailDrawer from '@/components/layout/AppDetailDrawer.vue'
import AppDetailMetaGrid from '@/components/layout/AppDetailMetaGrid.vue'
import AppFormSection from '@/components/layout/AppFormSection.vue'
import AppRowActions, { type AppRowAction } from '@/components/layout/AppRowActions.vue'
import AppStateBlock from '@/components/layout/AppStateBlock.vue'
import AppTabs, { type AppTabItem } from '@/components/layout/AppTabs.vue'
import ButtonGroup from '@/components/layout/ButtonGroup.vue'
import PageContainer from '@/components/layout/PageContainer.vue'
import PageHeader from '@/components/layout/PageHeader.vue'
import SectionCard from '@/components/layout/SectionCard.vue'
import StatusChip from '@/components/layout/StatusChip.vue'

type TableMode = 'data' | 'empty' | 'error'

interface DemoRow {
  id: number
  name: string
  owner: string
  status: string
  statusLabel: string
}

const loading = ref(false)
const detailOpen = ref(false)
const search = ref('')
const statusFilter = ref<string | null>(null)
const tableMode = ref<TableMode>('data')
const selectedAction = ref('')

const form = ref({
  title: 'Демонстрационная секция',
  mode: 'compact',
  enabled: true,
})

const headers = [
  { title: 'Название', key: 'name' },
  { title: 'Ответственный', key: 'owner', width: '180px' },
  { title: 'Статус', key: 'status', width: '140px' },
  { title: 'Действия', key: 'actions', sortable: false, align: 'end' as const, width: '132px' },
]

const rows: DemoRow[] = [
  { id: 1, name: 'Поставщики', owner: 'Команда каталога', status: 'active', statusLabel: 'Активно' },
  { id: 2, name: 'Импорт цен', owner: 'Pricing', status: 'processing', statusLabel: 'В работе' },
  { id: 3, name: 'Проверка доказательств', owner: 'Evidence', status: 'stale', statusLabel: 'Требует проверки' },
]

const rowActions: AppRowAction[] = [
  { key: 'open', label: 'Открыть', icon: 'mdi-open-in-new', color: 'primary' },
  { key: 'edit', label: 'Изменить', icon: 'mdi-pencil' },
  { key: 'delete', label: 'Удалить', icon: 'mdi-delete-outline', color: 'error' },
]

const statusFilterItems = [
  { title: 'Активно', value: 'active' },
  { title: 'В работе', value: 'processing' },
  { title: 'Требует проверки', value: 'stale' },
]

const tableModeTabs: AppTabItem[] = [
  { value: 'data', label: 'Данные', icon: 'mdi-table' },
  { value: 'empty', label: 'Пусто', icon: 'mdi-inbox-outline' },
  { value: 'error', label: 'Ошибка', icon: 'mdi-alert-outline' },
]

const modeItems = [
  { title: 'Компактный', value: 'compact' },
  { title: 'Обычный', value: 'default' },
]

const metaItems = [
  { key: 'type', label: 'Тип', value: 'Registry' },
  { key: 'rows', label: 'Строк', value: 3 },
  { key: 'state', label: 'Состояние', value: 'Demo' },
]

const filteredRows = computed(() => {
  return rows.filter((row) => {
    const matchesSearch = !search.value || row.name.toLowerCase().includes(search.value.toLowerCase())
    const matchesStatus = !statusFilter.value || row.status === statusFilter.value
    return matchesSearch && matchesStatus
  })
})
</script>

<style scoped>
.ui-foundation-note {
  color: var(--ds-text-secondary);
  font-size: 0.85rem;
}

.ui-foundation-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: var(--ds-space-12);
}

.ui-foundation-grid > * {
  border: 1px solid var(--ds-border-color);
  border-radius: var(--ds-radius-12);
  background: rgba(var(--v-theme-surface-container-lowest), 0.62);
}

.ui-foundation-form {
  display: grid;
  gap: var(--ds-space-14);
}

.ui-foundation-drawer-section {
  display: grid;
  gap: var(--ds-space-10);
  margin: var(--ds-space-16) 0;
}

.ui-foundation-section-title {
  color: var(--ds-text-primary);
  font-size: 0.95rem;
  font-weight: 700;
}

.ui-foundation-chip-row {
  display: flex;
  flex-wrap: wrap;
  gap: var(--ds-space-8);
}
</style>
