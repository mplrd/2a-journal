import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useTheme } from '@/composables/useTheme'

vi.mock('@/services/auth', () => ({
  authService: {
    updateProfile: vi.fn(),
  },
}))

describe('useTheme', () => {
  let theme

  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    document.documentElement.classList.remove('dark-mode')
    theme = useTheme()
  })

  it('initTheme applies light by default', () => {
    theme.initTheme()
    expect(document.documentElement.classList.contains('dark-mode')).toBe(false)
    expect(localStorage.getItem('theme')).toBe('light')
  })

  it('initTheme applies dark from localStorage', () => {
    localStorage.setItem('theme', 'dark')
    theme.initTheme()
    expect(document.documentElement.classList.contains('dark-mode')).toBe(true)
  })

  it('toggleTheme switches light to dark', () => {
    theme.initTheme()
    theme.toggleTheme()
    expect(document.documentElement.classList.contains('dark-mode')).toBe(true)
    expect(localStorage.getItem('theme')).toBe('dark')
  })

  it('toggleTheme switches dark to light', () => {
    localStorage.setItem('theme', 'dark')
    theme.initTheme()
    theme.toggleTheme()
    expect(document.documentElement.classList.contains('dark-mode')).toBe(false)
    expect(localStorage.getItem('theme')).toBe('light')
  })

  it('applyTheme persists to localStorage', () => {
    theme.applyTheme('dark')
    expect(localStorage.getItem('theme')).toBe('dark')
    theme.applyTheme('light')
    expect(localStorage.getItem('theme')).toBe('light')
  })
})
