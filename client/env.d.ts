/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_ENABLE_DEV_VISUAL_AUTH?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
