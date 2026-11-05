import type { Envelope, Paginated } from './types'

const BASE_URL = (import.meta.env.VITE_API_BASE_URL ?? '').replace(/\/$/, '')

export type FieldErrors = Record<string, string[]>

/** Error raised for any non-2xx response, carrying the API's per-field errors. */
export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly errors: FieldErrors = {},
    public readonly requestId: string | null = null,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

/** Raised when the request never got an HTTP response (offline, DNS, timeout…). */
export class NetworkError extends Error {
  constructor(cause: unknown) {
    super('Tidak dapat terhubung ke server. Periksa koneksi Anda.')
    this.name = 'NetworkError'
    this.cause = cause
  }
}

type TokenProvider = () => string | null
type UnauthorizedHandler = () => void

let tokenProvider: TokenProvider = () => null
let onUnauthorized: UnauthorizedHandler = () => {}

/** Wired once by the session module (keeps this file free of app state). */
export function configureClient(options: { token: TokenProvider; onUnauthorized: UnauthorizedHandler }): void {
  tokenProvider = options.token
  onUnauthorized = options.onUnauthorized
}

export type Query = Record<string, string | number | boolean | null | undefined>

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE'
  body?: unknown
  query?: Query
  headers?: Record<string, string>
  signal?: AbortSignal
}

function buildUrl(path: string, query?: Query): string {
  const url = `${BASE_URL}/api${path}`
  if (!query) return url
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(query)) {
    if (value !== null && value !== undefined && value !== '') params.set(key, String(value))
  }
  const qs = params.toString()
  return qs ? `${url}?${qs}` : url
}

async function send(path: string, options: RequestOptions, accept: string): Promise<Response> {
  const headers: Record<string, string> = { Accept: accept, ...options.headers }
  const token = tokenProvider()
  if (token) headers.Authorization = `Bearer ${token}`
  if (options.body !== undefined) headers['Content-Type'] = 'application/json'

  let response: Response
  try {
    response = await fetch(buildUrl(path, options.query), {
      method: options.method ?? 'GET',
      headers,
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      signal: options.signal,
    })
  } catch (error) {
    throw new NetworkError(error)
  }

  if (!response.ok) {
    const payload = (await response.json().catch(() => ({}))) as { message?: string; errors?: FieldErrors }
    // Expired / revoked token → force logout (Track B has no refresh token).
    if (response.status === 401 && path !== '/auth/login') onUnauthorized()
    throw new ApiError(
      payload.message ?? `Request gagal (${response.status})`,
      response.status,
      payload.errors ?? {},
      response.headers.get('X-Request-Id'),
    )
  }

  return response
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<Envelope<T>> {
  const response = await send(path, options, 'application/json')
  if (response.status === 204) return { success: true, message: 'OK', data: undefined as T }
  return (await response.json()) as Envelope<T>
}

export async function requestData<T>(path: string, options: RequestOptions = {}): Promise<T> {
  return (await request<T>(path, options)).data
}

export async function requestPage<T>(path: string, query: Query): Promise<Paginated<T>> {
  const envelope = await request<T[]>(path, { query })
  return { data: envelope.data, meta: envelope.meta ?? { page: 1, per_page: envelope.data.length, total: envelope.data.length, total_pages: 1 } }
}

/** Downloads a binary/HTML file with the bearer token and saves it via a temporary link. */
export async function download(path: string, fallbackName: string): Promise<void> {
  const response = await send(path, {}, 'text/html, application/octet-stream')
  const disposition = response.headers.get('Content-Disposition') ?? ''
  const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? fallbackName
  const url = URL.createObjectURL(await response.blob())
  const link = document.createElement('a')
  link.href = url
  link.download = name
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
