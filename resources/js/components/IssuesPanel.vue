<template>
  <div class="ll-issues-panel">
    <div class="ll-panel-label">{{ t('groups.title') }}</div>

    <!-- One-line explanation of what this panel is -->
    <p class="ll-issues-panel__intro">{{ t('groups.intro') }}</p>

    <!-- Sort controls -->
    <div class="ll-issues-panel__sort">
      <button
        v-for="opt in sortOptions"
        :key="opt.value"
        :class="['ll-sort-btn', { 'll-sort-btn--active': sort === opt.value }]"
        @click="sort = opt.value"
      >{{ opt.label }}</button>
    </div>

    <div v-if="loading" class="ll-issues-panel__empty">
      <span class="ll-spinner-sm" />
    </div>

    <div v-else-if="!supported" class="ll-issues-panel__empty ll-issues-panel__empty--block">
      {{ t('groups.unsupported') }}
    </div>

    <div v-else-if="!groups.length" class="ll-issues-panel__empty ll-issues-panel__empty--block">
      {{ t('groups.empty') }}
    </div>

    <!-- Group list -->
    <div
      v-for="group in sortedGroups"
      :key="group.fp"
      class="ll-issue-item"
      :class="{ 'll-issue-item--active': activeGroup === group.fp }"
      :title="group.title"
      @click="drillIn(group)"
    >
      <div class="ll-issue-item__header">
        <span :class="['ll-level-dot', `ll-level-${group.level}`]" style="width:8px;height:8px;border-radius:50%;flex-shrink:0" />
        <span class="ll-issue-item__title">{{ group.title }}</span>
        <span class="ll-issue-item__count">{{ group.count.toLocaleString() }}</span>
      </div>
      <div class="ll-issue-item__meta">
        <span class="ll-issue-item__ts">Last: {{ formatRelative(group.last_seen) }}</span>
        <Sparkline
          v-if="group.sparkline?.length"
          :series="group.sparkline"
          :width="60"
          :height="14"
          :color="levelColor(group.level)"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useFilesStore } from '../stores/files.js'
import { useEntriesStore } from '../stores/entries.js'
import Sparkline from './Sparkline.vue'
import api from '../api.js'
import { t } from '../i18n.js'

const filesStore = useFilesStore()
const entriesStore = useEntriesStore()

const groups    = ref([])
const loading   = ref(false)
const supported = ref(true)
const sort      = ref('count') // 'count' | 'last_ts' | 'first_ts'

const activeGroup = computed(() => entriesStore.activeFilters.group)

const sortOptions = [
  { label: t('groups.sortCount'),  value: 'count' },
  { label: t('groups.sortRecent'), value: 'last_ts' },
  { label: t('groups.sortFirst'),  value: 'first_ts' },
]

const sortedGroups = computed(() => {
  const list = [...groups.value]
  if (sort.value === 'count')   return list.sort((a, b) => b.count - a.count)
  if (sort.value === 'last_ts') return list.sort((a, b) => new Date(b.last_seen) - new Date(a.last_seen))
  if (sort.value === 'first_ts') return list.sort((a, b) => new Date(a.first_seen) - new Date(b.first_seen))
  return list
})

async function loadGroups() {
  const fileId = filesStore.activeFileId
  if (!fileId) return
  loading.value = true
  try {
    const data = await api.getGroups(fileId, { sort: sort.value })
    groups.value = data.groups ?? []
    supported.value = data.supported !== false
  } catch {} finally {
    loading.value = false
  }
}

watch(() => filesStore.activeFileId, loadGroups, { immediate: true })
watch(sort, loadGroups)

function drillIn(group) {
  // Drill into all occurrences of this issue by filtering the entry list to the
  // group's fingerprint. The label rides along so the active-filters bar can
  // show which issue is pinned and offer a one-click way out.
  if (filesStore.activeFileId) {
    // Clear level filters so the issue's own entries always show (an issue is a
    // single level; keeping a mismatched level filter would hide everything).
    entriesStore.applyFilters({ group: group.fp, groupLabel: group.title, levels: [] })
  }
}

function formatRelative(iso) {
  if (!iso) return '—'
  const diff = Date.now() - new Date(iso).getTime()
  const mins = Math.floor(diff / 60000)
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  return `${Math.floor(hrs / 24)}d ago`
}

const LEVEL_COLORS = {
  debug: '#6e7681', info: '#388bfd', notice: '#3fb950',
  warning: '#d29922', error: '#f85149', critical: '#ff7b72',
  alert: '#ffa657', emergency: '#ff6e96',
}

function levelColor(lv) {
  return LEVEL_COLORS[lv] ?? '#888'
}
</script>

<style scoped>
.ll-issues-panel {
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}

.ll-panel-label {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--ll-text-faint);
  padding: 8px 12px 6px;
  flex-shrink: 0;
}

.ll-issues-panel__intro {
  font-size: 11px;
  line-height: 1.4;
  color: var(--ll-text-faint);
  padding: 0 12px 8px;
  margin: 0;
  flex-shrink: 0;
}

.ll-issues-panel__sort {
  display: flex;
  gap: 2px;
  padding: 0 8px 6px;
  flex-shrink: 0;
}

.ll-sort-btn {
  font-size: 11px;
  padding: 2px 7px;
  border-radius: 4px;
  border: 1px solid var(--ll-border-subtle);
  background: none;
  color: var(--ll-text-muted);
  cursor: pointer;
  transition: background 0.1s;
}

.ll-sort-btn:hover { background: var(--ll-surface-raised); }
.ll-sort-btn--active {
  background: var(--ll-accent-bg);
  color: var(--ll-accent);
  border-color: var(--ll-accent);
}

.ll-issues-panel__empty {
  padding: 16px;
  font-size: 12px;
  color: var(--ll-text-faint);
  display: flex;
  justify-content: center;
}

.ll-issues-panel__empty--block {
  text-align: center;
  line-height: 1.5;
}

.ll-issue-item {
  padding: 6px 12px;
  cursor: pointer;
  border-block-end: 1px solid var(--ll-border-subtle);
  border-inline-start: 2px solid transparent;
  transition: background 0.1s;
}

.ll-issue-item:hover { background: var(--ll-surface-raised); }
.ll-issue-item--active {
  background: var(--ll-accent-bg);
  border-inline-start-color: var(--ll-accent);
}

.ll-issue-item__header {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-block-end: 2px;
}

.ll-issue-item__title {
  flex: 1;
  font-size: 12px;
  color: var(--ll-text);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ll-issue-item__count {
  font-size: 11px;
  font-family: 'JetBrains Mono', monospace;
  color: var(--ll-text-muted);
  background: var(--ll-surface-overlay);
  padding: 0 5px;
  border-radius: 8px;
}

.ll-issue-item__meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-inline-start: 14px;
}

.ll-issue-item__ts {
  font-size: 10px;
  color: var(--ll-text-faint);
}

.ll-spinner-sm {
  display: inline-block;
  width: 12px;
  height: 12px;
  border: 2px solid var(--ll-border);
  border-top-color: var(--ll-accent);
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }
</style>
