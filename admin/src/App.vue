<script setup>
import { onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'

const auth = useAuthStore()

onMounted(async () => {
  await auth.initSession()
})
</script>

<template>
  <div v-if="auth.initialized">
    <router-view />
    <Toast />
    <ConfirmDialog />
  </div>
</template>
