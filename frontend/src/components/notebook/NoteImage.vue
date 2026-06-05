<script setup>
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { notesService } from '@/services/notebook'

// Loads a note image through the authenticated stream endpoint and exposes it as
// an object URL, revoked on unmount to avoid leaking blobs.
const props = defineProps({
  noteId: { type: [Number, String], required: true },
  attachmentId: { type: [Number, String], required: true },
  alt: { type: String, default: '' },
})

const src = ref(null)
const failed = ref(false)

async function load() {
  revoke()
  failed.value = false
  try {
    src.value = await notesService.attachmentUrl(props.noteId, props.attachmentId)
  } catch {
    failed.value = true
  }
}

function revoke() {
  if (src.value) {
    URL.revokeObjectURL(src.value)
    src.value = null
  }
}

onMounted(load)
watch(() => props.attachmentId, load)
onBeforeUnmount(revoke)
</script>

<template>
  <div class="relative overflow-hidden rounded-md bg-gray-100 dark:bg-gray-800">
    <img
      v-if="src"
      :src="src"
      :alt="alt"
      class="h-full w-full object-cover"
      loading="lazy"
    />
    <div v-else-if="failed" class="flex h-full w-full items-center justify-center text-gray-400">
      <i class="pi pi-image text-xl"></i>
    </div>
    <div v-else class="flex h-full w-full items-center justify-center text-gray-300">
      <i class="pi pi-spin pi-spinner"></i>
    </div>
  </div>
</template>
