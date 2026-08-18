# 101 — Le verdict de plan figé, et sa réévaluation explicite

> Lot 3 du chantier « plans ». Complète la doc [83 — Plans de trading](83-trading-plans.md).
> C'est ce lot qui ferme le **ticket support #33**.

## Le défaut

Le verdict d'adhérence (`positions.plan_adherence`) est censé être une **photo** :
l'état du cadre au moment où la position a été prise. C'est ce qui lui donne sa
valeur — un trade pris hors plan reste marqué hors plan, même si le plan a été
élargi depuis, sinon la statistique de discipline ne veut plus rien dire.

Sauf qu'il ne bougeait qu'à **moitié** :

| Action | Le verdict |
|---|---|
| Modifier le **plan** | ne bougeait pas |
| Modifier le **trade** (`direction`, `entry_price`, `size`, `sl_points`, `symbol`, `opened_at`) | **était recalculé en douce** |

Ni figé ni vivant. Et rien à l'écran ne disait laquelle des deux règles
s'appliquait. Le rapporteur du ticket #33 a modifié son plan, n'a vu aucun
changement, et n'avait aucun moyen de le deviner : ni le badge, ni la doc, ni un
message ne mentionnaient qu'il regardait une photo.

## Ce qui bouge le verdict, désormais

Trois gestes, et trois seulement :

1. **Rattacher un plan** à un trade qui n'en avait pas → la photo est prise ;
2. **Changer de plan** → une nouvelle photo, contre le nouveau plan ;
3. **Détacher le plan** → le verdict est effacé (`plan_id`, `plan_adherence` et
   `plan_adherence_reason` repassent à `NULL`).

Tout le reste le laisse tel quel, **y compris corriger un prix d'entrée**. C'est
le point qui change : `TradeService::update()` ne recalcule plus sur
`direction`, `entry_price`, `size`, `sl_points`, `symbol` ou `opened_at`.

## `POST /trades/{id}/plan/reevaluate`

Un verdict figé sans moyen de le rafraîchir serait une impasse : l'utilisateur
resserre son plan, s'attend à voir bouger, et rien ne se passe sans un mot.

L'endpoint recalcule contre le plan **tel qu'il est maintenant**, avec les
valeurs actuelles du trade, et écrit le nouveau verdict. C'est un geste
**délibéré** : le résultat a été demandé, donc il est interprétable.

- `422 trades.error.no_plan` si le trade n'a aucun plan rattaché — il n'y a rien
  à réévaluer, et échouer bruyamment vaut mieux que ne rien faire en silence.
- `404` / `403` comme partout ailleurs.
- Ne touche **ni** `plan_id` **ni** aucun champ du trade : seulement le verdict
  et sa raison.

## Des raisons qui citent les bornes

`entry 25648 outside BUY zones` obligeait à rouvrir le plan pour savoir de
combien on avait raté, et contre quelle zone. Les raisons nomment maintenant ce
qui a servi :

| Avant | Après |
|---|---|
| `entry 25648 outside BUY zones` | `entry 25648 outside BUY zones (24000-24400)` |
| `outside trading windows` | `outside trading windows (10:00, 14:00-17:30)` |
| `outside trading windows` (jour non couvert) | `Mon not a trading day` |

Deux précisions qui ne sont pas cosmétiques :

- **Seules les zones du sens du signal** sont citées : les zones de vente n'ont
  rien à faire dans le refus d'un achat.
- **Un jour que le plan ne couvre pas** se distingue d'une heure tombée entre
  deux sessions. Ce sont deux erreurs différentes à corriger.

La liste est **plafonnée à trois bornes**, puis compte le reste (`+7`) : la
raison tient dans un `VARCHAR(255)` et un plan peut porter cinquante zones —
tout lister tronquerait la moitié utile de la phrase.

Les raisons déjà en base gardent leur forme : rien n'est réécrit rétroactivement.

## À l'écran

- **Infobulle du badge** : elle dit maintenant que le verdict est figé au
  rattachement, que modifier le plan ou le trade ne le change pas, et renvoie au
  bouton de réévaluation. Pour un trade hors plan, elle porte aussi la **raison**
  renvoyée par le back.
- **Bouton de réévaluation** (icône de rafraîchissement) à côté du badge, dans la
  colonne *Plan* de la liste des trades.

> La raison venant du back est en anglais ; sa localisation complète reste au
> backlog, comme c'était déjà le cas pour le badge.

## Tests

**Unitaires** — `PlanEvaluatorTest` : une zone refusée cite ses bornes ; seules
les zones du bon sens sont citées ; une longue liste se réduit à un compte et
tient sous 255 caractères ; un refus horaire cite l'heure et les fenêtres ; un
jour non couvert le dit.

**Intégration** — `TradeFlowTest` : éditer un trade ne déplace plus son verdict ;
rattacher un plan prend la photo ; changer de plan en reprend une ; détacher
efface les trois champs ; la réévaluation explicite déplace le verdict ; elle
tient compte d'une modification **du plan lui-même** — le cas du ticket #33 ; et
elle est refusée sur un trade sans plan.

## Points d'attention

- **Les ordres ne sont pas concernés.** `OrderService` n'a pas de chemin de
  recalcul : le verdict d'un ordre est pris à sa création et le trade en hérite à
  l'exécution. Rien à figer, donc rien à réévaluer.
- La réévaluation utilise le même `evaluatePlanAdherence()` que la création, donc
  elle profite des mêmes filtres — actif ([99](99-plan-instrument-cible.md)) et
  risque cumulé ([100](100-plan-risque-cumule.md)) compris — et s'exclut
  elle-même du cumul des positions ouvertes.
