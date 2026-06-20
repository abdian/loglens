<template>
  <div class="ll-app" :class="{ 'll-app--rtl': isRtl }">
    <!-- Top bar -->
    <header class="ll-topbar">
      <button class="ll-topbar__menu-btn" @click="uiStore.toggleSidebar()" :aria-label="t('sidebar.toggle')" :title="t('sidebar.toggle')">
        <svg width="18" height="18" viewBox="0 0 16 16" fill="currentColor"><path d="M1 3.75A.75.75 0 0 1 1.75 3h12.5a.75.75 0 0 1 0 1.5H1.75A.75.75 0 0 1 1 3.75Zm0 4A.75.75 0 0 1 1.75 7h12.5a.75.75 0 0 1 0 1.5H1.75A.75.75 0 0 1 1 7.75Zm0 4a.75.75 0 0 1 .75-.75h12.5a.75.75 0 0 1 0 1.5H1.75a.75.75 0 0 1-.75-.75Z"/></svg>
      </button>
      <div class="ll-topbar__brand">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--ll-accent)">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
          <polyline points="14 2 14 8 20 8"/>
          <line x1="16" y1="13" x2="8" y2="13"/>
          <line x1="16" y1="17" x2="8" y2="17"/>
          <polyline points="10 9 9 9 8 9"/>
        </svg>
        <span class="ll-topbar__title">{{ t('app.title') }}</span>
      </div>
      <div class="ll-topbar__actions">
        <span v-if="indexInfo" class="ll-index-status" :class="`ll-index-status--${indexInfo.state}`">
          <template v-if="indexInfo.state === 'building'">
            <span class="ll-spinner-xs" />
            {{ t('index.building', { percent: indexInfo.percent ?? 0 }) }}
          </template>
          <template v-else-if="indexInfo.state === 'ready'">
            <svg width="10" height="10" viewBox="0 0 16 16" fill="currentColor"><path d="M13.78 4.22a.75.75 0 0 1 0 1.06l-7.25 7.25a.75.75 0 0 1-1.06 0L2.22 9.28a.751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018L6 10.94l6.72-6.72a.75.75 0 0 1 1.06 0Z"/></svg>
            {{ t('index.ready') }}
          </template>
          <template v-else>{{ t('index.none') }}</template>
        </span>
        <!-- Rebuild search index (re-scans the file so search covers timestamps/levels) -->
        <button
          v-if="activeFileId"
          class="ll-topbar__btn"
          :disabled="rebuilding"
          @click="rebuildIndex"
          :title="t('index.rebuildHint')"
        >
          <span v-if="rebuilding" class="ll-spinner-xs" />
          <svg v-else width="13" height="13" viewBox="0 0 16 16" fill="currentColor">
            <path d="M8 2.5a5.487 5.487 0 0 0-4.131 1.869l1.204 1.204A.25.25 0 0 1 4.896 6H1.25A.25.25 0 0 1 1 5.75V2.104a.25.25 0 0 1 .427-.177l1.38 1.38A7.001 7.001 0 0 1 14.95 7.16a.75.75 0 0 1-1.49.178A5.501 5.501 0 0 0 8 2.5ZM2.04 8.662a.75.75 0 0 1 .857.626A5.501 5.501 0 0 0 8 13.5a5.487 5.487 0 0 0 4.131-1.869l-1.204-1.204A.25.25 0 0 1 11.104 10h3.646a.25.25 0 0 1 .25.25v3.646a.25.25 0 0 1-.427.177l-1.38-1.38A7.001 7.001 0 0 1 1.414 9.519a.75.75 0 0 1 .626-.857Z"/>
          </svg>
          <span class="ll-topbar__btn-label">{{ t('index.rebuild') }}</span>
        </button>
        <ThemeToggle />
        <button
          class="ll-topbar__btn"
          @click="openPalette"
          aria-label="Command palette"
          title="Command palette (Cmd+K)"
        >
          <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
            <path d="M3.372.672C3.852.239 4.498 0 5.25 0h5.5c.752 0 1.398.24 1.878.672C13.108 1.105 13.5 1.757 13.5 2.5v4a1 1 0 1 1-2 0v-.5H4.5v.5a1 1 0 1 1-2 0v-4c0-.743.392-1.395.872-1.828ZM11.5 4.5v-2a.5.5 0 0 0-.5-.5h-6a.5.5 0 0 0-.5.5v2h7ZM0 9.5A1.5 1.5 0 0 1 1.5 8h13A1.5 1.5 0 0 1 16 9.5v5a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 0 14.5v-5ZM6.5 12a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm7-1.5a1.5 1.5 0 1 0-3 0 1.5 1.5 0 0 0 3 0Z"/>
          </svg>
          <kbd class="ll-topbar__kbd">⌘K</kbd>
        </button>
        <!-- Keyboard shortcuts help -->
        <button class="ll-topbar__btn" @click="uiStore.toggleHelp()" :aria-label="t('help.title')" :title="t('help.title') + ' (?)'">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
            <path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM1.5 8a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0Zm5.927-2.479a1.3 1.3 0 0 0-.451.674.75.75 0 0 1-1.448-.39 2.8 2.8 0 0 1 .968-1.45A2.8 2.8 0 0 1 8.3 3.75c.64 0 1.247.182 1.71.56.473.387.74.95.74 1.59 0 .727-.31 1.235-.69 1.6-.225.215-.49.405-.71.56l-.13.092c-.26.184-.42.31-.52.444V9a.75.75 0 0 1-1.5 0v-.25c0-.518.246-.916.52-1.21.224-.24.5-.435.72-.59l.12-.085c.225-.16.38-.27.49-.376.13-.125.18-.24.18-.39 0-.18-.066-.31-.19-.412A1.3 1.3 0 0 0 8.3 5.25c-.337 0-.626.1-.873.271ZM9 11.75a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/>
          </svg>
        </button>
        <!-- Reports / analytics -->
        <button v-if="activeFileId" class="ll-topbar__btn" @click="uiStore.reportsOpen = true" :aria-label="t('reports.title')" :title="t('reports.title')">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
            <path d="M1.5 1.75a.75.75 0 0 0-1.5 0v11.5c0 .414.336.75.75.75h13.5a.75.75 0 0 0 0-1.5H1.5Zm12.97 3.28a.75.75 0 0 0-1.06-1.06L9 8.38 6.78 6.16a.75.75 0 0 0-1.06 0L2.97 8.91a.75.75 0 1 0 1.06 1.06l2.22-2.22 2.22 2.22a.75.75 0 0 0 1.06 0l4.94-4.94Z"/>
          </svg>
        </button>
        <!-- Diagnostics -->
        <button class="ll-topbar__btn" @click="uiStore.diagnosticsOpen = true" :aria-label="t('diagnostics.title')" :title="t('diagnostics.title')">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
            <path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM1.5 8a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0Zm7-3.25v2.992l2.028.812a.75.75 0 0 1-.557 1.392l-2.5-1A.751.751 0 0 1 7 8.25v-3.5a.75.75 0 0 1 1.5 0Z"/>
          </svg>
        </button>
        <!-- About / project links -->
        <AboutMenu />
      </div>
    </header>

    <!-- Main three-pane layout -->
    <div class="ll-main">
      <!-- Left: Sidebar -->
      <Sidebar />

      <!-- Center: content area -->
      <div class="ll-center">
        <!-- Tail / search controls -->
        <TailControls />

        <!-- Search bar -->
        <SearchBar ref="searchBarRef" />

        <!-- Active filters (levels / time range / issue) -->
        <ActiveFilters />

        <!-- Histogram (only when file is open and not in search mode) -->
        <Histogram
          v-if="activeFileId && !searchQuery && histogramBuckets.length"
          :buckets="histogramBuckets"
          :height="84"
          :granularity="histogramGranularity"
          :bucket="histogramBucket"
          :from="entriesStore.activeFilters.after"
          :to="entriesStore.activeFilters.before"
          @filter="onHistogramFilter"
          @granularity="onHistogramGranularity"
        />

        <!-- Entry list or search results -->
        <EntryList
          ref="entryListRef"
          @open-detail="openDetail"
          class="ll-center__entries"
        />
      </div>

      <!-- Drag handle to resize the detail pane (desktop only; only when the
           pane is actually showing an entry) -->
      <div
        v-if="detailOpen && !isMobile && entriesStore.selectedEntry"
        class="ll-resize-handle"
        :title="t('detail.resize')"
        @mousedown.prevent="startResize"
      />

      <!-- Right: Detail drawer -->
      <DetailDrawer
        :open="detailOpen"
        @close="closeDetail"
        @context="loadContext"
        :style="detailOpen && !isMobile ? { width: detailWidth + 'px', minWidth: '320px', maxWidth: 'none' } : null"
        :class="['ll-detail-pane', { 'll-detail-pane--open': detailOpen, 'll-detail-pane--resizing': resizing }]"
      />
    </div>

    <!-- Mobile: tap the backdrop to dismiss the sidebar drawer -->
    <div v-if="isMobile && sidebarOpen" class="ll-mobile-backdrop" @click="uiStore.toggleSidebar()" />

    <!-- Command palette -->
    <CommandPalette :open="paletteOpen" @close="uiStore.closePalette()" />

    <!-- Diagnostics -->
    <DiagnosticsPanel :open="uiStore.diagnosticsOpen" @close="uiStore.diagnosticsOpen = false" />

    <!-- Reports / analytics -->
    <ReportsPanel :open="uiStore.reportsOpen" @close="uiStore.reportsOpen = false" />

    <!-- Confirmation dialog (deletes / clears) -->
    <ConfirmDialog />
    <ShortcutHelp />

    <!-- Toast container -->
    <div class="ll-toast-container">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        :class="['ll-toast', `ll-toast--${toast.type}`]"
        @click="uiStore.dismissToast(toast.id)"
      >{{ toast.message }}</div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useUiStore }      from '../stores/ui.js'
import { useFilesStore }   from '../stores/files.js'
import { useEntriesStore } from '../stores/entries.js'
import { useSearchStore }  from '../stores/search.js'
import { useTailStore }    from '../stores/tail.js'
import { useKeyboard }     from '../composables/keyboard.js'
import { getBoot }         from '../api.js'
import { setLocale, t }    from '../i18n.js'
import api                 from '../api.js'

import Sidebar           from './Sidebar.vue'
import SearchBar         from './SearchBar.vue'
import ActiveFilters     from './ActiveFilters.vue'
import Histogram         from './Histogram.vue'
import EntryList         from './EntryList.vue'
import DetailDrawer      from './DetailDrawer.vue'
import CommandPalette    from './CommandPalette.vue'
import DiagnosticsPanel  from './DiagnosticsPanel.vue'
import ReportsPanel      from './ReportsPanel.vue'
import ConfirmDialog     from './ConfirmDialog.vue'
import ShortcutHelp      from './ShortcutHelp.vue'
import TailControls      from './TailControls.vue'
import ThemeToggle       from './ThemeToggle.vue'
import AboutMenu         from './AboutMenu.vue'

const uiStore      = useUiStore()
const filesStore   = useFilesStore()
const entriesStore = useEntriesStore()
const searchStore  = useSearchStore()
const tailStore    = useTailStore()

const searchBarRef  = ref(null)
const entryListRef  = ref(null)

const activeFileId  = computed(() => filesStore.activeFileId)
const detailOpen    = computed(() => uiStore.detailOpen)
const paletteOpen   = computed(() => uiStore.paletteOpen)
const sidebarOpen   = computed(() => uiStore.sidebarOpen)
const toasts        = computed(() => uiStore.toasts)
const indexInfo     = computed(() => entriesStore.indexInfo)
const searchQuery   = computed(() => searchStore.query)
const isRtl         = computed(() => getBoot()?.dir === 'rtl')

// Track the mobile breakpoint (matches the CSS at 820px).
const isMobile = ref(false)
let _mq = null
function syncMobile(e) { isMobile.value = e.matches }

// Resizable detail pane (persisted).
const detailWidth = ref(Number(localStorage.getItem('loglens-detail-width')) || 440)
const resizing = ref(false)
let _rsX = 0, _rsW = 0
function startResize(e) {
  resizing.value = true
  _rsX = e.clientX
  _rsW = detailWidth.value
  window.addEventListener('mousemove', onResize)
  window.addEventListener('mouseup', stopResize)
}
function onResize(e) {
  const delta = e.clientX - _rsX
  // Detail sits on the inline-end edge: dragging toward the center widens it
  // (sign flips for RTL, where the pane is on the left).
  const signed = isRtl.value ? delta : -delta
  const max = Math.min(960, Math.round(window.innerWidth * 0.75))
  detailWidth.value = Math.max(320, Math.min(max, _rsW + signed))
}
function stopResize() {
  resizing.value = false
  window.removeEventListener('mousemove', onResize)
  window.removeEventListener('mouseup', stopResize)
  try { localStorage.setItem('loglens-detail-width', String(detailWidth.value)) } catch {}
}

// On mobile, selecting a file or opening an entry should dismiss the sidebar
// drawer so the content is visible.
watch(activeFileId, () => { if (isMobile.value) uiStore.sidebarOpen = false })
watch(detailOpen,   (v) => { if (v && isMobile.value) uiStore.sidebarOpen = false })

// Histogram data
const histogramBuckets = ref([])
const histogramGranularity = ref(3600)
const histogramBucket = ref('auto') // auto | hour | day | week | month
async function loadHistogram() {
  if (!activeFileId.value) return
  try {
    // Intentionally full-range: the active time filter is rendered as a brush
    // highlight on the histogram (App passes activeFilters.after/before to the
    // component), not applied to the bucket query.
    const data = await api.getHistogram(
      activeFileId.value, undefined, undefined,
      histogramBucket.value === 'auto' ? undefined : histogramBucket.value,
    )
    histogramBuckets.value = data.buckets ?? []
    histogramGranularity.value = data.granularity ?? 3600
  } catch {
    histogramBuckets.value = []
  }
}
function onHistogramGranularity(bucket) {
  histogramBucket.value = bucket
  loadHistogram()
}

watch(activeFileId, (id) => {
  if (id) loadHistogram()
  else histogramBuckets.value = []
})

// Brushing the histogram sets the entry time filter (the single source of
// truth). The histogram stays full-range and highlights the selected window;
// clearing happens from the Active Filters bar.
// Rebuild the search index for the active file, then poll until ready. This
// re-scans the file with the current indexer (so search can match timestamps,
// levels and channels), and refreshes the view when done.
const rebuilding = ref(false)
let _indexPoll = null
async function rebuildIndex() {
  const id = activeFileId.value
  if (!id || rebuilding.value) return
  rebuilding.value = true
  if (_indexPoll) { clearTimeout(_indexPoll); _indexPoll = null }
  try {
    const res = await api.rebuildIndex(id)
    entriesStore.indexInfo = res
    uiStore.toastSuccess(t('index.rebuilding'))
    pollIndex(id, 0)
  } catch {
    uiStore.toastError(t('index.rebuildFailed'))
    rebuilding.value = false
  }
}
async function pollIndex(id, tries) {
  if (id !== activeFileId.value) { rebuilding.value = false; return }
  let state = null
  try {
    const s = await api.getIndexStatus(id)
    entriesStore.indexInfo = s
    state = s.state
  } catch {}
  if (state === 'ready' || tries >= 40) {
    rebuilding.value = false
    await entriesStore.loadFirstPage()
    loadHistogram()
    return
  }
  _indexPoll = setTimeout(() => pollIndex(id, tries + 1), 2000)
}

function onHistogramFilter({ from, to }) {
  entriesStore.applyFilters({ after: from, before: to })
}

// Detail drawer
function openDetail() {
  uiStore.openDetail()
}

function closeDetail() {
  uiStore.closeDetail()
  entriesStore.selectEntry(null)
}

async function loadContext(seq) {
  // Switch to context tab in detail drawer — already handled in DetailDrawer
}

function openPalette() {
  uiStore.openPalette()
}

// Keyboard shortcuts
useKeyboard({
  onJ:       () => entryListRef.value?.selectNext(),
  onK:       () => entryListRef.value?.selectPrev(),
  onSlash:   () => searchBarRef.value?.focus(),
  onGG:      () => entryListRef.value?.scrollToTop(),
  onG:       () => entryListRef.value?.scrollToBottom(),
  onE:       () => {
    const seq = entriesStore.findNext(entriesStore.selectedSeq, e => ['error','critical','alert','emergency'].includes(e.level))
    if (seq) entriesStore.selectEntry(seq)
  },
  onBigE:    () => {
    const seq = entriesStore.findPrev(entriesStore.selectedSeq, e => ['error','critical','alert','emergency'].includes(e.level))
    if (seq) entriesStore.selectEntry(seq)
  },
  onW:       () => {
    const seq = entriesStore.findNext(entriesStore.selectedSeq, e => e.level === 'warning')
    if (seq) entriesStore.selectEntry(seq)
  },
  onBigW:    () => {
    const seq = entriesStore.findPrev(entriesStore.selectedSeq, e => e.level === 'warning')
    if (seq) entriesStore.selectEntry(seq)
  },
  onEnter:   () => {
    if (entriesStore.selectedSeq) openDetail()
  },
  onEscape:  () => {
    if (detailOpen.value) closeDetail()
    else if (searchStore.query) searchStore.clear()
  },
  onPalette: openPalette,
  onHelp:    () => uiStore.toggleHelp(),
})

// URL state serialization
watch(
  [activeFileId, searchQuery, () => entriesStore.selectedSeq, () => entriesStore.activeFilters],
  () => {
    uiStore.serializeToUrl({
      fileId:   activeFileId.value,
      query:    searchStore.query,
      levels:   entriesStore.activeFilters.levels,
      after:    entriesStore.activeFilters.after,
      before:   entriesStore.activeFilters.before,
      selected: entriesStore.selectedSeq,
    })
  },
  { deep: true }
)

// Bootstrap
onMounted(async () => {
  // Responsive: watch the mobile breakpoint and start with the sidebar
  // collapsed on small screens so the log content is visible first.
  _mq = window.matchMedia('(max-width: 820px)')
  isMobile.value = _mq.matches
  if (isMobile.value) uiStore.sidebarOpen = false
  _mq.addEventListener?.('change', syncMobile)

  const boot = getBoot()
  setLocale(boot.locale ?? 'en')
  uiStore.initFromBoot()

  // Load files
  await filesStore.loadFiles()
  await searchStore.loadSavedSearches()

  // Restore state from URL
  const state = uiStore.deserializeFromUrl()
  if (state.fileId) {
    filesStore.setActiveFile(state.fileId)
    if (state.selected) {
      await entriesStore.loadPermalink(state.selected)
      // loadPermalink positions the page + selectedSeq but not selectedEntry;
      // selectEntry populates the drawer (instantly from the loaded page).
      await entriesStore.selectEntry(state.selected)
      uiStore.openDetail()
    } else {
      await entriesStore.openFile(state.fileId)
    }
    if (state.query) {
      searchStore.query = state.query
      await searchStore.search(state.fileId)
    }
    // Restore any persisted filter — levels OR a time range (the histogram
    // brush writes after/before with no levels).
    if (state.levels?.length || state.after || state.before) {
      await entriesStore.applyFilters({ levels: state.levels ?? [], after: state.after, before: state.before })
    }
  } else if (filesStore.allFiles.length) {
    // Auto-open most recent file
    const first = filesStore.allFiles[0]
    filesStore.setActiveFile(first.id)
    await entriesStore.openFile(first.id)
  }

  uiStore.urlLoaded = true
})

onUnmounted(() => {
  _mq?.removeEventListener?.('change', syncMobile)
  window.removeEventListener('mousemove', onResize)
  window.removeEventListener('mouseup', stopResize)
})
</script>

<style scoped>
.ll-app {
  display: flex;
  flex-direction: column;
  height: 100vh;
  width: 100vw;
  overflow: hidden;
  background: var(--ll-surface);
  color: var(--ll-text);
}

.ll-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 14px;
  height: 48px;
  background: var(--ll-surface-raised);
  border-block-end: 1px solid var(--ll-border);
  box-shadow: 0 1px 0 rgba(0, 0, 0, 0.2);
  flex-shrink: 0;
  gap: 12px;
  z-index: 5;
}

.ll-topbar__brand {
  display: flex;
  align-items: center;
  gap: 9px;
}

.ll-topbar__title {
  font-size: 15px;
  font-weight: 700;
  color: var(--ll-text);
  letter-spacing: -0.02em;
}

.ll-topbar__actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.ll-topbar__btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px;
  border-radius: 5px;
  border: 1px solid var(--ll-border-subtle);
  background: none;
  color: var(--ll-text-muted);
  cursor: pointer;
  font-size: 12px;
  transition: background 0.1s, color 0.1s;
}

.ll-topbar__btn:hover {
  background: var(--ll-surface-overlay);
  color: var(--ll-text);
}

.ll-topbar__btn:disabled {
  opacity: 0.6;
  cursor: default;
}

.ll-topbar__btn-label {
  font-size: 11px;
}

.ll-topbar__kbd {
  font-size: 10px;
  font-family: 'JetBrains Mono', monospace;
  padding: 1px 4px;
  border-radius: 3px;
  background: var(--ll-surface-sunken);
  color: var(--ll-text-faint);
  border: 1px solid var(--ll-border-subtle);
}

.ll-index-status {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  padding: 2px 6px;
  border-radius: 4px;
}

.ll-index-status--building {
  color: var(--ll-level-warning);
  background: var(--ll-level-warning-bg);
}

.ll-index-status--ready {
  color: var(--ll-level-notice);
  background: var(--ll-level-notice-bg);
}

.ll-index-status--none {
  color: var(--ll-text-faint);
}

.ll-spinner-xs {
  display: inline-block;
  width: 10px;
  height: 10px;
  border: 1.5px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.ll-main {
  display: flex;
  flex: 1;
  overflow: hidden;
  min-height: 0;
}

.ll-center {
  display: flex;
  flex-direction: column;
  flex: 1;
  overflow: hidden;
  min-width: 0;
}

.ll-center__entries {
  flex: 1;
  min-height: 0;
}

.ll-detail-pane {
  width: 0;
  overflow: hidden;
  transition: width 0.2s ease;
  flex-shrink: 0;
}

.ll-detail-pane--open {
  width: 440px;
  min-width: 320px;
  max-width: 50vw;
}

/* No width animation while actively dragging the resize handle. */
.ll-detail-pane--resizing { transition: none; }

.ll-resize-handle {
  width: 6px;
  flex-shrink: 0;
  cursor: col-resize;
  background: var(--ll-border);
  position: relative;
  z-index: 6;
  transition: background 0.1s;
}
.ll-resize-handle:hover,
.ll-resize-handle:active { background: var(--ll-accent); }
</style>
