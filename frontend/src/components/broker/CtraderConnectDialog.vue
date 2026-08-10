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

const { t, locale } = useI18n()

const props = defineProps({
  visible: { type: Boolean, default: false },
  account: { type: Object, default: null },
  // Existing connection → reconfigure mode. Null → create mode.
  connection: { type: Object, default: null },
  // The user's cTrader app credentials, if they already connected one account
  // (docs/91). In create mode they prefill this dialog; null means first
  // connection, and the form behaves exactly as it always did.
  shared: { type: Object, default: null },
})

const emit = defineEmits(['update:visible', 'connected'])

const { values, isEditing, canSubmit, changed, full, reset, isStored, storedFields, sharing } =
  useBrokerCredentialForm({
    connection: toRef(props, 'connection'),
    shared: toRef(props, 'shared'),
    publicFields: ['client_id', 'account_id_ctrader', 'environment'],
    secretFields: ['client_secret', 'access_token', 'refresh_token'],
    // Secret, but not required: a connection without one simply stops working at
    // token expiry instead of renewing itself.
    optionalFields: ['refresh_token'],
    defaults: { environment: CtraderEnvironment.LIVE },
  })

const loading = ref(false)
const error = ref(null)

/**
 * Nothing left to type in the app credentials — they came with the user's
 * first cTrader connection.
 */
const credentialsAreStored = computed(() => storedFields.value.length > 0)

// Folded by default in that case, because the dialog is then about one thing:
// picking the account. Everything stays on this single screen — sharing is a
// storage fact and must not cost the user a second navigation.
const showCredentials = ref(false)
const credentialsVisible = computed(() => !credentialsAreStored.value || showCredentials.value)

/**
 * How many connections these credentials feed, and — when reconfiguring more
 * than one — that editing them here reaches all of them. Without saying it,
 * changing a token from the second connection silently rewrites the first,
 * which is the one trap sharing introduces.
 */
const sharingBanner = computed(() => {
  if (!sharing.value) return null

  const feeds = t('broker.shared_credentials_feeds', { count: sharing.value.count })

  if (isEditing.value) {
    return sharing.value.count > 1 ? `${feeds} ${t('broker.shared_credentials_edit_warning')}` : null
  }

  return credentialsAreStored.value ? `${t('broker.shared_credentials_stored')} ${feeds}` : null
})

const discoveredAccounts = ref([])
const discovering = ref(false)
const discoveryError = ref(null)

const environmentOptions = computed(() => [
  { label: t('broker.ctrader_env_live'), value: CtraderEnvironment.LIVE },
  { label: t('broker.ctrader_env_demo'), value: CtraderEnvironment.DEMO },
])

/**
 * A blank secret input keeps the stored value — while reconfiguring, and now
 * also while creating a second connection on already-stored app credentials.
 * The two cases read differently to the user, hence two placeholders.
 */
const secretPlaceholder = (field, createKey) => {
  if (isEditing.value) return t('broker.credential_unchanged_placeholder')
  if (isStored(field)) return t('broker.credential_stored_placeholder')
  return t(createKey)
}

/**
 * Account discovery needs the three app credentials. They may already all be
 * stored — on the connection when reconfiguring, or on the user when this is a
 * second connection — so the button stays enabled and the backend reads them.
 */
const canDiscover = computed(() => {
  if (isEditing.value) return true
  return ['client_id', 'client_secret', 'access_token'].every(
    (f) => (values.value[f] ?? '').trim() !== '' || isStored(f),
  )
})

/**
 * Options labelled by traderLogin — the number the cTrader platform actually
 * shows. The value is ctidTraderAccountId, which is what the API authenticates
 * with and what we store.
 */
/**
 * Compact identification, rounded on purpose: "FTMO — 80 000 € — 1234567 —
 * Live". Several accounts at the same prop firm all carry the same broker
 * name, so the size is what actually tells them apart. The exact figures stay
 * in the details panel below — this line is for recognising an account, not
 * for reading its balance to the cent.
 */
function formatBalance(account) {
  if (account.balance == null) return null
  try {
    return new Intl.NumberFormat(locale.value, {
      ...(account.currency ? { style: 'currency', currency: account.currency } : {}),
      maximumFractionDigits: 0,
    }).format(account.balance)
  } catch {
    // An unexpected currency code must not take the whole picker down.
    return String(account.balance)
  }
}

/**
 * Only the accounts that can actually be connected. The broker refused the
 * others during discovery (archived accounts fail account auth with
 * RET_ACCOUNT_DISABLED), and an option that cannot be chosen is just noise.
 */
const selectableAccounts = computed(() => discoveredAccounts.value.filter((a) => !a.is_disabled))

const accountOptions = computed(() =>
  selectableAccounts.value.map((a) => ({
    label: [
      a.broker_name,
      formatBalance(a),
      a.trader_login || a.ctid_trader_account_id,
      a.is_live ? t('broker.ctrader_env_live') : t('broker.ctrader_env_demo'),
    ]
      .filter(Boolean)
      .join(' — '),
    value: String(a.ctid_trader_account_id),
  })),
)

/**
 * Hidden accounts are counted out loud. Someone who knows they have four
 * accounts and sees three would otherwise assume the lookup is broken — and if
 * every account is archived, an unexplained empty picker is the worst outcome
 * of all.
 */
const hiddenAccountCount = computed(
  () => discoveredAccounts.value.length - selectableAccounts.value.length,
)

/** The picked account, when it came from discovery rather than being typed. */
const selectedAccount = computed(() =>
  selectableAccounts.value.find(
    (a) => String(a.ctid_trader_account_id) === String(values.value.account_id_ctrader),
  ),
)

/**
 * Picking from the list fills both the id and the server, so showing either
 * input is redundant — the id in particular is a number the cTrader platform
 * never displays, and offering it for editing invites the mistake this dialog
 * was built to remove. They come back when discovery has not answered, which
 * is the only case where they have to be typed.
 */
const accountFromDiscovery = computed(() => Boolean(selectedAccount.value))

/** Everything the API returned for the picked account, for identification. */
const selectedAccountDetails = computed(() => Object.entries(selectedAccount.value?.details || {}))

// Folded away by default: fifteen key/value pairs permanently open pushed the
// actual controls off screen. Kept open across selections on purpose, so two
// accounts can be compared field by field.
const showAccountDetails = ref(false)

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

      <!-- One cTrader app, several accounts: the four credentials below belong
           to the app and are stored once for the user. This says how many
           connections they feed — and, when reconfiguring, that an edit here
           reaches every one of them. -->
      <Message
        v-if="sharingBanner"
        severity="info"
        :closable="false"
        class="text-sm"
        data-testid="ctrader-shared-banner"
      >
        {{ sharingBanner }}
      </Message>

      <!-- Folded away once stored, so a second connection is one field long.
           The inputs are still here, one click down: rotating a secret must
           not require going and finding the first connection. -->
      <div v-if="credentialsAreStored" class="flex justify-end">
        <Button
          :label="showCredentials ? t('broker.shared_credentials_hide') : t('broker.shared_credentials_show')"
          :icon="showCredentials ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
          size="small"
          severity="secondary"
          text
          data-testid="ctrader-shared-toggle"
          @click="showCredentials = !showCredentials"
        />
      </div>

      <template v-if="credentialsVisible">
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
            :placeholder="secretPlaceholder('client_secret', 'broker.ctrader_client_secret_placeholder')"
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
            :placeholder="secretPlaceholder('access_token', 'broker.ctrader_access_token_placeholder')"
            autocomplete="new-password"
            name="ctrader-access-token"
            spellcheck="false"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            {{ t('broker.ctrader_refresh_token') }}
            <FieldHelpIcon :text="t('broker.ctrader_refresh_token_help')" testid="ctrader-refresh-token-help" />
          </label>
          <InputText
            v-model="values.refresh_token"
            class="w-full"
            type="password"
            :placeholder="secretPlaceholder('refresh_token', 'broker.ctrader_refresh_token_placeholder')"
            autocomplete="new-password"
            name="ctrader-refresh-token"
            spellcheck="false"
          />
        </div>
      </template>

      <!-- Manual fallback, shown only while discovery has not answered. Both
           values come from the picked account otherwise. -->
      <template v-if="!accountFromDiscovery">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            {{ t('broker.ctrader_account_id') }}
            <FieldHelpIcon :text="t('broker.ctrader_account_id_help')" testid="ctrader-account-id-help" />
          </label>
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
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
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
      </template>

      <!-- Account lookup. The user must never have to know their
           ctidTraderAccountId: the cTrader platform never displays it (it shows
           traderLogin instead), so we look it up from the access token and let
           them pick by the login they recognise. -->
      <div
        class="rounded-md border border-gray-200 dark:border-gray-700 p-3 space-y-3"
        data-testid="ctrader-account-box"
      >
        <div class="flex items-center justify-between gap-2 flex-wrap">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ t('broker.ctrader_account') }}
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
          filter
          name="ctrader-account-picker"
          :placeholder="t('broker.ctrader_account_pick')"
        />

        <div v-if="accountOptions.length" class="flex items-start justify-between gap-2">
          <div class="text-xs text-gray-500 dark:text-gray-400 space-y-0.5">
            <p data-testid="ctrader-account-count">
              {{ t('broker.ctrader_account_count', { count: accountOptions.length }) }}
            </p>
            <p v-if="hiddenAccountCount" data-testid="ctrader-account-hidden">
              {{ t('broker.ctrader_account_hidden', { count: hiddenAccountCount }) }}
            </p>
          </div>

          <!-- Everything cTrader returned for the picked account, one click
               away. It is what lets a user tell two same-sized accounts apart,
               and the only place an undocumented field would ever show up. -->
          <button
            v-if="selectedAccountDetails.length"
            type="button"
            class="pi pi-info-circle text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-xs shrink-0"
            data-testid="ctrader-account-details-toggle"
            :aria-label="t('broker.ctrader_account_details')"
            :aria-expanded="showAccountDetails"
            @click="showAccountDetails = !showAccountDetails"
          />
        </div>

        <div
          v-if="showAccountDetails && selectedAccountDetails.length"
          class="rounded bg-gray-50 dark:bg-gray-800/50 p-2"
          data-testid="ctrader-account-details"
        >
          <p class="text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">
            {{ t('broker.ctrader_account_details') }}
          </p>
          <dl class="grid grid-cols-2 gap-x-3 gap-y-0.5 text-xs">
            <template v-for="[key, value] in selectedAccountDetails" :key="key">
              <dt class="text-gray-500 dark:text-gray-400 truncate">{{ key }}</dt>
              <dd class="text-gray-700 dark:text-gray-200 break-all">{{ value }}</dd>
            </template>
          </dl>
        </div>

        <Message v-if="discoveryError" severity="warn" :closable="false" class="text-sm">
          {{ discoveryError }}
        </Message>
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
