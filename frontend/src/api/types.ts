/**
 * API contract types (mirrors the Laravel resources / OpenAPI document at /docs/api.json).
 * Decimal amounts are strings ("12000.00") end-to-end to avoid float rounding.
 */

export type Role = 'admin' | 'cashier'

export type OrderStatus = 'PENDING' | 'PAID' | 'SHIPPED' | 'COMPLETED' | 'CANCELLED'

export type OrderAction = 'pay' | 'ship' | 'complete' | 'cancel' | 'download_invoice'

export interface PaginationMeta {
  page: number
  per_page: number
  total: number
  total_pages: number
}

export interface Envelope<T> {
  success: boolean
  message: string
  data: T
  meta?: PaginationMeta
}

export interface Paginated<T> {
  data: T[]
  meta: PaginationMeta
}

export interface User {
  id: number
  name: string
  email: string
  role: Role
}

export interface LoginResult {
  token_type: 'Bearer'
  access_token: string
  expires_at: string
  user: User
}

export interface Product {
  id: number
  product_code: string
  product_name: string
  stock: number
  price: string
  low_stock_threshold: number
  is_low_stock: boolean
  created_at: string | null
  updated_at: string | null
}

export interface Customer {
  id: string
  customer_name: string
  customer_address: string
  gender: 'Pria' | 'Wanita'
  birth_date: string | null
  phone: string | null
  email: string | null
}

export interface OrderItem {
  id: number
  product_id: number
  product_code: string
  product_name: string
  quantity: number
  unit_price: string
  subtotal: string
}

export interface StatusHistory {
  from_status: OrderStatus | null
  to_status: OrderStatus
  note: string | null
  changed_by: { id: number; name: string } | null
  created_at: string
}

export interface Order {
  id: number
  order_number: string
  status: OrderStatus
  total_amount: string
  order_date: string
  paid_at: string | null
  shipped_at: string | null
  completed_at: string | null
  cancelled_at: string | null
  cancel_reason: string | null
  customer?: { id: string; customer_name: string; phone: string | null; email: string | null }
  created_by?: { id: number; name: string }
  items_count?: number
  items?: OrderItem[]
  status_history?: StatusHistory[]
  allowed_actions: OrderAction[]
}

export interface StockMovement {
  id: number
  product_id: number
  type: 'INITIAL' | 'ORDER_OUT' | 'CANCEL_RESTORE' | 'ADJUSTMENT'
  quantity_change: number
  stock_after: number
  reference_type: string | null
  reference_id: string | null
  note: string | null
  created_by?: { id: number; name: string } | null
  created_at: string
}

export interface DashboardSummary {
  orders_today: number
  revenue_today: string
  orders_this_month: number
  revenue_this_month: string
  pending_orders: number
  awaiting_shipment: number
  total_products: number
  low_stock_products: number
  total_customers: number
  generated_at: string
}

export interface LowStockAlert {
  id: number
  product_id: number
  product_code: string
  product_name: string
  current_stock: number
  stock_at_alert: number
  threshold: number
  created_at: string
}

export interface SalesReport {
  group_by: 'day' | 'month'
  from: string
  to: string
  summary: { total_orders: number; revenue: string }
  rows: { period: string; total_orders: number; revenue: string }[]
}

export interface TopProduct {
  product_id: number
  product_code: string
  product_name: string
  quantity_sold: number
  revenue: string
  order_count: number
}

export interface CreateOrderPayload {
  customer_id: string
  items: { product_id: number; quantity: number }[]
}
