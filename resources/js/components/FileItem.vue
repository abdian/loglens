<template>
  <div
    :class="['ll-file-item', { 'll-file-item--active': active, 'll-file-item--indented': indented }]"
    role="button"
    tabindex="0"
    @click="$emit('select', file.id)"
    @keydown.enter="$emit('select', file.id)"
  >
    <span class="ll-file-item__name-row">
      <svg class="ll-file-item__icon" width="13" height="13" viewBox="0 0 16 16" fill="currentColor">
        <path d="M2 1.75C2 .784 2.784 0 3.75 0h6.586c.464 0 .909.184 1.237.513l2.914 2.914c.329.328.513.773.513 1.237v9.586A1.75 1.75 0 0 1 13.25 16h-9.5A1.75 1.75 0 0 1 2 14.25Zm1.75-.25a.25.25 0 0 0-.25.25v12.5c0 .138.112.25.25.25h9.5a.25.25 0 0 0 .25-.25V6h-2.75A1.75 1.75 0 0 1 8.75 4.25V1.5Zm6.75.062V4.25c0 .138.112.25.25.25h2.688Z"/>
      </svg>
      <span class="ll-file-item__name">{{ file.name }}</span>
      <span v-if="file.compressed" class="ll-file-item__gz">gz</span>

      <!-- Hover actions -->
      <span class="ll-file-item__actions" @click.stop>
        <button class="ll-file-act" :title="t('files.download')" @click.stop="download">
          <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M7.47 10.78a.75.75 0 0 0 1.06 0l3.75-3.75a.749.749 0 1 0-1.06-1.06L8.75 8.44V1.75a.75.75 0 0 0-1.5 0v6.69L4.78 5.97a.749.749 0 1 0-1.06 1.06ZM3.75 13a.75.75 0 0 0 0 1.5h8.5a.75.75 0 0 0 0-1.5Z"/></svg>
        </button>
        <button class="ll-file-act" :title="t('files.clear')" @click.stop="clear">
          <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M5.75 3a.75.75 0 0 0-.75.75v.75H2.75a.75.75 0 0 0 0 1.5h.357l.64 7.682A1.75 1.75 0 0 0 5.491 15h5.018a1.75 1.75 0 0 0 1.744-1.318l.64-7.682h.357a.75.75 0 0 0 0-1.5H11v-.75a.75.75 0 0 0-.75-.75ZM6.5 4.5v-.75h3v.75Z"/></svg>
        </button>
        <button class="ll-file-act ll-file-act--danger" :title="t('files.delete')" @click.stop="remove">
          <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor"><path d="M11 1.75V3h2.25a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1 0-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75ZM4.496 6.675l.66 6.6a.25.25 0 0 0 .249.225h5.19a.25.25 0 0 0 .249-.225l.66-6.6a.75.75 0 0 1 1.492.149l-.66 6.6A1.748 1.748 0 0 1 10.595 15h-5.19a1.75 1.75 0 0 1-1.741-1.575l-.66-6.6a.75.75 0 1 1 1.492-.15ZM6.5 1.75V3h3V1.75a.25.25 0 0 0-.25-.25h-2.5a.25.25 0 0 0-.25.25Z"/></svg>
        </button>
      </span>
    </span>
    <span class="ll-file-item__meta-row">
      <span class="ll-file-item__size">{{ file.size_human }}</span>
      <Sparkline v-if="sparkline?.length" :series="sparkline" :width="56" :height="14" :color="sparkColor" />
    </span>
  </div>
</template>

<script setup>
import { useFilesStore } from '../stores/files.js'
import { useEntriesStore } from '../stores/entries.js'
import { useUiStore } from '../stores/ui.js'
import Sparkline from './Sparkline.vue'
import { t } from '../i18n.js'

const props = defineProps({
  file:     { type: Object, required: true },
  active:   { type: Boolean, default: false },
  indented: { type: Boolean, default: false },
  sparkline:{ type: Array,  default: () => [] },
})
defineEmits(['select'])

const filesStore   = useFilesStore()
const entriesStore = useEntriesStore()
const uiStore      = useUiStore()
const sparkColor   = '#7c8cff'

async function download() {
  try {
    await filesStore.downloadFile(props.file.id)
  } catch {
    uiStore.toastError(t('files.actionFailed'))
  }
}

async function clear() {
  const ok = await uiStore.confirm({
    title: t('files.clearTitle'),
    message: t('files.clearMessage', { name: props.file.name }),
    confirmLabel: t('files.clear'),
    danger: true,
  })
  if (!ok) return
  try {
    await filesStore.clearFile(props.file.id)
    uiStore.toastSuccess(t('files.cleared'))
    if (entriesStore.activeFileId === props.file.id) entriesStore.openFile(props.file.id)
  } catch {
    uiStore.toastError(t('files.actionFailed'))
  }
}

async function remove() {
  const ok = await uiStore.confirm({
    title: t('files.deleteTitle'),
    message: t('files.deleteMessage', { name: props.file.name }),
    confirmLabel: t('files.delete'),
    danger: true,
  })
  if (!ok) return
  const wasActive = filesStore.activeFileId === props.file.id
  try {
    await filesStore.deleteFile(props.file.id)
    uiStore.toastSuccess(t('files.deleted'))
    if (wasActive) {
      const next = filesStore.allFiles[0]
      if (next) { filesStore.setActiveFile(next.id); entriesStore.openFile(next.id) }
      else entriesStore.clearActive() // no files left → empty the view, not stale rows
    }
  } catch {
    uiStore.toastError(t('files.actionFailed'))
  }
}
</script>

<style scoped>
.ll-file-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
  width: 100%;
  text-align: start;
  padding: 7px 9px;
  cursor: pointer;
  border-radius: var(--ll-radius-sm);
  margin-block: 2px;
  background: none;
  border: 1px solid transparent;
  border-inline-start: 2px solid transparent;
  transition: background 0.1s, border-color 0.1s;
}

.ll-file-item:hover { background: var(--ll-surface-overlay); }
.ll-file-item:focus-visible { outline: 2px solid var(--ll-accent); outline-offset: -2px; }
.ll-file-item--active {
  background: var(--ll-accent-bg);
  border-inline-start-color: var(--ll-accent);
}
.ll-file-item--indented { margin-inline-start: 14px; }

.ll-file-item__name-row {
  display: flex;
  align-items: center;
  gap: 7px;
}

.ll-file-item__icon { flex-shrink: 0; color: var(--ll-text-faint); }
.ll-file-item--active .ll-file-item__icon { color: var(--ll-accent); }

.ll-file-item__name {
  flex: 1;
  font-size: 12.5px;
  font-weight: 500;
  color: var(--ll-text);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ll-file-item__gz {
  font-size: 9px;
  padding: 1px 5px;
  border-radius: 4px;
  background: var(--ll-level-notice-bg);
  color: var(--ll-level-notice);
  text-transform: uppercase;
  font-weight: 700;
  letter-spacing: 0.04em;
}

.ll-file-item__actions {
  display: none;
  align-items: center;
  gap: 1px;
  flex-shrink: 0;
}
.ll-file-item:hover .ll-file-item__actions,
.ll-file-item:focus-within .ll-file-item__actions { display: inline-flex; }

.ll-file-act {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border: none;
  background: none;
  color: var(--ll-text-faint);
  border-radius: 4px;
  cursor: pointer;
  transition: background 0.1s, color 0.1s;
}
.ll-file-act:hover { background: var(--ll-surface-sunken); color: var(--ll-text); }
.ll-file-act--danger:hover { background: var(--ll-level-error-bg); color: var(--ll-level-error); }

.ll-file-item__meta-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding-inline-start: 20px;
}

.ll-file-item__size {
  font-size: 10.5px;
  color: var(--ll-text-faint);
  font-family: 'JetBrains Mono', monospace;
}
</style>
