<script setup>
defineProps({
  label: { type: String, required: true },
  valueClass: { type: String, default: '' },
})
</script>

<template>
  <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
    <div class="text-sm text-gray-500 dark:text-gray-400 mb-1">{{ label }}</div>
    <div class="text-2xl font-bold font-mono tabular-nums" :class="valueClass">
      <slot />
    </div>
  </div>
</template>
