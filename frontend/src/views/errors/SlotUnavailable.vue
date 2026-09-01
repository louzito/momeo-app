<script setup>
import { RouterLink, useRoute } from 'vue-router'
import StatusScreen from '@/components/StatusScreen.vue'

const route = useRoute()
const slug = route.query.slug
</script>

<template>
  <StatusScreen
    icon="🈵"
    tone="amber"
    title="Creneau indisponible"
    message="Ce creneau vient d'afficher complet ou n'est plus disponible. Choisissez une autre date dans le calendrier."
  >
    <RouterLink v-if="slug" :to="{ name: 'calendar', params: { slug } }" class="btn-primary">Voir le calendrier</RouterLink>
    <RouterLink :to="{ name: 'home' }" class="btn-outline">Accueil</RouterLink>
  </StatusScreen>
</template>
