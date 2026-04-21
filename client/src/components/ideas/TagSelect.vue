<template>
  <div class="tag-select">
    <v-text-field
      class="idea-form-field tag-select__field"
      v-model="draft"
      label="Теги"
      density="comfortable"
      variant="solo-filled"
      placeholder="Введите тег и нажмите Enter"
      hint="Например: UI, каталог, цены, отчёты"
      persistent-hint
      :disabled="disabled"
      @keydown.enter.prevent="commitDraft"
      @keydown="onKeydown"
      @blur="commitDraft"
    />

    <div v-if="tags.length" class="tag-select__chips">
      <v-chip
        v-for="(tag, index) in tags"
        :key="`${tag}-${index}`"
        color="primary"
        variant="tonal"
        closable
        :disabled="disabled"
        @click:close="removeTag(index)"
      >
        {{ tag }}
      </v-chip>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'

const props = withDefaults(defineProps<{ modelValue: string[]; disabled?: boolean }>(), {
  disabled: false,
})
const emit = defineEmits<{ (e: 'update:modelValue', value: string[]): void }>()

const draft = ref('')
const tags = computed(() => props.modelValue ?? [])

function commitDraft() {
  if (props.disabled) {
    return
  }

  const normalized = draft.value.trim()
  if (!normalized) {
    draft.value = ''
    return
  }

  if (tags.value.includes(normalized)) {
    draft.value = ''
    return
  }

  emit('update:modelValue', [...tags.value, normalized])
  draft.value = ''
}

function removeTag(index: number) {
  if (props.disabled) {
    return
  }

  const next = [...tags.value]
  next.splice(index, 1)
  emit('update:modelValue', next)
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === ',') {
    event.preventDefault()
    commitDraft()
  }
}
</script>

<style scoped>
.tag-select__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}
</style>
