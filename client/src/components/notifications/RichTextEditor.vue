<template>
  <div class="rich-editor" :class="{ 'rich-editor--focused': isFocused, 'rich-editor--error': error }">
    <label v-if="label" class="rich-editor__label" :class="{ 'rich-editor__label--active': isFocused || hasContent }">
      {{ label }}
    </label>

    <!-- Toolbar -->
    <div v-if="editor" class="rich-editor__toolbar">
      <button
        v-for="btn in toolbarButtons"
        :key="btn.action"
        type="button"
        class="toolbar-btn"
        :class="{ 'toolbar-btn--active': btn.isActive?.() }"
        :disabled="disabled"
        :title="btn.title"
        @click="btn.command()"
      >
        <v-icon :icon="btn.icon" size="18" />
      </button>
    </div>

    <!-- Editor -->
    <editor-content :editor="editor" class="rich-editor__content" />

    <!-- Error / hint -->
    <div v-if="error" class="rich-editor__error">{{ error }}</div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Underline from '@tiptap/extension-underline'

const props = withDefaults(
  defineProps<{
    modelValue?: string
    label?: string
    error?: string
    placeholder?: string
    disabled?: boolean
  }>(),
  {
    modelValue: '',
    label: '',
    error: '',
    placeholder: '',
    disabled: false,
  }
)

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
}>()

const isFocused = ref(false)

const editor = useEditor({
  content: props.modelValue,
  editable: !props.disabled,
  extensions: [
    StarterKit.configure({
      heading: { levels: [3, 4] },
    }),
    Underline,
  ],
  editorProps: {
    attributes: {
      class: 'tiptap-editor',
    },
    // Clean up Word-specific junk on paste
    transformPastedHTML(html: string) {
      return html
        // Remove Word-specific style/xml tags
        .replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '')
        .replace(/<xml[^>]*>[\s\S]*?<\/xml>/gi, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        // Remove class/style attrs with mso-* or Word-specific values
        .replace(/\s*class="[^"]*Mso[^"]*"/gi, '')
        .replace(/\s*style="[^"]*mso-[^"]*"/gi, '')
        // Clean up empty spans
        .replace(/<span\s*>\s*<\/span>/gi, '')
        // Collapse excessive whitespace in tags
        .replace(/<(\w+)(\s[^>]*)?\s*>\s*<\/\1>/gi, '')
    },
  },
  onUpdate({ editor: e }) {
    const html = e.getHTML()
    // Emit empty string for truly empty content
    emit('update:modelValue', html === '<p></p>' ? '' : html)
  },
  onFocus() {
    isFocused.value = true
  },
  onBlur() {
    isFocused.value = false
  },
})

const hasContent = computed(() => {
  return !!props.modelValue && props.modelValue !== '<p></p>'
})

// Sync external changes back into editor
watch(
  () => props.modelValue,
  (newVal) => {
    if (!editor.value) return
    const currentHTML = editor.value.getHTML()
    // Avoid resetting cursor when content hasn't really changed
    if (currentHTML !== newVal && !(newVal === '' && currentHTML === '<p></p>')) {
      editor.value.commands.setContent(newVal || '', { emitUpdate: false })
    }
  }
)

watch(
  () => props.disabled,
  (isDisabled) => {
    editor.value?.setEditable(!isDisabled)
  }
)

const toolbarButtons = computed(() => {
  if (!editor.value) return []
  const e = editor.value
  return [
    {
      action: 'bold',
      icon: 'mdi-format-bold',
      title: 'Жирный (Ctrl+B)',
      isActive: () => e.isActive('bold'),
      command: () => e.chain().focus().toggleBold().run(),
    },
    {
      action: 'italic',
      icon: 'mdi-format-italic',
      title: 'Курсив (Ctrl+I)',
      isActive: () => e.isActive('italic'),
      command: () => e.chain().focus().toggleItalic().run(),
    },
    {
      action: 'underline',
      icon: 'mdi-format-underline',
      title: 'Подчёркнутый (Ctrl+U)',
      isActive: () => e.isActive('underline'),
      command: () => e.chain().focus().toggleUnderline().run(),
    },
    {
      action: 'strike',
      icon: 'mdi-format-strikethrough',
      title: 'Зачёркнутый',
      isActive: () => e.isActive('strike'),
      command: () => e.chain().focus().toggleStrike().run(),
    },
    { action: 'sep1', icon: '', title: '', isActive: () => false, command: () => {} },
    {
      action: 'bulletList',
      icon: 'mdi-format-list-bulleted',
      title: 'Маркированный список',
      isActive: () => e.isActive('bulletList'),
      command: () => e.chain().focus().toggleBulletList().run(),
    },
    {
      action: 'orderedList',
      icon: 'mdi-format-list-numbered',
      title: 'Нумерованный список',
      isActive: () => e.isActive('orderedList'),
      command: () => e.chain().focus().toggleOrderedList().run(),
    },
    { action: 'sep2', icon: '', title: '', isActive: () => false, command: () => {} },
    {
      action: 'h3',
      icon: 'mdi-format-header-3',
      title: 'Заголовок 3',
      isActive: () => e.isActive('heading', { level: 3 }),
      command: () => e.chain().focus().toggleHeading({ level: 3 }).run(),
    },
    {
      action: 'blockquote',
      icon: 'mdi-format-quote-close',
      title: 'Цитата',
      isActive: () => e.isActive('blockquote'),
      command: () => e.chain().focus().toggleBlockquote().run(),
    },
    { action: 'sep3', icon: '', title: '', isActive: () => false, command: () => {} },
    {
      action: 'undo',
      icon: 'mdi-undo',
      title: 'Отменить (Ctrl+Z)',
      isActive: () => false,
      command: () => e.chain().focus().undo().run(),
    },
    {
      action: 'redo',
      icon: 'mdi-redo',
      title: 'Повторить (Ctrl+Y)',
      isActive: () => false,
      command: () => e.chain().focus().redo().run(),
    },
  ].filter((b) => b.icon) // filter out separators without icon
})

onBeforeUnmount(() => {
  editor.value?.destroy()
})
</script>

<style scoped>
.rich-editor {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  transition: border-color 0.2s;
  background: rgb(var(--v-theme-surface));
  position: relative;
}

.rich-editor--focused {
  border-color: rgb(var(--v-theme-primary));
  box-shadow: 0 0 0 1px rgb(var(--v-theme-primary));
}

.rich-editor--error {
  border-color: rgb(var(--v-theme-error));
}

.rich-editor__label {
  position: absolute;
  top: -9px;
  left: 10px;
  padding: 0 4px;
  font-size: 0.75rem;
  color: rgba(var(--v-theme-on-surface), 0.6);
  background: rgb(var(--v-theme-surface));
  z-index: 1;
  transition: color 0.2s;
}

.rich-editor--focused .rich-editor__label {
  color: rgb(var(--v-theme-primary));
}

.rich-editor__toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 2px;
  padding: 4px 6px;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgba(var(--v-theme-on-surface), 0.03);
  border-radius: 8px 8px 0 0;
}

.toolbar-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border: none;
  border-radius: 4px;
  background: transparent;
  cursor: pointer;
  color: rgba(var(--v-theme-on-surface), 0.7);
  transition: background 0.15s, color 0.15s;
}

.toolbar-btn:hover {
  background: rgba(var(--v-theme-on-surface), 0.08);
  color: rgba(var(--v-theme-on-surface), 0.9);
}

.toolbar-btn--active {
  background: rgba(var(--v-theme-primary), 0.12);
  color: rgb(var(--v-theme-primary));
}

.toolbar-btn:disabled {
  opacity: 0.45;
  cursor: default;
}

.rich-editor__content {
  min-height: 120px;
  max-height: 400px;
  overflow-y: auto;
}

.rich-editor__content :deep(.tiptap-editor) {
  padding: 10px 12px;
  outline: none;
  font-size: 0.875rem;
  line-height: 1.5;
  min-height: 100px;
}

.rich-editor__content :deep(.tiptap-editor p) {
  margin: 0 0 0.4em;
}

.rich-editor__content :deep(.tiptap-editor ul),
.rich-editor__content :deep(.tiptap-editor ol) {
  padding-left: 20px;
  margin: 0.3em 0;
}

.rich-editor__content :deep(.tiptap-editor li) {
  margin-bottom: 0.15em;
}

.rich-editor__content :deep(.tiptap-editor li p) {
  margin: 0;
}

.rich-editor__content :deep(.tiptap-editor h3) {
  font-size: 1.1rem;
  font-weight: 600;
  margin: 0.6em 0 0.3em;
}

.rich-editor__content :deep(.tiptap-editor h4) {
  font-size: 1rem;
  font-weight: 600;
  margin: 0.5em 0 0.25em;
}

.rich-editor__content :deep(.tiptap-editor blockquote) {
  border-left: 3px solid rgba(var(--v-theme-primary), 0.4);
  padding-left: 12px;
  margin: 0.5em 0;
  color: rgba(var(--v-theme-on-surface), 0.7);
}

.rich-editor__content :deep(.tiptap-editor p.is-editor-empty:first-child::before) {
  content: attr(data-placeholder);
  color: rgba(var(--v-theme-on-surface), 0.35);
  pointer-events: none;
  float: left;
  height: 0;
}

.rich-editor__error {
  padding: 4px 12px 6px;
  font-size: 0.75rem;
  color: rgb(var(--v-theme-error));
}
</style>
