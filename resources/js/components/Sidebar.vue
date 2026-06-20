<template>
  <aside class="ll-sidebar" :class="{ 'll-sidebar--collapsed': !sidebarOpen }">
    <!-- Sidebar header with panel tabs -->
    <div class="ll-sidebar__header">
      <!-- Expanded: tabs + collapse toggle -->
      <template v-if="sidebarOpen">
        <button
          :class="['ll-sidebar-tab', { 'll-sidebar-tab--active': panel === 'files' }]"
          @click="panel = 'files'"
          :title="t('files.title')"
        >
          <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
            <path d="M1.75 1A1.75 1.75 0 0 0 0 2.75v10.5C0 14.216.784 15 1.75 15h12.5A1.75 1.75 0 0 0 16 13.25v-8.5A1.75 1.75 0 0 0 14.25 3H7.5a.25.25 0 0 1-.2-.1l-.9-1.2C6.07 1.26 5.55 1 5 1H1.75Z"/>
          </svg>
          <span class="ll-sidebar-tab__label">{{ t('files.title') }}</span>
        </button>
        <button
          :class="['ll-sidebar-tab', { 'll-sidebar-tab--active': panel === 'issues' }]"
          @click="panel = 'issues'"
          :title="t('groups.tabHint')"
        >
          <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
            <path d="M6.457 1.047c.659-1.234 2.427-1.234 3.086 0l6.082 11.378A1.75 1.75 0 0 1 14.082 15H1.918a1.75 1.75 0 0 1-1.543-2.575Zm1.763.707a.25.25 0 0 0-.44 0L1.698 13.132a.25.25 0 0 0 .22.368h12.164a.25.25 0 0 0 .22-.368Zm.53 3.996v2.5a.75.75 0 0 1-1.5 0v-2.5a.75.75 0 0 1 1.5 0ZM9 11a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/>
          </svg>
          <span class="ll-sidebar-tab__label">{{ t('groups.title') }}</span>
        </button>
        <button class="ll-sidebar__collapse-btn ms-auto" @click="uiStore.toggleSidebar()" :title="t('sidebar.collapse')">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
            <path d="M6.22 3.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L9.94 8 6.22 4.28a.75.75 0 0 1 0-1.06Z" transform="scale(-1,1) translate(-16,0)"/>
          </svg>
        </button>
      </template>
      <!-- Collapsed: a single expand button that always fits the 40px rail -->
      <button v-else class="ll-sidebar__expand-btn" @click="uiStore.toggleSidebar()" :title="t('sidebar.expand')">
        <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor">
          <path d="M6.22 3.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L9.94 8 6.22 4.28a.75.75 0 0 1 0-1.06Z"/>
        </svg>
      </button>
    </div>

    <!-- Panel content -->
    <div class="ll-sidebar__content">
      <template v-if="panel === 'files'">
        <LevelFacets />
        <div class="ll-sidebar__divider" />
        <FileList />
      </template>
      <template v-else>
        <IssuesPanel />
      </template>
    </div>
  </aside>
</template>

<script setup>
import { computed } from 'vue'
import { useUiStore } from '../stores/ui.js'
import FileList from './FileList.vue'
import LevelFacets from './LevelFacets.vue'
import IssuesPanel from './IssuesPanel.vue'
import { t } from '../i18n.js'

const uiStore = useUiStore()
const sidebarOpen = computed(() => uiStore.sidebarOpen)
const panel = computed({
  get: () => uiStore.sidebarPanel,
  set: (v) => { uiStore.sidebarPanel = v },
})
</script>

<style scoped>
.ll-sidebar {
  display: flex;
  flex-direction: column;
  width: 244px;
  min-width: 200px;
  max-width: 340px;
  background: var(--ll-surface-raised);
  border-inline-end: 1px solid var(--ll-border);
  overflow: hidden;
  transition: width 0.2s ease;
  flex-shrink: 0;
}

.ll-sidebar--collapsed {
  width: 40px;
  min-width: 40px;
}

.ll-sidebar--collapsed .ll-sidebar-tab__label,
.ll-sidebar--collapsed .ll-sidebar__content {
  display: none;
}

.ll-sidebar__header {
  display: flex;
  align-items: center;
  padding: 4px 4px;
  border-block-end: 1px solid var(--ll-border);
  gap: 2px;
  flex-shrink: 0;
}

.ll-sidebar-tab {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 5px 8px;
  border: none;
  background: none;
  color: var(--ll-text-muted);
  cursor: pointer;
  border-radius: 5px;
  font-size: 12px;
  transition: background 0.1s, color 0.1s;
  white-space: nowrap;
}

.ll-sidebar-tab:hover { background: var(--ll-surface-overlay); color: var(--ll-text); }
.ll-sidebar-tab--active { background: var(--ll-accent-bg); color: var(--ll-accent); }

.ll-sidebar__collapse-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: none;
  background: none;
  color: var(--ll-text-faint);
  cursor: pointer;
  border-radius: 4px;
  margin-inline-start: auto;
}

.ll-sidebar__collapse-btn:hover { background: var(--ll-surface-overlay); color: var(--ll-text); }

.ll-sidebar__expand-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  margin: 0 auto;
  border: none;
  background: none;
  color: var(--ll-text-muted);
  cursor: pointer;
  border-radius: 5px;
}
.ll-sidebar__expand-btn:hover { background: var(--ll-surface-overlay); color: var(--ll-text); }

.ll-sidebar__content {
  display: flex;
  flex-direction: column;
  flex: 1;
  overflow: hidden;
}

.ll-sidebar__divider {
  height: 1px;
  background: var(--ll-border-subtle);
  margin: 4px 0;
  flex-shrink: 0;
}
</style>
