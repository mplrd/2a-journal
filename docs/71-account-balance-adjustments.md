# 71 — Correction du solde de compte (ajustements manuels)

Ticket #30. Permet de corriger le solde affiché d'un compte pour absorber un
écart non tracé par un trade : frais oubliés/non saisis, ou solde de départ
légèrement différent du capital initial théorique.

## Fonctionnalités

- Bouton **« Corriger le solde »** sur chaque compte (icône `pi-sliders-h`,
  inline sur desktop, dans le menu `…` sur mobile).
- Modale `AdjustBalanceDialog` :
  - rappel du solde calculé actuel ;
  - saisie **liée à deux champs** « Solde réel constaté » ⇄ « Ajustement » :
    éditer l'un calcule l'autre (façon prix/points des objectifs de trade) ;
  - motif optionnel ;
  - **historique des corrections** (date, montant signé, motif) avec
    suppression ligne par ligne.
- Le **capital initial n'est jamais modifié** (demande explicite). Le solde
  affiché devient :
  ```
  current_capital = initial_capital + SUM(pnl trades clôturés) + SUM(ajustements)
  ```

## Choix d'implémentation

### Modèle — ledger plutôt qu'une valeur unique
Le besoin (« ajuster des frais oubliés ») est par nature cumulatif et
auditable, d'où une **table d'historique** `account_balance_adjustments`
(une ligne = un delta signé + motif + date) plutôt qu'un champ unique écrasé.
Chaque correction est réversible (suppression), ce qui rétablit le solde.

Migration **027** additive et idempotente (`CREATE TABLE IF NOT EXISTS`,
FK `ON DELETE CASCADE` sur `accounts`). Jouée automatiquement au boot.

### Calcul du solde
`AccountRepository::findById` / `findAllByUserId` ajoutent un `LEFT JOIN`
sommant les ajustements par compte, agrégé dans `current_capital`. Aucune
colonne dénormalisée : le solde reste dérivé à la lecture, cohérent avec
l'agrégat de P&L existant.

### Saisie liée (delta signé)
Le composant réutilisable `BalanceAdjustmentInput` porte comme modèle le
**delta signé** (`amount`) ; le « solde réel » est dérivé du solde courant
(`base`) : `target = base + amount`, `amount = target − base`. Le champ
ajustement utilise une borne `min` dynamique (`-base`) pour autoriser la
saisie de valeurs négatives au clavier (contournement du filtre `-` de
PrimeVue InputNumber, cf. retours antérieurs).

### API
- `POST /accounts/{id}/adjustments` — `{ amount (delta signé ≠ 0), reason? }`
- `GET /accounts/{id}/adjustments` — historique (plus récent d'abord)
- `DELETE /accounts/{id}/adjustments/{adjustmentId}`

Validation serveur : montant numérique non nul (`accounts.error.invalid_adjustment`),
motif ≤ 255 (`accounts.error.reason_too_long`), propriété du compte
(404/403 réutilisant `AccountService::get`). Réponses en `message_key`.

## Couverture des tests

| Niveau | Fichier | Scénarios |
|--------|---------|-----------|
| Repo (intégration) | `AccountAdjustmentRepositoryTest` | create (+ montant négatif, reason null), findByAccountId (ordre, scope), findById, delete, cascade à la suppression du compte |
| Repo (intégration) | `AccountRepositoryTest` | `current_capital` inclut les ajustements, combine trades + ajustements, scope par compte |
| Service (unit) | `AccountServiceTest` | addAdjustment (succès, négatif, montant manquant/non numérique/0, reason trop long, forbidden, not found), listAdjustments (succès, forbidden), deleteAdjustment (succès, introuvable, autre compte, forbidden) |
| Flow (intégration) | `AccountFlowTest` | POST met à jour `current_capital` (positif/négatif), GET historique, DELETE rétablit le solde, montant invalide → 422, compte d'autrui → 403 |
| Composant (Vitest) | `BalanceAdjustmentInput.spec` | seed = base, target→delta (positif/négatif), delta→target, reflet d'un delta externe |
| Composant (Vitest) | `AdjustBalanceDialog.spec` | affiche le solde de base, emit `submit` {amount, reason}, pas d'emit si delta nul, rendu de l'historique, emit `delete-adjustment` |
| Store (Vitest) | `accounts-store.spec` | fetchAdjustments, addAdjustment, deleteAdjustment, propagation d'erreur |

Backend : 1349 tests verts. Frontend : 354 tests verts.
