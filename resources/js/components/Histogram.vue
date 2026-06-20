<template>
  <div class="ll-histogram" :style="{ height: height + 'px' }">
    <!-- Legend: which levels are present -->
    <div class="ll-histogram__legend">
      <span class="ll-histogram__legend-title">{{ t('histogram.title') }}</span>
      <span
        v-for="lv in presentLevels"
        :key="lv"
        class="ll-histogram__legend-item"
      >
        <span class="ll-histogram__swatch" :style="{ background: colorFor(lv) }" />
        {{ lv }}
      </span>
      <!-- Granularity selector -->
      <select
        class="ll-histogram__gran"
        :value="bucket"
        :title="t('histogram.granularity')"
        @change="$emit('granularity', $event.target.value)"
        @mousedown.stop
      >
        <option value="auto">{{ t('histogram.auto') }}</option>
        <option value="hour">{{ t('histogram.hourly') }}</option>
        <option value="day">{{ t('histogram.daily') }}</option>
        <option value="week">{{ t('histogram.weekly') }}</option>
        <option value="month">{{ t('histogram.monthly') }}</option>
      </select>
      <span class="ll-histogram__hint">{{ t('histogram.hint') }}</span>
    </div>

    <!-- Chart -->
    <div
      v-if="buckets.length"
      ref="containerRef"
      class="ll-histogram__chart"
      @mousemove="onMove"
      @mouseleave="onLeave"
      @mousedown="onDown"
      @mouseup="onUp"
    >
      <div class="ll-histogram__plot">
      <svg :width="width" :height="chartH" class="ll-histogram__svg">
        <!-- Active-range / live-selection highlight -->
        <rect
          v-if="highlight"
          :x="highlight.x"
          y="0"
          :width="highlight.w"
          :height="chartH"
          class="ll-histogram__sel"
        />
        <!-- Stacked bars -->
        <g v-for="(bar, i) in bars" :key="i">
          <rect
            v-for="seg in bar.segments"
            :key="seg.level"
            :x="bar.x"
            :y="seg.y"
            :width="bar.w"
            :height="seg.h"
            :fill="colorFor(seg.level)"
            :opacity="hoverIndex === null || hoverIndex === i ? 1 : 0.5"
          />
        </g>
        <!-- Hover guide -->
        <rect
          v-if="hoverIndex !== null && bars[hoverIndex]"
          :x="bars[hoverIndex].x"
          y="0"
          :width="bars[hoverIndex].w"
          :height="chartH"
          class="ll-histogram__hoverguide"
        />
      </svg>
      </div>

      <!-- Tooltip -->
      <div
        v-if="hoverIndex !== null && bars[hoverIndex]"
        class="ll-histogram__tooltip"
        :style="tooltipStyle"
      >
        <div class="ll-histogram__tt-time">{{ tooltipTime }}</div>
        <div
          v-for="row in tooltipRows"
          :key="row.level"
          class="ll-histogram__tt-row"
        >
          <span class="ll-histogram__swatch" :style="{ background: colorFor(row.level) }" />
          <span class="ll-histogram__tt-lv">{{ row.level }}</span>
          <span class="ll-histogram__tt-n">{{ row.count.toLocaleString() }}</span>
        </div>
        <div class="ll-histogram__tt-total">
          {{ t('histogram.total') }}: {{ bars[hoverIndex].total.toLocaleString() }}
        </div>
      </div>
    </div>

    <!-- Axis: earliest → latest -->
    <div v-if="buckets.length" class="ll-histogram__axis">
      <span>{{ axisStart }}</span>
      <span>{{ axisEnd }}</span>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { t } from '../i18n.js'

const props = defineProps({
  buckets:     { type: Array,  default: () => [] },
  height:      { type: Number, default: 84 },
  granularity: { type: Number, default: 3600 }, // seconds per bucket
  bucket:      { type: String, default: 'auto' }, // auto|hour|day|week|month
  from:        { type: [String, Number], default: null },
  to:          { type: [String, Number], default: null },
})

const emit = defineEmits(['filter', 'granularity'])

const LEVEL_COLORS = {
  emergency: '#c763e6',
  alert:     '#fb923c',
  critical:  '#e5384e',
  error:     '#fb6a82',
  warning:   '#f5b13d',
  notice:    '#34d399',
  info:      '#5b9bff',
  debug:     '#7c8696',
  unknown:   '#6b7280',
}
// Bottom → top stacking: least severe at the base, severe colors on top.
// 'unknown' (backend ordinal 8) is included so its counts are drawn and totaled
// consistently instead of silently inflating maxTotal / the tooltip total.
const STACK_ORDER = ['unknown', 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']

function colorFor(lv) { return LEVEL_COLORS[lv] ?? '#888' }

const containerRef = ref(null)
const width = ref(600)
const measuredH = ref(50)
const hoverIndex = ref(null)
const mouseX = ref(0)

// Drag-to-select state
let dragStart = null
const dragEnd = ref(null)

// Track the chart's real allocated height (legend + axis are flex-sized rows,
// so guessing their combined height with a magic constant let the SVG overflow
// and cover the date axis). The chart div is `flex: 1`, so its measured height
// is exactly the space left after the legend and axis — the SVG fills that.
const chartH = computed(() => Math.max(20, measuredH.value))

const presentLevels = computed(() => {
  const seen = new Set()
  for (const b of props.buckets) {
    for (const lv of Object.keys(b.levels ?? {})) {
      if ((b.levels[lv] ?? 0) > 0) seen.add(lv)
    }
  }
  return STACK_ORDER.filter(l => seen.has(l))
})

const maxTotal = computed(() => {
  let m = 0
  for (const b of props.buckets) m = Math.max(m, b.total ?? 0)
  return m || 1
})

const bars = computed(() => {
  const n = props.buckets.length
  if (!n) return []
  const w = width.value
  const step = w / n
  const barW = Math.max(1, step - (step > 4 ? 1 : 0))
  const out = []
  const H = chartH.value
  for (let i = 0; i < n; i++) {
    const b = props.buckets[i]
    const x = i * step
    const total = b.total ?? 0

    // Non-zero levels present in this bucket.
    const nz = STACK_ORDER
      .map(lv => ({ lv, count: b.levels?.[lv] ?? 0 }))
      .filter(s => s.count > 0)

    // Give every non-zero level a minimum visible height so a handful of errors
    // don't vanish under thousands of infos, while the tallest bar still fits:
    // reserve minH per level, scale the rest proportionally to the global max.
    const minH = nz.length > 1 ? 2 : 0
    const avail = Math.max(1, H - nz.length * minH)

    let y = H
    const segments = []
    for (const s of nz) {
      const h = minH + (s.count / maxTotal.value) * avail
      y -= h
      segments.push({ level: s.lv, y, h, count: s.count })
    }
    out.push({ x, w: barW, step, total, t: b.t, segments })
  }
  return out
})

// ── time helpers ────────────────────────────────────────────────
function toSec(v) {
  if (v == null) return null
  if (typeof v === 'number') return v
  const d = new Date(v)
  return isNaN(d.getTime()) ? null : Math.floor(d.getTime() / 1000)
}
function fmt(sec) {
  return new Date(sec * 1000).toLocaleString([], {
    month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit',
  })
}

const axisStart = computed(() => props.buckets.length ? fmt(props.buckets[0].t) : '')
const axisEnd   = computed(() => props.buckets.length ? fmt(props.buckets[props.buckets.length - 1].t + props.granularity) : '')

// ── hover tooltip ───────────────────────────────────────────────
const tooltipTime = computed(() => {
  const bar = bars.value[hoverIndex.value]
  if (!bar) return ''
  return `${fmt(bar.t)} – ${fmt(bar.t + props.granularity)}`
})
const tooltipRows = computed(() => {
  const bar = bars.value[hoverIndex.value]
  if (!bar) return []
  // severe first in the tooltip for quick scanning
  return [...bar.segments].reverse().map(s => ({ level: s.level, count: s.count }))
})
const tooltipStyle = computed(() => {
  const x = Math.min(Math.max(mouseX.value, 90), width.value - 90)
  return { left: x + 'px' }
})

// ── highlight (active filter range OR live drag) ────────────────
const highlight = computed(() => {
  const n = props.buckets.length
  if (!n) return null
  const step = width.value / n

  // Live drag selection takes precedence.
  if (dragStart !== null && dragEnd.value !== null) {
    const a = Math.min(dragStart, dragEnd.value)
    const b = Math.max(dragStart, dragEnd.value)
    return { x: a * step, w: (b - a + 1) * step }
  }

  // Otherwise reflect the active time filter, if it overlaps this range.
  const fromS = toSec(props.from)
  const toS   = toSec(props.to)
  if (fromS == null && toS == null) return null
  let startIdx = 0
  let endIdx = n - 1
  if (fromS != null) {
    startIdx = props.buckets.findIndex(b => b.t + props.granularity > fromS)
    if (startIdx < 0) return null // range starts after the last bucket
  }
  if (toS != null) {
    // Last bucket that begins before `to`; if none, the range predates all
    // buckets — show no highlight rather than the whole chart.
    endIdx = -1
    for (let i = n - 1; i >= 0; i--) {
      if (props.buckets[i].t < toS) { endIdx = i; break }
    }
    if (endIdx < 0) return null
  }
  if (endIdx < startIdx) return null
  return { x: startIdx * step, w: (endIdx - startIdx + 1) * step }
})

// ── pointer handlers ────────────────────────────────────────────
function indexAt(clientX) {
  const rect = containerRef.value?.getBoundingClientRect()
  if (!rect) return 0
  const x = clientX - rect.left
  const n = props.buckets.length
  return Math.min(n - 1, Math.max(0, Math.floor((x / rect.width) * n)))
}

function onMove(e) {
  const rect = containerRef.value.getBoundingClientRect()
  mouseX.value = e.clientX - rect.left
  hoverIndex.value = indexAt(e.clientX)
  if (dragStart !== null) dragEnd.value = hoverIndex.value
}

function onLeave() {
  if (dragStart === null) hoverIndex.value = null
}

function onDown(e) {
  dragStart = indexAt(e.clientX)
  dragEnd.value = dragStart
}

function onUp(e) {
  finishDrag(indexAt(e.clientX))
}

// Finish a drag using the supplied end index (or the last hovered one). Shared
// by the in-chart mouseup and the window-level safety net below.
function finishDrag(endIdx) {
  if (dragStart === null) return
  const start = dragStart
  dragStart = null
  const finalEnd = endIdx == null ? dragEnd.value : endIdx
  dragEnd.value = null

  const a = Math.min(start, finalEnd)
  const b = Math.max(start, finalEnd)
  const startBucket = props.buckets[a]
  const endBucket = props.buckets[b]
  if (!startBucket || !endBucket) return
  emit('filter', {
    from: new Date(startBucket.t * 1000).toISOString(),
    to:   new Date((endBucket.t + props.granularity) * 1000).toISOString(),
  })
}

// If the mouse is released outside the chart, still finalize the selection
// instead of getting stuck mid-drag.
function onWindowUp() {
  if (dragStart !== null) finishDrag(null)
}

// ── responsive sizing ───────────────────────────────────────────
let ro = null

// Measure the chart area and (re)attach the ResizeObserver. The chart div is
// v-if'd on buckets — which arrive asynchronously after the file opens — so the
// onMounted pass alone could run with a stale/default size and never observe,
// leaving the SVG taller than its box (it then spilled over the date axis). We
// also re-measure whenever the buckets change (e.g. granularity switch).
function measure() {
  const el = containerRef.value
  if (!el) return
  width.value = el.clientWidth || width.value
  measuredH.value = el.clientHeight || measuredH.value
  if (ro) ro.disconnect()
  ro = new ResizeObserver(([entry]) => {
    width.value = entry.contentRect.width || width.value
    measuredH.value = entry.contentRect.height || measuredH.value
  })
  ro.observe(el)
}

onMounted(() => {
  window.addEventListener('mouseup', onWindowUp)
  measure()
})

watch(() => props.buckets, async () => {
  await nextTick()
  measure()
}, { flush: 'post' })

onUnmounted(() => {
  window.removeEventListener('mouseup', onWindowUp)
  if (ro) ro.disconnect()
})
</script>

<style scoped>
.ll-histogram {
  position: relative;
  width: 100%;
  background: var(--ll-surface-raised);
  border-block-end: 1px solid var(--ll-border);
  overflow: hidden;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
}

.ll-histogram__legend {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 3px 10px 1px;
  font-size: 10px;
  color: var(--ll-text-muted);
  flex-shrink: 0;
  overflow: hidden;
  white-space: nowrap;
}

.ll-histogram__legend-title {
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--ll-text-faint);
}

.ll-histogram__legend-item {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  text-transform: capitalize;
}

.ll-histogram__gran {
  margin-inline-start: auto;
  background: var(--ll-surface-sunken);
  border: 1px solid var(--ll-border-subtle);
  border-radius: 4px;
  color: var(--ll-text-muted);
  font-size: 10px;
  padding: 1px 4px;
  cursor: pointer;
  color-scheme: dark;
}

.ll-histogram__hint {
  color: var(--ll-text-faint);
  font-style: italic;
}

.ll-histogram__swatch {
  width: 8px;
  height: 8px;
  border-radius: 2px;
  display: inline-block;
  flex-shrink: 0;
}

.ll-histogram__chart {
  position: relative;
  flex: 1;
  min-height: 0;
  cursor: crosshair;
  user-select: none;
}

/* Clip ONLY the SVG (not the chart box) so a pixel-off SVG height can never spill
   over the date axis — while the hover tooltip, which is taller than the chart
   and lives outside this wrapper, is free to overflow. */
.ll-histogram__plot {
  position: absolute;
  inset: 0;
  overflow: hidden;
}

.ll-histogram__svg { display: block; }

.ll-histogram__sel {
  fill: var(--ll-accent);
  opacity: 0.16;
}

.ll-histogram__hoverguide {
  fill: var(--ll-text);
  opacity: 0.08;
}

.ll-histogram__tooltip {
  position: absolute;
  top: 2px;
  transform: translateX(-50%);
  background: var(--ll-surface-overlay);
  border: 1px solid var(--ll-border);
  border-radius: 6px;
  padding: 6px 8px;
  font-size: 11px;
  color: var(--ll-text);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4);
  pointer-events: none;
  z-index: 20;
  min-width: 150px;
}

.ll-histogram__tt-time {
  font-size: 10px;
  color: var(--ll-text-faint);
  margin-block-end: 4px;
  white-space: nowrap;
}

.ll-histogram__tt-row {
  display: flex;
  align-items: center;
  gap: 6px;
  line-height: 1.5;
}

.ll-histogram__tt-lv {
  flex: 1;
  text-transform: capitalize;
}

.ll-histogram__tt-n {
  font-family: 'JetBrains Mono', monospace;
  color: var(--ll-text-muted);
}

.ll-histogram__tt-total {
  margin-block-start: 4px;
  padding-block-start: 4px;
  border-block-start: 1px solid var(--ll-border-subtle);
  font-size: 10px;
  color: var(--ll-text-muted);
}

.ll-histogram__axis {
  display: flex;
  justify-content: space-between;
  padding: 1px 10px 3px;
  font-size: 9.5px;
  color: var(--ll-text-faint);
  font-family: 'JetBrains Mono', monospace;
  flex-shrink: 0;
}
</style>
