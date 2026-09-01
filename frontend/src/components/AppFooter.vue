<script setup>
// Footer vitrine — alimente par la Configuration boutique (espace centre) :
// couleurs (--sb-footer-bg / --sb-footer-text), logo, reseaux sociaux, coordonnees.
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useTenantStore } from '@/stores/tenant'
import { SOCIAL_NETWORKS } from '@/utils/socialIcons'

const tenantStore = useTenantStore()
const tenant = computed(() => tenantStore.current)

// Reseaux sociaux configures (on n'affiche que ceux renseignes),
// avec les VRAIS logos de marque (SVG Simple Icons, fill=currentColor).
const socialLinks = computed(() =>
  SOCIAL_NETWORKS.filter((s) => tenant.value?.socials?.[s.key]).map((s) => ({
    ...s,
    url: tenant.value.socials[s.key],
  })),
)

const address = computed(() => tenant.value?.address || null)

// Pages legales activees (Configuration boutique) -> liens automatiques.
const legalLinks = computed(() => {
  const l = tenant.value?.legal || {}
  return [
    l.terms?.enabled ? { key: 'terms', label: 'Conditions générales' } : null,
    l.mentions?.enabled ? { key: 'mentions', label: 'Mentions légales' } : null,
  ].filter(Boolean)
})
const giftEnabled = computed(() => tenant.value?.giftVouchersEnabled !== false)
</script>

<template>
  <footer
    class="mt-20"
    :style="{ backgroundColor: 'var(--sb-footer-bg, #020617)', color: 'var(--sb-footer-text, #ffffff)' }"
  >
    <!-- Bande immersive -->
    <div class="relative overflow-hidden">
      <div class="section relative grid gap-8 py-14 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <div class="flex items-center gap-2.5">
            <img v-if="tenant?.logoUrl" :src="tenant.logoUrl" alt="Logo" class="h-9 w-9 rounded-xl object-cover" />
            <span v-else class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-400 to-accent-500 text-lg">✦</span>
            <span class="font-display text-lg font-extrabold">{{ tenant?.name || 'Momeo' }}</span>
          </div>
          <p class="mt-4 text-sm">{{ tenant?.tagline || 'Réservez vos prestations en ligne, simplement.' }}</p>
          <div v-if="socialLinks.length" class="mt-5 flex gap-2">
            <a
              v-for="s in socialLinks"
              :key="s.key"
              :href="s.url"
              target="_blank"
              rel="noopener"
              :title="s.label"
              :aria-label="s.label"
              class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 transition hover:bg-white/20"
            >
              <svg viewBox="0 0 24 24" class="h-4.5 w-4.5" width="18" height="18" fill="currentColor" aria-hidden="true">
                <path :d="s.path" />
              </svg>
            </a>
          </div>
        </div>

        <div>
          <p class="mb-3 text-sm font-semibold uppercase tracking-wide">L'etablissement</p>
          <ul class="space-y-2 text-sm">
            <li v-if="address?.street">📍 {{ address.street }}, {{ address.postcode }} {{ address.city }}</li>
            <li v-else-if="tenant?.city">📍 {{ tenant.city }}</li>
            <li v-if="tenant?.phone">📞 {{ tenant.phone }}</li>
            <li v-if="tenant?.email">✉️ {{ tenant.email }}</li>
          </ul>
        </div>

        <div>
          <p class="mb-3 text-sm font-semibold uppercase tracking-wide">Réserver</p>
          <ul class="space-y-2 text-sm">
            <li><RouterLink :to="{ name: 'shop' }" class="hover:underline">Boutique</RouterLink></li>
            <li><RouterLink :to="{ name: 'calendar' }" class="hover:underline">Calendrier</RouterLink></li>
            <li v-if="giftEnabled"><RouterLink :to="{ name: 'beneficiary-login' }" class="hover:underline">Activer un chèque cadeau</RouterLink></li>
          </ul>
        </div>

        <div>
          <p class="mb-3 text-sm font-semibold uppercase tracking-wide">Espaces</p>
          <ul class="space-y-2 text-sm">
            <li><RouterLink :to="{ name: 'account-login' }" class="hover:underline">Mon compte client</RouterLink></li>
            <li><RouterLink :to="{ name: 'admin-login' }" class="hover:underline">Espace professionnel</RouterLink></li>
          </ul>
        </div>
      </div>
    </div>

    <div class="border-t border-white/10 py-5 text-center text-xs">
      <p>© 2026 {{ tenant?.name || 'Momeo' }} — réservation de prestations en ligne.</p>
      <p v-if="legalLinks.length" class="mt-2 flex items-center justify-center gap-3">
        <RouterLink
          v-for="l in legalLinks"
          :key="l.key"
          :to="{ name: 'legal-page', params: { page: l.key } }"
          class="opacity-75 hover:underline hover:opacity-100"
        >{{ l.label }}</RouterLink>
      </p>
    </div>
  </footer>
</template>
