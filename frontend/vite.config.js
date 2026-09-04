import { fileURLToPath, URL } from 'node:url'
import fs from 'node:fs'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

const registryFile = fileURLToPath(new URL('../backend/config/tenants.json', import.meta.url))
let tenantCache = { mtime: 0, slugs: new Set() }
const knownSlugs = () => {
  try {
    const mtime = fs.statSync(registryFile).mtimeMs
    if (mtime !== tenantCache.mtime) {
      const data = JSON.parse(fs.readFileSync(registryFile, 'utf8'))
      tenantCache = { mtime, slugs: new Set(Object.keys(data)) }
    }
  } catch { /* registre absent : aucun slug */ }
  return tenantCache.slugs
}

const tenantRewrite = () => ({
  name: 'skybook-tenant-rewrite',
  configureServer(server) {
    server.middlewares.use((req, res, next) => {
      const match = (req.url || '').match(/^\/([a-z0-9][a-z0-9-]{0,62})\/api\/(.*)$/)
      if (match && knownSlugs().has(match[1])) {
        req.headers['x-todatempo-tenant'] = match[1]
        req.url = `/api/${match[2]}`
      }
      next()
    })
  },
})

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [vue(), tenantRewrite()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    // host:true = ecoute aussi sur les interfaces reseau -> permet au conteneur
    // caddy (reverse proxy multi-centres, port 80) d'atteindre Vite via
    // host.docker.internal. Necessaire pour localhost/{slug}/ en dev.
    host: true,    port: 5173,
    open: false,
    proxy: {
      // Le client appelle /api/... avec X-Skybook-Tenant. Vite ne fait ici
      // qu'acheminer vers Sylius, exactement comme le proxy de production.
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      },
    },
  },
})
