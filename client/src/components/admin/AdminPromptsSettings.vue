<template>
  <v-card variant="outlined" :loading="loading">
    <v-card-title class="d-flex align-center">
      <v-icon class="mr-2">mdi-text-box-edit</v-icon>
      Промпты LLM
      <v-spacer />
      <v-btn color="primary" variant="tonal" size="small" @click="savePrompts" :loading="saving">
        <v-icon start>mdi-content-save</v-icon>
        Сохранить
      </v-btn>
    </v-card-title>

    <v-card-text>
      <v-alert type="info" variant="tonal" class="mb-4">
        Настройте системные промпты для различных задач LLM. Используйте переменные в фигурных скобках, например: <code>{'{'}material_name{'}'}</code>
      </v-alert>

      <v-expansion-panels variant="accordion">
        <v-expansion-panel v-for="(prompt, key) in prompts" :key="key">
          <v-expansion-panel-title>
            {{ promptLabels[key] || key }}
          </v-expansion-panel-title>
          <v-expansion-panel-text>
            <v-textarea
              v-model="prompts[key]"
              variant="outlined"
              rows="6"
              auto-grow
              class="mono-text"
            />
          </v-expansion-panel-text>
        </v-expansion-panel>
      </v-expansion-panels>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import api from '@/api/axios'

const loading = ref(false)
const saving = ref(false)
const prompts = ref<Record<string, string>>({
  system_prompt: '',
  user_prompt_template: '',
})

const promptLabels: Record<string, string> = {
  system_prompt: 'Системный промпт',
  user_prompt_template: 'Пользовательский шаблон',
}

async function loadPrompts() {
  loading.value = true
  try {
    const response = await api.get('/api/admin/llm-prompts')
    prompts.value = response.data?.prompts || {
      system_prompt: '',
      user_prompt_template: '',
    }
  } catch (error) {
    console.error('Failed to load prompts:', error)
    // Initialize with empty prompts
    prompts.value = {
      system_prompt: '',
      user_prompt_template: '',
    }
  } finally {
    loading.value = false
  }
}

async function savePrompts() {
  saving.value = true
  try {
    await api.put('/api/admin/llm-prompts', {
      system_prompt: prompts.value.system_prompt || '',
      user_prompt_template: prompts.value.user_prompt_template || '',
    })
  } catch (error) {
    console.error('Failed to save prompts:', error)
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  loadPrompts()
})
</script>

<style scoped>
.mono-text :deep(textarea) {
  font-family: 'JetBrains Mono', 'Fira Code', monospace;
  font-size: 13px;
}
</style>
