<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, NetworkError } from '@/api/client'
import { session } from '@/auth/session'

const route = useRoute()
const router = useRouter()
const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

const expired = computed(() => route.query.expired === '1')

async function onSubmit(): Promise<void> {
  if (loading.value) return
  error.value = ''
  loading.value = true
  try {
    await session.login(email.value.trim(), password.value)
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/'
    await router.replace(redirect)
  } catch (e) {
    error.value = e instanceof ApiError && e.status === 429
      ? 'Terlalu banyak percobaan login. Coba lagi dalam 1 menit.'
      : e instanceof ApiError || e instanceof NetworkError ? e.message : 'Login gagal.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="flex min-h-screen items-center justify-center px-4">
    <form class="card w-full max-w-sm space-y-4" novalidate @submit.prevent="onSubmit">
      <h1 class="text-xl font-bold text-indigo-800">Azatech Online Shop</h1>

      <p v-if="expired" class="rounded-md bg-amber-50 p-3 text-sm text-amber-900" role="status">
        Sesi Anda telah berakhir. Silakan login kembali.
      </p>
      <p v-if="error" class="rounded-md bg-red-50 p-3 text-sm text-red-800" role="alert">{{ error }}</p>

      <div>
        <label for="email" class="label">Email</label>
        <input id="email" v-model="email" type="email" autocomplete="username" required class="input" />
      </div>
      <div>
        <label for="password" class="label">Password</label>
        <input id="password" v-model="password" type="password" autocomplete="current-password" required class="input" />
      </div>
      <button type="submit" class="btn-primary w-full" :disabled="loading || !email || !password">
        {{ loading ? 'Memproses…' : 'Login' }}
      </button>
      <p class="text-xs text-slate-500">Demo: admin@azatech.id / cashier@azatech.id — password: <code>password</code></p>
    </form>
  </main>
</template>
