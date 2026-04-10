<template>
  <div class="chat-attachment mt-1">
    <!-- Image: inline thumbnail with modal viewer -->
    <template v-if="isImage && !imgError">
      <!-- Loading skeleton -->
      <div v-if="loading" class="attachment-img-skeleton" />

      <!-- Loaded image -->
      <div v-else-if="blobUrl" class="attachment-img-wrapper">
        <img
          :src="blobUrl"
          :alt="attachment.original_name"
          class="attachment-img"
          @error="imgError = true"
          @click="openFullSize"
        />
        <div class="attachment-img-overlay">
          <v-icon size="20">mdi-magnify</v-icon>
        </div>
      </div>

      <!-- Failed to load -->
      <div v-else class="attachment-img-error">
        <v-icon size="20">mdi-image-broken-variant</v-icon>
      </div>
    </template>

    <!-- Generic file row (also used as image fallback) -->
    <!-- Use relativePath so the link goes through the Vite proxy (with session cookies) -->
    <a
      v-if="!isImage || imgError"
      :href="relativePath"
      target="_blank"
      rel="noopener noreferrer"
      class="attachment-file d-flex align-center gap-1 text-decoration-none"
    >
      <v-icon size="16" :color="iconColor">{{ icon }}</v-icon>
      <span class="text-caption attachment-filename">{{ attachment.original_name }}</span>
      <span class="text-caption text-disabled ml-1">{{ formatSize(attachment.size) }}</span>
    </a>

    <!-- Modal for full-size image -->
    <v-dialog
      v-model="showModal"
      max-width="90vw"
      max-height="90vh"
      class="image-modal"
    >
      <v-card class="image-modal-card">
        <div class="image-modal-header">
          <span class="image-modal-title text-caption">{{ attachment.original_name }}</span>
          <v-btn
            icon="mdi-close"
            variant="plain"
            size="small"
            @click="closeModal"
          />
        </div>
        <img
          :src="blobUrl"
          :alt="attachment.original_name"
          class="image-modal-img"
        />
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import type { ChatAttachment } from '@/api/supportChat'

const props = defineProps<{ attachment: ChatAttachment }>()

const imgError   = ref(false)
const showModal  = ref(false)
const blobUrl    = ref<string>('')
const loading    = ref(false)
const isImage    = computed(() => props.attachment.mime_type.startsWith('image/'))
const icon       = computed(() => isImage.value ? 'mdi-image-outline' : 'mdi-file-outline')
const iconColor  = computed(() => isImage.value ? 'primary' : 'grey-darken-1')

/**
 * Extract only the pathname from the absolute URL returned by Laravel.
 * This makes the request go through the Vite dev proxy (localhost:5173 → localhost:8000)
 * so session cookies are correctly forwarded.
 * In production the URL is already same-origin, but pathname still works fine.
 */
function toRelativePath(url: string): string {
  try {
    return new URL(url).pathname
  } catch {
    return url
  }
}

const relativePath = computed(() => toRelativePath(props.attachment.url))

// Fetch the image via relative path → Vite proxy → session cookies are sent
async function loadImage(): Promise<void> {
  if (!isImage.value) return
  loading.value = true
  try {
    const response = await fetch(relativePath.value, {
      credentials: 'include',
    })
    if (!response.ok) {
      imgError.value = true
      return
    }
    const blob = await response.blob()
    blobUrl.value = URL.createObjectURL(blob)
  } catch {
    imgError.value = true
  } finally {
    loading.value = false
  }
}

onMounted(loadImage)

onBeforeUnmount(() => {
  if (blobUrl.value) URL.revokeObjectURL(blobUrl.value)
})

function formatSize(bytes: number): string {
  if (bytes < 1_024)           return `${bytes} B`
  if (bytes < 1_024 * 1_024)  return `${(bytes / 1_024).toFixed(1)} KB`
  return `${(bytes / (1_024 * 1_024)).toFixed(1)} MB`
}

function openFullSize(): void {
  showModal.value = true
}

function closeModal(): void {
  showModal.value = false
}
</script>

<style scoped>
/* ═══════════════════════════════════════════════════════════════════════════
   ATTACHMENT ITEMS — Beautiful inline previews
   ═══════════════════════════════════════════════════════════════════════════ */

.chat-attachment {
  display: flex;
}

/* Loading skeleton */
.attachment-img-skeleton {
  width: 180px;
  height: 140px;
  border-radius: 8px;
  background: linear-gradient(
    90deg,
    rgba(var(--v-theme-on-surface), 0.06) 25%,
    rgba(var(--v-theme-on-surface), 0.12) 50%,
    rgba(var(--v-theme-on-surface), 0.06) 75%
  );
  background-size: 200% 100%;
  animation: shimmer 1.4s infinite;
}

@keyframes shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

/* Error state */
.attachment-img-error {
  width: 80px;
  height: 60px;
  border-radius: 8px;
  background: rgba(var(--v-theme-on-surface), 0.06);
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.2);
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(var(--v-theme-on-surface), 0.4);
}

/* Image preview wrapper with hover effects */
.attachment-img-wrapper {
  position: relative;
  display: inline-block;
  border-radius: 8px;
  overflow: hidden;
  cursor: pointer;
  max-width: 100%;
}

.attachment-img {
  display: block;
  max-width: 240px;
  max-height: 240px;
  min-width: 120px;
  min-height: 120px;
  width: auto;
  height: auto;
  border-radius: 8px;
  object-fit: cover;
  transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), filter 0.2s ease;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

.attachment-img:hover {
  transform: scale(1.04);
  filter: brightness(1.08);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.16);
}

/* Overlay on hover */
.attachment-img-overlay {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0);
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  transition: background 0.2s ease;
  color: white;
  pointer-events: none;
}

.attachment-img-wrapper:hover .attachment-img-overlay {
  background: rgba(0, 0, 0, 0.3);
}

/* Generic file link */
.attachment-file {
  padding: 6px 8px;
  border-radius: 6px;
  background: rgba(var(--v-theme-on-surface), 0.04);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  transition: all 0.15s ease;
  max-width: 240px;
}

.attachment-file:hover {
  background: rgba(var(--v-theme-on-surface), 0.08);
  border-color: rgba(var(--v-theme-on-surface), 0.12);
}

.attachment-filename {
  max-width: 160px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: rgb(var(--v-theme-on-surface));
}

/* Modal styles */
.image-modal {
  z-index: 2000;
}

.image-modal :deep(.v-overlay__content) {
  display: flex;
  align-items: center;
  justify-content: center;
}

.image-modal-card {
  display: flex;
  flex-direction: column;
  background: rgb(var(--v-theme-surface));
  border-radius: 12px;
  overflow: hidden;
  max-width: 90vw;
  max-height: 90vh;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
}

.image-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.06);
  background: rgba(var(--v-theme-on-surface), 0.02);
}

.image-modal-title {
  flex: 1;
  color: rgb(var(--v-theme-on-surface));
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.image-modal-img {
  max-width: 100%;
  max-height: calc(90vh - 50px);
  width: auto;
  height: auto;
  object-fit: contain;
  display: block;
  margin: 0 auto;
  padding: 16px;
}

/* Dark mode adjustments */
.v-theme--dark .attachment-img {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}

.v-theme--dark .attachment-img:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
}
</style>
