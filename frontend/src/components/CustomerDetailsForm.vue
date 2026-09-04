<script setup>
// Coordonnées et consentements des prestations TodaTempo. Les anciennes
// prestations a contraintes de securite conservent leur formulaire dedie.
const model = defineModel({ type: Object, required: true })

defineProps({
  requirements: { type: Array, default: () => [] },
  bookingPolicy: { type: String, default: '' },
})
</script>

<template>
  <div class="space-y-6">
    <div class="grid gap-4 sm:grid-cols-2">
      <div>
        <label class="label">Prenom</label>
        <input v-model="model.firstName" type="text" class="input" placeholder="Prenom" autocomplete="given-name" required />
      </div>
      <div>
        <label class="label">Nom</label>
        <input v-model="model.lastName" type="text" class="input" placeholder="Nom" autocomplete="family-name" required />
      </div>
      <div>
        <label class="label">Email</label>
        <input v-model="model.email" type="email" class="input" placeholder="vous@example.com" autocomplete="email" required />
      </div>
      <div>
        <label class="label">Telephone <span class="font-normal text-slate-400">(facultatif)</span></label>
        <input v-model="model.phone" type="tel" class="input" placeholder="06 12 34 56 78" autocomplete="tel" />
      </div>
    </div>

    <label v-if="model.phone" class="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4">
      <input
        v-model="model.smsReminderConsent"
        type="checkbox"
        class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
      />
      <span class="text-sm text-slate-700">J'accepte de recevoir un rappel de ce rendez-vous par SMS.</span>
    </label>

    <div>
      <label class="label">Informations utiles <span class="font-normal text-slate-400">(facultatif)</span></label>
      <textarea
        v-model="model.notes"
        rows="3"
        class="input"
        placeholder="Une allergie, une sensibilite ou une information que le professionnel doit connaitre ?"
      />
    </div>

    <div v-if="requirements.length" class="space-y-3">
      <p class="text-sm font-semibold text-slate-800">Conditions propres a cette prestation</p>
      <label
        v-for="requirement in requirements"
        :key="requirement.key"
        class="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4"
      >
        <input
          v-model="model.customAnswers[requirement.key]"
          type="checkbox"
          class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
        />
        <span class="text-sm text-slate-700">{{ requirement.label }}</span>
      </label>
    </div>

    <div class="space-y-3">
      <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <input
          v-model="model.bookingTermsAccepted"
          type="checkbox"
          class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
        />
        <span class="text-sm text-slate-700">
          J'accepte les conditions de réservation et la politique d'annulation de l'établissement.
          <span v-if="bookingPolicy" class="mt-2 block whitespace-pre-line rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{{ bookingPolicy }}</span>
        </span>
      </label>
      <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <input
          v-model="model.privacyAccepted"
          type="checkbox"
          class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
        />
        <span class="text-sm text-slate-700">J'accepte que mes donnees soient utilisees pour organiser et suivre ce rendez-vous.</span>
      </label>
    </div>
  </div>
</template>
