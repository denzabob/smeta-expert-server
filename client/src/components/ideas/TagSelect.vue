<template>
  <div>
    <v-text-field
      class="idea-form-field"
      v-model="draft"
      label="Теги"
      density="comfortable"
      variant="solo-filled"
      placeholder="Введите тег и нажмите Enter"
      @keydown.enter.prevent="commitDraft"
      @keydown="onKeydown"
      @blur="commitDraft"
    />

    <div v-if="tags.length" class="d-flex flex-wrap ga-2 mt-2">
      <v-chip
        v-for="(tag, index) in tags"
        :key="`${tag}-${index}`"
        closable
        @click:close="removeTag(index)"
      >
        {{ tag }}
      </v-chip>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'

const props = defineProps<{ modelValue: string[] }>()
const emit = defineEmits<{ (e: 'update:modelValue', value: string[]): void }>()

const draft = ref('')
const tags = computed(() => props.modelValue ?? [])

function commitDraft() {
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
