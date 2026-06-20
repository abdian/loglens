<template>
  <Teleport to="body">
    <div
      v-if="state"
      class="ll-confirm-overlay"
      @click.self="cancel"
      @keydown.esc="cancel"
    >
      <div class="ll-confirm" ref="dialogRef" tabindex="-1" role="alertdialog" aria-modal="true">
        <div class="ll-confirm__title">{{ state.title }}</div>
        <div class="ll-confirm__message">{{ state.message }}</div>
        <div class="ll-confirm__actions">
          <button class="ll-confirm__btn" @click="cancel">
            {{ state.cancelLabel || t('confirm.cancel') }}
          </button>
          <button
            :class="['ll-confirm__btn', state.danger ? 'll-confirm__btn--danger' : 'll-confirm__btn--primary']"
            @click="ok"
          >
            {{ state.confirmLabel || t('confirm.ok') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, watch, nextTick, ref, onMounted, onUnmounted } from 'vue'
import { useUiStore } from '../stores/ui.js'
import { useFocusTrap } from '../composables/focusTrap.js'
import { t } from '../i18n.js'

const uiStore = useUiStore()
const state = computed(() => uiStore.confirmState)
const dialogRef = ref(null)
const { activate, release } = useFocusTrap()

function ok()     { uiStore.resolveConfirm(true) }
function cancel() { uiStore.resolveConfirm(false) }

// Escape cancels, Enter confirms. The app's global shortcuts are suppressed
// while confirmState is set (see composables/keyboard.js), so there's no
// double-fire; focus sits on the dialog container (not a button), so Enter
// won't also natively activate a focused button.
function onKey(e) {
  if (!state.value) return
  if (e.key === 'Escape') { e.preventDefault(); cancel() }
  else if (e.key === 'Enter') { e.preventDefault(); ok() }
}

onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))

// Trap focus inside the dialog while open and restore it to the trigger on close.
watch(state, (s) => {
  if (s) nextTick(() => activate(dialogRef.value))
  else release()
})
</script>

<style scoped>
.ll-confirm-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 200;
  backdrop-filter: blur(1px);
}

.ll-confirm {
  width: min(420px, calc(100vw - 32px));
  background: var(--ll-surface-raised);
  border: 1px solid var(--ll-border);
  border-radius: 10px;
  padding: 18px;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);
}
.ll-confirm:focus { outline: none; }

.ll-confirm__title {
  font-size: 14px;
  font-weight: 700;
  color: var(--ll-text);
  margin-block-end: 8px;
}

.ll-confirm__message {
  font-size: 13px;
  line-height: 1.5;
  color: var(--ll-text-muted);
  margin-block-end: 18px;
  word-break: break-word;
}

.ll-confirm__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.ll-confirm__btn {
  padding: 6px 14px;
  font-size: 12.5px;
  border-radius: 6px;
  border: 1px solid var(--ll-border);
  background: var(--ll-surface-overlay);
  color: var(--ll-text);
  cursor: pointer;
  transition: background 0.1s, border-color 0.1s;
}

.ll-confirm__btn:hover { background: var(--ll-surface-sunken); }

.ll-confirm__btn--primary {
  background: var(--ll-accent);
  border-color: var(--ll-accent);
  color: #fff;
}
.ll-confirm__btn--primary:hover { filter: brightness(1.08); background: var(--ll-accent); }

.ll-confirm__btn--danger {
  background: var(--ll-level-error);
  border-color: var(--ll-level-error);
  color: #fff;
}
.ll-confirm__btn--danger:hover { filter: brightness(1.08); background: var(--ll-level-error); }
</style>
