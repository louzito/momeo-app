import { fileURLToPath, URL } from 'node:url'
import fs from 'node:fs'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// =============================================================================
// Multi-centres par slug : /{slug}/api/* est reecrit vers /api/* avec
// l'en-tete X-Skybook-Tenant: {slug}, PUIS le proxy /api ci-dessous forwarde
// vers Sylius. Ainsi Sylius/API Platform ne voient JAMAIS le prefixe (en prod,
// un reverse proxy — caddy/nginx — fera exactement la meme reecriture).
// SEULS les slugs presents dans le registre back/config/tenants.json sont
// reecrits — sinon /src/api/*.js (les fichiers sources servis par Vite !)
// serait avale par la reecriture. Registre relu a chaque changement (mtime).
// =============================================================================
const registryFile = fileURLToPath(new URL('../back/config/tenants.json', import.meta.url))
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

const skybookTenantRewrite = () => ({
  name: 'skybook-tenant-rewrite',
  configureServer(server) {
    server.middlewares.use((req, res, next) => {
      const m = (req.url || '').match(/^\/([a-z0-9][a-z0-9-]{0,62})\/api\/(.*)$/)
      if (m && knownSlugs().has(m[1])) {
        req.headers['x-skybook-tenant'] = m[1]
        req.url = '/api/' + m[2]
      }
      next()
    })
  },
})

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [vue(), skybookTenantRewrite()],
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
      // Le front appelle /{slug}/api/... -> reecrit en /api/... (middleware
      // ci-dessus) -> Vite le renvoie vers Sylius (Docker sur :8080).
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      },
    },
  },
})
