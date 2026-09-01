<script setup>
import { RouterLink } from 'vue-router'
import StatusScreen from '@/components/StatusScreen.vue'
</script>

<template>
  <StatusScreen
    icon="🚫"
    tone="rose"
    title="Eligibilite non respectee"
    message="Les informations declarees ne respectent pas les regles de securite de ce saut (age, poids, taille, certificat medical ou decharge). Pour votre securite, la reservation ne peut pas aboutir en l'etat."
  >
    <RouterLink :to="{ name: 'home' }" class="btn-primary">Retour a l'accueil</RouterLink>
    <a href="#" class="btn-outline">Contacter le centre</a>
  </StatusScreen>
</template>
