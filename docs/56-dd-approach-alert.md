# Étape 56 — Alerte d'approche du drawdown (max + journalier)

## Résumé

Quand un compte propfirm consomme une part importante de son drawdown (max ou journalier), l'utilisateur reçoit deux signaux :

1. **Bandeau rouge en haut du Dashboard** listant les comptes en alerte (ex. « PF Account A — 96% du drawdown maximum atteint »).
2. **Email** envoyé au moment où le seuil est franchi (1 mail par compte par type de DD par jour, dédupliqué jusqu'au reset journalier dans la TZ locale du user).

Le seuil de déclenchement est paramétrable au niveau utilisateur dans l'onglet Préférences :
- Champ **"Seuil d'alerte drawdown (%)"** (`dd_alert_threshold_percent`), plage **1–50 %**, défaut **5 %**.
- Sémantique : alerte quand le compte a consommé **(100 − seuil) %** ou plus de son drawdown. Avec le défaut 5 %, l'alerte se déclenche dès 95 % de DD utilisé.

Source du besoin : `docs/retours-beta-tests.md` ticket E-08 (alerte si DD dépassé). Conception affinée en discussion : un seul seuil par user (mêmes valeur pour max DD et daily DD), bandeau dashboard + email, dédup quotidien dans la TZ user.

## Comportement

### Côté utilisateur

- À l'ouverture du dashboard, un appel `GET /accounts/dd-status` récupère l'état DD de tous les comptes ayant au moins un champ DD configuré (PF par défaut, et non-PF si l'utilisateur a explicitement renseigné un DD via le toggle « Paramètres de gestion du risque » de l'onglet compte — cf. doc 54).
- Si au moins un compte est en alerte, le bandeau apparaît avec :
  - Icône d'avertissement, titre « Approche du drawdown ».
  - Une ligne par compte en alerte mentionnant le type de DD touché (max et/ou journalier) avec le % consommé.
  - Hint final : « Pense à vérifier tes positions ouvertes pour éviter une violation de la règle. »
- Les comptes sans DD configuré sont silencieusement ignorés (pas dans la réponse).

### Email

- Déclenché côté backend depuis `TradeService::close` après chaque sortie partielle ou finale qui pourrait franchir un seuil.
- 1 mail par compte par type de DD par jour (TZ locale du user).
- Sujet : « Alerte drawdown — compte {nom} » / « Drawdown alert — account {name} ».
- Contenu : nom du compte, type de DD, % consommé, montant consommé / total, rappel du seuil paramétrable.

### Déduplication

- Stamp `accounts.last_max_dd_alert_at` / `last_daily_dd_alert_at` (UTC) à chaque envoi.
- Au prochain `checkAndNotify`, on compare ce stamp à minuit local user → si `last_alert < todayStartLocal`, on peut ré-envoyer.
- Conséquence : reset à minuit local user. Un compte qui passe en alerte tous les jours envoie 1 mail / jour / type. Un compte qui oscille autour du seuil envoie au plus 1 mail max + 1 mail daily / jour, peu importe combien d'oscillations.

## Backend

### Schema (migration 017)

```sql
ALTER TABLE users
    ADD COLUMN dd_alert_threshold_percent DECIMAL(4,2) NOT NULL DEFAULT 5.00 AFTER be_threshold_percent;

ALTER TABLE accounts
    ADD COLUMN last_max_dd_alert_at TIMESTAMP NULL DEFAULT NULL AFTER profit_split,
    ADD COLUMN last_daily_dd_alert_at TIMESTAMP NULL DEFAULT NULL AFTER last_max_dd_alert_at;
```

Additive, pas de contrainte modifiée. Idempotent sous `migrate.php` au boot.

### `DrawdownService` (nouveau)

Trois responsabilités :

- `getStatusForUser(int $userId): array` — itère sur tous les comptes du user, retourne un tableau de DD-status pour ceux ayant un DD configuré. **Lecture seule**, aucun email envoyé. Utilisé par l'endpoint dashboard.
- `checkAndNotifyForAccount(int $accountId, int $userId): void` — recalcule l'état DD pour un compte, et si un seuil est franchi ET pas encore notifié aujourd'hui (TZ user), envoie un mail + stamp la colonne dedup. Appelé en fire-and-forget depuis `TradeService::close`.
- `computeForAccount(array $account, array $user): ?array` (privée) — math pure, pas d'I/O. Calcule `max_used_amount/percent`, `daily_used_amount/percent`, `alert_max`, `alert_daily`. Retourne `null` si `max_drawdown` ET `daily_drawdown` sont tous deux `null`.

Le P&L "non réalisé" (open trades) est exposé via `computeUnrealizedPnl(int $accountId): float`. **Aujourd'hui retourne 0** (pas de cours live des brokers). Quand une intégration de cours arrive (E-02 Ouinex, E-09 IG…), le corps de cette méthode devient l'agrégation `(current_price - entry_price) * remaining_size * direction_multiplier` sur les trades OPEN/SECURED — tout le pipeline en aval (DD usage, alertes, dashboard) est déjà câblé pour le consommer.

### Hook dans `TradeService::close`

```php
if ($this->drawdownService !== null) {
    $this->drawdownService->checkAndNotifyForAccount((int) $trade['account_id'], $userId);
}
```

`DrawdownService` est passé en constructeur (paramètre optionnel pour ne pas casser les tests unitaires existants). Le `checkAndNotify` est wrappé dans un try/catch interne — toute erreur (email API down, etc.) est loggée via `error_log` mais n'interrompt pas la réponse de close du trade.

### Endpoint `GET /accounts/dd-status`

```php
$router->get('/accounts/dd-status', [$accountController, 'ddStatus'], [$authMiddleware, $requireSubscription]);
```

Renvoie `{success: true, data: [...]}` où `data` est une liste de :

```json
{
  "account_id": 100,
  "account_name": "PF A",
  "currency": "USD",
  "max_drawdown": 5000,
  "daily_drawdown": 2000,
  "max_used_amount": 4800,
  "max_used_percent": 96.00,
  "daily_used_amount": 0,
  "daily_used_percent": 0,
  "alert_max": true,
  "alert_daily": false,
  "threshold_percent": 5.0
}
```

### Mise à jour des préférences user

`AuthService::updateProfile` accepte maintenant `dd_alert_threshold_percent`. Validation : numérique entre 1.0 et 10.0 inclus, sinon `ValidationException('auth.error.invalid_dd_alert_threshold')`. Field listé dans `PROFILE_FIELDS` (whitelist) et dans `UserRepository::PROFILE_FIELDS`.

### Email

Templates `api/templates/emails/dd-alert.fr.html` et `dd-alert.en.html`. Variables interpolées : `{{account_name}}`, `{{dd_label}}`, `{{used_percent}}`, `{{used_amount}}`, `{{dd_total}}`, `{{currency}}`. Méthode `EmailService::sendDdAlertEmail(string $toEmail, string $locale, string $type, array $status): void` (où `$type` ∈ {'max','daily'}).

## Frontend

### `DdAlertBanner.vue` (nouveau)

Composant autonome monté en haut du `DashboardView.vue`. Au mount, appelle `accountsService.ddStatus()` et stocke la réponse. Affiche le bandeau seulement si au moins un compte a `alert_max` ou `alert_daily`.

`defineExpose({ load })` permet à un parent de re-trigger l'appel après une action utilisateur si besoin (non utilisé pour l'instant).

### `PreferencesTab.vue`

Nouveau bloc sous le seuil BE :
- Label `t('account.dd_alert_threshold')` → « Seuil d'alerte drawdown (%) ».
- `<InputNumber>` (cohérent avec le widget BE threshold), min 1, max 10, défaut 5.
- Hint explicite sous le champ.

Le champ est intégré au form existant et au flux `authStore.updateProfile()`.

### Service

`accountsService.ddStatus()` — `GET /accounts/dd-status`. Aucune mise en cache pour l'instant : appel à chaque mount du dashboard.

## Couverture des tests

| Test | Fichier | Scénario | Statut |
|------|---------|----------|--------|
| `testGetStatusForUserSkipsAccountsWithoutDdConfig` | `DrawdownServiceTest.php` | Comptes sans DD config exclus de la réponse | ✅ |
| `testComputeReturnsZeroPercentsWhenAccountIsBreakEven` | `DrawdownServiceTest.php` | Pas de loss → 0% utilisé, aucune alerte | ✅ |
| `testComputeRaisesAlertMaxWhenLossExceedsCutoff` | `DrawdownServiceTest.php` | Loss > (100−seuil)% du DD max → alert_max true | ✅ |
| `testCheckAndNotifyEmailsOnceWhenThresholdCrossed` | `DrawdownServiceTest.php` | Seuil franchi et pas encore notifié → email + stamp | ✅ |
| `testCheckAndNotifySkipsWhenAlreadySentToday` | `DrawdownServiceTest.php` | Déjà notifié aujourd'hui (TZ user) → pas de mail | ✅ |

**Suite complète post-fix** : 1076/1076 PHPUnit + 212/212 Vitest.

## Limitations connues / Évolutions futures

- **P&L non réalisé** : actuellement 0 (pas de cours live brokers). L'alerte ne déclenche donc que sur les trades fermés. Quand l'intégration cours live arrive, plug dans `DrawdownService::computeUnrealizedPnl` — le reste fonctionnera tel quel.
- **Reset journalier en TZ user** : OK pour la majorité des cas. Si une propfirm utilise une autre TZ pour le reset (ex. broker en EST), l'utilisateur devra ajuster sa TZ globale (ou attendre une amélioration ciblée).
- **Aggregation multi-comptes** : 1 mail par compte. Si l'utilisateur a 5 PF en alerte le même jour, il reçoit jusqu'à 10 mails (5 max + 5 daily). À revoir si ça devient un problème de spam dans la pratique.
- **Pas de "leave alert"** : on ne notifie pas quand le compte sort de l'état d'alerte (par ex. après une grosse session profitable). Côté visuel, le bandeau disparaîtra au prochain refresh dashboard.
