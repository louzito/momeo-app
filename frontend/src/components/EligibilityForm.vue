<script setup>
// Formulaire d'informations d'eligibilite, reutilise a l'achat direct ET par le
// beneficiaire d'un cheque cadeau. Affiche les contraintes de la regle en clair.
const model = defineModel({ type: Object, required: true })

const props = defineProps({
  rule: { type: Object, required: true },
  showIdentity: { type: Boolean, default: true },
})
</script>

<template>
  <div class="space-y-6">
    <div v-if="showIdentity" class="grid gap-4 sm:grid-cols-3">
      <div>
        <label class="label">Prenom du sauteur</label>
        <input v-model="model.firstName" type="text" class="input" placeholder="Prenom" autocomplete="given-name" />
      </div>
      <div>
        <label class="label">Nom</label>
        <input v-model="model.lastName" type="text" class="input" placeholder="Nom" autocomplete="family-name" />
      </div>
      <div>
        <label class="label">Email</label>
        <input v-model="model.email" type="email" class="input" placeholder="vous@example.com" autocomplete="email" />
      </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
      <div>
        <label class="label">Age</label>
        <input v-model="model.age" type="number" min="0" class="input" placeholder="ans" />
        <p class="mt-1 text-xs text-slate-400">Requis : {{ rule.ageMin }}–{{ rule.ageMax }} ans</p>
      </div>
      <div>
        <label class="label">Poids (kg)</label>
        <input v-model="model.weightKg" type="number" min="0" class="input" placeholder="kg" />
        <p class="mt-1 text-xs text-slate-400">
          Max : {{ rule.weightMaxKg }} kg<template v-if="rule.bmiMax"> · IMC max : {{ rule.bmiMax }}</template>
        </p>
      </div>
      <div>
        <label class="label">Taille (cm)</label>
        <input v-model="model.heightCm" type="number" min="0" class="input" placeholder="cm" />
        <p class="mt-1 text-xs text-slate-400">Min : {{ rule.heightMinCm }} cm</p>
      </div>
    </div>

    <label
      v-if="rule.medicalCertificateRequired"
      class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4"
    >
      <input
        v-model="model.medicalCertificate"
        type="checkbox"
        class="mt-1 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
      />
      <span class="text-sm text-slate-700">
        <strong>Certificat medical requis.</strong> Je confirme disposer d'un certificat medical de
        non contre-indication (a presenter le jour J). <em>[upload simule]</em>
      </span>
    </label>

    <div v-if="rule.customRules?.length" class="space-y-2">
      <label
        v-for="cr in rule.customRules"
        :key="cr.key"
        class="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-3.5"
      >
        <input
          v-model="model.customAnswers[cr.key]"
          type="checkbox"
          class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
        />
        <span class="text-sm text-slate-700">{{ cr.label }}</span>
      </label>
    </div>

    <label
      v-if="rule.waiverRequired"
      class="flex items-start gap-3 rounded-xl border border-slate-300 bg-slate-50 p-4"
    >
      <input
        v-model="model.waiverAccepted"
        type="checkbox"
        class="mt-1 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
      />
      <span class="text-sm text-slate-700">
        J'ai lu et j'accepte la <a href="#" class="font-medium text-brand-600 underline">decharge de responsabilite</a>
        et je reconnais les risques inherents au saut en parachute. <em>(signature electronique simulee)</em>
      </span>
    </label>
  </div>
</template>
