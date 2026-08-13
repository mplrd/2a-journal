# 91 — Identifiants d'application partagés entre connexions broker

> Évolution #23 de `docs/specs/trading-journal-evolutions.md`.
> Livré le 2026-08-10. Corrigé le même jour par la migration 037 — voir
> « Renouvelé, pas écrit ».

## Le problème

Un utilisateur possédant deux comptes cTrader saisissait deux fois `client_id`,
`client_secret`, `access_token` et `refresh_token`. Seul le
`ctidTraderAccountId` diffère réellement entre les deux connexions : les quatre
autres valeurs appartiennent à **son application cTrader**, pas à un compte.

Trois conséquences :

1. Double saisie de quatre secrets longs, copiés-collés d'un portail broker.
2. Toute rotation de secret devait être rejouée à la main sur chaque connexion.
   Une connexion oubliée part en `ERROR` à sa passe suivante, et `sync()` refuse
   tout ce qui n'est pas `ACTIVE` : la synchro s'arrête sans que rien ne le
   signale.
3. Un refresh OAuth par connexion là où un seul suffit — ce qui pèse sur le
   budget de requêtes de l'évolution #22.

## Fonctionnalités

### Une seule saisie par broker

À la première connexion cTrader, rien ne change : les quatre identifiants sont
demandés. Aux suivantes, ils sont **déjà enregistrés** — la modale s'ouvre avec
le bloc d'identifiants replié et un bandeau qui le dit. Il ne reste que le
compte broker à choisir, via le sélecteur de comptes existant (doc 86), qui
fonctionne lui aussi sans ressaisie.

Le bloc reste accessible d'un clic (« Modifier les identifiants ») : faire
tourner un secret ne doit pas obliger à retrouver la première connexion.

### Une rotation, toutes les connexions

Modifier `client_secret` depuis n'importe quelle connexion le modifie pour
toutes celles du même broker. C'est l'objectif — et c'est aussi le seul piège
que le partage introduit. **Le bandeau nomme donc toujours le nombre de
connexions concernées**, et en reconfiguration ajoute que l'édition les touche
toutes. Sans cette phrase, modifier un token depuis la connexion n°2
réécrirait silencieusement la n°1.

### Déconnecter efface vraiment

Supprimer la dernière connexion d'un broker supprime aussi ses identifiants
enregistrés. Sans cela, « déconnecter » laisserait en base un access token et
un client secret utilisables pour un broker que l'utilisateur croit débranché.
Tant qu'il reste une connexion, la ligne est conservée : elle s'en sert.

### Les providers qui ne partagent rien ne bougent pas

Chez Ouinex et BingX, la clé d'API **est** le compte : il n'y a rien à hisser
au-dessus de la connexion. Ils traversent le chantier sans changement de
comportement — c'est le test de la généricité, et il est couvert par des tests
dédiés.

| Provider | Partagé (niveau utilisateur) | Identité (niveau connexion) |
|---|---|---|
| cTrader | `client_id`, `client_secret`, `access_token`, `refresh_token` | `ctid_trader_account_id` (+ `environment`) |
| MetaApi | `api_token` | `metaapi_account_id` |
| Ouinex | — | `service_api_key` |
| BingX | — | `api_key` |

## Choix d'implémentation

### Une capacité déclarée par provider, pas un cas particulier cTrader

Tout découle d'un flag `shared` dans `BrokerCredentialMapper::SPEC`, à côté du
flag `identity` déjà en place. Deux nouvelles méthodes en dérivent :

- `sharedFields($provider)` — les champs de formulaire partagés, dans l'ordre ;
- `split($provider, $credentials)` — coupe un tableau complet en `shared` /
  `own`.

Un provider qui ne déclare rien voit tout tomber dans `own`, c'est-à-dire
exactement son comportement d'avant. Idem pour un provider inconnu : la
tolérance de `publicView()` est reprise telle quelle.

### `environment` cTrader reste sur la connexion — volontairement

Le serveur (Live/Démo) sur lequel vit un compte est une propriété du **compte**,
pas de l'application : un même access token liste des comptes des deux côtés.
Le partager casserait une configuration mixte.

### Le stockage : `BrokerCredentialStore`

Nouvelle table `broker_credentials` (`user_id`, `provider`,
`credentials_encrypted`, `credentials_iv`), unique sur `(user_id, provider)` —
c'est cette contrainte qui fait du partage un fait de schéma et non une
convention. Même chiffrement que `broker_connections`.

`BrokerCredentialStore` est **le seul objet qui sait que les identifiants
vivent dans deux lignes**. Il expose :

| Méthode | Rôle |
|---|---|
| `forConnection($connection)` | Le tableau complet à donner à un connecteur : ligne de connexion + ligne partagée |
| `ownOf($connection)` | Ce que porte la seule connexion |
| `sharedFor($userId, $provider)` | Les identifiants d'application de l'utilisateur |
| `allSharedFor($userId)` | Idem, tous providers confondus |
| `store($userId, $provider, $credentials, $fromRefresh = false)` | Écrit la ligne partagée, rend le blob chiffré de la connexion. `$fromRefresh` réservé au renouvellement de token — voir plus bas |
| `sharedRenewedWithin(...)` | Voir « la course au refresh » plus bas |
| `forget($userId, $provider)` | Oublie les identifiants d'un broker |

**`ConnectorInterface` est inchangée** : les connecteurs continuent de recevoir
un unique tableau plat. C'était la contrainte de conception principale — les
quatre connecteurs, leurs tests et le CLI n'ont pas eu à bouger.

Les lectures sont volontairement tolérantes : une ligne chiffrée sous une
`BROKER_ENCRYPTION_KEY` ayant tourné doit rester listable (donc
reconfigurable ou supprimable) plutôt que de faire tomber l'écran des comptes.

### `create` utilise `merge()`, plus `build()`

`BrokerConnectionService::createConnection()` pose les identifiants partagés de
l'utilisateur en socle et applique le corps de requête par-dessus. Un champ
vide garde la valeur enregistrée — la règle de la reconfiguration, appliquée à
la création. Quand rien n'est partagé, le socle est vide et le comportement est
au caractère près celui de `build()`, erreurs de validation comprises.

### La course au refresh — une régression évitée

> **⚠️ Cette section décrit un garde-fou qui ne suffisait pas.** La fenêtre de
> fraîcheur ci-dessous est une lecture d'horloge : elle ne départage que des
> synchros **décalées**. Deux workers démarrés dans la même seconde lisent tous
> les deux « personne n'a renouvelé récemment » et consomment le même token.
> Constaté en environnement de test le 2026-08-13 — une connexion en échec à
> **chaque** passe, la perdante alternant. Remplacé par une réservation
> atomique (`refreshing_since`, migration 041) : voir
> [93](93-course-refresh-token-partage.md). La fenêtre de 300 s reste en place
> et garde son utilité — elle évite la réservation elle-même quand un
> renouvellement vient d'aboutir. Section conservée telle quelle pour
> l'historique du raisonnement.

cTrader **fait tourner le refresh token à chaque usage**. Une fois ce token
partagé, deux connexions synchronisées dans le même tick de scheduler
présenteraient le même token : la seconde utiliserait un token déjà consommé
par la première, le refresh lèverait une exception, la synchro échouerait et la
connexion basculerait en `ERROR`. Avant le partage, chaque connexion avait son
propre token et le problème n'existait pas — c'est donc bien le partage qui
créait la course, et c'est ici qu'elle se ferme.

`BrokerSyncService` saute le refresh quand un **renouvellement de token a
réussi** il y a moins de `SHARED_CREDENTIAL_FRESHNESS_SECONDS` (300 s) :

- assez large pour couvrir un tick de scheduler entier, là où deux connexions
  du même utilisateur se marchent dessus ;
- très en deçà de la durée de vie d'un access token cTrader, donc jamais au
  risque de travailler avec un token périmé.

Le même saut est le gain de l'évolution #22 dans le cas concurrent : un appel
de refresh pour tout le provider d'un utilisateur, au lieu d'un par connexion.
Rien n'est sauté quand rien n'est partagé.

#### Renouvelé, pas écrit — le correctif de la migration 037

La première version de cette garde s'appuyait sur `updated_at`, c'est-à-dire sur
la dernière **écriture** de la ligne partagée. Elle confondait deux situations
opposées :

- une autre connexion vient de renouveler le token → il est frais, sauter est
  correct ;
- **l'utilisateur vient de saisir ses identifiants** → l'access token collé
  depuis le portail broker peut dater de plusieurs mois, et sauter le refresh
  est exactement l'inverse de ce qu'il faut faire.

Le second cas est celui de la toute première synchro après une connexion, soit
le moment où le refresh est le plus nécessaire. La garde le supprimait.

La colonne `broker_credentials.refreshed_at` (migration 037) sépare les deux :
seul un renouvellement réussi l'écrit. `BrokerCredentialStore::store()` prend un
`$fromRefresh` à `false` par défaut, et **`BrokerSyncService` est le seul appelant
à le passer à `true`** — création et reconfiguration laissent la colonne
intacte. `NULL` (jamais renouvelé) ferme la fenêtre, donc rend le comportement
d'avant la 036 : on refresh.

L'âge est calculé **en SQL** (`TIMESTAMPDIFF(SECOND, refreshed_at,
UTC_TIMESTAMP())`) et non en PHP. `refreshed_at` est un `DATETIME` écrit et relu
en UTC, jamais converti dans le fuseau de session — même convention que
`broker_connections.syncing_since`. La version précédente lisait un `TIMESTAMP`
(`updated_at`), relu lui dans le fuseau de session.

### Migration 036 — purge assumée

La migration crée la table **puis supprime les connexions existantes**.
Décision du 2026-08-09 : les connexions d'avant stockent tout dans leur propre
blob, et plutôt que de deviner quelle partie est partageable, on repart de zéro
— personne n'utilise le broker hors env de test, la reconnexion prend trente
secondes.

Conséquence acceptée : `sync_logs` est en `ON DELETE CASCADE` sur
`broker_connections`, l'historique des passes part avec. **Les trades et
positions sont rattachés aux comptes, pas aux connexions : ils survivent
intégralement.** Le curseur de synchro étant perdu, la passe suivante rebalaie
l'historique sans rien réimporter (déduplication sur `external_id`).

Le runner trace les migrations par nom de fichier : ce `DELETE` s'exécute une
fois, jamais à chaque boot.

### API

Un endpoint s'ajoute, deux champs apparaissent sur chaque connexion.

```
GET /broker/credentials
```

```json
{
  "success": true,
  "data": {
    "CTRADER": {
      "credentials_public": { "client_id": "30528" },
      "credentials_set": { "client_secret": true, "access_token": true, "refresh_token": true },
      "credentials_shared_fields": ["client_id", "client_secret", "access_token", "refresh_token"],
      "credentials_shared_count": 2
    }
  }
}
```

C'est ce que lit la modale de **création** — elle ne dispose d'aucune connexion
d'où tirer un préremplissage. Aucune valeur secrète ne traverse : chacune
revient en drapeau posé/absent, comme sur les connexions.

Les payloads de connexion (`GET /broker/connections`, création,
reconfiguration) portent désormais `credentials_shared_fields` et
`credentials_shared_count`. Un provider qui ne partage rien renvoie `[]` et `0`.

### Front — tout reste dans la modale de synchro

Décision d'ergonomie : le partage est un fait de stockage, il ne doit imposer
**aucune navigation supplémentaire**. Pas d'écran « mes identifiants brokers ».

`useBrokerCredentialForm` gagne une troisième situation. Il gérait « créer »
(tout est requis) et « reconfigurer » (un champ vide conserve). Il gère
maintenant « créer alors que les identifiants sont déjà partagés » : les champs
partagés se comportent comme en reconfiguration, les autres comme en création.
Il expose `isStored(field)`, `storedFields` et `sharing` — le composable reste
sans HTTP, la récupération est au niveau du panneau.

`BrokerConnectionPanel` charge les identifiants partagés **à l'ouverture d'une
modale de connexion**, pas au montage : le panneau est rendu une fois par
compte, et la plupart des sessions n'ouvrent jamais de modale. Un échec est
avalé : le préremplissage est un confort, le perdre ne doit jamais coûter la
possibilité de connecter un compte à la main.

## Couverture des tests

### Backend — unitaires

`api/tests/Unit/Services/Broker/BrokerCredentialMapperTest.php`

| Test | Scénario | Statut |
|---|---|---|
| `testSharedFieldsOfCtraderAreTheFourAppCredentials` | Les 4 identifiants d'app sont partagés, pas le compte | ✅ |
| `testSharedFieldsOfMetaApiIsTheApiToken` | MetaApi partage son token | ✅ |
| `testOuinexAndBingxShareNothing` | Aucun partage là où la clé est le compte | ✅ |
| `testSharedFieldsOfUnknownProviderIsEmpty` | Provider inconnu : rien de partagé | ✅ |
| `testCtraderEnvironmentStaysWithTheConnection` | Live/Démo est une propriété du compte | ✅ |
| `testSplitCtraderSeparatesAppCredentialsFromTheAccount` | Découpe shared / own | ✅ |
| `testSplitOmitsAnAbsentOptionalSharedCredential` | Pas de clé vide écrite dans la ligne partagée | ✅ |
| `testSplitLeavesEverythingOnTheConnectionForBingx` | BingX inchangé | ✅ |
| `testSplitOfUnknownProviderKeepsEverythingOnTheConnection` | Provider inconnu inchangé | ✅ |

`api/tests/Unit/Services/Broker/BrokerSyncServiceTest.php`

| Test | Scénario | Statut |
|---|---|---|
| `testSyncSkipsTheRefreshWhenTheSharedTokenWasJustRenewed` | La course au refresh token est fermée | ✅ |
| `testSyncStillRefreshesWhenTheSharedTokenIsOld` | Hors fenêtre, le refresh a bien lieu | ✅ |
| `testSyncRefreshesWhenNothingIsSharedAtAll` | BingX/Ouinex : chemin de refresh inchangé | ✅ |
| `testSyncRefreshesOnTheFirstPassAfterCredentialsAreTyped` | Migration 037 : une saisie n'ouvre pas la fenêtre de saut | ✅ |

### Backend — intégration

`api/tests/Integration/Broker/BrokerSharedCredentialsTest.php` (24 tests)

| Test | Scénario | Statut |
|---|---|---|
| `testASecondCtraderConnectionNeedsOnlyItsAccountId` | Le cœur de la feature : plus de ressaisie | ✅ |
| `testTheFirstConnectionStillRequiresEveryCredential` | Validation de création intacte | ✅ |
| `testAppCredentialsAreStoredOnceForTheUser` | Une seule ligne par (user, provider) | ✅ |
| `testTheSharedRowHoldsTheAppCredentialsAndNotTheAccount` | Contenu de la ligne partagée | ✅ |
| `testTheConnectionRowKeepsOnlyWhatIdentifiesTheAccount` | Pas de copie du secret sur la connexion | ✅ |
| `testRotatingASecretFromOneConnectionReachesTheOther` | Une rotation, toutes les connexions | ✅ |
| `testRotatingASecretLeavesEachConnectionItsOwnAccount` | Les identités ne se mélangent pas | ✅ |
| `testAnotherUsersCredentialsAreNeverReached` | Cloisonnement par utilisateur | ✅ |
| `testSharedCredentialsAreEncryptedAtRest` | Rien en clair en base | ✅ |
| `testBingxKeepsEveryCredentialOnItsOwnConnection` | Aucune ligne partagée créée | ✅ |
| `testASecondBingxConnectionStillRequiresItsOwnKey` | Pas d'héritage là où la clé est le compte | ✅ |
| `testTheConnectionViewNamesHowManyConnectionsShareTheCredentials` | Données du bandeau | ✅ |
| `testALoneConnectionReportsSharingWithItselfOnly` | Compteur à 1 | ✅ |
| `testABingxConnectionReportsNoSharingAtAll` | Aucun bandeau côté BingX | ✅ |
| `testTheViewPrefillsIdentifiersHeldOnlyByTheSharedRow` | `client_id` prérempli, secrets jamais renvoyés | ✅ |
| `testSharedCredentialsForUserDescribesWhatIsAlreadyStored` | Charge utile de `GET /broker/credentials` | ✅ |
| `testSharedCredentialsForUserIsEmptyWithoutAnyConnection` | Réponse vide sans connexion | ✅ |
| `testSharedCredentialsForUserIgnoresProvidersThatShareNothing` | BingX absent de la réponse | ✅ |
| `testDisconnectingOneOfTwoConnectionsKeepsTheSharedCredentials` | La ligne survit tant qu'on s'en sert | ✅ |
| `testDisconnectingTheLastConnectionDropsTheSharedCredentials` | Déconnecter efface vraiment | ✅ |
| `testTypingCredentialsDoesNotCountAsARenewal` | Migration 037 : une saisie ne date pas un renouvellement | ✅ |
| `testReconfiguringDoesNotCountAsARenewalEither` | Idem pour une reconfiguration | ✅ |
| `testARenewalIsWhatOpensTheSkipWindow` | Seul `fromRefresh: true` ouvre la fenêtre | ✅ |
| `testAProviderWithoutSharedCredentialsNeverSkips` | BingX : jamais de saut | ✅ |

### Frontend

`frontend/src/composables/__tests__/useBrokerCredentialForm.spec.js` (13 tests) —
les trois modes du formulaire, le préremplissage, les secrets jamais renvoyés
en clair, les données du bandeau, et la tolérance à l'absence de source
partagée.

`frontend/src/components/broker/__tests__/CtraderConnectDialog.spec.js`
(9 tests ajoutés)

| Test | Scénario | Statut |
|---|---|---|
| `folds the app credentials away when they are already stored` | Bloc replié | ✅ |
| `connects with nothing but the account picked` | Un seul champ à remplir | ✅ |
| `says how many connections the stored credentials already feed` | Bandeau en création | ✅ |
| `unfolds to a prefilled client id and secrets marked as stored` | Contenu après dépliage | ✅ |
| `can look accounts up without retyping a stored secret` | Sélecteur de comptes actif | ✅ |
| `sends only the account when nothing else was touched` | Corps de requête | ✅ |
| `shows no fold and no banner for a first connection` | Première connexion inchangée | ✅ |
| `warns before an edit that reaches every connection` | Bandeau d'avertissement | ✅ |
| `stays quiet when the connection is the only one on those credentials` | Pas de bruit inutile | ✅ |

`frontend/src/components/broker/__tests__/MetaApiConnectDialog.spec.js`
(7 tests, fichier créé) — le second provider partagé, qui prouve que le front
n'est pas non plus taillé pour cTrader.

`frontend/src/components/broker/__tests__/BrokerConnectionPanel.spec.js`
(5 tests ajoutés) — chargement paresseux, transmission par provider, absence de
transmission en reconfiguration, et ouverture de la modale malgré un échec de
récupération.

**Total : 1031 tests unitaires backend, 728 tests d'intégration backend,
540 tests frontend — tous verts.** (Les 5 tests ajoutés par la migration 037 sont
inclus ; le frontend n'a pas bougé pour ce correctif.)

## Reste à faire

- **Jamais validé contre un vrai cTrader.** Comme tout le domaine broker, le
  flag et les identifiants réels rendent la vérification impossible en local :
  elle ne peut avoir lieu qu'en env de test. Ce qu'il faut y regarder en
  priorité : la création d'une deuxième connexion sans ressaisie, le fait
  qu'une passe de synchro concurrente ne provoque plus d'échec de refresh, et —
  depuis la 037 — que la **première** synchro après une saisie renouvelle bien
  le token au lieu de le sauter.
- Le saut de refresh ne dédoublonne que dans la fenêtre de 300 s. Réduire le
  nombre d'appels dans le cas général (une connexion qui synchronise toutes les
  15 minutes redemande un token à chaque passe) reste du ressort de
  l'évolution #22.
