import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [vue()],
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
