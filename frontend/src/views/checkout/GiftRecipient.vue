<script setup>
import { ref, onMounted } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useCheckoutGuard } from '@/composables/useCheckoutGuard'
import { useTenantContext } from '@/composables/useTenantContext'
import { useSessionStore } from '@/stores/session'
import CheckoutLayout from '@/components/CheckoutLayout.vue'

const router = useRouter()
const { cart } = useCheckoutGuard()
const { slug } = useTenantContext()
const session = useSessionStore()
const { gift } = storeToRefs(cart)
const error = ref('')

// Coordonnees de l'acheteur = email/adresse de facturation de la VRAIE
// commande Sylius (le beneficiaire, lui, n'a pas de compte a ce stade).
// Prefill depuis la session si le client est connecte.
onMounted(() => {
  if (session.customer && !gift.value.purchaserEmail) {
    gift.value.purchaserName = session.fullName || gift.value.purchaserName
    gift.value.purchaserEmail = session.customer.email || gift.value.purchaserEmail
  }
})

function next() {
  if (!gift.value.purchaserEmail) {
    error.value = 'Votre email est requis pour enregistrer la commande.'
    return
  }
  if (!gift.value.email) {
    error.value = "L'email du beneficiaire est requis pour lui envoyer le cheque cadeau."
    return
  }
  router.push({ name: 'checkout-summary', params: { slug: slug.value } })
}
</script>

<template>
  <CheckoutLayout
    v-if="cart.jumpType"
    step="gift"
    title="A qui offrez-vous cette prestation ?"
    subtitle="Le cheque cadeau nominatif sera envoye par email au beneficiaire."
  >
    <div class="max-w-lg space-y-5">
      <div class="card space-y-4 p-4">
        <p class="text-sm font-semibold text-slate-700">Vos coordonnees (acheteur)</p>
        <div>
          <label class="label">Votre nom</label>
          <input v-model="gift.purchaserName" type="text" class="input" placeholder="Prenom Nom" />
        </div>
        <div>
          <label class="label">Votre email</label>
          <input v-model="gift.purchaserEmail" type="email" class="input" placeholder="vous@example.com" />
          <p class="mt-1 text-xs text-slate-400">Sert a enregistrer la commande et vous confirmer l'achat.</p>
        </div>
      </div>

      <div>
        <label class="label">Nom du beneficiaire</label>
        <input v-model="gift.name" type="text" class="input" placeholder="Prenom Nom" />
      </div>
      <div>
        <label class="label">Email du beneficiaire</label>
        <input v-model="gift.email" type="email" class="input" placeholder="beneficiaire@example.com" />
        <p class="mt-1 text-xs text-slate-400">C'est cet email qui servira a activer le cheque cadeau.</p>
      </div>
      <div>
        <label class="label">Message personnalise (optionnel)</label>
        <textarea v-model="gift.message" rows="3" class="input" placeholder="Joyeux anniversaire ! …"></textarea>
      </div>

      <p v-if="error" class="rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-600">{{ error }}</p>
    </div>

    <div class="mt-8 flex items-center justify-between">
      <RouterLink :to="{ name: 'checkout-mode', params: { slug } }" class="btn-ghost">← Retour</RouterLink>
      <button class="btn-primary px-8" @click="next">Continuer</button>
    </div>
  </CheckoutLayout>
</template>
