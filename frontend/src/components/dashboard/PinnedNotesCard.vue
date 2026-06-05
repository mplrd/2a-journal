<script setup>
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useNotebookStore } from '@/stores/notebook'

// Compact, read-only band of the user's pinned notebook notes, surfaced high on
// the dashboard as pre-trade reminders. Self-hides when nothing is pinned so it
// never clutters the dashboard for users who don't use the notebook. Editing
// happens in the notebook tab.
const { t, locale } = useI18n()
const store = useNotebookStore()

const notes = computed(() => store.pinnedNotes.slice(0, 4))

function formatDate(value) {
  if (!value) return ''
  return new Intl.DateTimeFormat(locale.value === 'fr' ? 'fr-FR' : 'en-GB', {
    day: '2-digit', month: 'short',
  }).format(new Date(value))
}

onMounted(() => {
  // Pinned-only fetch keeps the dashboard light; the notebook tab refetches all.
  store.fetchNotes({ pinned: 1 }).catch(() => {})
})
</script>

<template>
  <div
    v-if="notes.length > 0"
    class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800"
    data-testid="pinned-notes"
  >
    <div class="mb-3 flex items-center justify-between">
      <h2 class="flex items-center gap-2 font-semibold text-gray-800 dark:text-gray-100">
        <i class="pi pi-bookmark-fill text-warning"></i>{{ t('notebook.dashboard.title') }}
      </h2>
      <RouterLink to="/notebook" class="text-sm text-brand-navy-600 hover:underline dark:text-brand-cream">
        {{ t('notebook.dashboard.see_all') }}
      </RouterLink>
    </div>

    <ul class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
      <li v-for="note in notes" :key="note.id" class="border-l-2 border-brand-navy-300 pl-3 dark:border-brand-navy-600">
        <div class="flex items-center justify-between gap-2 text-xs text-gray-500">
          <span class="truncate font-medium text-gray-700 dark:text-gray-200">{{ note.title || note.category_label || t('notebook.uncategorized') }}</span>
          <span class="shrink-0">{{ formatDate(note.note_date) }}</span>
        </div>
        <p class="mt-0.5 line-clamp-2 whitespace-pre-wrap text-sm text-gray-600 dark:text-gray-400">{{ note.content }}</p>
      </li>
    </ul>
  </div>
</template>
