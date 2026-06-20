<template>
  <Teleport to="body">
    <div v-if="open" class="ll-help-overlay" @click.self="close">
      <div
        ref="panelRef"
        class="ll-help"
        role="dialog"
        aria-modal="true"
        :aria-label="t('help.title')"
        tabindex="-1"
        @keydown.esc="close"
      >
        <div class="ll-help__header">
          <div class="ll-help__title">{{ t('help.title') }}</div>
          <button class="ll-help__close" :aria-label="t('help.close')" @click="close">✕</button>
        </div>
        <ul class="ll-help__list">
          <li v-for="row in rows" :key="row.label" class="ll-help__row">
            <span class="ll-help__desc">{{ row.label }}</span>
            <span class="ll-help__keys">
              <kbd v-for="k in row.keys" :key="k">{{ k }}</kbd>
            </span>
          </li>
        </ul>
        <div class="ll-help__hint">{{ t('help.hint') }}</div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, ref, watch, nextTick } from 'vue'
import { useUiStore } from '../stores/ui.js'
import { useFocusTrap } from '../composables/focusTrap.js'
import { t } from '../i18n.js'

const uiStore = useUiStore()
const open = computed(() => uiStore.helpOpen)
const panelRef = ref(null)
const { activate, release } = useFocusTrap()

function close() { uiStore.closeHelp() }

const rows = computed(() => [
  { label: t('help.nav'),       keys: ['j', 'k'] },
  { label: t('help.topBottom'), keys: ['g g', 'G'] },
  { label: t('help.nextError'), keys: ['e', 'E'] },
  { label: t('help.nextWarn'),  keys: ['w', 'W'] },
  { label: t('help.search'),    keys: ['/'] },
  { label: t('help.open'),      keys: ['Enter'] },
  { label: t('help.close'),     keys: ['Esc'] },
  { label: t('help.palette'),   keys: ['⌘ K'] },
  { label: t('help.help'),      keys: ['?'] },
])

watch(open, (v) => {
  if (v) nextTick(() => activate(panelRef.value))
  else release()
})
</script>

<style scoped>
.ll-help-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 210;
  backdrop-filter: blur(1px);
}

.ll-help {
  width: min(460px, calc(100vw - 32px));
  max-height: calc(100vh - 64px);
  overflow-y: auto;
  background: var(--ll-surface-raised);
  border: 1px solid var(--ll-border);
  border-radius: 10px;
  padding: 16px 18px;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);
}
.ll-help:focus { outline: none; }

.ll-help__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-block-end: 12px;
}

.ll-help__title {
  font-size: 14px;
  font-weight: 700;
  color: var(--ll-text);
}

.ll-help__close {
  border: none;
  background: transparent;
  color: var(--ll-text-muted);
  cursor: pointer;
  font-size: 13px;
  line-height: 1;
  padding: 4px;
  border-radius: 4px;
}
.ll-help__close:hover { background: var(--ll-surface-sunken); color: var(--ll-text); }

.ll-help__list { display: flex; flex-direction: column; gap: 2px; }

.ll-help__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 6px 4px;
  border-block-end: 1px solid var(--ll-border-subtle);
}

.ll-help__desc { font-size: 12.5px; color: var(--ll-text-muted); }

.ll-help__keys { display: flex; gap: 4px; flex-shrink: 0; }

.ll-help__keys kbd {
  font-family: 'JetBrains Mono', ui-monospace, monospace;
  font-size: 11px;
  color: var(--ll-text);
  background: var(--ll-surface-sunken);
  border: 1px solid var(--ll-border);
  border-radius: 4px;
  padding: 1px 6px;
  min-width: 18px;
  text-align: center;
}

.ll-help__hint {
  margin-block-start: 12px;
  font-size: 11.5px;
  color: var(--ll-text-faint);
  text-align: center;
}
</style>
