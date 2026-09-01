<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useTenantContext } from '@/composables/useTenantContext'
import { orderJumpTypes } from '@/utils/catalog'
import JumpTypeCard from '@/components/JumpTypeCard.vue'
import Spinner from '@/components/ui/Spinner.vue'

const { tenant, jumpTypes, loading, slug } = useTenantContext()

// Points forts : configures dans l'espace centre, sinon defauts generiques.
const DEFAULT_HIGHLIGHTS = ['Réservation en ligne', 'Professionnels à votre écoute', 'Paiement sécurisé']
const highlights = computed(() => tenant.value?.highlights?.length ? tenant.value.highlights : DEFAULT_HIGHLIGHTS)

// Section catalogue : titre + phrase configurables.
const catalogTitle = computed(() => tenant.value?.home?.catalogTitle?.trim() || 'Nos prestations')
const catalogText = computed(() => tenant.value?.home?.catalogText?.trim() || 'Découvrez nos services et réservez votre rendez-vous en quelques clics.')

// Produits mis en avant (max 9, ordonnes) — sinon les 9 premiers de la boutique.
const featuredJumps = computed(() => {
  const all = jumpTypes.value || []
  const codes = tenant.value?.home?.featured || []
  const picked = codes.map((c) => all.find((j) => j.id === c)).filter(Boolean)
  const list = picked.length ? picked : orderJumpTypes(all, tenant.value?.shopOrder)
  return list.slice(0, 9)
})
const hasMore = computed(() => (jumpTypes.value || []).length > featuredJumps.value.length)

// Page d'accueil configurable (espace centre > Configuration boutique >
// Page d'accueil) : bannieres (PC + mobile), titre et texte du hero.
// Fallbacks : l'image de branding par defaut et les textes historiques.
const heroDesktop = computed(() => tenant.value?.bannerUrl || tenant.value?.branding?.heroImage || '')
const heroMobile = computed(() => tenant.value?.bannerMobileUrl || heroDesktop.value)
const heroTitle = computed(() => tenant.value?.home?.title?.trim() || tenant.value?.tagline || tenant.value?.name || '')
const heroText = computed(() => tenant.value?.home?.subtitle?.trim() || tenant.value?.about || '')
const giftEnabled = computed(() => tenant.value?.giftVouchersEnabled !== false)
</script>

<template>
  <Spinner v-if="loading || !tenant" label="Chargement du centre…" />

  <div v-else>
    <!-- Hero brande (banniere configurable, variante mobile optionnelle) -->
    <section class="relative overflow-hidden">
      <picture v-if="heroDesktop">
        <source v-if="heroMobile !== heroDesktop" media="(max-width: 640px)" :srcset="heroMobile" />
        <img :src="heroDesktop" :alt="tenant.name" class="absolute inset-0 h-full w-full object-cover" />
      </picture>
      <div class="absolute inset-0 bg-gradient-to-r from-slate-900/80 via-slate-900/50 to-transparent" />
      <div class="section relative py-24 sm:py-32">
        <div class="max-w-xl text-white animate-fade-up">
          <span v-if="tenant.city" class="chip bg-white/15 text-white backdrop-blur">{{ tenant.branding?.logoEmoji || '✦' }} {{ tenant.city }}</span>
          <h1 class="mt-4 font-display text-4xl font-extrabold leading-tight sm:text-5xl">{{ heroTitle }}</h1>
          <p v-if="heroText" class="mt-4 text-lg text-white/85">{{ heroText }}</p>
          <div class="mt-8 flex flex-wrap gap-3">
            <a href="#catalogue" class="btn-accent">Voir les prestations</a>
            <RouterLink :to="{ name: 'calendar', params: { slug } }" class="btn-outline border-white/40 bg-white/10 text-white hover:bg-white/20 hover:text-white">
              Voir le calendrier
            </RouterLink>
          </div>
        </div>
      </div>
    </section>

    <!-- Points forts -->
    <section v-if="highlights.length" class="section -mt-8 relative z-10">
      <div class="grid gap-4 rounded-2xl bg-white p-6 shadow-soft sm:grid-cols-3">
        <div v-for="(h, i) in highlights" :key="i" class="flex items-center gap-3">
          <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-50 text-brand-600">✓</span>
          <span class="text-sm font-medium text-slate-700">{{ h }}</span>
        </div>
      </div>
    </section>

    <!-- Catalogue -->
    <section id="catalogue" class="section py-16">
      <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 class="font-display text-3xl font-bold text-slate-900">{{ catalogTitle }}</h2>
          <p class="mt-2 text-slate-500">{{ catalogText }}</p>
        </div>
        <RouterLink v-if="hasMore" :to="{ name: 'shop' }" class="btn-outline px-5 py-2 text-sm">Voir toute la boutique →</RouterLink>
      </div>
      <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <JumpTypeCard
          v-for="jt in featuredJumps"
          :key="jt.id"
          :jump-type="jt"
          :currency="tenant.currency"
          :slug="slug"
        />
      </div>
    </section>

    <!-- Bandeau cadeau (masque si les cheques cadeaux sont desactives) -->
    <section v-if="giftEnabled" class="section pb-16">
      <div class="flex flex-col items-center justify-between gap-6 rounded-3xl bg-gradient-to-r from-brand-600 to-brand-500 p-8 text-white sm:flex-row sm:p-12">
        <div>
          <h3 class="font-display text-2xl font-bold">Offrez un moment rien qu’à soi 🎁</h3>
          <p class="mt-2 max-w-lg text-white/85">
            Achetez un cheque cadeau : le beneficiaire choisira lui-meme sa date, en toute liberte.
          </p>
        </div>
        <a href="#catalogue" class="btn bg-white text-brand-700 hover:bg-white/90">Choisir une prestation à offrir</a>
      </div>
    </section>
  </div>
</template>
