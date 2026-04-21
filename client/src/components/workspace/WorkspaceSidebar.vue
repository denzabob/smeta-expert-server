<template>
  <nav class="workspace-sidebar">
    <div class="workspace-sidebar__modules">
      <button
        v-for="mod in modules"
        :key="mod.key"
        class="sidebar-module-btn"
        :class="{
          'sidebar-module-btn--active': activeModule === mod.key,
        }"
        @click="$emit('update:activeModule', mod.key)"
        :title="mod.label"
      >
        <v-icon size="20">{{ mod.icon }}</v-icon>
        <span class="sidebar-module-btn__label">{{ mod.label }}</span>
        <template v-if="loading">
          <span class="sidebar-badge-shimmer" />
        </template>
        <template v-else>
          <v-badge
            v-if="mod.count != null && mod.count > 0"
            :content="mod.count > 99 ? '99+' : String(mod.count)"
            color="primary"
            inline
            class="sidebar-module-btn__badge"
          />
          <v-badge
            v-if="mod.warnings && mod.warnings > 0"
            :content="String(mod.warnings)"
            color="warning"
            inline
            class="sidebar-module-btn__warning"
          />
        </template>
      </button>
    </div>
  </nav>
</template>

<script setup lang="ts">
export interface SidebarModule {
  key: string
  label: string
  icon: string
  count?: number | null
  warnings?: number
}

defineProps<{
  modules: SidebarModule[]
  activeModule: string
  loading?: boolean
}>()

defineEmits<{
  'update:activeModule': [value: string]
}>()
</script>

<style scoped>
.workspace-sidebar {
  width: 200px;
  min-width: 200px;
  background:
    linear-gradient(180deg, rgba(var(--v-theme-primary), 0.04), transparent 160px),
    rgba(var(--v-theme-surface-container-low), 0.84);
  border: 1px solid rgba(var(--v-theme-outline-variant), 0.72);
  border-radius: var(--md-sys-shape-corner-extra-large);
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  padding: 10px 0;
}

.workspace-sidebar__modules {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 0 10px;
}

.sidebar-module-btn {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 46px;
  padding: 11px 14px;
  border: none;
  background: transparent;
  border-radius: var(--md-sys-shape-corner-large);
  cursor: pointer;
  font-size: 0.875rem;
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface-variant), 0.92);
  transition: background 0.15s, color 0.15s, transform 0.15s;
  text-align: left;
  width: 100%;
  position: relative;
}

.sidebar-module-btn:hover {
  background: rgba(var(--v-theme-on-surface), 0.06);
  color: rgb(var(--v-theme-on-surface));
  transform: translateY(-1px);
}

.sidebar-module-btn--active {
  background: rgba(var(--v-theme-secondary-container), 0.92);
  color: rgb(var(--v-theme-on-secondary-container));
  font-weight: 700;
}

.sidebar-module-btn--active:hover {
  background: rgba(var(--v-theme-secondary-container), 0.96);
}

.sidebar-module-btn__label {
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sidebar-module-btn__badge {
  margin-left: auto;
}

.sidebar-module-btn__warning {
  margin-left: 2px;
}

.sidebar-badge-shimmer {
  width: 28px;
  height: 18px;
  border-radius: 9px;
  margin-left: auto;
  background: linear-gradient(90deg,
    rgba(var(--v-theme-on-surface), 0.06) 25%,
    rgba(var(--v-theme-on-surface), 0.12) 50%,
    rgba(var(--v-theme-on-surface), 0.06) 75%
  );
  background-size: 200% 100%;
  animation: sidebar-shimmer 1.5s ease-in-out infinite;
}

@keyframes sidebar-shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

@media (max-width: 960px) {
  .workspace-sidebar {
    width: 56px;
    min-width: 56px;
    padding: 10px 0;
  }

  .sidebar-module-btn__label,
  .sidebar-module-btn__badge,
  .sidebar-module-btn__warning {
    display: none;
  }

  .sidebar-module-btn {
    justify-content: center;
    padding: 10px;
    min-height: 44px;
  }
}
</style>
