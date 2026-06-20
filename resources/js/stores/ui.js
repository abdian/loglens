import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import { getBoot } from '../api.js'

export const useUiStore = defineStore('ui', () => {
  // Theme: 'dark' | 'light' | 'system'
  const theme = ref('dark')
  // Layout: sidebar open/closed
  const sidebarOpen = ref(true)
  const detailOpen = ref(false)
  // Active panel in sidebar
  const sidebarPanel = ref('files') // 'files' | 'issues'
  // Command palette
  const paletteOpen = ref(false)
  // Toast notifications
  const toasts = ref([])
  // Diagnostics panel
  const diagnosticsOpen = ref(false)
  // Reports / analytics panel
  const reportsOpen = ref(false)
  // Keyboard-shortcut help overlay
  const helpOpen = ref(false)
  // Confirmation dialog: { title, message, confirmLabel, cancelLabel, danger, resolve }
  const confirmState = ref(null)
  // URL-serialised state loaded flag
  const urlLoaded = ref(false)
  // Tail mode active
  const tailMode = ref(false)
  // Loading overlay
  const globalLoading = ref(false)

  let _toastSeq = 0

  function initFromBoot() {
    const boot = getBoot()
    // Boot theme takes precedence over localStorage on first load
    const stored = localStorage.getItem('loglens-theme')
    theme.value = stored ?? boot.theme ?? 'dark'
    applyTheme(theme.value)
  }

  function applyTheme(t) {
    document.documentElement.setAttribute('data-theme', t)
  }

  function setTheme(t) {
    theme.value = t
    localStorage.setItem('loglens-theme', t)
    applyTheme(t)
  }

  watch(theme, applyTheme)

  function toast(message, type = 'info', duration = 4000) {
    const id = ++_toastSeq
    toasts.value.push({ id, message, type })
    setTimeout(() => {
      toasts.value = toasts.value.filter(t => t.id !== id)
    }, duration)
  }

  function toastSuccess(msg) { toast(msg, 'success') }
  function toastError(msg)   { toast(msg, 'error', 6000) }
  function toastWarn(msg)    { toast(msg, 'warn') }
  function dismissToast(id)  { toasts.value = toasts.value.filter(t => t.id !== id) }

  function openPalette()  { paletteOpen.value = true }
  function closePalette() { paletteOpen.value = false }

  function toggleHelp()   { helpOpen.value = !helpOpen.value }
  function closeHelp()    { helpOpen.value = false }

  function openDetail()   { detailOpen.value = true }
  function closeDetail()  { detailOpen.value = false }

  /**
   * Show a confirmation dialog and resolve to true/false on the user's choice.
   * Usage: if (await uiStore.confirm({ title, message, danger: true })) { … }
   */
  function confirm(opts = {}) {
    // If a dialog is somehow already open, resolve it false so its promise
    // never leaks before showing the new one.
    if (confirmState.value) {
      const prev = confirmState.value.resolve
      confirmState.value = null
      prev(false)
    }
    return new Promise((resolve) => {
      confirmState.value = {
        title: opts.title ?? '',
        message: opts.message ?? '',
        confirmLabel: opts.confirmLabel ?? null,
        cancelLabel: opts.cancelLabel ?? null,
        danger: opts.danger ?? false,
        resolve,
      }
    })
  }
  function resolveConfirm(result) {
    if (confirmState.value) {
      const r = confirmState.value.resolve
      confirmState.value = null
      r(result)
    }
  }

  function toggleSidebar() { sidebarOpen.value = !sidebarOpen.value }

  /** Serialize current view state to URL */
  function serializeToUrl(state) {
    // Don't write the URL until the initial restore has finished — otherwise the
    // mutations during bootstrap (setActiveFile/openFile/applyFilters) clobber
    // the deep-link we're in the middle of restoring.
    if (!urlLoaded.value) return
    const url = new URL(window.location.href)
    const p = url.searchParams
    if (state.fileId)   p.set('file', state.fileId); else p.delete('file')
    if (state.query)    p.set('q', state.query); else p.delete('q')
    if (state.levels?.length) p.set('levels', state.levels.join(',')); else p.delete('levels')
    if (state.after)    p.set('after', state.after); else p.delete('after')
    if (state.before)   p.set('before', state.before); else p.delete('before')
    if (state.selected) p.set('entry', String(state.selected)); else p.delete('entry')
    window.history.replaceState({}, '', url)
  }

  /** Restore state from current URL */
  function deserializeFromUrl() {
    const p = new URLSearchParams(window.location.search)
    return {
      fileId:   p.get('file') ?? null,
      query:    p.get('q') ?? '',
      levels:   p.get('levels') ? p.get('levels').split(',') : [],
      after:    p.get('after') ?? null,
      before:   p.get('before') ?? null,
      selected: p.get('entry') ? Number(p.get('entry')) : null,
    }
  }

  return {
    theme, sidebarOpen, detailOpen, sidebarPanel, paletteOpen, toasts,
    diagnosticsOpen, reportsOpen, helpOpen, confirmState, urlLoaded, tailMode, globalLoading,
    initFromBoot, setTheme, toast, toastSuccess, toastError, toastWarn, dismissToast,
    openPalette, closePalette, toggleHelp, closeHelp, openDetail, closeDetail, confirm, resolveConfirm, toggleSidebar,
    serializeToUrl, deserializeFromUrl,
  }
})
