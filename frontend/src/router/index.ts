import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import type { Role } from '@/api/types'
import { session } from '@/auth/session'

declare module 'vue-router' {
  interface RouteMeta {
    /** Route is reachable without login. */
    public?: boolean
    /** Roles allowed to open the page (UI guard only — the API enforces the same rules). */
    roles?: Role[]
    title?: string
  }
}

const routes: RouteRecordRaw[] = [
  { path: '/login', name: 'login', component: () => import('@/pages/LoginPage.vue'), meta: { public: true, title: 'Login' } },
  {
    path: '/',
    component: () => import('@/components/AppLayout.vue'),
    children: [
      { path: '', name: 'dashboard', component: () => import('@/pages/DashboardPage.vue'), meta: { title: 'Dashboard' } },
      { path: 'orders', name: 'orders', component: () => import('@/pages/OrdersPage.vue'), meta: { title: 'Order' } },
      { path: 'orders/new', name: 'order-create', component: () => import('@/pages/OrderCreatePage.vue'), meta: { title: 'Buat Order' } },
      { path: 'orders/:id(\\d+)', name: 'order-detail', component: () => import('@/pages/OrderDetailPage.vue'), props: (r) => ({ id: Number(r.params.id) }), meta: { title: 'Detail Order' } },
      { path: 'products', name: 'products', component: () => import('@/pages/ProductsPage.vue'), meta: { title: 'Produk' } },
      { path: 'products/new', name: 'product-create', component: () => import('@/pages/ProductFormPage.vue'), meta: { title: 'Tambah Produk', roles: ['admin'] } },
      { path: 'products/:id(\\d+)/edit', name: 'product-edit', component: () => import('@/pages/ProductFormPage.vue'), props: (r) => ({ id: Number(r.params.id) }), meta: { title: 'Ubah Produk', roles: ['admin'] } },
      { path: 'products/:id(\\d+)/stock', name: 'product-stock', component: () => import('@/pages/ProductStockPage.vue'), props: (r) => ({ id: Number(r.params.id) }), meta: { title: 'Stok & Ledger', roles: ['admin'] } },
      { path: 'customers', name: 'customers', component: () => import('@/pages/CustomersPage.vue'), meta: { title: 'Pelanggan' } },
      { path: 'customers/new', name: 'customer-create', component: () => import('@/pages/CustomerFormPage.vue'), meta: { title: 'Tambah Pelanggan' } },
      { path: 'customers/:id/edit', name: 'customer-edit', component: () => import('@/pages/CustomerFormPage.vue'), props: true, meta: { title: 'Ubah Pelanggan' } },
      { path: 'reports', name: 'reports', component: () => import('@/pages/ReportsPage.vue'), meta: { title: 'Laporan', roles: ['admin'] } },
      { path: 'forbidden', name: 'forbidden', component: () => import('@/pages/ForbiddenPage.vue'), meta: { title: 'Akses ditolak' } },
    ],
  },
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/NotFoundPage.vue'), meta: { public: true, title: 'Tidak ditemukan' } },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach((to) => {
  if (!to.meta.public && !session.isAuthenticated.value) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.name === 'login' && session.isAuthenticated.value) {
    return { name: 'dashboard' }
  }
  if (to.meta.roles && !session.hasRole(...to.meta.roles)) {
    return { name: 'forbidden' }
  }
  return true
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · Azatech Shop` : 'Azatech Shop'
})

// Forced logout (expired / revoked token) → back to login, remembering where the user was.
session.onExpired(() => {
  const current = router.currentRoute.value
  void router.replace({ name: 'login', query: { expired: '1', redirect: current.fullPath } })
})
