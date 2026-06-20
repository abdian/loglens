<template>
  <div v-if="entriesStore.hasActiveFilters" class="ll-active-filters">
    <span class="ll-active-filters__label">{{ t('filters.active') }}</span>

    <!-- Level chips -->
    <button
      v-for="lv in levels"
      :key="'lv-' + lv"
      class="ll-filter-chip"
      :title="t('filters.removeLevel')"
      @click="entriesStore.removeLevel(lv)"
    >
      <span :class="['ll-level-dot', `ll-level-${lv}`]" />
      <span class="ll-filter-chip__text">{{ lv }}</span>
      <span class="ll-filter-chip__x">×</span>
    </button>

    <!-- Time range chip -->
    <button
      v-if="hasTime"
      class="ll-filter-chip"
      :title="t('filters.removeTime')"
      @click="entriesStore.clearTimeRange()"
    >
      <svg width="11" height="11" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM1.5 8a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0Zm7-3.25v2.992l2.028.812a.75.75 0 0 1-.557 1.392l-2.5-1A.751.751 0 0 1 7 8.25v-3.5a.75.75 0 0 1 1.5 0Z"/></svg>
      <span class="ll-filter-chip__text">{{ timeLabel }}</span>
      <span class="ll-filter-chip__x">×</span>
    </button>

    <!-- Issue / group chip -->
    <button
      v-if="group != null"
      class="ll-filter-chip ll-filter-chip--issue"
      :title="t('filters.removeIssue')"
      @click="entriesStore.clearGroup()"
    >
      <svg width="11" height="11" viewBox="0 0 16 16" fill="currentColor"><path d="M6.457 1.047c.659-1.234 2.427-1.234 3.086 0l6.082 11.378A1.75 1.75 0 0 1 14.082 15H1.918a1.75 1.75 0 0 1-1.543-2.575Zm1.763.707a.25.25 0 0 0-.44 0L1.698 13.132a.25.25 0 0 0 .22.368h12.164a.25.25 0 0 0 .22-.368Zm.53 3.996v2.5a.75.75 0 0 1-1.5 0v-2.5a.75.75 0 0 1 1.5 0ZM9 11a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg>
      <span class="ll-filter-chip__text">{{ groupLabel || t('filters.issue') }}</span>
      <span class="ll-filter-chip__x">×</span>
    </button>

    <button class="ll-active-filters__clear" @click="entriesStore.clearFilters()">
      {{ t('filters.clearAll') }}
    </button>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useEntriesStore } from '../stores/entries.js'
import { t } from '../i18n.js'

const entriesStore = useEntriesStore()

const levels     = computed(() => entriesStore.activeFilters.levels ?? [])
const group      = computed(() => entriesStore.activeFilters.group)
const groupLabel = computed(() => entriesStore.activeFilters.groupLabel)
const after      = computed(() => entriesStore.activeFilters.after)
const before     = computed(() => entriesStore.activeFilters.before)
const hasTime    = computed(() => !!after.value || !!before.value)

function fmt(v) {
  if (!v) return '…'
  const d = new Date(v)
  if (isNaN(d.getTime())) return String(v)
  return d.toLocaleString()
}

const timeLabel = computed(() => {
  if (after.value && before.value) return `${fmt(after.value)} – ${fmt(before.value)}`
  if (after.value)  return `${t('filters.from')} ${fmt(after.value)}`
  return `${t('filters.until')} ${fmt(before.value)}`
})
</script>

<style scoped>
.ll-active-filters {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  padding: 6px 10px;
  background: var(--ll-surface);
  border-block-end: 1px solid var(--ll-border-subtle);
  flex-shrink: 0;
}

.ll-active-filters__label {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--ll-text-faint);
}

.ll-filter-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 8px;
  border-radius: 12px;
  border: 1px solid var(--ll-border);
  background: var(--ll-surface-raised);
  color: var(--ll-text);
  cursor: pointer;
  font-size: 11px;
  transition: background 0.1s, border-color 0.1s;
}

.ll-filter-chip:hover {
  background: var(--ll-level-error-bg);
  border-color: var(--ll-level-error);
}

.ll-filter-chip--issue { color: var(--ll-text); }

.ll-filter-chip__text {
  text-transform: capitalize;
  max-width: 280px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ll-filter-chip__x {
  font-size: 14px;
  line-height: 1;
  color: var(--ll-text-faint);
}

.ll-filter-chip:hover .ll-filter-chip__x { color: var(--ll-level-error); }

.ll-level-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.ll-level-dot.ll-level-debug     { background: var(--ll-level-debug); }
.ll-level-dot.ll-level-info      { background: var(--ll-level-info); }
.ll-level-dot.ll-level-notice    { background: var(--ll-level-notice); }
.ll-level-dot.ll-level-warning   { background: var(--ll-level-warning); }
.ll-level-dot.ll-level-error     { background: var(--ll-level-error); }
.ll-level-dot.ll-level-critical  { background: var(--ll-level-critical); }
.ll-level-dot.ll-level-alert     { background: var(--ll-level-alert); }
.ll-level-dot.ll-level-emergency { background: var(--ll-level-emergency); }

.ll-active-filters__clear {
  margin-inline-start: auto;
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  border: 1px solid var(--ll-border-subtle);
  background: none;
  color: var(--ll-text-muted);
  cursor: pointer;
}
.ll-active-filters__clear:hover {
  background: var(--ll-surface-overlay);
  color: var(--ll-text);
}
</style>
