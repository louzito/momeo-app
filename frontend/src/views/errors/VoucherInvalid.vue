<script setup>
import { RouterLink } from 'vue-router'
import StatusScreen from '@/components/StatusScreen.vue'
</script>

<template>
  <StatusScreen
    icon="🎫"
    tone="rose"
    title="Cheque cadeau invalide"
    message="Ce cheque cadeau est introuvable, a deja ete utilise, ou le code ne correspond pas a l'email indique. Verifiez le code recu par email."
  >
    <RouterLink :to="{ name: 'beneficiary-login' }" class="btn-primary">Reessayer</RouterLink>
    <RouterLink :to="{ name: 'home' }" class="btn-outline">Accueil</RouterLink>
  </StatusScreen>
</template>
