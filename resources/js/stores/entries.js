import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../api.js'
import { useUiStore } from './ui.js'
import { useSearchStore } from './search.js'
import { t } from '../i18n.js'

export const useEntriesStore = defineStore('entries', () => {
  // State
  const entries = ref([])
  const cursor = ref({ older: null, newer: null })
  const indexInfo = ref(null)
  const fileInfo = ref(null)
  const loading = ref(false)
  const loadingOlder = ref(false)
  const loadingNewer = ref(false)
  const error = ref(null)
  const selectedSeq = ref(null)
  const selectedEntry = ref(null)
  const detailLoading = ref(false)

  // Active file + filter state (kept here for cursor continuity)
  const activeFileId = ref(null)
  const activeFilters = ref({ levels: [], after: null, before: null, group: null, groupLabel: null })
  // Bumped every time the first page is (re)loaded, so the list can scroll to
  // the newest entry on open AND on every filter change.
  const reloadToken = ref(0)

  // Monotonic load generation. Every primary load (open/first-page/jump/
  // permalink/clear) bumps it; each in-flight loader captures it and discards
  // its result if a newer load has started — so a slow response for file/filter
  // A can't overwrite the view after the user switched to B.
  let _loadGen = 0

  const selectedIndex = computed(() =>
    entries.value.findIndex(e => e.seq === selectedSeq.value)
  )

  const hasOlder = computed(() => !!cursor.value.older)
  const hasNewer = computed(() => !!cursor.value.newer)
  const hasActiveFilters = computed(() => {
    const f = activeFilters.value
    return !!(f.levels?.length || f.after || f.before || f.group != null)
  })

  /** Reset to an empty, file-less view (e.g. after the active file is deleted). */
  function clearActive() {
    activeFileId.value = null
    entries.value = []
    cursor.value = { older: null, newer: null }
    selectedSeq.value = null
    selectedEntry.value = null
    fileInfo.value = null
    indexInfo.value = null
    error.value = null
    activeFilters.value = { levels: [], after: null, before: null, group: null, groupLabel: null }
    _loadGen++ // invalidate any in-flight loads for the now-closed file
    useUiStore().closeDetail() // keep the drawer (and its resize handle) in sync
    reloadToken.value++
  }

  // Actions
  async function openFile(fileId, limit = 100) {
    activeFileId.value = fileId
    entries.value = []
    cursor.value = { older: null, newer: null }
    selectedSeq.value = null
    selectedEntry.value = null
    error.value = null
    // The previously open entry belongs to the old file — close its drawer.
    useUiStore().closeDetail()
    // Opening a fresh file always clears any previous file's filters.
    activeFilters.value = { levels: [], after: null, before: null, group: null, groupLabel: null }
    await loadFirstPage(limit)
  }

  /**
   * Load the newest page for the active file honoring activeFilters.
   *
   * When any filter is active we MUST go through the /entries endpoint, which
   * applies level/time/group predicates against the index. The /open endpoint
   * is an unfiltered tail-first fast path and silently ignores filters — using
   * it for a filtered view was the bug that made level/issue filtering a no-op.
   */
  async function loadFirstPage(limit = 100) {
    if (!activeFileId.value) return
    const gen = ++_loadGen
    const fileId = activeFileId.value
    loading.value = true
    error.value = null
    try {
      let data
      if (hasActiveFilters.value) {
        data = await api.getEntries(fileId, {
          direction: 'older',
          limit,
          ...buildFilterQuery(),
        })
      } else {
        data = await api.openFile(fileId, limit)
        if (gen === _loadGen && data.file) fileInfo.value = data.file
      }
      if (gen !== _loadGen) return // superseded by a newer load
      entries.value = data.entries ?? []
      cursor.value = data.cursor ?? { older: null, newer: null }
      if (data.index) indexInfo.value = { ...indexInfo.value, ...data.index }
    } catch (e) {
      if (gen !== _loadGen) return
      error.value = e.message
      entries.value = []
    } finally {
      if (gen === _loadGen) {
        loading.value = false
        reloadToken.value++
      }
    }
  }

  async function loadOlder() {
    if (!cursor.value.older || loadingOlder.value) return
    const gen = _loadGen
    const fileId = activeFileId.value
    loadingOlder.value = true
    try {
      const data = await api.getEntries(fileId, {
        cursor: cursor.value.older,
        direction: 'older',
        limit: 100,
        ...buildFilterQuery(),
      })
      // Drop a stale page if the file/filter changed while it was in flight.
      if (gen !== _loadGen || fileId !== activeFileId.value) return
      entries.value = [...(data.entries ?? []), ...entries.value]
      cursor.value.older = data.cursor?.older ?? null
      if (data.index) indexInfo.value = { ...indexInfo.value, ...data.index }
    } catch (e) {
      if (gen === _loadGen) error.value = e.message
    } finally {
      loadingOlder.value = false
    }
  }

  async function loadNewer() {
    if (!cursor.value.newer || loadingNewer.value) return
    const gen = _loadGen
    const fileId = activeFileId.value
    loadingNewer.value = true
    try {
      const data = await api.getEntries(fileId, {
        cursor: cursor.value.newer,
        direction: 'newer',
        limit: 100,
        ...buildFilterQuery(),
      })
      if (gen !== _loadGen || fileId !== activeFileId.value) return
      entries.value = [...entries.value, ...(data.entries ?? [])]
      cursor.value.newer = data.cursor?.newer ?? null
      if (data.index) indexInfo.value = { ...indexInfo.value, ...data.index }
    } catch (e) {
      if (gen === _loadGen) error.value = e.message
    } finally {
      loadingNewer.value = false
    }
  }

  function buildFilterQuery() {
    const q = {}
    if (activeFilters.value.levels?.length) q.levels = activeFilters.value.levels.join(',')
    if (activeFilters.value.after)  q.after = activeFilters.value.after
    if (activeFilters.value.before) q.before = activeFilters.value.before
    // group is a 64-bit fingerprint sent as a string (kept verbatim).
    if (activeFilters.value.group != null && activeFilters.value.group !== '') q.group = activeFilters.value.group
    return q
  }

  async function applyFilters(filters) {
    activeFilters.value = { ...activeFilters.value, ...filters }
    if (!activeFileId.value) return
    // When a search query is active the visible list comes from the search
    // store, so re-run the search with the new filters (the level facets / time
    // range otherwise appeared to do nothing). Otherwise reload the plain list.
    const searchStore = useSearchStore()
    if (searchStore.hasQuery) {
      await searchStore.search(activeFileId.value)
    } else {
      await loadFirstPage()
    }
  }

  /** Remove a single level from the active level filter. */
  async function removeLevel(level) {
    const next = (activeFilters.value.levels ?? []).filter(l => l !== level)
    await applyFilters({ levels: next })
  }

  /** Clear the time-range filter (after/before). */
  async function clearTimeRange() {
    await applyFilters({ after: null, before: null })
  }

  /** Clear the issue/group drill-in filter. */
  async function clearGroup() {
    await applyFilters({ group: null, groupLabel: null })
  }

  /** Reset every active filter back to the unfiltered file view. */
  async function clearFilters() {
    await applyFilters({ levels: [], after: null, before: null, group: null, groupLabel: null })
  }

  async function selectEntry(seq) {
    selectedSeq.value = seq
    if (!seq) { selectedEntry.value = null; return }
    const existing = entries.value.find(e => e.seq === seq)
    // A non-truncated list entry already carries the complete raw/message, so
    // show it directly — no request (keeps fast j/k navigation snappy).
    if (existing && !existing.truncated) {
      selectedEntry.value = existing
      return
    }
    // Otherwise show what we have instantly, then fetch the FULL entry (full
    // raw + untruncated message + stack trace) and replace it.
    selectedEntry.value = existing ?? null
    detailLoading.value = !existing
    try {
      const data = await api.getEntry(activeFileId.value, seq) // full=true
      if (selectedSeq.value === seq) selectedEntry.value = data
    } catch (e) {
      if (selectedSeq.value === seq && !existing) selectedEntry.value = null
    } finally {
      if (selectedSeq.value === seq) detailLoading.value = false
    }
  }

  async function jumpTo(at) {
    if (!activeFileId.value) return
    const gen = ++_loadGen
    const fileId = activeFileId.value
    loading.value = true
    try {
      const data = await api.jumpTo(fileId, at)
      if (gen !== _loadGen) return
      entries.value = data.entries ?? []
      cursor.value = data.cursor ?? { older: null, newer: null }
      if (data.anchor) selectedSeq.value = data.anchor
    } catch (e) {
      if (gen === _loadGen) error.value = e.message
    } finally {
      if (gen === _loadGen) loading.value = false
    }
  }

  async function loadPermalink(seq) {
    if (!activeFileId.value) return
    const gen = ++_loadGen
    const fileId = activeFileId.value
    loading.value = true
    try {
      const data = await api.permalink(fileId, seq)
      if (gen !== _loadGen) return
      entries.value = data.entries ?? []
      cursor.value = data.cursor ?? { older: null, newer: null }
      if (data.selected) selectedSeq.value = data.selected
    } catch (e) {
      if (gen === _loadGen) error.value = e.message
    } finally {
      if (gen === _loadGen) loading.value = false
    }
  }

  async function loadContext(seq) {
    if (!activeFileId.value) return null
    try {
      return await api.getContext(activeFileId.value, seq)
    } catch {
      return null
    }
  }

  /** Soft-delete a single entry from the active file's index (after confirm). */
  async function deleteEntry(seq) {
    if (!activeFileId.value || seq == null) return false
    const ui = useUiStore()
    const searchStore = useSearchStore()
    const ok = await ui.confirm({
      title: t('entry.deleteTitle'),
      message: t('entry.deleteMessage'),
      confirmLabel: t('actions.delete'),
      danger: true,
    })
    if (!ok) return false
    try {
      await api.deleteEntry(activeFileId.value, seq)
      entries.value = entries.value.filter(e => e.seq !== seq)
      searchStore.results = searchStore.results.filter(e => e.seq !== seq)
      if (searchStore.count != null && searchStore.count > 0) searchStore.count -= 1
      if (selectedSeq.value === seq) {
        selectedSeq.value = null
        selectedEntry.value = null
        ui.closeDetail()
      }
      ui.toastSuccess(t('entry.deleted'))
      return true
    } catch (e) {
      const msg = e?.body?.error
      ui.toastError(typeof msg === 'string' ? msg : t('entry.deleteFailed'))
      return false
    }
  }

  /** Prepend entries from tail (keeps list bounded to 2000) */
  function appendTailEntries(newEntries) {
    entries.value = [...entries.value, ...newEntries]
    const MAX = 2000
    if (entries.value.length > MAX) {
      entries.value = entries.value.slice(-MAX)
    }
  }

  /** Navigate to next entry matching predicate */
  function findNext(from, predicate) {
    const list = entries.value
    const startIdx = from !== null ? list.findIndex(e => e.seq === from) : -1
    for (let i = startIdx + 1; i < list.length; i++) {
      if (predicate(list[i])) return list[i].seq
    }
    return null
  }

  function findPrev(from, predicate) {
    const list = entries.value
    const startIdx = from !== null ? list.findIndex(e => e.seq === from) : list.length
    for (let i = (startIdx === -1 ? list.length : startIdx) - 1; i >= 0; i--) {
      if (predicate(list[i])) return list[i].seq
    }
    return null
  }

  return {
    entries, cursor, indexInfo, fileInfo, loading, loadingOlder, loadingNewer,
    error, selectedSeq, selectedEntry, detailLoading, activeFileId, activeFilters,
    reloadToken, selectedIndex, hasOlder, hasNewer, hasActiveFilters,
    clearActive, openFile, loadFirstPage, loadOlder, loadNewer, applyFilters, selectEntry,
    removeLevel, clearTimeRange, clearGroup, clearFilters,
    jumpTo, loadPermalink, loadContext, deleteEntry, appendTailEntries, findNext, findPrev,
  }
})
