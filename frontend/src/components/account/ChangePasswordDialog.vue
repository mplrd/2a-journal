<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import Password from 'primevue/password'
import Button from 'primevue/button'

const { t } = useI18n()
const authStore = useAuthStore()
const toast = useToast()

const props = defineProps({
  visible: Boolean,
})
const emit = defineEmits(['update:visible'])

const form = ref({ current_password: '', new_password: '', confirm_password: '' })
const submitting = ref(false)
const clientError = ref(null)

const canSubmit = computed(() => {
  return form.value.current_password
    && form.value.new_password
    && form.value.new_password === form.value.confirm_password
})

watch(() => props.visible, (val) => {
  if (val) {
    form.value = { current_password: '', new_password: '', confirm_password: '' }
    clientError.value = null
  }
})

async function handleSubmit() {
  clientError.value = null

  if (form.value.new_password !== form.value.confirm_password) {
    clientError.value = t('account.change_password.mismatch')
    return
  }

  submitting.value = true
  try {
    await authStore.changePassword({
      current_password: form.value.current_password,
      new_password: form.value.new_password,
    })
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('auth.success.password_changed'),
      life: 3000,
    })
    emit('update:visible', false)
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
    :header="t('account.change_password.title')"
    :modal="true"
    :closable="true"
    :style="{ width: '450px' }"
    @update:visible="handleClose"
  >
    <form class="flex flex-col gap-4" @submit.prevent="handleSubmit" data-testid="change-password-form">
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('account.change_password.current_password') }}</label>
        <Password
          v-model="form.current_password"
          :feedback="false"
          toggleMask
          inputClass="w-full"
          class="w-full"
          data-testid="input-current-password"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('account.change_password.new_password') }}</label>
        <Password
          v-model="form.new_password"
          toggleMask
          inputClass="w-full"
          class="w-full"
          data-testid="input-new-password"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('account.change_password.confirm_password') }}</label>
        <Password
          v-model="form.confirm_password"
          :feedback="false"
          toggleMask
          inputClass="w-full"
          class="w-full"
          data-testid="input-confirm-password"
        />
      </div>

      <p v-if="clientError" class="text-sm text-red-600" data-testid="change-password-error">{{ clientError }}</p>

      <div class="flex justify-end gap-2 pt-2">
        <Button type="button" :label="t('common.cancel')" severity="secondary" @click="handleClose" />
        <Button
          type="submit"
          :label="t('account.change_password.submit')"
          severity="danger"
          :loading="submitting"
          :disabled="!canSubmit"
          data-testid="submit-change-password"
        />
      </div>
    </form>
  </Dialog>
</template>
