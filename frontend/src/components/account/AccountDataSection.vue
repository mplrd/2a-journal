<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import DeleteAccountDialog from './DeleteAccountDialog.vue'

const { t } = useI18n()

const deleteAccountVisible = ref(false)

defineExpose({ deleteAccountVisible })
</script>

<template>
  <div
    class="mt-8 p-4 border border-red-300 dark:border-red-700 rounded-lg bg-red-50/30 dark:bg-red-900/10"
    data-testid="account-data-wrapper"
  >
    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-4" data-testid="account-data-title">
      {{ t('account.account_data.title') }}
    </h3>

    <div
      class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
      data-testid="account-data-row"
    >
      <div>
        <p class="font-medium text-gray-800 dark:text-gray-200">{{ t('account.account_data.delete_account') }}</p>
        <p class="text-sm text-gray-600 dark:text-gray-400" data-testid="delete-account-description">
          {{ t('account.account_data.delete_account_description') }}
        </p>
      </div>

      <p
        class="flex items-center gap-2 text-base font-bold uppercase tracking-wide text-red-600 dark:text-red-400"
        data-testid="irreversible-warning"
      >
        <i class="pi pi-exclamation-triangle" aria-hidden="true"></i>
        {{ t('account.account_data.irreversible_warning') }}
      </p>

      <Button
        :label="t('account.account_data.delete_account')"
        severity="danger"
        data-testid="open-delete-account"
        @click="deleteAccountVisible = true"
      />
    </div>

    <DeleteAccountDialog v-model:visible="deleteAccountVisible" />
  </div>
</template>
