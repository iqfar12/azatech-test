/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Base URL of the API. Empty = same origin (nginx / Vite proxy forwards /api). */
  readonly VITE_API_BASE_URL?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
