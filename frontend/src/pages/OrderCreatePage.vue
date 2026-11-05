<script setup lang="ts">
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { customerApi, orderApi, productApi } from '@/api/endpoints'
import type { CreateOrderPayload } from '@/api/types'
import OrderForm from '@/components/OrderForm.vue'
import StateBlock from '@/components/StateBlock.vue'

const router = useRouter()
const queryClient = useQueryClient()

// The demo catalogue is small; a large catalogue would use a server-side search combobox instead.
const products = useQuery({
  queryKey: ['products', { per_page: 100, sort: 'product_name', purpose: 'order-form' }],
  queryFn: () => productApi.list({ per_page: 100, sort: 'product_name' }),
})
const customers = useQuery({
  queryKey: ['customers', { per_page: 100, sort: 'customer_name', purpose: 'order-form' }],
  queryFn: () => customerApi.list({ per_page: 100, sort: 'customer_name' }),
})

const loading = computed(() => products.isPending.value || customers.isPending.value)
const error = computed(() => products.error.value ?? customers.error.value)

async function submit(payload: CreateOrderPayload, idempotencyKey: string): Promise<void> {
  const order = await orderApi.create(payload, idempotencyKey)
  queryClient.setQueryData(['order', order.id], order)
  // Stock changed and a new order exists → everything showing those numbers is stale now.
  await Promise.all([
    queryClient.invalidateQueries({ queryKey: ['orders'] }),
    queryClient.invalidateQueries({ queryKey: ['products'] }),
    queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
  ])
  await router.push(`/orders/${order.id}`)
}
</script>

<template>
  <section class="space-y-4">
    <h1 class="text-2xl font-bold">Buat Order</h1>
    <StateBlock :loading="loading" :error="error" @retry="products.refetch(); customers.refetch()">
      <OrderForm :products="products.data.value?.data ?? []" :customers="customers.data.value?.data ?? []" :submit="submit" />
    </StateBlock>
  </section>
</template>
