<template>
  <div class="ll-searchbar">
    <!-- Input area -->
    <div class="ll-searchbar__input-wrap" :class="{ 'll-searchbar__input-wrap--error': parseError }">
      <svg class="ll-searchbar__icon" width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
        <path d="M10.68 11.74a6 6 0 0 1-7.922-8.982 6 6 0 0 1 8.982 7.922l3.04 3.04a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215ZM11.5 7a4.499 4.499 0 1 0-8.997 0A4.499 4.499 0 0 0 11.5 7Z"/>
      </svg>
      <input
        ref="inputRef"
        v-model="localQuery"
        type="text"
        class="ll-searchbar__input"
        :placeholder="t('search.placeholder')"
        dir="ltr"
        autocomplete="off"
        spellcheck="false"
        @keydown.enter="commitSearch"
        @keydown.escape="clearSearch"
      />
      <!-- Tier badge -->
      <span v-if="tier" class="ll-searchbar__tier" :title="t('search.tier')">{{ tier }}</span>
      <!-- Count -->
      <span v-if="count !== null" class="ll-searchbar__count">
        {{ count.toLocaleString() }}
      </span>
      <!-- Loading spinner -->
      <span v-if="loading" class="ll-searchbar__spinner" />
      <!-- Clear -->
      <button v-if="localQuery" class="ll-searchbar__clear" @click="clearSearch">
        <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor">
          <path d="M3.72 3.72a.75.75 0 0 1 1.06 0L8 6.94l3.22-3.22a.749.749 0 0 1 1.275.326.749.749 0 0 1-.215.734L9.06 8l3.22 3.22a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215L8 9.06l-3.22 3.22a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042L6.94 8 3.72 4.78a.75.75 0 0 1 0-1.06Z"/>
        </svg>
      </button>
    </div>

    <!-- Parse error -->
    <div v-if="parseError" class="ll-searchbar__error">
      <svg width="12" height="12" viewBox="0 0 16 16" fill="currentColor" class="inline mr-1">
        <path d="M6.457 1.047c.659-1.234 2.427-1.234 3.086 0l6.082 11.378A1.75 1.75 0 0 1 14.082 15H1.918a1.75 1.75 0 0 1-1.543-2.575Z"/>
      </svg>
      {{ parseError.message }}
      <span v-if="parseError.position !== undefined" class="ml-2 text-[var(--ll-text-faint)]">
        at position {{ parseError.position }}
      </span>
    </div>

    <!-- Syntax chips -->
    <div class="ll-searchbar__chips">
      <button
        v-for="chip in chips"
        :key="chip.example"
        class="ll-chip"
        @click="insertChip(chip.example)"
        :title="chip.desc"
      >{{ chip.label }}</button>
      <!-- Saved searches dropdown -->
      <div class="relative" v-if="savedSearches.length">
        <button class="ll-chip" @click="showSaved = !showSaved">Saved ▾</button>
        <div v-if="showSaved" class="ll-saved-dropdown">
          <div
            v-for="s in savedSearches"
            :key="s.id"
            class="ll-saved-item"
            @click="applySaved(s)"
          >
            <span class="flex-1 truncate">{{ s.name }}</span>
            <button
              class="text-[var(--ll-text-faint)] hover:text-[var(--ll-level-error)] ml-1"
              @click.stop="deleteSaved(s.id)"
            >×</button>
          </div>
        </div>
      </div>
      <button class="ll-chip" @click="saveCurrent" v-if="localQuery">{{ t('search.save') }}</button>
    </div>

    <!-- One-line hint: what a query can match -->
    <div v-if="!parseError" class="ll-searchbar__hint">{{ t('search.hint') }}</div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useSearchStore } from '../stores/search.js'
import { useFilesStore } from '../stores/files.js'
import { t } from '../i18n.js'

const searchStore = useSearchStore()
const filesStore = useFilesStore()

const inputRef = ref(null)
const localQuery = ref(searchStore.query)
const showSaved = ref(false)

const tier = computed(() => searchStore.tier)
const count = computed(() => searchStore.count)
const loading = computed(() => searchStore.loading)
const parseError = computed(() => searchStore.parseError)
const savedSearches = computed(() => searchStore.savedSearches)

// Debounce
let _debounce = null
watch(localQuery, (val) => {
  searchStore.query = val
  if (_debounce) clearTimeout(_debounce)
  if (!val.trim()) {
    searchStore.clear()
    return
  }
  // Reflect "working" immediately (before the debounce elapses) so the list
  // shows a Searching… state instead of a flash of "no matches" — but only when
  // a search will actually run, so the flag is always cleared by search().
  if (filesStore.activeFileId) searchStore.loading = true
  _debounce = setTimeout(() => {
    if (filesStore.activeFileId) {
      searchStore.search(filesStore.activeFileId)
    }
  }, 300)
})

// Sync from store (e.g. URL restore)
watch(() => searchStore.query, (val) => {
  if (val !== localQuery.value) localQuery.value = val
})

function commitSearch() {
  if (!filesStore.activeFileId) return
  if (_debounce) { clearTimeout(_debounce); _debounce = null }
  searchStore.search(filesStore.activeFileId)
}

function clearSearch() {
  localQuery.value = ''
  searchStore.clear()
  inputRef.value?.focus()
}

function insertChip(example) {
  localQuery.value = (localQuery.value + ' ' + example).trim()
  inputRef.value?.focus()
}

const chips = [
  { label: 'level:error',    example: 'level:error',    desc: 'Filter by level' },
  { label: 'level:warning',  example: 'level:warning',  desc: 'Filter by level' },
  { label: 'after:-1h',      example: 'after:-1h',      desc: 'Last hour' },
  { label: 'after:-24h',     example: 'after:-24h',     desc: 'Last 24 hours' },
  { label: 'channel:queue',  example: 'channel:queue',  desc: 'Filter by channel' },
  { label: '"exact phrase"', example: '"exact phrase"', desc: 'Match an exact phrase' },
  { label: '/regex/',        example: '/pattern/',      desc: 'Regex search' },
]

async function saveCurrent() {
  const name = prompt('Save search as:')
  if (!name) return
  await searchStore.saveSearch(name)
}

function applySaved(s) {
  localQuery.value = s.query ?? ''
  showSaved.value = false
}

async function deleteSaved(id) {
  await searchStore.deleteSavedSearch(id)
}

// Expose focus method for keyboard shortcut
defineExpose({ focus: () => inputRef.value?.focus() })
</script>

<style scoped>
.ll-searchbar {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 6px 8px;
  border-block-end: 1px solid var(--ll-border-subtle);
  background: var(--ll-surface);
}

.ll-searchbar__input-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
  background: var(--ll-surface-sunken);
  border: 1px solid var(--ll-border);
  border-radius: 6px;
  padding: 0 8px;
  transition: border-color 0.1s;
}

.ll-searchbar__input-wrap:focus-within {
  border-color: var(--ll-accent);
  box-shadow: 0 0 0 2px var(--ll-accent-bg);
}

.ll-searchbar__input-wrap--error {
  border-color: var(--ll-level-error);
}

.ll-searchbar__icon {
  flex-shrink: 0;
  color: var(--ll-text-faint);
}

.ll-searchbar__input {
  flex: 1;
  background: none;
  border: none;
  outline: none;
  color: var(--ll-text);
  font-size: 13px;
  padding: 6px 0;
  font-family: 'JetBrains Mono', monospace;
  min-width: 0;
}

.ll-searchbar__input::placeholder {
  color: var(--ll-text-faint);
  font-family: -apple-system, system-ui, sans-serif;
}

.ll-searchbar__tier {
  flex-shrink: 0;
  font-size: 10px;
  padding: 1px 5px;
  border-radius: 3px;
  background: var(--ll-surface-overlay);
  color: var(--ll-text-faint);
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.ll-searchbar__count {
  flex-shrink: 0;
  font-size: 11px;
  color: var(--ll-text-muted);
}

.ll-searchbar__spinner {
  flex-shrink: 0;
  width: 14px;
  height: 14px;
  border: 2px solid var(--ll-border);
  border-top-color: var(--ll-accent);
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

.ll-searchbar__clear {
  flex-shrink: 0;
  background: none;
  border: none;
  color: var(--ll-text-faint);
  cursor: pointer;
  padding: 2px;
  border-radius: 3px;
  display: flex;
}

.ll-searchbar__clear:hover { color: var(--ll-text-muted); }

.ll-searchbar__error {
  font-size: 11px;
  color: var(--ll-level-error);
  padding: 2px 4px;
}

.ll-searchbar__hint {
  font-size: 10.5px;
  color: var(--ll-text-faint);
  padding: 0 4px;
  line-height: 1.4;
}

.ll-searchbar__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  align-items: center;
}

.ll-chip {
  font-size: 11px;
  padding: 2px 7px;
  border-radius: 4px;
  border: 1px solid var(--ll-border-subtle);
  background: var(--ll-surface-raised);
  color: var(--ll-text-muted);
  cursor: pointer;
  font-family: 'JetBrains Mono', monospace;
  transition: background 0.1s, color 0.1s;
}

.ll-chip:hover {
  background: var(--ll-surface-overlay);
  color: var(--ll-text);
  border-color: var(--ll-border);
}

.ll-saved-dropdown {
  position: absolute;
  inset-block-start: calc(100% + 4px);
  inset-inline-start: 0;
  background: var(--ll-surface-overlay);
  border: 1px solid var(--ll-border);
  border-radius: 6px;
  min-width: 200px;
  max-height: 200px;
  overflow-y: auto;
  z-index: 50;
  box-shadow: 0 4px 16px rgba(0,0,0,0.4);
}

.ll-saved-item {
  display: flex;
  align-items: center;
  padding: 6px 10px;
  font-size: 12px;
  cursor: pointer;
  color: var(--ll-text);
}

.ll-saved-item:hover { background: var(--ll-surface-raised); }
</style>
