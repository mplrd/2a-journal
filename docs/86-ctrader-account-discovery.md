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

---

# Suite — identifier un compte parmi plusieurs (2026-07-31)

## Ce que le premier jet n'a pas résolu

Au test réel, la découverte a bien listé les comptes… et le problème s'est déplacé :

> « j'ai plusieurs comptes et j'ai pas été foutu de savoir lequel correspondait auquel »
> « currency capital initial, ce genre de choses, tu peux pas ? parce que j'en ai plusieurs sur ftmo et je suis pas le seul dans ce cas »

Deux échecs distincts :

1. **Le libellé ne discrimine pas.** `1234567 — Live` ne veut rien dire pour qui a six comptes chez la même prop firm. Même en ajoutant `brokerTitleShort`, tous affichent « FTMO ».
2. **La liste contient des comptes archivés.** Le compte choisi au premier essai a échoué en `RET_ACCOUNT_DISABLED` — mais seulement *après* enregistrement de la connexion.

## Ce que dit vraiment le protocole

Point vérifié contre la [référence des model messages](https://help.ctrader.com/open-api/model-messages/), parce que l'hypothèse de départ était fausse. `ProtoOACtidTraderAccount` ne contient que **six champs** :

`ctidTraderAccountId`, `isLive`, `traderLogin`, `lastClosingDealTimestamp`, `lastBalanceUpdateTimestamp`, `brokerTitleShort`.

Conséquences :

- **Aucun champ ne marque un compte archivé.** Espérer le repérer dans la réponse de la liste était une impasse.
- **Ni le solde ni la devise n'y sont.** Ils vivent dans `ProtoOATrader`, à une auth de compte de distance.
- **Le « capital initial » n'existe pas dans l'API.** Aucun champ ne le porte. Sur un compte challenge non tradé, le **solde** en tient lieu — c'est le meilleur proxy disponible, et c'est ce qui distingue un 10 k d'un 100 k.

## Ce qui a été livré

### 1. Enrichissement par compte

`CtraderConnector::enrichAccounts()`, sur **le même websocket** déjà ouvert, pour chaque compte listé :

| Requête | Ce qu'on en tire |
|---|---|
| `ProtoOAAccountAuthReq` (2102) | Le compte est-il utilisable ? Un refus = compte indisponible. |
| `ProtoOATraderReq` (2121) | `balance` (÷ 10^`moneyDigits`), `depositAssetId`, et tous les scalaires en plus dans `details`. |
| `ProtoOAAssetListReq` (2112, **nouveau**) | `depositAssetId` → nom lisible (« EUR »). |

Le libellé devient **`FTMO — 80 000 € — 1234567 — Live`**, les segments absents étant simplement omis. Le montant est **arrondi à l'unité** : cette ligne sert à reconnaître un compte, pas à lire un solde au centime — les valeurs exactes restent dans le panneau de détails.

### 2. Les comptes archivés se déclarent eux-mêmes

L'auth de compte **est** le test d'archivage. Les comptes qui la refusent sont marqués `is_disabled` avec leur code d'erreur, affichés « indisponible (RET_ACCOUNT_DISABLED) » et **non sélectionnables** (`option-disabled`). L'échec est constaté pendant la découverte au lieu d'être découvert après enregistrement.

Distinction qui compte : seule une **erreur du broker** (`ProtoOAErrorRes`) lève le drapeau. Une panne de transport laisse le compte « inconnu » plutôt que faussement « archivé » — sinon on envoie l'utilisateur chasser un problème inexistant.

### 3. Panneau de détails, filtre, compteur

- Panneau `<dl>` des métadonnées brutes du compte sélectionné (`details`) — passe-plat de **tous** les champs scalaires, y compris ceux qu'on ne connaît pas. C'est délibérément un passe-plat et non une liste blanche : un champ inconnu est précisément ce qu'on veut voir apparaître, puisqu'aucun des six champs documentés ne marque l'archivage.
- `filter` activé sur la liste déroulante, et compteur de comptes rattachés.

### 4. Coût borné

| Garde-fou | Pourquoi |
|---|---|
| `MAX_ENRICHED_ACCOUNTS = 40` | 2 à 3 allers-retours par compte : une liste illimitée tiendrait la requête HTTP ouverte jusqu'au timeout. Au-delà, les comptes restent listés, sans enrichissement. |
| Cache de la liste d'assets par broker | Les `assetId` sont propres au broker : les comptes d'une même prop firm partagent une liste. Économise un tiers des allers-retours. |
| Dégradation gracieuse à chaque étape | L'échec d'un compte ne coûte ni son entrée, ni les comptes suivants, ni le résultat de la liste. |

## Vie privée

Le passe-plat expose désormais **deux** surfaces, pas une : les 6 champs de `ProtoOACtidTraderAccount`, plus les scalaires de `ProtoOATrader` ajoutés par l'enrichissement.

Trajet de la donnée, vérifié : elle n'est **ni journalisée** (aucun `error_log` sur le chemin de découverte), **ni persistée** côté serveur (`BrokerSyncController:67` renvoie directement le résultat), **ni stockée** côté client (une `ref` de composant, perdue à la fermeture du dialogue). Elle transite sur une requête authentifiée, s'affiche, disparaît. C'est le compte de l'utilisateur, renvoyé pour son propre access token.

**Un point d'attention pour la suite** : `ProtoOATrader` contient `swapFree` — documenté comme « If TRUE then account is Shariah compliant ». Un compte sans swap est un compte islamique : c'est une **inférence sur la religion**, donc une donnée sensible au sens de l'article 9 du RGPD. En l'état c'est sans conséquence (donnée personnelle de l'utilisateur, montrée à lui seul, jamais écrite nulle part). Mais le jour où quelqu'un journalise la réponse de découverte pour déboguer, ou l'inclut dans un export, ça devient une fuite de catégorie particulière. À garder en tête avant d'ajouter un log sur ce chemin.

Le passe-plat `is_scalar()` laissera par ailleurs passer tout champ que cTrader ajouterait plus tard. C'est assumé — c'est le mécanisme même par lequel on espère repérer ce qui marque un compte archivé — mais ça implique que la surface n'est pas figée : elle est celle de l'API amont, pas celle qu'on a choisie.

## Tests

| Fichier | Portée |
|---|---|
| `api/tests/Unit/Services/Broker/CtraderConnectorTest.php` | +5 : passe-plat de tous les champs (y compris inconnus), enrichissement solde + devise + broker, marquage des comptes refusés **sans interrompre les suivants**, liste d'assets demandée **une seule fois par broker**, panne de transport ≠ compte archivé. |
| `frontend/src/components/broker/__tests__/CtraderConnectDialog.spec.js` | +5 : libellé broker/taille/login, omission propre quand le solde manque (pas de « null »), compte archivé marqué **et** non sélectionnable, panneau de détails, compteur. |

1573 tests backend et 468 frontend verts.

## Ce qui reste ouvert

- **Le champ qui marque l'archivage n'est toujours pas identifié** — mais il n'est plus nécessaire : l'auth de compte tranche directement. Le panneau de détails reste le moyen de le découvrir s'il apparaît un jour.
- **Non testé en réel.** Comme toute la feature broker, ça n'est pas vérifiable en local (flag + vrais identifiants). Le prochain test réel doit confirmer que `brokerTitleShort` et les soldes remontent bien, et que les comptes FTMO archivés sont effectivement refusés à l'auth.
- Le rendu du libellé s'appuie sur `Intl.NumberFormat` : les stubs Vitest valident la présence du montant et de la devise, pas le rendu exact — à vérifier à l'œil dans le navigateur.
