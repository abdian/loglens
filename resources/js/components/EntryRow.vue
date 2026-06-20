<template>
  <div
    :class="[
      'll-entry-row',
      `ll-entry-row--${entry.level}`,
      { 'll-entry-row--selected': selected },
      { 'll-entry-row--error-class': isError },
      { 'll-entry-row--fresh': entry._fresh },
    ]"
    :data-seq="entry.seq"
    @click="emit('select', entry.seq)"
    @dblclick="emit('open', entry.seq)"
  >
    <!-- Timestamp -->
    <span class="ll-entry-row__ts" dir="ltr">{{ shortTs }}</span>

    <!-- Level badge -->
    <span :class="['ll-level-badge', `ll-level-${entry.level}`]">
      {{ entry.level }}
    </span>

    <!-- Channel -->
    <span v-if="entry.channel" class="ll-entry-row__channel">{{ entry.channel }}</span>

    <!-- Message / tokens -->
    <span class="ll-entry-row__message ll-log-content" dir="ltr">
      <template v-if="highlights.length">
        <span
          v-for="(part, i) in highlightedParts"
          :key="i"
          :class="part.highlight ? 'll-highlight' : ''"
          v-html="escapeHtml(part.text)"
        />
      </template>
      <template v-else-if="entry.tokens?.length">
        <span
          v-for="(tok, i) in entry.tokens"
          :key="i"
          :class="tok.classes"
          v-html="tok.text"
        />
      </template>
      <template v-else>{{ entry.message }}</template>
    </span>

    <!-- Exception indicator -->
    <span v-if="entry.exception" class="ll-entry-row__exc" title="Has stack trace">
      <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor">
        <path d="M6.457 1.047c.659-1.234 2.427-1.234 3.086 0l6.082 11.378A1.75 1.75 0 0 1 14.082 15H1.918a1.75 1.75 0 0 1-1.543-2.575Zm1.763.707a.25.25 0 0 0-.44 0L1.698 13.132a.25.25 0 0 0 .22.368h12.164a.25.25 0 0 0 .22-.368Zm.53 3.996v2.5a.75.75 0 0 1-1.5 0v-2.5a.75.75 0 0 1 1.5 0ZM9 11a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/>
      </svg>
    </span>

    <!-- Delete (opens a confirmation dialog) -->
    <button
      class="ll-entry-row__del"
      :title="t('entry.delete')"
      @click.stop="emit('delete', entry.seq)"
    >
      <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor">
        <path d="M11 1.75V3h2.25a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1 0-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75ZM4.496 6.675l.66 6.6a.25.25 0 0 0 .249.225h5.19a.25.25 0 0 0 .249-.225l.66-6.6a.75.75 0 0 1 1.492.149l-.66 6.6A1.748 1.748 0 0 1 10.595 15h-5.19a1.75 1.75 0 0 1-1.741-1.575l-.66-6.6a.75.75 0 1 1 1.492-.15ZM6.5 1.75V3h3V1.75a.25.25 0 0 0-.25-.25h-2.5a.25.25 0 0 0-.25.25Z"/>
      </svg>
    </button>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { t } from '../i18n.js'

const props = defineProps({
  entry:    { type: Object, required: true },
  selected: { type: Boolean, default: false },
})

const emit = defineEmits(['select', 'open', 'delete'])

const ERROR_LEVELS = new Set(['error', 'critical', 'alert', 'emergency'])
const isError = computed(() => ERROR_LEVELS.has(props.entry.level))

const shortTs = computed(() => {
  const dt = props.entry.datetime ?? props.entry.timestamp
  if (!dt) return ''
  // Show HH:MM:SS if today, otherwise MM-DD HH:MM
  const d = new Date(dt)
  const now = new Date()
  const sameDay = d.toDateString() === now.toDateString()
  if (sameDay) {
    return d.toTimeString().slice(0, 8)
  }
  const mo = String(d.getMonth() + 1).padStart(2, '0')
  const dy = String(d.getDate()).padStart(2, '0')
  const hh = String(d.getHours()).padStart(2, '0')
  const mm = String(d.getMinutes()).padStart(2, '0')
  return `${mo}-${dy} ${hh}:${mm}`
})

function escapeHtml(str) {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

// entry.highlights are server-computed CODE-POINT offsets into entry.message
// (the same redacted string we render). We slice on a code-point array so
// astral characters (emoji) don't shift the offsets vs PHP's mb_strlen.
const highlights = computed(() => props.entry.highlights ?? [])

const highlightedParts = computed(() => {
  const msg = props.entry.message ?? ''
  const hl = highlights.value
  if (!hl.length) return [{ text: msg, highlight: false }]

  const chars = Array.from(msg) // code points
  const parts = []
  let pos = 0
  for (const { start, length } of hl) {
    if (start > pos) parts.push({ text: chars.slice(pos, start).join(''), highlight: false })
    parts.push({ text: chars.slice(start, start + length).join(''), highlight: true })
    pos = start + length
  }
  if (pos < chars.length) parts.push({ text: chars.slice(pos).join(''), highlight: false })
  return parts
})
</script>

<style scoped>
.ll-entry-row {
  position: relative;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 5px 12px 5px 13px;
  cursor: pointer;
  border-block-end: 1px solid var(--ll-border-subtle);
  transition: background 0.1s ease;
  user-select: none;
  /* Fixed height to match the virtualizer's row size (border-box so the bottom
     border is included and rows never overlap). */
  box-sizing: border-box;
  height: 31px;
  font-size: 13px;
  line-height: 1.5;
}

.ll-entry-row:hover {
  background: var(--ll-surface-overlay);
}

.ll-entry-row--selected {
  background: var(--ll-accent-bg) !important;
}

.ll-entry-row__ts {
  flex-shrink: 0;
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  color: var(--ll-text-faint);
  min-width: 70px;
  letter-spacing: -0.02em;
}

.ll-entry-row__channel {
  flex-shrink: 0;
  font-size: 10.5px;
  color: var(--ll-text-muted);
  background: var(--ll-surface-overlay);
  padding: 1px 7px;
  border-radius: 10px;
  max-width: 96px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ll-entry-row:hover .ll-entry-row__channel {
  background: var(--ll-surface-sunken);
}

.ll-entry-row__message {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 12px;
  color: var(--ll-text);
  min-width: 0;
}

.ll-entry-row__exc {
  flex-shrink: 0;
  color: var(--ll-level-warning);
  display: inline-flex;
}

.ll-entry-row__del {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border: none;
  background: none;
  color: var(--ll-text-faint);
  border-radius: 6px;
  cursor: pointer;
  opacity: 0;
  transition: opacity 0.1s, background 0.1s, color 0.1s;
}

.ll-entry-row:hover .ll-entry-row__del { opacity: 1; }
.ll-entry-row__del:hover { background: var(--ll-level-error-bg); color: var(--ll-level-error); }

/* Freshly-arrived live-tail entry: brief flash + a leading accent that fades. */
.ll-entry-row--fresh {
  animation: ll-row-flash 2.5s ease-out;
}
.ll-entry-row--fresh::before {
  content: '';
  position: absolute;
  inset-inline-start: 0;
  inset-block: 0;
  width: 2px;
  background: var(--ll-level-notice);
  animation: ll-row-accent 2.5s ease-out forwards;
}
@keyframes ll-row-flash {
  0% { background: var(--ll-level-notice-bg); }
  100% { background: transparent; }
}
@keyframes ll-row-accent {
  0% { opacity: 1; }
  100% { opacity: 0; }
}
</style>
