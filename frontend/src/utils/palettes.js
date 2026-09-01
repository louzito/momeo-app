// Palettes de branding pretes a l'emploi (valeurs Tailwind, au format "R G B"
// pour supporter le modificateur d'opacite dans les classes brand-xxx/NN).
// Chaque tenant reference une palette "brand" (10 nuances) et un "accent"
// (3 nuances) via son objet branding. Voir src/composables/useBranding.js.

export const BRAND_PALETTES = {
  sky: {
    50: '239 246 255',
    100: '219 234 254',
    200: '191 219 254',
    300: '147 197 253',
    400: '96 165 250',
    500: '59 130 246',
    600: '37 99 235',
    700: '29 78 216',
    800: '30 64 175',
    900: '30 58 138',
  },
  emerald: {
    50: '236 253 245',
    100: '209 250 229',
    200: '167 243 208',
    300: '110 231 183',
    400: '52 211 153',
    500: '16 185 129',
    600: '5 150 105',
    700: '4 120 87',
    800: '6 95 70',
    900: '6 78 59',
  },
  violet: {
    50: '245 243 255',
    100: '237 233 254',
    200: '221 214 254',
    300: '196 181 253',
    400: '167 139 250',
    500: '139 92 246',
    600: '124 58 237',
    700: '109 40 217',
    800: '91 33 182',
    900: '76 29 149',
  },
}

export const ACCENT_PALETTES = {
  orange: { 400: '251 146 60', 500: '249 115 22', 600: '234 88 12' },
  amber: { 400: '251 191 36', 500: '245 158 11', 600: '217 119 6' },
  rose: { 400: '251 113 133', 500: '244 63 94', 600: '225 29 72' },
  cyan: { 400: '34 211 238', 500: '6 182 212', 600: '8 145 178' },
}
