<script setup>
import { ref } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { useSessionStore } from '@/stores/session'

const router = useRouter()
const route = useRoute()
const session = useSessionStore()

const mode = ref('login') // 'login' | 'register' | 'forgot' | 'forgot-sent'
const form = ref({ email: '', password: '', firstName: '', lastName: '', phone: '' })
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    if (mode.value === 'login') {
      await session.login(form.value.email, form.value.password)
    } else {
      await session.register({ ...form.value })
    }
    router.push(route.query.redirect || { name: 'account-dashboard' })
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

async function requestPasswordReset() {
  error.value = ''
  loading.value = true
  try {
    await session.requestPasswordReset(form.value.email)
    mode.value = 'forgot-sent'
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
      <span class="chip bg-brand-50 text-brand-700">👤 Espace client</span>
      <h1 class="mt-4 font-display text-4xl font-extrabold text-slate-900">Votre compte TodaTempo</h1>
      <p class="mt-3 text-lg text-slate-500">
        Retrouvez l'historique de vos commandes, vos reservations a venir et leurs confirmations.
      </p>
    </div>

    <div class="card mx-auto w-full max-w-md p-8">
      <div class="mb-6 flex rounded-xl bg-slate-100 p-1">
        <button
          class="flex-1 rounded-lg py-2 text-sm font-semibold transition"
          :class="mode === 'login' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'"
          @click="mode = 'login'"
        >Connexion</button>
        <button
          class="flex-1 rounded-lg py-2 text-sm font-semibold transition"
          :class="mode === 'register' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500'"
          @click="mode = 'register'"
        >Inscription</button>
      </div>

      <p v-if="mode === 'forgot-sent'" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-700">Si un compte correspond à cette adresse, un email de réinitialisation vient d'être envoyé.</p>
      <form v-else @submit.prevent="mode === 'forgot' ? requestPasswordReset() : submit()" class="space-y-4">
        <div v-if="mode === 'register'" class="grid grid-cols-2 gap-3">
          <div>
            <label class="label">Prenom</label>
            <input v-model="form.firstName" class="input" />
          </div>
          <div>
            <label class="label">Nom</label>
            <input v-model="form.lastName" class="input" />
          </div>
        </div>
        <div>
          <label class="label">Email</label>
          <input v-model="form.email" required type="email" class="input" placeholder="vous@example.com" />
        </div>
        <div v-if="mode !== 'forgot'">
          <label class="label">Mot de passe</label>
          <input v-model="form.password" required type="password" class="input" placeholder="••••••" />
        </div>
        <div v-if="mode === 'register'">
          <label class="label">Telephone (optionnel)</label>
          <input v-model="form.phone" class="input" />
        </div>
        <p v-if="error" class="rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-600">{{ error }}</p>
        <button class="btn-primary w-full py-3" :disabled="loading">
          {{ loading ? 'Veuillez patienter…' : mode === 'login' ? 'Se connecter' : mode === 'forgot' ? 'Envoyer le lien' : "Creer mon compte" }}
        </button>
        <button v-if="mode === 'login'" type="button" class="w-full text-sm text-brand-600 underline" @click="mode = 'forgot'">Mot de passe oublié ?</button>
        <button v-if="mode === 'forgot'" type="button" class="w-full text-sm text-brand-600 underline" @click="mode = 'login'">Retour à la connexion</button>
      </form>

      <p class="mt-4 text-center text-sm text-slate-400">
        Vous avez un cheque cadeau ?
        <RouterLink :to="{ name: 'beneficiary-login' }" class="text-brand-600 underline">Espace beneficiaire</RouterLink>
      </p>
    </div>
  </div>
</template>
