import { cp, rm } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const scriptDir = dirname(fileURLToPath(import.meta.url))
const docsRoot = resolve(scriptDir, '..')
const source = resolve(docsRoot, '.vitepress', 'dist')
const target = resolve(docsRoot, '..', 'server', 'public', 'docs')

await rm(target, { recursive: true, force: true })
await cp(source, target, { recursive: true })

console.log(`Copied VitePress dist to ${target}`)
