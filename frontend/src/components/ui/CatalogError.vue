<script setup>
defineProps({ message: { type: String, default: '' } })
defineEmits(['retry'])
</script>

<template>
  <div class="section py-16" role="alert">
    <div class="mx-auto max-w-xl rounded-2xl border border-red-200 bg-red-50 px-6 py-10 text-center">
      <div class="mb-3 text-3xl" aria-hidden="true">⚠️</div>
      <h1 class="font-display text-xl font-bold text-slate-900">Catalogue temporairement indisponible</h1>
      <p class="mt-2 text-sm text-slate-600">
        {{ message || "Nous n’avons pas pu charger les prestations. Veuillez réessayer dans quelques instants." }}
      </p>
      <button class="btn-primary mt-6" type="button" @click="$emit('retry')">Réessayer</button>
    </div>
  </div>
</template>
