<template>
  <Teleport to="body">
    <div v-if="open" class="ll-overlay-backdrop" @click="$emit('close')" />
    <div v-if="open" class="ll-diag-panel">
      <div class="ll-diag-panel__header">
        <span>{{ t('diagnostics.title') }}</span>
        <button class="ll-icon-btn" @click="$emit('close')">✕</button>
      </div>
      <div v-if="loading" class="ll-diag-panel__loading">Loading…</div>
      <div v-else-if="diag" class="ll-diag-panel__body">
        <pre class="ll-raw-entry" dir="ltr">{{ JSON.stringify(diag, null, 2) }}</pre>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, watch } from 'vue'
import api from '../api.js'
import { t } from '../i18n.js'

const props = defineProps({ open: Boolean })
defineEmits(['close'])

const diag    = ref(null)
const loading = ref(false)

watch(() => props.open, async (v) => {
  if (v && !diag.value) {
    loading.value = true
    try { diag.value = await api.getDiagnostics() }
    catch {}
    finally { loading.value = false }
  }
})
</script>

<style scoped>
.ll-diag-panel {
  position: fixed;
  inset-block-end: 1rem;
  inset-inline-end: 1rem;
  width: min(500px, calc(100vw - 2rem));
  max-height: 60vh;
  background: var(--ll-surface-overlay);
  border: 1px solid var(--ll-border);
  border-radius: 8px;
  box-shadow: 0 8px 32px rgba(0,0,0,0.5);
  z-index: 60;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.ll-diag-panel__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 12px;
  border-block-end: 1px solid var(--ll-border);
  font-size: 12px;
  font-weight: 600;
  color: var(--ll-text);
}

.ll-diag-panel__body {
  overflow-y: auto;
  padding: 8px;
}

.ll-diag-panel__loading {
  padding: 16px;
  font-size: 12px;
  color: var(--ll-text-muted);
}

.ll-icon-btn {
  background: none;
  border: none;
  color: var(--ll-text-muted);
  cursor: pointer;
  font-size: 14px;
  padding: 2px 4px;
}
</style>
