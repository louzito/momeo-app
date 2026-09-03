<script setup>
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useTenantStore } from '@/stores/tenant'
import Spinner from '@/components/ui/Spinner.vue'

const tenantStore = useTenantStore()
const { tenants } = storeToRefs(tenantStore)

onMounted(() => tenantStore.loadTenants())
</script>

<template>
  <div>
    <!-- Hero cinematique -->
    <section class="relative overflow-hidden bg-slate-950">
      <img
        src="https://images.unsplash.com/photo-1521673252667-e05da380b252?auto=format&fit=crop&w=1900&q=75"
        alt=""
        class="absolute inset-0 h-full w-full object-cover opacity-50"
      />
      <div class="absolute inset-0 bg-gradient-to-b from-slate-950/70 via-slate-950/40 to-slate-950" />
      <div class="pointer-events-none absolute right-10 top-24 hidden text-6xl opacity-80 animate-float lg:block">✦</div>
      <div class="section relative py-24 text-center sm:py-32">
        <span class="chip mx-auto mb-5 border border-white/15 bg-white/10 text-white backdrop-blur">TodaTempo · Réservation en ligne</span>
        <h1 class="mx-auto max-w-3xl font-display text-4xl font-extrabold leading-tight tracking-tight text-white sm:text-6xl animate-fade-up">
          Reservez votre moment.<br /><span class="bg-gradient-to-r from-brand-300 to-accent-400 bg-clip-text text-transparent">Prenez soin de vous.</span>
        </h1>
        <p class="mx-auto mt-5 max-w-xl text-lg text-slate-300">
          Choisissez un etablissement, selectionnez votre creneau ou offrez un cheque cadeau.
          Tout se passe en ligne, en quelques minutes.
        </p>
        <div class="mt-8 flex justify-center gap-3">
          <a href="#centres" class="btn bg-white px-6 text-slate-900 hover:bg-white/90">Choisir un etablissement</a>
          <RouterLink :to="{ name: 'beneficiary-login' }" class="btn border border-white/20 bg-white/5 text-white backdrop-blur hover:bg-white/15">J'ai un chèque cadeau</RouterLink>
        </div>
      </div>
    </section>

    <!-- Selection du centre -->
    <section id="centres" class="section -mt-10 pb-16">
      <div class="mb-6 flex items-end justify-between">
        <h2 class="font-display text-2xl font-bold text-slate-900">Nos etablissements partenaires</h2>
        <span class="text-sm text-slate-400">{{ tenants.length }} etablissements (demo)</span>
      </div>

      <Spinner v-if="!tenants.length" />

      <div v-else class="grid gap-6 md:grid-cols-3">
        <RouterLink
          v-for="t in tenants"
          :key="t.id"
          :to="{ name: 'tenant-home', params: { slug: t.slug } }"
          class="group card overflow-hidden transition hover:-translate-y-1 hover:shadow-soft"
        >
          <div class="relative h-40 overflow-hidden">
            <img :src="t.branding.heroImage" :alt="t.name" class="h-full w-full object-cover transition duration-500 group-hover:scale-105" />
            <div class="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent" />
            <div class="absolute bottom-3 left-4 text-white">
              <p class="font-display text-lg font-bold">{{ t.branding.logoEmoji }} {{ t.name }}</p>
              <p class="text-sm text-white/80">{{ t.city }}</p>
            </div>
          </div>
          <div class="p-5">
            <p class="text-sm text-slate-500">{{ t.tagline }}</p>
            <div class="mt-4 flex items-center justify-between">
              <span class="chip bg-slate-100 text-slate-600">{{ t.currency }} · {{ t.locale }}</span>
              <span class="font-medium text-brand-600 group-hover:underline">Entrer →</span>
            </div>
          </div>
        </RouterLink>
      </div>

      <p class="mt-10 text-center text-sm text-slate-400">
        Vous avez un cheque cadeau ?
        <RouterLink :to="{ name: 'beneficiary-login' }" class="font-medium text-brand-600 underline">Activez-le ici</RouterLink>.
      </p>
    </section>
  </div>
</template>
