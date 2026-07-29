<script setup>
import { computed, ref, toRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { brokerSyncService } from '@/services/brokerSync'
import { useBrokerCredentialForm } from '@/composables/useBrokerCredentialForm'
import { CtraderEnvironment } from '@/constants/enums'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import SelectButton from 'primevue/selectbutton'
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
  publicFields: ['client_id', 'account_id_ctrader', 'environment'],
  secretFields: ['client_secret', 'access_token'],
  defaults: { environment: CtraderEnvironment.LIVE },
})

const loading = ref(false)
const error = ref(null)

const environmentOptions = computed(() => [
  { label: t('broker.ctrader_env_live'), value: CtraderEnvironment.LIVE },
  { label: t('broker.ctrader_env_demo'), value: CtraderEnvironment.DEMO },
])

/** In reconfigure mode a blank secret input keeps the stored value. */
const secretPlaceholder = (createKey) =>
  isEditing.value ? t('broker.credential_unchanged_placeholder') : t(createKey)

async function submit() {
  if (!canSubmit.value) return
  loading.value = true
  error.value = null
  try {
    const response = isEditing.value
      ? await brokerSyncService.updateConnection(props.connection.id, changed.value)
      : await brokerSyncService.createCtraderConnection(props.account.id, full.value)

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
    :header="isEditing ? t('broker.reconfigure_provider', { provider: 'cTrader' }) : t('broker.connect_ctrader')"
    modal
    class="w-full max-w-lg"
  >
    <div class="space-y-4">
      <p class="text-sm text-gray-500">
        {{ isEditing ? t('broker.reconfigure_instructions') : t('broker.ctrader_instructions') }}
      </p>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.ctrader_environment') }}</label>
        <SelectButton
          v-model="values.environment"
          :options="environmentOptions"
          option-label="label"
          option-value="value"
          :allow-empty="false"
          name="ctrader-environment"
        />
        <p class="mt-1 text-xs text-gray-400">{{ t('broker.ctrader_environment_help') }}</p>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.ctrader_client_id') }}</label>
        <InputText
          v-model="values.client_id"
          class="w-full"
          :placeholder="t('broker.ctrader_client_id_placeholder')"
          autocomplete="off"
          name="ctrader-client-id"
          spellcheck="false"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.ctrader_client_secret') }}</label>
        <InputText
          v-model="values.client_secret"
          class="w-full"
          type="password"
          :placeholder="secretPlaceholder('broker.ctrader_client_secret_placeholder')"
          autocomplete="new-password"
          name="ctrader-client-secret"
          spellcheck="false"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.ctrader_access_token') }}</label>
        <InputText
          v-model="values.access_token"
          class="w-full"
          type="password"
          :placeholder="secretPlaceholder('broker.ctrader_access_token_placeholder')"
          autocomplete="new-password"
          name="ctrader-access-token"
          spellcheck="false"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.ctrader_account_id') }}</label>
        <InputText
          v-model="values.account_id_ctrader"
          class="w-full"
          :placeholder="t('broker.ctrader_account_id_placeholder')"
          autocomplete="off"
          name="ctrader-account-id"
          spellcheck="false"
        />
      </div>

      <Message v-if="error" severity="error" :closable="false">{{ t(error) }}</Message>

      <div class="flex justify-end gap-2 pt-2">
        <Button :label="t('common.cancel')" severity="secondary" text @click="$emit('update:visible', false)" />
        <Button
          :label="isEditing ? t('common.save') : t('broker.connect')"
          icon="pi pi-check"
          data-testid="ctrader-submit"
          :loading="loading"
          :disabled="!canSubmit"
          @click="submit"
        />
      </div>
    </div>
  </Dialog>
</template>
