<template>
  <Toast position="top-right" />
  <ConfirmDialog />
  <RouterView />
</template>

<script setup>
import { onMounted } from 'vue'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import { useAuthStore } from '@/stores/auth'

// Listen for verification signals from other tabs for the whole app
// lifetime, so the email-verification banner clears across tabs.
onMounted(() => {
  useAuthStore().startCrossTabSync()
})
</script>
