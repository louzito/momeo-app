<script setup>
// Page BOUTIQUE : tous les produits du centre, dans l'ordre configure dans
// l'espace centre (Configuration boutique > Boutique). L'accueil, lui,
// n'affiche que la selection "mise en avant" (max 9).
import { computed } from 'vue'
import { useTenantContext } from '@/composables/useTenantContext'
import { orderJumpTypes } from '@/utils/catalog'
import JumpTypeCard from '@/components/JumpTypeCard.vue'
import Spinner from '@/components/ui/Spinner.vue'

const { tenant, jumpTypes, loading, slug } = useTenantContext()

const products = computed(() => orderJumpTypes(jumpTypes.value || [], tenant.value?.shopOrder))
</script>

<template>
  <Spinner v-if="loading || !tenant" label="Chargement de la boutique…" />

  <div v-else class="section py-14">
    <div class="mb-10">
      <h1 class="font-display text-3xl font-bold text-slate-900 sm:text-4xl">Boutique</h1>
      <p class="mt-2 text-slate-500">Toutes nos expériences et formules.</p>
    </div>

    <div v-if="products.length" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
      <JumpTypeCard
        v-for="jt in products"
        :key="jt.id"
        :jump-type="jt"
        :currency="tenant.currency"
        :slug="slug"
      />
    </div>
    <div v-else class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-slate-500">
      Aucun produit pour le moment — revenez bientôt !
    </div>
  </div>
</template>
