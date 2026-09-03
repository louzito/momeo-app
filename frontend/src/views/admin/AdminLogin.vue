<script setup>
import { onMounted, ref } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { useAdminStore } from '@/stores/admin'

const router = useRouter()
const route = useRoute()
const admin = useAdminStore()

const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)
const automaticLogin = ref(false)

async function goToDashboard(target = null) {
  await router.replace(target || { name: 'admin-dashboard' })
}

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await admin.login(email.value, password.value)
    await goToDashboard(route.query.redirect)
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  const sso = route.query.sso
  if (!sso) return

  const redirect = route.query.redirect
  await router.replace({ name: 'admin-login' })
  if (sso === 'error') {
    error.value = 'Le lien de connexion a expiré. Ouvrez à nouveau votre espace depuis TodaTempo.'
    return
  }

  automaticLogin.value = true
  loading.value = true
  try {
    await admin.loginWithSso()
    await goToDashboard(redirect)
  } catch {
    error.value = 'La connexion automatique a expiré. Ouvrez à nouveau votre espace depuis TodaTempo.'
  } finally {
    automaticLogin.value = false
    loading.value = false
  }
})
</script>

<template>
  <div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-[#f7f4ee] p-4 sm:p-8">
    <div class="absolute -left-32 -top-32 h-96 w-96 rounded-full bg-amber-200/45 blur-3xl" />
    <div class="absolute -bottom-40 -right-24 h-[30rem] w-[30rem] rounded-full bg-rose-200/45 blur-3xl" />

    <div class="relative grid w-full max-w-5xl overflow-hidden rounded-[2rem] border border-white/80 bg-white/80 shadow-soft backdrop-blur lg:grid-cols-[1.05fr_.95fr]">
      <section class="flex min-h-[30rem] flex-col justify-between bg-slate-950 p-8 text-white sm:p-12">
        <div>
          <RouterLink :to="{ name: 'home' }" class="inline-flex items-center gap-3 text-white">
            <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-amber-300 font-display text-xl font-black text-slate-950">T</span>
            <span class="font-display text-xl font-extrabold tracking-tight">TodaTempo</span>
          </RouterLink>
          <p class="mt-16 text-xs font-bold uppercase tracking-[0.24em] text-amber-300">Espace professionnel</p>
          <h1 class="mt-4 max-w-md font-display text-4xl font-extrabold leading-tight sm:text-5xl">
            Votre activité, réunie au même endroit.
          </h1>
          <p class="mt-5 max-w-md text-base leading-7 text-slate-300">
            Gérez vos prestations, votre équipe, vos rendez-vous et votre établissement depuis votre espace TodaTempo.
          </p>
        </div>
        <p class="mt-12 text-sm text-slate-400">Un espace privé, attribué à votre établissement.</p>
      </section>

      <section class="flex items-center p-8 sm:p-12">
        <div class="w-full">
          <p class="text-sm font-semibold text-amber-700">Bienvenue</p>
          <h2 class="mt-2 font-display text-3xl font-bold text-slate-950">
            {{ automaticLogin ? 'Connexion à votre espace…' : 'Connexion à TodaTempo' }}
          </h2>
          <p class="mt-3 text-sm leading-6 text-slate-500">
            {{ automaticLogin ? 'Votre espace sécurisé est en cours d’ouverture.' : 'Utilisez vos identifiants professionnels pour continuer.' }}
          </p>

          <div v-if="automaticLogin" class="mt-8 flex items-center gap-3 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900" role="status">
            <span class="h-5 w-5 animate-spin rounded-full border-2 border-amber-500 border-t-transparent" aria-hidden="true" />
            Vérification de votre accès…
          </div>

          <form v-else class="mt-8 space-y-5" @submit.prevent="submit">
            <div>
              <label class="label" for="admin-email">Email professionnel</label>
              <input id="admin-email" v-model="email" type="email" autocomplete="username" class="input" placeholder="vous@etablissement.fr" required />
            </div>
            <div>
              <label class="label" for="admin-password">Mot de passe</label>
              <input id="admin-password" v-model="password" type="password" autocomplete="current-password" class="input" placeholder="••••••••" required />
            </div>
            <p v-if="error" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">{{ error }}</p>
            <button class="btn-primary w-full py-3" :disabled="loading">
              {{ loading ? 'Connexion…' : 'Accéder à mon espace' }}
            </button>
          </form>

          <p v-if="automaticLogin && error" class="mt-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">{{ error }}</p>
          <p class="mt-7 text-center text-sm text-slate-400">
            <RouterLink :to="{ name: 'home' }" class="font-medium text-slate-600 underline underline-offset-4">Retour au site</RouterLink>
          </p>
        </div>
      </section>
    </div>
  </div>
</template>
