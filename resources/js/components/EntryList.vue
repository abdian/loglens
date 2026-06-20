<template>
  <div class="ll-entry-list" ref="containerRef">
    <!-- Loading older -->
    <div v-if="loadingOlder" class="ll-entry-list__load-indicator ll-entry-list__load-indicator--top">
      <span class="ll-spinner" />
    </div>

    <!-- Virtualizer container -->
    <div
      ref="scrollRef"
      class="ll-entry-list__scroll"
      @scroll="onScroll"
    >
      <div :style="{ height: totalSize + 'px', position: 'relative' }">
        <div
          v-for="vrow in virtualRows"
          :key="rowKey(vrow.index)"
          :style="{
            position: 'absolute',
            top: 0,
            transform: `translateY(${vrow.start}px)`,
            width: '100%',
            height: ROW_HEIGHT + 'px',
            overflow: 'hidden',
          }"
        >
          <EntryRow
            :entry="getEntry(vrow.index)"
            :selected="getEntry(vrow.index)?.seq === selectedSeq"
            @select="onSelect"
            @open="onOpen"
            @delete="onDelete"
          />
        </div>
      </div>
    </div>

    <!-- Loading newer / tail -->
    <div v-if="loadingNewer || tailActive" class="ll-entry-list__load-indicator ll-entry-list__load-indicator--bottom">
      <button
        v-if="tailActive && tailPaused && tailPending"
        class="ll-tail-newbadge"
        @click="jumpToLatest"
        :title="t('tail.jumpLatest')"
      >
        {{ t('tail.newLines', { count: tailPending }) }} ↓
      </button>
      <span v-else-if="tailActive && tailPaused" class="text-xs text-[var(--ll-text-muted)]">{{ t('tail.paused') }}</span>
      <span v-else-if="tailActive" class="ll-live-dot" />
      <span v-else class="ll-spinner" />
    </div>

    <!-- Partial-results notice: the backend scan hit its byte cap before reaching
         the end; scrolling loads the next window. -->
    <div v-if="searchMode && searchTruncated && entries.length" class="ll-entry-list__partial" role="status">
      {{ t('search.partial') }}
    </div>

    <!-- Minimap -->
    <div class="ll-minimap" aria-hidden="true">
      <div
        v-for="mark in minimapMarks"
        :key="mark.seq"
        :class="['ll-minimap__mark', `ll-minimap__mark--${mark.level}`]"
        :style="{ top: mark.pct + '%' }"
        @click="scrollToSeq(mark.seq)"
      />
    </div>

    <!-- Loading overlay (first page / search) — clear feedback so it never
         feels hung -->
    <div v-if="loading" class="ll-entry-list__overlay">
      <span class="ll-spinner" />
      <span class="ll-entry-list__overlay-text">
        {{ searchMode ? t('search.searching') : t('common.loading') }}
      </span>
    </div>

    <!-- Empty state -->
    <div v-else-if="!entries.length" class="ll-entry-list__empty">
      <div class="ll-empty" role="status">
        <svg class="ll-empty__icon" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <template v-if="searchMode"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></template>
          <template v-else-if="noFile"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="7" y1="9" x2="17" y2="9"/><line x1="7" y1="13" x2="13" y2="13"/></template>
          <template v-else><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></template>
        </svg>
        <div class="ll-empty__title">
          {{ searchMode ? t('search.noResults') : (noFile ? t('entries.selectFile') : t('entries.empty')) }}
        </div>
        <div class="ll-empty__hint">
          {{ searchMode ? t('search.noResultsHint') : (noFile ? t('entries.selectFileHint') : t('entries.emptyHint')) }}
        </div>
        <div v-if="searchMode" class="ll-empty__examples">
          <code v-for="ex in queryExamples" :key="ex" class="ll-empty__ex">{{ ex }}</code>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useVirtualizer } from '@tanstack/vue-virtual'
import { useEntriesStore } from '../stores/entries.js'
import { useSearchStore } from '../stores/search.js'
import { useTailStore } from '../stores/tail.js'
import { t } from '../i18n.js'
import EntryRow from './EntryRow.vue'

const entriesStore = useEntriesStore()
const searchStore = useSearchStore()
const tailStore = useTailStore()

// In search mode the list is sourced from the search store (newest-first);
// in live-tail mode the file's newest page is followed by the streamed tail
// entries (which arrive at the bottom); otherwise it streams indexed entries.
const searchMode  = computed(() => searchStore.hasQuery)
const entries     = computed(() => {
  if (searchMode.value) return searchStore.results
  if (tailStore.active) return [...entriesStore.entries, ...tailStore.entries]
  return entriesStore.entries
})
const selectedSeq = computed(() => entriesStore.selectedSeq)
const loadingOlder = computed(() => searchMode.value ? false : entriesStore.loadingOlder)
const loadingNewer = computed(() => searchMode.value ? searchStore.loadingMore : entriesStore.loadingNewer)
const loading     = computed(() => searchMode.value ? searchStore.loading : entriesStore.loading)
const tailActive  = computed(() => !searchMode.value && tailStore.active)
const tailPaused  = computed(() => tailStore.paused)
const tailPending = computed(() => tailStore.pendingCount)
const searchTruncated = computed(() => searchStore.truncated)
const noFile      = computed(() => !entriesStore.activeFileId)
const queryExamples = ['level:error', 'after:-1h', '"payment failed"', '/timeout/']

// Resume live tail and flush buffered lines, then snap to the newest entry.
function jumpToLatest() {
  tailStore.resume()
  nextTick(() => scrollToBottom())
}

const scrollRef = ref(null)
const containerRef = ref(null)

// -- Virtualizer (fixed row height) --
// Rows are single-line (nowrap + ellipsis), so their height is constant. Using
// a fixed size lets the virtualizer skip per-row measurement entirely — a big
// scroll-performance win over dynamic measuring.
const ROW_HEIGHT = 31

const virtualizer = useVirtualizer(computed(() => ({
  count: entries.value.length,
  getScrollElement: () => scrollRef.value,
  estimateSize: () => ROW_HEIGHT,
  overscan: 12,
})))

const virtualRows = computed(() => virtualizer.value.getVirtualItems())
const totalSize   = computed(() => virtualizer.value.getTotalSize())

function getEntry(index) {
  return entries.value[index]
}

// Stable key: indexed entries key by seq; tail entries (seq null) key by their
// stamped uid. Prefixed so a seq can never collide with a uid.
function rowKey(index) {
  const e = entries.value[index]
  if (!e) return 'i' + index
  if (e.seq != null) return 's' + e.seq
  if (e._uid != null) return 't' + e._uid
  return 'i' + index
}

// -- Scroll & pagination --
const LOAD_THRESHOLD = 200  // px from edge to trigger load
let _isPinnedBottom = true
let _suppressScrollLoad = false

function onScroll(e) {
  const el = e.target
  const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight
  const distanceFromTop = el.scrollTop

  // Search mode: results are newest-first; scrolling down loads older matches.
  if (searchMode.value) {
    if (!_suppressScrollLoad && distanceFromBottom < LOAD_THRESHOLD && searchStore.hasMore && !searchStore.loadingMore) {
      searchStore.loadMore()
    }
    return
  }

  // Tail pin: if within 200px of bottom, stay pinned
  if (tailActive.value) {
    if (distanceFromBottom > 300) {
      _isPinnedBottom = false
      tailStore.pause()
    } else {
      _isPinnedBottom = true
      if (tailPaused.value) tailStore.resume()
    }
  }

  if (_suppressScrollLoad) return

  // Load older on reaching top
  if (distanceFromTop < LOAD_THRESHOLD && entriesStore.hasOlder && !loadingOlder.value) {
    const prevCount = entries.value.length
    const prevScrollTop = el.scrollTop
    entriesStore.loadOlder().then(() => {
      // Prepending shifts every row down by added*ROW_HEIGHT. With a fixed row
      // height the compensation is exact, so the viewport stays put (no jump).
      const added = entries.value.length - prevCount
      if (added > 0 && scrollRef.value) {
        nextTick(() => {
          if (scrollRef.value) scrollRef.value.scrollTop = prevScrollTop + added * ROW_HEIGHT
        })
      }
    })
  }

  // Load newer on reaching bottom — but never during live tail (the stream owns
  // the bottom; paginating there would fetch entries that collide with it).
  if (!tailActive.value && distanceFromBottom < LOAD_THRESHOLD && entriesStore.hasNewer && !loadingNewer.value) {
    entriesStore.loadNewer()
  }
}

// Scroll to a specific seq
function scrollToSeq(seq) {
  const idx = entries.value.findIndex(e => e.seq === seq)
  if (idx === -1) return
  virtualizer.value.scrollToIndex(idx, { align: 'center' })
}

// Auto-scroll to bottom in tail mode. Keyed on `received` (a monotonic arrival
// counter) rather than entries.length, which stops changing once the tail
// buffer hits its cap (push+shift keeps length constant).
watch(() => tailStore.received, () => {
  if (tailActive.value && _isPinnedBottom && scrollRef.value) {
    nextTick(() => {
      if (scrollRef.value) {
        scrollRef.value.scrollTop = scrollRef.value.scrollHeight
      }
    })
  }
})

// Scroll the selected entry into view ONLY when it isn't already visible —
// 'auto' alignment scrolls the minimum needed, so j/k navigation no longer
// yanks the viewport to re-center on every keystroke.
watch(selectedSeq, (seq) => {
  if (seq === null) return
  const idx = entries.value.findIndex(e => e.seq === seq)
  if (idx !== -1) virtualizer.value.scrollToIndex(idx, { align: 'auto' })
})

// Scroll to bottom whenever the first page (re)loads — on file open AND on every
// filter change — so the newest matching entry is visible at the bottom.
watch(() => entriesStore.reloadToken, () => {
  if (searchMode.value) return
  nextTick(() => {
    if (scrollRef.value) {
      scrollRef.value.scrollTop = scrollRef.value.scrollHeight
    }
    _isPinnedBottom = true
  })
})

// When a NEW search runs (query changes), jump to the top — newest match first.
// Tied to the query string, not results, so paging (loadMore) keeps its position.
watch(() => searchStore.query, () => {
  if (!searchMode.value) return
  nextTick(() => { if (scrollRef.value) scrollRef.value.scrollTop = 0 })
})

// -- Minimap --
const MINIMAP_LEVELS = new Set(['error', 'critical', 'alert', 'emergency', 'warning'])

const minimapMarks = computed(() => {
  const list = entries.value
  if (!list.length) return []
  const marks = []
  for (let i = 0; i < list.length; i++) {
    const e = list[i]
    if (MINIMAP_LEVELS.has(e.level)) {
      marks.push({
        seq: e.seq,
        level: e.level,
        pct: (i / list.length) * 100,
      })
    }
  }
  return marks
})

// -- Event handlers --
// A single click both selects the row AND opens the detail drawer — the most
// discoverable behavior (double-click also works, via onOpen).
function onSelect(seq) {
  entriesStore.selectEntry(seq)
  emit('open-detail')
}

function onOpen(seq) {
  entriesStore.selectEntry(seq)
  // Signal parent to open detail drawer
  emit('open-detail')
}

function onDelete(seq) {
  entriesStore.deleteEntry(seq)
}

const emit = defineEmits(['open-detail'])

// -- Keyboard nav (exposed for parent) --
function selectNext() {
  const list = entries.value
  const idx = list.findIndex(e => e.seq === selectedSeq.value)
  if (idx < list.length - 1) {
    entriesStore.selectEntry(list[idx + 1].seq)
  }
}

function selectPrev() {
  const list = entries.value
  const idx = list.findIndex(e => e.seq === selectedSeq.value)
  if (idx > 0) {
    entriesStore.selectEntry(list[idx - 1].seq)
  }
}

function scrollToTop() {
  if (scrollRef.value) scrollRef.value.scrollTop = 0
}

function scrollToBottom() {
  if (scrollRef.value) scrollRef.value.scrollTop = scrollRef.value.scrollHeight
}

defineExpose({ scrollToSeq, selectNext, selectPrev, scrollToTop, scrollToBottom })
</script>

<style scoped>
.ll-entry-list {
  position: relative;
  display: flex;
  flex-direction: column;
  flex: 1;
  overflow: hidden;
  min-height: 0;
}

.ll-entry-list__scroll {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding-inline-end: 10px; /* room for minimap */
  position: relative;
}

.ll-entry-list__load-indicator {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 4px;
  flex-shrink: 0;
  background: var(--ll-surface);
  border-block: 1px solid var(--ll-border-subtle);
}

.ll-entry-list__load-indicator--top { order: -1; }

.ll-entry-list__empty {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}

.ll-entry-list__overlay {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  background: color-mix(in srgb, var(--ll-surface) 70%, transparent);
  z-index: 4;
}

.ll-entry-list__overlay-text {
  font-size: 12px;
  color: var(--ll-text-muted);
}

.ll-tail-newbadge {
  font-size: 11px;
  font-weight: 600;
  color: #fff;
  background: var(--ll-accent);
  border: none;
  border-radius: 999px;
  padding: 2px 12px;
  cursor: pointer;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}
.ll-tail-newbadge:hover { filter: brightness(1.08); }

.ll-entry-list__partial {
  flex-shrink: 0;
  text-align: center;
  font-size: 11px;
  color: var(--ll-text-muted);
  padding: 4px;
  background: var(--ll-surface-sunken);
  border-block-start: 1px solid var(--ll-border-subtle);
}

.ll-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  text-align: center;
  padding: 20px;
  max-width: 360px;
  pointer-events: auto;
}
.ll-empty__icon { color: var(--ll-text-faint); opacity: 0.7; margin-block-end: 4px; }
.ll-empty__title { font-size: 14px; font-weight: 600; color: var(--ll-text-muted); }
.ll-empty__hint { font-size: 12px; color: var(--ll-text-faint); line-height: 1.5; }
.ll-empty__examples { display: flex; flex-wrap: wrap; gap: 6px; justify-content: center; margin-block-start: 6px; }
.ll-empty__ex {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  color: var(--ll-text-muted);
  background: var(--ll-surface-sunken);
  border: 1px solid var(--ll-border-subtle);
  border-radius: 4px;
  padding: 1px 6px;
}

.ll-spinner {
  display: inline-block;
  width: 16px;
  height: 16px;
  border: 2px solid var(--ll-border);
  border-top-color: var(--ll-accent);
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.ll-live-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--ll-level-notice);
  animation: pulse 1.5s ease-in-out infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.6; transform: scale(0.8); }
}
</style>
