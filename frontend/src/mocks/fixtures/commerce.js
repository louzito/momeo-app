// Commandes, réservations et chèques cadeaux de démonstration Momeo.

function daysFromNow(n, hh = 10, mm = 0) {
  const date = new Date()
  date.setDate(date.getDate() + n)
  date.setHours(hh, mm, 0, 0)
  return date.toISOString()
}

function monthsFromNow(n) {
  const date = new Date()
  date.setMonth(date.getMonth() + n)
  return date.toISOString()
}

export const vouchers = [
  {
    code: 'MOM-GIFT-2026', tenantId: 'dz_skyline', jumpTypeId: 'service_soin_eclat',
    amount: 75, currency: 'EUR', status: 'issued', beneficiaryEmail: 'emma@example.com',
    beneficiaryName: 'Emma Wilson', purchaserName: 'Lou Martin',
    personalMessage: 'Joyeux anniversaire Emma, profite bien de cette parenthèse !',
    issuedAt: monthsFromNow(-2), expiresAt: monthsFromNow(10), bookingId: null,
  },
  {
    code: 'MOM-USED-8842', tenantId: 'dz_skyline', jumpTypeId: 'service_massage_relaxant',
    amount: 90, currency: 'EUR', status: 'used', beneficiaryEmail: 'emma@example.com',
    beneficiaryName: 'Emma Wilson', purchaserName: 'David Wilson',
    personalMessage: 'Félicitations pour ton diplôme !', issuedAt: monthsFromNow(-8),
    expiresAt: monthsFromNow(4), bookingId: 'bk_used_emma',
  },
  {
    code: 'NOVA-GIFT-3321', tenantId: 'dz_chutelibre', jumpTypeId: 'service_coupe_signature',
    amount: 58, currency: 'EUR', status: 'reserved', beneficiaryEmail: 'luc@example.com',
    beneficiaryName: 'Luc Gagnon', purchaserName: 'Sophie Gagnon',
    personalMessage: 'Une pause rien que pour toi.', issuedAt: monthsFromNow(-1),
    expiresAt: monthsFromNow(11), bookingId: 'bk_resv_luc',
  },
]

export const bookings = [
  {
    id: 'bk_lou_confirmed', tenantId: 'dz_skyline', reference: 'RDV-10231', source: 'direct',
    orderId: 'ord_lou_direct', customerId: 'cust_lou', jumpTypeId: 'service_soin_eclat',
    jumperName: 'Lou Martin', slotStart: daysFromNow(6, 11, 30), slotEnd: daysFromNow(6, 12, 30),
    status: 'confirmed', options: [{ name: 'Masque premium', price: 15 }],
    boardingPassId: 'bp_lou_confirmed',
  },
  {
    id: 'bk_lou_completed', tenantId: 'dz_skyline', reference: 'RDV-09880', source: 'direct',
    orderId: 'ord_lou_past', customerId: 'cust_lou', jumpTypeId: 'service_soin_eclat',
    jumperName: 'Lou Martin', slotStart: daysFromNow(-40, 9), slotEnd: daysFromNow(-40, 10),
    status: 'completed', options: [], boardingPassId: 'bp_lou_completed',
  },
  {
    id: 'bk_used_emma', tenantId: 'dz_skyline', reference: 'RDV-09512', source: 'voucher',
    voucherCode: 'MOM-USED-8842', customerId: null, jumpTypeId: 'service_massage_relaxant',
    jumperName: 'Emma Wilson', slotStart: daysFromNow(-25, 14), slotEnd: daysFromNow(-25, 15),
    status: 'completed', options: [], boardingPassId: 'bp_used_emma',
  },
  {
    id: 'bk_resv_luc', tenantId: 'dz_chutelibre', reference: 'RDV-04420', source: 'voucher',
    voucherCode: 'NOVA-GIFT-3321', customerId: null, jumpTypeId: 'service_coupe_signature',
    jumperName: 'Luc Gagnon', slotStart: daysFromNow(9, 9), slotEnd: daysFromNow(9, 10),
    status: 'confirmed', options: [{ name: 'Soin profond', price: 22 }],
    boardingPassId: 'bp_resv_luc',
  },
  {
    id: 'bk_marie_confirmed', tenantId: 'dz_chutelibre', reference: 'RDV-04510', source: 'direct',
    orderId: 'ord_marie_direct', customerId: 'cust_marie', jumpTypeId: 'service_balayage',
    jumperName: 'Marie Tremblay', slotStart: daysFromNow(12, 9), slotEnd: daysFromNow(12, 12),
    status: 'confirmed', options: [], boardingPassId: 'bp_marie_confirmed',
  },
]

export const orders = [
  {
    id: 'ord_lou_direct', tenantId: 'dz_skyline', number: 'MOM-2026-0231', customerId: 'cust_lou',
    createdAt: daysFromNow(-8), status: 'paid', kind: 'direct', currency: 'EUR',
    lines: [{ label: 'Soin visage éclat', qty: 1, price: 75 }, { label: 'Masque premium', qty: 1, price: 15 }],
    total: 90, bookingId: 'bk_lou_confirmed', voucherCodes: [],
  },
  {
    id: 'ord_lou_gift', tenantId: 'dz_skyline', number: 'MOM-2026-0198', customerId: 'cust_lou',
    createdAt: monthsFromNow(-2), status: 'paid', kind: 'gift', currency: 'EUR',
    lines: [{ label: 'Soin visage éclat — chèque cadeau', qty: 1, price: 75 }],
    total: 75, bookingId: null, voucherCodes: ['MOM-GIFT-2026'],
  },
  {
    id: 'ord_lou_past', tenantId: 'dz_skyline', number: 'MOM-2026-0144', customerId: 'cust_lou',
    createdAt: daysFromNow(-52), status: 'paid', kind: 'direct', currency: 'EUR',
    lines: [{ label: 'Soin visage éclat', qty: 1, price: 75 }],
    total: 75, bookingId: 'bk_lou_completed', voucherCodes: [],
  },
  {
    id: 'ord_marie_direct', tenantId: 'dz_chutelibre', number: 'NOVA-2026-0510', customerId: 'cust_marie',
    createdAt: daysFromNow(-5), status: 'paid', kind: 'direct', currency: 'EUR',
    lines: [{ label: 'Balayage lumière', qty: 1, price: 145 }],
    total: 145, bookingId: 'bk_marie_confirmed', voucherCodes: [],
  },
]

export const boardingPasses = [
  {
    id: 'bp_lou_confirmed', bookingId: 'bk_lou_confirmed', tenantId: 'dz_skyline',
    reference: 'RDV-10231', jumperName: 'Lou Martin', jumpTypeName: 'Soin visage éclat',
    slotStart: daysFromNow(6, 11, 30), options: ['Masque premium'], waiverSigned: true, checkedInAt: null,
  },
  {
    id: 'bp_lou_completed', bookingId: 'bk_lou_completed', tenantId: 'dz_skyline',
    reference: 'RDV-09880', jumperName: 'Lou Martin', jumpTypeName: 'Soin visage éclat',
    slotStart: daysFromNow(-40, 9), options: [], waiverSigned: true, checkedInAt: daysFromNow(-40, 8, 45),
  },
  {
    id: 'bp_used_emma', bookingId: 'bk_used_emma', tenantId: 'dz_skyline',
    reference: 'RDV-09512', jumperName: 'Emma Wilson', jumpTypeName: 'Massage relaxant',
    slotStart: daysFromNow(-25, 14), options: [], waiverSigned: true, checkedInAt: daysFromNow(-25, 13, 50),
  },
  {
    id: 'bp_resv_luc', bookingId: 'bk_resv_luc', tenantId: 'dz_chutelibre',
    reference: 'RDV-04420', jumperName: 'Luc Gagnon', jumpTypeName: 'Coupe signature',
    slotStart: daysFromNow(9, 9), options: ['Soin profond'], waiverSigned: true, checkedInAt: null,
  },
  {
    id: 'bp_marie_confirmed', bookingId: 'bk_marie_confirmed', tenantId: 'dz_chutelibre',
    reference: 'RDV-04510', jumperName: 'Marie Tremblay', jumpTypeName: 'Balayage lumière',
    slotStart: daysFromNow(12, 9), options: [], waiverSigned: true, checkedInAt: null,
  },
]
