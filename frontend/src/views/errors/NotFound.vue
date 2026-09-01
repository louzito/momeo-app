<script setup>
import { RouterLink } from 'vue-router'
import StatusScreen from '@/components/StatusScreen.vue'
</script>

<template>
  <StatusScreen
    icon="🪂"
    code="Erreur 404"
    title="Page introuvable"
    message="La page que vous cherchez a peut-etre atterri ailleurs. Revenez sur la terre ferme."
  >
    <RouterLink :to="{ name: 'home' }" class="btn-primary">Retour a l'accueil</RouterLink>
  </StatusScreen>
</template>
