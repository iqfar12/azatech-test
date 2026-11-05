<script setup lang="ts">
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError } from '@/api/client'
import { productApi } from '@/api/endpoints'
import PaginationBar from '@/components/PaginationBar.vue'
import StateBlock from '@/components/StateBlock.vue'
import { formatDateTime, formatRupiah } from '@/lib/format'

const props = defineProps<{ id: number }>()
const route = useRoute()
const router = useRouter()
const queryClient = useQueryClient()

const page = computed(() => Math.max(1, Number(route.query.page) || 1))
const product = useQuery({ queryKey: computed(() => ['product', props.id]), queryFn: () => productApi.get(props.id) })
const movements = useQuery({
  queryKey: computed(() => ['stock-movements', props.id, page.value]),
  queryFn: () => productApi.movements(props.id, { page: page.value, per_page: 15 }),
  placeholderData: keepPreviousData,
})

const form = reactive<{ quantity_change: number | null; note: string }>({ quantity_change: null, note: '' })
const errors = ref<Record<string, string>>({})
const message = ref('')

const adjust = useMutation({
  mutationFn: () => productApi.adjustStock(props.id, { quantity_change: Number(form.quantity_change), note: form.note.trim() }),
  onSuccess: (result) => {
    message.value = `Stok diperbarui menjadi ${result.product.stock}.`
    form.quantity_change = null
    form.note = ''
    queryClient.setQueryData(['product', props.id], result.product)
    void queryClient.invalidateQueries({ queryKey: ['stock-movements', props.id] })
    void queryClient.invalidateQueries({ queryKey: ['products'] })
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
  },
  onError: (e) => {
    message.value = ''
    errors.value = e instanceof ApiError
      ? Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0] ?? '']))
      : { form: 'Gagal menyimpan penyesuaian.' }
    if (e instanceof ApiError && Object.keys(e.errors).length === 0) errors.value = { form: e.message }
  },
})

function onSubmit(): void {
  message.value = ''
  const next: Record<string, string> = {}
  if (!Number.isInteger(form.quantity_change) || form.quantity_change === 0) next.quantity_change = 'Isi perubahan stok (bukan 0), mis. 50 atau -3'
  if (form.note.trim().length < 3) next.note = 'Catatan wajib diisi (min. 3 karakter)'
  errors.value = next
  if (Object.keys(next).length === 0 && !adjust.isPending.value) adjust.mutate()
}

const typeLabel: Record<string, string> = {
  INITIAL: 'Stok awal',
  ORDER_OUT: 'Order',
  CANCEL_RESTORE: 'Batal order',
  ADJUSTMENT: 'Penyesuaian',
}
</script>

<template>
  <section class="space-y-6">
    <RouterLink to="/products" class="text-sm text-indigo-800 hover:underline">← Kembali ke produk</RouterLink>

    <StateBlock :loading="product.isPending.value" :error="product.error.value" @retry="product.refetch()">
      <div v-if="product.data.value" class="card">
        <h1 class="text-2xl font-bold">{{ product.data.value.product_name }}</h1>
        <p class="text-sm text-slate-600">
          {{ product.data.value.product_code }} · {{ formatRupiah(product.data.value.price) }} · batas minimum {{ product.data.value.low_stock_threshold }}
        </p>
        <p class="mt-2 text-3xl font-semibold" aria-live="polite">Stok: {{ product.data.value.stock }}</p>
      </div>
    </StateBlock>

    <form class="card grid grid-cols-1 gap-4 sm:grid-cols-6 sm:items-end" novalidate @submit.prevent="onSubmit">
      <h2 class="text-lg font-semibold sm:col-span-6">Stock adjustment (stock opname)</h2>
      <p v-if="errors.form" class="text-sm text-red-700 sm:col-span-6" role="alert">{{ errors.form }}</p>
      <p v-if="message" class="text-sm text-green-800 sm:col-span-6" role="status">{{ message }}</p>
      <div class="sm:col-span-2">
        <label for="qty-change" class="label">Perubahan (+/−)</label>
        <input id="qty-change" v-model.number="form.quantity_change" type="number" step="1" class="input" :aria-invalid="!!errors.quantity_change" aria-describedby="qty-change-error" />
        <p v-if="errors.quantity_change" id="qty-change-error" class="field-error">{{ errors.quantity_change }}</p>
      </div>
      <div class="sm:col-span-3">
        <label for="note" class="label">Catatan</label>
        <input id="note" v-model="form.note" class="input" placeholder="Stock opname bulanan" :aria-invalid="!!errors.note" aria-describedby="note-error" />
        <p v-if="errors.note" id="note-error" class="field-error">{{ errors.note }}</p>
      </div>
      <div class="sm:col-span-1">
        <button type="submit" class="btn-primary w-full" :disabled="adjust.isPending.value">Simpan</button>
      </div>
    </form>

    <section class="space-y-2" aria-labelledby="ledger-title">
      <h2 id="ledger-title" class="text-lg font-semibold">Riwayat pergerakan stok</h2>
      <StateBlock
        :loading="movements.isPending.value"
        :error="movements.error.value"
        :empty="movements.data.value?.data.length === 0"
        @retry="movements.refetch()"
      >
        <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
          <table class="table">
            <thead>
              <tr><th scope="col">Waktu</th><th scope="col">Tipe</th><th scope="col">Perubahan</th><th scope="col">Stok setelah</th><th scope="col">Referensi</th><th scope="col">Catatan</th><th scope="col">Oleh</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="m in movements.data.value?.data" :key="m.id">
                <td class="whitespace-nowrap">{{ formatDateTime(m.created_at) }}</td>
                <td>{{ typeLabel[m.type] ?? m.type }}</td>
                <td :class="m.quantity_change < 0 ? 'text-red-700' : 'text-green-800'">{{ m.quantity_change > 0 ? '+' : '' }}{{ m.quantity_change }}</td>
                <td>{{ m.stock_after }}</td>
                <td>
                  <RouterLink v-if="m.reference_type === 'order'" :to="`/orders/${m.reference_id}`" class="text-indigo-800 hover:underline">Order #{{ m.reference_id }}</RouterLink>
                  <span v-else>{{ m.reference_type ?? '—' }}</span>
                </td>
                <td>{{ m.note ?? '—' }}</td>
                <td>{{ m.created_by?.name ?? 'Sistem' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <PaginationBar v-if="movements.data.value" :meta="movements.data.value.meta" @change="(p) => router.replace({ query: { ...route.query, page: p } })" />
      </StateBlock>
    </section>
  </section>
</template>
