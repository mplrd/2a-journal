<script setup>
defineProps({
  code: { type: String, required: true },
  size: { type: Number, default: 24 },
})
</script>

<template>
  <!-- France: blue / white / red vertical stripes -->
  <svg v-if="code === 'fr'" :width="size" :height="size" viewBox="0 0 120 120" class="rounded-full shrink-0">
    <clipPath id="fr-clip"><circle cx="60" cy="60" r="60" /></clipPath>
    <g clip-path="url(#fr-clip)">
      <rect x="0" y="0" width="40" height="120" fill="#002395" />
      <rect x="40" y="0" width="40" height="120" fill="#ffffff" />
      <rect x="80" y="0" width="40" height="120" fill="#ED2939" />
    </g>
  </svg>

  <!-- United Kingdom: simplified Union Jack -->
  <svg v-else-if="code === 'en'" :width="size" :height="size" viewBox="0 0 120 120" class="rounded-full shrink-0">
    <clipPath id="gb-clip"><circle cx="60" cy="60" r="60" /></clipPath>
    <g clip-path="url(#gb-clip)">
      <rect width="120" height="120" fill="#012169" />
      <path d="M0,0 L120,120 M120,0 L0,120" stroke="#fff" stroke-width="20" />
      <path d="M0,0 L120,120 M120,0 L0,120" stroke="#C8102E" stroke-width="12" />
      <path d="M60,0 V120 M0,60 H120" stroke="#fff" stroke-width="24" />
      <path d="M60,0 V120 M0,60 H120" stroke="#C8102E" stroke-width="14" />
    </g>
  </svg>

  <!-- Fallback: code letters in circle -->
  <span v-else class="rounded-full bg-gray-200 dark:bg-gray-600 flex items-center justify-center text-xs font-bold uppercase" :style="{ width: size + 'px', height: size + 'px' }">
    {{ code }}
  </span>
</template>
