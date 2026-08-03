<script setup>
import { ref, toRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { brokerSyncService } from '@/services/brokerSync'
import { useBrokerCredentialForm } from '@/composables/useBrokerCredentialForm'
import FieldHelpIcon from '@/components/common/FieldHelpIcon.vue'
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
  publicFields: [],
  secretFields: ['api_key', 'api_secret'],
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
      : await brokerSyncService.createBingxConnection(props.account.id, full.value.api_key, full.value.api_secret)

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
    :header="isEditing ? t('broker.reconfigure_provider', { provider: 'BingX' }) : t('broker.connect_bingx')"
    modal
    class="w-full max-w-lg"
  >
    <div class="space-y-4">
      <p class="text-sm text-gray-500">
        {{ isEditing ? t('broker.reconfigure_instructions') : t('broker.bingx_instructions') }}
      </p>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.bingx_api_key') }}
          <FieldHelpIcon :text="t('broker.bingx_api_key_help')" testid="bingx-api-key-help" />
        </label>
        <InputText
          v-model="values.api_key"
          class="w-full"
          :placeholder="secretPlaceholder('broker.bingx_api_key_placeholder')"
          autocomplete="off"
          name="bingx-api-key"
          spellcheck="false"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.bingx_api_secret') }}
          <FieldHelpIcon :text="t('broker.bingx_api_secret_help')" testid="bingx-api-secret-help" />
        </label>
        <InputText
          v-model="values.api_secret"
          class="w-full"
          type="password"
          :placeholder="secretPlaceholder('broker.bingx_api_secret_placeholder')"
          autocomplete="new-password"
          name="bingx-api-secret"
          spellcheck="false"
        />
      </div>

      <Message v-if="error" severity="error" :closable="false">{{ t(error) }}</Message>

      <div class="flex justify-end gap-2 pt-2">
        <Button :label="t('common.cancel')" severity="secondary" text @click="$emit('update:visible', false)" />
        <Button
          :label="isEditing ? t('common.save') : t('broker.connect')"
          icon="pi pi-check"
          data-testid="bingx-submit"
          :loading="loading"
          :disabled="!canSubmit"
          @click="submit"
        />
      </div>
    </div>
  </Dialog>
</template>
