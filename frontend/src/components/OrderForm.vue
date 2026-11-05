<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { ApiError, NetworkError } from '@/api/client'
import type { CreateOrderPayload, Customer, Product } from '@/api/types'
import { formatRupiahCents, toCents } from '@/lib/format'
import { orderSchema, zodErrors } from '@/lib/orderSchema'
import { uuid } from '@/lib/uuid'

/**
 * Order form: dynamic product rows, real-time total, zod validation,
 * server errors shown per item, one Idempotency-Key per submit attempt,
 * and no double submit.
 *
 * The actual HTTP call is injected (`submit`) so the page owns caching/navigation
 * and tests can drive the form without a server.
 */
const props = defineProps<{
  products: Product[]
  customers: Customer[]
  submit: (payload: CreateOrderPayload, idempotencyKey: string) => Promise<void>
}>()

interface Row {
  uid: number
  product_id: number | null
  quantity: number | null
}

let nextUid = 1
const customerId = ref('')
const rows = reactive<Row[]>([{ uid: nextUid++, product_id: null, quantity: 1 }])
const errors = ref<Record<string, string>>({})
const formError = ref('')
const submitting = ref(false)

const productsById = computed(() => new Map(props.products.map((p) => [p.id, p])))

function lineSubtotalCents(row: Row): number {
  const product = row.product_id ? productsById.value.get(row.product_id) : undefined
  return product && row.quantity && row.quantity > 0 ? toCents(product.price) * row.quantity : 0
}

// Display only — the server recomputes prices and totals from its own data.
const totalCents = computed(() => rows.reduce((sum, row) => sum + lineSubtotalCents(row), 0))

function addRow(): void {
  rows.push({ uid: nextUid++, product_id: null, quantity: 1 })
}

function removeRow(index: number): void {
  if (rows.length > 1) rows.splice(index, 1)
  errors.value = {}
}

function fieldError(key: string): string | undefined {
  return errors.value[key]
}

function validate(): CreateOrderPayload | null {
  const candidate = {
    customer_id: customerId.value,
    items: rows.map((row) => ({ product_id: row.product_id ?? 0, quantity: row.quantity ?? Number.NaN })),
  }
  const parsed = orderSchema.safeParse(candidate)
  const next: Record<string, string> = parsed.success ? {} : zodErrors(parsed.error)

  // Fast feedback on stock; the server re-checks under lock and stays authoritative.
  candidate.items.forEach((item, index) => {
    const product = productsById.value.get(item.product_id)
    if (product && item.quantity > product.stock && !next[`items.${index}.quantity`]) {
      next[`items.${index}.quantity`] = `Stok ${product.product_name} tersisa ${product.stock}`
    }
  })

  errors.value = next
  return Object.keys(next).length === 0 && parsed.success ? parsed.data : null
}

async function onSubmit(): Promise<void> {
  if (submitting.value) return // double-submit guard (button is also disabled)
  formError.value = ''
  const payload = validate()
  if (!payload) return

  submitting.value = true
  // New key per submit attempt. Automatic retries of THIS attempt reuse it, so a
  // timeout followed by a retry can never create the order twice.
  const key = uuid()
  try {
    await submitWithRetry(payload, key)
  } catch (error) {
    if (error instanceof ApiError) {
      formError.value = error.message
      errors.value = Object.fromEntries(Object.entries(error.errors).map(([k, messages]) => [k, messages[0] ?? '']))
    } else if (error instanceof NetworkError) {
      formError.value = error.message
    } else {
      formError.value = 'Terjadi kesalahan tak terduga.'
    }
  } finally {
    submitting.value = false
  }
}

async function submitWithRetry(payload: CreateOrderPayload, key: string, attempt = 1): Promise<void> {
  try {
    await props.submit(payload, key)
  } catch (error) {
    const retriable = error instanceof NetworkError || (error instanceof ApiError && error.status === 409 && 'Idempotency-Key' in error.errors)
    if (retriable && attempt < 3) {
      await new Promise((resolve) => setTimeout(resolve, 400 * attempt))
      return submitWithRetry(payload, key, attempt + 1)
    }
    throw error
  }
}
</script>

<template>
  <form class="space-y-4" novalidate @submit.prevent="onSubmit">
    <div v-if="formError" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
      {{ formError }}
    </div>

    <div class="card">
      <label for="customer" class="label">Pelanggan</label>
      <select
        id="customer"
        v-model="customerId"
        class="input"
        :aria-invalid="!!fieldError('customer_id')"
        aria-describedby="customer-error"
      >
        <option value="" disabled>— Pilih pelanggan —</option>
        <option v-for="customer in customers" :key="customer.id" :value="customer.id">
          {{ customer.customer_name }}{{ customer.phone ? ` · ${customer.phone}` : '' }}
        </option>
      </select>
      <p v-if="fieldError('customer_id')" id="customer-error" class="field-error">{{ fieldError('customer_id') }}</p>
    </div>

    <fieldset class="card space-y-3">
      <legend class="px-1 text-sm font-semibold text-slate-700">Produk</legend>
      <p v-if="fieldError('items')" class="field-error" role="alert">{{ fieldError('items') }}</p>

      <div
        v-for="(row, index) in rows"
        :key="row.uid"
        class="grid grid-cols-1 gap-3 border-b border-slate-100 pb-3 last:border-b-0 sm:grid-cols-12 sm:items-start"
        :data-testid="`row-${index}`"
      >
        <div class="sm:col-span-6">
          <label :for="`product-${row.uid}`" class="label">Produk baris {{ index + 1 }}</label>
          <select
            :id="`product-${row.uid}`"
            v-model.number="row.product_id"
            class="input"
            :aria-invalid="!!fieldError(`items.${index}.product_id`)"
            :aria-describedby="`product-error-${row.uid}`"
          >
            <option :value="null" disabled>— Pilih produk —</option>
            <option v-for="product in products" :key="product.id" :value="product.id" :disabled="product.stock === 0">
              {{ product.product_code }} · {{ product.product_name }} (stok {{ product.stock }})
            </option>
          </select>
          <p v-if="fieldError(`items.${index}.product_id`)" :id="`product-error-${row.uid}`" class="field-error">
            {{ fieldError(`items.${index}.product_id`) }}
          </p>
          <p v-if="fieldError(`items.${index}`)" class="field-error">{{ fieldError(`items.${index}`) }}</p>
        </div>

        <div class="sm:col-span-2">
          <label :for="`qty-${row.uid}`" class="label">Jumlah</label>
          <input
            :id="`qty-${row.uid}`"
            v-model.number="row.quantity"
            type="number"
            min="1"
            step="1"
            inputmode="numeric"
            class="input"
            :aria-invalid="!!fieldError(`items.${index}.quantity`)"
            :aria-describedby="`qty-error-${row.uid}`"
          />
          <p v-if="fieldError(`items.${index}.quantity`)" :id="`qty-error-${row.uid}`" class="field-error">
            {{ fieldError(`items.${index}.quantity`) }}
          </p>
        </div>

        <div class="sm:col-span-3">
          <span class="label">Subtotal</span>
          <p class="py-2 text-sm font-medium" :data-testid="`subtotal-${index}`">{{ formatRupiahCents(lineSubtotalCents(row)) }}</p>
        </div>

        <div class="sm:col-span-1 sm:pt-6">
          <button
            type="button"
            class="btn-secondary w-full"
            :disabled="rows.length === 1"
            :aria-label="`Hapus baris ${index + 1}`"
            @click="removeRow(index)"
          >
            ✕
          </button>
        </div>
      </div>

      <button type="button" class="btn-secondary" :disabled="rows.length >= 50" @click="addRow">+ Tambah produk</button>
    </fieldset>

    <div class="card flex flex-col items-start justify-between gap-3 sm:flex-row sm:items-center">
      <p class="text-lg">
        Total: <strong data-testid="order-total" aria-live="polite">{{ formatRupiahCents(totalCents) }}</strong>
        <span class="block text-xs text-slate-500">Harga final dihitung oleh server.</span>
      </p>
      <button type="submit" class="btn-primary" :disabled="submitting" :aria-busy="submitting">
        {{ submitting ? 'Menyimpan…' : 'Buat Order' }}
      </button>
    </div>
  </form>
</template>
