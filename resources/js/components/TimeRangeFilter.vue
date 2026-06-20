<template>
  <div class="ll-timerange">
    <button class="ll-timerange__btn" :class="{ 'll-timerange__btn--set': hasRange }" @click="toggle" @blur="onBlur">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM1.5 8a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0Zm7-3.25v2.992l2.028.812a.75.75 0 0 1-.557 1.392l-2.5-1A.751.751 0 0 1 7 8.25v-3.5a.75.75 0 0 1 1.5 0Z"/></svg>
      <span class="ll-timerange__label">{{ label }}</span>
      <svg width="10" height="10" viewBox="0 0 16 16" fill="currentColor"><path d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z"/></svg>
    </button>

    <div v-if="open" class="ll-timerange__menu" @mousedown.prevent>
      <button
        v-for="p in presets"
        :key="p.key"
        class="ll-timerange__item"
        @click="applyPreset(p)"
      >{{ p.label }}</button>

      <div class="ll-timerange__divider" />

      <div class="ll-timerange__custom">
        <div class="ll-timerange__custom-title">{{ t('time.custom') }}</div>
        <label class="ll-timerange__field">
          <span>{{ t('time.from') }}</span>
          <input type="datetime-local" v-model="customFrom" />
        </label>
        <label class="ll-timerange__field">
          <span>{{ t('time.to') }}</span>
          <input type="datetime-local" v-model="customTo" />
        </label>
        <button class="ll-timerange__apply" :disabled="!customFrom && !customTo" @click="applyCustom">
          {{ t('time.apply') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useEntriesStore } from '../stores/entries.js'
import { t } from '../i18n.js'

const entriesStore = useEntriesStore()

const open = ref(false)
const customFrom = ref('')
const customTo = ref('')

const after  = computed(() => entriesStore.activeFilters.after)
const before = computed(() => entriesStore.activeFilters.before)
const hasRange = computed(() => !!after.value || !!before.value)

const presets = [
  { key: 'all', label: t('time.all'),     mins: null },
  { key: '15m', label: t('time.last15m'), mins: 15 },
  { key: '1h',  label: t('time.last1h'),  mins: 60 },
  { key: '6h',  label: t('time.last6h'),  mins: 360 },
  { key: '24h', label: t('time.last24h'), mins: 1440 },
  { key: '7d',  label: t('time.last7d'),  mins: 10080 },
]

function fmtShort(v) {
  const d = new Date(v)
  if (isNaN(d.getTime())) return String(v)
  return d.toLocaleString([], { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' })
}

const label = computed(() => {
  if (!hasRange.value) return t('time.all')
  if (after.value && before.value) return `${fmtShort(after.value)} – ${fmtShort(before.value)}`
  if (after.value) return `${t('filters.from')} ${fmtShort(after.value)}`
  return `${t('filters.until')} ${fmtShort(before.value)}`
})

function toggle() { open.value = !open.value }
function onBlur() { setTimeout(() => { open.value = false }, 150) }

function applyPreset(p) {
  open.value = false
  if (p.mins == null) {
    entriesStore.applyFilters({ after: null, before: null })
    return
  }
  const after = new Date(Date.now() - p.mins * 60000).toISOString()
  entriesStore.applyFilters({ after, before: null })
}

// datetime-local gives a local "YYYY-MM-DDTHH:mm"; convert to ISO for the API.
function toIso(local) {
  if (!local) return null
  const d = new Date(local)
  return isNaN(d.getTime()) ? null : d.toISOString()
}

function applyCustom() {
  open.value = false
  entriesStore.applyFilters({ after: toIso(customFrom.value), before: toIso(customTo.value) })
}

// Prefill the custom inputs from the active filter when the menu opens.
watch(open, (o) => {
  if (!o) return
  customFrom.value = toLocalInput(after.value)
  customTo.value = toLocalInput(before.value)
})

function toLocalInput(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  if (isNaN(d.getTime())) return ''
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}
</script>

<style scoped>
.ll-timerange { position: relative; }

.ll-timerange__btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 9px;
  font-size: 11px;
  border: 1px solid var(--ll-border-subtle);
  background: var(--ll-surface-sunken);
  color: var(--ll-text-muted);
  border-radius: 5px;
  cursor: pointer;
  transition: background 0.1s, color 0.1s, border-color 0.1s;
}
.ll-timerange__btn:hover { background: var(--ll-surface-overlay); color: var(--ll-text); }
.ll-timerange__btn--set {
  border-color: var(--ll-accent);
  color: var(--ll-accent);
  background: var(--ll-accent-bg);
}

.ll-timerange__label {
  max-width: 220px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ll-timerange__menu {
  position: absolute;
  inset-block-start: calc(100% + 4px);
  inset-inline-start: 0;
  background: var(--ll-surface-overlay);
  border: 1px solid var(--ll-border);
  border-radius: 8px;
  min-width: 220px;
  z-index: 50;
  box-shadow: 0 6px 24px rgba(0, 0, 0, 0.45);
  padding: 4px;
}

.ll-timerange__item {
  display: block;
  width: 100%;
  text-align: start;
  padding: 6px 10px;
  font-size: 12px;
  background: none;
  border: none;
  color: var(--ll-text);
  cursor: pointer;
  border-radius: 5px;
}
.ll-timerange__item:hover { background: var(--ll-surface-raised); }

.ll-timerange__divider {
  height: 1px;
  background: var(--ll-border-subtle);
  margin: 4px 0;
}

.ll-timerange__custom { padding: 4px 6px 6px; }
.ll-timerange__custom-title {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--ll-text-faint);
  margin-block-end: 6px;
}

.ll-timerange__field {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: 10.5px;
  color: var(--ll-text-muted);
  margin-block-end: 6px;
}
.ll-timerange__field input {
  background: var(--ll-surface-sunken);
  border: 1px solid var(--ll-border);
  border-radius: 4px;
  color: var(--ll-text);
  padding: 4px 6px;
  font-size: 12px;
  color-scheme: dark;
}

.ll-timerange__apply {
  width: 100%;
  padding: 5px;
  font-size: 12px;
  background: var(--ll-accent);
  border: none;
  border-radius: 5px;
  color: #fff;
  cursor: pointer;
  margin-block-start: 2px;
}
.ll-timerange__apply:disabled { opacity: 0.5; cursor: default; }
</style>
