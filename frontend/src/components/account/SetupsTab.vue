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
  { value: null, label: t('setups.category.uncategorized') },
]

const showAddRow = ref(false)
const newLabel = ref('')
const adding = ref(false)
const setupToDelete = ref(null)
const showDeleteDialog = ref(false)
const editingId = ref(null)
const editLabel = ref('')
const editCategory = ref(null)
const savingEdit = ref(false)

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

function startEdit(setup) {
  editingId.value = setup.id
  editLabel.value = setup.label
  editCategory.value = setup.category ?? null
}

function cancelEdit() {
  editingId.value = null
  editLabel.value = ''
  editCategory.value = null
}

async function saveEdit() {
  if (editingId.value === null) return
  const trimmed = editLabel.value.trim()
  if (!trimmed) return

  const original = store.setups.find((s) => s.id === editingId.value)
  const patch = {}
  if (!original || original.label !== trimmed) patch.label = trimmed
  if (!original || (original.category ?? null) !== (editCategory.value ?? null)) {
    patch.category = editCategory.value ?? null
  }

  if (Object.keys(patch).length === 0) {
    cancelEdit()
    return
  }

  savingEdit.value = true
  try {
    await store.updateSetup(editingId.value, patch)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('setups.success.updated'), life: 2000 })
    cancelEdit()
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  } finally {
    savingEdit.value = false
  }
}

function handleEditKeyup(event) {
  if (event.key === 'Enter') saveEdit()
  if (event.key === 'Escape') cancelEdit()
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

defineExpose({
  newLabel,
  confirmDelete,
  editingId,
  editLabel,
  editCategory,
  startEdit,
  cancelEdit,
  saveEdit,
})
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
      <Column field="label" :header="t('setups.label')">
        <template #body="{ data }">
          <InputText
            v-if="editingId === data.id"
            v-model="editLabel"
            :placeholder="t('setups.placeholder')"
            :data-testid="`edit-setup-input-${data.id}`"
            class="w-full"
            autofocus
            @keyup="handleEditKeyup"
          />
          <span v-else>{{ data.label }}</span>
        </template>
      </Column>
      <Column field="category" :header="t('setups.category_header')">
        <template #body="{ data }">
          <Select
            v-if="editingId === data.id"
            v-model="editCategory"
            :options="categoryOptions"
            optionLabel="label"
            optionValue="value"
            class="w-40"
            :data-testid="`edit-category-select-${data.id}`"
          />
          <span v-else class="text-sm text-gray-700 dark:text-gray-300">
            {{ t(`setups.category.${data.category || 'uncategorized'}`) }}
          </span>
        </template>
      </Column>
      <Column :header="''">
        <template #body="{ data }">
          <div class="flex items-center gap-1 justify-end">
            <template v-if="editingId === data.id">
              <Button
                icon="pi pi-check"
                severity="success"
                size="small"
                :loading="savingEdit"
                :disabled="!editLabel.trim()"
                v-tooltip.top="t('common.save')"
                :data-testid="`confirm-edit-btn-${data.id}`"
                @click="saveEdit"
              />
              <Button
                icon="pi pi-times"
                severity="secondary"
                size="small"
                text
                v-tooltip.top="t('common.cancel')"
                :data-testid="`cancel-edit-btn-${data.id}`"
                @click="cancelEdit"
              />
            </template>
            <template v-else>
              <Button
                icon="pi pi-pencil"
                severity="secondary"
                size="small"
                text
                v-tooltip.top="t('common.edit')"
                :data-testid="`edit-setup-btn-${data.id}`"
                @click="startEdit(data)"
              />
              <Button
                icon="pi pi-trash"
                severity="danger"
                size="small"
                text
                v-tooltip.top="t('common.delete')"
                @click="confirmDelete(data)"
              />
            </template>
          </div>
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
