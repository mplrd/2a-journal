# Évolution #11 — Partage direct vers messageries/réseaux + preview live

## Résumé

Deux ajouts autour du partage :
1. **Boutons de partage rapide** dans le ShareDialog existant (WhatsApp, Telegram, X, Discord, Email)
2. **Preview live** du texte de partage dans les formulaires de création d'ordre et de trade, avec bouton copier

## Architecture

### Scope

Frontend uniquement — aucun changement backend.

### Fichiers modifiés / créés

| Fichier | Action |
|---------|--------|
| `frontend/src/components/common/ShareDialog.vue` | Ajout boutons plateformes |
| `frontend/src/composables/useSharePreview.js` | **Créé** — composable preview client-side |
| `frontend/src/components/order/OrderForm.vue` | Ajout section preview live |
| `frontend/src/components/trade/TradeForm.vue` | Ajout section preview live |
| `frontend/src/__tests__/share-dialog.spec.js` | 7 nouveaux tests plateformes |

## Fonctionnalité 1 — Boutons de partage (ShareDialog)

### Plateformes

| Plateforme | Deep link | Icône | Particularité |
|------------|-----------|-------|---------------|
| WhatsApp | `https://wa.me/?text={text}` | `pi-whatsapp` | — |
| Telegram | `https://t.me/share/url?text={text}` | `pi-telegram` | — |
| X (Twitter) | `https://twitter.com/intent/tweet?text={text}` | `pi-twitter` | Troncature à 280 caractères |
| Discord | Copie clipboard | `pi-discord` | Pas de deep link, réutilise `copyToClipboard()` |
| Email | `mailto:?subject={subject}&body={text}` | `pi-envelope` | Sujet i18n (`share.email_subject`) |

### Logique spéciale

- **Twitter** : si le texte dépasse 280 caractères, il est tronqué à 277 + `...`
- **Discord** : pas de deep link natif, copie dans le presse-papiers
- **Email** : ouvre le client mail avec sujet traduit et corps = texte de la position
- **Texte** : toujours basé sur `currentText()`, qui respecte l'onglet emoji/plain actif

## Fonctionnalité 2 — Preview live à la création

### Composable `useSharePreview`

`frontend/src/composables/useSharePreview.js` — réplique client-side du format `ShareService::formatOpenPosition()` du backend.

Prend en entrée les refs réactives du formulaire et retourne un `sharePreviewText` computed qui se met à jour en temps réel :

```
📈 BUY NASDAQ @ 18240
🎯 TP1: 18350 (+110 pts)
🎯 TP2: 18400 (+160 pts)
🔒 BE: 18290 (+50 pts)
🛑 SL: 18190 (-50 pts)
⚖️ R/R: 2.2

💬 Divergence haussière RSI
```

### Intégration

Section ajoutée dans **OrderForm** et **TradeForm**, sous le champ notes :
- Encadré gris (`bg-gray-50 border`) avec le label "Aperçu du partage"
- Texte formaté en `<pre>` (font-mono, whitespace-pre-wrap)
- Bouton "Copier" → `navigator.clipboard.writeText()`
- N'apparaît que quand les champs minimaux sont remplis (symbol + entry_price)

### Utilité

- Permet de partager à une communauté ce qu'on prépare **pendant** la saisie
- Sert de contrôle visuel des données saisies avant soumission

## i18n

Clés ajoutées dans `fr.json` et `en.json` :

| Clé | FR | EN |
|-----|----|----|
| `share.share_via` | Partager via | Share via |
| `share.whatsapp` | WhatsApp | WhatsApp |
| `share.telegram` | Telegram | Telegram |
| `share.twitter` | X | X |
| `share.discord` | Discord | Discord |
| `share.email` | Email | Email |
| `share.email_subject` | Position de trading | Trading Position |
| `share.preview` | Aperçu du partage | Share preview |

## Tests

### Frontend (91 tests)

- **share-dialog.spec.js** — 7 nouveaux tests (total : 13) :
  - `renders share platform buttons` — vérifie la présence des 5 boutons via `data-testid`
  - `opens WhatsApp with encoded text` — spy `window.open`
  - `opens Telegram with encoded text` — spy `window.open`
  - `opens Twitter with encoded text` — spy `window.open`
  - `truncates Twitter text to 280 chars` — 300 chars → 277 + `...`
  - `copies to clipboard for Discord` — spy `navigator.clipboard.writeText`
  - `opens email with mailto link` — spy `window.open` avec `mailto:`

## Vérification manuelle

1. **ShareDialog** : 5 boutons plateformes visibles entre textarea et actions
2. **WhatsApp/Telegram/Twitter** : ouvrent un nouvel onglet avec le bon deep link
3. **Discord** : copie dans le presse-papiers + toast
4. **Email** : ouvre le client mail avec sujet et corps
5. **OrderForm** : preview live visible pendant la saisie, se met à jour en temps réel
6. **TradeForm** : idem
7. **Bouton Copier** : copie le texte de la preview + toast "Copié"

## Scope exclu (évolutions ultérieures)

- Personnalisation du format par plateforme (templates dédiés) — P3
- Web Share API native (navigator.share) comme alternative — P3
- Preview pour les trades fermés (format avec PnL, durée) — P3
