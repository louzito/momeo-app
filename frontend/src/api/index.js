// =============================================================================
// POINT D'ENTREE UNIQUE DE L'API
// -----------------------------------------------------------------------------
// Tous les composants / stores importent l'API DEPUIS ICI :  import api from '@/api'
//
// V1 utilise exclusivement la vraie API Sylius. Les fixtures de tests ne sont
// jamais importees par ce graphe de modules et ne peuvent donc pas se retrouver
// dans un bundle de production.
// =============================================================================

import { httpApi } from './httpApi'
import { USE_REAL_API } from './config'

// V1 is deliberately fail-closed: production code must never silently serve
// fixture data when the tenant API is unavailable. Mocks belong to tests only.
if (!USE_REAL_API) throw new Error('The mock API is not available in the application runtime.')

const api = httpApi

if (typeof window !== 'undefined') {
  // eslint-disable-next-line no-console
  console.info('[TodaTempo] API source : Sylius (réelle)')
}

export default api
