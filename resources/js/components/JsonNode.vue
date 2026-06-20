<template>
  <span class="ll-jn">
    <template v-if="isObject || isArray">
      <span class="ll-jn__bracket ll-jn__bracket--open" @click="toggle">
        {{ isArray ? '[' : '{' }}
        <span v-if="!expanded" class="ll-jn__ellipsis" @click.stop="toggle">
          {{ isArray ? `${data.length} items` : `${objKeys.length} keys` }}
        </span>
        {{ !expanded ? (isArray ? ']' : '}') : '' }}
      </span>
      <template v-if="expanded">
        <div
          v-for="(key, i) in isArray ? data.map((_,j)=>j) : objKeys"
          :key="key"
          class="ll-jn__entry"
        >
          <span v-if="!isArray" class="ll-jn__key">"{{ key }}"</span>
          <span v-if="!isArray" class="ll-jn__colon">: </span>
          <JsonNode :data="isArray ? data[key] : data[key]" :depth="depth + 1" />
          <span v-if="i < (isArray ? data.length : objKeys.length) - 1" class="ll-jn__comma">,</span>
        </div>
        <span class="ll-jn__bracket">{{ isArray ? ']' : '}' }}</span>
      </template>
    </template>

    <template v-else-if="typeof data === 'string'">
      <span class="ll-jn__string">"{{ data }}"</span>
    </template>
    <template v-else-if="typeof data === 'number'">
      <span class="ll-jn__number">{{ data }}</span>
    </template>
    <template v-else-if="typeof data === 'boolean'">
      <span class="ll-jn__bool">{{ data }}</span>
    </template>
    <template v-else-if="data === null">
      <span class="ll-jn__null">null</span>
    </template>
    <template v-else>
      <span class="ll-jn__unknown">{{ data }}</span>
    </template>
  </span>
</template>

<script setup>
import { ref, computed } from 'vue'

const props = defineProps({
  data:  { type: [Object, Array, String, Number, Boolean], default: null },
  depth: { type: Number, default: 0 },
})

const isObject = computed(() => props.data !== null && typeof props.data === 'object' && !Array.isArray(props.data))
const isArray  = computed(() => Array.isArray(props.data))
const objKeys  = computed(() => isObject.value ? Object.keys(props.data) : [])

// Auto-expand if small
const expanded = ref(
  (isObject.value && objKeys.value.length <= 5 && props.depth < 2) ||
  (isArray.value  && props.data.length   <= 5 && props.depth < 2)
)

function toggle() { expanded.value = !expanded.value }
</script>

<style scoped>
.ll-jn { font-family: 'JetBrains Mono', monospace; font-size: 12px; }
.ll-jn__entry { padding-inline-start: 16px; display: block; }
.ll-jn__key { color: #79c0ff; }
.ll-jn__colon { color: var(--ll-text-faint); }
.ll-jn__string { color: #a5d6ff; }
.ll-jn__number { color: #79c0ff; }
.ll-jn__bool { color: #ff7b72; }
.ll-jn__null { color: var(--ll-text-faint); font-style: italic; }
.ll-jn__bracket { cursor: pointer; color: var(--ll-text-muted); }
.ll-jn__ellipsis { font-size: 10px; color: var(--ll-text-faint); margin-inline: 4px; }
.ll-jn__comma { color: var(--ll-text-faint); }
[data-theme="light"] .ll-jn__key { color: #0550ae; }
[data-theme="light"] .ll-jn__string { color: #0a3069; }
[data-theme="light"] .ll-jn__number { color: #0550ae; }
[data-theme="light"] .ll-jn__bool { color: #cf222e; }
</style>
