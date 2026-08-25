import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'

const entries = {
  'public-price-indices-calculator': {
    source: './src/public-price-indices/calculator.ts',
    output: 'price-indices-public-calculator.js',
    name: 'PriceIndicesPublicCalculator',
  },
  'public-price-indices-chart': {
    source: './src/public-price-indices/chart.ts',
    output: 'price-indices-public-chart.js',
    name: 'PriceIndicesPublicChart',
  },
} as const

export default defineConfig(({ mode }) => {
  const entry = entries[mode as keyof typeof entries]
  if (!entry) throw new Error(`Unsupported public PriceIndices build mode: ${mode}`)

  return {
    publicDir: false,
    plugins: [
      {
        name: 'public-price-indices-trailing-whitespace',
        generateBundle(_options, bundle) {
          Object.values(bundle).forEach((output) => {
            if (output.type === 'chunk') output.code = output.code.replace(/[\t ]+$/gm, '')
          })
        },
      },
    ],
    build: {
      target: 'es2018',
      outDir: fileURLToPath(new URL('../server/public', import.meta.url)),
      emptyOutDir: false,
      sourcemap: false,
      minify: 'esbuild',
      lib: {
        entry: fileURLToPath(new URL(entry.source, import.meta.url)),
        name: entry.name,
        formats: ['iife'],
        fileName: () => entry.output,
      },
      rollupOptions: { output: { inlineDynamicImports: true } },
    },
  }
})
