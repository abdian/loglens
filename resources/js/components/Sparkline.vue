<template>
  <div ref="containerRef" class="ll-sparkline" :style="{ width: width + 'px', height: height + 'px' }" />
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue'
import uPlot from 'uplot'

const props = defineProps({
  series: { type: Array, default: () => [] },
  width:  { type: Number, default: 80 },
  height: { type: Number, default: 24 },
  color:  { type: String, default: '#388bfd' },
})

const containerRef = ref(null)
let chart = null

function buildChart() {
  if (!containerRef.value || !props.series.length) return
  if (chart) { chart.destroy(); chart = null }

  const data = props.series
  const xs = data.map((_, i) => i)

  const opts = {
    width:  props.width,
    height: props.height,
    pxAlign: false,
    cursor: { show: false },
    select: { show: false },
    legend: { show: false },
    scales: { x: { time: false }, y: { range: [0, Math.max(...data, 1)] } },
    axes: [],
    series: [
      {},
      {
        stroke: props.color,
        fill: props.color + '33',
        width: 1,
        points: { show: false },
      },
    ],
  }

  chart = new uPlot(opts, [xs, data], containerRef.value)
}

onMounted(buildChart)
onUnmounted(() => { if (chart) { chart.destroy(); chart = null } })
watch(() => [props.series, props.width, props.height, props.color], buildChart, { deep: true })
</script>

<style scoped>
.ll-sparkline { display: inline-block; overflow: hidden; }
.ll-sparkline :deep(.u-wrap) { margin: 0 !important; }
</style>
