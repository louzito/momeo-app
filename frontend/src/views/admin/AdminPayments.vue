<script setup>
// Moyens de paiement — PILOTES EN REEL depuis l'espace centre (API admin
// Sylius, jamais le panel Sylius) : activation, libelle affiche au client, et
// instructions de reglement (coordonnees bancaires du virement) montrees au
// client en fin de commande.
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useAdminStore } from '@/stores/admin'
import api from '@/api'
import { MEDIA_BASE, TENANT_SLUG } from '@/api/config'
import Spinner from '@/components/ui/Spinner.vue'

const admin = useAdminStore()
const methods = ref([])
const loading = ref(true)
const savingId = ref(null)
const savedId = ref(null)
const error = ref('')

const ICONS = { bank_transfer: '🏦', cash_on_delivery: '💵' }
function icon(code) {
  return ICONS[code] || '💳'
}
function subtitle(code) {
  if (code === 'bank_transfer') return 'Le client commande, paie par virement, vous confirmez a reception.'
  if (code === 'cash_on_delivery') return 'Règlement sur place, le jour du rendez-vous.'
  return 'Passerelle de paiement.'
}

// --- Passerelles (plugins Sylius Stripe / PayPal, installes cote back) ------
// Les cles sont stockees dans le gatewayConfig Sylius de la methode. L'URL de
// Le slug dans l'URL permet de sélectionner la bonne base tenant, car Stripe
// ne peut pas transmettre l'en-tête TodaTempo des appels du navigateur.
const GATEWAYS = {
  stripe_web_elements: {
    icon: '💳',
    title: 'Stripe — Carte bancaire',
    subtitle: 'Paiement hébergé Stripe Checkout (3-D Secure inclus).',
    fields: [
      { key: 'publishable_key', label: 'Cle publiable (pk_test_… / pk_live_…)', type: 'text' },
      { key: 'secret_key', label: 'Cle secrete ou restreinte (sk_… / rk_…)', type: 'password' },
      { key: 'webhook_secret_key', label: 'Secret de signature du webhook (whsec_…)', type: 'password' },
    ],
    required: ['publishable_key', 'secret_key', 'webhook_secret_key'],
  },
  sylius_paypal: {
    icon: '🅿️',
    title: 'PayPal',
    subtitle: 'Boutons PayPal au checkout (compte PayPal ou carte).',
    fields: [
      { key: 'client_id', label: 'Client ID (app REST PayPal)', type: 'text' },
      { key: 'client_secret', label: 'Client Secret', type: 'password' },
      { key: 'merchant_id', label: 'Merchant ID (compte marchand)', type: 'text' },
    ],
    required: ['client_id', 'client_secret'],
  },
}

const gatewayMethods = computed(() => methods.value.filter((m) => GATEWAYS[m.gateway]))
const offlineMethods = computed(() => methods.value.filter((m) => !GATEWAYS[m.gateway]))

const stripeWebhookUrl = computed(() => `${MEDIA_BASE}/api/v2/shop/payments/stripe/webhook/${TENANT_SLUG}`)

function missingKeys(pm) {
  const req = GATEWAYS[pm.gateway]?.required || []
  return req.filter((k) => !String(pm.config?.[k] || '').trim())
}

async function saveGateway(pm) {
  savingId.value = pm.id
  error.value = ''
  try {
    await api.updatePaymentMethod(admin.tenantId, pm.id, {
      config: pm.config,
      gatewayConfigId: pm.gatewayConfigId,
    })
    savedId.value = pm.id
    setTimeout(() => (savedId.value = null), 2000)
  } catch (e) {
    error.value = e?.message || 'Echec de l\'enregistrement des cles.'
  } finally {
    savingId.value = null
  }
}

async function toggleGateway(pm) {
  if (!pm.enabled && missingKeys(pm).length) {
    error.value = `Renseignez d'abord les cles requises (${missingKeys(pm).join(', ')}) puis enregistrez.`
    return
  }
  await toggle(pm)
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    methods.value = await api.getPaymentMethods(admin.tenantId)
  } catch (e) {
    error.value = e?.message || 'Impossible de charger les moyens de paiement.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function toggle(pm) {
  savingId.value = pm.id
  error.value = ''
  try {
    await api.updatePaymentMethod(admin.tenantId, pm.id, { enabled: !pm.enabled })
    pm.enabled = !pm.enabled
  } catch (e) {
    error.value = e?.message || 'Echec de la mise a jour.'
  } finally {
    savingId.value = null
  }
}

async function save(pm) {
  savingId.value = pm.id
  error.value = ''
  try {
    await api.updatePaymentMethod(admin.tenantId, pm.id, {
      label: pm.label,
      instructions: pm.instructions,
    })
    savedId.value = pm.id
    setTimeout(() => (savedId.value = null), 2000)
  } catch (e) {
    error.value = e?.message || 'Echec de l\'enregistrement.'
  } finally {
    savingId.value = null
  }
}
</script>

<template>
  <div class="mx-auto max-w-3xl">
    <h1 class="font-display text-2xl font-bold text-slate-900">Moyens de paiement</h1>
    <p class="mt-1 text-slate-500">
      Activez les moyens proposes a vos clients et renseignez vos coordonnees de reglement.
      Tout se gere ici — les changements sont appliques immediatement sur la boutique.
    </p>

    <div v-if="error" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
      ⚠️ {{ error }}
    </div>

    <p class="mt-3 rounded-xl border border-brand-100 bg-brand-50/60 p-3 text-sm text-slate-600">
      💡 Les virements en attente s'encaissent dans l'onglet
      <RouterLink :to="{ name: 'admin-orders' }" class="font-medium text-brand-600 underline">Commandes</RouterLink>
      (filtre « Virement en attente »).
    </p>

    <Spinner v-if="loading" />

    <template v-else>
      <!-- ============ Passerelles (Stripe / PayPal) ============ -->
      <h2 class="mt-8 font-display text-lg font-bold text-slate-900">Passerelles de paiement</h2>
      <p class="mt-1 text-sm text-slate-500">
        Renseignez vos cles, enregistrez, puis activez. Les modules serveur Stripe et PayPal
        sont deja installes — aucune manipulation hors de cet ecran.
      </p>

      <div class="mt-4 space-y-5">
        <div v-for="pm in gatewayMethods" :key="pm.id" class="card p-5">
          <div class="flex items-start justify-between gap-3">
            <div class="flex items-center gap-3">
              <span class="text-2xl">{{ GATEWAYS[pm.gateway].icon }}</span>
              <div>
                <p class="font-semibold text-slate-800">{{ GATEWAYS[pm.gateway].title }}</p>
                <p class="text-xs text-slate-400">{{ GATEWAYS[pm.gateway].subtitle }}</p>
              </div>
            </div>
            <button
              type="button"
              class="relative h-6 w-11 shrink-0 rounded-full transition"
              :class="pm.enabled ? 'bg-emerald-500' : 'bg-slate-300'"
              :disabled="savingId === pm.id"
              @click="toggleGateway(pm)"
            >
              <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white transition-all" :class="pm.enabled ? 'left-[22px]' : 'left-0.5'" />
            </button>
          </div>

          <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <div v-for="f in GATEWAYS[pm.gateway].fields" :key="f.key" :class="f.key === 'webhook_secret_key' ? 'sm:col-span-2' : ''">
              <label class="label">{{ f.label }}</label>
              <input v-model="pm.config[f.key]" :type="f.type" class="input font-mono text-xs" autocomplete="off" />
            </div>
          </div>

          <!-- Instructions webhook STRIPE -->
          <div v-if="pm.gateway === 'stripe_web_elements'" class="mt-4 rounded-xl border border-indigo-100 bg-indigo-50/60 p-4 text-sm text-slate-700">
            <p class="font-semibold text-slate-800">🔔 Configurer le webhook Stripe (obligatoire)</p>
            <ol class="mt-2 list-decimal space-y-1.5 pl-5">
              <li>
                Dans le dashboard Stripe : <strong>Developpeurs → Webhooks → « Ajouter un endpoint »</strong>.
              </li>
              <li>
                URL de l'endpoint :
                <code class="rounded bg-white px-1.5 py-0.5 font-mono text-xs text-indigo-700">{{ stripeWebhookUrl }}</code>
                <span class="block text-xs text-slate-500">
                  (URL de developpement — en production, remplacez <code class="font-mono">localhost:8080</code> par le domaine public de votre back.
                  Stripe exige une URL accessible depuis Internet en HTTPS.)
                </span>
              </li>
              <li>
                Evenements a selectionner : <code class="font-mono text-xs">checkout.session.completed</code>,
                <code class="font-mono text-xs">checkout.session.expired</code> et
                <code class="font-mono text-xs">checkout.session.async_payment_failed</code>.
              </li>
              <li>
                Copiez le <strong>secret de signature</strong> (<code class="font-mono text-xs">whsec_…</code>) affiche par Stripe
                dans le champ ci-dessus, puis <strong>Enregistrer</strong>.
              </li>
            </ol>
            <p class="mt-2 rounded-lg bg-white/70 p-2 font-mono text-[11px] text-slate-600">
              # Test en local (Stripe CLI) :<br />
              stripe listen --events checkout.session.completed,checkout.session.expired,checkout.session.async_payment_failed --forward-to localhost:8081/api/v2/shop/payments/stripe/webhook/{{ TENANT_SLUG }}
            </p>
          </div>

          <!-- Instructions webhook PAYPAL -->
          <div v-if="pm.gateway === 'sylius_paypal'" class="mt-4 rounded-xl border border-sky-100 bg-sky-50/60 p-4 text-sm text-slate-700">
            <p class="font-semibold text-slate-800">🔔 Webhook PayPal : rien a configurer a la main</p>
            <p class="mt-1.5">
              Le module PayPal enregistre lui-meme son webhook aupres de PayPal avec les identifiants
              ci-dessus. Creez simplement une app REST sur
              <strong>developer.paypal.com → Apps &amp; Credentials</strong> (Sandbox pour tester, Live en
              production), puis copiez le Client ID et le Client Secret ici.
            </p>
          </div>

          <div class="mt-3 flex items-center justify-between">
            <span class="text-xs" :class="pm.enabled ? 'text-emerald-600' : 'text-slate-400'">
              {{ pm.enabled ? '● Actif' : missingKeys(pm).length ? '○ Inactif — cles a renseigner' : '○ Inactif — pret a activer' }}
            </span>
            <div class="flex items-center gap-2">
              <span v-if="savedId === pm.id" class="text-xs text-emerald-600">✓ Cles enregistrees</span>
              <button class="btn-primary px-5 py-1.5 text-sm" :disabled="savingId === pm.id" @click="saveGateway(pm)">
                {{ savingId === pm.id ? 'Enregistrement…' : 'Enregistrer les cles' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ============ Moyens hors ligne ============ -->
      <h2 class="mt-10 font-display text-lg font-bold text-slate-900">Moyens hors ligne</h2>

    <div class="mt-4 space-y-4">
      <div v-for="pm in offlineMethods" :key="pm.id" class="card p-5">
        <div class="flex items-start justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="text-2xl">{{ icon(pm.code) }}</span>
            <div>
              <p class="font-semibold text-slate-800">{{ pm.label }}</p>
              <p class="text-xs text-slate-400">{{ subtitle(pm.code) }}</p>
            </div>
          </div>
          <!-- Toggle actif / inactif -->
          <button
            type="button"
            class="relative h-6 w-11 shrink-0 rounded-full transition"
            :class="pm.enabled ? 'bg-emerald-500' : 'bg-slate-300'"
            :disabled="savingId === pm.id"
            @click="toggle(pm)"
          >
            <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white transition-all" :class="pm.enabled ? 'left-[22px]' : 'left-0.5'" />
          </button>
        </div>

        <div class="mt-4 space-y-3">
          <div>
            <label class="label">Libelle affiche au client</label>
            <input v-model="pm.label" class="input" />
          </div>
          <div>
            <label class="label">
              Instructions de reglement
              <span class="font-normal text-slate-400">
                — affichees au client apres la commande{{ pm.code === 'bank_transfer' ? ' (IBAN, BIC, titulaire...)' : '' }}
              </span>
            </label>
            <textarea
              v-model="pm.instructions"
              rows="4"
              class="input font-mono text-xs"
              :placeholder="pm.code === 'bank_transfer'
                ? 'Titulaire : Mon Centre\nIBAN : FR76 ...\nBIC : ...\nIndiquez le numero de commande en reference.'
                : 'Ex. : règlement à l’accueil le jour du rendez-vous.'"
            />
          </div>
        </div>

        <div class="mt-3 flex items-center justify-between">
          <span class="text-xs" :class="pm.enabled ? 'text-emerald-600' : 'text-slate-400'">
            {{ pm.enabled ? '● Actif — propose aux clients' : '○ Inactif — masque du tunnel de commande' }}
          </span>
          <div class="flex items-center gap-2">
            <span v-if="savedId === pm.id" class="text-xs text-emerald-600">✓ Enregistre</span>
            <button class="btn-primary px-5 py-1.5 text-sm" :disabled="savingId === pm.id" @click="save(pm)">
              {{ savingId === pm.id ? 'Enregistrement…' : 'Enregistrer' }}
            </button>
          </div>
        </div>
      </div>

      <p class="rounded-xl bg-slate-100 p-4 text-xs text-slate-500">
        🔒 Aucune donnée de carte ne transite par TodaTempo : la saisie carte / PayPal se fait dans les
        composants securises de la passerelle. Les cles saisies ici sont stockees dans la configuration
        Sylius de la methode de paiement.
      </p>
    </div>
    </template>
  </div>
</template>
