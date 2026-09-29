<script setup>
import { RouterLink } from 'vue-router'
import { formatMoney } from '@/utils/format'
defineProps({ product: { type: Object, required: true }, currency: { type: String, default: 'EUR' }, featured: Boolean })
const available = (product) => product.stock > 0 && (product.pickupEnabled || product.deliveryEnabled)
</script>
<template>
        <article class="card flex flex-col overflow-hidden">
          <div class="relative h-44 bg-slate-100">
            <img v-if="product.image" :src="product.image" :alt="product.name" loading="lazy" class="h-full w-full object-cover" />
            <div v-else class="flex h-full items-center justify-center text-3xl text-slate-300" aria-hidden="true">✦</div>
            <span v-if="featured" class="absolute left-3 top-3 chip bg-accent-500 text-white">À la une</span>
          </div>
          <div class="flex flex-1 flex-col p-5">
            <h3 class="font-display text-lg font-bold text-slate-900">{{ product.name }}</h3>
            <p class="mt-1 flex-1 text-sm text-slate-500">{{ product.summary }}</p>
            <p class="mt-4 text-xl font-bold text-brand-700">{{ formatMoney(product.price, currency) }}</p>
            <p class="mt-2 text-sm" :class="available(product) ? 'text-slate-500' : 'text-rose-700'">
              {{ product.stock <= 0 ? 'Rupture de stock' : !available(product) ? 'Actuellement indisponible' : `${product.stock} en stock` }}
            </p>
            <p v-if="product.pickupEnabled" class="mt-1 text-sm text-slate-500">Retrait à l’établissement</p>
            <p v-if="product.deliveryEnabled" class="mt-1 text-sm text-slate-500">Livraison : {{ product.deliveryFee ? formatMoney(product.deliveryFee, currency) : 'offerte' }}</p>
            <RouterLink v-if="available(product)" :to="{ name: 'physical-products', query: { produit: product.id } }" class="btn-outline mt-4" :aria-label="`Choisir ${product.name}`">Choisir ce produit</RouterLink>
            <button v-else disabled class="btn-outline mt-4">Indisponible</button>
          </div>
        </article>
</template>
