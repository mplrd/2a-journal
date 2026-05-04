<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import ChangePasswordDialog from './ChangePasswordDialog.vue'

const { t } = useI18n()

const changePasswordVisible = ref(false)

defineExpose({ changePasswordVisible })
</script>

<template>
  <div
    class="mt-8 p-4 border border-gray-200 dark:border-gray-700 rounded-lg bg-white/50 dark:bg-gray-900/30"
    data-testid="security-section-wrapper"
  >
    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-4" data-testid="security-section-title">
      {{ t('account.security.title') }}
    </h3>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <p class="font-medium text-gray-800 dark:text-gray-200">{{ t('account.security.change_password') }}</p>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ t('account.security.change_password_description') }}</p>
      </div>
      <Button
        :label="t('account.security.change_password')"
        outlined
        data-testid="open-change-password"
        @click="changePasswordVisible = true"
      />
    </div>

    <ChangePasswordDialog v-model:visible="changePasswordVisible" />
  </div>
</template>
