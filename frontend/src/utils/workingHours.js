export const days = [
  ['monday', 'Lun'], ['tuesday', 'Mar'], ['wednesday', 'Mer'],
  ['thursday', 'Jeu'], ['friday', 'Ven'], ['saturday', 'Sam'], ['sunday', 'Dim'],
]

export function hydrateHours(value = {}) {
  if (Array.isArray(value)) return value.map(range => ({ ...range, days: [...range.days] }))
  const ranges = []
  for (const [day] of days) {
    const row = value?.[day]
    if (!row?.enabled) continue
    const start = row.start || '09:00'
    const end = row.end || '18:00'
    const existing = ranges.find(range => range.start === start && range.end === end)
    if (existing) existing.days.push(day)
    else ranges.push({ start, end, days: [day] })
  }
  return ranges
}

export function validateHours(ranges) {
  for (const [index, range] of ranges.entries()) {
    const label = `Créneau ${index + 1} : `
    if (![range.start, range.end].every(time => /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(time))) return label + 'renseignez des heures valides.'
    if (range.end <= range.start) return label + 'l’heure de fin doit être postérieure à l’heure de début.'
    if (!range.days.length) return label + 'sélectionnez au moins un jour.'
    if (ranges.slice(0, index).some(other => other.days.some(day => range.days.includes(day)) && range.start < other.end && range.end > other.start)) {
      return label + 'chevauchement avec un autre créneau sur un même jour.'
    }
  }
  return ''
}
