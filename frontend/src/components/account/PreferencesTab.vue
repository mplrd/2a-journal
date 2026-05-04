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
  dd_alert_threshold_percent: 5,
  default_page_size: 10,
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

const pageSizeOptions = [10, 25, 50, 100].map((value) => ({ value, label: String(value) }))

const saving = ref(false)

onMounted(() => {
  if (authStore.user) {
    form.value = {
      locale: authStore.user.locale || 'fr',
      theme: authStore.user.theme || 'light',
      timezone: authStore.user.timezone || 'Europe/Paris',
      default_currency: authStore.user.default_currency || 'EUR',
      be_threshold_percent: Number(authStore.user.be_threshold_percent) || 0,
      dd_alert_threshold_percent: Number(authStore.user.dd_alert_threshold_percent) || 5,
      default_page_size: Number(authStore.user.default_page_size) || 10,
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
  <form @submit.prevent="handleSave" data-testid="preferences-form">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-8 gap-y-4">
      <!-- ═══ Global preferences (left column) ═══ -->
      <div class="space-y-4">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">{{ t('account.global_preferences') }}</h3>

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

        <!-- Default page size -->
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('account.default_page_size') }}</label>
          <Select
            v-model="form.default_page_size"
            :options="pageSizeOptions"
            optionLabel="label"
            optionValue="value"
            data-testid="select-page-size"
            class="w-full"
          />
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ t('account.default_page_size_hint') }}</p>
        </div>
      </div>

      <!-- ═══ Stats preferences (right column) ═══ -->
      <div class="space-y-4">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">{{ t('account.stats_preferences') }}</h3>

        <div>
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

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('account.dd_alert_threshold') }}</label>
          <InputNumber
            v-model="form.dd_alert_threshold_percent"
            :min="1"
            :max="50"
            :maxFractionDigits="2"
            mode="decimal"
            locale="en-US"
            data-testid="input-dd-alert-threshold"
            class="w-full"
          />
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ t('account.dd_alert_threshold_hint') }}</p>
        </div>
      </div>
    </div>

    <!-- Save button -->
    <div class="pt-6 mt-2 border-t border-gray-200 dark:border-gray-700">
      <Button
        type="submit"
        :label="t('account.save')"
        :loading="saving"
        data-testid="save-button"
      />
    </div>
  </form>
</template>
