<script setup>
import { ref } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { useBeneficiaryStore } from '@/stores/beneficiary'

const router = useRouter()
const route = useRoute()
const store = useBeneficiaryStore()

// Prefill depuis le QR / lien d'activation de l'email (?code=...).
const code = ref(typeof route.query.code === 'string' ? route.query.code : '')
const email = ref('')
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await store.login(code.value, email.value)
    router.push(route.query.redirect || { name: 'beneficiary-dashboard' })
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="section grid gap-10 py-16 lg:grid-cols-2 lg:items-center">
    <div>
      <span class="chip bg-brand-50 text-brand-700">🎁 Mes cadeaux</span>
      <h1 class="mt-4 font-display text-4xl font-extrabold text-slate-900">Retrouvez vos cadeaux</h1>
      <p class="mt-3 text-lg text-slate-500">
        Retrouvez vos nouvelles cartes cadeaux à solde dans Mon compte, avec vos rendez-vous et commandes.
      </p>
      <RouterLink :to="{ name: 'account-dashboard', hash: '#cadeaux' }" class="btn-primary mt-5">Mes cartes cadeaux dans Mon compte</RouterLink>
    </div>

    <div class="card mx-auto w-full max-w-md p-8">
      <h2 class="mb-3 text-xl font-semibold">Anciens bons prestation</h2>
      <p class="mb-5 text-sm text-slate-600">Saisissez le code de votre bon et l’e-mail sur lequel vous l’avez reçu pour choisir votre rendez-vous.</p>
      <form @submit.prevent="submit" class="space-y-5">
        <div>
          <label class="label">Code du bon prestation</label>
          <input v-model="code" class="input font-mono" placeholder="6244956659" inputmode="numeric" />
        </div>
        <div>
          <label class="label">E-mail du bénéficiaire</label>
          <input v-model="email" type="email" class="input" placeholder="vous@example.com" />
        </div>
        <p v-if="error" class="rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-600">{{ error }}</p>
        <button class="btn-primary w-full py-3" :disabled="loading">
          {{ loading ? 'Connexion…' : 'Accéder à mes bons' }}
        </button>
      </form>
      <p class="mt-4 text-center text-sm text-slate-400">
        Vos rendez-vous, commandes et cartes cadeaux :
        <RouterLink :to="{ name: 'account-login' }" class="text-brand-600 underline">Mon compte</RouterLink>
      </p>
    </div>
  </div>
</template>
