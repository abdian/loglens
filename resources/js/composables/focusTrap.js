import { onUnmounted } from 'vue'

/**
 * Minimal focus trap for modal overlays.
 *
 * activate(el) — remember the currently-focused element, move focus into `el`
 * and keep Tab / Shift+Tab cycling within it. release() — restore focus to the
 * element that was focused before the modal opened. Both are no-ops if called
 * out of order, and the trap is torn down automatically on unmount.
 */
export function useFocusTrap() {
  let container = null
  let prevActive = null

  function focusable() {
    if (!container) return []
    const sel = 'a[href],button:not([disabled]),input:not([disabled]),' +
      'select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
    return Array.from(container.querySelectorAll(sel))
      .filter(el => el.offsetParent !== null || el === document.activeElement)
  }

  function onKey(e) {
    if (e.key !== 'Tab' || !container) return
    const items = focusable()
    if (!items.length) { e.preventDefault(); container.focus?.(); return }
    const first = items[0]
    const last = items[items.length - 1]
    const active = document.activeElement
    if (e.shiftKey && (active === first || !container.contains(active))) {
      e.preventDefault()
      last.focus()
    } else if (!e.shiftKey && active === last) {
      e.preventDefault()
      first.focus()
    }
  }

  function activate(el) {
    if (!el) return
    container = el
    prevActive = document.activeElement
    document.addEventListener('keydown', onKey, true)
    const items = focusable()
    ;(items[0] ?? el).focus?.()
  }

  function release() {
    if (!container) return
    document.removeEventListener('keydown', onKey, true)
    container = null
    const target = prevActive
    prevActive = null
    if (target && typeof target.focus === 'function') {
      try { target.focus() } catch { /* element gone */ }
    }
  }

  onUnmounted(release)

  return { activate, release }
}
