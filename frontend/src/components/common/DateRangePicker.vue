<script setup>
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DatePicker from 'primevue/datepicker'
import Popover from 'primevue/popover'

const { t, locale } = useI18n()

const props = defineProps({
  from: { type: [Date, String, null], default: null },
  to: { type: [Date, String, null], default: null },
})

const emit = defineEmits(['update:from', 'update:to'])

function toDate(v) {
  if (!v) return null
  if (v instanceof Date) return v
  return new Date(v)
}

function toRangeModel(f, t) {
  const fd = toDate(f)
  const td = toDate(t)
  if (!fd && !td) return null
  return [fd, td]
}

const range = ref(toRangeModel(props.from, props.to))
const popoverRef = ref(null)

watch(() => [props.from, props.to], ([f, t]) => {
  range.value = toRangeModel(f, t)
})

watch(range, (val) => {
  const [start, end] = val || [null, null]
  emit('update:from', start || null)
  emit('update:to', end || null)
})

function startOfDay(d) {
  const x = new Date(d)
  x.setHours(0, 0, 0, 0)
  return x
}
function endOfDay(d) {
  const x = new Date(d)
  x.setHours(23, 59, 59, 999)
  return x
}

function sameDay(a, b) {
  if (!a || !b) return false
  return a.getFullYear() === b.getFullYear()
    && a.getMonth() === b.getMonth()
    && a.getDate() === b.getDate()
}

function lastDaysRange(days) {
  const end = endOfDay(new Date())
  const start = startOfDay(new Date())
  start.setDate(start.getDate() - (days - 1))
  return [start, end]
}

function thisMonthRange() {
  const now = new Date()
  return [
    startOfDay(new Date(now.getFullYear(), now.getMonth(), 1)),
    endOfDay(now),
  ]
}

function thisQuarterRange() {
  const now = new Date()
  const q = Math.floor(now.getMonth() / 3)
  return [
    startOfDay(new Date(now.getFullYear(), q * 3, 1)),
    endOfDay(now),
  ]
}

function ytdRange() {
  const now = new Date()
  return [
    startOfDay(new Date(now.getFullYear(), 0, 1)),
    endOfDay(now),
  ]
}

const presets = computed(() => [
  { key: 'last_7_days', label: t('common.range.last_7_days'), build: () => lastDaysRange(7) },
  { key: 'last_30_days', label: t('common.range.last_30_days'), build: () => lastDaysRange(30) },
  { key: 'this_month', label: t('common.range.this_month'), build: thisMonthRange },
  { key: 'this_quarter', label: t('common.range.this_quarter'), build: thisQuarterRange },
  { key: 'year_to_date', label: t('common.range.year_to_date'), build: ytdRange },
])

function applyPreset(preset) {
  range.value = preset.build()
  popoverRef.value?.hide()
}

function clearRange() {
  range.value = null
  popoverRef.value?.hide()
}

function togglePopover(e) {
  popoverRef.value?.toggle(e)
}

const matchedPresetLabel = computed(() => {
  const [s, e] = range.value || [null, null]
  if (!s || !e) return null
  for (const p of presets.value) {
    const [ps, pe] = p.build()
    if (sameDay(s, ps) && sameDay(e, pe)) return p.label
  }
  return null
})

const dayFormatter = computed(() => new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short' }))
const dayYearFormatter = computed(() => new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'short', year: 'numeric' }))

const displayLabel = computed(() => {
  if (matchedPresetLabel.value) return matchedPresetLabel.value
  const [s, e] = range.value || [null, null]
  if (!s && !e) return t('common.range.placeholder')
  if (s && !e) return `${t('common.from')} ${dayYearFormatter.value.format(s)}`
  if (!s && e) return `${t('common.to')} ${dayYearFormatter.value.format(e)}`
  // Both present
  const sameYear = s.getFullYear() === e.getFullYear()
  const startStr = sameYear ? dayFormatter.value.format(s) : dayYearFormatter.value.format(s)
  const endStr = dayYearFormatter.value.format(e)
  return `${startStr} – ${endStr}`
})

const hasValue = computed(() => Boolean(range.value?.[0] || range.value?.[1]))
</script>

<template>
  <div class="relative">
    <button
      type="button"
      class="flex items-center gap-2 px-3 py-1.5 text-sm rounded-md border transition-colors cursor-pointer w-full"
      :class="hasValue
        ? 'bg-brand-green-700 border-brand-green-700 text-white font-semibold shadow-sm hover:bg-brand-green-800'
        : 'bg-transparent border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 font-medium hover:border-gray-400 dark:hover:border-gray-500'"
      @click="togglePopover"
    >
      <i class="pi pi-calendar text-xs"></i>
      <span class="truncate">{{ displayLabel }}</span>
      <i v-if="hasValue" class="pi pi-times text-xs ml-auto opacity-80 hover:opacity-100" @click.stop="clearRange"></i>
      <i v-else class="pi pi-chevron-down text-xs ml-auto opacity-60"></i>
    </button>

    <Popover ref="popoverRef">
      <div class="flex flex-col sm:flex-row gap-3">
        <!-- Presets column -->
        <div class="flex flex-col gap-1 sm:border-r sm:border-gray-200 sm:dark:border-gray-700 sm:pr-3 min-w-[160px]">
          <button
            v-for="preset in presets"
            :key="preset.key"
            type="button"
            class="text-left px-3 py-1.5 text-sm rounded-md cursor-pointer transition-colors"
            :class="matchedPresetLabel === preset.label
              ? 'bg-brand-green-700 text-white font-semibold'
              : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'"
            @click="applyPreset(preset)"
          >
            {{ preset.label }}
          </button>
          <button
            type="button"
            class="text-left px-3 py-1.5 text-sm rounded-md cursor-pointer text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 mt-1"
            @click="clearRange"
          >
            {{ t('common.range.clear') }}
          </button>
        </div>

        <!-- Inline calendar -->
        <DatePicker
          v-model="range"
          selectionMode="range"
          :inline="true"
          :numberOfMonths="2"
        />
      </div>
    </Popover>
  </div>
</template>
