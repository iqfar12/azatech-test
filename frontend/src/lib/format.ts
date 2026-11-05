/** Money helpers working in integer cents — never floats for currency math. */
export function toCents(amount: string | number): number {
  const [whole = '0', fraction = ''] = String(amount).split('.')
  const sign = whole.startsWith('-') ? -1 : 1
  const cents = Math.abs(Number.parseInt(whole, 10) || 0) * 100 + Number.parseInt((fraction + '00').slice(0, 2), 10)
  return sign * cents
}

const rupiah = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 2 })

export function formatRupiah(amount: string | number): string {
  return rupiah.format(toCents(amount) / 100)
}

export function formatRupiahCents(cents: number): string {
  return rupiah.format(cents / 100)
}

const dateTime = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta' })
const dateOnly = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeZone: 'Asia/Jakarta' })

export function formatDateTime(iso: string | null | undefined): string {
  return iso ? dateTime.format(new Date(iso)) : '—'
}

export function formatDate(iso: string | null | undefined): string {
  return iso ? dateOnly.format(new Date(iso)) : '—'
}

/** YYYY-MM-DD in Asia/Jakarta (matches how the API interprets report dates). */
export function isoDateInJakarta(date: Date): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(date)
}
