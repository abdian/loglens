<template>
  <div class="ll-json-tree ll-log-content" dir="ltr">
    <JsonNode :data="data" :depth="0" />
  </div>
</template>

<script setup>
import JsonNode from './JsonNode.vue'
defineProps({ data: { type: [Object, Array, String, Number, Boolean], default: null } })
</script>

<style scoped>
.ll-json-tree {
  font-size: 12px;
  background: var(--ll-surface-sunken);
  border-radius: 4px;
  padding: 6px 8px;
  overflow-x: auto;
}
</style>
