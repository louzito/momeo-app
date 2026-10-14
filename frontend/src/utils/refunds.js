// Money is validated in minor units, without rounding an overprecise input.
export function refundAmount(value) {
  const normalized = String(value).trim().replace(',', '.')
  if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) return null
  const [whole, fraction = ''] = normalized.split('.')
  const amount = Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
  return Number.isSafeInteger(amount) && amount > 0 ? amount : null
}
