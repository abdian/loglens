<template>
  <div class="ll-tail-controls">
    <!-- Live / Range toggle -->
    <div class="ll-tail-controls__toggle">
      <button
        :class="['ll-tail-btn', { 'll-tail-btn--active': !tailMode }]"
        @click="setMode('range')"
      >{{ t('tail.range') }}</button>
      <button
        :class="['ll-tail-btn', { 'll-tail-btn--active': tailMode }]"
        @click="setMode('live')"
      >
        <span v-if="active" class="ll-live-dot" style="margin-inline-end:4px" />
        {{ t('tail.live') }}
      </button>
    </div>

    <!-- Time-range filter (browse mode only) -->
    <TimeRangeFilter v-if="!tailMode" />

    <!-- Transport indicator -->
    <span v-if="tailMode" class="ll-tail-controls__transport">
      {{ transport }}
    </span>

    <!-- Paused indicator -->
    <span v-if="tailMode && paused" class="ll-tail-controls__paused">
      {{ t('tail.paused') }}
    </span>

    <!-- Stop button -->
    <button v-if="tailMode && active" class="ll-tail-controls__stop" @click="stopTail">
      <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor">
        <path d="M4 4h8v8H4z"/>
      </svg>
    </button>

    <!-- Export current view (respects active filters / search) -->
    <div class="ll-tail-controls__export" v-if="entryCount">
      <button class="ll-export-btn" @click="menuOpen = !menuOpen" @blur="onBlur" :title="t('export.title')">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor">
          <path d="M7.47 10.78a.75.75 0 0 0 1.06 0l3.75-3.75a.749.749 0 1 0-1.06-1.06L8.75 8.44V1.75a.75.75 0 0 0-1.5 0v6.69L4.78 5.97a.749.749 0 1 0-1.06 1.06ZM3.75 13a.75.75 0 0 0 0 1.5h8.5a.75.75 0 0 0 0-1.5Z"/>
        </svg>
        {{ t('export.label') }}
        <span class="ll-export-btn__count">{{ entryCount.toLocaleString() }}</span>
      </button>
      <div v-if="menuOpen" class="ll-export-menu" @mousedown.prevent>
        <button class="ll-export-menu__item" @click="copyView">{{ t('export.copy') }}</button>
        <button class="ll-export-menu__item" @click="downloadView">{{ t('export.download') }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useTailStore } from '../stores/tail.js'
import { useUiStore } from '../stores/ui.js'
import { useFilesStore } from '../stores/files.js'
import { useEntriesStore } from '../stores/entries.js'
import { useSearchStore } from '../stores/search.js'
import TimeRangeFilter from './TimeRangeFilter.vue'
import { t } from '../i18n.js'

const tailStore    = useTailStore()
const uiStore      = useUiStore()
const filesStore   = useFilesStore()
const entriesStore = useEntriesStore()
const searchStore  = useSearchStore()

const tailMode  = computed(() => uiStore.tailMode)
const active    = computed(() => tailStore.active)
const paused    = computed(() => tailStore.paused)
const transport = computed(() => tailStore.transport)

const menuOpen = ref(false)

// The entries currently on screen — search results when searching, otherwise
// the filtered/browsed page. Exporting always reflects what the user sees.
const currentEntries = computed(() =>
  searchStore.hasQuery ? searchStore.results : entriesStore.entries
)
const entryCount = computed(() => currentEntries.value.length)
const truncatedCount = computed(() => currentEntries.value.filter(e => e.truncated).length)

function viewText() {
  // List/search entries carry display-truncated raw text for oversized lines.
  // Mark those so the export never silently looks complete.
  return currentEntries.value
    .map(e => (e.raw ?? e.message ?? '') + (e.truncated ? '  … [truncated — open the entry for full content]' : ''))
    .join('\n')
}

function maybeWarnTruncated() {
  if (truncatedCount.value > 0) {
    uiStore.toastWarn(t('export.truncated', { n: truncatedCount.value }))
  }
}

async function copyView() {
  menuOpen.value = false
  try {
    await navigator.clipboard?.writeText(viewText())
    uiStore.toastSuccess(t('export.copied', { n: entryCount.value }))
    maybeWarnTruncated()
  } catch {
    uiStore.toastError(t('export.failed'))
  }
}

function downloadView() {
  menuOpen.value = false
  const blob = new Blob([viewText()], { type: 'text/plain;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  const base = filesStore.activeFile?.name ?? 'loglens'
  a.href = url
  a.download = `${base.replace(/\.log$/, '')}-export.log`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  setTimeout(() => URL.revokeObjectURL(url), 1000)
  uiStore.toastSuccess(t('export.downloaded', { n: entryCount.value }))
  maybeWarnTruncated()
}

function onBlur() {
  // Let a menu-item mousedown register before closing.
  setTimeout(() => { menuOpen.value = false }, 120)
}

function setMode(mode) {
  if (mode === 'live') {
    const id = filesStore.activeFileId
    if (!id) return
    uiStore.tailMode = true
    tailStore.start([id])
  } else {
    uiStore.tailMode = false
    tailStore.stop()
  }
}

function stopTail() {
  tailStore.stop()
  uiStore.tailMode = false
}
</script>

<style scoped>
.ll-tail-controls {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 8px;
  background: var(--ll-surface);
  border-block-end: 1px solid var(--ll-border-subtle);
  flex-shrink: 0;
}

.ll-tail-controls__toggle {
  display: flex;
  background: var(--ll-surface-sunken);
  border: 1px solid var(--ll-border-subtle);
  border-radius: 5px;
  padding: 1px;
  gap: 1px;
}

.ll-tail-btn {
  display: flex;
  align-items: center;
  padding: 3px 10px;
  font-size: 11px;
  border: none;
  background: none;
  color: var(--ll-text-muted);
  cursor: pointer;
  border-radius: 4px;
  transition: background 0.1s;
}

.ll-tail-btn--active {
  background: var(--ll-surface-raised);
  color: var(--ll-text);
  box-shadow: 0 1px 2px rgba(0,0,0,0.2);
}

.ll-tail-controls__transport {
  font-size: 10px;
  color: var(--ll-text-faint);
  font-family: 'JetBrains Mono', monospace;
  text-transform: uppercase;
  padding: 1px 5px;
  background: var(--ll-surface-raised);
  border-radius: 3px;
}

.ll-tail-controls__paused {
  font-size: 11px;
  color: var(--ll-level-warning);
  flex: 1;
}

.ll-tail-controls__stop {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 4px;
  border: none;
  background: var(--ll-level-error-bg);
  color: var(--ll-level-error);
  cursor: pointer;
}

.ll-tail-controls__stop:hover {
  background: var(--ll-level-error);
  color: white;
}

.ll-tail-controls__export {
  position: relative;
  margin-inline-start: auto;
}

.ll-export-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 9px;
  font-size: 11px;
  border: 1px solid var(--ll-border-subtle);
  background: var(--ll-surface-raised);
  color: var(--ll-text-muted);
  border-radius: 5px;
  cursor: pointer;
  transition: background 0.1s, color 0.1s;
}

.ll-export-btn:hover { background: var(--ll-surface-overlay); color: var(--ll-text); }

.ll-export-btn__count {
  font-family: 'JetBrains Mono', monospace;
  font-size: 10px;
  color: var(--ll-text-faint);
  background: var(--ll-surface-sunken);
  padding: 0 5px;
  border-radius: 8px;
}

.ll-export-menu {
  position: absolute;
  inset-block-start: calc(100% + 4px);
  inset-inline-end: 0;
  background: var(--ll-surface-overlay);
  border: 1px solid var(--ll-border);
  border-radius: 6px;
  min-width: 160px;
  z-index: 50;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4);
  overflow: hidden;
}

.ll-export-menu__item {
  display: block;
  width: 100%;
  text-align: start;
  padding: 7px 12px;
  font-size: 12px;
  background: none;
  border: none;
  color: var(--ll-text);
  cursor: pointer;
}

.ll-export-menu__item:hover { background: var(--ll-surface-raised); }

.ll-live-dot {
  display: inline-block;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--ll-level-notice);
  animation: pulse 1.5s ease-in-out infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}
</style>
