import { render, screen, waitFor } from '@testing-library/vue'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { ApiError, NetworkError } from '@/api/client'
import type { CreateOrderPayload, Customer, Product } from '@/api/types'
import OrderForm from '@/components/OrderForm.vue'

const products: Product[] = [
  { id: 1, product_code: 'P0001', product_name: 'Hydrococo 150ml', stock: 10, price: '12000.00', low_stock_threshold: 5, is_low_stock: false, created_at: null, updated_at: null },
  { id: 3, product_code: 'P0003', product_name: 'Aqua 600ml', stock: 3, price: '4000.00', low_stock_threshold: 5, is_low_stock: true, created_at: null, updated_at: null },
]
const customers: Customer[] = [
  { id: '9d2f6c1e-1111-4222-8333-444455556666', customer_name: 'Budi Santoso', customer_address: 'Jakarta', gender: 'Pria', birth_date: null, phone: '081234567890', email: null },
]

type SubmitFn = (payload: CreateOrderPayload, key: string) => Promise<void>

function setup(submit: SubmitFn = vi.fn<SubmitFn>().mockResolvedValue(undefined)) {
  const user = userEvent.setup()
  render(OrderForm, { props: { products, customers, submit } })
  return { user, submit }
}

async function fillValidOrder(user: ReturnType<typeof userEvent.setup>) {
  await user.selectOptions(screen.getByLabelText('Pelanggan'), customers[0]!.id)
  await user.selectOptions(screen.getByLabelText('Produk baris 1'), '1')
  const qty = screen.getAllByLabelText('Jumlah')[0]!
  await user.clear(qty)
  await user.type(qty, '2')
  await user.click(screen.getByRole('button', { name: '+ Tambah produk' }))
  await user.selectOptions(screen.getByLabelText('Produk baris 2'), '3')
}

describe('OrderForm', () => {
  it('updates the total in real time as rows change', async () => {
    const { user } = setup()
    await fillValidOrder(user)

    // 2 × 12.000 + 1 × 4.000 = 28.000
    expect(screen.getByTestId('order-total').textContent).toMatch(/28\.000/)

    await user.click(screen.getByRole('button', { name: 'Hapus baris 2' }))
    expect(screen.getByTestId('order-total').textContent).toMatch(/24\.000/)
  })

  it('validates with the schema before calling the API', async () => {
    const { user, submit } = setup()

    await user.click(screen.getByRole('button', { name: 'Buat Order' }))

    expect(await screen.findByText('Pilih pelanggan')).toBeInTheDocument()
    expect(screen.getByText('Pilih produk')).toBeInTheDocument()
    expect(submit).not.toHaveBeenCalled()
  })

  it('warns when the quantity exceeds the known stock', async () => {
    const { user, submit } = setup()
    await user.selectOptions(screen.getByLabelText('Pelanggan'), customers[0]!.id)
    await user.selectOptions(screen.getByLabelText('Produk baris 1'), '3')
    const qty = screen.getByLabelText('Jumlah')
    await user.clear(qty)
    await user.type(qty, '5')

    await user.click(screen.getByRole('button', { name: 'Buat Order' }))

    expect(await screen.findByText('Stok Aqua 600ml tersisa 3')).toBeInTheDocument()
    expect(submit).not.toHaveBeenCalled()
  })

  it('submits only product ids and quantities, with a UUID Idempotency-Key', async () => {
    const { user, submit } = setup()
    await fillValidOrder(user)

    await user.click(screen.getByRole('button', { name: 'Buat Order' }))

    await waitFor(() => expect(submit).toHaveBeenCalledTimes(1))
    const [payload, key] = vi.mocked(submit).mock.calls[0]!
    expect(payload).toEqual({
      customer_id: customers[0]!.id,
      items: [
        { product_id: 1, quantity: 2 },
        { product_id: 3, quantity: 1 },
      ],
    })
    expect(key).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
  })

  it('prevents double submit while the request is in flight', async () => {
    let resolve!: () => void
    const submit = vi.fn<SubmitFn>().mockImplementation(() => new Promise<void>((r) => (resolve = r)))
    const { user } = setup(submit)
    await fillValidOrder(user)

    const button = screen.getByRole('button', { name: 'Buat Order' })
    await user.click(button)
    await user.click(screen.getByRole('button', { name: 'Menyimpan…' }))
    await user.dblClick(screen.getByRole('button', { name: 'Menyimpan…' }))

    expect(submit).toHaveBeenCalledTimes(1)
    expect(screen.getByRole('button', { name: 'Menyimpan…' })).toBeDisabled()
    resolve()
    await waitFor(() => expect(screen.getByRole('button', { name: 'Buat Order' })).toBeEnabled())
  })

  it('shows server errors next to the offending item', async () => {
    const submit = vi.fn<SubmitFn>().mockRejectedValue(
      new ApiError('Stok tidak mencukupi', 422, { 'items.1.quantity': ['Stok Aqua 600ml tersisa 0, diminta 1'] }),
    )
    const { user } = setup(submit)
    await fillValidOrder(user)

    await user.click(screen.getByRole('button', { name: 'Buat Order' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Stok tidak mencukupi')
    const qtyInputs = screen.getAllByLabelText('Jumlah')
    expect(qtyInputs[1]).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByText('Stok Aqua 600ml tersisa 0, diminta 1')).toBeInTheDocument()
  })

  it('uses a new key per submit attempt but reuses it for automatic network retries', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const submit = vi
      .fn<SubmitFn>()
      .mockRejectedValueOnce(new NetworkError(new TypeError('Failed to fetch')))
      .mockResolvedValueOnce(undefined)
      .mockRejectedValueOnce(new ApiError('Stok tidak mencukupi', 422, {}))
    const { user } = setup(submit)
    await fillValidOrder(user)

    // Attempt 1: network failure → retried automatically with the SAME key.
    await user.click(screen.getByRole('button', { name: 'Buat Order' }))
    await vi.advanceTimersByTimeAsync(1000)
    await waitFor(() => expect(submit).toHaveBeenCalledTimes(2))
    const calls = vi.mocked(submit).mock.calls
    expect(calls[1]![1]).toBe(calls[0]![1])

    // Attempt 2 (user clicks again): a NEW key.
    await waitFor(() => expect(screen.getByRole('button', { name: 'Buat Order' })).toBeEnabled())
    await user.click(screen.getByRole('button', { name: 'Buat Order' }))
    await waitFor(() => expect(submit).toHaveBeenCalledTimes(3))
    expect(vi.mocked(submit).mock.calls[2]![1]).not.toBe(calls[0]![1])

    vi.useRealTimers()
  })
})
