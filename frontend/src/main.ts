import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { createApp } from 'vue'
import { ApiError } from '@/api/client'
import { session } from '@/auth/session'
import App from './App.vue'
import { router } from './router'
import './style.css'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: true,
      // Never retry client errors (401/403/404/422); retry transient failures twice.
      retry: (failureCount, error) => !(error instanceof ApiError && error.status < 500) && failureCount < 2,
    },
    mutations: { retry: false },
  },
})

// Never show the previous user's cached data after a forced logout.
session.onExpired(() => queryClient.clear())

createApp(App).use(router).use(VueQueryPlugin, { queryClient }).mount('#app')
