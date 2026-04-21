<template>
  <v-form class="idea-comment-form md3-form-stack--compact" @submit.prevent="submit">
    <v-textarea
      class="idea-form-field idea-comment-form__field"
      v-model="comment"
      label="Комментарий"
      hint="Добавьте уточнение, обратную связь или аргументы по предложению"
      persistent-hint
      variant="solo-filled"
      rows="3"
      auto-grow
      :disabled="loading"
    />
    <div class="idea-comment-form__actions md3-actions-row">
      <v-btn type="submit" color="primary" variant="flat" :loading="loading" :disabled="!comment.trim()">
        Отправить
      </v-btn>
    </div>
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

<style scoped>
.idea-comment-form__actions {
  justify-content: flex-end;
}

@media (max-width: 760px) {
  .idea-comment-form__actions {
    justify-content: stretch;
  }

  .idea-comment-form__actions > * {
    flex: 1 1 100%;
  }
}
</style>
