// Generateur de creneaux (slots) factices. Les creneaux sont calcules par
// rapport a la date du jour afin que le calendrier affiche toujours des
// disponibilites futures. Le remplissage est deterministe (stable par session).
//
// La generation depend desormais d'un `schedule` (jours d'ouverture, plages
// horaires, capacite) editable par l'admin du centre. Le catalogue et le
// schedule sont passes en parametres (plus d'import de fixtures statiques ici).

function seeded(n) {
  let t = (n + 0x6d2b79f5) >>> 0
  t = Math.imul(t ^ (t >>> 15), t | 1)
  t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296
}

function hashString(str) {
  let h = 0
  for (let i = 0; i < str.length; i++) h = (Math.imul(31, h) + str.charCodeAt(i)) | 0
  return h >>> 0
}

const INSTRUCTORS = ['Alex Rivera', 'Marie Tremblay', 'Diego Rojas', 'Sam Carter', 'Lea Fontaine']

// schedule = { openDays: number[] (0=dim..6=sam), times: string[] "HH:MM", capacity: number }
export function generateSlots(tenantId, { jumpTypes = [], schedule, daysAhead = 21 } = {}) {
  const sched = schedule || { openDays: [0, 1, 3, 4, 5, 6], times: ['09:00', '11:30', '14:00', '16:30'], capacity: 8 }
  const jumpTypeIds = jumpTypes.map((j) => j.id)
  // Les prestations longues ne sont proposées que le matin.
  const affLikeIds = jumpTypes
    .filter((j) => /aff|pac/i.test(j.name) || j.durationMin >= 200)
    .map((j) => j.id)

  const slots = []
  const today = new Date()
  today.setHours(0, 0, 0, 0)

  for (let d = 0; d < daysAhead; d++) {
    const day = new Date(today)
    day.setDate(today.getDate() + d)
    if (!sched.openDays.includes(day.getDay())) continue

    sched.times.forEach((time, ti) => {
      const [hh, mm] = time.split(':').map(Number)
      const start = new Date(day)
      start.setHours(hh, mm, 0, 0)
      if (start.getTime() < Date.now()) return

      const end = new Date(start)
      end.setMinutes(end.getMinutes() + 90)

      const seed = hashString(tenantId) ^ (d * 17 + ti * 7)
      const r = seeded(seed)
      const capacity = sched.capacity
      let booked
      if (r < 0.18) booked = capacity
      else if (r < 0.4) booked = capacity - 1
      else booked = Math.floor(r * (capacity - 2))

      const isMorning = hh < 12
      const compatibleJumpTypeIds = isMorning
        ? jumpTypeIds
        : jumpTypeIds.filter((id) => !affLikeIds.includes(id))

      slots.push({
        id: `slot_${tenantId}_${d}_${ti}`,
        tenantId,
        start: start.toISOString(),
        end: end.toISOString(),
        capacity,
        booked: Math.max(0, Math.min(capacity, booked)),
        remaining: Math.max(0, capacity - booked),
        compatibleJumpTypeIds,
        instructor: INSTRUCTORS[(d + ti) % INSTRUCTORS.length],
      })
    })
  }

  return slots
}
