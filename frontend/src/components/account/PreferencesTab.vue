<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useTheme } from '@/composables/useTheme'
import { useToast } from 'primevue/usetoast'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Button from 'primevue/button'

const { t, locale } = useI18n()
const authStore = useAuthStore()
const { applyTheme } = useTheme()
const toast = useToast()

const form = ref({
  locale: 'fr',
  theme: 'light',
  timezone: 'Europe/Paris',
  default_currency: 'EUR',
  be_threshold_percent: 0,
})

const timezones = Intl.supportedValuesOf('timeZone')
const currencies = ['EUR', 'USD', 'GBP', 'CHF', 'JPY', 'CAD', 'AUD']

const themeOptions = [
  { value: 'light', label: t('account.theme_light') },
  { value: 'dark', label: t('account.theme_dark') },
]

const localeOptions = [
  { value: 'fr', label: 'Français' },
  { value: 'en', label: 'English' },
]

const saving = ref(false)

onMounted(() => {
  if (authStore.user) {
    form.value = {
      locale: authStore.user.locale || 'fr',
      theme: authStore.user.theme || 'light',
      timezone: authStore.user.timezone || 'Europe/Paris',
      default_currency: authStore.user.default_currency || 'EUR',
      be_threshold_percent: Number(authStore.user.be_threshold_percent) || 0,
    }
  }
})

async function handleSave() {
  saving.value = true
  try {
    await authStore.updateProfile({ ...form.value })

    applyTheme(form.value.theme)

    if (form.value.locale !== locale.value) {
      locale.value = form.value.locale
      localStorage.setItem('locale', form.value.locale)
    }

    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('auth.success.profile_updated'),
      life: 3000,
    })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <form class="max-w-lg space-y-4" @submit.prevent="handleSave" data-testid="preferences-form">
    <!-- Locale -->
    <div>
      <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('account.locale') }}</label>
      <Select
        v-model="form.locale"
        :options="localeOptions"
        optionLabel="label"
        optionValue="value"
        data-testid="select-locale"
        class="w-full"
      />
    </div>

    <!-- Theme -->
    <div>
      <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('account.theme') }}</label>
      <Select
        v-model="form.theme"
        :options="themeOptions"
        optionLabel="label"
        optionValue="value"
        data-testid="select-theme"
        class="w-full"
      />
    </div>

    <!-- Timezone -->
    <div>
      <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('account.timezone') }}</label>
      <Select
        v-model="form.timezone"
        :options="timezones"
        filter
        data-testid="select-timezone"
        class="w-full"
      />
    </div>

    <!-- Default currency -->
    <div>
      <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('account.default_currency') }}</label>
      <Select
        v-model="form.default_currency"
        :options="currencies"
        data-testid="select-currency"
        class="w-full"
      />
    </div>

    <!-- BE threshold -->
    <div class="pt-4 mt-2 border-t border-gray-200 dark:border-gray-700">
      <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">{{ t('account.stats_preferences') }}</h3>
      <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('account.be_threshold') }}</label>
      <InputNumber
        v-model="form.be_threshold_percent"
        :min="0"
        :max="5"
        :maxFractionDigits="4"
        mode="decimal"
        locale="en-US"
        data-testid="input-be-threshold"
        class="w-full"
      />
      <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ t('account.be_threshold_hint') }}</p>
    </div>

    <!-- Save button -->
    <div class="pt-2">
      <Button
        type="submit"
        :label="t('account.save')"
        :loading="saving"
        data-testid="save-button"
      />
    </div>
  </form>
</template>
