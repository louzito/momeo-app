export const weekDays = [
  ['monday', 'Lun', 'Lundi'], ['tuesday', 'Mar', 'Mardi'],
  ['wednesday', 'Mer', 'Mercredi'], ['thursday', 'Jeu', 'Jeudi'],
  ['friday', 'Ven', 'Vendredi'], ['saturday', 'Sam', 'Samedi'], ['sunday', 'Dim', 'Dimanche'],
]

export function slotsFromHours(hours = {}) {
  const slots = []
  for (const [day] of weekDays) {
    const row = hours?.[day]
    const ranges = Array.isArray(row) ? row : row?.enabled ? [row] : []
    for (const { start, end } of ranges) {
      let slot = slots.find((item) => item.start === start && item.end === end && !item.days.includes(day))
      if (!slot) slots.push(slot = { start, end, days: [] })
      slot.days.push(day)
    }
  }
  return slots
}

export function hoursFromSlots(slots) {
  return Object.fromEntries(weekDays.map(([day]) => [day, slots
    .filter((slot) => slot.days.includes(day))
    .map(({ start, end }) => ({ start, end }))
    .sort((a, b) => a.start.localeCompare(b.start))]))
}

export function validateSlots(slots) {
  const time = /^([01]\d|2[0-3]):[0-5]\d$/
  return slots.map((slot, index) => {
    if (!time.test(slot.start) || !time.test(slot.end)) return 'Renseignez une heure de début et de fin valides.'
    if (slot.end <= slot.start) return 'L’heure de fin doit être postérieure à l’heure de début.'
    if (!slot.days.length) return 'Sélectionnez au moins un jour pour ce créneau.'
    const overlap = slots.findIndex((other, i) => i !== index && other.start < slot.end && slot.start < other.end && other.days.some((day) => slot.days.includes(day)))
    if (overlap !== -1) {
      const shared = weekDays.filter(([day]) => slot.days.includes(day) && slots[overlap].days.includes(day)).map(([, label]) => label).join(', ')
      return `Chevauchement avec le créneau ${overlap + 1} (${shared}). Modifiez les horaires ou les jours.`
    }
    return ''
  })
}
