// Correspondance statut -> libelle + classes Tailwind (badges).
export const STATUS_META = {
  // Cheques cadeaux
  issued: { label: 'Disponible', classes: 'bg-emerald-100 text-emerald-700', dot: 'bg-emerald-500' },
  // Statuts REELS (App\Entity\GiftVoucher) : awaiting_payment/active/used/expired.
  active: { label: 'Disponible', classes: 'bg-emerald-100 text-emerald-700', dot: 'bg-emerald-500' },
  awaiting_payment: { label: 'Paiement en attente', classes: 'bg-amber-100 text-amber-700', dot: 'bg-amber-500' },
  reserved: { label: 'Reserve', classes: 'bg-blue-100 text-blue-700', dot: 'bg-blue-500' },
  used: { label: 'Utilise', classes: 'bg-slate-200 text-slate-600', dot: 'bg-slate-500' },
  expired: { label: 'Expire', classes: 'bg-rose-100 text-rose-700', dot: 'bg-rose-500' },
  extended: { label: 'Prolonge', classes: 'bg-amber-100 text-amber-700', dot: 'bg-amber-500' },
  refunded: { label: 'Rembourse', classes: 'bg-slate-200 text-slate-600', dot: 'bg-slate-500' },
  // Reservations
  confirmed: { label: 'Confirmee', classes: 'bg-emerald-100 text-emerald-700', dot: 'bg-emerald-500' },
  postponed: { label: 'Reportee (meteo)', classes: 'bg-amber-100 text-amber-700', dot: 'bg-amber-500' },
  cancelled: { label: 'Annulee', classes: 'bg-rose-100 text-rose-700', dot: 'bg-rose-500' },
  completed: { label: 'Effectuee', classes: 'bg-slate-200 text-slate-600', dot: 'bg-slate-500' },
  // Commandes
  paid: { label: 'Payee', classes: 'bg-emerald-100 text-emerald-700', dot: 'bg-emerald-500' },
}

export function statusMeta(status) {
  return STATUS_META[status] || { label: status, classes: 'bg-slate-100 text-slate-600', dot: 'bg-slate-400' }
}
