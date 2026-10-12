# Personnalisation des apparences — ticket #154

L’écran Apparence propose un colorpicker pour les couleurs principale et
complémentaire, ainsi que pour les couleurs existantes du haut et du pied de page.
Les nuances des boutons, survols et autres éléments sont dérivées des couleurs
choisies. Les anciennes palettes restent acceptées par le serveur et le rendu.

La typographie est enregistrée dans le document existant sous la forme
`{ heading: "Plus Jakarta Sans", body: "Inter" }`. Les anciens styles `modern`,
`classic` et `system` restent compatibles. Les titres et le texte possèdent chacun
un sélecteur et une phrase d’aperçu ; Inter est la police de texte par défaut.

Le catalogue complet est chargé depuis l’API publique Fontsource, filtrée sur
les Google Fonts, sans clé API. Seules les familles choisies sont chargées via
Google Fonts. Une panne du catalogue affiche un message et un bouton Réessayer,
et conserve les polices actuelles. Sans accès à Google Fonts, les polices de
secours du navigateur assurent le rendu. L’enregistrement reste un brouillon.
