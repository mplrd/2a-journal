import { describe, it, expect } from 'vitest'
import { ref } from 'vue'
import { useBrokerCredentialForm } from '../useBrokerCredentialForm'

/**
 * The form behind every broker connect/reconfigure dialog.
 *
 * Three modes now, not two. Create with nothing stored is the original: every
 * required field must be typed. Reconfigure never receives secrets back, so a
 * blank input means "keep the stored value". And create *with app credentials
 * already shared* (docs/91) behaves like the second one on the shared fields
 * and like the first on the rest — that is the whole point of the feature: on
 * a second cTrader account there is nothing to type but the account.
 */
describe('useBrokerCredentialForm', () => {
  const CTRADER = {
    publicFields: ['client_id', 'account_id_ctrader', 'environment'],
    secretFields: ['client_secret', 'access_token', 'refresh_token'],
    optionalFields: ['refresh_token'],
    defaults: { environment: 'LIVE' },
  }

  const sharedCredentials = {
    credentials_public: { client_id: '30528' },
    credentials_set: { client_secret: true, access_token: true, refresh_token: true },
    credentials_shared_fields: ['client_id', 'client_secret', 'access_token', 'refresh_token'],
    credentials_shared_count: 1,
  }

  function build({ connection = null, shared = null } = {}) {
    return useBrokerCredentialForm({
      connection: ref(connection),
      shared: ref(shared),
      ...CTRADER,
    })
  }

  // ── Create, nothing stored: unchanged ───────────────────────────

  it('still demands every required field when nothing is stored yet', () => {
    const form = build()

    expect(form.canSubmit.value).toBe(false)

    form.values.value.client_id = '30528'
    form.values.value.client_secret = 'sec'
    form.values.value.access_token = 'tok'
    form.values.value.account_id_ctrader = '12345678'

    expect(form.canSubmit.value).toBe(true)
  })

  it('reports no sharing when the user has no stored credentials', () => {
    expect(build().sharing.value).toBeNull()
  })

  // ── Create with shared credentials ──────────────────────────────

  it('needs only the account when the app credentials are already stored', () => {
    const form = build({ shared: sharedCredentials })

    // Nothing typed yet — but three of the four required fields are stored.
    expect(form.canSubmit.value).toBe(false)

    form.values.value.account_id_ctrader = '12345678'

    expect(form.canSubmit.value).toBe(true)
  })

  it('prefills the non-secret shared identifiers', () => {
    const form = build({ shared: sharedCredentials })

    expect(form.values.value.client_id).toBe('30528')
  })

  it('never prefills a secret, only marks it as stored', () => {
    const form = build({ shared: sharedCredentials })

    expect(form.values.value.client_secret).toBe('')
    expect(form.isStored('client_secret')).toBe(true)
    expect(form.isStored('account_id_ctrader')).toBe(false)
  })

  it('does not treat an unstored optional secret as stored', () => {
    const form = build({
      shared: {
        ...sharedCredentials,
        credentials_set: { client_secret: true, access_token: true, refresh_token: false },
      },
    })

    expect(form.isStored('refresh_token')).toBe(false)
  })

  it('exposes what the sharing banner needs', () => {
    const form = build({ shared: { ...sharedCredentials, credentials_shared_count: 2 } })

    expect(form.sharing.value).toEqual({
      fields: ['client_id', 'client_secret', 'access_token', 'refresh_token'],
      count: 2,
    })
  })

  it('keeps the defaults that are not shared', () => {
    const form = build({ shared: sharedCredentials })

    expect(form.values.value.environment).toBe('LIVE')
  })

  it('lets the user override a stored credential by typing over it', () => {
    // Rotating a secret from the create dialog has to reach the server, so a
    // typed value must survive into the payload.
    const form = build({ shared: sharedCredentials })
    form.values.value.account_id_ctrader = '12345678'
    form.values.value.client_secret = 'rotated'

    expect(form.full.value.client_secret).toBe('rotated')
  })

  // ── Reconfigure ─────────────────────────────────────────────────

  it('reads the sharing banner off the connection when reconfiguring', () => {
    const form = build({
      connection: {
        id: 42,
        credentials_public: { client_id: '30528', account_id_ctrader: '12345678' },
        credentials_set: { client_secret: true },
        credentials_shared_fields: ['client_id', 'client_secret', 'access_token', 'refresh_token'],
        credentials_shared_count: 3,
      },
    })

    expect(form.sharing.value).toEqual({
      fields: ['client_id', 'client_secret', 'access_token', 'refresh_token'],
      count: 3,
    })
  })

  it('reports no sharing for a provider that shares nothing', () => {
    const form = build({
      connection: {
        id: 42,
        credentials_public: {},
        credentials_set: { api_key: true },
        credentials_shared_fields: [],
        credentials_shared_count: 0,
      },
    })

    expect(form.sharing.value).toBeNull()
  })

  it('submits only what changed when reconfiguring', () => {
    const form = build({
      connection: {
        id: 42,
        credentials_public: { client_id: '30528', account_id_ctrader: '12345678' },
        credentials_set: { client_secret: true },
      },
    })

    expect(form.canSubmit.value).toBe(false)
    form.values.value.client_secret = 'rotated'

    expect(form.changed.value).toEqual({ client_secret: 'rotated' })
    expect(form.canSubmit.value).toBe(true)
  })

  it('tolerates being built without a shared source at all', () => {
    // Three of the four dialogs pass nothing: their provider shares nothing.
    const form = useBrokerCredentialForm({
      connection: ref(null),
      publicFields: ['api_key'],
      secretFields: ['api_secret'],
    })

    expect(form.sharing.value).toBeNull()
    expect(form.isStored('api_secret')).toBe(false)
  })
})
