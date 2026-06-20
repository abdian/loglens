<template>
  <div class="ll-stack-trace ll-log-content" dir="ltr">
    <!-- Exception header -->
    <div class="ll-stack-trace__header">
      <span class="ll-stack-trace__class">{{ exc.class }}</span>
      <span class="ll-stack-trace__msg">{{ exc.message }}</span>
      <span v-if="exc.throw_file" class="ll-stack-trace__throw">
        {{ exc.throw_file }}:{{ exc.throw_line }}
      </span>
    </div>

    <!-- Frames -->
    <div class="ll-stack-trace__frames">
      <template v-for="(group, gi) in frameGroups" :key="gi">
        <!-- App frames always shown -->
        <template v-if="!group.vendor">
          <div
            v-for="(frame, fi) in group.frames"
            :key="fi"
            class="ll-frame ll-frame--app"
          >
            <span class="ll-frame__num">#{{ frame._idx }}</span>
            <span class="ll-frame__call">{{ frame.call }}</span>
            <span class="ll-frame__location">
              <a
                v-if="editorUrl(frame)"
                :href="editorUrl(frame)"
                class="ll-frame__link"
                @click.prevent="openEditor(frame)"
              >{{ frame.file }}:{{ frame.line }}</a>
              <span v-else>{{ frame.file }}:{{ frame.line }}</span>
            </span>
          </div>
        </template>

        <!-- Vendor frames collapsed by default -->
        <template v-else>
          <div
            class="ll-frame ll-frame--vendor-toggle"
            @click="group.expanded = !group.expanded"
          >
            <span class="ll-frame__toggle-icon">{{ group.expanded ? '▾' : '▸' }}</span>
            <span class="ll-frame__vendor-label">
              {{ group.frames.length }} vendor frame{{ group.frames.length > 1 ? 's' : '' }}
            </span>
          </div>
          <template v-if="group.expanded">
            <div
              v-for="(frame, fi) in group.frames"
              :key="fi"
              class="ll-frame ll-frame--vendor"
            >
              <span class="ll-frame__num">#{{ frame._idx }}</span>
              <span class="ll-frame__call">{{ frame.call }}</span>
              <span class="ll-frame__location">{{ frame.file }}:{{ frame.line }}</span>
            </div>
          </template>
        </template>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive } from 'vue'
import { getBoot } from '../api.js'

const props = defineProps({
  exc: { type: Object, required: true },
})

const boot = getBoot()

// Build frame groups: consecutive vendor frames are collapsed together
const frameGroups = computed(() => {
  const frames = (props.exc.frames ?? []).map((f, i) => ({ ...f, _idx: i }))
  const groups = []
  let current = null

  for (const frame of frames) {
    const isVendor = frame.vendor
    if (!current || current.vendor !== isVendor) {
      current = reactive({ vendor: isVendor, frames: [], expanded: false })
      groups.push(current)
    }
    current.frames.push(frame)
  }

  // Auto-expand if there are no app frames (all vendor)
  if (groups.length === 1 && groups[0].vendor) {
    groups[0].expanded = true
  }

  return groups
})

function editorUrl(frame) {
  if (!frame.file || !frame.line) return null
  const editor = boot?.editor ?? 'vscode'
  const file = frame.file
  const line = frame.line

  const schemes = {
    vscode: `vscode://file/${file}:${line}`,
    'vscode-insiders': `vscode-insiders://file/${file}:${line}`,
    phpstorm: `phpstorm://open?file=${encodeURIComponent(file)}&line=${line}`,
    idea: `idea://open?file=${encodeURIComponent(file)}&line=${line}`,
    cursor: `cursor://file/${file}:${line}`,
    sublime: `subl://open?url=file://${file}&line=${line}`,
  }
  return schemes[editor] ?? null
}

function openEditor(frame) {
  const url = editorUrl(frame)
  if (url) window.open(url, '_blank')
  else {
    // Fallback: copy path:line
    navigator.clipboard?.writeText(`${frame.file}:${frame.line}`)
  }
}
</script>

<style scoped>
.ll-stack-trace {
  font-size: 12px;
  line-height: 1.6;
  overflow-x: auto;
}

.ll-stack-trace__header {
  padding: 8px 12px;
  background: var(--ll-level-error-bg);
  border-radius: 6px;
  margin-block-end: 8px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.ll-stack-trace__class {
  font-weight: 700;
  color: var(--ll-level-error);
  word-break: break-all;
}

.ll-stack-trace__msg {
  color: var(--ll-text);
}

.ll-stack-trace__throw {
  color: var(--ll-text-muted);
  font-size: 11px;
}

/* Grid: [#num] on the left, the call + location stacked in the right column so
   nothing is squeezed into a per-character wrap on a narrow panel. */
.ll-frame {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: baseline;
  gap: 1px 8px;
  padding: 3px 4px;
  border-radius: 3px;
}

.ll-frame--app {
  background: var(--ll-surface-raised);
  margin-block-end: 2px;
}

.ll-frame--app:hover {
  background: var(--ll-surface-overlay);
}

.ll-frame--vendor {
  color: var(--ll-text-muted);
  font-size: 11px;
  padding-inline-start: 20px;
}

.ll-frame--vendor-toggle {
  cursor: pointer;
  color: var(--ll-text-faint);
  font-size: 11px;
  padding: 1px 4px;
  user-select: none;
}

.ll-frame--vendor-toggle:hover {
  color: var(--ll-text-muted);
}

.ll-frame__num {
  grid-column: 1;
  grid-row: 1 / span 2;
  color: var(--ll-text-faint);
  min-width: 24px;
  font-size: 11px;
}

.ll-frame__call {
  grid-column: 2;
  grid-row: 1;
  color: var(--ll-text);
  overflow-wrap: anywhere;
  word-break: break-word;
}

.ll-frame__location {
  grid-column: 2;
  grid-row: 2;
  color: var(--ll-accent);
  font-size: 11px;
  overflow-wrap: anywhere;
  word-break: break-all;
}

.ll-frame__link {
  color: var(--ll-accent);
  text-decoration: none;
}

.ll-frame__link:hover {
  text-decoration: underline;
}

.ll-frame__toggle-icon {
  flex-shrink: 0;
  width: 12px;
}
</style>
