import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api, { getBoot } from '../api.js'

const WATCHDOG_MS = 10_000  // restart SSE if no event for 10s
const WINDOW_RECONNECT_MS = 500

export const useTailStore = defineStore('tail', () => {
  const active = ref(false)
  const paused = ref(false)
  const transport = ref('polling') // 'sse' | 'polling'
  const tailInfo = ref(null)
  const cursors = ref({}) // { fileId: cursor }
  const entries = ref([])
  // Monotonic count of entries ever received — increments on EVERY arrival even
  // after the buffer hits its cap (where length stops changing), so the list's
  // auto-scroll watcher keeps firing.
  const received = ref(0)
  const error = ref(null)
  const fileColors = ref({}) // { fileId: colorIndex }
  const monitoredFiles = ref([]) // file ids
  // Entries that arrived while paused (user scrolled up). Buffered, not dropped,
  // and flushed on resume so the live view never silently loses lines.
  const pending = ref([])
  const pendingCount = computed(() => pending.value.length)

  const MAX_BUFFER = 5000

  // SSE internals (not reactive — managed by logic)
  let _es = null
  let _watchdog = null
  let _pollTimer = null
  let _pollEtag = null
  let _stopped = false
  let _uid = 0

  /**
   * Push an arriving tail entry: stamp a stable unique id (so the virtualized
   * list keys it correctly) and a transient `_fresh` flag that briefly
   * highlights it as just-arrived, then bound the buffer.
   */
  function _pushEntry(entry) {
    entry._uid = ++_uid
    entry._fresh = true
    // Paused (scrolled up): buffer instead of dropping, so resume restores them.
    if (paused.value) {
      pending.value.push(entry)
      if (pending.value.length > MAX_BUFFER) pending.value.shift()
      return
    }
    entries.value.push(entry)
    received.value++
    // Capture the reactive proxy (not the raw object) so clearing _fresh later
    // actually triggers a re-render of the row.
    const proxy = entries.value[entries.value.length - 1]
    // Amortize trimming: slice off the overflow in one pass once we drift past
    // the cap, instead of an O(n) shift() on every single arrival at the cap.
    if (entries.value.length > MAX_BUFFER + 256) {
      entries.value = entries.value.slice(-MAX_BUFFER)
    }
    setTimeout(() => { if (proxy) proxy._fresh = false }, 2500)
  }

  const FILE_COLORS = [
    '#388bfd', '#3fb950', '#d29922', '#bc8cff', '#39c5cf',
    '#ff7b72', '#ffa657', '#79c0ff', '#56d364', '#ff6e96',
  ]

  function getFileColor(fileId) {
    if (!fileColors.value[fileId]) {
      const idx = Object.keys(fileColors.value).length % FILE_COLORS.length
      fileColors.value[fileId] = FILE_COLORS[idx]
    }
    return fileColors.value[fileId]
  }

  async function loadTailInfo() {
    try {
      tailInfo.value = await api.getTailInfo()
    } catch {}
  }

  function buildStreamUrl(q = '') {
    const boot = getBoot()
    const params = new URLSearchParams()
    params.set('files', monitoredFiles.value.join(','))
    if (q) params.set('q', q)
    // Per-file opaque cursors as the JSON `cursors` map the server actually
    // parses (same shape as polling). The previous "fid:cursor,.." string was
    // unparseable server-side, so SSE always resumed at EOF and dropped the gap.
    if (Object.keys(cursors.value).length) {
      params.set('cursors', JSON.stringify(cursors.value))
    }
    return `${boot.apiUrl}/tail/stream?${params}`
  }

  function _resetWatchdog() {
    if (_watchdog) clearTimeout(_watchdog)
    _watchdog = setTimeout(() => {
      if (active.value && !paused.value && transport.value === 'sse') {
        _restartSSE()
      }
    }, WATCHDOG_MS)
  }

  function _onSseEntry(fileId, entry) {
    _resetWatchdog()
    _pushEntry({ ...entry, _fileId: fileId })
  }

  function _onWindowEnd(data) {
    // Update cursors and reconnect
    if (data.cursors) cursors.value = { ...cursors.value, ...data.cursors }
    if (!_stopped) {
      setTimeout(() => _startSSE(), WINDOW_RECONNECT_MS)
    }
  }

  function _startSSE() {
    if (_es) { _es.close(); _es = null }
    _stopped = false

    const url = buildStreamUrl()
    _es = new EventSource(url, { withCredentials: true })

    // Dynamic file event listeners
    for (const fileId of monitoredFiles.value) {
      _es.addEventListener(`entry:${fileId}`, (e) => {
        _resetWatchdog()
        try {
          const entry = JSON.parse(e.data)
          // Track cursor from event id
          if (e.lastEventId) cursors.value[fileId] = e.lastEventId
          _onSseEntry(fileId, entry)
        } catch {}
      })
    }

    _es.addEventListener('rotation', () => {
      _resetWatchdog()
    })

    _es.addEventListener('window-end', (e) => {
      _resetWatchdog()
      try {
        const data = JSON.parse(e.data)
        _onWindowEnd(data)
      } catch {}
    })

    _es.onerror = () => {
      // Fall back to polling. Tear down the SSE handle AND the watchdog so it
      // can't later fire against a now-polling transport (leak).
      if (_es) { _es.close(); _es = null }
      if (_watchdog) { clearTimeout(_watchdog); _watchdog = null }
      transport.value = 'polling'
      if (!_stopped) _startPolling()
    }

    _resetWatchdog()
    transport.value = 'sse'
  }

  async function _startPolling() {
    if (_pollTimer) clearTimeout(_pollTimer)
    _stopped = false
    transport.value = 'polling'

    const poll = async () => {
      if (_stopped || paused.value) {
        _pollTimer = setTimeout(poll, tailInfo.value?.poll_idle_ms ?? 3000)
        return
      }
      try {
        const result = await api.tailPoll(
          // Server expects a JSON `cursors` map (or cursor[id]) for multi-file.
          { files: monitoredFiles.value.join(','), cursors: JSON.stringify(cursors.value) },
          _pollEtag
        )
        if (result.idle) {
          _pollTimer = setTimeout(poll, tailInfo.value?.poll_idle_ms ?? 3000)
          return
        }
        _pollEtag = result.etag

        for (const entry of result.entries ?? []) {
          // Poll entries carry their source file id under `file` (seq is null
          // for tail). Per-file cursors come from result.cursors below.
          entry._fileId = entry.file
          _pushEntry(entry)
        }
        if (result.cursors) cursors.value = { ...cursors.value, ...result.cursors }

        const delay = result.idle ? (tailInfo.value?.poll_idle_ms ?? 3000) : (tailInfo.value?.poll_active_ms ?? 500)
        _pollTimer = setTimeout(poll, delay)
      } catch (e) {
        error.value = e.message
        _pollTimer = setTimeout(poll, 5000)
      }
    }
    poll()
  }

  async function start(fileIds, initialCursors = {}) {
    monitoredFiles.value = fileIds
    cursors.value = { ...initialCursors }
    entries.value = []
    pending.value = []
    received.value = 0
    active.value = true
    paused.value = false
    _stopped = false
    error.value = null

    await loadTailInfo()

    // Determine transport
    const info = tailInfo.value
    const useSSE = info && info.transport === 'sse' && !info.force_polling
    if (useSSE) {
      _startSSE()
    } else {
      _startPolling()
    }
  }

  function stop() {
    _stopped = true
    active.value = false
    if (_es) { _es.close(); _es = null }
    if (_watchdog) { clearTimeout(_watchdog); _watchdog = null }
    if (_pollTimer) { clearTimeout(_pollTimer); _pollTimer = null }
  }

  function pause() {
    paused.value = true
  }

  function resume() {
    paused.value = false
    // Flush entries buffered while paused, then bound the list.
    if (pending.value.length) {
      entries.value.push(...pending.value)
      received.value += pending.value.length
      if (entries.value.length > MAX_BUFFER) entries.value = entries.value.slice(-MAX_BUFFER)
      pending.value = []
    }
    if (_stopped) return
    // Re-establish whichever transport is active (the SSE-error path may have
    // switched us to polling).
    if (transport.value === 'sse' && !_es) _startSSE()
    else if (transport.value === 'polling' && !_pollTimer) _startPolling()
  }

  function _restartSSE() {
    _startSSE()
  }

  return {
    active, paused, transport, tailInfo, cursors, entries, received, error,
    fileColors, monitoredFiles, pendingCount, getFileColor,
    start, stop, pause, resume, loadTailInfo,
  }
})
