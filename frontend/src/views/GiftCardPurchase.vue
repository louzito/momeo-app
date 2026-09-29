<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import api from '@/api'
import { useCartStore } from '@/stores/cart'
import { useTenantContext } from '@/composables/useTenantContext'
import { formatMoney } from '@/utils/format'

const router = useRouter()
const cart = useCartStore()
const { tenant } = useTenantContext()
const offer = ref(null)
const loading = ref(true)
const submitting = ref(false)
const error = ref('')
const amountChoice = ref('5000')
const customAmount = ref('')
const form = ref({ buyerName: '', buyerEmail: '', recipientName: '', recipientEmail: '', message: '', delivery: 'buyer', paymentMethod: '' })
const amount = computed(() => {
  if (amountChoice.value !== 'custom') return Number(amountChoice.value)
  const value = customAmount.value.trim().replace(',', '.')
  if (!/^\d+(\.\d{1,2})?$/.test(value)) return null
  const [euros, cents = ''] = value.split('.')
  return Number(euros) * 100 + Number(cents.padEnd(2, '0'))
})
const validAmount = computed(() => Number.isSafeInteger(amount.value) && amount.value >= offer.value?.minimum && amount.value <= offer.value?.maximum)
async function load() {
  loading.value = true
  error.value = ''
  try {
    offer.value = await api.getGiftCardOffer()
    form.value.paymentMethod = offer.value.paymentMethods[0]?.code || ''
  } catch { error.value = 'Impossible de charger les cartes cadeaux. Veuillez réessayer.' }
  finally { loading.value = false }
}
async function submit() {
  if (submitting.value || !validAmount.value) return
  submitting.value = true
  error.value = ''
  try {
    if (!cart.addGift(tenant.value.id, { recipientName: form.value.recipientName, recipientEmail: form.value.recipientEmail, message: form.value.message, delivery: form.value.delivery, amount: amount.value })) throw new Error('Terminez la commande en cours ou limitez le panier à dix cartes cadeaux.')
    await router.push({ name: 'cart' })
  } catch (e) {
    error.value = e?.message ? e.message
      : e?.status === 429 ? 'Veuillez patienter une minute avant de réessayer.'
        : 'Impossible de préparer votre carte cadeau. Veuillez réessayer.'
  }
  finally { submitting.value = false }
}
onMounted(load)
</script>

<template>
  <main class="section py-10">
    <div class="mx-auto max-w-2xl space-y-6">
      <h1 class="font-display text-3xl font-bold">Offrir une carte cadeau</h1>
      <p>Offrez un crédit à utiliser dans la boutique de l’établissement. Votre destinataire choisira ses prestations ou produits, sans date à fixer aujourd’hui.</p>
      <p v-if="loading" role="status">Chargement des cartes cadeaux…</p>
      <div v-if="error" role="alert" class="rounded-xl bg-rose-50 p-4 text-rose-700">{{ error }}</div>
      <button v-if="!loading && !offer" class="btn-outline" @click="load">Réessayer</button>
      <p v-if="offer && !offer.enabled" class="card p-6">La vente de cartes cadeaux est momentanément désactivée. Les cartes déjà achetées restent utilisables selon leurs conditions de validité.</p>
      <p v-else-if="offer && !offer.paymentMethods.length" class="card p-6">Aucun moyen de paiement n’est disponible pour le moment. Contactez l’établissement pour offrir une carte cadeau.</p>
      <form v-else-if="offer" class="space-y-6" @submit.prevent="submit">
        <fieldset class="card space-y-4 p-5" :disabled="submitting">
          <legend class="font-semibold">Montant de la carte</legend>
          <div class="flex flex-wrap gap-4">
            <label v-for="preset in offer.presets" :key="preset" class="flex items-center gap-2 rounded-xl border p-3">
              <input v-model="amountChoice" type="radio" name="amount" :value="String(preset)" /> {{ formatMoney(preset / 100, offer.currency) }}
            </label>
            <label class="flex items-center gap-2 rounded-xl border p-3"><input v-model="amountChoice" type="radio" name="amount" value="custom" /> Montant libre</label>
          </div>
          <label v-if="amountChoice === 'custom'" class="block">Montant en euros
            <input v-model="customAmount" class="input mt-2 w-full" inputmode="decimal" required maxlength="10" placeholder="75,50" aria-describedby="amount-help" />
          </label>
          <p id="amount-help" class="text-sm text-slate-600">Entre {{ formatMoney(offer.minimum / 100, offer.currency) }} et {{ formatMoney(offer.maximum / 100, offer.currency) }}, au centime près.</p>
        </fieldset>
        <fieldset class="card space-y-4 p-5" :disabled="submitting">
          <legend class="font-semibold">Votre cadeau</legend>
          <label class="block">Nom du destinataire <input v-model="form.recipientName" class="input mt-2 w-full" required maxlength="100" /></label>
          <label class="block">Un message (facultatif) <textarea v-model="form.message" class="input mt-2 w-full" rows="4" maxlength="1000" /></label>
          <label class="flex items-start gap-2"><input v-model="form.delivery" type="radio" name="delivery" value="buyer" class="mt-1" /> Recevoir la carte par e-mail pour l’imprimer ou l’offrir moi-même</label>
          <label class="flex items-start gap-2"><input v-model="form.delivery" type="radio" name="delivery" value="recipient" class="mt-1" /> Envoyer la carte directement au destinataire après paiement</label>
          <label v-if="form.delivery === 'recipient'" class="block">E-mail du destinataire <input v-model="form.recipientEmail" class="input mt-2 w-full" type="email" required maxlength="254" /></label>
        </fieldset>
        <section class="card space-y-3 p-5" aria-label="Récapitulatif avant paiement">
          <h2 class="font-semibold">Votre récapitulatif</h2>
          <p>Boutique d’utilisation : <strong>{{ offer.shopName }}</strong></p>
          <p>Validité : <strong>{{ offer.validityMonths }} mois à compter de la confirmation du paiement</strong>.</p>
          <p v-if="validAmount">À payer : <strong>{{ formatMoney(amount / 100, offer.currency) }}</strong> · Crédit offert : <strong>{{ formatMoney(amount / 100, offer.currency) }}</strong>.</p>
          <p v-else class="text-rose-700">Saisissez un montant valide.</p>
          <p>Envoi à : {{ form.delivery === 'buyer' ? (form.buyerEmail || 'votre adresse e-mail') : (form.recipientEmail || 'l’adresse e-mail du destinataire') }}.</p>
          <p class="text-sm text-slate-600">Le code et le lien imprimable seront envoyés uniquement après encaissement du montant complet.</p>
          <button class="btn-primary w-full" type="submit" :disabled="submitting || !validAmount">{{ submitting ? 'Préparation de votre commande…' : 'Ajouter au panier' }}</button>
        </section>
      </form>
      <p class="text-sm"><RouterLink :to="{ name: 'beneficiary-login' }" class="underline">Vous possédez un ancien bon pour une prestation ? Accédez à votre espace bénéficiaire.</RouterLink></p>
    </div>
  </main>
</template>
