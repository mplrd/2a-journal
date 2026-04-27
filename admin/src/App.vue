<script setup>
import { useAuthStore } from '@/stores/auth'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'

// auth.initSession() is called once by the router beforeEach guard before any
// navigation resolves. We deliberately do NOT call it from App.vue onMounted
// too — that triggered a race on F5 where the second call hit /auth/refresh
// with an already-rotated token (server invalidates the old refresh token on
// each use) and 401-ed, clearing the auth state and bouncing back to /login.
const auth = useAuthStore()
</script>

<template>
  <div v-if="auth.initialized">
    <router-view />
    <Toast />
    <ConfirmDialog />
  </div>
</template>
