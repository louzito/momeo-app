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
      <span class="chip bg-brand-50 text-brand-700">🎁 Espace beneficiaire</span>
      <h1 class="mt-4 font-display text-4xl font-extrabold text-slate-900">Activez votre cheque cadeau</h1>
      <p class="mt-3 text-lg text-slate-500">
        Saisissez le code de votre cheque cadeau et l'email sur lequel vous l'avez recu. Vous pourrez
        ensuite choisir librement la date de votre prestation.
      </p>
    </div>

    <div class="card mx-auto w-full max-w-md p-8">
      <form @submit.prevent="submit" class="space-y-5">
        <div>
          <label class="label">Code du cheque cadeau</label>
          <input v-model="code" class="input font-mono" placeholder="6244956659" inputmode="numeric" />
        </div>
        <div>
          <label class="label">Email du beneficiaire</label>
          <input v-model="email" type="email" class="input" placeholder="vous@example.com" />
        </div>
        <p v-if="error" class="rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-600">{{ error }}</p>
        <button class="btn-primary w-full py-3" :disabled="loading">
          {{ loading ? 'Connexion…' : 'Acceder a mes cheques' }}
        </button>
      </form>
      <p class="mt-4 text-center text-sm text-slate-400">
        Vous cherchez vos reservations ?
        <RouterLink :to="{ name: 'account-login' }" class="text-brand-600 underline">Espace client</RouterLink>
      </p>
    </div>
  </div>
</template>
