// =============================================================================
// POINT D'ENTREE UNIQUE DE L'API
// -----------------------------------------------------------------------------
// Tous les composants / stores importent l'API DEPUIS ICI :  import api from '@/api'
//
// La source est choisie dans src/api/config.js (USE_REAL_API) :
//   - false -> fausse API mockee (src/mocks/mockApi.js)
//   - true  -> vraie API Sylius (src/api/httpApi.js), via le proxy Vite
//
// httpApi remplace le mock methode par methode (pattern "strangler") : tant qu'une
// methode n'est pas encore branchee sur le vrai back, elle retombe sur le mock.
// =============================================================================

import { mockApi } from '@/mocks/mockApi'
import { httpApi } from './httpApi'
import { USE_REAL_API } from './config'

const api = USE_REAL_API ? httpApi : mockApi

if (typeof window !== 'undefined') {
  // eslint-disable-next-line no-console
  console.info(`[Momeo] API source : ${USE_REAL_API ? 'Sylius (réelle)' : 'mock'}`)
}

export default api
