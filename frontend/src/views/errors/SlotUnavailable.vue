<script setup>
import { RouterLink } from 'vue-router'
import { useCartStore } from '@/stores/cart'
import StatusScreen from '@/components/StatusScreen.vue'

const cart = useCartStore()
</script>

<template>
  <StatusScreen
    icon="🈵"
    tone="amber"
    title="Créneau indisponible"
    message="Ce créneau est complet ou n’est plus disponible. Choisissez une autre date pour votre prestation."
  >
    <RouterLink v-if="cart.jumpType" :to="{ name: 'checkout-schedule' }" class="btn-primary">Choisir une autre date</RouterLink>
    <RouterLink :to="{ name: 'shop' }" class="btn-outline">Découvrir nos prestations</RouterLink>
  </StatusScreen>
</template>
