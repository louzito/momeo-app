import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

// Bundle Vue's matching server renderer (already locked with Vue). No Node service.
export default defineConfig({
  plugins: [vue()],
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  ssr: { noExternal: true },
  build: { target: 'node20', ssr: 'src/site-server.js', outDir: 'dist-ssr', rollupOptions: { output: { entryFileNames: 'site-server.mjs' } } },
})
