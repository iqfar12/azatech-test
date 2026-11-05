<script setup lang="ts">
import type { OrderStatus } from '@/api/types'

defineProps<{ status: OrderStatus }>()

const styles: Record<OrderStatus, string> = {
  PENDING: 'bg-amber-100 text-amber-900',
  PAID: 'bg-blue-100 text-blue-900',
  SHIPPED: 'bg-violet-100 text-violet-900',
  COMPLETED: 'bg-green-100 text-green-900',
  CANCELLED: 'bg-slate-200 text-slate-800',
}
</script>

<template>
  <span :class="['inline-block rounded-full px-2 py-0.5 text-xs font-semibold', styles[status]]">{{ status }}</span>
</template>
