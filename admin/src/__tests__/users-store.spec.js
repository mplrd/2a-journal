import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useUsersStore } from '@/stores/users'

vi.mock('@/services/users', () => ({
  usersService: {
    list: vi.fn(),
    suspend: vi.fn(),
    unsuspend: vi.fn(),
    resetPassword: vi.fn(),
    remove: vi.fn(),
  },
}))

import { usersService } from '@/services/users'

describe('users store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('fetches users with current filters and pagination', async () => {
    usersService.list.mockResolvedValueOnce({
      data: [{ id: 1, email: 'u@b.com' }],
      meta: { total: 1 },
    })

    const store = useUsersStore()
    store.setFilters({ search: 'foo', status: 'active' })
    await store.fetchUsers()

    expect(usersService.list).toHaveBeenCalledWith(expect.objectContaining({
      search: 'foo',
      status: 'active',
      page: 1,
      per_page: 50,
    }))
    expect(store.users).toHaveLength(1)
    expect(store.total).toBe(1)
  })

  it('replaces the suspended user in the local list', async () => {
    const store = useUsersStore()
    store.users = [{ id: 1, email: 'u@b.com', suspended_at: null }]

    usersService.suspend.mockResolvedValueOnce({
      data: { id: 1, email: 'u@b.com', suspended_at: '2026-04-27 10:00:00' },
    })

    await store.suspend(1)
    expect(store.users[0].suspended_at).not.toBeNull()
  })

  it('removes the deleted user from the local list', async () => {
    const store = useUsersStore()
    store.users = [
      { id: 1, email: 'a@b.com' },
      { id: 2, email: 'b@b.com' },
    ]
    usersService.remove.mockResolvedValueOnce(undefined)

    await store.remove(1)
    expect(store.users).toHaveLength(1)
    expect(store.users[0].id).toBe(2)
  })
})
