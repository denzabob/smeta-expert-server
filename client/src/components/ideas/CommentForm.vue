<template>
  <v-form @submit.prevent="submit">
    <v-textarea
      class="idea-form-field"
      v-model="comment"
      label="Комментарий"
      variant="solo-filled"
      rows="3"
      :disabled="loading"
    />
    <v-btn type="submit" color="primary" :loading="loading" :disabled="!comment.trim()">
      Отправить
    </v-btn>
  </v-form>
</template>

<script setup lang="ts">
import { ref } from 'vue'

const props = defineProps<{ loading?: boolean }>()
const emit = defineEmits<{ (e: 'submit', value: string): void }>()

const comment = ref('')

function submit() {
  const normalized = comment.value.trim()
  if (!normalized) return

  emit('submit', normalized)
  if (!props.loading) {
    comment.value = ''
  }
}
</script>
