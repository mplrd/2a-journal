# 102 — L'alerte à la saisie manuelle

> Lot 2 du chantier « plans ». Complète la doc [83 — Plans de trading](83-trading-plans.md).

## Le défaut

L'objectif de la fonctionnalité est double : filtrer les signaux d'un robot, et
**alerter le trader qui saisit à la main**. La première moitié marchait. La
seconde, non.

Le formulaire de trade n'exposait qu'un sélecteur de plan. Le verdict
n'apparaissait qu'**après enregistrement**, sous forme de badge dans la liste.
C'est un constat, pas une alerte : au moment où on le lit, le trade est pris.

## `POST /plans/{id}/evaluate`

Lecture seule. Le formulaire l'appelle pendant la saisie et affiche la réponse
sous le sélecteur de plan.

**Corps** : `account_id`, `direction`, `symbol`, `entry_price` (obligatoires),
puis `size`, `sl_points`, `opened_at` (optionnels).
**Réponse** : `{ plan_adherence, plan_adherence_reason }`.

- **N'écrit rien.** C'est tout le contrat : le formulaire appelle à chaque frappe.
- **Un formulaire à moitié rempli obtient quand même une réponse.** Sans taille
  ni stop, les filtres de risque restent simplement inactifs — la règle déjà en
  vigueur partout ailleurs. Refuser de répondre laisserait l'utilisateur sans rien
  au moment où il en a le plus besoin.
- **`opened_at` absent** = maintenant, ce qui est le bon défaut pour un ordre :
  il est jugé à l'instant où on le pose.
- Le plan doit appartenir à l'utilisateur et être actif (`403` / `404` sinon).

## Le point qui compte : le même évaluateur

`TradeService` et `OrderService` portaient chacun **leur propre copie** de
l'assemblage autour de `PlanEvaluator` : charger le plan, ramener le symbole à
l'actif, chiffrer le risque du signal et celui déjà engagé, comparer. La
simulation en aurait fait une **troisième**.

Trois copies d'un garde-fou, ce sont trois endroits où oublier un filtre — et une
prévisualisation qui contredirait le verdict enregistré une seconde plus tard
serait pire que pas de prévisualisation du tout.

Tout est donc passé dans **`PlanAdherenceEvaluator`**, seul point d'assemblage,
partagé par les trois chemins. `PlanEvaluator` reste pur en dessous ; ce service
ne fait que l'alimenter, et n'écrit jamais.

Deux différences entre les appelants sont devenues des paramètres plutôt que du
code dupliqué :

| | Trade | Ordre | Simulation |
|---|---|---|---|
| Instant d'évaluation | `opened_at` | maintenant | `opened_at` sinon maintenant |
| Clé d'erreur plan invalide | `trades.error.invalid_plan` | `orders.error.invalid_plan` | `plan.error.not_found` |

## À l'écran

Sous le sélecteur de plan, dans les formulaires **trade** et **ordre** :

- **vert** « Ce trade rentre dans le plan. »
- **ambre** « Ce trade sort du plan — *raison* »
- l'aide habituelle reste affichée tant qu'il n'y a rien à dire.

**Jamais bloquant.** Le bouton d'enregistrement ne l'attend pas et ne refuse
jamais à cause de lui : la saisie manuelle n'est pas contrainte par le plan
(cf. doc 83 § adhérence), et un formulaire qui refuserait de partir parce qu'une
vérification en lecture seule est injoignable serait une régression.

## Le composable `usePlanPreview`

Partagé par les deux formulaires. Trois comportements qui ne sont pas
cosmétiques :

- **Debounce de 400 ms** — sinon un appel par frappe sur le prix d'entrée.
- **Réponse périmée ignorée** : les requêtes se doublent pendant la frappe, et
  seule la dernière question posée décrit ce qui est à l'écran. Un compteur de
  séquence jette les réponses dépassées.
- **Échec avalé** : pas de toast, pas de message rouge. Le verdict disparaît,
  l'aide revient, la saisie continue.

## Tests

**Intégration** — `TradingPlanServiceTest` : un brouillon dans le plan est
annoncé comme tel ; hors du plan il revient avec sa raison ; **la simulation
n'écrit rien** ; un formulaire à moitié rempli obtient une réponse ; le plan d'un
autre utilisateur est refusé ; un sens manquant est refusé.

**Front** — `usePlanPreview.spec.js` : rien tant qu'aucun plan n'est choisi ;
rien tant qu'un des quatre champs obligatoires manque (un cas par champ) ; un
seul appel quand la frappe s'arrête ; la raison est rapportée ; un échec est
avalé ; une réponse arrivée après une plus récente est jetée.

Les suites existantes couvrent la refonte : `TradeServiceTest` et
`OrderServiceTest` construisent désormais leur service avec
`PlanAdherenceEvaluator`, et l'ensemble des tests d'adhérence — création, verdict
figé, réévaluation, chemin robot — tourne sur le code partagé.

## Points d'attention

- L'endpoint est derrière le **flag `plans_enabled`**, comme le reste des plans.
- La raison renvoyée est en anglais, comme le badge : sa localisation reste au
  backlog.
- `TradeService` et `OrderService` ont perdu cinq dépendances chacun au profit
  d'une seule. Leurs constructeurs restent rétro-compatibles (paramètre optionnel
  en fin de liste), mais les appels de test qui passaient les anciennes
  dépendances ont été repris.
