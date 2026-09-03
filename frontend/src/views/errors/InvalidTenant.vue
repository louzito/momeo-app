<script setup>
defineProps({ message: { type: String, required: true } })
</script>

<template>
  <main class="mx-auto flex min-h-screen max-w-xl items-center px-6 text-center">
    <div>
      <h1 class="text-3xl font-bold text-slate-900">Centre introuvable</h1>
      <p class="mt-4 text-slate-600">{{ message }}</p>
    </div>
  </main>
</template>
