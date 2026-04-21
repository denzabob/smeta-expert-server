<template>
  <div class="attachment-uploader">
    <input
      ref="fileInputRef"
      type="file"
      class="d-none"
      accept="image/png,image/jpeg,image/webp"
      multiple
      @change="onFileInput"
    />

    <v-sheet
      class="idea-dropzone upload-zone attachment-uploader__dropzone"
      :class="{ 'idea-dropzone--active': isDragging, 'attachment-uploader__dropzone--disabled': disabled }"
      rounded
      @click="openFileDialog"
      @dragenter.prevent="onDragEnter"
      @dragover.prevent="onDragOver"
      @dragleave.prevent="onDragLeave"
      @drop.prevent="onDrop"
    >
      <div class="attachment-uploader__title">Скриншоты</div>
      <div class="attachment-uploader__text">
        Перетащите файлы сюда, нажмите для выбора или вставьте изображение через Ctrl+V.
      </div>
      <div class="attachment-uploader__caption">
        До {{ maxFiles }} файлов, форматы: PNG, JPG, WEBP.
      </div>
    </v-sheet>

    <div v-if="files.length" class="attachment-uploader__chips">
      <v-chip
        v-for="(file, index) in files"
        :key="`${file.name}-${index}`"
        color="primary"
        variant="tonal"
        closable
        :disabled="disabled"
        @click:close="removeFile(index)"
      >
        {{ file.name }}
      </v-chip>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const props = withDefaults(defineProps<{ modelValue: File[]; maxFiles?: number; disabled?: boolean }>(), {
  maxFiles: 5,
  disabled: false,
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: File[]): void
  (e: 'error', message: string): void
}>()

const fileInputRef = ref<HTMLInputElement | null>(null)
const isDragging = ref(false)
const files = computed(() => props.modelValue ?? [])

const allowedTypes = new Set(['image/png', 'image/jpeg', 'image/webp'])

function openFileDialog() {
  if (props.disabled) {
    return
  }
  fileInputRef.value?.click()
}

function onFileInput(event: Event) {
  const input = event.target as HTMLInputElement
  const selected = Array.from(input.files ?? [])
  addFiles(selected)
  if (input) {
    input.value = ''
  }
}

function onDragEnter() {
  if (props.disabled) {
    return
  }
  isDragging.value = true
}

function onDragOver() {
  if (props.disabled) {
    return
  }
  isDragging.value = true
}

function onDragLeave(event: DragEvent) {
  const currentTarget = event.currentTarget as HTMLElement | null
  const relatedTarget = event.relatedTarget as Node | null
  if (!currentTarget || !relatedTarget || !currentTarget.contains(relatedTarget)) {
    isDragging.value = false
  }
}

function onDrop(event: DragEvent) {
  if (props.disabled) {
    return
  }
  isDragging.value = false
  const dropped = Array.from(event.dataTransfer?.files ?? [])
  addFiles(dropped)
}

function removeFile(index: number) {
  if (props.disabled) {
    return
  }

  const next = [...files.value]
  next.splice(index, 1)
  emit('update:modelValue', next)
}

function addFiles(newFiles: File[]) {
  if (props.disabled) {
    return
  }

  const next = [...files.value]

  for (const file of newFiles) {
    if (!allowedTypes.has(file.type)) {
      emit('error', `Файл ${file.name} имеет неподдерживаемый формат.`)
      continue
    }

    if (next.length >= props.maxFiles) {
      emit('error', `Можно прикрепить максимум ${props.maxFiles} файлов.`)
      break
    }

    next.push(file)
  }

  emit('update:modelValue', next)
}

function onPaste(event: ClipboardEvent) {
  if (props.disabled) {
    return
  }

  const items = Array.from(event.clipboardData?.items ?? [])
  const pastedFiles: File[] = []

  for (const item of items) {
    if (item.type.startsWith('image/')) {
      const file = item.getAsFile()
      if (file) {
        pastedFiles.push(file)
      }
    }
  }

  if (pastedFiles.length > 0) {
    addFiles(pastedFiles)
  }
}

onMounted(() => {
  document.addEventListener('paste', onPaste)
})

onBeforeUnmount(() => {
  document.removeEventListener('paste', onPaste)
})
</script>

<style scoped>
.attachment-uploader__dropzone {
  padding: 18px;
  border: 1px dashed color-mix(in srgb, rgb(var(--v-theme-primary)) 38%, var(--ds-border-color));
  border-radius: var(--ds-radius-18);
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary-container), 0.18), rgba(var(--v-theme-surface-container-lowest), 0.92));
  cursor: pointer;
  transition:
    border-color 0.18s ease,
    background-color 0.18s ease,
    transform 0.18s ease;
}

.attachment-uploader__dropzone:hover {
  border-color: rgba(var(--v-theme-primary), 0.46);
  transform: translateY(-1px);
}

.attachment-uploader__dropzone--disabled {
  opacity: 0.72;
  cursor: default;
  transform: none;
}

.attachment-uploader__dropzone--disabled:hover {
  border-color: color-mix(in srgb, rgb(var(--v-theme-primary)) 38%, var(--ds-border-color));
  transform: none;
}

.attachment-uploader__title {
  font-size: 15px;
  font-weight: 700;
  color: var(--ds-text-primary);
}

.attachment-uploader__text {
  margin-top: 6px;
  font-size: 14px;
  line-height: 1.55;
  color: var(--ds-text-secondary);
}

.attachment-uploader__caption {
  margin-top: 8px;
  font-size: 12px;
  color: var(--ds-text-tertiary);
}

.attachment-uploader__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}
</style>
