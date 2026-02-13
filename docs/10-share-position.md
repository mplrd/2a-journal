# Évolution #1 — Partage de position (texte)

## Résumé

Fonctionnalité de partage des positions (ordres et trades) sous forme de texte formaté, copiable dans le presse-papiers. Deux variantes : avec emojis (pour Discord, Telegram...) et sans emojis (pour les plateformes sobres).

## Architecture

### Backend

#### Service
- **ShareService** (`api/src/Services/ShareService.php`)
  - `generateText()` — texte formaté avec emojis
  - `generateTextPlain()` — texte sans emojis
  - Ownership check (404/403)
  - Adapte le format selon le type de position (ORDER vs TRADE) et le statut du trade (OPEN/SECURED vs CLOSED)

#### Repository
- **TradeRepository** — ajout de `findByPositionId()` pour enrichir les données trade

#### Controller
- **PositionController** — 2 nouvelles actions : `shareText()`, `shareTextPlain()`

#### Routes
| Méthode | URI | Action | Middleware |
|---------|-----|--------|------------|
| GET | `/positions/{id}/share/text` | shareText | AuthMiddleware |
| GET | `/positions/{id}/share/text-plain` | shareTextPlain | AuthMiddleware |

### Frontend

#### Service
- **positionsService** — ajout de `shareText(id)` et `shareTextPlain(id)`

#### Composant
- **ShareDialog** (`frontend/src/components/common/ShareDialog.vue`)
  - Dialog modal avec basculement emojis/sans emojis
  - Textarea readonly avec le texte formaté
  - Bouton copier → `navigator.clipboard.writeText()` (fallback `execCommand`)
  - Toast de confirmation

#### Intégration
- Bouton partage (icône `pi-share-alt`) ajouté dans :
  - **OrdersView** — colonne actions
  - **TradesView** — colonne actions

## Format du texte

### Ordre (OC)
```
📈 BUY NASDAQ @ 18240
🎯 TP: 18350 (+110 pts)
🛑 SL: 18190 (-50 pts)
⚖️ R/R: 2.2

💬 Touchette haut de zone weekly
```

### Trade ouvert (OPEN/SECURED)
Même format que l'ordre, basé sur les données de la position.

### Trade fermé (CLOSED)
```
📈 BUY NASDAQ @ 18240 → 18350
✅ PnL: +110 (+0.60%)
🎯 Exit: TP
⚖️ R/R: 2.2
⏱️ 2h30

💬 Divergence haussière sur RSI
```

### Version sans emojis
```
BUY NASDAQ @ 18240
TP: 18350 (+110 pts)
SL: 18190 (-50 pts)
R/R: 2.2

Touchette haut de zone weekly
```

## Détails de formatage

- **Direction** : 📈 (BUY) / 📉 (SELL)
- **PnL positif** : ✅, **PnL négatif** : ❌
- **Targets multiples** : TP1, TP2... (TP seul si un seul target)
- **BE** : ligne 🔒 BE si renseigné
- **R/R** : calculé depuis le 1er target / sl_points (ordres), ou depuis `risk_reward` (trades clôturés)
- **Durée** : `45min`, `2h30`, `2h` (sans zéro inutile)
- **Prix** : trailing zeros supprimés (18240 au lieu de 18240.00000)

## i18n

Bloc `share` ajouté dans `fr.json` et `en.json` :
- `title`, `share`, `copy`, `copied`, `with_emojis`, `without_emojis`

## Tests

### Backend (436 tests, 1113 assertions)
- **ShareServiceTest** (17 tests) — format BUY/SELL, targets multiples, sans targets, BE, trade open/closed, PnL négatif, durée, plain text, erreurs 404/403, trailing zeros
- **ShareFlowTest** (7 tests) — endpoints intégration : order, trade, trade fermé, text-plain, auth 401, forbidden 403, not found 404

### Frontend (84 tests)
- **share-dialog.spec.js** (6 tests) — rendu, fetch, affichage texte, erreur réseau, émission close, méthodes service

## Scope exclu (évolutions ultérieures)

- Génération d'image (PNG card) — P2
- Lien de partage public (URL temporaire) — P3
- Export PDF — P3
- Personnalisation du partage (masquer SL, templates Discord/Telegram) — P3
