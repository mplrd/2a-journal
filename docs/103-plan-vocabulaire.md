# 103 — Le vocabulaire des plans

> Lot 5 du chantier « plans ». Complète la doc [83 — Plans de trading](83-trading-plans.md).

## Le défaut

Les libellés de l'écran décrivaient des **mécanismes** au lieu d'**intentions**, et
une partie d'entre eux était devenue **fausse** au fil des lots précédents. Ce lot
ne touche aucune logique : il reprend les textes et pose un test qui empêche la
dérive de recommencer.

## Ce qui était devenu faux

Ces textes datent d'avant la doc [102](102-plan-alerte-a-la-saisie.md) : ils
décrivent une fonctionnalité réservée aux robots, alors que le plan alerte
désormais aussi le trader qui saisit à la main.

| Clé | Le problème |
|---|---|
| `plan.subtitle` | « le cadre dans lequel un **robot** a le droit d'entrer » — ne parlait que du robot. |
| `plan.archive_confirm` | « ne sera plus proposé **aux robots** » — il n'est plus proposé non plus dans les formulaires de trade et d'ordre. |
| `trades.plan_hint`, `orders.plan_hint` | « Purement indicatif, ne bloque rien » s'affiche **juste au-dessus** du verdict introduit au lot 2, et se lit comme une propriété des plans alors que la phrase ne vaut que sur ce formulaire. Sur le chemin robot, le plan bloque pour de bon. |

Les deux `plan_hint` disent maintenant les deux moitiés : « Ici rien n'est bloqué
— un robot, lui, refuserait le signal. »

## Un mot par notion

Le même verdict s'affiche sur trois écrans : le badge d'un trade, celui d'un
ordre, et le journal d'événements du webhook. Il s'y écrivait de deux façons.

| Notion | Avant | Après |
|---|---|---|
| Signal refusé | « Hors plan » (badge) / « Hors **du** plan » (journal webhook) | « Hors plan » partout |
| Aucun plan rattaché | « Sans plan » (badge) / « **Aucun** plan » (sélecteur) | « Sans plan » partout |

L'anglais ne présentait pas cette dérive ; seul `fr.json` a bougé.

## Les remarques de Robin

**Les zones** — `plan.zones_hint` énonçait la règle de comparaison. Il énonce
maintenant l'intention, avec un exemple : *« Les prix auxquels vous acceptez
d'entrer : "je n'achète le DAX qu'entre 24 000 et 24 400". »*

**La règle des bornes** — nouvelle clé `plan.zone_bounds_hint`. Rien à l'écran ne
disait que la borne basse doit être la plus basse quel que soit le sens, et
`PlanEvaluator::checkZones()` réordonne silencieusement (`min`/`max`) : sur une
zone de vente on saisit naturellement « de 24 400 à 24 000 » et on ne comprend pas
ce qui s'est passé. Le texte est joint à l'aide des zones dans **la même bulle**
plutôt que sur une seconde icône : la surprise porte sur le même champ.

**Les plages horaires** — `plan.field.windows` disait « Plages horaires
(sessions) », ce qui laisse croire qu'on décrit ses sessions de marché. C'est
devenu « **Plages de validité du plan** », avec une nouvelle aide
`plan.windows_hint` : *« Les moments où le plan s'applique — pas vos sessions de
marché. »*

**La valeur du point** — l'infobulle du risque nommait « Mon compte › Mes actifs »
en **texte mort**. Voir plus bas : la réponse n'est pas un lien.

## La valeur du point : ce que le plafond veut dire

Un plafond de risque est un **pourcentage du capital**. Ce qui le convertit en
argent, c'est `point_value(actif, compte)` : le réglage du compte quand il
existe, la valeur de l'actif sinon (`SignalRiskCalculator.php`). Or **un plan n'a
pas de compte** — il vise un actif, le compte arrive avec le signal.

Conséquence : le même « 1 % » peut représenter deux montants différents sur deux
comptes. Rien à l'écran ne le disait.

Deux tentatives ont été écartées avant d'y arriver :

| Tentative | Pourquoi non |
|---|---|
| Une phrase « la valeur du point se règle actif par actif » | **Fausse** : elle escamote le compte, qui est précisément la dimension qui rend le chiffre ambigu. |
| Un lien vers *Mon compte › Mes actifs* | L'éditeur est une modale qui tient un brouillon non enregistré ; le lien devait s'ouvrir dans un nouvel onglet pour ne pas le jeter. Un contournement d'un défaut d'écran, pas une décision. |

Ce qui est livré : une ligne **en lecture seule** sous les deux champs de risque,
dès qu'un actif est choisi.

- valeurs identiques partout → *« Valeur du point du DAX 40 : 25 sur tous vos comptes. »*
- valeurs différentes → *« Valeur du point du DAX 40 : 25 (Demo FTMO) · 1 (Live IB)
  — un même plafond n'engage donc pas le même montant selon le compte. »*

**Aucune édition ici.** Le plan n'ayant pas de compte, il n'y a pas *une* cellule
à modifier : il y en aurait autant que de comptes, et l'éditeur de plan
deviendrait un second éditeur d'actifs à tenir d'accord avec le premier. L'édition
reste dans la matrice de *Mes actifs*.

La logique vit dans `pointValueSummary()` (`utils/planForm.js`), pure et testée :
repli sur la valeur de l'actif là où aucun réglage de compte n'existe, effondrement
sur un seul chiffre quand tous les comptes s'accordent, et **1 plutôt qu'« inconnu »**
quand rien n'est stocké — les deux colonnes sont `NOT NULL DEFAULT 1`. La ligne
disparaît sans bruit s'il n'y a pas encore de compte, et le chargement des données
ne peut pas faire échouer l'écran.

## Deux étiquettes qui se ressemblaient trop

Dans la colonne « Filtres », `plan.tag.risk` affichait « ≤ 1 % » à côté de
« cumul ≤ 5 % » : la première n'était qualifiée nulle part. Elle affiche
désormais « **par trade** ≤ 1 % ». De même `plan.field.max_plan_risk`, « Risque
max cumulé », ne disait pas cumulé sur quoi → « Risque max cumulé **sur le plan** ».

Et `plan.error.invalid_zone`, « Zone invalide (sens et bornes > 0 requis) », est
passé en français lisible.

## Le test qui tient la suite

`frontend/src/__tests__/locales.spec.js` (nouveau) transforme en test ce qui
n'était qu'une étape manuelle de la skill `/check-i18n` :

**Sur les deux fichiers de langue**
- ils exposent **exactement** le même jeu de clés ;
- aucune traduction n'est vide ;
- une clé porte les **mêmes interpolations** des deux côtés — une traduction qui
  a perdu son `{count}` rend une phrase sans le nombre, qui se lit comme un texte
  fini plutôt que comme un bug.

**Sur le vocabulaire des plans**
- le verdict s'écrit pareil sur les trois écrans (c'est le test qui a échoué en
  premier, sur « Hors du plan ») ;
- l'introduction et la confirmation d'archivage ne présentent plus les plans
  comme une affaire de robots ;
- la règle des bornes et l'objet des plages existent dans les deux langues ;
- **toute phrase qui énonce une valeur du point nomme le compte** — c'est le test
  qui empêche de réécrire « actif par actif » ;
- les deux étiquettes de risque restent distinguables sans leur infobulle.

Une détection des clés **orphelines** a été écartée : sur 1 225 clés elle en
signale 460, presque toutes construites dynamiquement (`plan.error.*` renvoyées
par l'API en `message_key`, `webhook.…reject_reason.` + code, `plan.tag.zones`
via littéral de gabarit). Un garde-fou qui crie faux 460 fois n'est pas un
garde-fou.

## Bilan

13 clés retouchées, 5 créées (`plan.zone_bounds_hint`, `plan.windows_hint`,
`plan.point_value_uniform`, `plan.point_value_varies`, `plan.point_value_entry`),
dans `fr.json` et `en.json`. Une fonction pure ajoutée (`pointValueSummary`).
Aucun changement de logique métier : rien de ce lot n'entre dans une décision
d'acceptation ou de refus.

## Ce que ce lot ne couvre pas

La **raison du refus** — le texte le plus lu de la fonctionnalité, présent dans le
badge, son infobulle, l'alerte de saisie et le journal du webhook — reste en
**anglais**, en dur, telle que `PlanEvaluator` la produit :

```
symbol GER40 not covered (plan targets DAX)
direction BUY not allowed (plan allows SELL)
entry 25648 outside BUY zones (24000-24400)
Mon not a trading day
outside trading windows (10:00, 14:00-17:30)
risk 1.500% exceeds plan max 1.000%
plan risk 5.300% (open 4.000% + signal 1.300%) exceeds plan max 5.000%
an open position under the plan has no stop: plan risk unbounded
```

Ce n'est pas un travail de vocabulaire : il faut que le back renvoie une **clé et
des paramètres** au lieu d'une phrase, et trancher le sort des raisons **déjà
écrites en base** (`positions.plan_adherence_reason`, `VARCHAR(255)`) — le verdict
étant figé (doc [101](101-plan-verdict-fige.md)), elles ne peuvent pas être
régénérées. Porté au backlog dans [evolutions.md](evolutions.md).
