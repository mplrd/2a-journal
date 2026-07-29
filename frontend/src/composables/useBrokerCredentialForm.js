import { computed, ref, watch } from 'vue'

/**
 * Shared state for the broker connect/reconfigure dialogs.
 *
 * The two modes differ in one rule. On create, every required field must be
 * filled. On reconfigure, the API never sends secrets back, so their inputs
 * start empty and a blank field means "keep the stored value" — the form
 * therefore submits only what the user actually changed, and enables the
 * submit button as soon as anything differs from the prefilled state.
 *
 * @param {object} options
 * @param {import('vue').Ref} options.connection  Existing connection, or null to create
 * @param {string[]} options.publicFields  Non-secret fields, prefilled from
 *   connection.credentials_public (keyed by request-body field name)
 * @param {string[]} options.secretFields  Secret fields, never prefilled
 * @param {object} [options.defaults]  Initial values in create mode
 */
export function useBrokerCredentialForm({ connection, publicFields, secretFields, defaults = {} }) {
  const allFields = [...publicFields, ...secretFields]

  const values = ref({})
  const initial = ref({})

  const isEditing = computed(() => Boolean(connection.value))

  function reset() {
    const prefill = {}
    for (const field of allFields) {
      prefill[field] = ''
    }

    if (connection.value) {
      // Only the non-secret identifiers come back from the API.
      Object.assign(prefill, connection.value.credentials_public || {})
    } else {
      Object.assign(prefill, defaults)
    }

    initial.value = { ...prefill }
    values.value = { ...prefill }
  }

  watch(connection, reset, { immediate: true })

  /** Fields the user actually changed, as the request body. */
  const changed = computed(() => {
    const payload = {}
    for (const field of allFields) {
      const value = (values.value[field] ?? '').toString().trim()
      if (value !== '' && value !== initial.value[field]) {
        payload[field] = value
      }
    }
    return payload
  })

  /** Full body for a create call: every field, trimmed. */
  const full = computed(() => {
    const payload = {}
    for (const field of allFields) {
      payload[field] = (values.value[field] ?? '').toString().trim()
    }
    return payload
  })

  const canSubmit = computed(() => {
    if (isEditing.value) {
      return Object.keys(changed.value).length > 0
    }
    return allFields.every((field) => (values.value[field] ?? '').toString().trim() !== '')
  })

  return { values, isEditing, canSubmit, changed, full, reset }
}
