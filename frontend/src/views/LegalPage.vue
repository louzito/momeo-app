<script setup>
// Page legale configurable (espace centre > Configuration boutique) :
// /legal/terms = Conditions generales, /legal/mentions = Mentions legales.
// Active/desactive par centre ; le contenu est du texte simple (les sauts de
// ligne sont conserves). Une page desactivee affiche un message neutre.
import { computed } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { useTenantContext } from '@/composables/useTenantContext'
import Spinner from '@/components/ui/Spinner.vue'

const route = useRoute()
const { tenant, loading } = useTenantContext()

const LABELS = { terms: 'Conditions générales', mentions: 'Mentions légales' }
const pageKey = computed(() => (route.params.page === 'mentions' ? 'mentions' : 'terms'))
const title = computed(() => LABELS[pageKey.value])
const page = computed(() => tenant.value?.legal?.[pageKey.value] || null)
const enabled = computed(() => page.value?.enabled === true && (page.value?.content || '').trim() !== '')
</script>

<template>
  <Spinner v-if="loading || !tenant" label="Chargement…" />
  <div v-else class="section py-14">
    <div class="mx-auto max-w-3xl">
      <h1 class="font-display text-3xl font-bold text-slate-900">{{ title }}</h1>
      <template v-if="enabled">
        <div class="prose-legal mt-8 text-[15px] leading-relaxed text-slate-700">{{ page.content }}</div>
      </template>
      <div v-else class="mt-8 rounded-2xl border border-slate-200 bg-white p-8 text-slate-500">
        Cette page n'est pas disponible pour ce centre.
      </div>
      <RouterLink :to="{ name: 'tenant-home' }" class="mt-10 inline-block text-sm font-medium text-brand-600 hover:underline">← Retour à l'accueil</RouterLink>
    </div>
  </div>
</template>

<style scoped>
/* Texte simple : on respecte les sauts de ligne saisis dans l'espace centre. */
.prose-legal { white-space: pre-line; }
</style>
