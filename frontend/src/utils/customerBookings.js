export function splitCustomerBookings(bookings, now = Date.now()) {
  const timestamp = now instanceof Date ? now.getTime() : Number(now)
  const upcoming = []
  const past = []

  for (const booking of bookings || []) {
    const start = new Date(booking.slotStart).getTime()
    if (!Number.isFinite(start)) continue
    ;(start >= timestamp ? upcoming : past).push(booking)
  }

  upcoming.sort((a, b) => new Date(a.slotStart) - new Date(b.slotStart))
  past.sort((a, b) => new Date(b.slotStart) - new Date(a.slotStart))

  return { upcoming, past }
}
