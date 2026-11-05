<script setup lang="ts">
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { ref } from 'vue'
import { ApiError } from '@/api/client'
import { productApi } from '@/api/endpoints'
import type { Product } from '@/api/types'
import { session } from '@/auth/session'
import PaginationBar from '@/components/PaginationBar.vue'
import SortableTh from '@/components/SortableTh.vue'
import StateBlock from '@/components/StateBlock.vue'
import { useListQuery } from '@/composables/useListQuery'
import { formatRupiah } from '@/lib/format'

const list = useListQuery({ sort: 'product_code', filters: ['low_stock'] })
const queryClient = useQueryClient()
const actionError = ref('')

const products = useQuery({
  queryKey: ['products', list.params],
  queryFn: () => productApi.list(list.params.value),
  placeholderData: keepPreviousData,
})

const remove = useMutation({
  mutationFn: (product: Product) => productApi.remove(product.id),
  onSuccess: () => {
    void queryClient.invalidateQueries({ queryKey: ['products'] })
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
  },
  onError: (e) => {
    actionError.value = e instanceof ApiError ? e.message : 'Gagal menghapus produk.'
  },
})

function confirmDelete(product: Product): void {
  actionError.value = ''
  if (window.confirm(`Hapus produk ${product.product_name}?`)) remove.mutate(product)
}
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-bold">Produk</h1>
      <RouterLink v-if="session.isAdmin.value" to="/products/new" class="btn-primary">+ Tambah Produk</RouterLink>
    </div>

    <div class="flex flex-wrap items-end gap-3">
      <div class="w-full sm:w-72">
        <label for="product-search" class="label">Cari kode / nama</label>
        <input id="product-search" v-model="list.searchInput.value" type="search" class="input" placeholder="mis. Hydrococo" />
      </div>
      <label class="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          :checked="list.filters.value.low_stock === '1'"
          @change="list.update({ low_stock: ($event.target as HTMLInputElement).checked ? '1' : null })"
        />
        Hanya stok menipis
      </label>
    </div>

    <p v-if="actionError" class="text-sm text-red-700" role="alert">{{ actionError }}</p>

    <StateBlock
      :loading="products.isPending.value"
      :error="products.error.value"
      :empty="products.data.value?.data.length === 0"
      empty-text="Tidak ada produk yang cocok."
      @retry="products.refetch()"
    >
      <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white" :aria-busy="products.isFetching.value">
        <table class="table">
          <thead>
            <tr>
              <SortableTh label="Kode" :direction="list.sortDirection('product_code')" @sort="list.toggleSort('product_code')" />
              <SortableTh label="Nama" :direction="list.sortDirection('product_name')" @sort="list.toggleSort('product_name')" />
              <SortableTh label="Harga" :direction="list.sortDirection('price')" @sort="list.toggleSort('price')" />
              <SortableTh label="Stok" :direction="list.sortDirection('stock')" @sort="list.toggleSort('stock')" />
              <th v-if="session.isAdmin.value" scope="col">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="product in products.data.value?.data" :key="product.id">
              <td class="font-mono">{{ product.product_code }}</td>
              <td>{{ product.product_name }}</td>
              <td>{{ formatRupiah(product.price) }}</td>
              <td>
                <span :class="product.is_low_stock ? 'font-semibold text-red-700' : ''">{{ product.stock }}</span>
                <span v-if="product.is_low_stock" class="ml-1 rounded bg-red-100 px-1.5 text-xs text-red-800">menipis</span>
              </td>
              <td v-if="session.isAdmin.value" class="whitespace-nowrap">
                <RouterLink :to="`/products/${product.id}/edit`" class="text-indigo-800 hover:underline">Ubah</RouterLink>
                ·
                <RouterLink :to="`/products/${product.id}/stock`" class="text-indigo-800 hover:underline">Stok</RouterLink>
                ·
                <button type="button" class="text-red-700 hover:underline" @click="confirmDelete(product)">Hapus</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar v-if="products.data.value" :meta="products.data.value.meta" @change="(p) => list.update({ page: p }, false)" />
    </StateBlock>
  </section>
</template>
