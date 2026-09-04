<script setup>
// Configuration boutique — TOUT se pilote ici, Sylius-first, en 4 sous-menus :
//   Général            : identité (logo, nom), fonctionnalités (chèques cadeaux),
//                        couleurs, réseaux sociaux, coordonnées.
//   Page d'accueil     : bannières (PC + mobile) + titre / texte du hero.
//   Conditions générales & Mentions légales : pages activables, liées
//                        automatiquement dans le footer de la vitrine.
// Stockage : channel Sylius (nom/contacts) + taxon skybook_config (JSON public
// + images typées logo / banner / banner_mobile). La vitrine lit tout via le
// shop API.
import { ref, computed, onMounted } from 'vue'
import { useAdminStore } from '@/stores/admin'
import api from '@/api'
import { displayImageUrl } from '@/api/config'
import { SHOP_DEFAULT_COLORS } from '@/composables/useBranding'
import { SOCIAL_NETWORKS } from '@/utils/socialIcons'
import Spinner from '@/components/ui/Spinner.vue'

const admin = useAdminStore()
const loading = ref(true)
const saving = ref(false)
const saved = ref(false)
const error = ref('')

const cfg = ref(null)

const TABS = [
  { key: 'general', label: 'Général' },
  { key: 'home', label: "Page d'accueil" },
  { key: 'shop', label: 'Boutique' },
  { key: 'emails', label: 'Emails' },
  { key: 'terms', label: 'Conditions générales' },
  { key: 'mentions', label: 'Mentions légales' },
]
const tab = ref('general')

// Fichiers images en attente d'upload (uploades a l'enregistrement).
const files = ref({ logo: null, banner: null, banner_mobile: null })
const previews = ref({ logo: '', banner: '', banner_mobile: '' })

const IMAGE_RULES = {
  logo: { label: 'logo', maxMo: 4 },
  banner: { label: 'bannière ordinateur', maxMo: 6 },
  banner_mobile: { label: 'bannière mobile', maxMo: 6 },
}

const COLOR_FIELDS = [
  { key: 'header', label: 'Fond du header' },
  { key: 'textHeader', label: 'Texte du header' },
  { key: 'footer', label: 'Fond du footer' },
  { key: 'textFooter', label: 'Texte du footer' },
]

const EMAIL_TYPES = [
  {
    key: "booking_confirmation", label: "Confirmation de réservation",
    desc: "Envoyé dès qu'une réservation est enregistrée.",
    defaults: { subject: "Confirmation de votre réservation %reservation% — %centre%", intro: "Bonjour %prenom%,\n\nVotre réservation pour %prestation% est bien enregistrée le %date% à %heure%.", signature: "À bientôt,\nL'équipe %centre%" },
    vars: "%prenom% %nom% %reservation% %commande% %prestation% %date% %heure% %centre% %total%",
  },
  {
    key: "payment_confirmation", label: "Confirmation de paiement",
    desc: "Envoyé lorsque le paiement est confirmé.",
    defaults: { subject: "Confirmation de votre paiement — %centre%", intro: "Bonjour %prenom%,\n\nNous confirmons votre paiement de %total% pour la réservation %reservation%.", signature: "Merci de votre confiance,\nL'équipe %centre%" },
    vars: "%prenom% %nom% %reservation% %commande% %prestation% %date% %heure% %centre% %total%",
  },
  {
    key: "booking_cancelled", label: "Annulation",
    desc: "Envoyé lorsqu'une réservation est annulée.",
    defaults: { subject: "Annulation de votre réservation %reservation% — %centre%", intro: "Bonjour %prenom%,\n\nVotre réservation pour %prestation%, prévue le %date% à %heure%, a bien été annulée.", signature: "L'équipe %centre%" },
    vars: "%prenom% %nom% %reservation% %prestation% %date% %heure% %centre%",
  },
  {
    key: "booking_rescheduled", label: "Déplacement",
    desc: "Envoyé lorsqu'une réservation change de créneau.",
    defaults: { subject: "Nouvelle date pour votre réservation %reservation% — %centre%", intro: "Bonjour %prenom%,\n\nVotre réservation pour %prestation% a été déplacée au %date% à %heure%.", signature: "À bientôt,\nL'équipe %centre%" },
    vars: "%prenom% %nom% %reservation% %prestation% %date% %heure% %centre%",
  },
  {
    key: "order_confirmation",
    label: "Confirmation de commande",
    desc: "Envoyé au client dès que sa commande est enregistrée (avant paiement pour un virement).",
    defaults: {
      subject: "Votre commande %commande% — %centre%",
      intro: "Bonjour %prenom%,\n\nMerci pour votre réservation chez %centre% !\nVotre commande %commande% (%total%) a bien été enregistrée.",
      signature: "À bientôt,\nL'équipe %centre%",
    },
    vars: "%prenom% %nom% %commande% %centre% %total%",
  },
  {
    key: "invoice_generated",
    label: "Facture (PDF joint)",
    desc: "Envoyé quand le paiement est encaissé, avec la facture officielle en pièce jointe.",
    defaults: {
      subject: "Votre facture %facture% — %centre%",
      intro: "Bonjour %prenom%,\n\nVotre facture %facture% pour la commande %commande% est disponible en pièce jointe de cet email.",
      signature: "Merci de votre confiance,\nL'équipe %centre%",
    },
    vars: "%prenom% %nom% %commande% %centre% %total% %facture%",
  },
  {
    key: "gift_voucher", label: "Chèque cadeau",
    desc: "Envoyé au bénéficiaire et à l'acheteur après activation du cadeau.",
    defaults: { subject: "Votre chèque cadeau %prestation% — %centre%", intro: "Bonjour,\n\nVoici votre chèque cadeau %prestation% chez %centre%.", signature: "À bientôt,\nL'équipe %centre%" },
    vars: "%prenom_beneficiaire% %code% %prestation% %centre% %expire% %montant%",
  },
]

const jumps = ref([])
const MAX_FEATURED = 9
const MAX_HIGHLIGHTS = 6

async function load() {
  loading.value = true
  error.value = ''
  try {
    cfg.value = await api.getShopConfig()
    try { jumps.value = await api.getJumpTypes(admin.tenantId) } catch { jumps.value = [] }
  } catch (e) {
    error.value = e?.message || 'Impossible de charger la configuration.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

function onImagePicked(type, e) {
  const f = e.target.files?.[0]
  if (!f) return
  const rule = IMAGE_RULES[type]
  if (!f.type.startsWith('image/')) { error.value = 'Choisissez un fichier image.'; return }
  if (f.size > rule.maxMo * 1024 * 1024) { error.value = `Image trop lourde pour la ${rule.label} (${rule.maxMo} Mo max).`; return }
  error.value = ''
  files.value[type] = f
  if (previews.value[type]) URL.revokeObjectURL(previews.value[type])
  previews.value[type] = URL.createObjectURL(f)
}

function resetColors() {
  cfg.value.colors = { ...SHOP_DEFAULT_COLORS }
}

// --- Points forts (max 6) ---
function addHighlight() {
  if ((cfg.value.home.highlights || []).length < MAX_HIGHLIGHTS) cfg.value.home.highlights.push('')
}
function removeHighlight(i) {
  cfg.value.home.highlights.splice(i, 1)
}

// --- Produits mis en avant sur l'accueil (max 9, ordonnes) ---
const featuredJumps = computed(() => (cfg.value?.home?.featured || []).map((c) => jumps.value.find((j) => j.id === c)).filter(Boolean))
const availableJumps = computed(() => jumps.value.filter((j) => !(cfg.value?.home?.featured || []).includes(j.id)))
const featuredToAdd = ref('')
function addFeatured() {
  if (!featuredToAdd.value) return
  if (cfg.value.home.featured.length >= MAX_FEATURED) { error.value = `Maximum ${MAX_FEATURED} produits mis en avant.`; return }
  cfg.value.home.featured.push(featuredToAdd.value)
  featuredToAdd.value = ''
}
function removeFeatured(i) {
  cfg.value.home.featured.splice(i, 1)
}
function moveFeatured(i, dir) {
  const arr = cfg.value.home.featured
  const j = i + dir
  if (j < 0 || j >= arr.length) return
  ;[arr[i], arr[j]] = [arr[j], arr[i]]
}

// --- Ordre de la page Boutique ---
const orderedShopJumps = computed(() => {
  const order = cfg.value?.shopOrder || []
  const byCode = new Map(jumps.value.map((j) => [j.id, j]))
  const head = order.map((c) => byCode.get(c)).filter(Boolean)
  const rest = jumps.value.filter((j) => !order.includes(j.id))
  return [...head, ...rest]
})
function moveShop(i, dir) {
  const list = orderedShopJumps.value.map((j) => j.id)
  const j = i + dir
  if (j < 0 || j >= list.length) return
  ;[list[i], list[j]] = [list[j], list[i]]
  cfg.value.shopOrder = list
}

async function save() {
  if (!cfg.value.name?.trim()) { error.value = 'Le nom de la boutique est requis.'; tab.value = 'general'; return }
  try {
    new Intl.DateTimeFormat('fr-FR', { timeZone: cfg.value.timezone }).format()
  } catch {
    error.value = 'Le fuseau horaire est invalide (utilisez un identifiant IANA, par exemple Europe/Paris).'
    tab.value = 'general'
    return
  }
  saving.value = true
  error.value = ''
  try {
    await api.saveShopConfig(admin.tenantId, cfg.value)
    const urlKeys = { logo: 'logoUrl', banner: 'bannerUrl', banner_mobile: 'bannerMobileUrl' }
    for (const [type, file] of Object.entries(files.value)) {
      if (!file) continue
      const res = await api.uploadShopImage(admin.tenantId, file, type)
      cfg.value[urlKeys[type]] = displayImageUrl(res.path)
      files.value[type] = null
    }
    saved.value = true
    setTimeout(() => (saved.value = false), 2500)
  } catch (e) {
    error.value = e?.message || 'Echec de l\'enregistrement.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-3xl">
    <h1 class="font-display text-2xl font-bold text-slate-900">Configuration boutique</h1>
    <p class="mt-1 text-slate-500">
      Identité, page d'accueil, pages légales — appliqués immédiatement sur la boutique en ligne.
    </p>

    <!-- Sous-menus -->
    <div class="mt-5 flex flex-wrap gap-1 rounded-xl bg-slate-100 p-1">
      <button
        v-for="t in TABS"
        :key="t.key"
        type="button"
        class="rounded-lg px-4 py-2 text-sm font-medium transition"
        :class="tab === t.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
        @click="tab = t.key"
      >
        {{ t.label }}
      </button>
    </div>

    <div v-if="error" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">⚠️ {{ error }}</div>

    <Spinner v-if="loading" />

    <form v-else-if="cfg" class="mt-6 space-y-6" @submit.prevent="save">
      <!-- ======================= GENERAL ======================= -->
      <template v-if="tab === 'general'">
        <section class="card p-6">
          <h2 class="mb-4 font-semibold text-slate-800">Identité</h2>
          <div class="flex flex-wrap items-center gap-5">
            <img
              v-if="previews.logo || cfg.logoUrl"
              :src="previews.logo || cfg.logoUrl"
              alt="Logo"
              class="h-16 w-16 rounded-2xl border border-slate-200 object-cover"
            />
            <span v-else class="flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-400 to-accent-500 text-2xl">🪂</span>
            <div>
              <label class="btn-outline cursor-pointer px-4 py-2 text-sm">
                {{ cfg.logoUrl || files.logo ? 'Changer le logo…' : 'Ajouter un logo…' }}
                <input type="file" accept="image/*" class="hidden" @change="onImagePicked('logo', $event)" />
              </label>
              <p class="mt-1 text-xs text-slate-400">Carré conseillé (affiché 36×36 px), 4 Mo max.</p>
            </div>
          </div>
          <div class="mt-4">
            <label class="label">Nom de la boutique</label>
            <input v-model="cfg.name" class="input" placeholder="Institut TodaTempo" required />
          </div>
          <div class="mt-4">
            <label class="label">Fuseau horaire du centre</label>
            <input v-model="cfg.timezone" class="input" placeholder="Europe/Paris" required />
            <p class="mt-1 text-xs text-slate-400">Identifiant IANA, par exemple Europe/Paris ou America/Guadeloupe.</p>
          </div>
        </section>

        <section class="card p-6">
          <h2 class="mb-1 font-semibold text-slate-800">Fonctionnalités</h2>
          <p class="mb-4 text-sm text-slate-500">Activez ou non les briques optionnelles de votre boutique.</p>
          <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-4 transition hover:border-brand-300">
            <input v-model="cfg.giftVouchersEnabled" type="checkbox" class="mt-1 h-4 w-4 accent-brand-600" />
            <span>
              <span class="font-medium text-slate-800">Chèques cadeaux 🎁</span>
              <span class="mt-0.5 block text-sm text-slate-500">
                Vos clients peuvent offrir une prestation (activé par défaut). Si désactivé : le mode « En cadeau »
                disparaît du tunnel d'achat et les liens « Chèque cadeau » de la vitrine sont masqués.
                Les chèques déjà vendus restent activables par leurs bénéficiaires.
              </span>
            </span>
          </label>
        </section>

        <section class="card p-6">
          <div class="mb-4 flex items-center justify-between">
            <h2 class="font-semibold text-slate-800">Couleurs</h2>
            <button type="button" class="btn-ghost px-3 py-1 text-xs" @click="resetColors">↺ Couleurs par défaut</button>
          </div>
          <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="f in COLOR_FIELDS" :key="f.key">
              <label class="label">{{ f.label }}</label>
              <div class="flex items-center gap-2">
                <input v-model="cfg.colors[f.key]" type="color" class="h-10 w-14 cursor-pointer rounded-lg border border-slate-200" />
                <input v-model="cfg.colors[f.key]" class="input font-mono text-xs" />
              </div>
            </div>
          </div>
          <div class="mt-4 overflow-hidden rounded-xl border border-slate-200">
            <div class="flex items-center gap-2 px-4 py-2.5 text-sm font-semibold" :style="{ backgroundColor: cfg.colors.header, color: cfg.colors.textHeader }">
              <img v-if="previews.logo || cfg.logoUrl" :src="previews.logo || cfg.logoUrl" class="h-5 w-5 rounded object-cover" />
              <span v-else>🪂</span>
              {{ cfg.name || 'Mon établissement' }} <span class="ml-auto font-normal opacity-70">Prestations · Réserver</span>
            </div>
            <div class="bg-white px-4 py-3 text-xs text-slate-400">… contenu du site …</div>
            <div class="px-4 py-2.5 text-xs" :style="{ backgroundColor: cfg.colors.footer, color: cfg.colors.textFooter }">
              <span class="opacity-70">© {{ cfg.name || 'Ma boutique' }} — footer</span>
            </div>
          </div>
        </section>

        <section class="card p-6">
          <h2 class="mb-1 font-semibold text-slate-800">Réseaux sociaux</h2>
          <p class="mb-4 text-sm text-slate-500">Affichés dans le footer de la boutique (seuls les liens renseignés apparaissent).</p>
          <div class="grid gap-4 sm:grid-cols-2">
            <div v-for="s in SOCIAL_NETWORKS" :key="s.key">
              <label class="label flex items-center gap-1.5">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path :d="s.path" /></svg>
                {{ s.label }}
              </label>
              <input v-model="cfg.socials[s.key]" type="url" class="input" :placeholder="s.placeholder" />
            </div>
          </div>
        </section>

        <section class="card p-6">
          <h2 class="mb-4 font-semibold text-slate-800">Coordonnées du centre</h2>
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="label">Adresse</label>
              <input v-model="cfg.address.street" class="input" placeholder="2091 Goetz Rd" />
            </div>
            <div>
              <label class="label">Code postal</label>
              <input v-model="cfg.address.postcode" class="input" placeholder="92570" />
            </div>
            <div>
              <label class="label">Ville</label>
              <input v-model="cfg.address.city" class="input" placeholder="Perris" />
            </div>
            <div>
              <label class="label">Téléphone</label>
              <input v-model="cfg.contactPhone" type="tel" class="input" placeholder="+33 6 12 34 56 78" />
            </div>
            <div>
              <label class="label">Email de contact</label>
              <input v-model="cfg.contactEmail" type="email" class="input" placeholder="contact@moncentre.fr" />
            </div>
          </div>
        </section>
      </template>

      <!-- ======================= PAGE D'ACCUEIL ======================= -->
      <template v-else-if="tab === 'home'">
        <section class="card p-6">
          <h2 class="mb-1 font-semibold text-slate-800">Bannière (ordinateur)</h2>
          <p class="mb-4 text-sm text-slate-500">
            Grande image du haut de la page d'accueil. <strong>Taille recommandée : 1920 × 640 px</strong>
            (format paysage, JPG conseillé, 6 Mo max). Sans bannière, l'image par défaut est utilisée.
          </p>
          <div
            v-if="previews.banner || cfg.bannerUrl"
            class="mb-3 aspect-[3/1] overflow-hidden rounded-xl border border-slate-200 bg-slate-100"
          >
            <img :src="previews.banner || cfg.bannerUrl" alt="Bannière" class="h-full w-full object-cover" />
          </div>
          <label class="btn-outline cursor-pointer px-4 py-2 text-sm">
            {{ cfg.bannerUrl || files.banner ? 'Changer la bannière…' : 'Ajouter une bannière…' }}
            <input type="file" accept="image/*" class="hidden" @change="onImagePicked('banner', $event)" />
          </label>
        </section>

        <section class="card p-6">
          <h2 class="mb-1 font-semibold text-slate-800">Bannière mobile <span class="font-normal text-slate-400">(optionnelle)</span></h2>
          <p class="mb-4 text-sm text-slate-500">
            Variante affichée sur téléphone, plus verticale. <strong>Taille recommandée : 828 × 1100 px</strong>
            (6 Mo max). Sans variante, la bannière ordinateur est réutilisée.
          </p>
          <div
            v-if="previews.banner_mobile || cfg.bannerMobileUrl"
            class="mb-3 aspect-[3/4] w-40 overflow-hidden rounded-xl border border-slate-200 bg-slate-100"
          >
            <img :src="previews.banner_mobile || cfg.bannerMobileUrl" alt="Bannière mobile" class="h-full w-full object-cover" />
          </div>
          <label class="btn-outline cursor-pointer px-4 py-2 text-sm">
            {{ cfg.bannerMobileUrl || files.banner_mobile ? 'Changer la bannière mobile…' : 'Ajouter une bannière mobile…' }}
            <input type="file" accept="image/*" class="hidden" @change="onImagePicked('banner_mobile', $event)" />
          </label>
        </section>

        <section class="card p-6">
          <h2 class="mb-1 font-semibold text-slate-800">Points forts</h2>
          <p class="mb-4 text-sm text-slate-500">Les 3 a 6 arguments affiches sous la banniere (✓). Vides = defauts generiques.</p>
          <div class="space-y-2">
            <div v-for="(h, i) in cfg.home.highlights" :key="i" class="flex items-center gap-2">
              <span class="text-emerald-600">✓</span>
              <input v-model="cfg.home.highlights[i]" class="input" maxlength="60" placeholder="Ex. Produits naturels et gestes experts" />
              <button type="button" class="btn-ghost px-2 py-1 text-slate-400 hover:text-rose-600" @click="removeHighlight(i)">✕</button>
            </div>
          </div>
          <button v-if="cfg.home.highlights.length < MAX_HIGHLIGHTS" type="button" class="btn-outline mt-3 px-3 py-1.5 text-sm" @click="addHighlight">+ Ajouter un point fort</button>
        </section>

        <section class="card p-6">
          <h2 class="mb-1 font-semibold text-slate-800">Section produits de l'accueil</h2>
          <p class="mb-4 text-sm text-slate-500">Titre et phrase au-dessus des produits mis en avant.</p>
          <div class="space-y-4">
            <div>
              <label class="label">Titre de la section</label>
              <input v-model="cfg.home.catalogTitle" class="input" maxlength="70" placeholder="Nos meilleures experiences" />
            </div>
            <div>
              <label class="label">Phrase d'accroche</label>
              <input v-model="cfg.home.catalogText" class="input" maxlength="160" placeholder="Découvrez nos prestations et réservez votre rendez-vous en quelques clics." />
            </div>
          </div>

          <h3 class="mb-1 mt-6 font-medium text-slate-800">Produits mis en avant <span class="font-normal text-slate-400">({{ cfg.home.featured.length }}/{{ MAX_FEATURED }})</span></h3>
          <p class="mb-3 text-sm text-slate-500">Selection ordonnee affichee sur l'accueil. Vide = les 9 premiers de la Boutique.</p>
          <div v-if="featuredJumps.length" class="mb-3 space-y-2">
            <div v-for="(j, i) in featuredJumps" :key="j.id" class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2">
              <span class="w-6 text-center text-xs font-bold text-slate-400">{{ i + 1 }}</span>
              <span class="flex-1 truncate text-sm font-medium text-slate-700">{{ j.name }}</span>
              <button type="button" class="btn-ghost px-2 py-0.5" :disabled="i === 0" @click="moveFeatured(i, -1)">▲</button>
              <button type="button" class="btn-ghost px-2 py-0.5" :disabled="i === featuredJumps.length - 1" @click="moveFeatured(i, 1)">▼</button>
              <button type="button" class="btn-ghost px-2 py-0.5 text-slate-400 hover:text-rose-600" @click="removeFeatured(i)">✕</button>
            </div>
          </div>
          <div v-if="availableJumps.length && cfg.home.featured.length < MAX_FEATURED" class="flex items-center gap-2">
            <select v-model="featuredToAdd" class="input flex-1">
              <option value="" disabled>Choisir un produit a mettre en avant…</option>
              <option v-for="j in availableJumps" :key="j.id" :value="j.id">{{ j.name }}</option>
            </select>
            <button type="button" class="btn-outline px-4 py-2 text-sm" @click="addFeatured">Ajouter</button>
          </div>
        </section>

        <section class="card p-6">
          <h2 class="mb-1 font-semibold text-slate-800">Textes du haut de page</h2>
          <p class="mb-4 text-sm text-slate-500">Affichés par-dessus la bannière. Laissez vide pour garder les textes par défaut.</p>
          <div class="space-y-4">
            <div>
              <label class="label">Titre</label>
              <input v-model="cfg.home.title" class="input" placeholder="Prenez soin de vous, simplement" maxlength="90" />
            </div>
            <div>
              <label class="label">Texte sous le titre</label>
              <textarea v-model="cfg.home.subtitle" class="input min-h-24" rows="3" maxlength="300"
                placeholder="Une phrase ou deux pour donner envie : votre cadre, votre expérience, votre équipe…" />
            </div>
          </div>
        </section>
      </template>

      <!-- ======================= EMAILS ======================= -->
      <template v-else-if="tab === 'emails'">
        <section v-for="t in EMAIL_TYPES" :key="t.key" class="card p-6">
          <h2 class="mb-1 font-semibold text-slate-800">{{ t.label }}</h2>
          <p class="mb-4 text-sm text-slate-500">{{ t.desc }}</p>
          <div class="space-y-4">
            <div>
              <label class="label">Sujet de l'email</label>
              <input v-model="cfg.emails[t.key].subject" class="input" maxlength="120" :placeholder="t.defaults.subject" />
            </div>
            <div>
              <label class="label">Message d'introduction</label>
              <textarea v-model="cfg.emails[t.key].intro" class="input min-h-28" rows="4" :placeholder="t.defaults.intro" />
            </div>
            <div>
              <label class="label">Signature</label>
              <textarea v-model="cfg.emails[t.key].signature" class="input min-h-20" rows="2" :placeholder="t.defaults.signature" />
            </div>
          </div>
          <p class="mt-3 text-xs text-slate-400">
            Champs vides = textes par défaut (affichés en grisé). Variables disponibles :
            <code class="rounded bg-slate-100 px-1">{{ t.vars }}</code> — remplacées automatiquement a l'envoi.
          </p>
        </section>
        <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800">
          💡 Le rendu utilise la charte du centre (nom en en-tête). Testez en passant une commande :
          les emails partent sur MailHog en développement (localhost:8025).
        </div>
      </template>

      <!-- ======================= BOUTIQUE (tri) ======================= -->
      <template v-else-if="tab === 'shop'">
        <section class="card p-6">
          <h2 class="mb-1 font-semibold text-slate-800">Ordre des produits de la Boutique</h2>
          <p class="mb-4 text-sm text-slate-500">
            La page Boutique affiche TOUS vos produits dans cet ordre (l'accueil, lui,
            n'affiche que la selection « mise en avant »). Utilisez ▲ ▼ puis Enregistrer.
          </p>
          <div v-if="orderedShopJumps.length" class="space-y-2">
            <div v-for="(j, i) in orderedShopJumps" :key="j.id" class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2">
              <span class="w-6 text-center text-xs font-bold text-slate-400">{{ i + 1 }}</span>
              <span class="flex-1 truncate text-sm font-medium text-slate-700">{{ j.name }}</span>
              <span class="text-xs text-slate-400">{{ (j.basePrice ?? j.price) ? ((j.basePrice ?? j.price) + ' ' + (cfg.currency || '')) : '' }}</span>
              <button type="button" class="btn-ghost px-2 py-0.5" :disabled="i === 0" @click="moveShop(i, -1)">▲</button>
              <button type="button" class="btn-ghost px-2 py-0.5" :disabled="i === orderedShopJumps.length - 1" @click="moveShop(i, 1)">▼</button>
            </div>
          </div>
          <p v-else class="text-sm text-slate-400">Aucune prestation — créez-en une dans l’onglet « Prestations ».</p>
        </section>
      </template>

      <!-- ======================= CGV / MENTIONS ======================= -->
      <template v-else>
        <section class="card p-6">
          <h2 class="mb-1 font-semibold text-slate-800">{{ tab === 'terms' ? 'Conditions générales' : 'Mentions légales' }}</h2>
          <p class="mb-4 text-sm text-slate-500">
            Page publique de votre boutique. Une fois activée (et remplie), le lien apparaît
            automatiquement dans le footer de la vitrine.
          </p>
          <label class="mb-4 flex cursor-pointer items-center gap-3">
            <input v-model="cfg.legal[tab].enabled" type="checkbox" class="h-4 w-4 accent-brand-600" />
            <span class="font-medium text-slate-800">Activer la page {{ tab === 'terms' ? '« Conditions générales »' : '« Mentions légales »' }}</span>
          </label>
          <textarea
            v-model="cfg.legal[tab].content"
            class="input min-h-72 font-mono text-[13px] leading-relaxed"
            rows="16"
            :placeholder="tab === 'terms'
              ? 'Article 1 — Objet\nLes présentes conditions générales régissent…'
              : 'Éditeur du site\nRaison sociale, adresse, SIRET…\n\nHébergement\n…'"
          />
          <p class="mt-2 text-xs text-slate-400">Texte simple — les retours à la ligne sont conservés à l'affichage.</p>
        </section>
      </template>

      <div class="flex items-center justify-end gap-3">
        <span v-if="saved" class="text-sm text-emerald-600">✓ Configuration enregistrée</span>
        <button class="btn-primary px-8" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </div>
    </form>
  </div>
</template>
