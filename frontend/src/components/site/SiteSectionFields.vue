<script setup>
import SiteCatalogFields from './SiteCatalogFields.vue'
import SiteRichTextEditor from './SiteRichTextEditor.vue'
import SiteMediaPicker from './SiteMediaPicker.vue'
import SiteLinkEditor from './SiteLinkEditor.vue'
const props = defineProps({ block: Object, pages: Array, disabled: Boolean })
const emit = defineEmits(['media'])
const blank = () => ({ type: 'doc', content: [{ type: 'paragraph' }] })
function image(value) { emit('media', value); return { mediaId: value.id, alt: value.alt || '' } }
function choose(value, index) {
  const ref = image(value)
  if (props.block.type === 'gallery') { if (index == null) props.block.props.images.push(ref); else props.block.props.images[index] = ref }
  else if (['imageText', 'giftCard'].includes(props.block.type)) props.block.props.image = ref
  else Object.assign(props.block.props, ref)
}
</script>
<template>
  <fieldset :disabled="disabled" class="min-w-0 space-y-4">
    <legend class="mb-3 font-semibold">Paramètres de la section</legend>
    <SiteCatalogFields v-if="block.type === 'catalog'" :block="block" />
    <label v-if="['catalog', 'giftCard'].includes(block.type)" class="block">Texte du bouton <input v-model="block.props.buttonLabel" class="input" maxlength="100" :required="block.type === 'giftCard'" /></label>
    <label class="block">Fond <select v-model="block.variant" class="input"><option value="light">Clair</option><option value="color">Couleur du thème</option></select></label>
    <label class="block">Alignement <select v-model="block.align" class="input"><option value="left">À gauche</option><option value="center">Centré</option></select></label>
    <label v-if="'title' in block.props" class="block">Titre <input v-model="block.props.title" class="input" maxlength="200" /></label>
    <label v-if="['banner', 'giftCard'].includes(block.type)" class="block">Texte <textarea v-model="block.props.text" class="input" maxlength="2000" /></label>
    <template v-if="['banner', 'image', 'imageText', 'giftCard'].includes(block.type)">
      <button v-if="block.type === 'giftCard' && block.props.image" type="button" class="btn-ghost" @click="block.props.image = null">Retirer l’image</button>
      <p class="font-medium">Image</p><SiteMediaPicker :disabled="disabled" @select="choose($event)" />
      <label v-if="!['imageText', 'giftCard'].includes(block.type)" class="block">Texte alternatif <input v-model="block.props.alt" class="input" maxlength="300" /></label>
      <label v-else-if="block.props.image" class="block">Texte alternatif <input v-model="block.props.image.alt" class="input" maxlength="300" /></label>
      <label v-if="block.type === 'imageText'" class="block">Position de l’image <select v-model="block.props.position" class="input"><option value="left">À gauche</option><option value="right">À droite</option></select></label>
    </template>
    <template v-if="block.type === 'banner'">
      <button type="button" class="btn-outline" @click="block.props.button = block.props.button ? null : { label: 'Découvrir', link: { type: 'route', target: 'shop' } }">{{ block.props.button ? 'Retirer le bouton' : 'Ajouter un bouton' }}</button>
      <template v-if="block.props.button"><label class="block">Texte du bouton <input v-model="block.props.button.label" class="input" maxlength="100" required /></label><SiteLinkEditor v-model="block.props.button.link" :pages="pages" /></template>
    </template>
    <SiteRichTextEditor v-if="['text', 'imageText'].includes(block.type)" :key="block.id" v-model="block.props.content" :disabled="disabled" />
    <template v-if="block.type === 'gallery'">
      <div v-for="(entry, index) in block.props.images" :key="index" class="space-y-2 rounded border p-3">
        <p>Image {{ index + 1 }}</p><label class="block">Texte alternatif <input v-model="entry.alt" class="input" maxlength="300" /></label>
        <SiteMediaPicker :disabled="disabled" @select="choose($event, index)" /><button type="button" class="btn-ghost" @click="block.props.images.splice(index, 1)">Retirer l’image</button>
      </div>
      <div v-if="block.props.images.length < 20"><p>Ajouter une image</p><SiteMediaPicker :disabled="disabled" @select="choose($event)" /></div>
    </template>
    <template v-if="block.type === 'faq'">
      <div v-for="(item, index) in block.props.items" :key="block.id + '-' + index" class="space-y-2 rounded border p-3">
        <label class="block">Question {{ index + 1 }} <input v-model="item.question" class="input" maxlength="300" required /></label><SiteRichTextEditor v-model="item.answer" :disabled="disabled" />
        <button type="button" class="btn-ghost" @click="block.props.items.splice(index, 1)">Retirer la question</button>
      </div>
      <button v-if="block.props.items.length < 30" type="button" class="btn-outline" @click="block.props.items.push({ question: 'Votre question', answer: blank() })">Ajouter une question</button>
    </template>
    <template v-if="block.type === 'practical'">
      <label class="block">Adresse <textarea v-model="block.props.address" class="input" maxlength="1000" /></label>
      <label class="block">Téléphone <input v-model="block.props.phone" class="input" maxlength="40" /></label>
      <label class="block">E-mail <input v-model="block.props.email" class="input" type="email" maxlength="254" /></label>
      <label class="block">Horaires <textarea v-model="block.props.hours" class="input" maxlength="2000" /></label>
      <button type="button" class="btn-outline" @click="block.props.link = block.props.link ? null : { type: 'external', target: '' }">{{ block.props.link ? 'Retirer le lien d’accès' : 'Ajouter un lien d’accès' }}</button><SiteLinkEditor v-if="block.props.link" v-model="block.props.link" :pages="pages" />
    </template>
    <template v-if="block.type === 'heading'"><label>Titre <input v-model="block.props.text" class="input" maxlength="200" /></label><label>Niveau <select v-model="block.props.level" class="input"><option :value="2">Titre 2</option><option :value="3">Titre 3</option></select></label></template>
    <template v-if="block.type === 'button'"><label>Texte du bouton <input v-model="block.props.label" class="input" maxlength="100" /></label><SiteLinkEditor v-model="block.props.link" :pages="pages" /></template>
  </fieldset>
</template>
