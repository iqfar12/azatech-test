<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import type { Role } from '@/api/types'
import { session } from '@/auth/session'

const router = useRouter()
const queryClient = useQueryClient()
const menuOpen = ref(false)

const links: { to: string; label: string; roles?: Role[] }[] = [
  { to: '/', label: 'Dashboard' },
  { to: '/orders', label: 'Order' },
  { to: '/products', label: 'Produk' },
  { to: '/customers', label: 'Pelanggan' },
  { to: '/reports', label: 'Laporan', roles: ['admin'] },
]

// Menu adapts to the role (the API enforces the same rules independently).
const visibleLinks = computed(() => links.filter((link) => !link.roles || session.hasRole(...link.roles)))

async function logout(): Promise<void> {
  await session.logout()
  queryClient.clear()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:rounded focus:bg-white focus:px-3 focus:py-2">
      Lewati ke konten
    </a>
    <header class="border-b border-slate-200 bg-white">
      <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3">
        <RouterLink to="/" class="text-lg font-bold text-indigo-800">Azatech Shop</RouterLink>

        <button
          type="button"
          class="btn-secondary md:hidden"
          :aria-expanded="menuOpen"
          aria-controls="main-nav"
          @click="menuOpen = !menuOpen"
        >
          Menu
        </button>

        <nav
          id="main-nav"
          aria-label="Navigasi utama"
          :class="[menuOpen ? 'flex' : 'hidden', 'absolute left-0 right-0 top-14 z-10 flex-col gap-1 border-b border-slate-200 bg-white p-3 md:static md:flex md:flex-row md:border-0 md:p-0']"
        >
          <RouterLink
            v-for="link in visibleLinks"
            :key="link.to"
            :to="link.to"
            class="rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
            :active-class="link.to === '/' ? '' : 'bg-indigo-50 text-indigo-800'"
            exact-active-class="bg-indigo-50 text-indigo-800"
            @click="menuOpen = false"
          >
            {{ link.label }}
          </RouterLink>
        </nav>

        <div class="hidden items-center gap-3 md:flex">
          <span class="text-sm text-slate-600">
            {{ session.state.user?.name }}
            <span class="ml-1 rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold uppercase">{{ session.role.value }}</span>
          </span>
          <button type="button" class="btn-secondary" @click="logout">Logout</button>
        </div>
      </div>
    </header>

    <main id="main" class="mx-auto max-w-7xl px-4 py-6">
      <RouterView />
    </main>

    <div class="mx-auto max-w-7xl px-4 pb-6 md:hidden">
      <button type="button" class="btn-secondary w-full" @click="logout">Logout ({{ session.state.user?.name }})</button>
    </div>
  </div>
</template>
