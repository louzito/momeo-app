<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import api from '@/api'
import { TENANT_SLUG } from '@/api/config'
import { fetchSiteCatalog, selectSiteOffers } from '@/api/siteCatalog'
import JumpTypeCard from '@/components/JumpTypeCard.vue'
import PhysicalProductCard from '@/components/PhysicalProductCard.vue'
import SiteMediaImage from './SiteMediaImage.vue'
const props = defineProps({ block: Object, media: Object, editor: Boolean })
const catalog = ref(null), gift = ref(null), loading = ref(true), error = ref(false)
let request = 0
async function load() {
  const current = ++request
  loading.value = true; error.value = false; catalog.value = null; gift.value = null
  try {
    const value = props.block.type === 'catalog' ? await fetchSiteCatalog(api, TENANT_SLUG) : await api.getGiftCardOffer()
    if (current !== request) return
    if (props.block.type === 'catalog') catalog.value = value
    else gift.value = value
  } catch { if (current === request) error.value = true }
  finally { if (current === request) loading.value = false }
}
const offers = computed(() => props.block.type === 'catalog' ? selectSiteOffers(props.block.props, catalog.value) : [])
const visible = computed(() => !loading.value && !error.value && (props.block.type === 'catalog' ? offers.value.length > 0 : gift.value?.enabled === true))
const shopLink = computed(() => ({ name: 'shop', query: props.block.props.mode === 'category' ? { categorie: props.block.props.category } : {} }))
watch(() => [props.block.id, props.block.type], load, { immediate: true })
onBeforeUnmount(() => { request++ })
</script>
<template>
  <section v-if="editor || visible" class="rounded-xl p-5" :class="[block.variant === 'color' ? 'bg-brand-50 text-brand-900' : 'bg-white text-slate-900', block.align === 'center' ? 'text-center' : 'text-left']" :aria-busy="loading">
    <template v-if="editor && !visible">
      <p v-if="loading" role="status">Chargement des offres…</p>
      <p v-else-if="error" role="alert">Impossible de charger les offres. <button type="button" class="underline" @click="load">Réessayer</button></p>
      <p v-else>{{ block.type === 'catalog' ? 'Aucune offre disponible dans cette sélection.' : 'La vente de cartes cadeaux est désactivée.' }} Cette section sera masquée sur le site.</p>
    </template>
    <template v-if="visible">
      <SiteMediaImage v-if="block.type === 'giftCard' && media?.[block.props.image?.mediaId]" :media="media[block.props.image.mediaId]" :alt="block.props.image.alt" />
      <h2 v-if="block.props.title" class="mb-4 text-2xl font-semibold">{{ block.props.title }}</h2>
      <template v-if="block.type === 'catalog'">
        <div class="site-catalog grid gap-5">
          <template v-for="offer in offers" :key="offer.value.id">
            <JumpTypeCard v-if="offer.kind === 'service'" :jump-type="offer.value" :options="catalog.options" :currency="catalog.tenant.currency" :slug="TENANT_SLUG" />
            <PhysicalProductCard v-else :product="offer.value" :currency="catalog.tenant.currency" />
          </template>
        </div>
        <RouterLink v-if="block.props.buttonLabel" :to="shopLink" class="btn-primary mt-4">{{ block.props.buttonLabel }}</RouterLink>
      </template>
      <template v-else>
        <p class="whitespace-pre-line">{{ block.props.text }}</p>
        <RouterLink v-if="gift.paymentMethods?.length" :to="{ name: 'gift-card-purchase' }" class="btn-primary mt-4">{{ block.props.buttonLabel }}</RouterLink>
        <p v-else class="mt-3">L’achat est momentanément indisponible. Contactez l’établissement.</p>
      </template>
    </template>
  </section>
</template>
<style scoped>
@container site-page (min-width: 560px) { .site-catalog { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@container site-page (min-width: 900px) { .site-catalog { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
</style>
