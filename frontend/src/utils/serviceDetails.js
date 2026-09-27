// Même périmètre que les options proposées et présélectionnées au checkout.
export function applicableServiceOptions(service, options = []) {
  return options.filter((option) => option.scope !== 'PER_JUMP'
    || !option.linkedJumpTypeIds?.length || option.linkedJumpTypeIds.includes(service?.id))
}

export function serviceStartingPrice(service, options = []) {
  const cents = Math.round((service?.basePrice || 0) * 100)
  return (cents + applicableServiceOptions(service, options)
    .filter((option) => option.mandatory)
    .reduce((total, option) => total + Math.round(option.price * 100), 0)) / 100
}

export function formatDuration(minutes) {
  if (!Number.isFinite(minutes) || minutes <= 0) return ''
  const hours = Math.floor(minutes / 60)
  const rest = minutes % 60
  return [hours ? `${hours} h` : '', rest ? `${rest} min` : ''].filter(Boolean).join(' ')
}

export function serviceCharacteristics(service) {
  const configured = service?.configuredDetails || {}
  const rows = []
  const duration = formatDuration(configured.durationMin)
  if (duration) rows.push({ label: 'Durée', value: duration })
  if (!service?.legacyEligibility) return rows
  const add = (field, label, unit) => {
    if (Number.isFinite(configured[field]) && configured[field] > 0) {
      rows.push({ label, value: `${configured[field].toLocaleString('fr-FR')}${unit}` })
    }
  }
  add('altitudeM', 'Altitude', ' m')
  add('ageMin', 'Âge minimum', ' ans')
  add('ageMax', 'Âge maximum', ' ans')
  add('weightMaxKg', 'Poids maximum', ' kg')
  add('heightMinCm', 'Taille minimum', ' cm')
  add('bmiMax', 'IMC maximum', '')
  if (configured.medicalCertificateRequired) rows.push({ label: 'Certificat médical', value: 'Requis' })
  if (configured.waiverRequired) rows.push({ label: 'Décharge de responsabilité', value: 'À signer' })
  return rows
}
