<script setup lang="ts">
import { computed } from 'vue'
import { ApiError, NetworkError } from '@/api/client'

/** One component for the three non-happy states of any data view. */
const props = defineProps<{
  loading?: boolean
  error?: unknown
  empty?: boolean
  emptyText?: string
}>()

const emit = defineEmits<{ retry: [] }>()

const errorMessage = computed(() => {
  if (props.error instanceof ApiError || props.error instanceof NetworkError) return props.error.message
  return props.error ? 'Terjadi kesalahan tak terduga.' : ''
})
const requestId = computed(() => (props.error instanceof ApiError ? props.error.requestId : null))
</script>

<template>
  <div v-if="loading" class="card animate-pulse text-sm text-slate-500" role="status" aria-live="polite">Memuat data…</div>
  <div v-else-if="error" class="card border-red-200 bg-red-50 text-sm text-red-800" role="alert">
    <p>{{ errorMessage }}</p>
    <p v-if="requestId" class="mt-1 text-xs text-red-700">Request ID: {{ requestId }}</p>
    <button type="button" class="btn-secondary mt-3" @click="emit('retry')">Coba lagi</button>
  </div>
  <div v-else-if="empty" class="card text-center text-sm text-slate-500">{{ emptyText ?? 'Belum ada data.' }}</div>
  <slot v-else />
</template>
