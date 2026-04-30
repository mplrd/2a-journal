<script setup>
const props = defineProps({
  modelValue: { type: [Number, String, Array, null], default: null },
  options: { type: Array, required: true }, // [{ value, label, color? }]
  multi: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'change'])

// Static palette so Tailwind's content scanner picks up every class.
// `color` on an option (typically derived from a domain category) selects
// a row here. If absent, we fall back to the default brand-green theme.
const COLOR_THEMES = {
  default: {
    active: 'bg-brand-green-700 border-brand-green-700 text-white font-semibold shadow-sm dark:bg-brand-green-500 dark:border-brand-green-500',
    inactive: 'bg-transparent border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 font-medium hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-800 dark:hover:text-gray-200',
  },
  blue: {
    active: 'bg-blue-600 border-blue-600 text-white font-semibold shadow-sm dark:bg-blue-500 dark:border-blue-500',
    inactive: 'bg-transparent border-blue-300 dark:border-blue-700 text-blue-700 dark:text-blue-300 font-medium hover:bg-blue-50 dark:hover:bg-blue-900/30',
  },
  purple: {
    active: 'bg-purple-600 border-purple-600 text-white font-semibold shadow-sm dark:bg-purple-500 dark:border-purple-500',
    inactive: 'bg-transparent border-purple-300 dark:border-purple-700 text-purple-700 dark:text-purple-300 font-medium hover:bg-purple-50 dark:hover:bg-purple-900/30',
  },
  amber: {
    active: 'bg-amber-600 border-amber-600 text-white font-semibold shadow-sm dark:bg-amber-500 dark:border-amber-500',
    inactive: 'bg-transparent border-amber-300 dark:border-amber-700 text-amber-700 dark:text-amber-300 font-medium hover:bg-amber-50 dark:hover:bg-amber-900/30',
  },
  gray: {
    active: 'bg-gray-600 border-gray-600 text-white font-semibold shadow-sm dark:bg-gray-500 dark:border-gray-500',
    inactive: 'bg-transparent border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 font-medium hover:bg-gray-100 dark:hover:bg-gray-700',
  },
}

function isActive(value) {
  if (props.multi) {
    return Array.isArray(props.modelValue) && props.modelValue.includes(value)
  }
  return props.modelValue === value
}

function classesFor(opt) {
  const theme = COLOR_THEMES[opt.color] || COLOR_THEMES.default
  return isActive(opt.value) ? theme.active : theme.inactive
}

function toggle(value) {
  let next
  if (props.multi) {
    const current = Array.isArray(props.modelValue) ? [...props.modelValue] : []
    const idx = current.indexOf(value)
    if (idx === -1) current.push(value)
    else current.splice(idx, 1)
    next = current
  } else {
    next = props.modelValue === value ? null : value
  }
  emit('update:modelValue', next)
  emit('change', next)
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <button
      v-for="opt in options"
      :key="String(opt.value)"
      type="button"
      class="px-3 py-1 rounded-full text-sm border transition-colors cursor-pointer"
      :class="classesFor(opt)"
      @click="toggle(opt.value)"
    >
      {{ opt.label }}
    </button>
  </div>
</template>
