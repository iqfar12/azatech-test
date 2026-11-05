<script setup lang="ts">
import { keepPreviousData, useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { reportApi } from '@/api/endpoints'
import BarChart from '@/components/BarChart.vue'
import StateBlock from '@/components/StateBlock.vue'
import { formatRupiah, isoDateInJakarta, toCents } from '@/lib/format'

const route = useRoute()
const router = useRouter()

const today = new Date()
const defaultFrom = isoDateInJakarta(new Date(today.getTime() - 29 * 86_400_000))
const defaultTo = isoDateInJakarta(today)

const q = (key: string, fallback: string) => (typeof route.query[key] === 'string' ? (route.query[key] as string) : fallback)
const from = computed(() => q('from', defaultFrom))
const to = computed(() => q('to', defaultTo))
const groupBy = computed(() => (q('group_by', 'day') === 'month' ? 'month' : 'day') as 'day' | 'month')
const sortBy = computed(() => (q('sort_by', 'quantity') === 'revenue' ? 'revenue' : 'quantity') as 'quantity' | 'revenue')

function set(key: string, value: string): void {
  void router.replace({ query: { ...route.query, [key]: value } })
}

const sales = useQuery({
  queryKey: computed(() => ['reports', 'sales', from.value, to.value, groupBy.value]),
  queryFn: () => reportApi.sales({ from: from.value, to: to.value, group_by: groupBy.value }),
  placeholderData: keepPreviousData,
})
const top = useQuery({
  queryKey: computed(() => ['reports', 'top', from.value, to.value, sortBy.value]),
  queryFn: () => reportApi.topProducts({ from: from.value, to: to.value, limit: 10, sort_by: sortBy.value }),
  placeholderData: keepPreviousData,
})

const points = computed(() =>
  (sales.data.value?.rows ?? []).map((row) => ({
    label: row.period,
    value: toCents(row.revenue),
    display: `${formatRupiah(row.revenue)} (${row.total_orders} order)`,
  })),
)
</script>

<template>
  <section class="space-y-6">
    <h1 class="text-2xl font-bold">Laporan Penjualan</h1>

    <div class="card grid grid-cols-1 gap-3 sm:grid-cols-4">
      <div>
        <label for="r-from" class="label">Dari</label>
        <input id="r-from" type="date" class="input" :value="from" :max="to" @change="set('from', ($event.target as HTMLInputElement).value)" />
      </div>
      <div>
        <label for="r-to" class="label">Sampai</label>
        <input id="r-to" type="date" class="input" :value="to" :min="from" @change="set('to', ($event.target as HTMLInputElement).value)" />
      </div>
      <div>
        <label for="r-group" class="label">Kelompokkan per</label>
        <select id="r-group" class="input" :value="groupBy" @change="set('group_by', ($event.target as HTMLSelectElement).value)">
          <option value="day">Hari</option>
          <option value="month">Bulan</option>
        </select>
      </div>
      <p class="self-end text-xs text-slate-500">Hanya order PAID, SHIPPED, dan COMPLETED. Zona waktu Asia/Jakarta.</p>
    </div>

    <StateBlock :loading="sales.isPending.value" :error="sales.error.value" @retry="sales.refetch()">
      <div v-if="sales.data.value" class="card space-y-4">
        <dl class="flex flex-wrap gap-8">
          <div><dt class="text-sm text-slate-600">Total order</dt><dd class="text-2xl font-semibold">{{ sales.data.value.summary.total_orders }}</dd></div>
          <div><dt class="text-sm text-slate-600">Total omzet</dt><dd class="text-2xl font-semibold">{{ formatRupiah(sales.data.value.summary.revenue) }}</dd></div>
        </dl>
        <BarChart :title="`Omzet per ${groupBy === 'day' ? 'hari' : 'bulan'}`" :points="points" />
      </div>
    </StateBlock>

    <section aria-labelledby="top-title" class="space-y-2">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <h2 id="top-title" class="text-lg font-semibold">Produk terlaris</h2>
        <div>
          <label for="r-sort" class="label">Urutkan berdasarkan</label>
          <select id="r-sort" class="input" :value="sortBy" @change="set('sort_by', ($event.target as HTMLSelectElement).value)">
            <option value="quantity">Jumlah terjual</option>
            <option value="revenue">Omzet</option>
          </select>
        </div>
      </div>
      <StateBlock :loading="top.isPending.value" :error="top.error.value" :empty="top.data.value?.length === 0" @retry="top.refetch()">
        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
          <table class="table">
            <thead>
              <tr><th scope="col">#</th><th scope="col">Produk</th><th scope="col">Qty terjual</th><th scope="col">Omzet</th><th scope="col">Jumlah order</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="(p, i) in top.data.value" :key="p.product_id">
                <td>{{ i + 1 }}</td>
                <td>{{ p.product_code }} · {{ p.product_name }}</td>
                <td>{{ p.quantity_sold }}</td>
                <td>{{ formatRupiah(p.revenue) }}</td>
                <td>{{ p.order_count }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </StateBlock>
    </section>
  </section>
</template>
