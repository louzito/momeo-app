import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: 'http://127.0.0.1:4173',
    trace: 'on-first-retry',
    timezoneId: 'UTC',
    locale: 'fr-FR',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'npm run dev -- --host 127.0.0.1 --port 4173',
    // Vite donne la priorite aux variables VITE_* du processus sur les fichiers
    // .env : les scenarios tournent donc a la racine et sur /api/v2 meme si la
    // machine possede un .env.local d'hebergement prefixe.
    env: {
      VITE_APP_BASE: '/',
      VITE_API_BASE: '/api/v2',
      VITE_MEDIA_BASE: '',
      VITE_WEBSITE_LOGIN_URL: '/todatempo/fr/connexion',
    },
    url: 'http://127.0.0.1:4173',
    reuseExistingServer: !process.env.CI,
  },
})
