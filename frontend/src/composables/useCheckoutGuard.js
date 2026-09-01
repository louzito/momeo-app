import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCartStore } from '@/stores/cart'

// Redirige vers la vitrine si le tunnel est aborde sans saut selectionne
// (ex. acces direct a une URL d'etape, ou rechargement de page).
export function useCheckoutGuard() {
  const cart = useCartStore()
  const route = useRoute()
  const router = useRouter()

  onMounted(() => {
    if (!cart.jumpType) {
      router.replace({ name: 'tenant-home', params: { slug: route.params.slug } })
    }
  })

  return { cart }
}
