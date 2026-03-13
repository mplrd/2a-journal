<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useTheme } from '@/composables/useTheme'
import { useToast } from 'primevue/usetoast'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost/api'

const { t, locale } = useI18n()
const authStore = useAuthStore()
const { applyTheme } = useTheme()
const toast = useToast()

const form = ref({
  first_name: '',
  last_name: '',
  email: '',
  timezone: '',
  default_currency: '',
  theme: '',
  locale: '',
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
const fileInput = ref(null)
const previewUrl = ref(null)
const uploading = ref(false)

const avatarInitials = computed(() => {
  const f = authStore.user?.first_name?.[0] || ''
  const l = authStore.user?.last_name?.[0] || ''
  return (f + l).toUpperCase()
})

const avatarImageUrl = computed(() => {
  if (previewUrl.value) return previewUrl.value
  if (authStore.user?.profile_picture) {
    return `${API_URL.replace(/\/api$/, '/api/')}${authStore.user.profile_picture}`
  }
  return null
})

onMounted(() => {
  if (authStore.user) {
    form.value = {
      first_name: authStore.user.first_name || '',
      last_name: authStore.user.last_name || '',
      email: authStore.user.email || '',
      timezone: authStore.user.timezone || 'Europe/Paris',
      default_currency: authStore.user.default_currency || 'EUR',
      theme: authStore.user.theme || 'light',
      locale: authStore.user.locale || 'fr',
    }
  }
})

function triggerFileInput() {
  fileInput.value?.click()
}

async function handleFileChange(event) {
  const file = event.target.files?.[0]
  if (!file) return

  previewUrl.value = URL.createObjectURL(file)
  uploading.value = true

  try {
    await authStore.uploadProfilePicture(file)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('auth.success.profile_picture_updated'),
      life: 3000,
    })
  } catch (err) {
    previewUrl.value = null
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: t(err.messageKey || 'error.internal'),
      life: 5000,
    })
  } finally {
    uploading.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

async function handleSave() {
  saving.value = true
  try {
    const { email, ...data } = form.value
    await authStore.updateProfile(data)

    // Apply side effects
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
  <div>
    <!-- Profile picture -->
    <div class="mb-6 flex flex-col items-start gap-2">
      <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('account.profile_picture') }}</label>
      <button
        type="button"
        class="relative w-24 h-24 rounded-full overflow-hidden cursor-pointer group focus:outline-none focus:ring-2 focus:ring-blue-500"
        data-testid="avatar-upload"
        @click="triggerFileInput"
      >
        <img
          v-if="avatarImageUrl"
          :src="avatarImageUrl"
          alt=""
          class="w-full h-full object-cover"
          data-testid="avatar-image"
        />
        <span
          v-else
          class="w-full h-full bg-blue-600 text-white flex items-center justify-center text-2xl font-semibold"
          data-testid="avatar-initials"
        >
          {{ avatarInitials }}
        </span>
        <span class="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
          <i class="pi pi-pencil text-white text-lg"></i>
        </span>
        <span
          v-if="uploading"
          class="absolute inset-0 bg-black/50 flex items-center justify-center"
        >
          <i class="pi pi-spin pi-spinner text-white text-xl"></i>
        </span>
      </button>
      <input
        ref="fileInput"
        type="file"
        accept="image/jpeg,image/png,image/webp"
        class="hidden"
        data-testid="file-input"
        @change="handleFileChange"
      />
      <span class="text-xs text-gray-500 dark:text-gray-400">{{ t('account.change_picture') }}</span>
    </div>

    <form class="max-w-lg space-y-4" @submit.prevent="handleSave" data-testid="account-form">
      <!-- First name -->
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('account.first_name') }}</label>
        <InputText v-model="form.first_name" data-testid="input-first-name" class="w-full" />
      </div>

      <!-- Last name -->
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('account.last_name') }}</label>
        <InputText v-model="form.last_name" data-testid="input-last-name" class="w-full" />
      </div>

      <!-- Email (read-only) -->
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('account.email') }}</label>
        <InputText :modelValue="form.email" disabled data-testid="input-email" class="w-full" />
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
  </div>
</template>
