import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../api.js'
import { useEntriesStore } from './entries.js'

export const useSearchStore = defineStore('search', () => {
  const query = ref('')
  const results = ref([])
  const cursor = ref({ older: null })
  const tier = ref(null)
  const caseSensitive = ref(false)
  const count = ref(null)
  const truncated = ref(false)
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref(null)
  const parseError = ref(null) // { code, message, position }
  const activeFileId = ref(null)

  // Saved searches
  const savedSearches = ref([])
  const savedLoading = ref(false)

  const hasQuery = computed(() => query.value.trim().length > 0)
  const hasMore = computed(() => !!cursor.value.older)

  // The active level/time filters live in the entries store (single source of
  // truth) so the level facets and time-range picker affect search results too,
  // instead of search keeping a private, never-written copy.
  function buildQuery() {
    const f = useEntriesStore().activeFilters
    return {
      q: query.value,
      limit: 50,
      case_sensitive: caseSensitive.value ? 1 : undefined,
      levels: f.levels?.length ? f.levels.join(',') : undefined,
      after: f.after || undefined,
      before: f.before || undefined,
    }
  }

  let _searchController = null
  let _lastQuery = ''

  async function search(fileId) {
    if (!fileId) { loading.value = false; return }
    activeFileId.value = fileId

    const currentQ = JSON.stringify(buildQuery())
    // Cache hit: results already match — clear any pre-set loading flag (the
    // SearchBar sets it on keystroke before the debounce) so the overlay never
    // sticks when the same query is re-submitted.
    if (currentQ === _lastQuery && results.value.length > 0) { loading.value = false; return }
    _lastQuery = currentQ

    // Cancel in-flight request
    if (_searchController) _searchController.abort()
    _searchController = new AbortController()

    loading.value = true
    error.value = null
    parseError.value = null
    results.value = []
    cursor.value = { older: null }
    count.value = null
    tier.value = null
    truncated.value = false

    try {
      const data = await api.search(fileId, buildQuery())
      // Check stale: query might have changed while in flight
      if (_lastQuery !== JSON.stringify(buildQuery())) return
      results.value = data.entries ?? []
      cursor.value = { older: data.cursor?.older ?? null }
      tier.value = data.tier ?? null
      caseSensitive.value = data.case_sensitive ?? caseSensitive.value
      count.value = data.count ?? null
      truncated.value = !!data.truncated
    } catch (e) {
      if (e.name === 'AbortError') return
      if (e.status === 422) {
        parseError.value = e.body?.error ?? { message: e.message }
      } else {
        error.value = e.message
      }
    } finally {
      loading.value = false
    }
  }

  async function loadMore() {
    if (!cursor.value.older || !activeFileId.value || loadingMore.value) return
    loadingMore.value = true
    try {
      const data = await api.search(activeFileId.value, {
        ...buildQuery(),
        cursor: cursor.value.older,
      })
      results.value = [...results.value, ...(data.entries ?? [])]
      cursor.value = { older: data.cursor?.older ?? null }
    } catch (e) {
      error.value = e.message
    } finally {
      loadingMore.value = false
    }
  }

  function clear() {
    query.value = ''
    results.value = []
    cursor.value = { older: null }
    error.value = null
    parseError.value = null
    count.value = null
    tier.value = null
    loading.value = false
    loadingMore.value = false
    _lastQuery = ''
  }

  async function loadSavedSearches() {
    savedLoading.value = true
    try {
      // API wraps the list in { searches: [...] }.
      const res = await api.listSavedSearches()
      savedSearches.value = (res && res.searches) || []
    } catch {} finally {
      savedLoading.value = false
    }
  }

  async function saveSearch(name) {
    const f = useEntriesStore().activeFilters
    // API wraps the created record in { search: {...} } with id/name inside.
    const res = await api.createSavedSearch({
      name,
      query: query.value,
      files: activeFileId.value ? [activeFileId.value] : [],
      levels: f.levels ?? [],
      after: f.after,
      before: f.before,
    })
    const saved = (res && res.search) || res
    savedSearches.value = [...savedSearches.value, saved]
    return saved
  }

  async function deleteSavedSearch(id) {
    await api.deleteSavedSearch(id)
    savedSearches.value = savedSearches.value.filter(s => s.id !== id)
  }

  return {
    query, results, cursor, tier, caseSensitive, count, truncated,
    loading, loadingMore, error, parseError, activeFileId,
    savedSearches, savedLoading,
    hasQuery, hasMore,
    search, loadMore, clear, loadSavedSearches, saveSearch, deleteSavedSearch,
  }
})
