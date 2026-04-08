<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Textarea from 'primevue/textarea'
import { CustomFieldType } from '@/constants/enums'

const { t } = useI18n()

const props = defineProps({
  visible: Boolean,
  field: { type: Object, default: null },
})

const emit = defineEmits(['update:visible', 'save'])

const form = ref(getDefaultForm())

function getDefaultForm() {
  return {
    name: '',
    field_type: null,
    options_text: '',
  }
}

const typeOptions = computed(() =>
  Object.values(CustomFieldType).map((value) => ({
    label: t(`custom_fields.types.${value}`),
    value,
  })),
)

const isEdit = computed(() => !!props.field)

const dialogTitle = computed(() =>
  isEdit.value ? t('custom_fields.edit') : t('custom_fields.create'),
)

const showOptions = computed(() => form.value.field_type === CustomFieldType.SELECT)

watch(
  () => props.visible,
  (val) => {
    if (val) {
      if (props.field) {
        const options = props.field.options ? JSON.parse(props.field.options) : []
        form.value = {
          name: props.field.name,
          field_type: props.field.field_type,
          options_text: options.join('\n'),
        }
      } else {
        form.value = getDefaultForm()
      }
    }
  },
)

function handleSave() {
  const data = {
    name: form.value.name,
    field_type: form.value.field_type,
  }

  if (form.value.field_type === CustomFieldType.SELECT) {
    data.options = form.value.options_text
      .split('\n')
      .map((o) => o.trim())
      .filter((o) => o.length > 0)
  }

  emit('save', data)
}

function close() {
  emit('update:visible', false)
}
</script>

<template>
  <Dialog
    :visible="visible"
    :header="dialogTitle"
    modal
    :style="{ width: '28rem' }"
    @update:visible="close"
  >
    <div class="flex flex-col gap-4">
      <div>
        <label class="block text-sm font-medium mb-1">{{ t('custom_fields.name') }}</label>
        <InputText v-model="form.name" class="w-full" :maxlength="100" />
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">{{ t('custom_fields.field_type') }}</label>
        <Select
          v-model="form.field_type"
          :options="typeOptions"
          option-label="label"
          option-value="value"
          class="w-full"
          :disabled="isEdit"
        />
      </div>

      <div v-if="showOptions">
        <label class="block text-sm font-medium mb-1">{{ t('custom_fields.options') }}</label>
        <Textarea
          v-model="form.options_text"
          class="w-full"
          rows="4"
          :placeholder="t('custom_fields.options_help')"
        />
      </div>
    </div>

    <template #footer>
      <div class="flex justify-end gap-2">
        <Button :label="t('common.cancel')" severity="secondary" @click="close" />
        <Button :label="t('common.save')" @click="handleSave" />
      </div>
    </template>
  </Dialog>
</template>
