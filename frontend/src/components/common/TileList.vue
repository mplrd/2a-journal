<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'

const { t } = useI18n()

const props = defineProps({
  items: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  // Pagination — emits @page with the same 0-based contract as PrimeVue
  // DataTable so existing onPage handlers can be plugged unchanged.
  // Pass totalRecords=null to disable the pagination footer entirely.
  totalRecords: { type: Number, default: null },
  page: { type: Number, default: 1 }, // 1-based, matches store.page
  perPage: { type: Number, default: 10 },
})

const emit = defineEmits(['page'])

const totalPages = computed(() => {
  if (props.totalRecords === null) return null
  return Math.max(1, Math.ceil(props.totalRecords / props.perPage))
})

const hasPagination = computed(() => {
  return props.totalRecords !== null && totalPages.value > 1
})

function goPrev() {
  if (props.page <= 1) return
  // 1-based current → 0-based target = page - 2
  emit('page', { page: props.page - 2, rows: props.perPage })
}

function goNext() {
  if (props.page * props.perPage >= props.totalRecords) return
  // 1-based current → 0-based target = page (next page index)
  emit('page', { page: props.page, rows: props.perPage })
}
</script>

<template>
  <div>
    <div v-if="loading" class="py-12 flex justify-center" data-testid="tile-list-loading">
      <i class="pi pi-spin pi-spinner text-2xl text-gray-400"></i>
    </div>

    <div
      v-else-if="items.length === 0"
      class="py-12 text-center text-gray-500"
      data-testid="tile-list-empty"
    >
      <slot name="empty" />
    </div>

    <div v-else class="flex flex-col gap-3" data-testid="tile-list">
      <slot v-for="item in items" :key="item.id ?? item" :item="item" />
    </div>

    <div
      v-if="hasPagination"
      class="mt-4 flex items-center justify-between gap-2"
      data-testid="tile-list-pagination"
    >
      <Button
        :label="t('common.previous')"
        icon="pi pi-chevron-left"
        outlined
        size="small"
        :disabled="page <= 1"
        @click="goPrev"
      />
      <span class="text-sm text-gray-500 dark:text-gray-400">
        {{ t('common.page_x_of_y', { current: page, total: totalPages }) }}
      </span>
      <Button
        :label="t('common.next')"
        iconPos="right"
        icon="pi pi-chevron-right"
        outlined
        size="small"
        :disabled="page >= totalPages"
        @click="goNext"
      />
    </div>
  </div>
</template>
