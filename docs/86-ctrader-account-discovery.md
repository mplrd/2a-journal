# 86 — Découverte des comptes cTrader & aides de saisie

## Contexte

Retour utilisateur direct après la livraison de la reconfiguration (`85-broker-connection-reconfigure.md`) :

> « j'ai une erreur sur mon account ID, c'est quelle info exactement ? […] il va manquer une info pour dire quel compte c'est si j'en ai plusieurs ? j'ai pensé que c'était peut être l'ID du compte mais quand j'y met l'ID d'un compte, ça me dit que ça trouve pas non plus »

Le formulaire demandait de saisir **à la main** un champ intitulé « Account ID cTrader », avec le placeholder `ex: 12345678`. Deux problèmes, qui se cumulent :

1. **Ce n'est pas le numéro de compte affiché dans la plateforme cTrader.** L'Open API authentifie avec le `ctidTraderAccountId` ; ce que la plateforme montre est le `traderLogin`. Dans le protocole ce sont deux champs distincts du même objet `ProtoOACtidTraderAccount`. Les deux sont numériques, et notre placeholder ressemblait précisément à un numéro de login → l'utilisateur a saisi le mauvais et obtenu « compte introuvable ».
2. **Avec plusieurs comptes, rien ne permettait de savoir lequel était lequel.** Même en connaissant la bonne valeur, un `ctidTraderAccountId` est opaque : il ne ressemble à rien que l'utilisateur puisse reconnaître.

Un simple libellé d'aide ne suffisait donc pas : il fallait supprimer la saisie manuelle.

## Ce qui a été livré

### 1. Découverte des comptes depuis l'access token

`ProtoOAGetAccountListByAccessTokenReq` (payloadType 2149) renvoie, pour chaque compte rattaché au token, les trois informations nécessaires :

| Champ protocole | Usage |
|---|---|
| `ctidTraderAccountId` | Ce qu'on **stocke** — la valeur dont l'API a besoin. Jamais affichée. |
| `traderLogin` | Ce qu'on **affiche** — le numéro que l'utilisateur reconnaît. |
| `isLive` | Détermine le serveur (Live/Démo) **sans le demander**. |

- Nouvelle méthode `CtraderConnector::fetchAccounts()`. Seule l'auth applicative est requise : la requête est indexée par access token, pas par compte — elle tourne donc *avant* de savoir quel compte authentifier, ce qui résout le problème de l'œuf et la poule.
- Nouvel endpoint `POST /broker/ctrader/accounts`. Accepte soit les trois identifiants applicatifs saisis, soit un `connection_id` seul : en reconfiguration, les identifiants déjà stockés sont réutilisés, donc **le bouton fonctionne sans retaper le secret**.
- Bouton **« Charger mes comptes »** dans le dialogue → liste déroulante « `1234567` — Live ». Choisir une entrée remplit l'ID **et** positionne le sélecteur Live/Démo. Un compte unique est sélectionné automatiquement.
- La saisie manuelle du `ctidTraderAccountId` reste possible en repli si la découverte est indisponible.

### 2. Remontée de l'erreur, pas de son masquage

Un échec broker est **rapporté, pas levé** : `discoverCtraderAccounts()` renvoie `{ accounts: [], error }` plutôt qu'une exception à clé générique. La raison est l'information utile — `CH_CLIENT_AUTH_FAILURE - wrong clientSecret` désigne le champ à corriger, et un bouton qui échoue en silence ne sert à rien. Le message passe par le même filtre d'expurgation que le reste (cf. doc 85).

Seule une erreur d'appelant lève une exception : identifiant manquant (`ValidationException`) ou connexion d'un autre utilisateur (`ForbiddenException`).

### 3. Infobulles d'aide sur chaque identifiant

Nouveau composant partagé `frontend/src/components/common/FieldHelpIcon.vue`, reprenant le pattern de la doc 82 (icône `pi-info-circle`, `v-tooltip`, `role="img"` + `aria-label` — l'aide au survol seul est invisible pour les lecteurs d'écran et au tactile).

Posé sur les 10 champs d'identifiants des 4 dialogues (cTrader, MetaApi, Ouinex, BingX), chaque texte indiquant **où** trouver la valeur. Celui du champ compte cTrader énonce explicitement le piège :

> « Attention : ce n'est PAS le numéro de compte affiché dans la plateforme cTrader (celui-là est le traderLogin). L'API a besoin du ctidTraderAccountId, un identifiant interne différent. Utilisez « Charger mes comptes » pour le remplir automatiquement. »

Le placeholder trompeur `ex: 12345678` a été remplacé par `ctidTraderAccountId (rempli automatiquement)`.

## Tests

| Fichier | Portée |
|---|---|
| `api/tests/Unit/Services/Broker/CtraderConnectorTest.php` | +4 : liste des comptes (mapping des 3 champs, payloadType 2149, access token transmis), tolérance `isLive` absent, entrées sans id ignorées, propagation de l'erreur broker. |
| `api/tests/Integration/Broker/BrokerConnectionServiceTest.php` | +5 : découverte avec identifiants saisis, réutilisation des secrets stockés via `connection_id`, identifiants requis sans connexion, isolation entre utilisateurs, remontée de la raison sans exception. |
| `frontend/src/components/common/__tests__/FieldHelpIcon.spec.js` | 3 : `aria-label` (pas seulement le survol), affordance partagée, `data-testid`. |
| `frontend/src/components/broker/__tests__/CtraderConnectDialog.spec.js` | +8 : bouton désactivé sans identifiants, liste étiquetée par `traderLogin`, remplissage ID + serveur dérivé du compte choisi, affichage de la raison d'échec, `connection_id` envoyé en reconfiguration, présence et contenu des infobulles, placeholder trompeur supprimé. |

1568 tests backend et 463 frontend verts.

## Notes

- **Aucune migration**, aucun changement de schéma.
- Le `traderLogin` est conservé en **chaîne** et non en entier : il peut dépasser la plage `int` de PHP chez certains brokers et n'est de toute façon jamais utilisé dans un calcul.
- Un désaccord Live/Démo produit la **même** erreur « compte introuvable » qu'un mauvais ID. En dérivant le serveur de `isLive`, on supprime les deux causes d'un coup — c'est probablement ce qui bloquait aussi la validation live en attente (cf. `85` et le checkpoint cTrader).
