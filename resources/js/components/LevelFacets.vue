<template>
  <div class="ll-level-facets">
    <div class="ll-panel-label">{{ t('levels.title') }}</div>
    <div
      v-for="lv in levelOrder"
      :key="lv"
      v-show="counts[lv] !== undefined"
      :class="['ll-facet-item', { 'll-facet-item--active': selectedLevels.includes(lv) }]"
      @click="toggleLevel(lv)"
    >
      <span :class="['ll-level-dot', `ll-level-${lv}`]" />
      <span class="ll-facet-item__name">{{ lv }}</span>
      <span class="ll-facet-item__count">{{ counts[lv]?.toLocaleString() ?? '—' }}</span>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useEntriesStore } from '../stores/entries.js'
import { useFilesStore } from '../stores/files.js'
import api from '../api.js'
import { t } from '../i18n.js'

const entriesStore = useEntriesStore()
const filesStore = useFilesStore()

const counts = ref({})
const selectedLevels = computed(() => entriesStore.activeFilters.levels ?? [])

const levelOrder = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug']

async function loadCounts() {
  const fileId = filesStore.activeFileId
  if (!fileId) return
  try {
    const data = await api.getLevels(fileId)
    counts.value = data.levels ?? {}
  } catch {}
}

watch(() => filesStore.activeFileId, loadCounts, { immediate: true })

function toggleLevel(lv) {
  const current = [...selectedLevels.value]
  const idx = current.indexOf(lv)
  if (idx === -1) current.push(lv)
  else current.splice(idx, 1)
  entriesStore.applyFilters({ levels: current })
}
</script>

<style scoped>
.ll-level-facets {
  padding: 8px 0;
}

.ll-panel-label {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--ll-text-faint);
  padding: 0 12px 6px;
}

.ll-facet-item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 12px;
  cursor: pointer;
  border-radius: 4px;
  margin-inline: 4px;
  transition: background 0.1s;
  user-select: none;
}

.ll-facet-item:hover { background: var(--ll-surface-raised); }
.ll-facet-item--active { background: var(--ll-accent-bg); }

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

.ll-facet-item__name {
  flex: 1;
  font-size: 12px;
  color: var(--ll-text);
  text-transform: capitalize;
}

.ll-facet-item__count {
  font-size: 11px;
  color: var(--ll-text-faint);
  font-family: 'JetBrains Mono', monospace;
}
</style>
