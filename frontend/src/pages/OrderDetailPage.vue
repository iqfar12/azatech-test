<script setup lang="ts">
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { ApiError } from '@/api/client'
import { orderApi } from '@/api/endpoints'
import type { Order, OrderStatus } from '@/api/types'
import StateBlock from '@/components/StateBlock.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { formatDateTime, formatRupiah } from '@/lib/format'

const props = defineProps<{ id: number }>()
const queryClient = useQueryClient()

const order = useQuery({ queryKey: computed(() => ['order', props.id]), queryFn: () => orderApi.get(props.id) })
const actionError = ref('')
const showCancel = ref(false)
const cancelReason = ref('')
const cancelError = ref('')

const can = (action: Order['allowed_actions'][number]) => order.data.value?.allowed_actions.includes(action) ?? false

function onDone(updated: Order): void {
  queryClient.setQueryData(['order', props.id], updated)
  void queryClient.invalidateQueries({ queryKey: ['orders'] })
  void queryClient.invalidateQueries({ queryKey: ['products'] }) // cancel restores stock
  void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
}

function onFail(e: unknown): void {
  actionError.value = e instanceof ApiError ? e.message : 'Aksi gagal.'
  // 409: someone else changed the order meanwhile → show the real state.
  if (e instanceof ApiError && e.status === 409) void order.refetch()
}

const transition = useMutation({
  mutationFn: (action: 'pay' | 'ship' | 'complete') => orderApi.transition(props.id, action),
  onMutate: () => (actionError.value = ''),
  onSuccess: onDone,
  onError: onFail,
})

const cancel = useMutation({
  mutationFn: () => orderApi.cancel(props.id, cancelReason.value.trim()),
  onMutate: () => (actionError.value = ''),
  onSuccess: (updated) => {
    showCancel.value = false
    cancelReason.value = ''
    onDone(updated)
  },
  onError: onFail,
})

const busy = computed(() => transition.isPending.value || cancel.isPending.value)

function submitCancel(): void {
  cancelError.value = cancelReason.value.trim().length < 3 ? 'Alasan pembatalan wajib diisi (min. 3 karakter).' : ''
  if (!cancelError.value && !busy.value) cancel.mutate()
}

const downloading = ref(false)
async function downloadInvoice(): Promise<void> {
  if (!order.data.value) return
  downloading.value = true
  try {
    await orderApi.downloadInvoice(order.data.value)
  } catch (e) {
    actionError.value = e instanceof ApiError ? e.message : 'Gagal mengunduh invoice.'
  } finally {
    downloading.value = false
  }
}

const timelineSteps: { status: OrderStatus; label: string; at: (o: Order) => string | null }[] = [
  { status: 'PENDING', label: 'Dibuat', at: (o) => o.order_date },
  { status: 'PAID', label: 'Dibayar', at: (o) => o.paid_at },
  { status: 'SHIPPED', label: 'Dikirim', at: (o) => o.shipped_at },
  { status: 'COMPLETED', label: 'Selesai', at: (o) => o.completed_at },
]
</script>

<template>
  <section class="space-y-6">
    <RouterLink to="/orders" class="text-sm text-indigo-800 hover:underline">← Kembali ke daftar order</RouterLink>

    <StateBlock :loading="order.isPending.value" :error="order.error.value" @retry="order.refetch()">
      <template v-if="order.data.value">
        <header class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 class="font-mono text-2xl font-bold">{{ order.data.value.order_number }}</h1>
            <p class="text-sm text-slate-600">
              {{ order.data.value.customer?.customer_name }} · dibuat oleh {{ order.data.value.created_by?.name }} ·
              {{ formatDateTime(order.data.value.order_date) }}
            </p>
          </div>
          <StatusBadge :status="order.data.value.status" />
        </header>

        <p v-if="actionError" class="rounded-md bg-red-50 p-3 text-sm text-red-800" role="alert">{{ actionError }}</p>

        <!-- Buttons follow allowed_actions from the API (status × role); the API re-checks on click. -->
        <div class="flex flex-wrap gap-2">
          <button v-if="can('pay')" type="button" class="btn-primary" :disabled="busy" @click="transition.mutate('pay')">Tandai PAID</button>
          <button v-if="can('ship')" type="button" class="btn-primary" :disabled="busy" @click="transition.mutate('ship')">Tandai SHIPPED</button>
          <button v-if="can('complete')" type="button" class="btn-primary" :disabled="busy" @click="transition.mutate('complete')">Tandai COMPLETED</button>
          <button v-if="can('cancel')" type="button" class="btn-danger" :disabled="busy" :aria-expanded="showCancel" aria-controls="cancel-form" @click="showCancel = !showCancel">
            Batalkan order
          </button>
          <button v-if="can('download_invoice')" type="button" class="btn-secondary" :disabled="downloading" @click="downloadInvoice">
            {{ downloading ? 'Mengunduh…' : 'Unduh invoice' }}
          </button>
        </div>

        <form v-if="showCancel" id="cancel-form" class="card space-y-3 border-red-200" novalidate @submit.prevent="submitCancel">
          <label for="cancel-reason" class="label">Alasan pembatalan</label>
          <textarea id="cancel-reason" v-model="cancelReason" rows="2" class="input" :aria-invalid="!!cancelError" aria-describedby="cancel-error"></textarea>
          <p v-if="cancelError" id="cancel-error" class="field-error">{{ cancelError }}</p>
          <p class="text-sm text-slate-600">Stok seluruh item akan dikembalikan dan tercatat di ledger.</p>
          <div class="flex gap-2">
            <button type="submit" class="btn-danger" :disabled="busy">Konfirmasi batal</button>
            <button type="button" class="btn-secondary" @click="showCancel = false">Tutup</button>
          </div>
        </form>

        <section aria-labelledby="timeline-title" class="card">
          <h2 id="timeline-title" class="mb-3 text-lg font-semibold">Timeline status</h2>
          <ol v-if="order.data.value.status !== 'CANCELLED'" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <li v-for="step in timelineSteps" :key="step.status" class="flex items-start gap-2">
              <span :class="['mt-1 h-3 w-3 shrink-0 rounded-full', step.at(order.data.value) ? 'bg-indigo-700' : 'bg-slate-300']" aria-hidden="true"></span>
              <div>
                <p class="text-sm font-medium">{{ step.label }}</p>
                <p class="text-xs text-slate-600">{{ formatDateTime(step.at(order.data.value)) }}</p>
              </div>
            </li>
          </ol>
          <p v-else class="text-sm text-slate-700">
            Dibatalkan {{ formatDateTime(order.data.value.cancelled_at) }} — <em>{{ order.data.value.cancel_reason }}</em>
          </p>

          <h3 class="mt-4 text-sm font-semibold text-slate-700">Riwayat</h3>
          <ul class="mt-1 space-y-1 text-sm">
            <li v-for="(h, i) in order.data.value.status_history" :key="i">
              <time :datetime="h.created_at" class="text-slate-500">{{ formatDateTime(h.created_at) }}</time>
              — {{ h.from_status ?? '∅' }} → <strong>{{ h.to_status }}</strong>
              <span v-if="h.changed_by"> oleh {{ h.changed_by.name }}</span>
              <span v-if="h.note" class="text-slate-600"> ({{ h.note }})</span>
            </li>
          </ul>
        </section>

        <section aria-labelledby="items-title" class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
          <h2 id="items-title" class="sr-only">Item order</h2>
          <table class="table">
            <thead>
              <tr><th scope="col">Kode</th><th scope="col">Produk</th><th scope="col">Qty</th><th scope="col">Harga satuan</th><th scope="col">Subtotal</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="item in order.data.value.items" :key="item.id">
                <td class="font-mono">{{ item.product_code }}</td>
                <td>{{ item.product_name }}</td>
                <td>{{ item.quantity }}</td>
                <td>{{ formatRupiah(item.unit_price) }}</td>
                <td>{{ formatRupiah(item.subtotal) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4" class="text-right font-semibold">Total</td>
                <td class="font-semibold">{{ formatRupiah(order.data.value.total_amount) }}</td>
              </tr>
            </tfoot>
          </table>
        </section>
      </template>
    </StateBlock>
  </section>
</template>
