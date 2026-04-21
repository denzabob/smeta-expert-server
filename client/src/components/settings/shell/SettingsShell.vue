<template>
  <div class="ss-shell" :class="{ 'ss-shell--mobile': mobile }">
    <!-- Desktop sidebar nav -->
    <nav v-if="!mobile" class="ss-nav" :style="{ width: navWidth + 'px' }">
      <template v-for="section in sections" :key="section.id">
        <div v-if="section.dividerBefore" class="ss-nav-divider" />
        <button
          class="ss-nav-btn"
          :class="{ 'ss-nav-btn--active': modelValue === section.id }"
          @click="$emit('update:modelValue', section.id)"
        >
          <v-icon v-if="section.icon" :icon="section.icon" size="16" class="ss-nav-icon" />
          <span class="ss-nav-label">{{ section.title }}</span>
        </button>
      </template>
    </nav>

    <!-- Main area: (optional mobile tabs) + scrollable content + optional footer -->
    <div class="ss-main">
      <!-- Mobile horizontal scroll tabs -->
      <div v-if="mobile" class="ss-mobile-tabs">
        <button
          v-for="section in sections"
          :key="section.id"
          class="ss-mobile-tab"
          :class="{ 'ss-mobile-tab--active': modelValue === section.id }"
          @click="$emit('update:modelValue', section.id)"
        >
          <v-icon v-if="section.icon" :icon="section.icon" size="14" class="mr-1" />
          {{ section.title }}
        </button>
      </div>

      <!-- Content (scrollable) -->
      <div class="ss-content" :style="{ padding: appliedPadding }">
        <slot />
      </div>

      <!-- Footer (optional) -->
      <div v-if="$slots.footer" class="ss-footer">
        <slot name="footer" />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useDisplay } from 'vuetify'

export interface SettingsSection {
  id: string | number
  title: string
  icon?: string
  dividerBefore?: boolean
}

const props = withDefaults(defineProps<{
  sections: SettingsSection[]
  modelValue: string | number
  /** Optional: force mobile layout. Defaults to auto-detect via Vuetify useDisplay. */
  isMobile?: boolean
  navWidth?: number
  contentPadding?: string
}>(), {
  navWidth: 200,
  contentPadding: '20px 24px',
})

defineEmits<{
  'update:modelValue': [value: string | number]
}>()

const { smAndDown } = useDisplay()
// Explicit prop takes priority; otherwise auto-detect from viewport
const mobile = computed(() => props.isMobile !== undefined ? props.isMobile : smAndDown.value)
const appliedPadding = computed(() => mobile.value ? '14px 16px' : props.contentPadding)
</script>

<style scoped>
/* ── Root ────────────────────────────────────────────────────── */
.ss-shell {
  display: flex;
  flex-direction: row;
  flex: 1 1 0;
  overflow: hidden;
  background: transparent;
}

/* ── Desktop sidebar nav ─────────────────────────────────────── */
.ss-nav {
  flex-shrink: 0;
  padding: 14px 10px;
  border-right: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.04), transparent 140px),
    rgba(var(--v-theme-surface-container-low), 0.72);
  overflow-y: auto;
}

.ss-nav-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  min-height: 44px;
  padding: 10px 12px;
  margin-bottom: 4px;
  font-size: 0.84rem;
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface-variant), 0.9);
  background: transparent;
  border: none;
  border-radius: var(--md-sys-shape-corner-large);
  text-align: left;
  cursor: pointer;
  transition: background 0.13s, color 0.13s;
}

.ss-nav-btn:hover {
  background: rgba(var(--v-theme-on-surface), 0.06);
  color: rgb(var(--v-theme-on-surface));
}

.ss-nav-btn--active {
  background: rgba(var(--v-theme-secondary-container), 0.92);
  color: rgb(var(--v-theme-on-secondary-container));
  font-weight: 700;
}

.ss-nav-icon {
  flex-shrink: 0;
  opacity: 0.88;
}

.ss-nav-label {
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.ss-nav-divider {
  height: 1px;
  background: rgba(var(--v-theme-outline-variant), 0.72);
  margin: 8px 10px;
}

/* ── Main area ───────────────────────────────────────────────── */
.ss-main {
  flex: 1 1 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  min-width: 0;
}

/* ── Mobile tabs ─────────────────────────────────────────────── */
.ss-mobile-tabs {
  display: flex;
  overflow-x: auto;
  flex-shrink: 0;
  border-bottom: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.035), transparent 120px),
    rgba(var(--v-theme-surface-container-low), 0.72);
  scrollbar-width: none;
}

.ss-mobile-tabs::-webkit-scrollbar {
  display: none;
}

.ss-mobile-tab {
  display: inline-flex;
  align-items: center;
  min-height: 44px;
  padding: 10px 14px;
  font-size: 0.82rem;
  font-weight: 600;
  white-space: nowrap;
  border: none;
  background: none;
  cursor: pointer;
  color: rgba(var(--v-theme-on-surface-variant), 0.88);
  border-bottom: 3px solid transparent;
  transition: color 0.13s, border-color 0.13s;
}

.ss-mobile-tab--active {
  color: rgb(var(--v-theme-primary));
  border-bottom-color: rgb(var(--v-theme-primary));
  font-weight: 700;
}

/* ── Scrollable content ──────────────────────────────────────── */
.ss-content {
  flex: 1 1 0;
  overflow-y: auto;
  background: transparent;
}

/* ── Footer strip ────────────────────────────────────────────── */
.ss-footer {
  flex-shrink: 0;
  border-top: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  background: rgba(var(--v-theme-surface-container-low), 0.56);
}

/* ── Mobile overrides (auto-applied via .ss-shell--mobile class) ─ */
/*
 * On mobile: switch from row (sidebar | content) to column (tabs / content / footer).
 * Internal content scroll is preserved — modal contexts rely on it (fixed outer height).
 * Page context (UserSettingsView) supplies a 70vh anchor via .usd-body.
 */
.ss-shell--mobile {
  flex-direction: column;
}

.ss-shell--mobile .ss-mobile-tabs {
  min-height: 44px;
}
</style>
