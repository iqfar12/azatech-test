import { computed, reactive, readonly } from 'vue'
import { configureClient } from '@/api/client'
import { authApi } from '@/api/endpoints'
import type { Role, User } from '@/api/types'

/**
 * Session state (Track B: Sanctum personal access token, no refresh token).
 *
 * - The token lives in memory and in sessionStorage (survives a reload, dies with the tab);
 *   it is never put in a cookie readable by other sites nor in localStorage.
 * - When the token expires (timer on expires_at) or the API answers 401,
 *   the user is logged out and sent to the login page ("forced logout").
 */
const STORAGE_KEY = 'azatech.session'

interface StoredSession {
  token: string
  expiresAt: string
  user: User
}

interface SessionState {
  token: string | null
  expiresAt: string | null
  user: User | null
  expired: boolean
}

const state = reactive<SessionState>({ token: null, expiresAt: null, user: null, expired: false })
let expiryTimer: ReturnType<typeof setTimeout> | undefined
const listeners = new Set<() => void>()

function persist(): void {
  try {
    if (state.token && state.user && state.expiresAt) {
      const value: StoredSession = { token: state.token, expiresAt: state.expiresAt, user: state.user }
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(value))
    } else {
      sessionStorage.removeItem(STORAGE_KEY)
    }
  } catch {
    /* storage unavailable (private mode) – keep the in-memory session only */
  }
}

function scheduleExpiry(): void {
  clearTimeout(expiryTimer)
  if (!state.expiresAt) return
  const ms = new Date(state.expiresAt).getTime() - Date.now()
  if (ms <= 0) {
    expire()
    return
  }
  // setTimeout max delay is ~24.8 days; tokens live hours, so this is safe.
  expiryTimer = setTimeout(expire, ms)
}

function clear(): void {
  clearTimeout(expiryTimer)
  state.token = null
  state.expiresAt = null
  state.user = null
  persist()
}

/** Forced logout (token expired or rejected). */
function expire(): void {
  if (!state.token) return
  clear()
  state.expired = true
  listeners.forEach((listener) => listener())
}

function restore(): void {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY)
    if (!raw) return
    const stored = JSON.parse(raw) as StoredSession
    if (new Date(stored.expiresAt).getTime() <= Date.now()) {
      sessionStorage.removeItem(STORAGE_KEY)
      return
    }
    state.token = stored.token
    state.expiresAt = stored.expiresAt
    state.user = stored.user
    scheduleExpiry()
  } catch {
    sessionStorage.removeItem(STORAGE_KEY)
  }
}

configureClient({ token: () => state.token, onUnauthorized: expire })
restore()

export const session = {
  state: readonly(state),
  isAuthenticated: computed(() => state.token !== null),
  role: computed<Role | null>(() => state.user?.role ?? null),
  isAdmin: computed(() => state.user?.role === 'admin'),

  async login(email: string, password: string): Promise<void> {
    const result = await authApi.login(email, password)
    state.token = result.access_token
    state.expiresAt = result.expires_at
    state.user = result.user
    state.expired = false
    persist()
    scheduleExpiry()
  },

  async logout(): Promise<void> {
    try {
      if (state.token) await authApi.logout()
    } finally {
      clear()
    }
  },

  hasRole(...roles: Role[]): boolean {
    return state.user !== null && roles.includes(state.user.role)
  },

  /** Subscribe to forced logouts (used by the router to redirect to /login). */
  onExpired(listener: () => void): () => void {
    listeners.add(listener)
    return () => listeners.delete(listener)
  },

  acknowledgeExpired(): void {
    state.expired = false
  },
}
