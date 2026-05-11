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

const apiKey = ref('')
const apiSecret = ref('')
const loading = ref(false)
const error = ref(null)

async function connect() {
  if (!apiKey.value || !apiSecret.value || !props.account) return
  loading.value = true
  error.value = null
  try {
    await brokerSyncService.createOuinexConnection(
      props.account.id,
      apiKey.value,
      apiSecret.value,
    )
    apiKey.value = ''
    apiSecret.value = ''
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
    :header="t('broker.connect_ouinex')"
    modal
    class="w-full max-w-lg"
  >
    <div class="space-y-4">
      <p class="text-sm text-gray-500">{{ t('broker.ouinex_instructions') }}</p>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.ouinex_api_key') }}</label>
        <InputText v-model="apiKey" class="w-full" :placeholder="t('broker.ouinex_api_key_placeholder')" />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ t('broker.ouinex_api_secret') }}</label>
        <InputText v-model="apiSecret" class="w-full" type="password" :placeholder="t('broker.ouinex_api_secret_placeholder')" />
      </div>

      <Message v-if="error" severity="error" :closable="false">{{ t(error) }}</Message>

      <div class="flex justify-end gap-2 pt-2">
        <Button :label="t('common.cancel')" severity="secondary" text @click="$emit('update:visible', false)" />
        <Button :label="t('broker.connect')" icon="pi pi-check" :loading="loading" :disabled="!apiKey || !apiSecret" @click="connect" />
      </div>
    </div>
  </Dialog>
</template>
