<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useSetupsStore } from '@/stores/setups'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Dialog from 'primevue/dialog'

const { t } = useI18n()
const toast = useToast()
const store = useSetupsStore()

const categoryOptions = [
  { value: 'timeframe', label: t('setups.category.timeframe') },
  { value: 'pattern', label: t('setups.category.pattern') },
  { value: 'context', label: t('setups.category.context') },
]

const showAddRow = ref(false)
const newLabel = ref('')
const adding = ref(false)
const setupToDelete = ref(null)
const showDeleteDialog = ref(false)

onMounted(() => {
  store.fetchSetups(true)
})

function openAddRow() {
  showAddRow.value = true
  newLabel.value = ''
}

function cancelAdd() {
  showAddRow.value = false
  newLabel.value = ''
}

async function handleAdd() {
  const label = newLabel.value.trim()
  if (!label) return

  adding.value = true
  try {
    await store.createSetup({ label })
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('setups.success.created'), life: 3000 })
    newLabel.value = ''
    showAddRow.value = false
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  } finally {
    adding.value = false
  }
}

function handleKeyup(event) {
  if (event.key === 'Enter') handleAdd()
  if (event.key === 'Escape') cancelAdd()
}

async function handleCategoryChange(setup, category) {
  if (category === setup.category) return
  try {
    await store.updateSetup(setup.id, { category })
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('setups.success.updated'), life: 2000 })
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  }
}

function confirmDelete(setup) {
  setupToDelete.value = setup
  showDeleteDialog.value = true
}

async function handleDelete() {
  if (!setupToDelete.value) return

  try {
    await store.deleteSetup(setupToDelete.value.id)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('setups.success.deleted'), life: 3000 })
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  } finally {
    showDeleteDialog.value = false
    setupToDelete.value = null
  }
}

defineExpose({ newLabel, confirmDelete })
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('setups.title') }}</h3>
      <Button
        :label="t('setups.add')"
        icon="pi pi-plus"
        size="small"
        data-testid="add-setup-btn"
        @click="openAddRow"
      />
    </div>

    <!-- Add row -->
    <div v-if="showAddRow" class="flex items-center gap-2 mb-4">
      <InputText
        v-model="newLabel"
        :placeholder="t('setups.placeholder')"
        data-testid="new-setup-input"
        class="flex-1"
        @keyup="handleKeyup"
      />
      <Button
        icon="pi pi-check"
        severity="success"
        size="small"
        :loading="adding"
        :disabled="!newLabel.trim()"
        data-testid="confirm-add-btn"
        @click="handleAdd"
      />
      <Button
        icon="pi pi-times"
        severity="secondary"
        size="small"
        text
        data-testid="cancel-add-btn"
        @click="cancelAdd"
      />
    </div>

    <p v-if="!store.loading && store.setups.length === 0 && !showAddRow" class="text-gray-500" data-testid="setups-empty">
      {{ t('setups.empty') }}
    </p>

    <DataTable
      v-if="store.setups.length > 0"
      :value="store.setups"
      :loading="store.loading"
      stripedRows
      class="mt-2"
      data-testid="setups-table"
    >
      <Column field="label" :header="t('setups.label')" />
      <Column field="category" :header="t('setups.category_header')">
        <template #body="{ data }">
          <Select
            :modelValue="data.category"
            :options="categoryOptions"
            optionLabel="label"
            optionValue="value"
            class="w-40"
            data-testid="category-select"
            @update:modelValue="(v) => handleCategoryChange(data, v)"
          />
        </template>
      </Column>
      <Column :header="''">
        <template #body="{ data }">
          <Button
            icon="pi pi-trash"
            severity="danger"
            size="small"
            text
            v-tooltip.top="t('common.delete')"
            @click="confirmDelete(data)"
          />
        </template>
      </Column>
    </DataTable>

    <!-- Delete confirmation dialog -->
    <Dialog
      v-model:visible="showDeleteDialog"
      :header="t('setups.confirm_delete_title')"
      modal
      :style="{ width: '400px' }"
      data-testid="confirm-dialog"
    >
      <p>{{ t('setups.confirm_delete', { label: setupToDelete?.label }) }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" text @click="showDeleteDialog = false" />
        <Button :label="t('common.delete')" severity="danger" @click="handleDelete" data-testid="confirm-delete-btn" />
      </template>
    </Dialog>
  </div>
</template>
