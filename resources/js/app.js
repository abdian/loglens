/**
 * LogLens — Vue 3 SPA entry point.
 *
 * Reads boot config from #loglens-boot (inert JSON script tag — CSP-safe, no eval).
 * Creates Vue app + Pinia, applies i18n + theme, mounts to #loglens-app.
 */
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './components/App.vue'
import { i18nPlugin } from './i18n.js'
import '../css/app.css'

// ── Boot configuration ────────────────────────────────────────────────────────
// The blade shell renders <script type="application/json" id="loglens-boot">.
// type="application/json" means the browser treats it as inert data — no eval,
// strict-CSP compatible.
const bootEl = document.getElementById('loglens-boot')
if (!bootEl) {
  document.body.innerHTML = '<p style="color:red;font-family:monospace;padding:1rem">LogLens: missing #loglens-boot config element.</p>'
  throw new Error('LogLens: #loglens-boot not found')
}

// ── Create app ────────────────────────────────────────────────────────────────
const app = createApp(App)

// Pinia store
const pinia = createPinia()
app.use(pinia)

// i18n
app.use(i18nPlugin)

// ── Mount ─────────────────────────────────────────────────────────────────────
const mountEl = document.getElementById('loglens-app')
if (!mountEl) throw new Error('LogLens: #loglens-app mount element not found')

app.mount(mountEl)
