<template>
  <div class="ll-file-list">
    <div class="ll-panel-label">{{ t('files.title') }}</div>

    <div v-if="loading" class="ll-file-list__loading">
      <span class="ll-spinner-sm" />
    </div>

    <div v-else-if="!allFiles.length" class="ll-file-list__empty">
      {{ t('files.empty') }}
    </div>

    <template v-else>
      <!-- Root-level files -->
      <FileItem
        v-for="file in tree.root"
        :key="file.id"
        :file="file"
        :active="file.id === activeFileId"
        :sparkline="sparklines[file.id]"
        @select="selectFile"
      />

      <!-- Sub-folders -->
      <template v-for="(folderFiles, folderName) in tree.subs" :key="folderName">
        <button type="button" class="ll-folder-header" @click="toggleFolder(folderName)">
          <svg class="ll-folder-header__chev" :class="{ 'll-folder-header__chev--open': openFolders[folderName] }" width="12" height="12" viewBox="0 0 16 16" fill="currentColor">
            <path d="M6.22 3.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L9.94 8 6.22 4.28a.75.75 0 0 1 0-1.06Z"/>
          </svg>
          <span class="ll-folder-header__name">{{ folderName }}</span>
          <span class="ll-folder-header__count">{{ folderFiles.length }}</span>
        </button>
        <template v-if="openFolders[folderName]">
          <FileItem
            v-for="file in folderFiles"
            :key="file.id"
            :file="file"
            :active="file.id === activeFileId"
            indented
            :sparkline="sparklines[file.id]"
            @select="selectFile"
          />
        </template>
      </template>
    </template>
  </div>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import { useFilesStore } from '../stores/files.js'
import { useEntriesStore } from '../stores/entries.js'
import FileItem from './FileItem.vue'
import { t } from '../i18n.js'

const filesStore = useFilesStore()
const entriesStore = useEntriesStore()

const loading      = computed(() => filesStore.loading)
const allFiles     = computed(() => filesStore.allFiles)
const activeFileId = computed(() => filesStore.activeFileId)
const sparklines   = computed(() => filesStore.sparklines)

// Build a clean tree: root files first, real sub-folders grouped + collapsible.
const tree = computed(() => {
  const root = []
  const subs = {}
  for (const f of allFiles.value) {
    const folder = f.folder && f.folder !== '/' ? f.folder : null
    if (folder) (subs[folder] ??= []).push(f)
    else root.push(f)
  }
  return { root, subs }
})

const openFolders = reactive({})

watch(() => tree.value.subs, (subs) => {
  for (const name of Object.keys(subs)) {
    if (openFolders[name] === undefined) openFolders[name] = true
  }
}, { immediate: true, deep: false })

// Preload sparklines for the visible files so the list reads at a glance.
watch(allFiles, (files) => {
  files.slice(0, 16).forEach(f => filesStore.loadSparkline(f.id))
}, { immediate: true })

function toggleFolder(name) {
  openFolders[name] = !openFolders[name]
}

function selectFile(id) {
  filesStore.setActiveFile(id)
  entriesStore.openFile(id)
  filesStore.loadSparkline(id)
}
</script>

<style scoped>
.ll-file-list {
  flex: 1;
  overflow-y: auto;
  padding: 4px 6px 8px;
}

.ll-panel-label {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--ll-text-faint);
  padding: 6px 8px 8px;
}

.ll-file-list__loading,
.ll-file-list__empty {
  padding: 12px 10px;
  font-size: 12px;
  color: var(--ll-text-faint);
}

.ll-folder-header {
  display: flex;
  align-items: center;
  gap: 6px;
  width: 100%;
  padding: 5px 8px;
  cursor: pointer;
  user-select: none;
  font-size: 11px;
  color: var(--ll-text-muted);
  background: none;
  border: none;
  border-radius: var(--ll-radius-sm);
  margin-block-start: 4px;
}

.ll-folder-header:hover { color: var(--ll-text); background: var(--ll-surface-overlay); }
.ll-folder-header__chev { flex-shrink: 0; color: var(--ll-text-faint); transition: transform 0.15s ease; }
.ll-folder-header__chev--open { transform: rotate(90deg); }
.ll-folder-header__name { flex: 1; text-align: start; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ll-folder-header__count {
  font-size: 10px;
  color: var(--ll-text-faint);
  background: var(--ll-surface-overlay);
  padding: 1px 7px;
  border-radius: 10px;
}

.ll-file-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
  width: 100%;
  text-align: start;
  padding: 7px 9px;
  cursor: pointer;
  border-radius: var(--ll-radius-sm);
  margin-block: 2px;
  background: none;
  border: 1px solid transparent;
  border-inline-start: 2px solid transparent;
  transition: background 0.1s, border-color 0.1s;
}

.ll-file-item:hover { background: var(--ll-surface-overlay); }
.ll-file-item--active {
  background: var(--ll-accent-bg);
  border-inline-start-color: var(--ll-accent);
}
.ll-file-item--indented { margin-inline-start: 14px; }

.ll-file-item__name-row {
  display: flex;
  align-items: center;
  gap: 7px;
}

.ll-file-item__icon { flex-shrink: 0; color: var(--ll-text-faint); }
.ll-file-item--active .ll-file-item__icon { color: var(--ll-accent); }

.ll-file-item__name {
  flex: 1;
  font-size: 12.5px;
  font-weight: 500;
  color: var(--ll-text);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ll-file-item__gz {
  font-size: 9px;
  padding: 1px 5px;
  border-radius: 4px;
  background: var(--ll-level-notice-bg);
  color: var(--ll-level-notice);
  text-transform: uppercase;
  font-weight: 700;
  letter-spacing: 0.04em;
}

.ll-file-item__meta-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding-inline-start: 20px;
}

.ll-file-item__size {
  font-size: 10.5px;
  color: var(--ll-text-faint);
  font-family: 'JetBrains Mono', monospace;
}

.ll-spinner-sm {
  display: inline-block;
  width: 12px;
  height: 12px;
  border: 2px solid var(--ll-border);
  border-top-color: var(--ll-accent);
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }
</style>
