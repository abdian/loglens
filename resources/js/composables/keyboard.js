/**
 * LogLens keyboard shortcut composable.
 *
 * Registers a global keydown listener on mount and removes it on unmount.
 * Skips shortcuts when focus is inside an input/textarea/select/contenteditable.
 *
 * Shortcuts:
 *   j / k          — next / previous entry
 *   /              — focus search
 *   g g (double-g) — scroll to top
 *   G              — scroll to bottom
 *   e              — next error
 *   E              — previous error
 *   w              — next warning
 *   W              — previous warning
 *   Enter          — open detail drawer
 *   Escape         — close detail drawer / clear selection
 *   Cmd/Ctrl+K     — open command palette
 */
import { onMounted, onUnmounted, ref } from 'vue'
import { useUiStore } from '../stores/ui.js'

export function useKeyboard({
  onJ, onK, onSlash, onGG, onG, onE, onBigE, onW, onBigW, onEnter, onEscape, onPalette, onHelp,
} = {}) {
  let _lastG = 0
  const listening = ref(true)
  const ui = useUiStore()

  function isEditable(el) {
    if (!el) return false
    const tag = el.tagName.toLowerCase()
    return tag === 'input' || tag === 'textarea' || tag === 'select' ||
      el.contentEditable === 'true' || el.closest('[role="textbox"]')
  }

  function handler(e) {
    if (!listening.value) return
    // A modal (confirm dialog / command palette / reports / help) owns the
    // keyboard while open — don't let list shortcuts fire underneath it.
    if (ui.confirmState || ui.paletteOpen || ui.reportsOpen || ui.helpOpen) return
    if (isEditable(document.activeElement)) return

    const key = e.key
    const ctrl = e.ctrlKey || e.metaKey

    // "?" opens the keyboard-shortcut help (Shift+/ on most layouts).
    if (key === '?') {
      e.preventDefault()
      onHelp?.()
      return
    }

    // Cmd/Ctrl+K — palette
    if (ctrl && key === 'k') {
      e.preventDefault()
      onPalette?.()
      return
    }

    switch (key) {
      case 'j':
        e.preventDefault()
        onJ?.()
        break
      case 'k':
        e.preventDefault()
        onK?.()
        break
      case '/':
        e.preventDefault()
        onSlash?.()
        break
      case 'G':
        e.preventDefault()
        onG?.()
        break
      case 'g': {
        const now = Date.now()
        if (now - _lastG < 500) {
          e.preventDefault()
          onGG?.()
        }
        _lastG = now
        break
      }
      case 'e':
        e.preventDefault()
        onE?.()
        break
      case 'E':
        e.preventDefault()
        onBigE?.()
        break
      case 'w':
        e.preventDefault()
        onW?.()
        break
      case 'W':
        e.preventDefault()
        onBigW?.()
        break
      case 'Enter':
        // Only when we have a selected entry
        onEnter?.()
        break
      case 'Escape':
        e.preventDefault()
        onEscape?.()
        break
    }
  }

  onMounted(() => window.addEventListener('keydown', handler))
  onUnmounted(() => window.removeEventListener('keydown', handler))

  return { listening }
}
