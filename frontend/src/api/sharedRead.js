// Un seul appel réseau pour les consommateurs simultanés, sans cache persistant.
// Les erreurs sont également libérées pour permettre une nouvelle tentative.
export function shareInFlight(read) {
  let pending = null
  return (...args) => {
    if (!pending) {
      pending = Promise.resolve().then(() => read(...args)).finally(() => {
        pending = null
      })
    }
    return pending
  }
}
