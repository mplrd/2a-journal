import { computed, ref, watch } from 'vue'

/**
 * Shared state for the broker connect/reconfigure dialogs.
 *
 * Three modes, not two:
 *
 *  - Create with nothing stored — every required field must be filled.
 *  - Reconfigure — the API never sends secrets back, so their inputs start
 *    empty and a blank field means "keep the stored value". The form submits
 *    only what the user actually changed, and enables submit as soon as
 *    anything differs from the prefilled state.
 *  - Create while the provider's app credentials are already shared
 *    (docs/91-broker-shared-credentials.md) — the shared fields behave like the
 *    reconfigure case (prefilled or flagged as stored, blank means keep) and
 *    everything else like the create case. On a second cTrader account that
 *    leaves exactly one thing to fill: the account itself.
 *
 * @param {object} options
 * @param {import('vue').Ref} options.connection  Existing connection, or null to create
 * @param {import('vue').Ref} [options.shared]  The user's stored app credentials
 *   for this provider ({credentials_public, credentials_set,
 *   credentials_shared_fields, credentials_shared_count}), or null. Only
 *   consulted in create mode — a connection carries its own copy of all four.
 * @param {string[]} options.publicFields  Non-secret fields, prefilled from
 *   credentials_public (keyed by request-body field name)
 * @param {string[]} options.secretFields  Secret fields, never prefilled
 * @param {string[]} [options.optionalFields]  Fields that may stay empty on
 *   create. Secrecy and requiredness are independent — a refresh token is
 *   secret but optional — so this is listed separately rather than carved out
 *   of secretFields, mirroring the `secret`/`required` split the backend
 *   BrokerCredentialMapper already uses.
 * @param {object} [options.defaults]  Initial values in create mode
 */
export function useBrokerCredentialForm({
  connection,
  shared = null,
  publicFields,
  secretFields,
  optionalFields = [],
  defaults = {},
}) {
  const allFields = [...publicFields, ...secretFields]
  const requiredFields = allFields.filter((field) => !optionalFields.includes(field))

  const values = ref({})
  const initial = ref({})

  const isEditing = computed(() => Boolean(connection.value))

  /** The stored app credentials, only while creating — null otherwise. */
  const sharedSource = computed(() => (isEditing.value ? null : (shared?.value ?? null)))

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
      // Shared identifiers come on top of the defaults, not under them: an
      // already-stored client_id is a fact, a default is a guess.
      Object.assign(prefill, sharedSource.value?.credentials_public || {})
    }

    initial.value = { ...prefill }
    values.value = { ...prefill }
  }

  watch([connection, () => sharedSource.value], reset, { immediate: true })

  /**
   * Fields the server already holds, so the dialog can stop demanding them and
   * say "unchanged" instead of an empty required input. Empty while
   * reconfiguring: there the connection's own credentials_set already drives
   * the placeholders, and every field is optional anyway.
   */
  const storedFields = computed(() => {
    const source = sharedSource.value
    if (!source) return []

    const scope = source.credentials_shared_fields || []
    return allFields.filter(
      (field) =>
        scope.includes(field) &&
        (source.credentials_set?.[field] === true ||
          (source.credentials_public?.[field] ?? '') !== ''),
    )
  })

  const isStored = (field) => storedFields.value.includes(field)

  /**
   * What the sharing banner needs: which fields are shared and how many
   * connections they feed. Null when the provider shares nothing — Ouinex and
   * BingX, where the API key *is* the account.
   */
  const sharing = computed(() => {
    const source = connection.value ?? sharedSource.value
    if (!source) return null

    const fields = source.credentials_shared_fields || []
    if (fields.length === 0) return null

    return { fields, count: source.credentials_shared_count || 0 }
  })

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

  /**
   * Full body for a create call: every field, trimmed. A stored shared
   * credential left untouched goes out blank, which the server reads as "keep
   * what is stored" — the same rule as reconfiguring.
   */
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
    return requiredFields.every(
      (field) => (values.value[field] ?? '').toString().trim() !== '' || isStored(field),
    )
  })

  return { values, isEditing, canSubmit, changed, full, reset, storedFields, isStored, sharing }
}
