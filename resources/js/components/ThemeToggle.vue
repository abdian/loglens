<template>
  <div class="ll-theme-toggle">
    <button
      v-for="opt in options"
      :key="opt.value"
      :class="['ll-theme-btn', { 'll-theme-btn--active': theme === opt.value }]"
      @click="uiStore.setTheme(opt.value)"
      :title="opt.label"
    >
      <span v-html="opt.icon" />
    </button>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useUiStore } from '../stores/ui.js'
import { t } from '../i18n.js'

const uiStore = useUiStore()
const theme = computed(() => uiStore.theme)

const options = [
  {
    value: 'dark',
    label: t('theme.dark'),
    icon: '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M9.598 1.591a.749.749 0 0 1 .785-.175 7.001 7.001 0 1 1-8.967 8.967.75.75 0 0 1 .961-.96 5.5 5.5 0 0 0 7.046-7.046.75.75 0 0 1 .175-.786Zm1.616 1.945a7 7 0 0 1-7.678 7.678 5.499 5.499 0 1 0 7.678-7.678Z"/></svg>',
  },
  {
    value: 'light',
    label: t('theme.light'),
    icon: '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M8 12a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm0-1.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Zm5.657-8.157a.75.75 0 0 1 0 1.061l-1.061 1.06a.749.749 0 0 1-1.275-.326.749.749 0 0 1 .215-.734l1.061-1.061a.75.75 0 0 1 1.06 0Zm-9.193 9.193a.75.75 0 0 1 0 1.06l-1.06 1.061a.75.75 0 1 1-1.061-1.06l1.06-1.061a.75.75 0 0 1 1.061 0ZM8 0a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0V.75A.75.75 0 0 1 8 0ZM3 8a.75.75 0 0 1-.75.75H.75a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 3 8Zm13 0a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 16 8Zm-8 5a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 13Zm3.536-1.464a.75.75 0 0 1 1.06 0l1.061 1.06a.75.75 0 0 1-1.06 1.061l-1.061-1.06a.75.75 0 0 1 0-1.061ZM2.343 2.343a.75.75 0 0 1 1.061 0l1.06 1.061a.75.75 0 0 1-1.06 1.06l-1.06-1.06a.75.75 0 0 1 0-1.061Z"/></svg>',
  },
  {
    value: 'system',
    label: t('theme.system'),
    icon: '<svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M1.75 2h12.5c.966 0 1.75.784 1.75 1.75v7.5A1.75 1.75 0 0 1 14.25 13H8.5v1.5l1.75.001a.75.75 0 0 1 0 1.5h-4.5a.75.75 0 0 1 0-1.5L7.5 14.5V13H1.75A1.75 1.75 0 0 1 0 11.25v-7.5C0 2.784.784 2 1.75 2ZM1.5 11.25c0 .138.112.25.25.25h12.5a.25.25 0 0 0 .25-.25v-7.5a.25.25 0 0 0-.25-.25H1.75a.25.25 0 0 0-.25.25Z"/></svg>',
  },
]
</script>

<style scoped>
.ll-theme-toggle {
  display: flex;
  gap: 2px;
  background: var(--ll-surface-sunken);
  border: 1px solid var(--ll-border-subtle);
  border-radius: 6px;
  padding: 2px;
}

.ll-theme-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 22px;
  border-radius: 4px;
  border: none;
  background: none;
  color: var(--ll-text-faint);
  cursor: pointer;
  transition: background 0.1s, color 0.1s;
}

.ll-theme-btn:hover { color: var(--ll-text); }

.ll-theme-btn--active {
  background: var(--ll-surface-raised);
  color: var(--ll-text);
  box-shadow: 0 1px 3px rgba(0,0,0,0.3);
}
</style>
