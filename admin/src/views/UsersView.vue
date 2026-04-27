<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useUsersStore } from '@/stores/users'
import AdminLayout from '@/components/AdminLayout.vue'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Tag from 'primevue/tag'

const { t } = useI18n()
const toast = useToast()
const confirm = useConfirm()
const store = useUsersStore()

const search = ref('')
const status = ref('all')

const statusOptions = [
  { label: t('users.filter.all'), value: 'all' },
  { label: t('users.filter.active'), value: 'active' },
  { label: t('users.filter.suspended'), value: 'suspended' },
]

onMounted(load)

async function load() {
  store.setFilters({ search: search.value || undefined, status: status.value })
  await store.fetchUsers()
}

function isSuspended(user) {
  return user.suspended_at !== null && user.suspended_at !== undefined
}

async function handleSuspend(user) {
  try {
    await store.suspend(user.id)
    toast.add({ severity: 'success', summary: t('common.confirm'), detail: t('users.toast.suspended'), life: 3000 })
  } catch (err) {
    toast.add({ severity: 'error', summary: t('error.internal'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  }
}

async function handleUnsuspend(user) {
  try {
    await store.unsuspend(user.id)
    toast.add({ severity: 'success', summary: t('common.confirm'), detail: t('users.toast.unsuspended'), life: 3000 })
  } catch (err) {
    toast.add({ severity: 'error', summary: t('error.internal'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  }
}

async function handleResetPassword(user) {
  try {
    await store.resetPassword(user.id)
    toast.add({ severity: 'success', summary: t('common.confirm'), detail: t('users.toast.passwordResetSent'), life: 3000 })
  } catch (err) {
    toast.add({ severity: 'error', summary: t('error.internal'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  }
}

function handleDelete(user) {
  confirm.require({
    header: t('users.confirmDelete.title'),
    message: t('users.confirmDelete.message', { email: user.email }),
    acceptLabel: t('common.delete'),
    rejectLabel: t('common.cancel'),
    acceptClass: 'p-button-danger',
    accept: async () => {
      try {
        await store.remove(user.id)
        toast.add({ severity: 'success', summary: t('common.confirm'), detail: t('users.toast.deleted'), life: 3000 })
      } catch (err) {
        toast.add({ severity: 'error', summary: t('error.internal'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
      }
    },
  })
}
</script>

<template>
  <AdminLayout>
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-xl font-bold">{{ t('users.title') }}</h2>
    </div>

    <div class="flex gap-3 mb-4">
      <InputText v-model="search" :placeholder="t('users.search')" @keyup.enter="load" class="w-72" />
      <Select v-model="status" :options="statusOptions" optionLabel="label" optionValue="value" class="w-48" @change="load" />
      <Button icon="pi pi-search" :label="t('common.search')" @click="load" />
    </div>

    <DataTable :value="store.users" :loading="store.loading" stripedRows>
      <Column field="email" :header="t('users.columns.email')">
        <template #body="{ data }">
          <span>{{ data.email }}</span>
          <Tag
            v-if="data.role === 'ADMIN'"
            :value="t('users.roleBadge.admin')"
            severity="warn"
            class="ml-2"
          />
        </template>
      </Column>
      <Column :header="t('users.columns.status')">
        <template #body="{ data }">
          <Tag
            :value="isSuspended(data) ? t('users.status.suspended') : t('users.status.active')"
            :severity="isSuspended(data) ? 'danger' : 'success'"
          />
        </template>
      </Column>
      <Column field="account_count" :header="t('users.columns.accounts')" />
      <Column field="trade_count" :header="t('users.columns.trades')" />
      <Column :header="t('users.columns.lastLogin')">
        <template #body="{ data }">
          <span v-if="data.last_login_at">{{ new Date(data.last_login_at).toLocaleString() }}</span>
          <span v-else class="text-gray-400 italic">{{ t('users.columns.never') }}</span>
        </template>
      </Column>
      <Column field="created_at" :header="t('users.columns.createdAt')">
        <template #body="{ data }">
          {{ data.created_at ? new Date(data.created_at).toLocaleDateString() : '-' }}
        </template>
      </Column>
      <Column :header="t('users.columns.subscription')">
        <template #body="{ data }">
          <Tag v-if="data.subscription_status" :value="data.subscription_status" :severity="data.subscription_status === 'active' ? 'success' : 'warn'" />
          <span v-else class="text-gray-400">-</span>
        </template>
      </Column>
      <Column :header="t('users.columns.subStarted')">
        <template #body="{ data }">
          {{ data.subscription_started_at ? new Date(data.subscription_started_at).toLocaleDateString() : '-' }}
        </template>
      </Column>
      <Column :header="t('users.columns.graceEnd')">
        <template #body="{ data }">
          {{ data.grace_period_end ? new Date(data.grace_period_end).toLocaleDateString() : '-' }}
        </template>
      </Column>
      <Column :header="t('common.actions')">
        <template #body="{ data }">
          <div class="flex gap-1">
            <Button
              v-if="!isSuspended(data)"
              icon="pi pi-ban"
              severity="warn"
              size="small"
              text
              v-tooltip.top="t('users.actions.suspend')"
              @click="handleSuspend(data)"
            />
            <Button
              v-else
              icon="pi pi-check-circle"
              severity="success"
              size="small"
              text
              v-tooltip.top="t('users.actions.unsuspend')"
              @click="handleUnsuspend(data)"
            />
            <Button
              icon="pi pi-key"
              severity="info"
              size="small"
              text
              v-tooltip.top="t('users.actions.resetPassword')"
              @click="handleResetPassword(data)"
            />
            <Button
              icon="pi pi-trash"
              severity="danger"
              size="small"
              text
              v-tooltip.top="t('users.actions.delete')"
              @click="handleDelete(data)"
            />
          </div>
        </template>
      </Column>
    </DataTable>
  </AdminLayout>
</template>
