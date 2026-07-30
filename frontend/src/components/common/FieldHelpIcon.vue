<script setup>
/**
 * Info icon next to a form label, carrying its explanation as both a hover
 * tooltip and an aria-label — same affordance as the hints in docs/82, factored
 * out because the broker connect dialogs need one per credential field.
 *
 * The aria-label matters: hover-only help is invisible to screen readers and to
 * touch users.
 */
defineProps({
  text: { type: String, required: true },
  testid: { type: String, default: null },
})
</script>

<template>
  <i
    class="pi pi-info-circle text-gray-400 cursor-help text-xs align-middle"
    role="img"
    :data-testid="testid"
    :aria-label="text"
    v-tooltip.top="text"
  />
</template>
