<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  // localStorage key persisting the expanded/collapsed state across
  // navigations and reloads. Different views use different keys so the
  // user's preference is per-view.
  storageKey: { type: String, required: true },
  // Default state on first visit. Most callers want collapsed-on-mobile
  // (defaultExpanded = false) but expanded-on-desktop. The view is
  // expected to wrap this component in `v-if="isMobile"` for the mobile
  // case and pass false; for a "always collapsible" use case, pass true.
  defaultExpanded: { type: Boolean, default: false },
  // Visual: when true, no background/border around the wrapper (the
  // caller wraps it itself). Default: card-like container.
  bare: { type: Boolean, default: false },
})

const stored = typeof window !== 'undefined' ? localStorage.getItem(props.storageKey) : null
const initial = stored === null ? props.defaultExpanded : stored === 'true'
const expanded = ref(initial)

function toggle() {
  expanded.value = !expanded.value
  if (typeof window !== 'undefined') {
    localStorage.setItem(props.storageKey, String(expanded.value))
  }
}
</script>

<template>
  <div
    :class="bare
      ? ''
      : 'bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-3'"
  >
    <button
      type="button"
      class="flex items-center gap-2 text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 cursor-pointer"
      data-testid="collapsible-filters-toggle"
      @click="toggle"
    >
      <i class="pi text-xs" :class="expanded ? 'pi-chevron-down' : 'pi-chevron-right'"></i>
      {{ t('common.filters') }}
    </button>

    <div v-if="expanded" class="mt-3" data-testid="collapsible-filters-body">
      <slot />
    </div>
  </div>
</template>
