<template>
  <aside
    v-if="entry"
    class="ll-detail-drawer"
    :class="{ 'll-detail-drawer--open': open }"
    role="complementary"
  >
    <!-- Header -->
    <div class="ll-detail-drawer__header">
      <div class="ll-detail-drawer__meta">
        <span :class="['ll-level-badge', `ll-level-${entry.level}`]">{{ entry.level }}</span>
        <span class="ll-detail-drawer__time" dir="ltr">{{ entry.datetime }}</span>
        <span v-if="entry.channel" class="ll-detail-drawer__chan">{{ entry.channel }}</span>
      </div>
      <div class="ll-detail-drawer__actions">
        <!-- Copy message (primary) -->
        <button class="ll-icon-btn" @click="copyMessage" :title="t('detail.copyMessage')">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
            <path d="M0 6.75C0 5.784.784 5 1.75 5h1.5a.75.75 0 0 1 0 1.5h-1.5a.25.25 0 0 0-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 0 0 .25-.25v-1.5a.75.75 0 0 1 1.5 0v1.5A1.75 1.75 0 0 1 9.25 16h-7.5A1.75 1.75 0 0 1 0 14.25Z"/><path d="M5 1.75C5 .784 5.784 0 6.75 0h7.5C15.216 0 16 .784 16 1.75v7.5A1.75 1.75 0 0 1 14.25 11h-7.5A1.75 1.75 0 0 1 5 9.25Zm1.75-.25a.25.25 0 0 0-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 0 0 .25-.25v-7.5a.25.25 0 0 0-.25-.25Z"/>
          </svg>
        </button>
        <!-- Overflow menu (secondary actions) -->
        <div class="ll-detail-menu">
          <button class="ll-icon-btn" @click="menuOpen = !menuOpen" @blur="onMenuBlur" :title="t('detail.more')">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M8 4a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm0 5.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM9.5 13.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z"/></svg>
          </button>
          <div v-if="menuOpen" class="ll-detail-menu__list" @mousedown.prevent>
            <button class="ll-detail-menu__item" @click="run(copyRaw)">{{ t('detail.copyRaw') }}</button>
            <button class="ll-detail-menu__item" @click="run(downloadEntry)">{{ t('detail.download') }}</button>
            <button class="ll-detail-menu__item" @click="run(viewInContext)">{{ t('detail.viewContext') }}</button>
            <button class="ll-detail-menu__item" @click="run(copyPermalink)">{{ t('detail.copyLink') }}</button>
            <button class="ll-detail-menu__item ll-detail-menu__item--danger" @click="run(onDelete)">{{ t('entry.delete') }}</button>
          </div>
        </div>
        <!-- Close (always visible) -->
        <button class="ll-icon-btn ll-icon-btn--close" @click="emit('close')" :title="t('detail.close')">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
            <path d="M3.72 3.72a.75.75 0 0 1 1.06 0L8 6.94l3.22-3.22a.749.749 0 0 1 1.275.326.749.749 0 0 1-.215.734L9.06 8l3.22 3.22a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215L8 9.06l-3.22 3.22a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L6.94 8 3.72 4.78a.75.75 0 0 1 0-1.06Z"/>
          </svg>
          <span class="ll-icon-btn__label">Esc</span>
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="detailLoading" class="ll-detail-drawer__loading">
      <span class="text-xs text-[var(--ll-text-muted)] animate-pulse">{{ t('detail.loading') }}</span>
    </div>

    <!-- Tabs -->
    <div class="ll-detail-drawer__tabs" v-else>
      <button
        v-for="tab in tabs"
        :key="tab.id"
        :class="['ll-tab-btn', { 'll-tab-btn--active': activeTab === tab.id }]"
        @click="activeTab = tab.id"
        :disabled="!tab.available"
      >{{ tab.label }}</button>
    </div>

    <!-- Tab content -->
    <div class="ll-detail-drawer__body" v-if="!detailLoading">
      <!-- Parsed -->
      <div v-if="activeTab === 'parsed'" class="ll-detail-parsed">
        <div class="ll-detail-parsed__message ll-log-content" dir="ltr">{{ entry.message }}</div>

        <template v-if="entry.context && Object.keys(entry.context).length">
          <div class="ll-detail-section-label">{{ t('detail.contextData') }}</div>
          <JsonTree :data="entry.context" />
        </template>

        <template v-if="entry.extra && Object.keys(entry.extra).length">
          <div class="ll-detail-section-label">{{ t('detail.extra') }}</div>
          <JsonTree :data="entry.extra" />
        </template>
      </div>

      <!-- Raw -->
      <div v-else-if="activeTab === 'raw'" class="ll-detail-raw">
        <pre class="ll-raw-entry" dir="ltr">{{ entry.raw }}</pre>
      </div>

      <!-- Stack trace -->
      <div v-else-if="activeTab === 'trace' && entry.exception">
        <StackTrace :exc="entry.exception" />
      </div>

      <!-- Surrounding entries (the lines logged just before/after this one) -->
      <div v-else-if="activeTab === 'context'" class="ll-detail-context">
        <div class="ll-context-caption">{{ t('detail.surroundingHint') }}</div>
        <div v-if="contextLoading" class="text-xs text-[var(--ll-text-muted)] p-4">{{ t('detail.loading') }}</div>
        <template v-else-if="contextLines.length">
          <div
            v-for="line in contextLines"
            :key="line.seq"
            :class="[
              'll-context-line',
              { 'll-context-line--selected': line.seq === entry.seq },
              `ll-entry-row--${line.level}`,
            ]"
            :title="line.seq === entry.seq ? '' : t('detail.jumpToEntry')"
            @click="line.seq !== entry.seq && jumpToLine(line.seq)"
          >
            <span class="ll-context-line__ts" dir="ltr">{{ line.datetime }}</span>
            <span :class="['ll-level-badge', `ll-level-${line.level}`]" style="font-size:10px">{{ line.level }}</span>
            <span class="ll-context-line__msg ll-log-content" dir="ltr">{{ line.message }}</span>
            <span v-if="line.seq === entry.seq" class="ll-context-line__here">{{ t('detail.thisEntry') }}</span>
          </div>
        </template>
        <div v-else class="text-xs text-[var(--ll-text-faint)] p-4">{{ t('detail.noSurrounding') }}</div>
      </div>

      <!-- Occurrences: every entry sharing this one's fingerprint (same error) -->
      <div v-else-if="activeTab === 'occurrences'" class="ll-detail-context">
        <div class="ll-context-caption">{{ t('detail.occurrencesHint') }}</div>
        <div v-if="occLoading" class="text-xs text-[var(--ll-text-muted)] p-4">{{ t('detail.loading') }}</div>
        <template v-else-if="occurrences.length">
          <div class="ll-occ-summary">{{ t('detail.occurrencesCount', { n: occCountLabel }) }}</div>
          <div
            v-for="line in occurrences"
            :key="line.seq"
            :class="[
              'll-context-line',
              { 'll-context-line--selected': line.seq === entry.seq },
              `ll-entry-row--${line.level}`,
            ]"
            :title="line.seq === entry.seq ? '' : t('detail.jumpToEntry')"
            @click="line.seq !== entry.seq && jumpToLine(line.seq)"
          >
            <span class="ll-context-line__ts" dir="ltr">{{ line.datetime }}</span>
            <span class="ll-context-line__msg ll-log-content" dir="ltr">{{ line.message }}</span>
            <span v-if="line.seq === entry.seq" class="ll-context-line__here">{{ t('detail.thisEntry') }}</span>
          </div>
        </template>
        <div v-else class="text-xs text-[var(--ll-text-faint)] p-4">{{ t('detail.noOccurrences') }}</div>
      </div>

      <!-- Mail preview -->
      <div v-else-if="activeTab === 'mail'" class="ll-detail-mail">
        <div v-if="mailLoading" class="text-xs text-[var(--ll-text-muted)] p-4">{{ t('detail.loading') }}</div>
        <template v-else-if="mailData">
          <div class="ll-mail-headers">
            <div><strong>From:</strong> {{ mailData.headers.from }}</div>
            <div><strong>To:</strong> {{ mailData.headers.to }}</div>
            <div><strong>Subject:</strong> {{ mailData.headers.subject }}</div>
            <div><strong>Date:</strong> {{ mailData.headers.date }}</div>
          </div>
          <iframe
            v-if="mailData.html"
            class="ll-mail-frame"
            :srcdoc="mailSrcdoc"
            sandbox=""
            referrerpolicy="no-referrer"
            loading="lazy"
            :title="t('detail.mail')"
          />
          <pre v-else-if="mailData.text" class="ll-raw-entry" dir="ltr">{{ mailData.text }}</pre>
          <div v-if="mailData.attachments?.length" class="ll-mail-attachments">
            <div class="ll-detail-section-label">Attachments</div>
            <div v-for="att in mailData.attachments" :key="att.name" class="ll-mail-attachment">
              📎 {{ att.name }} ({{ att.type }}, {{ att.size }} bytes)
            </div>
          </div>
        </template>
      </div>
    </div>
  </aside>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useEntriesStore } from '../stores/entries.js'
import { useUiStore } from '../stores/ui.js'
import StackTrace from './StackTrace.vue'
import JsonTree from './JsonTree.vue'
import api from '../api.js'
import { t } from '../i18n.js'

const props = defineProps({
  open: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'context'])

const entriesStore = useEntriesStore()
const uiStore = useUiStore()

const entry = computed(() => entriesStore.selectedEntry)
const detailLoading = computed(() => entriesStore.detailLoading)

const activeTab = ref('parsed')
const contextLines = ref([])
const contextLoading = ref(false)
const mailData = ref(null)
const mailLoading = ref(false)
const occurrences = ref([])
const occLoading = ref(false)

const OCC_LIMIT = 100
const occCountLabel = computed(() =>
  occurrences.value.length >= OCC_LIMIT ? `${OCC_LIMIT}+` : String(occurrences.value.length)
)

// The store's deleteEntry shows the confirmation dialog itself.
function onDelete() {
  if (entry.value?.seq != null) entriesStore.deleteEntry(entry.value.seq)
}

// Overflow menu for the secondary header actions (keeps the close button from
// being pushed off a narrow drawer).
const menuOpen = ref(false)
function run(fn) { menuOpen.value = false; fn() }
function onMenuBlur() { setTimeout(() => { menuOpen.value = false }, 150) }

const tabs = computed(() => [
  { id: 'parsed',      label: t('entry.parsed'),       available: true },
  { id: 'raw',         label: t('entry.raw'),          available: true },
  { id: 'trace',       label: t('entry.trace'),        available: !!entry.value?.exception },
  { id: 'occurrences', label: t('detail.occurrences'), available: entry.value?.fingerprint != null },
  { id: 'context',     label: t('detail.surrounding'), available: true },
  { id: 'mail',        label: t('detail.mail'),        available: !!mailData.value },
].filter(tab => tab.available))

// Only fetch a mail preview for entries that actually look like a logged email
// (MAIL_MAILER=log) — avoids a wasted request + 422 on every normal log entry.
function looksLikeMail(e) {
  const r = e?.raw ?? ''
  return /MIME-Version:|Content-Type:\s*(?:multipart|text\/html)|Message-ID:/i.test(r)
}

let _mailReqId = 0
// Drive off the selected entry's seq (not the object identity) so upgrading the
// list copy to the full entry doesn't reset the open tab.
watch(() => entry.value?.seq, async (seq) => {
  contextLines.value = []
  occurrences.value = []
  mailData.value = null
  if (seq == null) return
  activeTab.value = entry.value?.exception ? 'trace' : 'parsed'

  if (entriesStore.activeFileId && looksLikeMail(entry.value)) {
    const reqId = ++_mailReqId
    mailLoading.value = true
    try {
      const data = await api.getMail(entriesStore.activeFileId, seq)
      if (reqId === _mailReqId) mailData.value = data
    } catch {
      if (reqId === _mailReqId) mailData.value = null
    } finally {
      if (reqId === _mailReqId) mailLoading.value = false
    }
  }
})

watch(() => activeTab.value === 'context' && entry.value?.seq, async (trigger) => {
  if (!trigger) return
  contextLoading.value = true
  try {
    const data = await entriesStore.loadContext(entry.value.seq)
    contextLines.value = data?.entries ?? []
  } finally {
    contextLoading.value = false
  }
}, { immediate: false })

// Occurrences: all entries sharing this one's fingerprint (the same error type).
let _occReqId = 0
watch(() => activeTab.value === 'occurrences' && entry.value?.seq, async (trigger) => {
  if (!trigger || entry.value?.fingerprint == null) return
  const reqId = ++_occReqId
  occLoading.value = true
  try {
    const data = await api.getEntries(entriesStore.activeFileId, {
      group: entry.value.fingerprint, limit: OCC_LIMIT, direction: 'older',
    })
    if (reqId === _occReqId) occurrences.value = data?.entries ?? []
  } finally {
    if (reqId === _occReqId) occLoading.value = false
  }
}, { immediate: false })

async function writeClipboard(text, okMsg) {
  if (!text) return
  try {
    await navigator.clipboard?.writeText(text)
    uiStore.toastSuccess(okMsg)
  } catch {
    uiStore.toastError(t('detail.copyFailed'))
  }
}

function copyMessage() {
  writeClipboard(entry.value?.message, t('detail.copiedMessage'))
}

// Copy the full raw log entry (timestamp, level, message, stack trace, context).
function copyRaw() {
  writeClipboard(entry.value?.raw ?? entry.value?.message, t('detail.copiedRaw'))
}

// Download this single entry as a .log text file (client-side, no server call).
function downloadEntry() {
  const e = entry.value
  if (!e) return
  const text = e.raw ?? e.message ?? ''
  const blob = new Blob([text], { type: 'text/plain;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  const stamp = (e.datetime ?? '').replace(/[:.]/g, '-') || ('entry-' + (e.seq ?? ''))
  a.href = url
  a.download = `loglens-${e.level ?? 'entry'}-${stamp}.log`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  setTimeout(() => URL.revokeObjectURL(url), 1000)
  uiStore.toastSuccess(t('detail.downloaded'))
}

// Jump from a surrounding line to that entry (loads it into the drawer).
function jumpToLine(seq) {
  entriesStore.selectEntry(seq)
}

function viewInContext() {
  activeTab.value = 'context'
  emit('context', entry.value?.seq)
}

async function copyPermalink() {
  if (!entry.value?.seq || !entriesStore.activeFileId) return
  const url = new URL(window.location.href)
  url.searchParams.set('file', entriesStore.activeFileId)
  url.searchParams.set('entry', String(entry.value.seq))
  await writeClipboard(url.href, t('detail.copiedLink'))
}

// Logged email HTML is attacker-controlled. Render it inside a fully sandboxed
// iframe (no allow-scripts, no allow-same-origin) so it can neither run JS nor
// reach this origin's cookies/DOM — the real barrier, not regex stripping. A
// locked-down CSP and a forced UTF-8 base document add defence in depth. The
// server already strips dangerous elements before this point as a third layer.
const mailSrcdoc = computed(() => {
  const html = mailData.value?.html
  if (!html) return ''
  return '<!doctype html><html><head><meta charset="utf-8">'
    + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; '
    + 'img-src data: https: http:; style-src \'unsafe-inline\'; font-src data:;">'
    + '<base target="_blank">'
    + '<style>html,body{margin:0}body{font:13px/1.6 system-ui,-apple-system,sans-serif;'
    + 'padding:10px;color:#111;background:#fff;word-break:break-word;overflow-wrap:anywhere}'
    + 'img{max-width:100%;height:auto}a{color:#2563eb}</style></head><body>'
    + html + '</body></html>'
})
</script>

<style scoped>
.ll-detail-drawer {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--ll-surface-raised);
  border-inline-start: 1px solid var(--ll-border);
  overflow: hidden;
  min-width: 0;
}

.ll-detail-drawer__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  border-block-end: 1px solid var(--ll-border);
  gap: 8px;
  flex-shrink: 0;
}

.ll-detail-drawer__meta {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;           /* allow the time to truncate instead of pushing the
                            close button off the drawer */
  flex: 1;
}

.ll-detail-drawer__time {
  font-size: 12px;
  color: var(--ll-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ll-detail-drawer__chan {
  font-size: 12px;
  color: var(--ll-text-faint);
  flex-shrink: 0;
  max-width: 90px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ll-detail-drawer__actions {
  display: flex;
  align-items: center;
  gap: 2px;
  flex-shrink: 0;
}

.ll-detail-menu { position: relative; }

.ll-detail-menu__list {
  position: absolute;
  inset-block-start: calc(100% + 4px);
  inset-inline-end: 0;
  background: var(--ll-surface-overlay);
  border: 1px solid var(--ll-border);
  border-radius: 6px;
  min-width: 170px;
  z-index: 50;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4);
  overflow: hidden;
  padding: 4px;
}

.ll-detail-menu__item {
  display: block;
  width: 100%;
  text-align: start;
  padding: 7px 10px;
  font-size: 12.5px;
  background: none;
  border: none;
  color: var(--ll-text);
  cursor: pointer;
  border-radius: 4px;
}
.ll-detail-menu__item:hover { background: var(--ll-surface-raised); }
.ll-detail-menu__item--danger { color: var(--ll-level-error); }
.ll-detail-menu__item--danger:hover { background: var(--ll-level-error-bg); }

.ll-detail-drawer__tabs {
  display: flex;
  border-block-end: 1px solid var(--ll-border);
  flex-shrink: 0;
}

.ll-tab-btn {
  padding: 6px 12px;
  font-size: 12px;
  color: var(--ll-text-muted);
  background: none;
  border: none;
  border-block-end: 2px solid transparent;
  cursor: pointer;
  transition: color 0.1s, border-color 0.1s;
}

.ll-tab-btn:hover:not(:disabled) { color: var(--ll-text); }
.ll-tab-btn--active { color: var(--ll-accent) !important; border-block-end-color: var(--ll-accent); }
.ll-tab-btn:disabled { opacity: 0.4; cursor: default; }

.ll-detail-drawer__body {
  flex: 1;
  overflow-y: auto;
  padding: 12px;
}

.ll-detail-drawer__loading {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}

.ll-detail-parsed__message {
  font-size: 13px;
  color: var(--ll-text);
  padding: 8px;
  background: var(--ll-surface-sunken);
  border-radius: 6px;
  word-break: break-word;
  margin-block-end: 12px;
  white-space: pre-wrap;
}

.ll-detail-section-label {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--ll-text-faint);
  margin-block: 12px 6px;
}

.ll-detail-raw pre {
  font-size: 11px;
  white-space: pre-wrap;
  word-break: break-all;
  color: var(--ll-text);
  background: var(--ll-surface-sunken);
  padding: 8px;
  border-radius: 4px;
  margin: 0;
}

.ll-context-caption {
  font-size: 11px;
  line-height: 1.4;
  color: var(--ll-text-faint);
  padding: 2px 6px 8px;
}

.ll-occ-summary {
  font-size: 12px;
  font-weight: 600;
  color: var(--ll-text);
  padding: 4px 6px 8px;
}

.ll-context-line {
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding: 3px 6px;
  font-size: 12px;
  border-inline-start: 2px solid transparent;
  border-block-end: 1px solid var(--ll-border-subtle);
  cursor: pointer;
  transition: background 0.1s;
}

.ll-context-line:hover { background: var(--ll-surface-overlay); }

.ll-context-line--selected {
  background: var(--ll-accent-bg);
  border-inline-start-color: var(--ll-accent);
  cursor: default;
}

.ll-context-line__here {
  font-size: 9px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--ll-accent);
  flex-shrink: 0;
  white-space: nowrap;
}

.ll-context-line__ts {
  font-size: 10px;
  color: var(--ll-text-faint);
  min-width: 64px;
  flex-shrink: 0;
}

.ll-context-line__msg {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--ll-text);
}

.ll-mail-headers {
  font-size: 12px;
  color: var(--ll-text-muted);
  padding: 8px;
  background: var(--ll-surface-sunken);
  border-radius: 4px;
  margin-block-end: 12px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.ll-mail-frame {
  width: 100%;
  min-height: 240px;
  height: 50vh;
  border: 1px solid var(--ll-border);
  border-radius: 4px;
  background: #fff;
  display: block;
}

.ll-mail-attachments {
  margin-block-start: 12px;
}

.ll-mail-attachment {
  font-size: 12px;
  color: var(--ll-text-muted);
  padding: 4px 0;
}

.ll-icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 5px;
  border: none;
  background: none;
  color: var(--ll-text-muted);
  cursor: pointer;
  transition: background 0.1s, color 0.1s;
}

.ll-icon-btn:hover {
  background: var(--ll-surface-overlay);
  color: var(--ll-text);
}

/* The close button is visually distinct so it's obvious how to dismiss the panel. */
.ll-icon-btn--close {
  width: auto;
  gap: 4px;
  padding-inline: 6px;
  border: 1px solid var(--ll-border);
  color: var(--ll-text-muted);
}
.ll-icon-btn--close:hover {
  background: var(--ll-level-error-bg);
  color: var(--ll-level-error);
  border-color: var(--ll-level-error);
}
.ll-icon-btn__label {
  font-size: 10px;
  font-family: 'JetBrains Mono', monospace;
}

.ll-icon-btn--danger:hover {
  background: var(--ll-level-error-bg);
  color: var(--ll-level-error);
}
</style>
