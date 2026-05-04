<script setup>
defineProps({
  // PrimeIcons class without the `pi pi-` prefix, e.g. "plus", "trash".
  icon: { type: String, default: 'plus' },
  // Mandatory accessible label — the FAB has no visible text.
  ariaLabel: { type: String, required: true },
})

defineEmits(['click'])
</script>

<template>
  <button
    type="button"
    class="lg:hidden fixed bottom-6 right-6 z-30 w-14 h-14 rounded-full bg-brand-green-700 hover:bg-brand-green-800 active:bg-brand-green-900 text-white shadow-lg flex items-center justify-center transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-green-500"
    :aria-label="ariaLabel"
    data-testid="fab"
    @click="$emit('click')"
  >
    <i :class="['pi', `pi-${icon}`, 'text-xl']"></i>
  </button>
</template>
