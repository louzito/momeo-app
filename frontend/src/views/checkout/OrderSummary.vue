<script setup>
import { useRouter, RouterLink } from 'vue-router'
import { useCheckoutGuard } from '@/composables/useCheckoutGuard'
import { useTenantContext } from '@/composables/useTenantContext'
import CheckoutLayout from '@/components/CheckoutLayout.vue'
import { formatDate, formatTime, formatMoney } from '@/utils/format'

const router = useRouter()
const { cart } = useCheckoutGuard()
const { tenant, slug } = useTenantContext()

function pay() {
  router.push({ name: 'checkout-payment', params: { slug: slug.value } })
}
</script>

<template>
  <CheckoutLayout
    v-if="cart.jumpType"
    step="summary"
    title="Recapitulatif de votre commande"
    subtitle="Verifiez les details avant de passer au paiement."
  >
    <div class="space-y-5">
      <!-- Prestation -->
      <div class="card flex items-center gap-4 p-4">
        <img :src="cart.jumpType.image" :alt="cart.jumpType.name" class="h-20 w-20 rounded-xl object-cover" />
        <div class="flex-1">
          <p class="font-semibold text-slate-900">{{ cart.jumpType.name }}</p>
          <p class="text-sm text-slate-500">{{ tenant?.name }}</p>
          <p v-if="cart.isGift" class="mt-1 text-sm text-accent-600">🎁 Cheque cadeau</p>
        </div>
        <span class="font-semibold text-slate-800">{{ formatMoney(cart.jumpType.basePrice, tenant?.currency) }}</span>
      </div>

      <!-- Creneau (direct) -->
      <div v-if="!cart.isGift && cart.slot" class="card p-4">
        <p class="text-sm font-semibold text-slate-700">Creneau reserve</p>
        <p class="mt-1 capitalize text-slate-800">
          🗓️ {{ formatDate(cart.slot.start, { weekday: true }) }} a {{ formatTime(cart.slot.start) }}
        </p>
        <p class="text-sm text-slate-400">Membre de l'équipe : {{ cart.slot.instructor }}</p>
      </div>

      <!-- Beneficiaire (gift) -->
      <div v-if="cart.isGift" class="card p-4">
        <p class="text-sm font-semibold text-slate-700">Beneficiaire du cadeau</p>
        <p class="mt-1 text-slate-800">👤 {{ cart.gift.name || cart.gift.email }}</p>
        <p class="text-sm text-slate-400">{{ cart.gift.email }}</p>
        <p v-if="cart.gift.message" class="mt-2 rounded-lg bg-slate-50 p-2 text-sm italic text-slate-600">« {{ cart.gift.message }} »</p>
      </div>

      <!-- Client (direct) -->
      <div v-if="!cart.isGift && (cart.jumper.firstName || cart.jumper.lastName)" class="card p-4">
        <p class="text-sm font-semibold text-slate-700">Coordonnees</p>
        <p class="mt-1 text-slate-800">{{ cart.jumper.firstName }} {{ cart.jumper.lastName }}</p>
        <p class="text-sm text-slate-500">{{ cart.jumper.email }}<template v-if="cart.jumper.phone"> · {{ cart.jumper.phone }}</template></p>
        <p class="text-sm text-emerald-600">✓ Conditions de reservation acceptees</p>
      </div>

      <!-- Options -->
      <div v-if="cart.selectedOptions.length" class="card p-4">
        <p class="mb-2 text-sm font-semibold text-slate-700">Options</p>
        <ul class="divide-y divide-slate-100">
          <li v-for="o in cart.selectedOptions" :key="o.id" class="flex justify-between py-2 text-sm">
            <span class="text-slate-600">{{ o.name }}</span>
            <span class="text-slate-700">+{{ formatMoney(o.price, tenant?.currency) }}</span>
          </li>
        </ul>
      </div>
    </div>

    <div class="mt-8 flex items-center justify-between">
      <RouterLink :to="{ name: 'checkout-options', params: { slug } }" class="btn-ghost">← Modifier</RouterLink>
      <button class="btn-primary px-8" @click="pay">Proceder au paiement</button>
    </div>
  </CheckoutLayout>
</template>
