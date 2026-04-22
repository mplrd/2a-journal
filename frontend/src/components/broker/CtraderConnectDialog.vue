<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { brokerSyncService } from '@/services/brokerSync'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import Message from 'primevue/message'

const { t } = useI18n()

const props = defineProps({
  visible: { type: Boolean, default: false },
  account: { type: Object, default: null },
})

const emit = defineEmits(['update:visible', 'connected'])

const clientId = ref('')
const clientSecret = ref('')
const accessToken = ref('')
const accountIdCtrader = ref('')
const loading = ref(false)
const error = ref(null)

async function connect() {
  if (!accessToken.value || !accountIdCtrader.value || !clientId.value || !clientSecret.value) return
  loading.value = true
  error.value = null
  try {
    await brokerSyncService.createCtraderConnection(
      props.account.id,
      clientId.value,
      clientSecret.value,
      accessToken.value,
      accountIdCtrader.value,
    )
    clientId.value = ''
    clientSecret.value = ''
    accessToken.value = ''
    accountIdCtrader.value = ''
    emit('connected')
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
    :header="t('broker.connect_ctrader')"
    modal
    class="w-full max-w-lg"
  >
    <div class="space-y-4">
      <p class="text-sm text-gray-500">{{ t('broker.ctrader_instructions') }}</p>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.ctrader_client_id') }}</label>
        <InputText v-model="clientId" class="w-full" :placeholder="t('broker.ctrader_client_id_placeholder')" />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.ctrader_client_secret') }}</label>
        <InputText v-model="clientSecret" class="w-full" type="password" :placeholder="t('broker.ctrader_client_secret_placeholder')" />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.ctrader_access_token') }}</label>
        <InputText v-model="accessToken" class="w-full" type="password" :placeholder="t('broker.ctrader_access_token_placeholder')" />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.ctrader_account_id') }}</label>
        <InputText v-model="accountIdCtrader" class="w-full" :placeholder="t('broker.ctrader_account_id_placeholder')" />
      </div>

      <Message v-if="error" severity="error" :closable="false">{{ t(error) }}</Message>

      <div class="flex justify-end gap-2 pt-2">
        <Button :label="t('common.cancel')" severity="secondary" text @click="$emit('update:visible', false)" />
        <Button :label="t('broker.connect')" icon="pi pi-check" :loading="loading" :disabled="!clientId || !clientSecret || !accessToken || !accountIdCtrader" @click="connect" />
      </div>
    </div>
  </Dialog>
</template>
