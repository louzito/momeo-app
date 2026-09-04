/**
 * Read-once migration for renamed localStorage identifiers.
 *
 * TodaTempo keys are the only write target. Legacy keys remain readable and
 * are removed after a successful copy, making the migration idempotent.
 */
export function migrateLocalStorageKey(canonicalKey, legacyKeys = []) {
  try {
    const current = localStorage.getItem(canonicalKey)
    if (current !== null) return current
    for (const legacyKey of legacyKeys) {
      const legacy = localStorage.getItem(legacyKey)
      if (legacy === null) continue
      localStorage.setItem(canonicalKey, legacy)
      localStorage.removeItem(legacyKey)
      return legacy
    }
  } catch {
    // Storage may be unavailable (privacy mode, SSR, quota). Callers already
    // treat persistence as optional.
  }
  return null
}
