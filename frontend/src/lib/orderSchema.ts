import { z } from 'zod'

/**
 * Client-side schema for the order form. The server re-validates everything
 * (and is the only authority on stock and prices); this is for fast feedback.
 * Issue paths match the API error keys ("items.1.quantity").
 */
export const orderItemSchema = z.object({
  product_id: z.number({ invalid_type_error: 'Pilih produk' }).int().positive('Pilih produk'),
  quantity: z
    .number({ invalid_type_error: 'Jumlah wajib diisi' })
    .int('Jumlah harus bilangan bulat')
    .min(1, 'Jumlah minimal 1')
    .max(1000, 'Jumlah maksimal 1000'),
})

export const orderSchema = z
  .object({
    customer_id: z.string({ required_error: 'Pilih pelanggan' }).uuid('Pilih pelanggan'),
    items: z.array(orderItemSchema).min(1, 'Order minimal berisi 1 produk').max(50),
  })
  .superRefine((value, ctx) => {
    const seen = new Map<number, number>()
    value.items.forEach((item, index) => {
      if (item.product_id > 0 && seen.has(item.product_id)) {
        ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['items', index, 'product_id'], message: 'Produk sudah ada di baris lain' })
      }
      seen.set(item.product_id, index)
    })
  })

export type OrderFormValues = z.infer<typeof orderSchema>

/** Flattens zod issues to { "items.0.quantity": "message" } (first message per field). */
export function zodErrors(error: z.ZodError): Record<string, string> {
  const result: Record<string, string> = {}
  for (const issue of error.issues) {
    const key = issue.path.join('.')
    if (!(key in result)) result[key] = issue.message
  }
  return result
}
