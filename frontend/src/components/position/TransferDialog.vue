<script setup>
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import Button from 'primevue/button'

const { t } = useI18n()

const props = defineProps({
  visible: Boolean,
  position: { type: Object, default: null },
  accounts: { type: Array, default: () => [] },
  loading: Boolean,
})

const emit = defineEmits(['update:visible', 'transfer'])

const selectedAccountId = ref(null)

const availableAccounts = computed(() => {
  if (!props.position) return props.accounts
  return props.accounts
    .filter((a) => a.id !== props.position.account_id)
    .map((a) => ({ label: a.name, value: a.id }))
})

watch(
  () => props.visible,
  (val) => {
    if (val) {
      selectedAccountId.value = null
    }
  },
)

function handleTransfer() {
  if (selectedAccountId.value) {
    emit('transfer', selectedAccountId.value)
  }
}

function handleClose() {
  emit('update:visible', false)
}
</script>

<template>
  <Dialog
    :visible="visible"
    :header="t('positions.transfer')"
    :modal="true"
    :closable="true"
    :style="{ width: '400px' }"
    @update:visible="handleClose"
  >
    <div class="flex flex-col gap-4">
      <p class="text-sm text-gray-600">
        {{ t('positions.transfer_description') }}
      </p>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ t('positions.target_account') }}</label>
        <Select
          v-model="selectedAccountId"
          :options="availableAccounts"
          optionLabel="label"
          optionValue="value"
          :placeholder="t('positions.select_account')"
          class="w-full"
        />
      </div>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" @click="handleClose" />
      <Button :label="t('positions.transfer')" :loading="loading" :disabled="!selectedAccountId" @click="handleTransfer" />
    </template>
  </Dialog>
</template>
