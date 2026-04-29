<script setup>
const props = defineProps({
  modelValue: { type: [Number, String, Array, null], default: null },
  options: { type: Array, required: true }, // [{ value, label }]
  multi: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'change'])

function isActive(value) {
  if (props.multi) {
    return Array.isArray(props.modelValue) && props.modelValue.includes(value)
  }
  return props.modelValue === value
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
      :class="isActive(opt.value)
        ? 'bg-brand-green-700 border-brand-green-700 text-white font-semibold shadow-sm dark:bg-brand-green-500 dark:border-brand-green-500 dark:text-white'
        : 'bg-transparent border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 font-medium hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-800 dark:hover:text-gray-200'"
      @click="toggle(opt.value)"
    >
      {{ opt.label }}
    </button>
  </div>
</template>
