import { watch } from 'vue'
import { useAuthStore } from '@/stores/auth'

function applyTheme(theme) {
  if (theme === 'dark') {
    document.documentElement.classList.add('dark-mode')
  } else {
    document.documentElement.classList.remove('dark-mode')
  }
  localStorage.setItem('theme', theme)
}

function getCurrentTheme() {
  return document.documentElement.classList.contains('dark-mode') ? 'dark' : 'light'
}

export function useTheme() {
  const authStore = useAuthStore()

  function initTheme() {
    const theme = authStore.user?.theme || localStorage.getItem('theme') || 'light'
    applyTheme(theme)
  }

  function toggleTheme() {
    const next = getCurrentTheme() === 'dark' ? 'light' : 'dark'
    applyTheme(next)
    authStore.updateProfile({ theme: next })
  }

  // Apply theme when user profile loads
  watch(
    () => authStore.user?.theme,
    (theme) => {
      if (theme) {
        applyTheme(theme)
      }
    },
  )

  return { initTheme, toggleTheme, applyTheme, getCurrentTheme }
}
