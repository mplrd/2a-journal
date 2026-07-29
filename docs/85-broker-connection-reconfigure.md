# 85 — Reconfiguration d'une connexion broker

## Contexte

Une connexion broker pouvait être **créée** et **supprimée**, jamais **modifiée**. Concrètement, quand les identifiants devenaient invalides — cas déclencheur : un `clientSecret` cTrader régénéré côté portail, qui fait échouer l'authentification avec `CH_CLIENT_AUTH_FAILURE - wrong clientSecret` — l'utilisateur se retrouvait dans une impasse :

1. Chaque synchronisation échoue.
2. Après plusieurs échecs consécutifs, `markError()` bascule la connexion en statut `ERROR`.
3. `BrokerSyncService::sync()` refuse tout statut autre que `ACTIVE` → plus aucune synchro possible.
4. Le seul bouton restant était **Supprimer**, ce qui perdait le `sync_cursor` (la synchro suivante rebalaye 90 jours d'historique) et supprimait en cascade tout l'historique `sync_logs`.

Cette livraison ajoute une **reconfiguration sur place** : mêmes ligne, id, curseur et logs — seuls les identifiants changent.

## Ce qui a été livré

### 1. Reconfiguration des identifiants (`PUT /broker/connections/{id}`)

- Remplace tout ou partie des identifiants de la connexion existante, **sans changer de provider**.
- **Un champ laissé vide conserve la valeur enregistrée.** L'API ne renvoie jamais un secret au client, donc un champ mot de passe non touché arrive vide : il ne doit pas être interprété comme un effacement volontaire. Le frontend n'envoie d'ailleurs que les champs réellement modifiés.
- Le statut est remis à `ACTIVE`, `last_sync_error` est effacé et `consecutive_failures` remis à 0 — sinon la connexion resterait bloquée en `ERROR`.
- `sync_cursor`, `symbols_seen` et l'historique `sync_logs` sont **préservés** : aucune réimportation.
- Une soumission vide (aucun champ renseigné) est refusée (`broker.error.no_credential_change`) plutôt que de réinitialiser silencieusement le statut.

### 2. Test des identifiants à l'enregistrement (non bloquant)

`testConnection()` existait sur les quatre connecteurs mais n'était jamais appelé. Il l'est désormais à la création **et** à la reconfiguration.

- **Non bloquant** : les identifiants sont enregistrés dans tous les cas. Une indisponibilité du broker ne doit jamais empêcher de corriger un secret.
- La réponse porte `connection_test: { success, error }`. Le panneau affiche un toast vert si le broker accepte, un toast orange **reprenant le message du broker** sinon.
- Nouvelle méthode `ConnectorInterface::getLastTestError()` (implémentation partagée via le trait `TracksLastTestError`) : `testConnection()` renvoyait un `bool` nu, où un secret erroné et un host injoignable étaient indiscernables. Le message du broker est maintenant remonté tel quel — `wrong clientSecret` est actionnable, `false` ne l'est pas.

### 3. Serveur cTrader Live / Démo par connexion

- Le host venait de la variable d'environnement globale `CTRADER_WS_HOST` : impossible pour un compte démo et un compte réel de coexister entre utilisateurs.
- Nouvel enum `CtraderEnvironment` (`LIVE` / `DEMO`), stocké **dans le blob d'identifiants chiffré** de la connexion, et sélecteur Live/Démo dans le dialogue.
- Un compte cTrader n'existe que sur un seul des deux serveurs ; un compte démo authentifié sur le serveur live est refusé.
- **Rétrocompatible** : une connexion créée avant cette livraison n'a pas de clé `environment` et continue de passer par `CTRADER_WS_HOST`. Aucune migration.

## Architecture

| Fichier | Rôle |
|---|---|
| `api/src/Services/Broker/BrokerCredentialMapper.php` | Source unique de la forme des identifiants par provider : quel champ de la requête alimente quelle clé, lesquels sont secrets, lesquels sont requis. Porte `build()` (création), `merge()` (reconfiguration) et `publicView()` (ce que le client a le droit de voir). |
| `api/src/Services/Broker/BrokerConnectionService.php` | Cycle de vie de la connexion : création, reconfiguration, lecture. Le contrôleur ne fait plus que router. |
| `api/src/Services/Broker/ConnectorRegistry.php` | Résolution provider → connecteur. |
| `api/src/Services/Broker/TracksLastTestError.php` | Implémentation partagée de `getLastTestError()`. |
| `api/src/Enums/CtraderEnvironment.php` | `LIVE` / `DEMO` + host associé. |
| `frontend/src/composables/useBrokerCredentialForm.js` | État partagé des dialogues : préremplissage, « vide = conservé », calcul des champs modifiés, activation du bouton. |

### Ce que l'API renvoie sur une connexion

`sanitizeConnection` retirait le chiffré mais ne renvoyait aucun identifiant, donc le dialogue ne pouvait rien préremplir. Le service expose maintenant :

- `credentials_public` — identifiants **non secrets** en clair, indexés par nom de champ de formulaire (`client_id`, `account_id_ctrader`, `environment`, `metaapi_account_id`).
- `credentials_set` — un booléen par secret (`client_secret: true`), qui dit **si** un secret est enregistré sans jamais le divulguer.

Aucun secret ne transite vers le client, dans aucun sens.

### Expurgation des messages d'erreur

Remonter le message du broker est utile, mais le message brut n'est pas toujours que ça : BingX signe **en query string**, et un `GuzzleException` embarque l'URI complète — donc potentiellement une signature HMAC et le endpoint interne. Avant de sortir vers le client, `sanitizeTestError()` :

1. remplace la valeur des paramètres sensibles (`signature`, `api_key`, `secret`, `access_token`, `token`, `password`) par `[redacted]` ;
2. expurge toute suite hexadécimale de 32 caractères ou plus (une signature par construction) ;
3. tronque à 300 caractères.

Un message court comme `cTrader API error: CH_CLIENT_AUTH_FAILURE - wrong clientSecret` passe donc intact. Le filtre s'applique à `connection_test.error` **et** à `last_sync_error`. Le texte complet reste en base pour le débogage. Reste `sync_logs.error_message`, servi par la route `/logs` : noté dans `docs/evolutions.md`.

### Robustesse

Une connexion chiffrée avec une clé `BROKER_ENCRYPTION_KEY` ayant depuis été tournée reste listable — donc reconfigurable ou supprimable — au lieu de faire échouer tout l'écran Comptes. Le déchiffrement échoué renvoie un jeu d'identifiants vide : rien n'est prérempli, tous les champs doivent être ressaisis, ce que `merge()` impose alors.

## Parcours utilisateur

1. Compte → bloc **Connexion**. Le bouton **Reconfigurer** est disponible aussi bien sur une connexion saine que sur une connexion cassée (avant, une connexion cassée n'offrait que **Supprimer**).
2. Le dialogue s'ouvre prérempli avec les identifiants non secrets. Les champs secrets sont vides avec le libellé « Inchangé — laisser vide pour conserver ».
3. L'utilisateur corrige le champ fautif seul et enregistre.
4. Toast vert si le broker accepte les identifiants, toast orange reprenant le message du broker sinon. Dans les deux cas c'est enregistré.
5. La connexion repasse `ACTIVE` et **Synchroniser** redevient utilisable, sans réimportation d'historique.

## Tests

| Fichier | Portée |
|---|---|
| `api/tests/Unit/Services/Broker/BrokerCredentialMapperTest.php` | 17 tests : mapping par provider, `trim()` des identifiants, champs requis, « vide = conservé », rejet d'un environnement inconnu, non-divulgation des secrets. |
| `api/tests/Integration/Broker/BrokerConnectionServiceTest.php` | 16 tests : remplacement partiel, préservation du curseur / de l'id / des logs, déblocage d'une connexion `ERROR`, isolation entre utilisateurs, test non bloquant (échec et exception), bascule Live↔Démo, déchiffrement impossible. |
| `api/tests/Unit/Services/Broker/CtraderConnectorTest.php` | +5 tests : résolution du host selon l'environnement, repli sur la config, remontée du message d'erreur du broker. |
| `frontend/src/components/broker/__tests__/CtraderConnectDialog.spec.js` | 9 tests : mode création vs reconfiguration, préremplissage, envoi des seuls champs modifiés, sélecteur Live/Démo. |
| `frontend/src/components/broker/__tests__/BrokerConnectionPanel.spec.js` | 6 tests : bouton Reconfigurer sur connexion saine et cassée, ouverture en mode édition, toasts de résultat du test. |

## Notes

- **Aucune migration** : `environment` vit dans le blob d'identifiants déjà chiffré, pas dans une colonne.
- Le changement de provider sur un compte existant reste hors périmètre — il faut toujours supprimer puis recréer, car les données déjà importées sont préfixées par provider (`ctrader_`, `bingx_`…).
- `BrokerSyncService` résout encore les connecteurs via son propre `match()` privé plutôt que par `ConnectorRegistry` : noté dans `docs/evolutions.md`.
