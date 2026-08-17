<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import ToggleButton from 'primevue/togglebutton'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import FieldHelpIcon from '@/components/common/FieldHelpIcon.vue'
import SymbolForm from '@/components/symbol/SymbolForm.vue'
import { plansService } from '@/services/plans'
import { useSymbolsStore } from '@/stores/symbols'
import { useAccountsStore } from '@/stores/accounts'
import { useSymbolAccountSettingsStore } from '@/stores/symbolAccountSettings'
import { blankPlanForm, planToForm, formToPayload, isPlanFormValid, pointValueSummary } from '@/utils/planForm'
import { formatZoneRange, formatWindowTime, daysMaskToLabel } from '@/utils/planDisplay'
import { useNumberLocale } from '@/composables/useNumberLocale'

// Cap chips per group so a plan with many zones/windows can't blow up a row.
const MAX_SUMMARY_CHIPS = 4

const { t, locale } = useI18n()
const { numberLocale } = useNumberLocale()
const toast = useToast()
const confirm = useConfirm()
const symbolsStore = useSymbolsStore()
const accountsStore = useAccountsStore()
const settingsStore = useSymbolAccountSettingsStore()

const plans = ref([])
const loading = ref(false)

const showEditor = ref(false)
const showSymbolForm = ref(false)
const saving = ref(false)
const form = ref(blankPlanForm())

// A curated set of IANA zones; the plan's own value is injected if it differs.
const BASE_TZ = ['UTC', 'Europe/Paris', 'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Chicago', 'Asia/Tokyo', 'Asia/Shanghai', 'Australia/Sydney']

const directionOptions = computed(() => [
  { label: t('plan.direction.both'), value: null },
  { label: t('common.buy'), value: 'BUY' },
  { label: t('common.sell'), value: 'SELL' },
])

const zoneDirectionOptions = computed(() => [
  { label: t('common.buy'), value: 'BUY' },
  { label: t('common.sell'), value: 'SELL' },
])

// The asset the plan targets. A zone is a pair of bare prices, so it only means
// something once the asset is named — hence no "every asset" entry: a plan that
// filters nothing by market is the hole this field closes. Plans stored before
// it exist without one; editing such a plan asks for it.
//
// Same store, same option shape (label = name, value = code) as the trade and
// order forms, so the picker reads identically wherever an asset is chosen.
const symbolOptions = computed(() => symbolsStore.symbolOptions)

const timezoneOptions = computed(() => {
  const set = new Set(BASE_TZ)
  if (form.value.timezone) set.add(form.value.timezone)
  return [...set].map((tz) => ({ label: tz, value: tz }))
})

// Monday-first short weekday names in the active locale (2024-01-01 = Monday).
const dayLabels = computed(() => {
  const fmt = new Intl.DateTimeFormat(locale.value, { weekday: 'short' })
  return [0, 1, 2, 3, 4, 5, 6].map((i) => fmt.format(new Date(Date.UTC(2024, 0, 1 + i))))
})

const canSave = computed(() => isPlanFormValid(form.value))

function directionLabel(value) {
  if (!value) return t('plan.direction.both')
  return t(value === 'BUY' ? 'common.buy' : 'common.sell')
}

// Readable per-plan summaries for the list (e.g. "Achat 24000–24400",
// "Lun–Ven 09:00–17:30"), so a plan is recognizable without opening the editor.
function zoneSummary(plan) {
  // Colour + arrow each zone by its side, matching the app's long/short
  // convention (BUY → green ↑, SELL → red ↓). The arrow replaces the word.
  return (plan.zones ?? []).map((z) => ({
    text: formatZoneRange(z),
    severity: z.direction === 'BUY' ? 'success' : 'danger',
    icon: z.direction === 'BUY' ? 'pi pi-arrow-up' : 'pi pi-arrow-down',
  }))
}
function windowSummary(plan) {
  const allDays = t('plan.window.all_days')
  return (plan.windows ?? []).map((w) => `${daysMaskToLabel(w.days_mask, dayLabels.value, allDays)} ${formatWindowTime(w)}`.trim())
}
// "No filter" only holds when none of them is set — the list grew past what an
// inline condition could carry without one of them being forgotten.
function hasFilter(plan) {
  return Boolean(plan.symbol)
    || Boolean(plan.zones?.length)
    || Boolean(plan.windows?.length)
    || plan.max_risk_percent != null
    || plan.max_plan_risk_percent != null
}
function capped(list) {
  return { shown: list.slice(0, MAX_SUMMARY_CHIPS), extra: Math.max(0, list.length - MAX_SUMMARY_CHIPS) }
}
// What a zone is for, then the bound rule — the surprise ("I typed 24400 then
// 24000 and it flipped") lands on the same field, so it belongs in the same
// help bubble rather than a second icon beside the first.
const zonesHelp = computed(() => `${t('plan.zones_hint')} ${t('plan.zone_bounds_hint')}`)

// The form holds the asset CODE (that is what the API takes); the point value
// hangs off the row.
const selectedSymbol = computed(
  () => (symbolsStore.symbols ?? []).find((s) => s.code === form.value.symbol) ?? null,
)

// Read-only, and deliberately so: the plan has no account, so there is no one
// cell to edit here. What it can do is say what its caps resolve to.
const pointValues = computed(() => pointValueSummary(
  selectedSymbol.value,
  accountsStore.accounts ?? [],
  settingsStore.getPointValue,
))

function formatPointValue(value) {
  return Number(value).toLocaleString(numberLocale.value, { maximumFractionDigits: 5 })
}

const pointValueLine = computed(() => {
  const summary = pointValues.value
  if (!summary) return null
  const asset = selectedSymbol.value.name || selectedSymbol.value.code
  if (summary.uniform) {
    return t('plan.point_value_uniform', { asset, value: formatPointValue(summary.value) })
  }
  const list = summary.entries
    .map((e) => t('plan.point_value_entry', { value: formatPointValue(e.value), account: e.accountName }))
    .join(' · ')
  return t('plan.point_value_varies', { asset, list })
})

async function load() {
  loading.value = true
  try {
    const resp = await plansService.list()
    plans.value = resp.data ?? []
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err?.messageKey ?? 'error.internal'), life: 4000 })
  } finally {
    loading.value = false
  }

  // The assets only feed the asset picker. Losing them must not cost the user
  // their plan list, so this failure stays quiet — the editor then says it has
  // nothing to offer rather than showing an unexplained empty list, and the
  // "+" button still lets one be created on the spot.
  try {
    await symbolsStore.fetchSymbols()
  } catch {
    // handled in the store; the picker simply stays empty
  }

  // Same rule for what the risk caps resolve to: informative, never load-bearing.
  // Missing either one just hides the line.
  try {
    await Promise.all([
      accountsStore.accounts?.length ? Promise.resolve() : accountsStore.fetchAccounts(),
      settingsStore.fetchMatrix(),
    ])
  } catch {
    // the point-value line simply stays hidden
  }
}

// Create an asset without leaving the plan, exactly as the trade form does:
// realising mid-plan that the asset is missing should not send the user to
// another screen and lose what they were typing.
async function handleSymbolCreate(data) {
  try {
    const created = await symbolsStore.createSymbol(data)
    form.value.symbol = created.code
    showSymbolForm.value = false
    toast.add({ severity: 'success', summary: t('symbols.success.created'), life: 2500 })
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err?.messageKey ?? 'error.internal'), life: 4000 })
  }
}

function openCreate() {
  form.value = blankPlanForm()
  showEditor.value = true
}

function openEdit(plan) {
  form.value = planToForm(plan)
  showEditor.value = true
}

function addZone() {
  form.value.zones.push({ direction: 'BUY', low_price: null, high_price: null })
}
function removeZone(index) {
  form.value.zones.splice(index, 1)
}

function addWindow() {
  form.value.windows.push({ days: [true, true, true, true, true, false, false], start_time: '09:00', end_time: '17:30' })
}
function removeWindow(index) {
  form.value.windows.splice(index, 1)
}

async function save() {
  if (!canSave.value) return
  saving.value = true
  try {
    const payload = formToPayload(form.value)
    if (form.value.id) {
      await plansService.update(form.value.id, payload)
      toast.add({ severity: 'success', summary: t('plan.success.updated'), life: 2500 })
    } else {
      await plansService.create(payload)
      toast.add({ severity: 'success', summary: t('plan.success.created'), life: 2500 })
    }
    showEditor.value = false
    await load()
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err?.messageKey ?? 'error.internal'), life: 4000 })
  } finally {
    saving.value = false
  }
}

function askArchive(plan) {
  confirm.require({
    message: t('plan.archive_confirm'),
    header: plan.name,
    icon: 'pi pi-exclamation-triangle',
    rejectProps: { label: t('common.cancel'), severity: 'secondary', outlined: true },
    acceptProps: { label: t('plan.actions.archive'), severity: 'danger' },
    accept: () => archive(plan),
  })
}

async function archive(plan) {
  try {
    await plansService.archive(plan.id)
    toast.add({ severity: 'info', summary: t('plan.success.archived'), life: 2500 })
    await load()
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err?.messageKey ?? 'error.internal'), life: 4000 })
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-4">
      <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('plan.subtitle') }}</p>
      <Button icon="pi pi-plus" :label="t('plan.new')" data-testid="new-plan-button" @click="openCreate" />
    </div>

    <div
      v-if="!loading && plans.length === 0"
      class="text-center py-16 text-gray-400"
      data-testid="plans-empty"
    >
      <i class="pi pi-compass text-4xl mb-3 block"></i>
      <p>{{ t('plan.empty') }}</p>
    </div>

    <DataTable v-else :value="plans" :loading="loading" data-key="id" row-hover stripedRows data-testid="plans-table">
      <Column field="name" :header="t('plan.field.name')">
        <template #body="{ data }"><span class="font-medium">{{ data.name }}</span></template>
      </Column>
      <Column :header="t('plan.field.direction')">
        <template #body="{ data }">{{ directionLabel(data.allowed_direction) }}</template>
      </Column>
      <Column :header="t('plan.field.filters')">
        <template #body="{ data }">
          <div class="flex flex-col gap-1 items-start">
            <Tag
              v-if="data.symbol"
              :value="data.symbol"
              severity="info"
              icon="pi pi-bookmark"
              data-testid="plan-symbol-tag"
            />
            <div v-if="data.zones?.length" class="flex flex-wrap gap-1" data-testid="plan-zone-summary">
              <Tag v-for="(z, i) in capped(zoneSummary(data)).shown" :key="'z' + i" :value="z.text" :severity="z.severity" :icon="z.icon" />
              <Tag v-if="capped(zoneSummary(data)).extra" :value="t('plan.summary.more', { count: capped(zoneSummary(data)).extra })" severity="secondary" />
            </div>
            <div v-if="data.windows?.length" class="flex flex-wrap gap-1" data-testid="plan-window-summary">
              <Tag v-for="(w, i) in capped(windowSummary(data)).shown" :key="'w' + i" :value="w" severity="contrast" />
              <Tag v-if="capped(windowSummary(data)).extra" :value="t('plan.summary.more', { count: capped(windowSummary(data)).extra })" severity="secondary" />
            </div>
            <Tag v-if="data.max_risk_percent != null" :value="t('plan.tag.risk', { pct: Number(data.max_risk_percent) })" severity="warn" />
            <Tag
              v-if="data.max_plan_risk_percent != null"
              :value="t('plan.tag.plan_risk', { pct: Number(data.max_plan_risk_percent) })"
              severity="warn"
              data-testid="plan-plan-risk-tag"
            />
            <span v-if="!hasFilter(data)" class="text-xs text-gray-400">{{ t('plan.tag.none') }}</span>
          </div>
        </template>
      </Column>
      <Column :header="t('plan.field.usage')">
        <template #body="{ data }">
          <span class="text-xs">{{ t('plan.used_by', { count: data.robot_count ?? 0 }) }}</span>
        </template>
      </Column>
      <Column :header="t('common.actions')">
        <template #body="{ data }">
          <div class="flex gap-1">
            <Button icon="pi pi-pencil" size="small" text v-tooltip.top="t('common.edit')" data-testid="plan-edit" @click="openEdit(data)" />
            <Button icon="pi pi-trash" size="small" text severity="danger" v-tooltip.top="t('plan.actions.archive')" data-testid="plan-archive" @click="askArchive(data)" />
          </div>
        </template>
      </Column>
    </DataTable>

    <!-- Create / edit editor -->
    <Dialog v-model:visible="showEditor" :header="form.id ? t('plan.edit') : t('plan.new')" modal class="w-full max-w-3xl" data-testid="plan-editor">
      <div class="flex flex-col gap-5">
        <!-- Basics -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="md:col-span-2">
            <label class="block text-sm font-medium mb-1">{{ t('plan.field.name') }}</label>
            <InputText v-model="form.name" class="w-full" maxlength="120" :placeholder="t('plan.name_placeholder')" data-testid="plan-name-input" />
          </div>
          <div>
            <label class="flex items-center gap-1 text-sm font-medium mb-1">
              {{ t('plan.field.symbol') }}
              <FieldHelpIcon :text="t('plan.symbol_hint')" testid="plan-symbol-help" />
            </label>
            <div class="flex gap-1">
              <Select
                v-model="form.symbol"
                :options="symbolOptions"
                option-label="label"
                option-value="value"
                class="w-full"
                :placeholder="t('plan.symbol_placeholder')"
                :empty-message="t('plan.no_symbols')"
                data-testid="plan-symbol-select"
              />
              <Button
                icon="pi pi-plus"
                severity="secondary"
                size="small"
                v-tooltip.top="t('symbols.add_symbol')"
                data-testid="plan-add-symbol"
                @click="showSymbolForm = true"
              />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">{{ t('plan.field.direction') }}</label>
            <Select v-model="form.allowed_direction" :options="directionOptions" option-label="label" option-value="value" class="w-full" data-testid="plan-direction-select" />
          </div>
        </div>

        <!-- Price zones -->
        <div>
          <div class="flex items-center justify-between mb-2">
            <label class="flex items-center gap-1 text-sm font-medium">
              {{ t('plan.field.zones') }}
              <FieldHelpIcon :text="zonesHelp" testid="plan-zones-help" />
            </label>
            <Button icon="pi pi-plus" :label="t('plan.add_zone')" size="small" text data-testid="plan-add-zone" @click="addZone" />
          </div>
          <div v-for="(zone, i) in form.zones" :key="i" class="flex items-center gap-2 mb-2" data-testid="plan-zone-row">
            <Select v-model="zone.direction" :options="zoneDirectionOptions" option-label="label" option-value="value" class="w-28" />
            <InputNumber v-model="zone.low_price" :min="0" :maxFractionDigits="5" class="flex-1" :placeholder="t('plan.zone_low')" />
            <span class="text-gray-400">–</span>
            <InputNumber v-model="zone.high_price" :min="0" :maxFractionDigits="5" class="flex-1" :placeholder="t('plan.zone_high')" />
            <Button icon="pi pi-times" size="small" text severity="danger" @click="removeZone(i)" />
          </div>
        </div>

        <!-- Time windows -->
        <div>
          <div class="flex items-center justify-between mb-2">
            <label class="flex items-center gap-1 text-sm font-medium">
              {{ t('plan.field.windows') }}
              <FieldHelpIcon :text="t('plan.windows_hint')" testid="plan-windows-help" />
            </label>
            <Button icon="pi pi-plus" :label="t('plan.add_window')" size="small" text data-testid="plan-add-window" @click="addWindow" />
          </div>
          <div v-if="form.windows.length" class="mb-3">
            <label class="block text-xs font-medium mb-1">{{ t('plan.field.timezone') }}</label>
            <Select v-model="form.timezone" :options="timezoneOptions" option-label="label" option-value="value" class="w-full md:w-64" filter data-testid="plan-timezone-select" />
          </div>
          <div v-for="(win, i) in form.windows" :key="i" class="flex flex-wrap items-center gap-2 mb-2" data-testid="plan-window-row">
            <div class="flex gap-1">
              <ToggleButton
                v-for="(label, d) in dayLabels"
                :key="d"
                v-model="win.days[d]"
                :onLabel="label"
                :offLabel="label"
                class="!px-2 !py-1 text-xs"
              />
            </div>
            <InputText v-model="win.start_time" class="w-20" placeholder="09:00" />
            <span class="text-gray-400">→</span>
            <InputText v-model="win.end_time" class="w-20" placeholder="17:30" />
            <Button icon="pi pi-times" size="small" text severity="danger" @click="removeWindow(i)" />
          </div>
        </div>

        <!-- Max risk: per signal, then over everything the plan still carries -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="flex items-center gap-1 text-sm font-medium mb-1">
              {{ t('plan.field.max_risk') }}
              <FieldHelpIcon :text="t('plan.max_risk_hint')" testid="plan-risk-help" />
            </label>
            <InputNumber v-model="form.max_risk_percent" :min="0" :maxFractionDigits="3" suffix=" %" class="w-full" :placeholder="t('plan.max_risk_placeholder')" data-testid="plan-risk-input" />
          </div>
          <div>
            <label class="flex items-center gap-1 text-sm font-medium mb-1">
              {{ t('plan.field.max_plan_risk') }}
              <FieldHelpIcon :text="t('plan.max_plan_risk_hint')" testid="plan-plan-risk-help" />
            </label>
            <InputNumber v-model="form.max_plan_risk_percent" :min="0" :maxFractionDigits="3" suffix=" %" class="w-full" :placeholder="t('plan.max_plan_risk_placeholder')" data-testid="plan-plan-risk-input" />
          </div>
        </div>

        <!-- Both caps are a percentage of capital, converted to money by
             point_value(asset, account) — and this plan has no account. Showing
             what it resolves to per account is the only honest thing this
             screen can say about it; editing belongs to My assets. -->
        <p v-if="pointValueLine" class="text-xs text-gray-400" data-testid="plan-point-value-line">
          {{ pointValueLine }}
        </p>
      </div>

      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" @click="showEditor = false" />
        <Button :label="t('common.save')" :loading="saving" :disabled="!canSave" data-testid="plan-save" @click="save" />
      </template>

      <SymbolForm
        v-model:visible="showSymbolForm"
        :loading="symbolsStore.loading"
        @save="handleSymbolCreate"
      />
    </Dialog>
  </div>
</template>
