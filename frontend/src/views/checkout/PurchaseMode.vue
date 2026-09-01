<script setup>
import { computed, watchEffect } from 'vue'
import { useRouter } from 'vue-router'
import { useCheckoutGuard } from '@/composables/useCheckoutGuard'
import { useTenantContext } from '@/composables/useTenantContext'
import CheckoutLayout from '@/components/CheckoutLayout.vue'

const router = useRouter()
const { cart } = useCheckoutGuard()
const { slug, tenant } = useTenantContext()

// Cheques cadeaux desactives (Configuration boutique) : l'etape n'a plus de
// raison d'etre -> on enchaine directement sur le choix du creneau.
// NB : l'ESPACE beneficiaire reste accessible (les cheques deja vendus
// doivent pouvoir etre actives) — on ne masque que la VENTE.
const giftEnabled = computed(() => tenant.value?.giftVouchersEnabled !== false)
watchEffect(() => {
  if (tenant.value && !giftEnabled.value && cart.jumpType) {
    choose('direct')
  }
})

function choose(kind) {
  cart.setKind(kind)
  const next = kind === 'gift' ? 'checkout-gift' : 'checkout-schedule'
  router.push({ name: next, params: { slug: slug.value } })
}
</script>

<template>
  <CheckoutLayout
    v-if="cart.jumpType"
    step="options"
    title="Pour vous ou en cadeau ?"
    subtitle="Choisissez si cette prestation est pour vous ou pour un proche."
  >
    <div class="grid gap-5 sm:grid-cols-2">
      <button
        class="group card p-8 text-left transition hover:-translate-y-1 hover:border-brand-400 hover:shadow-soft"
        :class="cart.kind === 'direct' ? 'border-brand-500 ring-2 ring-brand-500/20' : ''"
        @click="choose('direct')"
      >
        <div class="text-4xl">🗓️</div>
        <h3 class="mt-4 font-display text-xl font-bold text-slate-900">Pour moi (avec date)</h3>
        <p class="mt-2 text-sm text-slate-500">
          Choisissez tout de suite votre creneau et recevez votre carte d'embarquement.
        </p>
        <span class="mt-4 inline-block font-medium text-brand-600 group-hover:underline">Choisir une date →</span>
      </button>

      <button
        class="group card p-8 text-left transition hover:-translate-y-1 hover:border-accent-400 hover:shadow-soft"
        :class="cart.kind === 'gift' ? 'border-accent-500 ring-2 ring-accent-500/20' : ''"
        @click="choose('gift')"
      >
        <div class="text-4xl">🎁</div>
        <h3 class="mt-4 font-display text-xl font-bold text-slate-900">En cadeau (cheque cadeau)</h3>
        <p class="mt-2 text-sm text-slate-500">
          Le beneficiaire recoit un cheque cadeau et choisira sa date lui-meme, quand il le souhaite.
        </p>
        <span class="mt-4 inline-block font-medium text-accent-600 group-hover:underline">Offrir cette prestation →</span>
      </button>
    </div>
  </CheckoutLayout>
</template>
