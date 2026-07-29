<script setup>
import { ref, toRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { brokerSyncService } from '@/services/brokerSync'
import { useBrokerCredentialForm } from '@/composables/useBrokerCredentialForm'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Message from 'primevue/message'

const { t } = useI18n()

const props = defineProps({
  visible: { type: Boolean, default: false },
  account: { type: Object, default: null },
  // Existing connection → reconfigure mode. Null → create mode.
  connection: { type: Object, default: null },
})

const emit = defineEmits(['update:visible', 'connected'])

const { values, isEditing, canSubmit, changed, full, reset } = useBrokerCredentialForm({
  connection: toRef(props, 'connection'),
  publicFields: ['metaapi_account_id'],
  secretFields: ['api_token'],
})

const loading = ref(false)
const error = ref(null)

const secretPlaceholder = (createKey) =>
  isEditing.value ? t('broker.credential_unchanged_placeholder') : t(createKey)

async function submit() {
  if (!canSubmit.value || (!isEditing.value && !props.account)) return
  loading.value = true
  error.value = null
  try {
    const response = isEditing.value
      ? await brokerSyncService.updateConnection(props.connection.id, changed.value)
      : await brokerSyncService.createMetaApiConnection(
          props.account.id,
          full.value.api_token,
          full.value.metaapi_account_id,
        )

    // Clear the form so reopening the dialog never shows stale input.
    reset()
    emit('connected', response.data)
  } catch (err) {
    error.value = err.messageKey || err.message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Dialog
    :visible="visible"
    @update:visible="$emit('update:visible', $event)"
    :header="isEditing ? t('broker.reconfigure_provider', { provider: 'MetaApi' }) : t('broker.connect_metaapi')"
    modal
    class="w-full max-w-lg"
  >
    <div class="space-y-4">
      <p class="text-sm text-gray-500">
        {{ isEditing ? t('broker.reconfigure_instructions') : t('broker.metaapi_instructions') }}
      </p>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.metaapi_token') }}</label>
        <InputText
          v-model="values.api_token"
          class="w-full"
          :placeholder="secretPlaceholder('broker.metaapi_token_placeholder')"
          autocomplete="new-password"
          name="metaapi-api-token"
          spellcheck="false"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.metaapi_account_id') }}</label>
        <InputText
          v-model="values.metaapi_account_id"
          class="w-full"
          :placeholder="t('broker.metaapi_account_id_placeholder')"
          autocomplete="off"
          name="metaapi-account-id"
          spellcheck="false"
        />
      </div>

      <Message v-if="error" severity="error" :closable="false">{{ t(error) }}</Message>

      <div class="flex justify-end gap-2 pt-2">
        <Button :label="t('common.cancel')" severity="secondary" text @click="$emit('update:visible', false)" />
        <Button
          :label="isEditing ? t('common.save') : t('broker.connect')"
          icon="pi pi-check"
          data-testid="metaapi-submit"
          :loading="loading"
          :disabled="!canSubmit"
          @click="submit"
        />
      </div>
    </div>
  </Dialog>
</template>
