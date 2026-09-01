<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useCheckoutGuard } from '@/composables/useCheckoutGuard'
import { useTenantContext } from '@/composables/useTenantContext'
import { useSessionStore } from '@/stores/session'
import api from '@/api'
import CheckoutLayout from '@/components/CheckoutLayout.vue'
import { formatMoney } from '@/utils/format'

const router = useRouter()
const session = useSessionStore()
const { cart } = useCheckoutGuard()
const { tenant, slug } = useTenantContext()

// Le virement cree une VRAIE commande Sylius ; la carte reste une simulation
// (Stripe sera branche plus tard, aucune commande reelle n'en resulte).
// Cheques cadeaux REELS (chantier 2026-08) : un cheque doit correspondre a une
// vraie commande Sylius (le backend cree le GiftVoucher a partir de la
// commande) -> le virement est donc le SEUL moyen de paiement propose pour un
// cadeau ; la carte demo reste reservee a l'achat direct.
// Le virement n'est propose QUE si le centre l'a active dans son espace admin
// (payment-method Sylius `bank_transfer` enabled) — verifie sur le shop API.
const bankMethod = ref(null)
const methodsLoaded = ref(false)
const canBankTransfer = computed(() => !!bankMethod.value)
const canCardDemo = computed(() => !cart.isGift)
const method = ref(cart.isGift ? 'bank_transfer' : cart.paymentMethod)

onMounted(async () => {
  try {
    const list = (await api.getCheckoutPaymentMethods?.()) || []
    bankMethod.value = list.find((m) => m.code === 'bank_transfer') || null
  } catch {
    bankMethod.value = null
  }
  methodsLoaded.value = true
  if (cart.isGift) {
    // Cadeau : force le virement des qu'il est disponible (aucun repli sur la
    // carte demo, qui ne cree pas de commande reelle donc pas de cheque).
    if (canBankTransfer.value) selectMethod('bank_transfer')
  } else if (!canBankTransfer.value && method.value === 'bank_transfer') {
    // Virement desactive par le centre -> repli sur la carte demo.
    selectMethod('card_demo')
  }
})

// Carte factice pre-remplie (aucun vrai paiement).
const card = ref({
  number: '4242 4242 4242 4242',
  name: 'LOU MARTIN',
  expiry: '12/29',
  cvc: '123',
})
const processing = ref(false)
const error = ref('')

function selectMethod(m) {
  method.value = m
  cart.setPaymentMethod(m)
}

// Cadeau sans virement active chez le centre : aucun moyen de creer une
// vraie commande -> on bloque avant l'appel API plutot que de laisser passer
// silencieusement un cheque cadeau mock.
const blocked = computed(() => cart.isGift && methodsLoaded.value && !canBankTransfer.value)

async function pay() {
  if (blocked.value) {
    error.value = "Le virement n'est pas active par ce centre : impossible de generer un cheque cadeau pour le moment. Contactez le centre."
    return
  }
  processing.value = true
  error.value = ''
  try {
    cart.setPaymentMethod(method.value)
    if (method.value === 'card_demo') {
      await api.processPayment({
        amount: cart.total,
        currency: tenant.value.currency,
        card: card.value,
      })
    }
    await cart.checkout(session.customer?.id || null)
    const next = cart.isGift ? 'checkout-gift-confirmation' : 'checkout-confirmation'
    router.push({ name: next, params: { slug: slug.value } })
  } catch (e) {
    error.value = e?.message || 'La commande a echoue. Reessayez.'
  } finally {
    processing.value = false
  }
}
</script>

<template>
  <CheckoutLayout
    v-if="cart.jumpType"
    step="payment"
    title="Paiement"
    subtitle="Choisissez votre moyen de paiement."
  >
    <div class="max-w-lg">
      <!-- Choix du moyen de paiement -->
      <div class="grid gap-3 sm:grid-cols-2">
        <button
          v-if="canBankTransfer"
          type="button"
          class="card p-4 text-left transition hover:border-brand-400"
          :class="method === 'bank_transfer' ? 'border-brand-500 ring-2 ring-brand-500/20' : ''"
          @click="selectMethod('bank_transfer')"
        >
          <div class="text-2xl">🏦</div>
          <p class="mt-2 font-semibold text-slate-900">{{ bankMethod?.name || 'Virement bancaire' }}</p>
          <p class="mt-1 text-xs text-slate-500">
            Commande enregistree immediatement, confirmee a reception du virement.
          </p>
        </button>

        <button
          v-if="canCardDemo"
          type="button"
          class="card p-4 text-left transition hover:border-brand-400"
          :class="method === 'card_demo' ? 'border-brand-500 ring-2 ring-brand-500/20' : ''"
          @click="selectMethod('card_demo')"
        >
          <div class="text-2xl">💳</div>
          <p class="mt-2 font-semibold text-slate-900">Carte bancaire</p>
          <p class="mt-1 text-xs text-slate-500">Demo — aucun paiement reel (Stripe plus tard).</p>
        </button>
      </div>

      <!-- Cadeau + virement non active par le centre : blocage explicite -->
      <div v-if="blocked" class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-700">
        Ce centre n'a pas encore active le virement bancaire : il est impossible de generer un cheque cadeau reel pour le moment. Contactez le centre directement.
      </div>

      <!-- Virement : comment ca marche -->
      <div v-if="method === 'bank_transfer' && !blocked" class="mt-6 rounded-2xl border border-brand-200 bg-brand-50/50 p-5">
        <p class="font-semibold text-slate-800">Comment ca marche</p>
        <ol v-if="cart.isGift" class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600">
          <li>Votre commande est enregistree tout de suite.</li>
          <li>Vous recevez les coordonnees bancaires et la reference a indiquer.</li>
          <li>Des reception du virement, le cheque cadeau (code + QR) est envoye par email au beneficiaire.</li>
        </ol>
        <ol v-else class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600">
          <li>Votre commande est enregistree tout de suite (creneau garde).</li>
          <li>Vous recevez les coordonnees bancaires et la reference a indiquer.</li>
          <li>L'etablissement confirme votre rendez-vous a reception du virement.</li>
        </ol>
      </div>

      <!-- Carte demo : formulaire factice -->
      <template v-else>
        <div class="mt-6 rounded-2xl bg-gradient-to-br from-slate-800 to-slate-900 p-6 text-white shadow-soft">
          <div class="flex justify-between">
            <span class="text-sm text-white/60">Carte bancaire</span>
            <span>💳</span>
          </div>
          <p class="mt-6 font-mono text-xl tracking-widest">{{ card.number }}</p>
          <div class="mt-4 flex justify-between text-sm">
            <span>{{ card.name }}</span>
            <span>{{ card.expiry }}</span>
          </div>
        </div>

        <div class="mt-6 space-y-4">
          <div>
            <label class="label">Numero de carte</label>
            <input v-model="card.number" class="input" />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label">Expiration</label>
              <input v-model="card.expiry" class="input" placeholder="MM/AA" />
            </div>
            <div>
              <label class="label">CVC</label>
              <input v-model="card.cvc" class="input" placeholder="123" />
            </div>
          </div>
          <div>
            <label class="label">Nom sur la carte</label>
            <input v-model="card.name" class="input" />
          </div>
        </div>
      </template>

      <div v-if="error" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
        ⚠️ {{ error }}
      </div>

      <button class="btn-primary mt-6 w-full py-3 text-base" :disabled="processing || blocked" @click="pay">
        <template v-if="processing">{{ method === 'bank_transfer' ? 'Enregistrement…' : 'Paiement en cours…' }}</template>
        <template v-else>
          {{ method === 'bank_transfer'
            ? `Commander ${formatMoney(cart.total, tenant?.currency)} (payer par virement)`
            : `Payer ${formatMoney(cart.total, tenant?.currency)}` }}
        </template>
      </button>
      <p class="mt-3 flex items-center justify-center gap-1 text-xs text-slate-400">
        {{ method === 'bank_transfer' ? '🏦 Commande reelle (Sylius) · paiement a reception du virement' : '🔒 Paiement simule · conforme PCI-DSS (delegue a la passerelle)' }}
      </p>
    </div>
  </CheckoutLayout>
</template>
