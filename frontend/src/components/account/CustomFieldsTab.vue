<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useCustomFieldsStore } from '@/stores/customFields'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import CustomFieldDefinitionForm from '@/components/custom-field/CustomFieldDefinitionForm.vue'

const { t } = useI18n()
const toast = useToast()
const store = useCustomFieldsStore()

const showForm = ref(false)
const editingField = ref(null)
const fieldToDelete = ref(null)
const showDeleteDialog = ref(false)

onMounted(() => {
  store.fetchDefinitions(true)
})

function openCreateForm() {
  editingField.value = null
  showForm.value = true
}

function openEditForm(field) {
  editingField.value = field
  showForm.value = true
}

async function handleSave(data) {
  try {
    if (editingField.value) {
      await store.updateDefinition(editingField.value.id, data)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('custom_fields.success.updated'), life: 3000 })
    } else {
      await store.createDefinition(data)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('custom_fields.success.created'), life: 3000 })
    }
    showForm.value = false
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  }
}

function confirmDelete(field) {
  fieldToDelete.value = field
  showDeleteDialog.value = true
}

async function handleDelete() {
  if (!fieldToDelete.value) return
  try {
    await store.deleteDefinition(fieldToDelete.value.id)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('custom_fields.success.deleted'), life: 3000 })
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
  } finally {
    showDeleteDialog.value = false
    fieldToDelete.value = null
  }
}

function typeLabel(type) {
  return t(`custom_fields.types.${type}`)
}

function typeSeverity(type) {
  switch (type) {
    case 'BOOLEAN': return 'info'
    case 'TEXT': return 'secondary'
    case 'NUMBER': return 'warn'
    case 'SELECT': return 'success'
    default: return 'secondary'
  }
}

function formatOptions(options) {
  if (!options) return ''
  try {
    return JSON.parse(options).join(', ')
  } catch {
    return options
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('custom_fields.title') }}</h3>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ t('custom_fields.subtitle') }}</p>
      </div>
      <Button
        :label="t('custom_fields.create')"
        icon="pi pi-plus"
        size="small"
        @click="openCreateForm"
      />
    </div>

    <p v-if="!store.loading && store.definitions.length === 0" class="text-gray-500">
      {{ t('custom_fields.no_fields') }}
    </p>

    <DataTable
      v-if="store.definitions.length > 0"
      :value="store.definitions"
      :loading="store.loading"
      stripedRows
      class="mt-2 text-sm"
    >
      <Column field="name" :header="t('custom_fields.name')" />
      <Column field="field_type" :header="t('custom_fields.field_type')">
        <template #body="{ data }">
          <Tag :value="typeLabel(data.field_type)" :severity="typeSeverity(data.field_type)" />
        </template>
      </Column>
      <Column field="options" :header="t('custom_fields.options')">
        <template #body="{ data }">
          <span class="text-gray-500 text-xs">{{ formatOptions(data.options) }}</span>
        </template>
      </Column>
      <Column :header="''">
        <template #body="{ data }">
          <div class="flex gap-1 justify-end">
            <Button
              icon="pi pi-pencil"
              severity="secondary"
              text
              size="small"
              v-tooltip.top="t('common.edit')"
              @click="openEditForm(data)"
            />
            <Button
              icon="pi pi-trash"
              severity="danger"
              text
              size="small"
              v-tooltip.top="t('common.delete')"
              @click="confirmDelete(data)"
            />
          </div>
        </template>
      </Column>
    </DataTable>

    <CustomFieldDefinitionForm
      v-model:visible="showForm"
      :field="editingField"
      @save="handleSave"
    />

    <!-- Delete confirmation dialog -->
    <Dialog
      v-model:visible="showDeleteDialog"
      :header="fieldToDelete?.name"
      modal
      :style="{ width: '400px' }"
    >
      <p>{{ t('custom_fields.confirm_delete') }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" text @click="showDeleteDialog = false" />
        <Button :label="t('common.delete')" severity="danger" @click="handleDelete" />
      </template>
    </Dialog>
  </div>
</template>
