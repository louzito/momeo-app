// Tri des produits selon l'ordre configure dans l'espace centre
// (Configuration boutique > Boutique). Les produits absents de l'ordre
// configure sont ajoutes a la fin, dans leur ordre naturel.
export function orderJumpTypes(jumps = [], order = []) {
  if (!order?.length) return [...jumps]
  const byCode = new Map(jumps.map((j) => [j.id, j]))
  const ordered = order.map((code) => byCode.get(code)).filter(Boolean)
  const rest = jumps.filter((j) => !order.includes(j.id))
  return [...ordered, ...rest]
}
