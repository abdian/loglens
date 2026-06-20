<template>
  <Teleport to="body">
    <div v-if="open" class="ll-overlay-backdrop" @click="close" />
    <div
      v-if="open"
      ref="paletteRef"
      class="ll-palette"
      role="dialog"
      aria-modal="true"
      aria-label="Command palette"
      @keydown.escape="close"
    >
      <div class="ll-palette__input-wrap">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" style="color:var(--ll-text-faint);flex-shrink:0">
          <path d="M10.68 11.74a6 6 0 0 1-7.922-8.982 6 6 0 0 1 8.982 7.922l3.04 3.04a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215ZM11.5 7a4.499 4.499 0 1 0-8.997 0A4.499 4.499 0 0 0 11.5 7Z"/>
        </svg>
        <input
          ref="inputRef"
          v-model="query"
          type="text"
          class="ll-palette__input"
          :placeholder="t('palette.placeholder')"
          dir="auto"
          autocomplete="off"
          @keydown.arrow-down.prevent="move(1)"
          @keydown.arrow-up.prevent="move(-1)"
          @keydown.enter.prevent="runSelected"
        />
      </div>

      <div class="ll-palette__results">
        <div
          v-for="(item, i) in filtered"
          :key="item.id"
          :class="['ll-palette-item', { 'll-palette-item--active': i === selectedIdx }]"
          @mouseenter="selectedIdx = i"
          @click="run(item)"
        >
          <span class="ll-palette-item__icon" v-html="item.icon" />
          <span class="ll-palette-item__label">{{ item.label }}</span>
          <span v-if="item.shortcut" class="ll-palette-item__shortcut">{{ item.shortcut }}</span>
        </div>
        <div v-if="!filtered.length" class="ll-palette__empty">No commands found.</div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { useUiStore } from '../stores/ui.js'
import { useFilesStore } from '../stores/files.js'
import { useEntriesStore } from '../stores/entries.js'
import { useSearchStore } from '../stores/search.js'
import { useTailStore } from '../stores/tail.js'
import { useFocusTrap } from '../composables/focusTrap.js'
import { t } from '../i18n.js'

const props = defineProps({ open: Boolean })
const emit = defineEmits(['close'])
const paletteRef = ref(null)
const { activate, release } = useFocusTrap()

const uiStore = useUiStore()
const filesStore = useFilesStore()
const entriesStore = useEntriesStore()
const searchStore = useSearchStore()
const tailStore = useTailStore()

const query = ref('')
const selectedIdx = ref(0)
const inputRef = ref(null)

watch(() => props.open, async (v) => {
  if (v) {
    query.value = ''
    selectedIdx.value = 0
    await nextTick()
    activate(paletteRef.value)
    inputRef.value?.focus()
  } else {
    release()
  }
})

const commands = computed(() => [
  {
    id: 'theme-dark',
    label: t('theme.dark'),
    icon: '🌑',
    action: () => uiStore.setTheme('dark'),
  },
  {
    id: 'theme-light',
    label: t('theme.light'),
    icon: '☀️',
    action: () => uiStore.setTheme('light'),
  },
  {
    id: 'theme-system',
    label: t('theme.system'),
    icon: '💻',
    action: () => uiStore.setTheme('system'),
  },
  {
    id: 'toggle-sidebar',
    label: 'Toggle sidebar',
    icon: '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M1 2.75A.75.75 0 0 1 1.75 2h12.5a.75.75 0 0 1 0 1.5H1.75A.75.75 0 0 1 1 2.75Zm0 5A.75.75 0 0 1 1.75 7h12.5a.75.75 0 0 1 0 1.5H1.75A.75.75 0 0 1 1 7.75ZM1.75 12h12.5a.75.75 0 0 1 0 1.5H1.75a.75.75 0 0 1 0-1.5Z"/></svg>',
    action: () => uiStore.toggleSidebar(),
    shortcut: 'B',
  },
  {
    id: 'issues-panel',
    label: 'Show Issues',
    icon: '⚡',
    action: () => { uiStore.sidebarPanel = 'issues'; close() },
  },
  {
    id: 'files-panel',
    label: 'Show Files',
    icon: '📁',
    action: () => { uiStore.sidebarPanel = 'files'; close() },
  },
  {
    id: 'reload-files',
    label: 'Reload file list',
    icon: '🔄',
    action: () => filesStore.loadFiles(),
  },
  {
    id: 'diagnostics',
    label: t('diagnostics.title'),
    icon: '🔧',
    action: () => { uiStore.diagnosticsOpen = !uiStore.diagnosticsOpen; close() },
  },
  {
    id: 'tail-start',
    label: 'Start live tail',
    icon: '📡',
    action: () => {
      const id = filesStore.activeFileId
      if (id) { tailStore.start([id]); uiStore.tailMode = true }
      close()
    },
  },
  {
    id: 'tail-stop',
    label: 'Stop live tail',
    icon: '⏹',
    action: () => { tailStore.stop(); uiStore.tailMode = false; close() },
  },
  {
    id: 'clear-search',
    label: 'Clear search',
    icon: '✕',
    action: () => searchStore.clear(),
    shortcut: 'Esc',
  },
  {
    id: 'download-file',
    label: t('actions.download'),
    icon: '⬇️',
    action: () => {
      const id = filesStore.activeFileId
      if (id) filesStore.downloadFile(id)
      close()
    },
  },
  {
    id: 'clear-file',
    label: t('actions.clear'),
    icon: '🗑',
    action: async () => {
      const id = filesStore.activeFileId
      if (id && confirm('Clear this log file?')) {
        await filesStore.clearFile(id)
        uiStore.toastSuccess('File cleared.')
      }
      close()
    },
  },
  // Shortcut cheat-sheet items (read-only labels)
  { id: 'ks-j',   label: 'j — Next entry',          icon: '⌨️', shortcut: 'j',   action: close },
  { id: 'ks-k',   label: 'k — Previous entry',       icon: '⌨️', shortcut: 'k',   action: close },
  { id: 'ks-sl',  label: '/ — Focus search',          icon: '⌨️', shortcut: '/',   action: close },
  { id: 'ks-gg',  label: 'gg — Scroll to top',        icon: '⌨️', shortcut: 'gg',  action: close },
  { id: 'ks-G',   label: 'G — Scroll to bottom',      icon: '⌨️', shortcut: 'G',   action: close },
  { id: 'ks-e',   label: 'e — Next error',            icon: '⌨️', shortcut: 'e',   action: close },
  { id: 'ks-E',   label: 'E — Previous error',        icon: '⌨️', shortcut: 'E',   action: close },
  { id: 'ks-w',   label: 'w — Next warning',          icon: '⌨️', shortcut: 'w',   action: close },
  { id: 'ks-W',   label: 'W — Previous warning',      icon: '⌨️', shortcut: 'W',   action: close },
  { id: 'ks-Ent', label: 'Enter — Open detail',       icon: '⌨️', shortcut: '↵',   action: close },
  { id: 'ks-Esc', label: 'Esc — Close detail',        icon: '⌨️', shortcut: 'Esc', action: close },
])

const filtered = computed(() => {
  const q = query.value.toLowerCase()
  if (!q) return commands.value
  return commands.value.filter(c => c.label.toLowerCase().includes(q))
})

watch(filtered, () => { selectedIdx.value = 0 })

function move(delta) {
  const max = filtered.value.length - 1
  selectedIdx.value = Math.max(0, Math.min(max, selectedIdx.value + delta))
}

function runSelected() {
  const item = filtered.value[selectedIdx.value]
  if (item) run(item)
}

function run(item) {
  item.action()
  if (item.id !== 'clear-file' && item.id !== 'tail-start' && item.id !== 'tail-stop') close()
}

function close() {
  emit('close')
}
</script>

<style scoped>
.ll-palette {
  position: fixed;
  inset-block-start: 15vh;
  inset-inline-start: 50%;
  transform: translateX(-50%);
  width: min(600px, calc(100vw - 32px));
  background: var(--ll-surface-overlay);
  border: 1px solid var(--ll-border);
  border-radius: 10px;
  box-shadow: 0 16px 48px rgba(0,0,0,0.6);
  z-index: 50;
  overflow: hidden;
  animation: fadeIn 0.12s ease-out;
}

@keyframes fadeIn { from { opacity: 0; transform: translateX(-50%) translateY(-8px); } to { opacity: 1; transform: translateX(-50%) translateY(0); } }

.ll-palette__input-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  border-block-end: 1px solid var(--ll-border);
}

.ll-palette__input {
  flex: 1;
  background: none;
  border: none;
  outline: none;
  font-size: 15px;
  color: var(--ll-text);
}

.ll-palette__results {
  max-height: 400px;
  overflow-y: auto;
  padding: 4px;
}

.ll-palette-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
  color: var(--ll-text);
}

.ll-palette-item--active { background: var(--ll-accent-bg); }
.ll-palette-item__icon { font-size: 14px; width: 20px; text-align: center; flex-shrink: 0; }
.ll-palette-item__label { flex: 1; }
.ll-palette-item__shortcut {
  font-size: 11px;
  font-family: 'JetBrains Mono', monospace;
  padding: 1px 5px;
  border-radius: 3px;
  background: var(--ll-surface-sunken);
  color: var(--ll-text-faint);
  border: 1px solid var(--ll-border);
}

.ll-palette__empty {
  padding: 16px;
  text-align: center;
  font-size: 13px;
  color: var(--ll-text-faint);
}
</style>
