<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import Button from 'primevue/button'
import Popover from 'primevue/popover'

const { t, locale } = useI18n()
const router = useRouter()
const authStore = useAuthStore()

const userMenuRef = ref(null)

const isMobile = ref(window.innerWidth < 768)
function onResize() {
  isMobile.value = window.innerWidth < 768
}
onMounted(() => window.addEventListener('resize', onResize))
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

const languages = [
  { code: 'fr', label: 'FR' },
  { code: 'en', label: 'EN' },
]

const navLinks = computed(() => [
  { to: '/', label: t('nav.dashboard'), icon: 'pi pi-home' },
  { to: '/accounts', label: t('nav.accounts'), icon: 'pi pi-wallet' },
  { to: '/positions', label: t('nav.positions'), icon: 'pi pi-chart-line' },
  { to: '/orders', label: t('nav.orders'), icon: 'pi pi-list' },
  { to: '/trades', label: t('nav.trades'), icon: 'pi pi-arrow-right-arrow-left' },
  { to: '/symbols', label: t('nav.symbols'), icon: 'pi pi-star' },
])

const userInitials = computed(() => {
  const f = authStore.user?.first_name?.[0] || ''
  const l = authStore.user?.last_name?.[0] || ''
  return (f + l).toUpperCase()
})

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
    authStore.updateLocale(code)
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
  <div class="flex flex-col min-h-screen bg-gray-50">
    <!-- Header (full width, always on top) -->
    <header class="bg-white shadow-sm border-b border-gray-200 z-10">
      <div class="px-4 py-3 flex items-center justify-between">
        <!-- Left: Burger + Title -->
        <div class="flex items-center gap-3">
          <button
            class="text-gray-600 hover:text-gray-900 cursor-pointer"
            data-testid="burger-menu"
            :aria-label="t('nav.menu')"
            @click="toggleSidebar"
          >
            <i class="pi pi-bars text-xl"></i>
          </button>
          <h1 class="text-lg font-semibold text-gray-800">{{ t('app.title') }}</h1>
        </div>

        <!-- Right: Language selector + Avatar + User menu -->
        <div class="flex items-center gap-3">
          <!-- Language selector -->
          <div class="flex gap-1">
            <button
              v-for="lang in languages"
              :key="lang.code"
              :data-testid="`lang-option-${lang.code}`"
              class="px-2 py-1 text-sm rounded border cursor-pointer"
              :class="locale === lang.code
                ? 'font-bold bg-blue-50 border-blue-300 text-blue-700'
                : 'border-gray-300 text-gray-600 hover:bg-gray-50'"
              @click="switchLocale(lang.code)"
            >
              {{ lang.label }}
            </button>
          </div>

          <button
            class="flex items-center gap-2 cursor-pointer"
            data-testid="user-menu-trigger"
            @click="toggleUserMenu"
          >
            <span
              class="w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center text-sm font-semibold"
              data-testid="user-avatar"
            >
              {{ userInitials }}
            </span>
            <span class="hidden md:inline text-sm text-gray-700">{{ authStore.fullName }}</span>
            <i class="pi pi-chevron-down text-xs text-gray-500"></i>
          </button>

          <Popover ref="userMenuRef">
            <div class="p-3 min-w-[200px]">
              <!-- User info -->
              <div class="mb-3 pb-3 border-b border-gray-200">
                <p class="font-semibold text-sm text-gray-800">{{ authStore.fullName }}</p>
                <p class="text-xs text-gray-500" data-testid="user-email">{{ authStore.user?.email }}</p>
              </div>

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
        class="fixed md:static top-[53px] md:top-0 left-0 z-30 h-[calc(100vh-53px)] md:h-auto bg-white border-r border-gray-200 transition-all duration-300 overflow-hidden shrink-0"
        :class="sidebarOpen ? 'w-64' : 'w-0'"
      >
        <nav class="w-64 p-3 flex flex-col gap-1 overflow-y-auto">
          <RouterLink
            v-for="link in navLinks"
            :key="link.to"
            :to="link.to"
            class="flex items-center gap-3 px-3 py-2 text-gray-700 hover:bg-gray-100 rounded-md"
            @click="handleNavClick"
          >
            <i :class="link.icon"></i>
            <span>{{ link.label }}</span>
          </RouterLink>
        </nav>
      </aside>

      <!-- Main content -->
      <main class="flex-1 px-4 py-6 max-w-7xl w-full mx-auto min-w-0">
        <RouterView />
      </main>
    </div>
  </div>
</template>
