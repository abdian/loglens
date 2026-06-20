<template>
  <Teleport to="body">
    <div v-if="open" class="ll-reports-overlay" @click.self="$emit('close')">
      <div class="ll-reports" role="dialog" aria-modal="true">
        <!-- Header -->
        <div class="ll-reports__header">
          <span class="ll-reports__title">
            {{ t('reports.title') }}
            <span v-if="fileName" class="ll-reports__file">· {{ fileName }}</span>
          </span>
          <button class="ll-reports__close" @click="$emit('close')">
            <svg width="13" height="13" viewBox="0 0 16 16" fill="currentColor"><path d="M3.72 3.72a.75.75 0 0 1 1.06 0L8 6.94l3.22-3.22a.749.749 0 0 1 1.06 1.06L9.06 8l3.22 3.22a.749.749 0 1 1-1.06 1.06L8 9.06l-3.22 3.22a.749.749 0 0 1-1.06-1.06L6.94 8 3.72 4.78a.75.75 0 0 1 0-1.06Z"/></svg>
            <span class="ll-reports__close-kbd">Esc</span>
          </button>
        </div>

        <div v-if="loading" class="ll-reports__loading">
          <span class="ll-spinner" /> {{ t('common.loading') }}
        </div>

        <div v-else-if="!hasData" class="ll-reports__loading">{{ t('reports.empty') }}</div>

        <div v-else class="ll-reports__body">
          <p v-if="partial" class="ll-reports__partial">{{ t('reports.partial') }}</p>

          <!-- Summary cards -->
          <div class="ll-reports__cards">
            <div class="ll-report-card">
              <div class="ll-report-card__num">{{ total.toLocaleString() }}</div>
              <div class="ll-report-card__label">{{ t('reports.total') }}</div>
            </div>
            <div class="ll-report-card ll-report-card--error" @click="filterLevels(ERROR_LEVELS)">
              <div class="ll-report-card__num">{{ errorCount.toLocaleString() }}</div>
              <div class="ll-report-card__label">{{ t('reports.errors') }}</div>
            </div>
            <div class="ll-report-card ll-report-card--warn" @click="filterLevels(['warning'])">
              <div class="ll-report-card__num">{{ (levels.warning ?? 0).toLocaleString() }}</div>
              <div class="ll-report-card__label">{{ t('reports.warnings') }}</div>
            </div>
            <div class="ll-report-card">
              <div class="ll-report-card__num ll-report-card__num--sm">{{ errorRate }}%</div>
              <div class="ll-report-card__label">{{ t('reports.errorRate') }}</div>
            </div>
          </div>

          <!-- Level distribution -->
          <section class="ll-report-section">
            <h3 class="ll-report-section__title">{{ t('reports.byLevel') }}</h3>
            <div
              v-for="row in levelRows"
              :key="row.name"
              class="ll-report-bar-row"
              :title="t('reports.filterByLevel')"
              @click="filterLevels([row.name])"
            >
              <span class="ll-report-bar-row__name">
                <span :class="['ll-level-dot', `ll-level-${row.name}`]" />
                {{ row.name }}
              </span>
              <span class="ll-report-bar-row__track">
                <span class="ll-report-bar-row__fill" :style="{ width: row.bar + '%', background: colorFor(row.name) }" />
              </span>
              <span class="ll-report-bar-row__count">{{ row.count.toLocaleString() }}</span>
              <span class="ll-report-bar-row__pct">{{ row.pct }}%</span>
            </div>
          </section>

          <!-- Volume over time -->
          <section v-if="histogramBuckets.length" class="ll-report-section">
            <h3 class="ll-report-section__title">{{ t('reports.volume') }}</h3>
            <Histogram
              :buckets="histogramBuckets"
              :granularity="histogramGranularity"
              :bucket="bucket"
              :height="140"
              @filter="onVolumeFilter"
              @granularity="onGranularity"
            />
          </section>

          <!-- Top recurring errors -->
          <section v-if="topIssues.length" class="ll-report-section">
            <h3 class="ll-report-section__title">{{ t('reports.topIssues') }}</h3>
            <div
              v-for="g in topIssues"
              :key="g.fp"
              class="ll-report-issue"
              :title="g.title"
              @click="drillIssue(g)"
            >
              <span :class="['ll-level-dot', `ll-level-${g.level}`]" />
              <span class="ll-report-issue__title">{{ g.title }}</span>
              <Sparkline
                v-if="g.sparkline?.length"
                :series="g.sparkline"
                :width="70"
                :height="16"
                :color="colorFor(g.level)"
              />
              <span class="ll-report-issue__count">{{ g.count.toLocaleString() }}</span>
            </div>
          </section>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useFilesStore } from '../stores/files.js'
import { useEntriesStore } from '../stores/entries.js'
import Histogram from './Histogram.vue'
import Sparkline from './Sparkline.vue'
import api from '../api.js'
import { t } from '../i18n.js'

const props = defineProps({ open: Boolean })
const emit = defineEmits(['close'])

const filesStore = useFilesStore()
const entriesStore = useEntriesStore()

const LEVEL_ORDER = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug']
const ERROR_LEVELS = ['error', 'critical', 'alert', 'emergency']
const LEVEL_COLORS = {
  emergency: '#f472b6', alert: '#fb923c', critical: '#ff5470', error: '#fb6a82',
  warning: '#f5b13d', notice: '#34d399', info: '#5b9bff', debug: '#7c8696', unknown: '#6b7280',
}
function colorFor(lv) { return LEVEL_COLORS[lv] ?? '#888' }

const loading = ref(false)
const levels = ref({})
const partial = ref(false)
const histogramBuckets = ref([])
const histogramGranularity = ref(3600)
const bucket = ref('auto')
const topIssues = ref([])

const fileName = computed(() => filesStore.activeFile?.name ?? null)
const total = computed(() => Object.values(levels.value).reduce((a, b) => a + b, 0))
const errorCount = computed(() => ERROR_LEVELS.reduce((a, lv) => a + (levels.value[lv] ?? 0), 0))
const errorRate = computed(() => total.value ? Math.round((errorCount.value / total.value) * 1000) / 10 : 0)
const hasData = computed(() => total.value > 0 || histogramBuckets.value.length > 0)

const levelRows = computed(() => {
  const tot = total.value || 1
  const max = Math.max(1, ...LEVEL_ORDER.map(n => levels.value[n] ?? 0))
  return LEVEL_ORDER
    .map(name => ({ name, count: levels.value[name] ?? 0 }))
    .filter(r => r.count > 0)
    .map(r => ({
      ...r,
      bar: Math.round((r.count / max) * 1000) / 10, // bar width: relative to the largest level
      pct: Math.round((r.count / tot) * 1000) / 10, // label: share of all entries
    }))
})

async function load() {
  const fileId = filesStore.activeFileId
  if (!fileId) return
  loading.value = true
  try {
    const [lv, hist, grp] = await Promise.all([
      api.getLevels(fileId).catch(() => ({})),
      api.getHistogram(fileId, undefined, undefined, bucket.value === 'auto' ? undefined : bucket.value).catch(() => ({})),
      api.getGroups(fileId, { sort: 'count', limit: 8 }).catch(() => ({})),
    ])
    levels.value = lv.levels ?? {}
    partial.value = !!lv.partial
    histogramBuckets.value = hist.buckets ?? []
    histogramGranularity.value = hist.granularity ?? 3600
    topIssues.value = grp.groups ?? []
  } finally {
    loading.value = false
  }
}

// Guard against out-of-order responses when the granularity is changed quickly.
let _volReq = 0
async function reloadVolume() {
  const fileId = filesStore.activeFileId
  if (!fileId) return
  const reqId = ++_volReq
  const hist = await api.getHistogram(fileId, undefined, undefined, bucket.value === 'auto' ? undefined : bucket.value).catch(() => ({}))
  if (reqId !== _volReq) return
  histogramBuckets.value = hist.buckets ?? []
  histogramGranularity.value = hist.granularity ?? 3600
}

function onGranularity(b) { bucket.value = b; reloadVolume() }

// Selecting a slice / issue / level filters the main view and closes the report.
function onVolumeFilter({ from, to }) {
  entriesStore.applyFilters({ after: from, before: to })
  emit('close')
}
function filterLevels(list) {
  entriesStore.applyFilters({ levels: list })
  emit('close')
}
function drillIssue(g) {
  entriesStore.applyFilters({ group: g.fp, groupLabel: g.title, levels: [] })
  emit('close')
}

// Reload every time it opens — the file's data may have changed (clear, tail,
// reindex) without the file id changing, so a per-file cache would go stale.
watch(() => props.open, (v) => { if (v) load() })

function onKey(e) {
  if (props.open && e.key === 'Escape') { e.preventDefault(); emit('close') }
}
onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<style scoped>
.ll-reports-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  z-index: 90;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 5vh 16px;
  overflow-y: auto;
  animation: ll-fade-in 0.15s ease;
}

.ll-reports {
  width: min(820px, 100%);
  background: var(--ll-surface-raised);
  border: 1px solid var(--ll-border);
  border-radius: 12px;
  box-shadow: 0 16px 48px rgba(0, 0, 0, 0.5);
  display: flex;
  flex-direction: column;
  max-height: 90vh;
}

.ll-reports__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  border-block-end: 1px solid var(--ll-border);
  flex-shrink: 0;
}

.ll-reports__title { font-size: 15px; font-weight: 700; color: var(--ll-text); }
.ll-reports__file { font-weight: 400; color: var(--ll-text-faint); font-size: 13px; }

.ll-reports__close {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 8px;
  border: 1px solid var(--ll-border);
  border-radius: 6px;
  background: none;
  color: var(--ll-text-muted);
  cursor: pointer;
}
.ll-reports__close:hover { background: var(--ll-level-error-bg); color: var(--ll-level-error); border-color: var(--ll-level-error); }
.ll-reports__close-kbd { font-size: 10px; font-family: 'JetBrains Mono', monospace; }

.ll-reports__loading {
  padding: 40px;
  text-align: center;
  color: var(--ll-text-muted);
  font-size: 13px;
}

.ll-reports__body { overflow-y: auto; padding: 16px 18px 22px; }

.ll-reports__partial {
  font-size: 11px;
  color: var(--ll-level-warning);
  background: var(--ll-level-warning-bg);
  padding: 6px 10px;
  border-radius: 6px;
  margin: 0 0 14px;
}

.ll-reports__cards {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 10px;
  margin-block-end: 22px;
}

.ll-report-card {
  background: var(--ll-surface-sunken);
  border: 1px solid var(--ll-border-subtle);
  border-radius: 8px;
  padding: 12px;
  text-align: center;
}
.ll-report-card--error, .ll-report-card--warn { cursor: pointer; }
.ll-report-card--error:hover { border-color: var(--ll-level-error); }
.ll-report-card--warn:hover { border-color: var(--ll-level-warning); }

.ll-report-card__num {
  font-size: 22px;
  font-weight: 700;
  color: var(--ll-text);
  font-family: 'JetBrains Mono', monospace;
}
.ll-report-card__num--sm { font-size: 18px; }
.ll-report-card--error .ll-report-card__num { color: var(--ll-level-error); }
.ll-report-card--warn .ll-report-card__num { color: var(--ll-level-warning); }
.ll-report-card__label {
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--ll-text-faint);
  margin-block-start: 4px;
}

.ll-report-section { margin-block-end: 22px; }
.ll-report-section__title {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--ll-text-faint);
  margin: 0 0 10px;
}

.ll-report-bar-row {
  display: grid;
  grid-template-columns: 90px 1fr 56px 44px;
  align-items: center;
  gap: 8px;
  padding: 3px 4px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 12px;
}
.ll-report-bar-row:hover { background: var(--ll-surface-overlay); }
.ll-report-bar-row__name { display: flex; align-items: center; gap: 6px; text-transform: capitalize; color: var(--ll-text); }
.ll-report-bar-row__track { height: 8px; background: var(--ll-surface-sunken); border-radius: 4px; overflow: hidden; }
.ll-report-bar-row__fill { display: block; height: 100%; border-radius: 4px; }
.ll-report-bar-row__count { text-align: end; font-family: 'JetBrains Mono', monospace; color: var(--ll-text-muted); }
.ll-report-bar-row__pct { text-align: end; font-size: 10.5px; color: var(--ll-text-faint); }

.ll-level-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.ll-level-dot.ll-level-debug { background: var(--ll-level-debug); }
.ll-level-dot.ll-level-info { background: var(--ll-level-info); }
.ll-level-dot.ll-level-notice { background: var(--ll-level-notice); }
.ll-level-dot.ll-level-warning { background: var(--ll-level-warning); }
.ll-level-dot.ll-level-error { background: var(--ll-level-error); }
.ll-level-dot.ll-level-critical { background: var(--ll-level-critical); }
.ll-level-dot.ll-level-alert { background: var(--ll-level-alert); }
.ll-level-dot.ll-level-emergency { background: var(--ll-level-emergency); }

.ll-report-issue {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 4px;
  border-radius: 4px;
  cursor: pointer;
  border-block-end: 1px solid var(--ll-border-subtle);
}
.ll-report-issue:hover { background: var(--ll-surface-overlay); }
.ll-report-issue__title {
  flex: 1;
  font-size: 12px;
  color: var(--ll-text);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ll-report-issue__count {
  font-family: 'JetBrains Mono', monospace;
  font-size: 11px;
  color: var(--ll-text-muted);
  background: var(--ll-surface-overlay);
  padding: 0 6px;
  border-radius: 8px;
}

.ll-spinner {
  display: inline-block;
  width: 14px;
  height: 14px;
  border: 2px solid var(--ll-border);
  border-top-color: var(--ll-accent);
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
  vertical-align: middle;
}
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 560px) {
  .ll-reports__cards { grid-template-columns: repeat(2, 1fr); }
  .ll-report-bar-row { grid-template-columns: 76px 1fr 48px; }
  .ll-report-bar-row__pct { display: none; }
}
</style>
