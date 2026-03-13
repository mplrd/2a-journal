import { createRouter, createWebHistory } from 'vue-router'
import { api } from '@/services/api'
import pinia from '@/stores'
import { useAuthStore } from '@/stores/auth'
import { useOnboarding } from '@/composables/useOnboarding'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { guest: true },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/RegisterView.vue'),
      meta: { guest: true },
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('@/views/ForgotPasswordView.vue'),
      meta: { guest: true },
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('@/views/ResetPasswordView.vue'),
      meta: { guest: true },
    },
    {
      path: '/verify-email',
      name: 'verify-email',
      component: () => import('@/views/VerifyEmailView.vue'),
    },
    {
      path: '/',
      component: () => import('@/components/layout/AppLayout.vue'),
      meta: { auth: true },
      children: [
        {
          path: '',
          name: 'dashboard',
          component: () => import('@/views/DashboardView.vue'),
        },
        {
          path: 'accounts',
          name: 'accounts',
          component: () => import('@/views/AccountsView.vue'),
        },
        {
          path: 'positions',
          name: 'positions',
          component: () => import('@/views/PositionsView.vue'),
        },
        {
          path: 'orders',
          name: 'orders',
          component: () => import('@/views/OrdersView.vue'),
        },
        {
          path: 'trades',
          name: 'trades',
          component: () => import('@/views/TradesView.vue'),
        },
        {
          path: 'performance',
          name: 'performance',
          component: () => import('@/views/PerformanceView.vue'),
        },
        {
          path: 'account',
          name: 'account',
          component: () => import('@/views/AccountView.vue'),
        },
      ],
    },
  ],
})

let initPromise = null

router.beforeEach(async (to) => {
  const authStore = useAuthStore(pinia)

  // On first navigation, attempt silent refresh to restore session
  if (!authStore.initialized) {
    if (!initPromise) {
      initPromise = authStore.initSession()
    }
    await initPromise
  }

  const token = api.getAccessToken()

  if (to.meta.auth && !token) {
    return { name: 'login' }
  }

  if (to.meta.auth && token) {
    const { ensureAccountsLoaded, isOnboarding, isRouteAllowed, onboardingRoute } = useOnboarding()
    await ensureAccountsLoaded()

    if (isOnboarding.value && !isRouteAllowed(to.name)) {
      return onboardingRoute.value
    }
  }

  if (to.meta.guest && token) {
    const { isOnboarding, onboardingRoute } = useOnboarding()
    if (isOnboarding.value && onboardingRoute.value) {
      return onboardingRoute.value
    }
    return { name: 'dashboard' }
  }
})

export default router
