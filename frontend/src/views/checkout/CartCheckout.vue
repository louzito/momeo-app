<script setup>
import { computed, ref } from 'vue'
import { useCartStore } from '@/stores/cart'
import { useTenantContext } from '@/composables/useTenantContext'
import { formatMoney } from '@/utils/format'
import Payment from './Payment.vue'
const cart = useCartStore()
const { tenant } = useTenantContext()
const form = ref(null), processing = ref(false), error = ref('')
const locked = computed(() => processing.value || !!cart.checkoutPayload || !!cart.lastResult)
const pickup = computed(() => cart.products.every(p => p.pickupEnabled))
const delivery = computed(() => cart.products.every(p => p.deliveryEnabled))
async function verify() {
  error.value = ''
  if (!form.value.reportValidity()) return false
  if (cart.jumpType && (!cart.slot || !cart.eligibilityChecked)) { error.value = 'Vérifiez le créneau et les informations de votre prestation avant le paiement.'; return false }
  if (cart.products.length && !(cart.fulfillmentMode === 'pickup' ? pickup.value : delivery.value)) { error.value = 'Choisissez un mode de remise disponible pour tous les produits.'; return false }
  return true
}
</script>
<template>
  <main class="section space-y-6 py-10">
    <h1 class="text-3xl font-bold">Votre panier</h1>
    <p>Une seule réservation de prestation par commande. Vous pouvez ajouter plusieurs produits et cartes cadeaux.</p>
    <p v-if="!cart.hasItems">Votre panier est vide. <RouterLink :to="{ name: 'shop' }" class="underline">Découvrir la boutique</RouterLink></p>
    <template v-else>
      <p v-if="cart.checkoutPayload && !cart.lastResult" role="status" class="rounded bg-amber-50 p-4">Votre commande a été envoyée. Réessayez le paiement pour retrouver son résultat, sans créer une seconde commande.</p>
      <div class="grid gap-6 lg:grid-cols-2">
        <section class="card space-y-4 p-5" aria-label="Articles du panier">
          <div v-if="cart.jumpType" class="border-b pb-4">
            <h2 class="font-semibold">{{ cart.jumpType.name }} — {{ formatMoney(cart.subtotal, tenant?.currency) }}</h2>
            <p v-if="cart.slot">{{ new Date(cart.slot.start).toLocaleString('fr-FR') }}</p><p v-else>Créneau à choisir.</p>
            <p v-for="option in cart.selectedOptions" :key="option.id">{{ option.name }}</p>
            <RouterLink v-if="!locked" :to="{ name: cart.slot ? 'checkout-eligibility' : 'checkout-schedule' }" class="underline">Vérifier le créneau et les informations</RouterLink>
            <button v-if="!locked" class="btn-ghost" @click="cart.jumpType = null; cart.slot = null; cart.perJumpOptions = []; cart.perOrderOptions = []">Retirer la prestation</button>
          </div>
          <div v-for="(product, index) in cart.products" :key="product.id" class="flex flex-wrap items-center justify-between gap-2 border-b pb-3">
            <span>{{ product.name }} — {{ formatMoney(product.price * product.quantity, tenant?.currency) }}</span>
            <label>Quantité de {{ product.name }} <input v-model.number="product.quantity" class="input w-20" type="number" min="1" :max="Math.min(99, product.stock)" :disabled="locked" /></label><button class="btn-ghost" :disabled="locked" @click="cart.products.splice(index, 1)">Retirer</button>
          </div>
          <div v-for="(gift, index) in cart.gifts" :key="index" class="flex flex-wrap justify-between gap-2 border-b pb-3"><span>Carte cadeau pour {{ gift.recipientName }} — {{ formatMoney(gift.amount / 100, tenant?.currency) }}</span><button class="btn-ghost" :disabled="locked" @click="cart.gifts.splice(index, 1)">Retirer</button></div>
          <template v-if="cart.products.length">
            <label class="block">Mode de remise <select v-model="cart.fulfillmentMode" class="input" :disabled="locked"><option value="pickup" :disabled="!pickup">Retrait au centre</option><option value="delivery" :disabled="!delivery">Livraison</option></select></label>
            <p v-if="!pickup && !delivery" role="alert">Ces produits n’ont pas de mode de remise commun. Retirez les produits incompatibles pour commander.</p>
            <p v-if="cart.fulfillmentMode === 'delivery'">Livraison : {{ formatMoney(cart.deliveryFee, tenant?.currency) }}</p>
          </template>
          <p class="font-bold">Total : {{ formatMoney(cart.total, tenant?.currency) }}</p>
          <p>À régler maintenant : {{ formatMoney(cart.dueNow, tenant?.currency) }}</p><p v-if="cart.balanceDue">Solde de la prestation à régler sur place : {{ formatMoney(cart.balanceDue, tenant?.currency) }}</p>
          <p v-if="cart.gifts.length" class="text-sm">Les cartes offertes sont payables intégralement. Le crédit d’une autre carte ne peut pas les financer.</p>
          <RouterLink v-if="!locked" :to="{ name: 'shop' }" class="btn-outline">Continuer mes achats</RouterLink>
        </section>
        <form ref="form" class="card space-y-3 p-5" @submit.prevent>
          <fieldset :disabled="locked" class="space-y-3">
            <legend class="mb-3 font-semibold">Coordonnées communes à la commande</legend>
            <label class="block">Prénom <input v-model.trim="cart.jumper.firstName" class="input" required maxlength="100" autocomplete="given-name" /></label>
            <label class="block">Nom <input v-model.trim="cart.jumper.lastName" class="input" required maxlength="100" autocomplete="family-name" /></label>
            <label class="block">E-mail <input v-model.trim="cart.jumper.email" class="input" type="email" required maxlength="180" autocomplete="email" /></label>
            <template v-if="cart.products.length">
              <label class="block">Adresse de facturation{{ cart.fulfillmentMode === 'delivery' ? ' et de livraison' : '' }} <input v-model.trim="cart.jumper.street" class="input" required maxlength="255" autocomplete="street-address" /></label>
              <label class="block">Code postal <input v-model.trim="cart.jumper.postcode" class="input" required maxlength="20" autocomplete="postal-code" /></label>
              <label class="block">Ville <input v-model.trim="cart.jumper.city" class="input" required maxlength="255" autocomplete="address-level2" /></label>
              <label class="block">Pays <select v-model="cart.jumper.countryCode" class="input"><option value="FR">France</option><option value="BE">Belgique</option><option value="CH">Suisse</option><option value="LU">Luxembourg</option></select></label>
            </template>
          </fieldset>
        </form>
      </div>
      <p v-if="error" role="alert" class="text-rose-700">{{ error }}</p>
      <Payment embedded :before-pay="verify" @processing="processing = $event" />
      <button v-if="cart.lastResult" class="btn-outline" @click="cart.reset()">Commencer une nouvelle commande</button>
      <RouterLink v-if="cart.lastResult" :to="{ name: 'checkout-shop-confirmation', params: { orderToken: cart.lastResult.order.orderToken } }" class="btn-primary">Voir ma commande</RouterLink>
    </template>
  </main>
</template>
