<script setup>
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Chart from 'primevue/chart'

const { t } = useI18n()

defineProps({
  title: { type: String, required: true },
  type: { type: String, required: true },
  data: { type: Object, default: null },
  options: { type: Object, required: true },
  detailable: { type: Boolean, default: false },
})

defineEmits(['detail'])
</script>

<template>
  <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ title }}</h3>
      <div class="flex items-center gap-2">
        <slot name="header-actions" />
        <Button
          v-if="detailable"
          :label="t('performance.view_details')"
          icon="pi pi-table"
          severity="secondary"
          text
          size="small"
          @click="$emit('detail')"
        />
      </div>
    </div>
    <div v-if="data" class="h-64">
      <Chart :type="type" :data="data" :options="options" class="h-full" />
    </div>
    <p v-else class="text-gray-400 text-sm py-8 text-center">{{ t('performance.no_data') }}</p>
  </div>
</template>
