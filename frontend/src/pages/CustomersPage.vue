<script setup lang="ts">
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { ref } from 'vue'
import { ApiError } from '@/api/client'
import { customerApi } from '@/api/endpoints'
import type { Customer } from '@/api/types'
import { session } from '@/auth/session'
import PaginationBar from '@/components/PaginationBar.vue'
import SortableTh from '@/components/SortableTh.vue'
import StateBlock from '@/components/StateBlock.vue'
import { useListQuery } from '@/composables/useListQuery'
import { formatDate } from '@/lib/format'

const list = useListQuery({ sort: 'customer_name' })
const queryClient = useQueryClient()
const actionError = ref('')

const customers = useQuery({
  queryKey: ['customers', list.params],
  queryFn: () => customerApi.list(list.params.value),
  placeholderData: keepPreviousData,
})

const remove = useMutation({
  mutationFn: (customer: Customer) => customerApi.remove(customer.id),
  onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['customers'] }),
  onError: (e) => {
    actionError.value = e instanceof ApiError ? e.message : 'Gagal menghapus pelanggan.'
  },
})

function confirmDelete(customer: Customer): void {
  actionError.value = ''
  if (window.confirm(`Hapus pelanggan ${customer.customer_name}?`)) remove.mutate(customer)
}
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-bold">Pelanggan</h1>
      <RouterLink to="/customers/new" class="btn-primary">+ Tambah Pelanggan</RouterLink>
    </div>

    <div class="w-full sm:w-72">
      <label for="customer-search" class="label">Cari nama / telepon / email</label>
      <input id="customer-search" v-model="list.searchInput.value" type="search" class="input" />
    </div>

    <p v-if="actionError" class="text-sm text-red-700" role="alert">{{ actionError }}</p>

    <StateBlock
      :loading="customers.isPending.value"
      :error="customers.error.value"
      :empty="customers.data.value?.data.length === 0"
      empty-text="Tidak ada pelanggan yang cocok."
      @retry="customers.refetch()"
    >
      <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white" :aria-busy="customers.isFetching.value">
        <table class="table">
          <thead>
            <tr>
              <SortableTh label="Nama" :direction="list.sortDirection('customer_name')" @sort="list.toggleSort('customer_name')" />
              <th scope="col">Alamat</th>
              <th scope="col">Gender</th>
              <SortableTh label="Tgl lahir" :direction="list.sortDirection('birth_date')" @sort="list.toggleSort('birth_date')" />
              <th scope="col">Kontak</th>
              <th scope="col">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="customer in customers.data.value?.data" :key="customer.id">
              <td class="font-medium">{{ customer.customer_name }}</td>
              <td>{{ customer.customer_address }}</td>
              <td>{{ customer.gender }}</td>
              <td>{{ formatDate(customer.birth_date) }}</td>
              <td>
                <div>{{ customer.phone ?? '—' }}</div>
                <div class="text-slate-500">{{ customer.email ?? '' }}</div>
              </td>
              <td class="whitespace-nowrap">
                <RouterLink :to="`/customers/${customer.id}/edit`" class="text-indigo-800 hover:underline">Ubah</RouterLink>
                <!-- Cashiers may manage customers but not delete them (API enforces this too). -->
                <template v-if="session.isAdmin.value">
                  ·
                  <button type="button" class="text-red-700 hover:underline" @click="confirmDelete(customer)">Hapus</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar v-if="customers.data.value" :meta="customers.data.value.meta" @change="(p) => list.update({ page: p }, false)" />
    </StateBlock>
  </section>
</template>
