<script setup>
defineProps({
  icon: { type: String, default: 'pi pi-inbox' },
  title: { type: String, required: true },
  description: { type: String, default: '' },
})
</script>

<template>
  <div
    class="flex flex-col items-center justify-center text-center px-6 py-16 bg-white dark:bg-gray-800 border border-dashed border-gray-200 dark:border-gray-700 rounded-lg"
    data-testid="empty-state"
  >
    <div class="w-16 h-16 mb-4 rounded-full bg-gray-50 dark:bg-gray-700/40 flex items-center justify-center text-gray-400 dark:text-brand-navy-300">
      <i :class="icon" class="text-2xl"></i>
    </div>
    <h4 class="text-base font-semibold text-gray-800 dark:text-gray-100 mb-1">
      {{ title }}
    </h4>
    <p v-if="description" class="text-sm text-gray-500 dark:text-gray-400 max-w-sm mb-5">
      {{ description }}
    </p>
    <slot />
  </div>
</template>
