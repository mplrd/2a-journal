<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'

const { t } = useI18n()
const authStore = useAuthStore()
const toast = useToast()
const router = useRouter()

const props = defineProps({
  visible: Boolean,
})
const emit = defineEmits(['update:visible'])

const form = ref({ email_confirmation: '', password: '' })
const submitting = ref(false)
const clientError = ref(null)

const userEmail = computed(() => authStore.user?.email || '')
const emailMatches = computed(() => form.value.email_confirmation === userEmail.value && userEmail.value !== '')
const canSubmit = computed(() => emailMatches.value && form.value.password)

watch(() => props.visible, (val) => {
  if (val) {
    form.value = { email_confirmation: '', password: '' }
    clientError.value = null
  }
})

async function handleSubmit() {
  clientError.value = null
  submitting.value = true
  try {
    await authStore.deleteAccount({
      password: form.value.password,
      email_confirmation: form.value.email_confirmation,
    })
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('auth.success.account_deleted'),
      life: 3000,
    })
    emit('update:visible', false)
    router.push({ name: 'login' })
  } catch (err) {
    clientError.value = t(err.messageKey || 'error.internal')
  } finally {
    submitting.value = false
  }
}

function handleClose() {
  emit('update:visible', false)
}
</script>

<template>
  <Dialog
    :visible="visible"
    :header="t('account.delete_account.title')"
    :modal="true"
    :closable="true"
    :style="{ width: '500px' }"
    @update:visible="handleClose"
  >
    <form class="flex flex-col gap-4" @submit.prevent="handleSubmit" data-testid="delete-account-form">
      <div class="p-3 bg-red-50 dark:bg-red-900/30 border border-red-300 dark:border-red-700 rounded">
        <p class="font-semibold text-red-700 dark:text-red-300 mb-1">{{ t('account.delete_account.warning_title') }}</p>
        <p class="text-sm text-red-700 dark:text-red-300">{{ t('account.delete_account.warning_line1') }}</p>
        <p class="text-sm text-red-700 dark:text-red-300">{{ t('account.delete_account.warning_line2') }}</p>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          {{ t('account.delete_account.type_email', { email: userEmail }) }}
        </label>
        <InputText
          v-model="form.email_confirmation"
          class="w-full"
          data-testid="input-email-confirmation"
        />
        <p
          v-if="form.email_confirmation && !emailMatches"
          class="text-xs text-red-600 mt-1"
          data-testid="email-mismatch"
        >
          {{ t('account.delete_account.email_mismatch') }}
        </p>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('account.delete_account.password') }}</label>
        <Password
          v-model="form.password"
          :feedback="false"
          toggleMask
          inputClass="w-full"
          class="w-full"
          data-testid="input-delete-password"
        />
      </div>

      <p v-if="clientError" class="text-sm text-red-600" data-testid="delete-account-error">{{ clientError }}</p>

      <div class="flex justify-end gap-2 pt-2">
        <Button type="button" :label="t('common.cancel')" severity="secondary" @click="handleClose" />
        <Button
          type="submit"
          :label="t('account.delete_account.confirm_button')"
          severity="danger"
          :loading="submitting"
          :disabled="!canSubmit"
          data-testid="submit-delete-account"
        />
      </div>
    </form>
  </Dialog>
</template>
