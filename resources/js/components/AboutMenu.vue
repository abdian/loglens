<template>
  <div class="ll-about">
    <button class="ll-topbar__btn" @click="open = !open" @blur="onBlur" :title="t('about.title')">
      <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
        <path d="M8 0a8 8 0 1 1 0 16A8 8 0 0 1 8 0ZM1.5 8a6.5 6.5 0 1 0 13 0 6.5 6.5 0 0 0-13 0ZM9 4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM7.25 6.5a.75.75 0 0 0 0 1.5h.25v3h-.5a.75.75 0 0 0 0 1.5h2.5a.75.75 0 0 0 0-1.5H9V7.25a.75.75 0 0 0-.75-.75Z"/>
      </svg>
    </button>

    <div v-if="open" class="ll-about__menu" @mousedown.prevent>
      <div class="ll-about__head">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--ll-accent)">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
        </svg>
        <span class="ll-about__name">LogLens</span>
        <a class="ll-about__version" :href="LINKS.changelog" target="_blank" rel="noopener noreferrer" :title="t('about.changelog')">v{{ version }}</a>
      </div>

      <a class="ll-about__item" :href="LINKS.repo" target="_blank" rel="noopener noreferrer">
        <svg class="ll-about__icon" width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a8 8 0 0 0-2.53 15.59c.4.07.55-.17.55-.38v-1.34c-2.23.49-2.7-1.07-2.7-1.07-.36-.92-.89-1.17-.89-1.17-.73-.5.06-.49.06-.49.8.06 1.23.83 1.23.83.71 1.23 1.87.87 2.33.66.07-.52.28-.87.5-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.83-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82a7.6 7.6 0 0 1 4 0c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.52.56.83 1.28.83 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48v2.2c0 .21.15.46.55.38A8 8 0 0 0 8 0Z"/></svg>
        <span class="ll-about__label">{{ t('about.github') }}</span>
        <svg class="ll-about__ext" width="11" height="11" viewBox="0 0 16 16" fill="currentColor"><path d="M3.75 2h3.5a.75.75 0 0 1 0 1.5h-3.5a.25.25 0 0 0-.25.25v8.5c0 .138.112.25.25.25h8.5a.25.25 0 0 0 .25-.25v-3.5a.75.75 0 0 1 1.5 0v3.5A1.75 1.75 0 0 1 12.25 14h-8.5A1.75 1.75 0 0 1 2 12.25v-8.5C2 2.784 2.784 2 3.75 2Zm6.69 0L9 2a.75.75 0 0 1 0-1.5h4.25a.75.75 0 0 1 .75.75V5.5a.75.75 0 0 1-1.5 0V3.56L7.78 8.28a.75.75 0 0 1-1.06-1.06Z"/></svg>
      </a>

      <a class="ll-about__item" :href="LINKS.docs" target="_blank" rel="noopener noreferrer">
        <svg class="ll-about__icon" width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M0 1.75A.75.75 0 0 1 .75 1h4.253c1.227 0 2.317.59 3 1.501A3.743 3.743 0 0 1 11.006 1h4.245a.75.75 0 0 1 .75.75v10.5a.75.75 0 0 1-.75.75h-4.507a2.25 2.25 0 0 0-1.591.659l-.622.621a.75.75 0 0 1-1.06 0l-.622-.621A2.25 2.25 0 0 0 5.258 13H.75a.75.75 0 0 1-.75-.75Zm7.251 10.324.004-5.073-.002-2.253A2.25 2.25 0 0 0 5.003 2.5H1.5v9h3.757a3.75 3.75 0 0 1 1.994.574ZM8.755 4.75l-.004 7.322a3.752 3.752 0 0 1 1.992-.572H14.5v-9h-3.495a2.25 2.25 0 0 0-2.25 2.25Z"/></svg>
        <span class="ll-about__label">{{ t('about.docs') }}</span>
        <svg class="ll-about__ext" width="11" height="11" viewBox="0 0 16 16" fill="currentColor"><path d="M3.75 2h3.5a.75.75 0 0 1 0 1.5h-3.5a.25.25 0 0 0-.25.25v8.5c0 .138.112.25.25.25h8.5a.25.25 0 0 0 .25-.25v-3.5a.75.75 0 0 1 1.5 0v3.5A1.75 1.75 0 0 1 12.25 14h-8.5A1.75 1.75 0 0 1 2 12.25v-8.5C2 2.784 2.784 2 3.75 2Zm6.69 0L9 2a.75.75 0 0 1 0-1.5h4.25a.75.75 0 0 1 .75.75V5.5a.75.75 0 0 1-1.5 0V3.56L7.78 8.28a.75.75 0 0 1-1.06-1.06Z"/></svg>
      </a>

      <a class="ll-about__item" :href="LINKS.issues" target="_blank" rel="noopener noreferrer">
        <svg class="ll-about__icon" width="15" height="15" viewBox="0 0 16 16" fill="currentColor"><path d="M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13ZM0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8Zm9 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM7.25 4.75a.75.75 0 0 1 1.5 0v3.5a.75.75 0 0 1-1.5 0Z"/></svg>
        <span class="ll-about__label">{{ t('about.report') }}</span>
        <svg class="ll-about__ext" width="11" height="11" viewBox="0 0 16 16" fill="currentColor"><path d="M3.75 2h3.5a.75.75 0 0 1 0 1.5h-3.5a.25.25 0 0 0-.25.25v8.5c0 .138.112.25.25.25h8.5a.25.25 0 0 0 .25-.25v-3.5a.75.75 0 0 1 1.5 0v3.5A1.75 1.75 0 0 1 12.25 14h-8.5A1.75 1.75 0 0 1 2 12.25v-8.5C2 2.784 2.784 2 3.75 2Zm6.69 0L9 2a.75.75 0 0 1 0-1.5h4.25a.75.75 0 0 1 .75.75V5.5a.75.75 0 0 1-1.5 0V3.56L7.78 8.28a.75.75 0 0 1-1.06-1.06Z"/></svg>
      </a>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { getBoot } from '../api.js'
import { t } from '../i18n.js'

// Project links (the package's own home). Adjust here if the repo moves.
const REPO = 'https://github.com/abdian/loglens'
const LINKS = {
  repo: REPO,
  docs: `${REPO}/tree/main/docs`,
  issues: `${REPO}/issues/new`,
  changelog: `${REPO}/blob/main/CHANGELOG.md`,
}

const version = getBoot()?.version ?? ''
const open = ref(false)
function onBlur() { setTimeout(() => { open.value = false }, 150) }
</script>

<style scoped>
.ll-about { position: relative; }

.ll-about__menu {
  position: absolute;
  inset-block-start: calc(100% + 6px);
  inset-inline-end: 0;
  min-width: 220px;
  background: var(--ll-surface-overlay);
  border: 1px solid var(--ll-border);
  border-radius: 8px;
  box-shadow: 0 8px 28px rgba(0, 0, 0, 0.45);
  z-index: 80;
  padding: 6px;
}

.ll-about__head {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 8px 8px;
  border-block-end: 1px solid var(--ll-border-subtle);
  margin-block-end: 4px;
}
.ll-about__name { font-weight: 700; font-size: 13px; color: var(--ll-text); }
.ll-about__version {
  margin-inline-start: auto;
  font-size: 11px;
  font-family: 'JetBrains Mono', monospace;
  color: var(--ll-text-muted);
  background: var(--ll-surface-sunken);
  border: 1px solid var(--ll-border-subtle);
  padding: 1px 6px;
  border-radius: 10px;
  text-decoration: none;
}
.ll-about__version:hover { color: var(--ll-accent); border-color: var(--ll-accent); }

.ll-about__item {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 7px 8px;
  border-radius: 5px;
  color: var(--ll-text);
  text-decoration: none;
  font-size: 12.5px;
}
.ll-about__item:hover { background: var(--ll-surface-raised); }
.ll-about__icon { flex-shrink: 0; color: var(--ll-text-muted); }
.ll-about__label { flex: 1; }
.ll-about__ext { flex-shrink: 0; color: var(--ll-text-faint); }
</style>
