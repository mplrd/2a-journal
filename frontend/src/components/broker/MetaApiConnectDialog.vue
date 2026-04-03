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

const apiToken = ref('')
const metaApiAccountId = ref('')
const loading = ref(false)
const error = ref(null)

async function connect() {
  if (!apiToken.value || !metaApiAccountId.value || !props.account) return
  loading.value = true
  error.value = null
  try {
    await brokerSyncService.createMetaApiConnection(
      props.account.id,
      apiToken.value,
      metaApiAccountId.value,
    )
    apiToken.value = ''
    metaApiAccountId.value = ''
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
    :header="t('broker.connect_metaapi')"
    modal
    class="w-full max-w-lg"
  >
    <div class="space-y-4">
      <p class="text-sm text-gray-500">{{ t('broker.metaapi_instructions') }}</p>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.metaapi_token') }}</label>
        <InputText v-model="apiToken" class="w-full" :placeholder="t('broker.metaapi_token_placeholder')" />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.metaapi_account_id') }}</label>
        <InputText v-model="metaApiAccountId" class="w-full" :placeholder="t('broker.metaapi_account_id_placeholder')" />
      </div>

      <Message v-if="error" severity="error" :closable="false">{{ t(error) }}</Message>

      <div class="flex justify-end gap-2 pt-2">
        <Button :label="t('common.cancel')" severity="secondary" text @click="$emit('update:visible', false)" />
        <Button :label="t('broker.connect')" icon="pi pi-check" :loading="loading" :disabled="!apiToken || !metaApiAccountId" @click="connect" />
      </div>
    </div>
  </Dialog>
</template>
