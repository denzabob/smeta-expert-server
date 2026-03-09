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
      class="idea-dropzone upload-zone pa-4"
      :class="{ 'idea-dropzone--active': isDragging }"
      rounded
      @click="openFileDialog"
      @dragenter.prevent="onDragEnter"
      @dragover.prevent="onDragOver"
      @dragleave.prevent="onDragLeave"
      @drop.prevent="onDrop"
    >
      <div class="text-subtitle-2">Скриншоты</div>
      <div class="text-body-2 text-medium-emphasis">
        Перетащите файлы сюда, нажмите для выбора или вставьте изображение через Ctrl+V.
      </div>
      <div class="text-caption text-medium-emphasis mt-1">
        До {{ maxFiles }} файлов, форматы: PNG, JPG, WEBP.
      </div>
    </v-sheet>

    <div v-if="files.length" class="mt-3 d-flex flex-wrap ga-2">
      <v-chip
        v-for="(file, index) in files"
        :key="`${file.name}-${index}`"
        closable
        @click:close="removeFile(index)"
      >
        {{ file.name }}
      </v-chip>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const props = withDefaults(defineProps<{ modelValue: File[]; maxFiles?: number }>(), {
  maxFiles: 5,
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
  isDragging.value = true
}

function onDragOver() {
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
  isDragging.value = false
  const dropped = Array.from(event.dataTransfer?.files ?? [])
  addFiles(dropped)
}

function removeFile(index: number) {
  const next = [...files.value]
  next.splice(index, 1)
  emit('update:modelValue', next)
}

function addFiles(newFiles: File[]) {
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
