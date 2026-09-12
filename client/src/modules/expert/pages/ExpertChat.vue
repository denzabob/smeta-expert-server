<template>
  <div class="expert-chat">
    <section class="expert-chat__main">
      <header class="expert-chat__toolbar">
        <v-menu location="bottom start">
          <template #activator="{ props: menuProps }"><v-btn v-bind="menuProps" variant="text" append-icon="mdi-chevron-down" class="expert-chat__conversation">{{ conversation?.title || 'Общий анализ' }}</v-btn></template>
          <v-list density="compact" min-width="240"><v-list-subheader>Чаты проекта</v-list-subheader><v-list-item v-for="item in project.conversations" :key="item.id" :title="item.title" prepend-icon="mdi-message-text-outline" @click="conversationId = item.id" /><v-divider /><v-list-item title="Новый чат" prepend-icon="mdi-plus" @click="notify('Новый чат будет доступен после подключения сохранения')" /></v-list>
        </v-menu>
        <div class="expert-chat__toolbar-actions">
          <v-tooltip text="Контекст проекта"><template #activator="{ props: tooltipProps }"><v-btn v-bind="tooltipProps" icon="mdi-dock-right" size="small" :variant="contextOpen ? 'tonal' : 'text'" aria-label="Контекст проекта" @click="toggleContext" /></template></v-tooltip>
          <v-btn icon="mdi-dots-horizontal" size="small" variant="text" aria-label="Действия чата" />
        </div>
      </header>

      <div ref="messageArea" class="expert-chat__messages">
        <div v-if="!messages.length" class="expert-chat__empty">
          <div class="expert-chat__empty-icon"><v-icon icon="mdi-prism" size="30" /></div>
          <h1>Чем помочь в этом исследовании?</h1>
          <p>Prism AI будет работать с материалами, фактами и документом этого проекта.</p>
          <div class="expert-chat__quick-actions">
            <button v-for="action in project.quickActions" :key="action" type="button" @click="sendMessage(action)"><v-icon icon="mdi-arrow-up-right" size="17" /><span>{{ action }}</span></button>
          </div>
        </div>
        <template v-else>
          <ExpertChatMessage v-for="message in messages" :key="message.id" :message="message" @action="notify" @open-source="openSource" />
        </template>
      </div>

      <div v-if="selectedMaterials.length || messages.length" class="expert-chat__context-chips">
        <v-chip size="small" variant="tonal" prepend-icon="mdi-folder-multiple-outline">Материалы проекта · {{ project.materials.length }}</v-chip>
        <v-chip size="small" variant="tonal" prepend-icon="mdi-book-open-page-variant-outline">Нормативы · {{ project.normatives.filter((item) => item.connected).length }}</v-chip>
      </div>
      <ExpertChatComposer :selected-materials="selectedMaterials" @send="sendMessage" @attachment="handleAttachment" @remove-material="removeMaterial" />
    </section>

    <ExpertContextPanel v-if="contextOpen && !mdAndDown" :project="project" @close="contextOpen = false" @action="notify" />
    <v-navigation-drawer v-if="mdAndDown" v-model="contextOpen" temporary location="right" width="360"><ExpertContextPanel :project="project" @close="contextOpen = false" @action="notify" /></v-navigation-drawer>

    <v-dialog v-model="materialPickerOpen" max-width="640" scrollable>
      <v-card class="expert-chat__picker">
        <v-card-title class="d-flex align-center justify-space-between"><span>Материалы проекта</span><v-btn icon="mdi-close" variant="text" @click="materialPickerOpen = false" /></v-card-title>
        <v-card-text><v-text-field v-model="materialSearch" prepend-inner-icon="mdi-magnify" placeholder="Найти материал" variant="outlined" density="compact" hide-details class="mb-3" /><v-list lines="two"><v-list-item v-for="material in filteredMaterials" :key="material.id" :prepend-icon="material.icon" :title="material.name" :subtitle="`${material.format} · ${material.meta}`" @click="toggleMaterial(material)"><template #append><v-checkbox-btn :model-value="selectedMaterials.some((item) => item.id === material.id)" /></template></v-list-item></v-list></v-card-text>
        <v-card-actions class="justify-end"><v-btn variant="text" @click="materialPickerOpen = false">Отмена</v-btn><v-btn color="primary" variant="flat" @click="materialPickerOpen = false">Добавить {{ selectedMaterials.length || '' }}</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
    <v-snackbar v-model="snackbarOpen" :timeout="2600">{{ snackbarText }}<template #actions><v-btn variant="text" @click="snackbarOpen = false">Закрыть</v-btn></template></v-snackbar>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useDisplay } from 'vuetify'
import ExpertChatComposer from '../components/chat/ExpertChatComposer.vue'
import ExpertChatMessage from '../components/chat/ExpertChatMessage.vue'
import ExpertContextPanel from '../components/chat/ExpertContextPanel.vue'
import type { ExpertMessage, ExpertProject, ExpertProjectMaterial } from '../types'

const props = defineProps<{ project: ExpertProject }>()
const { mdAndDown } = useDisplay()
const conversationId = ref(props.project.conversations[0]?.id ?? '')
const localMessages = ref<Record<string, ExpertMessage[]>>({})
const contextOpen = ref(!mdAndDown.value)
const materialPickerOpen = ref(false)
const materialSearch = ref('')
const selectedMaterials = ref<ExpertProjectMaterial[]>([])
const snackbarOpen = ref(false)
const snackbarText = ref('')
const messageArea = ref<HTMLElement | null>(null)
const conversation = computed(() => props.project.conversations.find((item) => item.id === conversationId.value) ?? props.project.conversations[0])
const messages = computed(() => localMessages.value[conversationId.value] ?? conversation.value?.messages ?? [])
const filteredMaterials = computed(() => props.project.materials.filter((item) => item.name.toLowerCase().includes(materialSearch.value.trim().toLowerCase())))

function notify(action: string) { snackbarText.value = action === 'Копировать' ? 'Текст скопирован в прототипе' : `${action}: функция будет подключена на следующем этапе`; snackbarOpen.value = true }
function openSource() { contextOpen.value = true }
function toggleContext() { contextOpen.value = !contextOpen.value }
function handleAttachment(action: string) { if (action === 'materials') materialPickerOpen.value = true; else notify(action === 'normative' ? 'Добавление норматива' : 'Загрузка материалов') }
function toggleMaterial(material: ExpertProjectMaterial) { const exists = selectedMaterials.value.some((item) => item.id === material.id); selectedMaterials.value = exists ? selectedMaterials.value.filter((item) => item.id !== material.id) : [...selectedMaterials.value, material] }
function removeMaterial(id: string) { selectedMaterials.value = selectedMaterials.value.filter((item) => item.id !== id) }
async function sendMessage(text: string) {
  const current = messages.value
  const userMessage: ExpertMessage = { id: `local-${Date.now()}`, role: 'user', text, createdAt: 'сейчас' }
  const reply: ExpertMessage = { id: `local-ai-${Date.now()}`, role: 'assistant', text: 'На этом этапе это интерфейсный прототип. Команда уже связана с контекстом проекта; анализ материалов и сохранение результата будут подключены вместе с AI Gateway.', createdAt: 'сейчас', sources: selectedMaterials.value.map((item) => ({ id: item.id, label: item.name, detail: item.meta, icon: item.icon })) }
  localMessages.value = { ...localMessages.value, [conversationId.value]: [...current, userMessage, reply] }
  selectedMaterials.value = []
  await nextTick(); messageArea.value?.scrollTo({ top: messageArea.value.scrollHeight, behavior: 'smooth' })
}
</script>

<style scoped>
.expert-chat { display: flex; height: 100%; min-height: 0; overflow: hidden; background: rgb(var(--v-theme-background)); }
.expert-chat__main { display: flex; flex: 1; min-width: 0; min-height: 0; flex-direction: column; }
.expert-chat__toolbar { display: flex; align-items: center; justify-content: space-between; min-height: 54px; padding: 7px 14px; border-bottom: 1px solid rgba(var(--v-theme-outline-variant), .55); background: rgb(var(--v-theme-surface)); }
.expert-chat__conversation { font-weight: 800; text-transform: none; }
.expert-chat__toolbar-actions { display: flex; gap: 4px; }
.expert-chat__messages { display: flex; flex: 1; min-height: 0; flex-direction: column; gap: 24px; overflow-y: auto; padding: 28px max(22px, calc((100% - 820px) / 2)); scroll-behavior: smooth; }
.expert-chat__empty { display: grid; align-content: center; justify-items: center; min-height: 100%; padding: 24px; text-align: center; }
.expert-chat__empty-icon { display: grid; place-items: center; width: 54px; height: 54px; margin-bottom: 16px; border-radius: 50%; color: rgb(var(--v-theme-on-primary-container)); background: rgb(var(--v-theme-primary-container)); }
.expert-chat__empty h1 { margin: 0; font-size: clamp(1.35rem, 3vw, 1.85rem); letter-spacing: -.025em; }
.expert-chat__empty p { max-width: 540px; margin: 8px 0 24px; color: rgba(var(--v-theme-on-surface-variant), .76); font-size: .82rem; }
.expert-chat__quick-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 260px)); gap: 9px; }
.expert-chat__quick-actions button { display: flex; align-items: center; gap: 9px; padding: 12px 13px; border: 1px solid rgba(var(--v-theme-outline-variant), .62); border-radius: var(--md-sys-shape-corner-large); color: rgba(var(--v-theme-on-surface), .84); background: rgb(var(--v-theme-surface)); cursor: pointer; text-align: left; font: inherit; font-size: .76rem; }
.expert-chat__quick-actions button:hover { border-color: rgba(var(--v-theme-primary), .52); background: rgba(var(--v-theme-primary), .045); }
.expert-chat__context-chips { display: flex; gap: 6px; width: min(820px, calc(100% - 36px)); margin: 0 auto; overflow-x: auto; }
.expert-chat__picker { border-radius: var(--md-sys-shape-corner-extra-large); }
@media (max-width: 700px) { .expert-chat__messages { gap: 18px; padding: 18px 12px; } .expert-chat__quick-actions { grid-template-columns: 1fr; width: 100%; } .expert-chat__quick-actions button { max-width: none; } .expert-chat__context-chips { width: calc(100% - 16px); } }
</style>
