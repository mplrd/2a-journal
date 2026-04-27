import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const LoginView = () => import('@/views/LoginView.vue')
const UsersView = () => import('@/views/UsersView.vue')
const SettingsView = () => import('@/views/SettingsView.vue')

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: '/users' },
    { path: '/login', name: 'login', component: LoginView, meta: { public: true } },
    { path: '/users', name: 'users', component: UsersView },
    { path: '/settings', name: 'settings', component: SettingsView },
    { path: '/:pathMatch(.*)*', redirect: '/users' },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  // Wait for the initial session check to settle (refresh token via cookie)
  if (!auth.initialized) {
    await auth.initSession()
  }

  if (to.meta.public) {
    // If already logged in as admin, skip the login page
    if (to.name === 'login' && auth.isAuthenticated && auth.isAdmin) {
      return { name: 'users' }
    }
    return true
  }

  if (!auth.isAuthenticated || !auth.isAdmin) {
    return { name: 'login' }
  }

  return true
})

export default router
