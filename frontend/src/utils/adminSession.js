// Full navigation also discards the in-memory back-office session.
export function redirectExpiredAdminSession({ storage, location, tenant, loginUrl }) {
  for (const key of [
    `todatempo.sylius.jwt.${tenant}`, `momeo.sylius.jwt.${tenant}`,
    `todatempo.admin.${tenant}`, `momeo.admin.${tenant}`,
  ]) {
    try { storage.removeItem(key) } catch { /* Storage may be unavailable. */ }
  }
  location.replace(loginUrl)
}
