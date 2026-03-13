<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useTheme } from '@/composables/useTheme'
import { useOnboarding } from '@/composables/useOnboarding'
import Button from 'primevue/button'
import Popover from 'primevue/popover'
import FlagIcon from '@/components/common/FlagIcon.vue'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost/api'

const { t, locale } = useI18n()
const router = useRouter()
const authStore = useAuthStore()
const { initTheme, toggleTheme, getCurrentTheme } = useTheme()
const { isRouteAllowed } = useOnboarding()

const userMenuRef = ref(null)
const localeMenuRef = ref(null)

const isMobile = ref(window.innerWidth < 768)
function onResize() {
  isMobile.value = window.innerWidth < 768
}
onMounted(() => {
  window.addEventListener('resize', onResize)
  initTheme()
})
onUnmounted(() => window.removeEventListener('resize', onResize))

// Sidebar: open by default on desktop, closed on mobile, persist preference
const sidebarOpen = ref(getSidebarDefault())

function getSidebarDefault() {
  const saved = localStorage.getItem('sidebarOpen')
  if (saved !== null) return saved === 'true'
  return window.innerWidth >= 768
}

function toggleSidebar() {
  sidebarOpen.value = !sidebarOpen.value
  localStorage.setItem('sidebarOpen', String(sidebarOpen.value))
}

function handleNavClick() {
  if (isMobile.value) {
    sidebarOpen.value = false
    localStorage.setItem('sidebarOpen', 'false')
  }
}

const localeOptions = [
  { code: 'fr', label: 'Français' },
  { code: 'en', label: 'English' },
]

const navLinks = computed(() => [
  { to: '/', name: 'dashboard', label: t('nav.dashboard'), icon: 'pi pi-home' },
  { to: '/accounts', name: 'accounts', label: t('nav.accounts'), icon: 'pi pi-wallet' },
  { to: '/positions', name: 'positions', label: t('nav.positions'), icon: 'pi pi-chart-line' },
  { to: '/orders', name: 'orders', label: t('nav.orders'), icon: 'pi pi-list' },
  { to: '/trades', name: 'trades', label: t('nav.trades'), icon: 'pi pi-arrow-right-arrow-left' },
  { to: '/performance', name: 'performance', label: t('nav.performance'), icon: 'pi pi-chart-bar' },
])

const userInitials = computed(() => {
  const f = authStore.user?.first_name?.[0] || ''
  const l = authStore.user?.last_name?.[0] || ''
  return (f + l).toUpperCase()
})

const avatarUrl = computed(() => {
  if (authStore.user?.profile_picture) {
    return `${API_URL.replace(/\/api$/, '/api/')}${authStore.user.profile_picture}`
  }
  return null
})

const themeIcon = computed(() => getCurrentTheme() === 'dark' ? 'pi pi-sun' : 'pi pi-moon')

// Apply locale from user profile when it loads
watch(
  () => authStore.user?.locale,
  (userLocale) => {
    if (userLocale && userLocale !== locale.value) {
      locale.value = userLocale
      localStorage.setItem('locale', userLocale)
    }
  },
  { immediate: true },
)

function switchLocale(code) {
  locale.value = code
  localStorage.setItem('locale', code)
  if (authStore.isAuthenticated) {
    authStore.updateProfile({ locale: code })
  }
}

function toggleUserMenu(event) {
  userMenuRef.value.toggle(event)
}

async function handleLogout() {
  await authStore.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="flex flex-col min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Header (full width, always on top) -->
    <header class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700 z-10">
      <div class="px-4 py-3 flex items-center justify-between">
        <!-- Left: Burger + Title -->
        <div class="flex items-center gap-3">
          <button
            class="text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white cursor-pointer"
            data-testid="burger-menu"
            :aria-label="t('nav.menu')"
            @click="toggleSidebar"
          >
            <i class="pi pi-bars text-xl"></i>
          </button>
          <h1 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('app.title') }}</h1>
        </div>

        <!-- Right: Locale selector + Dark mode toggle + Avatar + User menu -->
        <div class="flex items-center gap-3">
          <!-- Locale selector (flag badge) -->
          <button
            data-testid="locale-select"
            class="cursor-pointer"
            @click="localeMenuRef.toggle($event)"
          >
            <FlagIcon :code="locale" :size="28" data-testid="locale-flag" />
          </button>
          <Popover ref="localeMenuRef">
            <div class="flex flex-col gap-1 min-w-[140px]">
              <button
                v-for="option in localeOptions"
                :key="option.code"
                class="flex items-center gap-2 px-3 py-2 rounded-md cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700"
                :class="locale === option.code ? 'bg-gray-100 dark:bg-gray-700' : ''"
                @click="switchLocale(option.code); localeMenuRef.toggle($event)"
              >
                <FlagIcon :code="option.code" :size="20" />
                <span class="text-sm">{{ option.label }}</span>
              </button>
            </div>
          </Popover>

          <!-- Dark mode toggle -->
          <button
            data-testid="theme-toggle"
            class="text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white cursor-pointer p-1"
            @click="toggleTheme"
          >
            <i :class="themeIcon" class="text-lg"></i>
          </button>

          <button
            class="flex items-center gap-2 cursor-pointer"
            data-testid="user-menu-trigger"
            @click="toggleUserMenu"
          >
            <img
              v-if="authStore.user?.profile_picture"
              :src="avatarUrl"
              alt=""
              class="w-8 h-8 rounded-full object-cover"
              data-testid="user-avatar"
            />
            <span
              v-else
              class="w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center text-sm font-semibold"
              data-testid="user-avatar"
            >
              {{ userInitials }}
            </span>
            <span class="hidden md:inline text-sm text-gray-700 dark:text-gray-300">{{ authStore.fullName }}</span>
            <i class="pi pi-chevron-down text-xs text-gray-500 dark:text-gray-400"></i>
          </button>

          <Popover ref="userMenuRef">
            <div class="p-3 min-w-[200px]">
              <!-- User info -->
              <div class="mb-3 pb-3 border-b border-gray-200 dark:border-gray-600">
                <p class="font-semibold text-sm text-gray-800 dark:text-gray-100">{{ authStore.fullName }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400" data-testid="user-email">{{ authStore.user?.email }}</p>
              </div>

              <!-- My account link -->
              <RouterLink
                to="/account"
                class="flex items-center gap-2 px-2 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-md mb-2"
                data-testid="my-account-link"
              >
                <i class="pi pi-user"></i>
                {{ t('nav.my_account') }}
              </RouterLink>

              <!-- Logout -->
              <Button
                :label="t('nav.logout')"
                icon="pi pi-sign-out"
                severity="secondary"
                size="small"
                class="w-full"
                @click="handleLogout"
              />
            </div>
          </Popover>
        </div>
      </div>
    </header>

    <!-- Body: Sidebar + Content -->
    <div class="flex flex-1 min-h-0">
      <!-- Backdrop (mobile only) -->
      <div
        v-if="sidebarOpen && isMobile"
        class="fixed inset-0 bg-black/30 z-20"
        @click="toggleSidebar"
      />

      <!-- Sidebar (below header) -->
      <aside
        class="fixed md:static top-[53px] md:top-0 left-0 z-30 h-[calc(100vh-53px)] md:h-auto bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 transition-all duration-300 overflow-hidden shrink-0"
        :class="sidebarOpen ? 'w-64' : 'w-0'"
      >
        <nav class="w-64 p-3 flex flex-col gap-1 overflow-y-auto">
          <template v-for="link in navLinks" :key="link.to">
            <RouterLink
              v-if="isRouteAllowed(link.name)"
              :to="link.to"
              class="flex items-center gap-3 px-3 py-2 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-md"
              @click="handleNavClick"
            >
              <i :class="link.icon"></i>
              <span>{{ link.label }}</span>
            </RouterLink>
            <span
              v-else
              class="flex items-center gap-3 px-3 py-2 text-gray-400 dark:text-gray-600 cursor-not-allowed rounded-md"
            >
              <i :class="link.icon"></i>
              <span>{{ link.label }}</span>
            </span>
          </template>
        </nav>
      </aside>

      <!-- Main content -->
      <main class="flex-1 px-4 py-6 max-w-7xl w-full mx-auto min-w-0">
        <RouterView />
      </main>
    </div>
  </div>
</template>
