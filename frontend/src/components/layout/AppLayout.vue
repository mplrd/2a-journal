<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { authService } from '@/services/auth'
import { useTheme } from '@/composables/useTheme'
import { useOnboarding } from '@/composables/useOnboarding'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Popover from 'primevue/popover'
import FlagIcon from '@/components/common/FlagIcon.vue'
import BrandLogo from '@/components/common/BrandLogo.vue'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost/api'
const ADMIN_URL = import.meta.env.VITE_ADMIN_URL || ''

const { t, locale } = useI18n()
const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const toast = useToast()
const { initTheme, toggleTheme, getCurrentTheme } = useTheme()
const { isRouteAllowed } = useOnboarding()

const isAdmin = computed(() => authStore.user?.role === 'ADMIN')

async function openAdmin() {
  if (!ADMIN_URL) return
  try {
    const response = await authService.ssoIssueCode()
    const code = response.data.code
    const url = new URL(ADMIN_URL)
    url.searchParams.set('code', code)
    window.open(url.toString(), '_blank', 'noopener,noreferrer')
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: t(err.messageKey || 'error.internal'),
      life: 5000,
    })
  }
}

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

// Navigation:
// Desktop: a permanent dock-style sidebar (icons only, label on hover).
// Mobile:  a drop-down panel that takes the full width and shows the
// sections as a grid of square tiles.
const mobileOpen = ref(false)

function toggleSidebar() {
  // Burger button is md:hidden, so this only fires on mobile.
  mobileOpen.value = !mobileOpen.value
}

function handleNavClick() {
  if (isMobile.value) {
    mobileOpen.value = false
  }
}

const localeOptions = [
  { code: 'fr', label: 'Français' },
  { code: 'en', label: 'English' },
]

function isActiveLink(linkTo) {
  if (linkTo === '/') return route.path === '/'
  return route.path === linkTo || route.path.startsWith(linkTo + '/')
}

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
  <div class="flex flex-col h-screen overflow-hidden bg-gray-50 dark:bg-gray-900">
    <!-- Header (full width, always on top) -->
    <header class="shrink-0 bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700 z-10">
      <div class="px-4 py-3 flex items-center justify-between">
        <!-- Left: Burger + Title -->
        <div class="flex items-center gap-3">
          <button
            class="md:hidden text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white cursor-pointer"
            data-testid="burger-menu"
            :aria-label="t('nav.menu')"
            @click="toggleSidebar"
          >
            <i class="pi pi-bars text-xl"></i>
          </button>
          <RouterLink to="/" class="flex items-center gap-2">
            <BrandLogo :size="40" class="text-brand-navy-900 dark:text-brand-cream" />
            <h1 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('app.title') }}</h1>
          </RouterLink>
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
              class="w-8 h-8 rounded-full bg-brand-navy-900 text-white flex items-center justify-center text-sm font-semibold"
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
      <!-- Mobile dropdown backdrop -->
      <div
        v-if="mobileOpen"
        class="md:hidden fixed inset-0 top-[53px] bg-black/40 z-20"
        @click="toggleSidebar"
      />

      <!-- Desktop dock (always icons only, label on hover) -->
      <aside class="hidden md:flex md:flex-col w-14 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 shrink-0">
        <nav class="p-2 flex flex-col h-full overflow-y-auto">
          <div class="flex-1 flex flex-col justify-center gap-1">
            <template v-for="link in navLinks" :key="link.to">
              <RouterLink
                v-if="isRouteAllowed(link.name)"
                v-tooltip.right="link.label"
                :to="link.to"
                class="flex items-center justify-center px-3 py-2 rounded-md"
                :class="isActiveLink(link.to)
                  ? 'bg-brand-green-100 dark:bg-brand-green-700/20 text-brand-green-800 dark:text-brand-green-300'
                  : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'"
              >
                <i :class="link.icon"></i>
              </RouterLink>
              <span
                v-else
                v-tooltip.right="link.label"
                class="flex items-center justify-center px-3 py-2 text-gray-400 dark:text-gray-600 cursor-not-allowed rounded-md"
              >
                <i :class="link.icon"></i>
              </span>
            </template>
          </div>
          <button
            v-if="isAdmin && ADMIN_URL"
            v-tooltip.right="t('nav.go_to_admin')"
            type="button"
            class="flex items-center justify-center px-3 py-2 text-amber-700 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-gray-700 rounded-md border-t border-gray-200 dark:border-gray-700 pt-3 cursor-pointer w-full"
            data-testid="admin-link"
            @click="openAdmin"
          >
            <i class="pi pi-shield"></i>
          </button>
        </nav>
      </aside>

      <!-- Mobile dropdown menu (full-width grid of square tiles) -->
      <div
        v-if="mobileOpen"
        class="md:hidden fixed top-[53px] left-0 right-0 z-30 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 shadow-xl max-h-[calc(100vh-53px)] overflow-y-auto"
        data-testid="mobile-menu"
      >
        <div class="grid grid-cols-3 gap-3 p-4">
          <template v-for="link in navLinks" :key="link.to">
            <RouterLink
              v-if="isRouteAllowed(link.name)"
              :to="link.to"
              class="aspect-square flex flex-col items-center justify-center gap-2 rounded-lg border"
              :class="isActiveLink(link.to)
                ? 'bg-brand-green-100 dark:bg-brand-green-700/20 border-brand-green-300 dark:border-brand-green-700 text-brand-green-800 dark:text-brand-green-300 font-semibold'
                : 'bg-gray-50 dark:bg-gray-700/40 border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300'"
              @click="handleNavClick"
            >
              <i :class="link.icon" class="text-3xl"></i>
              <span class="text-xs text-center px-1">{{ link.label }}</span>
            </RouterLink>
            <span
              v-else
              class="aspect-square flex flex-col items-center justify-center gap-2 rounded-lg border bg-gray-50 dark:bg-gray-700/40 border-gray-200 dark:border-gray-700 text-gray-400 dark:text-gray-600"
            >
              <i :class="link.icon" class="text-3xl"></i>
              <span class="text-xs text-center px-1">{{ link.label }}</span>
            </span>
          </template>
        </div>
        <button
          v-if="isAdmin && ADMIN_URL"
          type="button"
          class="w-full flex items-center justify-center gap-3 py-4 text-amber-700 dark:text-amber-400 border-t border-gray-200 dark:border-gray-700 cursor-pointer"
          data-testid="admin-link-mobile"
          @click="openAdmin"
        >
          <i class="pi pi-shield text-xl"></i>
          <span class="text-base font-medium">{{ t('nav.go_to_admin') }}</span>
          <i class="pi pi-external-link text-sm"></i>
        </button>
      </div>

      <!-- Main content (only this area scrolls) -->
      <main class="flex-1 overflow-y-auto px-4 py-6 min-w-0">
        <div class="w-full">
          <RouterView />
        </div>
      </main>
    </div>
  </div>
</template>
