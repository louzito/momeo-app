<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useTenantContext } from '@/composables/useTenantContext'
import { useCheckoutGuard } from '@/composables/useCheckoutGuard'
import CheckoutLayout from '@/components/CheckoutLayout.vue'
import OptionSelector from '@/components/OptionSelector.vue'

const router = useRouter()
const { cart } = useCheckoutGuard()
const { options, tenant, slug } = useTenantContext()
const { selectedOptions } = storeToRefs(cart)

const selectedIds = computed(() => selectedOptions.value.map((o) => o.id))

// N'affiche que les options pertinentes pour le saut choisi : les PER_ORDER
// (toujours) et les PER_JUMP non ciblees (toutes) ou ciblant ce saut precis.
const visibleOptions = computed(() => {
  const jumpId = cart.jumpType?.id
  return options.value.filter(
    (o) =>
      o.scope !== 'PER_JUMP' ||
      !o.linkedJumpTypeIds?.length ||
      o.linkedJumpTypeIds.includes(jumpId),
  )
})

function next() {
  router.push({ name: 'checkout-mode', params: { slug: slug.value } })
}
</script>

<template>
  <CheckoutLayout
    v-if="cart.jumpType"
    step="options"
    title="Personnalisez votre prestation"
    subtitle="Ajoutez des options pour rendre l'experience inoubliable."
  >
    <OptionSelector
      :options="visibleOptions"
      :selected-ids="selectedIds"
      :currency="tenant?.currency || 'USD'"
      @toggle="cart.toggleOption"
    />

    <div class="mt-8 flex justify-end">
      <button class="btn-primary px-8" @click="next">Continuer</button>
    </div>
  </CheckoutLayout>
</template>
