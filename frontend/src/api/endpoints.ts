import { download, request, requestData, requestPage, type Query } from './client'
import type {
  CreateOrderPayload,
  Customer,
  DashboardSummary,
  LoginResult,
  LowStockAlert,
  Order,
  Product,
  SalesReport,
  StockMovement,
  TopProduct,
  User,
} from './types'

export const authApi = {
  login: (email: string, password: string) =>
    requestData<LoginResult>('/auth/login', { method: 'POST', body: { email, password, device_name: 'web' } }),
  logout: () => request<null>('/auth/logout', { method: 'POST' }),
  me: () => requestData<User & { token_expires_at: string | null }>('/auth/me'),
}

export const productApi = {
  list: (query: Query) => requestPage<Product>('/products', query),
  get: (id: number) => requestData<Product>(`/products/${id}`),
  create: (body: Partial<Product> & { stock?: number }) => requestData<Product>('/products', { method: 'POST', body }),
  update: (id: number, body: Partial<Product>) => requestData<Product>(`/products/${id}`, { method: 'PUT', body }),
  remove: (id: number) => request<null>(`/products/${id}`, { method: 'DELETE' }),
  adjustStock: (id: number, body: { quantity_change: number; note: string }) =>
    requestData<{ movement: StockMovement; product: Product }>(`/products/${id}/stock-adjustments`, { method: 'POST', body }),
  movements: (id: number, query: Query) => requestPage<StockMovement>(`/products/${id}/stock-movements`, query),
}

export const customerApi = {
  list: (query: Query) => requestPage<Customer>('/customers', query),
  get: (id: string) => requestData<Customer>(`/customers/${id}`),
  create: (body: Partial<Customer>) => requestData<Customer>('/customers', { method: 'POST', body }),
  update: (id: string, body: Partial<Customer>) => requestData<Customer>(`/customers/${id}`, { method: 'PUT', body }),
  remove: (id: string) => request<null>(`/customers/${id}`, { method: 'DELETE' }),
}

export const orderApi = {
  list: (query: Query) => requestPage<Order>('/orders', query),
  get: (id: number) => requestData<Order>(`/orders/${id}`),
  create: (body: CreateOrderPayload, idempotencyKey: string) =>
    requestData<Order>('/orders', { method: 'POST', body, headers: { 'Idempotency-Key': idempotencyKey } }),
  transition: (id: number, action: 'pay' | 'ship' | 'complete') => requestData<Order>(`/orders/${id}/${action}`, { method: 'POST' }),
  cancel: (id: number, cancelReason: string) =>
    requestData<Order>(`/orders/${id}/cancel`, { method: 'POST', body: { cancel_reason: cancelReason } }),
  downloadInvoice: (order: Pick<Order, 'id' | 'order_number'>) =>
    download(`/orders/${order.id}/invoice`, `invoice-${order.order_number}.html`),
}

export const dashboardApi = {
  summary: () => requestData<DashboardSummary>('/dashboard/summary'),
  lowStockAlerts: () => requestData<LowStockAlert[]>('/notifications/low-stock'),
}

export const reportApi = {
  sales: (query: { from: string; to: string; group_by: 'day' | 'month' }) => requestData<SalesReport>('/reports/sales', { query }),
  topProducts: (query: { from: string; to: string; limit: number; sort_by: 'quantity' | 'revenue' }) =>
    requestData<TopProduct[]>('/reports/top-products', { query }),
}
