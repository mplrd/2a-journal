<script setup>
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const authStore = useAuthStore()
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold mb-4">{{ t('dashboard.title') }}</h1>
    <p v-if="authStore.fullName" class="text-gray-600">
      {{ t('dashboard.welcome', { name: authStore.fullName }) }}
    </p>
  </div>
</template>
