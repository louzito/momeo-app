<script setup>
const props = defineProps({ modelValue: { type: Object, required: true }, pages: { type: Array, default: () => [] } })
const emit = defineEmits(['update:modelValue'])
const routes = { services: 'Prestations', store: 'Boutique', 'gift-card': 'Carte cadeau', booking: 'Choisir une prestation pour réserver' }
function typeChanged(type) {
  emit('update:modelValue', { type, target: type === 'route' ? 'services' : '' })
}
function targetChanged(target) { emit('update:modelValue', { ...props.modelValue, target }) }
</script>
<template>
  <div class="grid gap-3 sm:grid-cols-2">
    <label class="block">Destination
      <select class="input mt-1" :value="modelValue.type" @change="typeChanged($event.target.value)">
        <option value="page">Page du site</option><option value="route">Prestation, boutique ou carte cadeau</option><option value="external">Lien externe</option>
      </select>
    </label>
    <label v-if="modelValue.type === 'page'" class="block">Page
      <select class="input mt-1" required :value="modelValue.target" @change="targetChanged($event.target.value)">
        <option disabled value="">Choisir une page</option>
        <option v-if="modelValue.target && !pages.some(p => p.id === modelValue.target)" :value="modelValue.target" disabled>Page indisponible — remplacez ce lien</option>
        <option v-for="page in pages" :key="page.id" :value="page.id" :disabled="page.archived">{{ page.draft.title }}{{ page.archived ? ' (archivée)' : !page.published ? ' (à publier)' : '' }}</option>
      </select>
    </label>
    <label v-else-if="modelValue.type === 'route'" class="block">Écran
      <select class="input mt-1" :value="modelValue.target" @change="targetChanged($event.target.value)">
        <option v-if="!routes[modelValue.target]" :value="modelValue.target">Destination existante</option>
        <option v-for="(label, key) in routes" :key="key" :value="key">{{ label }}</option>
      </select>
    </label>
    <label v-else class="block">Adresse du lien
      <input class="input mt-1" type="url" required maxlength="2048" placeholder="https://exemple.fr" :value="modelValue.target" @input="targetChanged($event.target.value)" />
    </label>
  </div>
</template>
