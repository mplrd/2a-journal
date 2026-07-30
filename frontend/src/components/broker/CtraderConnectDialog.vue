<script setup>
import { computed, ref, toRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { brokerSyncService } from '@/services/brokerSync'
import { useBrokerCredentialForm } from '@/composables/useBrokerCredentialForm'
import { CtraderEnvironment } from '@/constants/enums'
import FieldHelpIcon from '@/components/common/FieldHelpIcon.vue'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
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

const discoveredAccounts = ref([])
const discovering = ref(false)
const discoveryError = ref(null)

const environmentOptions = computed(() => [
  { label: t('broker.ctrader_env_live'), value: CtraderEnvironment.LIVE },
  { label: t('broker.ctrader_env_demo'), value: CtraderEnvironment.DEMO },
])

/** In reconfigure mode a blank secret input keeps the stored value. */
const secretPlaceholder = (createKey) =>
  isEditing.value ? t('broker.credential_unchanged_placeholder') : t(createKey)

/**
 * Account discovery needs the three app credentials. While reconfiguring they
 * may already all be stored, so the button stays enabled and the backend reads
 * them from the connection.
 */
const canDiscover = computed(() => {
  if (isEditing.value) return true
  return ['client_id', 'client_secret', 'access_token'].every(
    (f) => (values.value[f] ?? '').trim() !== '',
  )
})

/**
 * Options labelled by traderLogin — the number the cTrader platform actually
 * shows. The value is ctidTraderAccountId, which is what the API authenticates
 * with and what we store.
 */
const accountOptions = computed(() =>
  discoveredAccounts.value.map((a) => ({
    label: `${a.trader_login || a.ctid_trader_account_id} — ${
      a.is_live ? t('broker.ctrader_env_live') : t('broker.ctrader_env_demo')
    }`,
    value: String(a.ctid_trader_account_id),
  })),
)

async function loadAccounts() {
  if (!canDiscover.value) return
  discovering.value = true
  discoveryError.value = null
  try {
    const payload = isEditing.value
      ? { connection_id: props.connection.id, ...changed.value }
      : {
          client_id: values.value.client_id,
          client_secret: values.value.client_secret,
          access_token: values.value.access_token,
          environment: values.value.environment,
        }

    const resp = await brokerSyncService.fetchCtraderAccounts(payload)
    discoveredAccounts.value = resp.data.accounts || []
    discoveryError.value = resp.data.error || null

    if (!discoveryError.value && discoveredAccounts.value.length === 0) {
      discoveryError.value = t('broker.ctrader_no_accounts')
    }
    // A single account needs no choosing.
    if (discoveredAccounts.value.length === 1) {
      selectAccount(String(discoveredAccounts.value[0].ctid_trader_account_id))
    }
  } catch (err) {
    discoveryError.value = err.messageKey ? t(err.messageKey) : err.message
  } finally {
    discovering.value = false
  }
}

/**
 * Picking an account fills the id AND the server: isLive comes from the same
 * response, so the user never has to work out which of the two cTrader servers
 * their account lives on — a mismatch there produces the same "account not
 * found" as a wrong id.
 */
function selectAccount(ctidTraderAccountId) {
  values.value.account_id_ctrader = ctidTraderAccountId
  const picked = discoveredAccounts.value.find(
    (a) => String(a.ctid_trader_account_id) === ctidTraderAccountId,
  )
  if (picked) {
    values.value.environment = picked.is_live ? CtraderEnvironment.LIVE : CtraderEnvironment.DEMO
  }
}

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
    discoveredAccounts.value = []
    discoveryError.value = null
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
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          {{ t('broker.ctrader_client_id') }}
          <FieldHelpIcon :text="t('broker.ctrader_client_id_help')" testid="ctrader-client-id-help" />
        </label>
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
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          {{ t('broker.ctrader_client_secret') }}
          <FieldHelpIcon :text="t('broker.ctrader_client_secret_help')" testid="ctrader-client-secret-help" />
        </label>
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
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
          {{ t('broker.ctrader_access_token') }}
          <FieldHelpIcon :text="t('broker.ctrader_access_token_help')" testid="ctrader-access-token-help" />
        </label>
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

      <!-- Account selection. The user must never have to know their
           ctidTraderAccountId: the cTrader platform never displays it (it shows
           traderLogin instead), so we look it up from the access token and let
           them pick by the login they recognise. -->
      <div class="rounded-md border border-gray-200 dark:border-gray-700 p-3 space-y-3">
        <div class="flex items-center justify-between gap-2 flex-wrap">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ t('broker.ctrader_account') }}
            <FieldHelpIcon :text="t('broker.ctrader_account_id_help')" testid="ctrader-account-id-help" />
          </label>
          <Button
            :label="t('broker.ctrader_load_accounts')"
            icon="pi pi-search"
            size="small"
            severity="secondary"
            data-testid="ctrader-load-accounts"
            :loading="discovering"
            :disabled="!canDiscover"
            @click="loadAccounts"
          />
        </div>

        <Select
          v-if="accountOptions.length"
          :model-value="values.account_id_ctrader"
          @update:model-value="selectAccount"
          :options="accountOptions"
          option-label="label"
          option-value="value"
          class="w-full"
          name="ctrader-account-picker"
          :placeholder="t('broker.ctrader_account_pick')"
        />

        <Message v-if="discoveryError" severity="warn" :closable="false" class="text-sm">
          {{ discoveryError }}
        </Message>

        <!-- Manual fallback: the value can still be typed if discovery is
             unavailable. -->
        <div>
          <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ t('broker.ctrader_account_id') }}</label>
          <InputText
            v-model="values.account_id_ctrader"
            class="w-full"
            :placeholder="t('broker.ctrader_account_id_placeholder')"
            autocomplete="off"
            name="ctrader-account-id"
            spellcheck="false"
          />
        </div>

        <div>
          <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">
            {{ t('broker.ctrader_environment') }}
            <FieldHelpIcon :text="t('broker.ctrader_environment_help')" testid="ctrader-environment-help" />
          </label>
          <SelectButton
            v-model="values.environment"
            :options="environmentOptions"
            option-label="label"
            option-value="value"
            :allow-empty="false"
            name="ctrader-environment"
          />
        </div>
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
