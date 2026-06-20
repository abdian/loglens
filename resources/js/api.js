/**
 * LogLens API client
 *
 * Reads the boot config from #loglens-boot JSON script tag once.
 * Sends CSRF token + XMLHttpRequest header on mutating requests.
 * All methods return parsed JSON or throw an ApiError.
 */

/** Boot config parsed once at module load */
let _boot = null

export function getBoot() {
  if (_boot) return _boot
  const el = document.getElementById('loglens-boot')
  if (!el) throw new Error('LogLens: #loglens-boot element not found')
  _boot = JSON.parse(el.textContent)
  return _boot
}

export class ApiError extends Error {
  constructor(status, body) {
    super(body?.message ?? `HTTP ${status}`)
    this.status = status
    this.body = body
    this.name = 'ApiError'
  }
}

/**
 * Core fetch wrapper.
 * @param {string} path - path relative to apiUrl (no leading slash)
 * @param {RequestInit & { query?: Record<string,any> }} [options]
 */
async function request(path, options = {}) {
  const boot = getBoot()
  const { query, ...fetchOpts } = options

  let url = `${boot.apiUrl}/${path}`
  if (query) {
    const params = new URLSearchParams()
    for (const [k, v] of Object.entries(query)) {
      if (v !== undefined && v !== null && v !== '') {
        if (Array.isArray(v)) {
          params.set(k, v.join(','))
        } else {
          params.set(k, String(v))
        }
      }
    }
    const qs = params.toString()
    if (qs) url += `?${qs}`
  }

  const method = (fetchOpts.method ?? 'GET').toUpperCase()
  const isMutating = !['GET', 'HEAD', 'OPTIONS'].includes(method)

  const headers = new Headers(fetchOpts.headers)
  headers.set('X-Requested-With', 'XMLHttpRequest')
  headers.set('Accept', 'application/json')
  if (isMutating) {
    headers.set('X-CSRF-TOKEN', boot.csrf)
    if (!headers.has('Content-Type') && fetchOpts.body) {
      headers.set('Content-Type', 'application/json')
    }
  }

  // Bound every request so a hung backend (slow disk, stuck index build) can't
  // leave the UI spinning forever. Caller can pass options.timeout (ms) or its
  // own signal; 0/Infinity disables.
  const timeoutMs = options.timeout ?? 30000
  const controller = !fetchOpts.signal && timeoutMs && timeoutMs !== Infinity ? new AbortController() : null
  const timer = controller ? setTimeout(() => controller.abort(), timeoutMs) : null

  let resp
  try {
    resp = await fetch(url, {
      ...fetchOpts,
      headers,
      credentials: 'same-origin',
      signal: fetchOpts.signal ?? controller?.signal,
    })
  } catch (e) {
    if (e?.name === 'AbortError') throw new ApiError(0, { message: 'Request timed out' })
    throw e
  } finally {
    if (timer) clearTimeout(timer)
  }

  if (!resp.ok) {
    let body = null
    try { body = await resp.json() } catch {}
    throw new ApiError(resp.status, body)
  }

  if (resp.status === 204) return null
  return resp.json()
}

// ── File endpoints ──────────────────────────────────────────────

export const api = {
  /** GET files */
  listFiles() {
    return request('files')
  },

  /** GET files/{file}/open */
  openFile(fileId, limit = 100) {
    return request(`files/${fileId}/open`, { query: { limit } })
  },

  /** GET files/{file}/entries */
  getEntries(fileId, params = {}) {
    return request(`files/${fileId}/entries`, { query: params })
  },

  /** GET files/{file}/entries/{seq} */
  getEntry(fileId, seq, full = true) {
    return request(`files/${fileId}/entries/${seq}`, { query: { full: full ? 1 : undefined } })
  },

  /** GET files/{file}/expand */
  expandEntry(fileId, offset, length) {
    return request(`files/${fileId}/expand`, { query: { offset, length } })
  },

  /** GET files/{file}/jump */
  jumpTo(fileId, at) {
    return request(`files/${fileId}/jump`, { query: { at } })
  },

  /** GET files/{file}/permalink/{seq} */
  permalink(fileId, seq) {
    return request(`files/${fileId}/permalink/${seq}`)
  },

  /** GET files/{file}/context/{seq} */
  getContext(fileId, seq, radius = 25) {
    return request(`files/${fileId}/context/${seq}`, { query: { radius } })
  },

  /** GET files/{file}/levels */
  getLevels(fileId, filters = {}) {
    return request(`files/${fileId}/levels`, { query: filters })
  },

  /** GET files/{file}/search */
  search(fileId, params = {}) {
    return request(`files/${fileId}/search`, { query: params })
  },

  /** GET files/{file}/groups */
  getGroups(fileId, params = {}) {
    return request(`files/${fileId}/groups`, { query: params })
  },

  /** GET files/{file}/groups/new */
  getNewGroups(fileId, since) {
    return request(`files/${fileId}/groups/new`, { query: { since } })
  },

  /** GET files/{file}/histogram */
  getHistogram(fileId, from, to, bucket) {
    return request(`files/${fileId}/histogram`, { query: { from, to, bucket } })
  },

  /** GET files/{file}/sparkline */
  getSparkline(fileId) {
    return request(`files/${fileId}/sparkline`)
  },

  /** GET files/{file}/mail/{seq} */
  getMail(fileId, seq) {
    return request(`files/${fileId}/mail/${seq}`)
  },

  /** GET files/{file}/index */
  getIndexStatus(fileId) {
    return request(`files/${fileId}/index`)
  },

  /** POST files/{file}/index */
  buildIndex(fileId) {
    return request(`files/${fileId}/index`, { method: 'POST' })
  },

  /** POST files/{file}/index/rebuild */
  rebuildIndex(fileId) {
    return request(`files/${fileId}/index/rebuild`, { method: 'POST' })
  },

  /** POST files/{file}/clear */
  clearFile(fileId) {
    return request(`files/${fileId}/clear`, { method: 'POST' })
  },

  /** DELETE files/{file} */
  deleteFile(fileId) {
    return request(`files/${fileId}`, { method: 'DELETE' })
  },

  /** DELETE files/{file}/entries/{seq} — soft-delete a single entry */
  deleteEntry(fileId, seq) {
    return request(`files/${fileId}/entries/${seq}`, { method: 'DELETE' })
  },

  /** POST files/batch */
  batchFiles(op, files) {
    return request('files/batch', {
      method: 'POST',
      body: JSON.stringify({ op, files }),
    })
  },

  /** GET files/{file}/writability */
  getWritability(fileId) {
    return request(`files/${fileId}/writability`)
  },

  /** GET files/{file}/download → signed URL */
  getDownloadUrl(fileId) {
    return request(`files/${fileId}/download`)
  },

  /** GET download?files=... → zip download URL (navigate) */
  getBatchDownloadUrl(fileIds) {
    const boot = getBoot()
    const params = new URLSearchParams({ files: fileIds.join(',') })
    return `${boot.apiUrl}/download?${params}`
  },

  // ── Tail ──────────────────────────────────────────────────────

  /** GET tail/info */
  getTailInfo() {
    return request('tail/info')
  },

  /**
   * GET tail/poll — returns 304 when idle (via ETag).
   * Returns null on 304 (idle), data object otherwise.
   */
  async tailPoll(params = {}, etag = null) {
    const boot = getBoot()
    const queryParams = new URLSearchParams()
    for (const [k, v] of Object.entries(params)) {
      if (v !== undefined && v !== null) queryParams.set(k, String(v))
    }
    const url = `${boot.apiUrl}/tail/poll?${queryParams}`
    const headers = new Headers({
      'X-Requested-With': 'XMLHttpRequest',
      Accept: 'application/json',
    })
    if (etag) headers.set('If-None-Match', etag)

    const resp = await fetch(url, { credentials: 'same-origin', headers })
    if (resp.status === 304) return { idle: true, etag }
    if (!resp.ok) {
      let body = null
      try { body = await resp.json() } catch {}
      throw new ApiError(resp.status, body)
    }
    const data = await resp.json()
    const newEtag = resp.headers.get('ETag')
    return { ...data, etag: newEtag ?? etag }
  },

  // ── Diagnostics ───────────────────────────────────────────────

  getDiagnostics() {
    return request('diagnostics')
  },

  // ── Saved searches ────────────────────────────────────────────

  listSavedSearches() {
    return request('saved-searches')
  },

  createSavedSearch(data) {
    return request('saved-searches', {
      method: 'POST',
      body: JSON.stringify(data),
    })
  },

  deleteSavedSearch(id) {
    return request(`saved-searches/${id}`, { method: 'DELETE' })
  },
}

export default api
