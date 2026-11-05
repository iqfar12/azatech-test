<script setup lang="ts">
import type { PaginationMeta } from '@/api/types'

defineProps<{ meta: PaginationMeta }>()
const emit = defineEmits<{ change: [page: number] }>()
</script>

<template>
  <nav class="mt-3 flex items-center justify-between text-sm" aria-label="Paginasi">
    <p class="text-slate-600">
      Halaman {{ meta.page }} dari {{ Math.max(1, meta.total_pages) }} · {{ meta.total }} data
    </p>
    <div class="flex gap-2">
      <button type="button" class="btn-secondary" :disabled="meta.page <= 1" @click="emit('change', meta.page - 1)">
        Sebelumnya
      </button>
      <button type="button" class="btn-secondary" :disabled="meta.page >= meta.total_pages" @click="emit('change', meta.page + 1)">
        Berikutnya
      </button>
    </div>
  </nav>
</template>
