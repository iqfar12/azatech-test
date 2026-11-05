<script setup lang="ts">
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { z } from 'zod'
import { ApiError } from '@/api/client'
import { customerApi } from '@/api/endpoints'
import StateBlock from '@/components/StateBlock.vue'
import { zodErrors } from '@/lib/orderSchema'

const props = defineProps<{ id?: string }>()
const router = useRouter()
const queryClient = useQueryClient()
const isEdit = computed(() => !!props.id)

const form = reactive({ customer_name: '', customer_address: '', gender: 'Pria' as 'Pria' | 'Wanita', birth_date: '', phone: '', email: '' })
const errors = ref<Record<string, string>>({})
const formError = ref('')

const schema = z.object({
  customer_name: z.string().trim().min(2, 'Nama minimal 2 karakter').max(150),
  customer_address: z.string().trim().min(2, 'Alamat wajib diisi').max(255),
  gender: z.enum(['Pria', 'Wanita']),
  birth_date: z.string().regex(/^(\d{4}-\d{2}-\d{2})?$/, 'Format tanggal YYYY-MM-DD'),
  phone: z.string().regex(/^(\+?[0-9\s-]{8,20})?$/, 'Nomor telepon tidak valid'),
  email: z.union([z.literal(''), z.string().email('Email tidak valid')]),
})

const existing = useQuery({
  queryKey: computed(() => ['customer', props.id]),
  queryFn: () => customerApi.get(props.id as string),
  enabled: isEdit,
})

watch(
  () => existing.data.value,
  (c) => {
    if (!c) return
    Object.assign(form, {
      customer_name: c.customer_name,
      customer_address: c.customer_address,
      gender: c.gender,
      birth_date: c.birth_date ?? '',
      phone: c.phone ?? '',
      email: c.email ?? '',
    })
  },
  { immediate: true },
)

const save = useMutation({
  mutationFn: () => {
    const body = { ...form, birth_date: form.birth_date || null, phone: form.phone || null, email: form.email || null }
    return isEdit.value ? customerApi.update(props.id as string, body) : customerApi.create(body)
  },
  onSuccess: (customer) => {
    void queryClient.invalidateQueries({ queryKey: ['customers'] })
    queryClient.setQueryData(['customer', customer.id], customer)
    void router.push('/customers')
  },
  onError: (e) => {
    formError.value = e instanceof ApiError ? e.message : 'Gagal menyimpan.'
    if (e instanceof ApiError) errors.value = Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0] ?? '']))
  },
})

function onSubmit(): void {
  formError.value = ''
  const parsed = schema.safeParse(form)
  errors.value = parsed.success ? {} : zodErrors(parsed.error)
  if (parsed.success && !save.isPending.value) save.mutate()
}
</script>

<template>
  <section class="max-w-xl space-y-4">
    <h1 class="text-2xl font-bold">{{ isEdit ? 'Ubah Pelanggan' : 'Tambah Pelanggan' }}</h1>
    <StateBlock :loading="isEdit && existing.isPending.value" :error="existing.error.value" @retry="existing.refetch()">
      <form class="card space-y-4" novalidate @submit.prevent="onSubmit">
        <p v-if="formError" class="rounded-md bg-red-50 p-3 text-sm text-red-800" role="alert">{{ formError }}</p>
        <div>
          <label for="c-name" class="label">Nama</label>
          <input id="c-name" v-model="form.customer_name" class="input" :aria-invalid="!!errors.customer_name" />
          <p v-if="errors.customer_name" class="field-error">{{ errors.customer_name }}</p>
        </div>
        <div>
          <label for="c-address" class="label">Alamat</label>
          <input id="c-address" v-model="form.customer_address" class="input" :aria-invalid="!!errors.customer_address" />
          <p v-if="errors.customer_address" class="field-error">{{ errors.customer_address }}</p>
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label for="c-gender" class="label">Gender</label>
            <select id="c-gender" v-model="form.gender" class="input">
              <option value="Pria">Pria</option>
              <option value="Wanita">Wanita</option>
            </select>
          </div>
          <div>
            <label for="c-birth" class="label">Tanggal lahir</label>
            <input id="c-birth" v-model="form.birth_date" type="date" class="input" :aria-invalid="!!errors.birth_date" />
            <p v-if="errors.birth_date" class="field-error">{{ errors.birth_date }}</p>
          </div>
          <div>
            <label for="c-phone" class="label">Telepon</label>
            <input id="c-phone" v-model="form.phone" type="tel" class="input" :aria-invalid="!!errors.phone" />
            <p v-if="errors.phone" class="field-error">{{ errors.phone }}</p>
          </div>
          <div>
            <label for="c-email" class="label">Email</label>
            <input id="c-email" v-model="form.email" type="email" class="input" :aria-invalid="!!errors.email" />
            <p v-if="errors.email" class="field-error">{{ errors.email }}</p>
          </div>
        </div>
        <div class="flex gap-2">
          <button type="submit" class="btn-primary" :disabled="save.isPending.value">Simpan</button>
          <RouterLink to="/customers" class="btn-secondary">Batal</RouterLink>
        </div>
      </form>
    </StateBlock>
  </section>
</template>
