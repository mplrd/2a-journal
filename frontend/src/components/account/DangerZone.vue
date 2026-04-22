<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import ChangePasswordDialog from './ChangePasswordDialog.vue'
import DeleteAccountDialog from './DeleteAccountDialog.vue'

const { t } = useI18n()

const changePasswordVisible = ref(false)
const deleteAccountVisible = ref(false)
</script>

<template>
  <div class="mt-8 p-4 border border-red-300 dark:border-red-700 rounded-lg bg-red-50/30 dark:bg-red-900/10">
    <h3 class="text-base font-semibold text-red-700 dark:text-red-300 mb-4" data-testid="danger-zone-title">
      {{ t('account.danger_zone.title') }}
    </h3>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <p class="font-medium text-gray-800 dark:text-gray-200">{{ t('account.danger_zone.change_password') }}</p>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ t('account.danger_zone.change_password_description') }}</p>
      </div>
      <Button
        :label="t('account.danger_zone.change_password')"
        severity="danger"
        outlined
        data-testid="open-change-password"
        @click="changePasswordVisible = true"
      />
    </div>

    <div class="mt-4 pt-4 border-t border-red-200 dark:border-red-800 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <p class="font-medium text-gray-800 dark:text-gray-200">{{ t('account.danger_zone.delete_account') }}</p>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ t('account.danger_zone.delete_account_description') }}</p>
      </div>
      <Button
        :label="t('account.danger_zone.delete_account')"
        severity="danger"
        data-testid="open-delete-account"
        @click="deleteAccountVisible = true"
      />
    </div>

    <ChangePasswordDialog v-model:visible="changePasswordVisible" />
    <DeleteAccountDialog v-model:visible="deleteAccountVisible" />
  </div>
</template>
