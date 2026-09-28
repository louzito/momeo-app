<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/api'
import { formatMoney } from '@/utils/format'

const route = useRoute()
const card = ref(null)
const loading = ref(true)
const error = ref('')
const print = () => window.print()
async function load() {
  loading.value = true
  error.value = ''
  try { card.value = await api.getGiftCardDocument(route.hash.slice(1)) }
  catch { error.value = 'Cette carte cadeau est introuvable ou momentanément indisponible. Vérifiez le lien reçu par e-mail.' }
  finally { loading.value = false }
}
onMounted(load)
</script>

<template>
  <main class="gift-document section py-12">
    <div class="mx-auto max-w-2xl space-y-6">
      <p v-if="loading" role="status">Chargement de votre carte cadeau…</p>
      <div v-if="error" role="alert"><p>{{ error }}</p><button class="btn-outline mt-4" @click="load">Réessayer</button></div>
      <article v-if="card" class="card space-y-6 border-2 p-6 sm:p-10">
        <h1 class="font-display text-3xl font-bold">Carte cadeau · {{ card.shopName }}</h1>
        <p class="text-4xl font-bold">{{ formatMoney(card.amount / 100, card.currency) }}</p>
        <p v-if="card.recipientName">Pour <strong>{{ card.recipientName }}</strong></p>
        <p v-if="card.buyerName">De la part de {{ card.buyerName }}</p>
        <p v-if="card.message" class="whitespace-pre-wrap break-words">{{ card.message }}</p>
        <p>Code à utiliser au paiement : <strong class="block break-all font-mono">{{ card.code }}</strong></p>
        <p>Valable jusqu’au {{ card.expiresAt.split('-').reverse().join('/') }} dans la boutique de {{ card.shopName }}.</p>
        <p v-if="card.status !== 'active'">Cette carte est {{ card.status === 'expired' ? 'expirée' : 'inactive' }}.</p>
        <a :href="card.shopUrl" class="break-all underline" rel="noreferrer">{{ card.shopUrl }}</a>
      </article>
      <button v-if="card" class="gift-print-button btn-primary" @click="print">Imprimer ma carte cadeau</button>
    </div>
  </main>
</template>

<style>
@media print {
  body:has(.gift-document) header, body:has(.gift-document) footer, .gift-print-button { display: none !important; }
  .gift-document { padding: 0; color: #000; }
  .gift-document article { box-shadow: none; break-inside: avoid; }
}
</style>
