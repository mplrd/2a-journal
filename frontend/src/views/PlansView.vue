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
import { plansService } from '@/services/plans'
import { blankPlanForm, planToForm, formToPayload } from '@/utils/planForm'

const { t, locale } = useI18n()
const toast = useToast()
const confirm = useConfirm()

const plans = ref([])
const loading = ref(false)

const showEditor = ref(false)
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

const canSave = computed(() => form.value.name.trim().length > 0)

function directionLabel(value) {
  if (!value) return t('plan.direction.both')
  return t(value === 'BUY' ? 'common.buy' : 'common.sell')
}

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
          <div class="flex flex-wrap gap-1">
            <Tag v-if="data.zones.length" :value="t('plan.tag.zones', { count: data.zones.length })" severity="info" />
            <Tag v-if="data.windows.length" :value="t('plan.tag.windows', { count: data.windows.length })" severity="info" />
            <Tag v-if="data.max_risk_percent != null" :value="t('plan.tag.risk', { pct: Number(data.max_risk_percent) })" severity="warn" />
            <span v-if="!data.zones.length && !data.windows.length && data.max_risk_percent == null && !data.allowed_direction" class="text-xs text-gray-400">{{ t('plan.tag.none') }}</span>
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
          <div>
            <label class="block text-sm font-medium mb-1">{{ t('plan.field.name') }}</label>
            <InputText v-model="form.name" class="w-full" maxlength="120" :placeholder="t('plan.name_placeholder')" data-testid="plan-name-input" />
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">{{ t('plan.field.direction') }}</label>
            <Select v-model="form.allowed_direction" :options="directionOptions" option-label="label" option-value="value" class="w-full" data-testid="plan-direction-select" />
          </div>
        </div>

        <!-- Price zones -->
        <div>
          <div class="flex items-center justify-between mb-2">
            <label class="text-sm font-medium">{{ t('plan.field.zones') }}</label>
            <Button icon="pi pi-plus" :label="t('plan.add_zone')" size="small" text data-testid="plan-add-zone" @click="addZone" />
          </div>
          <p class="text-xs text-gray-400 mb-2">{{ t('plan.zones_hint') }}</p>
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
            <label class="text-sm font-medium">{{ t('plan.field.windows') }}</label>
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

        <!-- Max risk -->
        <div class="md:w-64">
          <label class="block text-sm font-medium mb-1">{{ t('plan.field.max_risk') }}</label>
          <InputNumber v-model="form.max_risk_percent" :min="0" :maxFractionDigits="3" suffix=" %" class="w-full" :placeholder="t('plan.max_risk_placeholder')" data-testid="plan-risk-input" />
          <p class="text-xs text-gray-400 mt-1">{{ t('plan.max_risk_hint') }}</p>
        </div>
      </div>

      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" @click="showEditor = false" />
        <Button :label="t('common.save')" :loading="saving" :disabled="!canSave" data-testid="plan-save" @click="save" />
      </template>
    </Dialog>
  </div>
</template>
