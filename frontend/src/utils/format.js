// Helpers de formatage (devise, dates) — cote client uniquement.

const CURRENCY_LOCALE = {
  USD: 'en-US',
  CAD: 'fr-CA',
  CLP: 'es-CL',
  EUR: 'fr-FR',
}

export function formatMoney(amount, currency = 'USD') {
  const locale = CURRENCY_LOCALE[currency] || 'en-US'
  try {
    return new Intl.NumberFormat(locale, {
      style: 'currency',
      currency,
      maximumFractionDigits: currency === 'CLP' ? 0 : 2,
    }).format(amount)
  } catch {
    return `${amount} ${currency}`
  }
}

export function formatDate(input, opts = {}) {
  const d = input instanceof Date ? input : new Date(input)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleDateString('fr-FR', {
    weekday: opts.weekday ? 'long' : undefined,
    day: '2-digit',
    month: opts.short ? 'short' : 'long',
    year: 'numeric',
  })
}

export function formatTime(input) {
  const d = input instanceof Date ? input : new Date(input)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

export function daysBetween(from, to) {
  const a = new Date(from)
  const b = new Date(to)
  return Math.round((b - a) / (1000 * 60 * 60 * 24))
}

export function isoDay(date) {
  // Renvoie AAAA-MM-JJ en heure locale (utile pour grouper des creneaux par jour)
  const d = date instanceof Date ? date : new Date(date)
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}
