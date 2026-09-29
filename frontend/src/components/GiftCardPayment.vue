<script setup>
import { computed, ref, watch } from 'vue'
import api from '@/api'
import { formatMoney } from '@/utils/format'
const props = defineProps({ due: { type: Number, required: true }, later: { type: Number, default: 0 }, eligible: { type: Number, default: null }, disabled: Boolean })
const emit = defineEmits(['change'])
const code = ref('')
const available = ref(null)
const loading = ref(false)
const error = ref('')
const applied = computed(() => Math.min(props.eligible ?? props.due, available.value || 0))
function notify() { emit('change', { code: available.value === null ? '' : code.value, amount: applied.value, remaining: props.due - applied.value }) }
watch(() => [props.due, props.eligible], notify)
function clear() { available.value = null; error.value = ''; notify() }
async function check() {
  loading.value = true; error.value = ''; available.value = null; notify()
  try { available.value = (await api.getGiftCardBalance(code.value)).available; notify() }
  catch (e) { error.value = e?.message || 'Impossible de vérifier cette carte cadeau.' }
  finally { loading.value = false }
}
</script>
<template>
  <fieldset class="my-5 rounded-xl border border-slate-200 p-4" :disabled="disabled || loading">
    <legend class="px-1 font-semibold">Utiliser une carte cadeau</legend>
    <label class="text-sm" for="gift-payment-code">Code de la carte</label>
    <div class="mt-2 flex gap-2">
      <input id="gift-payment-code" v-model.trim="code" class="input min-w-0 flex-1" autocomplete="off" @input="clear" />
      <button type="button" class="btn-outline" :disabled="!code || due <= 0" @click="check">{{ loading ? 'Vérification…' : 'Appliquer' }}</button>
    </div>
    <p v-if="error" role="alert" class="mt-2 text-sm text-rose-700">{{ error }}</p>
    <div v-if="available !== null" aria-live="polite" class="mt-3 space-y-1 text-sm">
      <p>Crédit cadeau : <strong>{{ formatMoney(applied / 100, 'EUR') }}</strong></p>
      <p>Reste à payer maintenant : {{ formatMoney((due - applied) / 100, 'EUR') }}</p>
      <p>Solde de la carte après cet achat : {{ formatMoney((available - applied) / 100, 'EUR') }}</p>
      <p v-if="later">Solde de la prestation à régler ultérieurement : {{ formatMoney(later / 100, 'EUR') }}. La carte s’applique uniquement au montant dû maintenant.</p>
      <p v-if="due > applied">Le complément sera réglé par carte bancaire.</p>
      <button type="button" class="underline" @click="code = ''; clear()">Retirer la carte</button>
    </div>
  </fieldset>
</template>
