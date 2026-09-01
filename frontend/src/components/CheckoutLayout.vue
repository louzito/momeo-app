<script setup>
import { storeToRefs } from 'pinia'
import { useCartStore } from '@/stores/cart'
import { useTenantStore } from '@/stores/tenant'
import CheckoutStepper from './CheckoutStepper.vue'
import OrderSummaryCard from './OrderSummaryCard.vue'

// Ossature commune des etapes du tunnel : stepper + contenu + recap lateral.
defineProps({
  step: { type: String, required: true },
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  showSummary: { type: Boolean, default: true },
})

const cart = useCartStore()
const tenantStore = useTenantStore()
const { jumpType, selectedOptions, slot, kind, gift } = storeToRefs(cart)
</script>

<template>
  <div class="section py-10">
    <CheckoutStepper :current="step" :kind="cart.kind" />

    <div class="grid gap-8" :class="showSummary ? 'lg:grid-cols-[1fr_360px]' : ''">
      <div>
        <header v-if="title" class="mb-6">
          <h1 class="font-display text-2xl font-bold text-slate-900 sm:text-3xl">{{ title }}</h1>
          <p v-if="subtitle" class="mt-2 text-slate-500">{{ subtitle }}</p>
        </header>
        <slot />
      </div>

      <aside v-if="showSummary" class="lg:sticky lg:top-24 lg:self-start">
        <OrderSummaryCard
          :jump-type="jumpType"
          :options="selectedOptions"
          :slot="slot"
          :kind="kind"
          :gift="gift"
          :currency="tenantStore.currency"
        >
          <slot name="summary-extra" />
        </OrderSummaryCard>
      </aside>
    </div>
  </div>
</template>
