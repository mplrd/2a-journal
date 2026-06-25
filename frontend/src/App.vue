<template>
  <Toast position="top-right" />
  <ConfirmDialog />
  <RouterView />
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import { useAuthStore } from '@/stores/auth'
import { useNumberLocale } from '@/composables/useNumberLocale'
import { decimalSeparatorForLocale } from '@/utils/numberLocale'
import { installNumpadDecimalRemap } from '@/utils/numpadDecimal'

const { numberLocale } = useNumberLocale()
let uninstallNumpadRemap

// Listen for verification signals from other tabs for the whole app
// lifetime, so the email-verification banner clears across tabs.
onMounted(() => {
  useAuthStore().startCrossTabSync()
  // Make the numpad decimal key insert the active locale's decimal separator
  // app-wide (fr → ',', en → no-op). Reactive to language switches.
  uninstallNumpadRemap = installNumpadDecimalRemap(() => decimalSeparatorForLocale(numberLocale.value))
})

onUnmounted(() => uninstallNumpadRemap?.())
</script>
