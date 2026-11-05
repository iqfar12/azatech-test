<script setup lang="ts">
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { z } from 'zod'
import { ApiError } from '@/api/client'
import { productApi } from '@/api/endpoints'
import StateBlock from '@/components/StateBlock.vue'
import { zodErrors } from '@/lib/orderSchema'

const props = defineProps<{ id?: number }>()
const router = useRouter()
const queryClient = useQueryClient()
const isEdit = computed(() => props.id !== undefined)

const form = reactive({ product_code: '', product_name: '', price: '', low_stock_threshold: 5, stock: 0 })
const errors = ref<Record<string, string>>({})
const formError = ref('')

const schema = z.object({
  product_code: z.string().trim().min(1, 'Kode wajib diisi').max(30).regex(/^[A-Za-z0-9-]+$/, 'Hanya huruf, angka, dan -'),
  product_name: z.string().trim().min(2, 'Nama minimal 2 karakter').max(150),
  price: z.string().regex(/^\d+(\.\d{1,2})?$/, 'Harga tidak valid (maks. 2 desimal)').refine((v) => Number(v) > 0, 'Harga harus > 0'),
  low_stock_threshold: z.number({ invalid_type_error: 'Wajib angka' }).int().min(0),
  stock: z.number({ invalid_type_error: 'Wajib angka' }).int().min(0),
})

const existing = useQuery({
  queryKey: computed(() => ['product', props.id]),
  queryFn: () => productApi.get(props.id as number),
  enabled: isEdit,
})

watch(
  () => existing.data.value,
  (product) => {
    if (!product) return
    Object.assign(form, {
      product_code: product.product_code,
      product_name: product.product_name,
      price: product.price,
      low_stock_threshold: product.low_stock_threshold,
    })
  },
  { immediate: true },
)

const save = useMutation({
  mutationFn: () => {
    const body = { ...form, price: String(form.price) }
    if (isEdit.value) {
      const { stock: _ignored, ...rest } = body
      void _ignored
      return productApi.update(props.id as number, rest)
    }
    return productApi.create(body)
  },
  onSuccess: (product) => {
    void queryClient.invalidateQueries({ queryKey: ['products'] })
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    queryClient.setQueryData(['product', product.id], product)
    void router.push('/products')
  },
  onError: (e) => {
    formError.value = e instanceof ApiError ? e.message : 'Gagal menyimpan.'
    if (e instanceof ApiError) errors.value = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0] ?? '']))
  },
})

function onSubmit(): void {
  formError.value = ''
  const parsed = schema.safeParse({ ...form, price: String(form.price) })
  errors.value = parsed.success ? {} : zodErrors(parsed.error)
  if (parsed.success && !save.isPending.value) save.mutate()
}
</script>

<template>
  <section class="max-w-xl space-y-4">
    <h1 class="text-2xl font-bold">{{ isEdit ? 'Ubah Produk' : 'Tambah Produk' }}</h1>

    <StateBlock :loading="isEdit && existing.isPending.value" :error="existing.error.value" @retry="existing.refetch()">
      <form class="card space-y-4" novalidate @submit.prevent="onSubmit">
        <p v-if="formError" class="rounded-md bg-red-50 p-3 text-sm text-red-800" role="alert">{{ formError }}</p>

        <div>
          <label for="code" class="label">Kode produk</label>
          <input id="code" v-model="form.product_code" class="input uppercase" :aria-invalid="!!errors.product_code" aria-describedby="code-error" />
          <p v-if="errors.product_code" id="code-error" class="field-error">{{ errors.product_code }}</p>
        </div>
        <div>
          <label for="name" class="label">Nama produk</label>
          <input id="name" v-model="form.product_name" class="input" :aria-invalid="!!errors.product_name" aria-describedby="name-error" />
          <p v-if="errors.product_name" id="name-error" class="field-error">{{ errors.product_name }}</p>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <label for="price" class="label">Harga (Rp)</label>
            <input id="price" v-model="form.price" inputmode="decimal" class="input" :aria-invalid="!!errors.price" aria-describedby="price-error" />
            <p v-if="errors.price" id="price-error" class="field-error">{{ errors.price }}</p>
          </div>
          <div>
            <label for="threshold" class="label">Batas stok minimum</label>
            <input id="threshold" v-model.number="form.low_stock_threshold" type="number" min="0" class="input" :aria-invalid="!!errors.low_stock_threshold" />
            <p v-if="errors.low_stock_threshold" class="field-error">{{ errors.low_stock_threshold }}</p>
          </div>
          <div v-if="!isEdit">
            <label for="stock" class="label">Stok awal</label>
            <input id="stock" v-model.number="form.stock" type="number" min="0" class="input" :aria-invalid="!!errors.stock" />
            <p v-if="errors.stock" class="field-error">{{ errors.stock }}</p>
          </div>
        </div>
        <p v-if="isEdit" class="text-sm text-slate-600">Stok hanya bisa diubah melalui menu <strong>Stok</strong> (stock adjustment) agar tercatat di ledger.</p>

        <div class="flex gap-2">
          <button type="submit" class="btn-primary" :disabled="save.isPending.value">{{ save.isPending.value ? 'Menyimpan…' : 'Simpan' }}</button>
          <RouterLink to="/products" class="btn-secondary">Batal</RouterLink>
        </div>
      </form>
    </StateBlock>
  </section>
</template>
