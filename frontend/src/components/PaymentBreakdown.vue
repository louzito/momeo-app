<script setup>
import { formatMoney } from '@/utils/format'
defineProps({ value: Object, currency: { type: String, default: 'EUR' } })
</script>
<template>
  <dl v-if="value?.giftCard" class="my-3 space-y-1 text-sm">
    <div class="flex justify-between gap-4"><dt>Carte cadeau</dt><dd>{{ formatMoney(value.giftCard / 100, currency) }}</dd></div>
    <div class="flex justify-between gap-4"><dt>Part bancaire</dt><dd>{{ formatMoney((value.dueNow - value.giftCard) / 100, currency) }}</dd></div>
    <div v-if="value.dueLater" class="flex justify-between gap-4"><dt>À régler ultérieurement</dt><dd>{{ formatMoney(value.dueLater / 100, currency) }}</dd></div>
    <div v-if="value.giftCardRefunded" class="flex justify-between gap-4"><dt>Crédit cadeau restitué</dt><dd>{{ formatMoney(value.giftCardRefunded / 100, currency) }}</dd></div>
  </dl>
</template>
