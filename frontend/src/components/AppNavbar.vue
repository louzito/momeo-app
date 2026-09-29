<script setup>
// MONO-CENTRE : plus de selecteur de centre — le site EST le centre.
import { ref } from 'vue'
import SitePublicMenu from '@/components/site/SitePublicMenu.vue'
import SitePublicLink from '@/components/site/SitePublicLink.vue'
import { RouterLink } from 'vue-router'
import { useTenantStore } from '@/stores/tenant'
import { useSessionStore } from '@/stores/session'

const tenantStore = useTenantStore()
const session = useSessionStore()
const mobileOpen = ref(false)
</script>

<template>
  <header
    class="sticky top-0 z-30 border-b border-white/10 backdrop-blur-xl"
    :style="{ backgroundColor: 'var(--sb-header-bg, #020617)', color: 'var(--sb-header-text, #ffffff)' }"
  >
    <div class="section flex min-h-16 items-center justify-between gap-4">
      <!-- Logo (image configuree dans l'espace centre, sinon embleme par defaut) -->
      <RouterLink :to="{ name: 'tenant-home' }" class="flex items-center gap-2.5">
        <img
          v-if="tenantStore.current?.logoUrl"
          :src="tenantStore.current.logoUrl"
          alt="Logo"
          class="h-9 w-9 rounded-xl object-cover"
        />
        <span v-else class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-400 to-accent-500 text-lg shadow-glow">✦</span>
        <span class="font-display text-lg font-extrabold tracking-tight">
          {{ tenantStore.current?.name || 'TodaTempo' }}
        </span>
      </RouterLink>

      <!-- Nav desktop -->
      <nav aria-label="Menu principal" class="hidden items-center gap-1 md:flex">
        <SitePublicMenu v-if="tenantStore.siteNavigation?.main" :items="tenantStore.siteNavigation.main" horizontal />
        <template v-else>
        <RouterLink :to="{ name: 'shop', query: { categorie: 'prestations' } }" class="nav-link">Prestations</RouterLink>
        <RouterLink :to="{ name: 'shop', query: { categorie: 'produits' } }" class="nav-link">Boutique</RouterLink>
        <RouterLink v-if="tenantStore.current?.giftVouchersEnabled !== false" :to="{ name: 'gift-card-purchase' }" class="nav-link">Carte cadeau</RouterLink>
        </template>
      </nav>

      <!-- Actions -->
      <div class="flex flex-wrap items-center gap-2">
        <RouterLink :to="{ name: 'cart' }" class="nav-link">Panier</RouterLink>
        <SitePublicLink v-if="tenantStore.siteNavigation?.primary" :link="tenantStore.siteNavigation.primary" class="rounded-xl bg-brand-600 px-3 py-2 text-sm font-semibold text-white">Prendre rendez-vous</SitePublicLink>
        <RouterLink
          v-if="session.isLoggedIn"
          :to="{ name: 'account-dashboard' }"
          class="hidden items-center gap-2 rounded-xl border border-white/15 bg-white/10 px-3 py-2 text-sm font-medium text-white hover:bg-white/20 sm:flex"
        >
          <span>👤</span><span class="max-w-[8rem] truncate">{{ session.customer.firstName }}</span>
        </RouterLink>
        <RouterLink
          v-else
          :to="{ name: 'account-login' }"
          class="hidden rounded-xl bg-gradient-to-r from-brand-500 to-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-glow transition hover:brightness-110 sm:inline-flex"
        >
          Mon compte
        </RouterLink>
        <button class="rounded-lg p-2 text-white/80 hover:bg-white/10 md:hidden" @click="mobileOpen = !mobileOpen" :aria-expanded="mobileOpen" aria-controls="mobile-navigation" aria-label="Menu">☰</button>
      </div>
    </div>

    <!-- Nav mobile -->
    <div v-if="mobileOpen" class="border-t border-white/10 md:hidden" :style="{ backgroundColor: 'var(--sb-header-bg, #020617)' }">
      <nav id="mobile-navigation" aria-label="Menu principal mobile" class="section flex flex-col py-2">
        <SitePublicMenu v-if="tenantStore.siteNavigation?.main" :items="tenantStore.siteNavigation.main" @navigate="mobileOpen = false" />
        <template v-else>
        <RouterLink :to="{ name: 'shop', query: { categorie: 'prestations' } }" class="nav-link justify-start" @click="mobileOpen = false">Prestations</RouterLink>
        <RouterLink :to="{ name: 'shop', query: { categorie: 'produits' } }" class="nav-link justify-start" @click="mobileOpen = false">Boutique</RouterLink>
        <RouterLink v-if="tenantStore.current?.giftVouchersEnabled !== false" :to="{ name: 'gift-card-purchase' }" class="nav-link justify-start" @click="mobileOpen = false">Carte cadeau</RouterLink>
        </template>
        <RouterLink :to="{ name: session.isLoggedIn ? 'account-dashboard' : 'account-login' }" class="nav-link justify-start" @click="mobileOpen = false">Mon compte</RouterLink>
      </nav>
    </div>
  </header>
</template>

<style scoped>
/* Couleur de texte du header pilotee par la config boutique (--sb-header-text). */
.nav-link {
  @apply inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium transition hover:bg-white/10;
  color: color-mix(in srgb, var(--sb-header-text, #ffffff) 72%, transparent);
}
.nav-link:hover,
.router-link-active.nav-link {
  color: var(--sb-header-text, #ffffff);
}
</style>
