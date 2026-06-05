<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import { useNotebookStore } from '@/stores/notebook'

defineProps({ visible: { type: Boolean, default: false } })
const emit = defineEmits(['update:visible'])

const { t } = useI18n()
const toast = useToast()
const store = useNotebookStore()

const newLabel = ref('')
const adding = ref(false)
const editingId = ref(null)
const editLabel = ref('')
const confirmId = ref(null)

function notifyError(err) {
  toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 5000 })
}

async function handleAdd() {
  const label = newLabel.value.trim()
  if (!label || adding.value) return
  adding.value = true
  try {
    await store.createCategory({ label })
    newLabel.value = ''
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('notebook.categories.success.created'), life: 2000 })
  } catch (err) {
    notifyError(err)
  } finally {
    adding.value = false
  }
}

function startEdit(category) {
  editingId.value = category.id
  editLabel.value = category.label
}

function cancelEdit() {
  editingId.value = null
  editLabel.value = ''
}

async function saveEdit() {
  const label = editLabel.value.trim()
  if (!label) return
  const original = store.categories.find((c) => c.id === editingId.value)
  if (original && original.label === label) {
    cancelEdit()
    return
  }
  try {
    await store.updateCategory(editingId.value, { label })
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('notebook.categories.success.updated'), life: 2000 })
    cancelEdit()
  } catch (err) {
    notifyError(err)
  }
}

async function handleDelete() {
  const id = confirmId.value
  confirmId.value = null
  if (!id) return
  try {
    await store.deleteCategory(id)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('notebook.categories.success.deleted'), life: 2000 })
  } catch (err) {
    notifyError(err)
  }
}
</script>

<template>
  <Dialog
    :visible="visible"
    :header="t('notebook.categories.title')"
    modal
    :style="{ width: '440px' }"
    data-testid="category-manager"
    @update:visible="emit('update:visible', $event)"
  >
    <div class="mb-4 flex items-center gap-2">
      <InputText
        v-model="newLabel"
        :placeholder="t('notebook.categories.placeholder')"
        class="flex-1"
        data-testid="new-category-input"
        @keyup.enter="handleAdd"
      />
      <Button
        :label="t('notebook.categories.add')"
        icon="pi pi-plus"
        size="small"
        :loading="adding"
        :disabled="!newLabel.trim()"
        data-testid="add-category-btn"
        @click="handleAdd"
      />
    </div>

    <p v-if="store.categories.length === 0" class="text-sm text-gray-500" data-testid="categories-empty">
      {{ t('notebook.categories.empty') }}
    </p>

    <ul v-else class="divide-y divide-gray-100 dark:divide-gray-700">
      <li v-for="cat in store.categories" :key="cat.id" class="flex items-center gap-2 py-2">
        <template v-if="editingId === cat.id">
          <InputText v-model="editLabel" class="flex-1" autofocus @keyup.enter="saveEdit" @keyup.esc="cancelEdit" />
          <Button icon="pi pi-check" severity="success" size="small" :disabled="!editLabel.trim()" @click="saveEdit" />
          <Button icon="pi pi-times" severity="secondary" size="small" text @click="cancelEdit" />
        </template>
        <template v-else>
          <span class="flex-1 text-gray-800 dark:text-gray-100">{{ cat.label }}</span>
          <Button icon="pi pi-pencil" severity="secondary" size="small" text v-tooltip.top="t('common.edit')" @click="startEdit(cat)" />
          <Button icon="pi pi-trash" severity="danger" size="small" text v-tooltip.top="t('common.delete')" @click="confirmId = cat.id" />
        </template>
      </li>
    </ul>

    <Dialog
      :visible="confirmId !== null"
      :header="t('notebook.categories.confirm_delete_title')"
      modal
      :style="{ width: '380px' }"
      @update:visible="confirmId = null"
    >
      <p class="text-sm">
        {{ t('notebook.categories.confirm_delete', { label: store.categories.find((c) => c.id === confirmId)?.label }) }}
      </p>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" text @click="confirmId = null" />
        <Button :label="t('common.delete')" severity="danger" data-testid="confirm-delete-category" @click="handleDelete" />
      </template>
    </Dialog>
  </Dialog>
</template>
