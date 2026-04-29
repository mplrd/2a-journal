<script setup>
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DatePicker from 'primevue/datepicker'

const { t } = useI18n()

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

const range = ref([toDate(props.from), toDate(props.to)])

watch(() => [props.from, props.to], ([f, t]) => {
  range.value = [toDate(f), toDate(t)]
})

watch(range, (val) => {
  const [start, end] = val || [null, null]
  emit('update:from', start || null)
  emit('update:to', end || null)
})

const placeholder = computed(() => `${t('common.from')} – ${t('common.to')}`)

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

function setLastDays(days) {
  const end = endOfDay(new Date())
  const start = startOfDay(new Date())
  start.setDate(start.getDate() - (days - 1))
  range.value = [start, end]
}

function setThisMonth() {
  const now = new Date()
  range.value = [
    startOfDay(new Date(now.getFullYear(), now.getMonth(), 1)),
    endOfDay(now),
  ]
}

function setThisQuarter() {
  const now = new Date()
  const q = Math.floor(now.getMonth() / 3)
  range.value = [
    startOfDay(new Date(now.getFullYear(), q * 3, 1)),
    endOfDay(now),
  ]
}

function setYearToDate() {
  const now = new Date()
  range.value = [
    startOfDay(new Date(now.getFullYear(), 0, 1)),
    endOfDay(now),
  ]
}

function clearRange() {
  range.value = [null, null]
}
</script>

<template>
  <DatePicker
    v-model="range"
    selectionMode="range"
    dateFormat="yy-mm-dd"
    :numberOfMonths="2"
    showIcon
    iconDisplay="input"
    :placeholder="placeholder"
    class="w-full"
  >
    <template #footer>
      <div class="flex flex-wrap gap-1 p-2 border-t border-gray-200 dark:border-gray-700">
        <button type="button" class="px-2 py-1 text-xs rounded-full bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 cursor-pointer" @click="setLastDays(7)">
          {{ t('common.range.last_7_days') }}
        </button>
        <button type="button" class="px-2 py-1 text-xs rounded-full bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 cursor-pointer" @click="setLastDays(30)">
          {{ t('common.range.last_30_days') }}
        </button>
        <button type="button" class="px-2 py-1 text-xs rounded-full bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 cursor-pointer" @click="setThisMonth">
          {{ t('common.range.this_month') }}
        </button>
        <button type="button" class="px-2 py-1 text-xs rounded-full bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 cursor-pointer" @click="setThisQuarter">
          {{ t('common.range.this_quarter') }}
        </button>
        <button type="button" class="px-2 py-1 text-xs rounded-full bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 cursor-pointer" @click="setYearToDate">
          {{ t('common.range.year_to_date') }}
        </button>
        <button type="button" class="px-2 py-1 text-xs rounded-full text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer ml-auto" @click="clearRange">
          {{ t('common.range.clear') }}
        </button>
      </div>
    </template>
  </DatePicker>
</template>
