<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { dashboardApi } from '@/api/endpoints'
import { session } from '@/auth/session'
import StateBlock from '@/components/StateBlock.vue'
import { formatDateTime, formatRupiah } from '@/lib/format'

const summary = useQuery({ queryKey: ['dashboard'], queryFn: dashboardApi.summary })
const alerts = useQuery({
  queryKey: ['low-stock-alerts'],
  queryFn: dashboardApi.lowStockAlerts,
  enabled: computed(() => session.isAdmin.value),
})

const cards = computed(() => {
  const s = summary.data.value
  if (!s) return []
  return [
    { label: 'Order hari ini', value: String(s.orders_today) },
    { label: 'Omzet hari ini', value: formatRupiah(s.revenue_today) },
    { label: 'Omzet bulan ini', value: formatRupiah(s.revenue_this_month) },
    { label: 'Menunggu pembayaran', value: String(s.pending_orders) },
    { label: 'Menunggu pengiriman', value: String(s.awaiting_shipment) },
    { label: 'Produk stok menipis', value: `${s.low_stock_products} / ${s.total_products}` },
    { label: 'Total pelanggan', value: String(s.total_customers) },
  ]
})
</script>

<template>
  <section class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-bold">Dashboard</h1>
      <RouterLink to="/orders/new" class="btn-primary">+ Buat Order</RouterLink>
    </div>

    <StateBlock :loading="summary.isPending.value" :error="summary.error.value" @retry="summary.refetch()">
      <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div v-for="card in cards" :key="card.label" class="card">
          <dt class="text-sm text-slate-600">{{ card.label }}</dt>
          <dd class="mt-1 text-2xl font-semibold">{{ card.value }}</dd>
        </div>
      </dl>
      <p class="mt-2 text-xs text-slate-500">Diperbarui {{ formatDateTime(summary.data.value?.generated_at) }} (cache)</p>
    </StateBlock>

    <section v-if="session.isAdmin.value" aria-labelledby="alerts-title" class="space-y-2">
      <h2 id="alerts-title" class="text-lg font-semibold">Notifikasi stok menipis</h2>
      <StateBlock
        :loading="alerts.isPending.value"
        :error="alerts.error.value"
        :empty="(alerts.data.value ?? []).length === 0"
        empty-text="Tidak ada notifikasi stok."
        @retry="alerts.refetch()"
      >
        <ul class="card divide-y divide-slate-100">
          <li v-for="alert in alerts.data.value" :key="alert.id" class="flex flex-wrap justify-between gap-2 py-2 text-sm">
            <span>
              <RouterLink :to="`/products/${alert.product_id}/stock`" class="font-medium text-indigo-800 hover:underline">
                {{ alert.product_code }} · {{ alert.product_name }}
              </RouterLink>
              turun ke {{ alert.stock_at_alert }} (batas {{ alert.threshold }}), stok kini {{ alert.current_stock }}
            </span>
            <time class="text-slate-500" :datetime="alert.created_at">{{ formatDateTime(alert.created_at) }}</time>
          </li>
        </ul>
      </StateBlock>
    </section>
  </section>
</template>
