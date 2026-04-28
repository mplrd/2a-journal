# Étape 33 — Édition d'un trade à tout moment + transfert opt-in + regroupement des actions

## Résumé

Trois ajustements à la grille des trades, livrés ensemble car ils touchent la même barre d'actions :

1. **Édition d'un trade autorisée même après clôture.** Le bouton crayon n'est plus masqué quand `status = CLOSED`. Le user peut corriger une donnée saisie par erreur (entry, taille, setup, notes…) sans avoir à supprimer/recréer le trade.
2. **Le bouton "Transfert" devient opt-in via un setting global** `trade_transfer_enabled` (default `false`). Tant que l'admin ne l'active pas dans le BO, le bouton est masqué côté SPA **et** l'endpoint refuse l'appel server-side.
3. **Regroupement visuel des boutons d'action** en 3 clusters (gestion / édition / partage), alignés à droite de la cellule.

## Décisions

- **Édition post-clôture côté backend** : aucune restriction n'a jamais existé dans `PositionService::update()` — c'est purement le frontend qui gardait l'utilisateur (le `v-if="data.status !== TradeStatus.CLOSED"`). Lever le gate côté UI suffit, mais on **pin** le contrat avec un test d'intégration qui prouve que `PUT /positions/{id}` fonctionne sur un trade `CLOSED`.
- **Setting `trade_transfer_enabled`** : suit le pattern `PlatformSettingsService` existant (DB > env > null). On introduit un nouveau flag `is_public: true` dans `knownSettings()` pour distinguer ce qu'un user authentifié peut voir des settings purement admin (mail_from_address, billing_grace_days…).
- **Diffusion du flag au SPA via `/auth/me` + login + register** : pas de nouvel endpoint dédié type `/settings/public`. Le SPA charge déjà la session au démarrage, on enrichit la réponse avec `public_settings` — un round-trip de moins.
- **Kill-switch server-side** : on n'a pas voulu d'un flag UI-only. `PositionService::transfer()` résout le setting et lève `ForbiddenException('positions.error.transfer_disabled')` si `false`. La même intention que la flag (feature désactivée) s'applique à un appel API direct.
- **Regroupement** : 3 groupes séparés par un divider `divide-x` :
    - **Gestion** (gauche) : prochain objectif (BE/TP), clôturer, transférer
    - **Édition** (milieu) : éditer, supprimer
    - **Partage** (droite) : partager
  Le groupe "Gestion" disparaît entièrement quand `status === CLOSED` (aucune des 3 actions n'a de sens), évitant un divider orphelin.

## Choix d'implémentation

### Backend

**`PlatformSettingsService::knownSettings()`** — ajout d'une entrée :

```php
'trade_transfer_enabled' => [
    'type' => SettingType::BOOL->value,
    'env_var' => 'TRADE_TRANSFER_ENABLED',
    'description' => 'admin.settings.desc.trade_transfer_enabled',
    'is_public' => true,
],
```

**`PlatformSettingsService::publicSettings()`** — nouvelle méthode :

```php
public function publicSettings(): array
{
    $out = [];
    foreach (self::knownSettings() as $key => $meta) {
        if (empty($meta['is_public'])) continue;
        $value = $this->resolve($key);
        if ($value === null && $meta['type'] === SettingType::BOOL->value) {
            $value = false; // safe default for "off until enabled"
        }
        $out[$key] = $value;
    }
    return $out;
}
```

**`AuthService::enrichUserPayload()`** — helper privé qui ajoute `public_settings` à toute réponse contenant un user (login, register, /auth/me). Centralise la règle, évite que le SPA ait à interroger un second endpoint.

**`PositionService::transfer()`** — kill-switch en tête de méthode :

```php
if ($this->platformSettings !== null
    && $this->platformSettings->resolve('trade_transfer_enabled') !== true) {
    throw new ForbiddenException('positions.error.transfer_disabled');
}
```

`PlatformSettingsService` est injecté via `routes.php` (déjà instancié pour AuthService et EmailService).

### Frontend

**`TradesView.vue`** — la cellule action passe de `<div class="flex gap-2">…</div>` à 3 `<div>` enfants séparés par `divide-x`, le wrapper en `flex justify-end` pour aligner à droite. Le groupe "Gestion" est conditionné par `v-if="data.status !== TradeStatus.CLOSED"` ; le bouton Transfer dedans est lui-même conditionné par `authStore.user?.public_settings?.trade_transfer_enabled`.

Le SPA lit `auth.user.public_settings` qui arrive automatiquement avec le payload `/auth/me` au démarrage, ou directement dans la réponse de `/auth/login` et `/auth/register`. Pas de store dédié ni de service additionnel.

## Variables d'environnement

| Variable | Type | Default | Description |
|---|---|---|---|
| `TRADE_TRANSFER_ENABLED` | bool | unset (= `false`) | Active la fonctionnalité de transfert d'un trade entre comptes (UI + endpoint). |

L'admin peut overrider via le BO `/settings` sans redéploiement (DB > env).

## Couverture des tests

| Test | Type | Scenario | Statut |
|---|---|---|---|
| `testUpdatePositionAllowedWhenTradeClosed` | Integration | PUT /positions/{id} sur position dont le trade est CLOSED → 200, valeurs persistées | ✅ |
| `testRegisterIncludesPublicSettings` | Integration | POST /auth/register inclut `public_settings.trade_transfer_enabled = false` | ✅ |
| `testLoginIncludesPublicSettings` | Integration | POST /auth/login inclut `public_settings.trade_transfer_enabled = false` | ✅ |
| `testMeIncludesPublicSettingsDefaultFalse` | Integration | GET /auth/me renvoie `public_settings.trade_transfer_enabled = false` quand DB et env sont vides | ✅ |
| `testMePublicSettingsReflectsDbOverride` | Integration | DB override de `trade_transfer_enabled = true` → /auth/me renvoie `true` | ✅ |
| `testTransferPositionForbiddenWhenSettingDisabled` | Integration | POST /positions/{id}/transfer → 403 + `positions.error.transfer_disabled` quand le flag est `false` | ✅ |
| `testTransferPositionSuccess` | Integration | Avec flag activé : transfer normal réussit | ✅ |
| `testTransferPositionForbiddenTargetAccount` | Integration | Avec flag activé : compte cible d'un autre user → 403 (existant, mis à jour) | ✅ |
| `testHistoryAfterTransfer` | Integration | Avec flag activé : status_history loggue le transfert (existant, mis à jour) | ✅ |

Total : 4 nouveaux tests, 3 tests existants adaptés (opt-in via helper `enableTransferSetting()`).

## Notes sécurité

- Le flag `trade_transfer_enabled` est un **vrai kill-switch** : refus côté UI **et** côté endpoint. Pas de bypass possible via appel API direct.
- `public_settings` n'expose que les settings flaggés `is_public: true` dans `knownSettings()`. Les valeurs admin-only (mail_from_address, billing_grace_days, etc.) ne fuitent pas dans `/auth/me`.
- Aucun nouvel input utilisateur n'a été ajouté pour l'édition post-clôture : `validateUpdate()` et l'ownership check (`PositionService::get()`) restent inchangés et continuent de s'appliquer.
