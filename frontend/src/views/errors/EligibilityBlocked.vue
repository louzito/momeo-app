<script setup>
import { RouterLink } from 'vue-router'
import StatusScreen from '@/components/StatusScreen.vue'
</script>

<template>
  <StatusScreen
    icon="🚫"
    tone="rose"
    title="Eligibilite non respectee"
    message="Les informations déclarées ne respectent pas les conditions de réservation de cette prestation. Contactez l'établissement pour vérifier les modalités du rendez-vous."
  >
    <RouterLink :to="{ name: 'home' }" class="btn-primary">Retour a l'accueil</RouterLink>
    <a href="#" class="btn-outline">Contacter le centre</a>
  </StatusScreen>
</template>
