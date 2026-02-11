import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useAccountsStore } from '@/stores/accounts'
import { accountsService } from '@/services/accounts'
import { AccountType, AccountMode } from '@/constants/enums'

vi.mock('@/services/accounts', () => ({
  accountsService: {
    list: vi.fn(),
    get: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
  },
}))

describe('accounts store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = useAccountsStore()
    vi.restoreAllMocks()
  })

  it('initial state is empty', () => {
    expect(store.accounts).toEqual([])
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
  })

  it('fetchAccounts loads accounts', async () => {
    const mockAccounts = [
      { id: 1, name: 'Account 1', account_type: AccountType.BROKER, mode: AccountMode.DEMO },
      { id: 2, name: 'Account 2', account_type: AccountType.PROPFIRM, mode: AccountMode.FUNDED },
    ]
    accountsService.list.mockResolvedValue({ success: true, data: mockAccounts })

    await store.fetchAccounts()

    expect(store.accounts).toEqual(mockAccounts)
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
  })

  it('fetchAccounts sets error on failure', async () => {
    const error = new Error('Network error')
    error.messageKey = 'error.network'
    accountsService.list.mockRejectedValue(error)

    await expect(store.fetchAccounts()).rejects.toThrow()

    expect(store.error).toBe('error.network')
    expect(store.accounts).toEqual([])
  })

  it('createAccount adds to list', async () => {
    const newAccount = { id: 1, name: 'New', account_type: AccountType.BROKER, mode: AccountMode.DEMO }
    accountsService.create.mockResolvedValue({ success: true, data: newAccount })

    await store.createAccount({ name: 'New', account_type: AccountType.BROKER, mode: AccountMode.DEMO })

    expect(store.accounts).toHaveLength(1)
    expect(store.accounts[0].name).toBe('New')
  })

  it('createAccount sets error on failure', async () => {
    const error = new Error('Validation')
    error.messageKey = 'accounts.error.field_required'
    accountsService.create.mockRejectedValue(error)

    await expect(store.createAccount({})).rejects.toThrow()

    expect(store.error).toBe('accounts.error.field_required')
  })

  it('updateAccount replaces in list', async () => {
    store.accounts = [
      { id: 1, name: 'Old Name', account_type: AccountType.BROKER, mode: AccountMode.DEMO },
    ]
    const updated = { id: 1, name: 'New Name', account_type: AccountType.BROKER, mode: AccountMode.LIVE }
    accountsService.update.mockResolvedValue({ success: true, data: updated })

    await store.updateAccount(1, { name: 'New Name', account_type: AccountType.BROKER, mode: AccountMode.LIVE })

    expect(store.accounts[0].name).toBe('New Name')
    expect(store.accounts[0].mode).toBe(AccountMode.LIVE)
  })

  it('deleteAccount removes from list', async () => {
    store.accounts = [
      { id: 1, name: 'A' },
      { id: 2, name: 'B' },
    ]
    accountsService.remove.mockResolvedValue({ success: true })

    await store.deleteAccount(1)

    expect(store.accounts).toHaveLength(1)
    expect(store.accounts[0].id).toBe(2)
  })

  it('deleteAccount sets error on failure', async () => {
    store.accounts = [{ id: 1, name: 'A' }]
    const error = new Error('Forbidden')
    error.messageKey = 'accounts.error.forbidden'
    accountsService.remove.mockRejectedValue(error)

    await expect(store.deleteAccount(1)).rejects.toThrow()

    expect(store.error).toBe('accounts.error.forbidden')
    // Account should still be in list since delete failed
    expect(store.accounts).toHaveLength(1)
  })

  it('loading is true during operations', async () => {
    let resolvePromise
    accountsService.list.mockReturnValue(
      new Promise((resolve) => {
        resolvePromise = resolve
      }),
    )

    const promise = store.fetchAccounts()
    expect(store.loading).toBe(true)

    resolvePromise({ success: true, data: [] })
    await promise

    expect(store.loading).toBe(false)
  })
})
