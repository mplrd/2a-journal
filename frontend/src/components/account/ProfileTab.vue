<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'primevue/usetoast'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import DangerZone from './DangerZone.vue'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost/api'

const { t } = useI18n()
const authStore = useAuthStore()
const toast = useToast()

const form = ref({
  first_name: '',
  last_name: '',
  email: '',
})

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

    <DangerZone />
  </div>
</template>
