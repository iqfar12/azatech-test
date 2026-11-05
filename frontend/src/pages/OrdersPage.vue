<script setup lang="ts">
import { keepPreviousData, useQuery } from '@tanstack/vue-query'
import { orderApi } from '@/api/endpoints'
import type { OrderStatus } from '@/api/types'
import PaginationBar from '@/components/PaginationBar.vue'
import SortableTh from '@/components/SortableTh.vue'
import StateBlock from '@/components/StateBlock.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { useListQuery } from '@/composables/useListQuery'
import { formatDateTime, formatRupiah } from '@/lib/format'

const list = useListQuery({ sort: '-order_date', filters: ['status', 'date_from', 'date_to', 'customer_id'] })
const statuses: OrderStatus[] = ['PENDING', 'PAID', 'SHIPPED', 'COMPLETED', 'CANCELLED']

const orders = useQuery({
  queryKey: ['orders', list.params],
  queryFn: () => orderApi.list(list.params.value),
  placeholderData: keepPreviousData,
})

function setFilter(key: string, event: Event): void {
  list.update({ [key]: (event.target as HTMLInputElement | HTMLSelectElement).value || null })
}
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-bold">Order</h1>
      <RouterLink to="/orders/new" class="btn-primary">+ Buat Order</RouterLink>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div>
        <label for="o-search" class="label">No. order</label>
        <input id="o-search" v-model="list.searchInput.value" type="search" class="input" placeholder="INV-2026…" />
      </div>
      <div>
        <label for="o-status" class="label">Status</label>
        <select id="o-status" class="input" :value="list.filters.value.status" @change="setFilter('status', $event)">
          <option value="">Semua</option>
          <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
        </select>
      </div>
      <div>
        <label for="o-from" class="label">Dari tanggal</label>
        <input id="o-from" type="date" class="input" :value="list.filters.value.date_from" @change="setFilter('date_from', $event)" />
      </div>
      <div>
        <label for="o-to" class="label">Sampai tanggal</label>
        <input id="o-to" type="date" class="input" :value="list.filters.value.date_to" @change="setFilter('date_to', $event)" />
      </div>
    </div>

    <StateBlock
      :loading="orders.isPending.value"
      :error="orders.error.value"
      :empty="orders.data.value?.data.length === 0"
      empty-text="Tidak ada order untuk filter ini."
      @retry="orders.refetch()"
    >
      <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white" :aria-busy="orders.isFetching.value">
        <table class="table">
          <thead>
            <tr>
              <SortableTh label="No. order" :direction="list.sortDirection('order_number')" @sort="list.toggleSort('order_number')" />
              <SortableTh label="Tanggal" :direction="list.sortDirection('order_date')" @sort="list.toggleSort('order_date')" />
              <th scope="col">Pelanggan</th>
              <th scope="col">Item</th>
              <SortableTh label="Total" :direction="list.sortDirection('total_amount')" @sort="list.toggleSort('total_amount')" />
              <SortableTh label="Status" :direction="list.sortDirection('status')" @sort="list.toggleSort('status')" />
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="order in orders.data.value?.data" :key="order.id">
              <td>
                <RouterLink :to="`/orders/${order.id}`" class="font-mono text-indigo-800 hover:underline">{{ order.order_number }}</RouterLink>
              </td>
              <td class="whitespace-nowrap">{{ formatDateTime(order.order_date) }}</td>
              <td>{{ order.customer?.customer_name }}</td>
              <td>{{ order.items_count }}</td>
              <td>{{ formatRupiah(order.total_amount) }}</td>
              <td><StatusBadge :status="order.status" /></td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar v-if="orders.data.value" :meta="orders.data.value.meta" @change="(p) => list.update({ page: p }, false)" />
    </StateBlock>
  </section>
</template>
