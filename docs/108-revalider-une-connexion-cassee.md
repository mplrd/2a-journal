# 108 — Revalider une connexion broker cassée

## Le problème

Une connexion broker qui échoue trois fois de suite fait sauter le disjoncteur :
`BrokerSyncSchedulerService` appelle `markError()`, la ligne passe en `ERROR`.

Or `BrokerConnectionRepository::findDueForAutoSync()` ne sélectionne que les
connexions `ACTIVE` (`:112`). **Une connexion en `ERROR` n'est donc plus jamais
retentée**, même une fois la cause disparue côté broker. Il faut un geste
explicite de l'utilisateur.

Ce geste était impossible.

### Le verrou, des deux côtés

Rejouer la boîte de dialogue sans rien changer était refusé deux fois :

```js
// frontend/src/composables/useBrokerCredentialForm.js
const canSubmit = computed(() => {
  if (isEditing.value) {
    return Object.keys(changed.value).length > 0   // bouton mort
  }
```

```php
// api/src/Services/Broker/BrokerConnectionService::updateCredentials()
if (!$this->mapper->hasAnyField($provider, $body)) {
    throw new ValidationException('broker.error.no_credential_change', 'credentials');
}
```

Les secrets ne redescendent jamais au client : leurs champs repartent vides, et
un champ vide veut dire « garde ce qui est stocké ». En gardant le même compte
dans la liste déroulante, `changed` était donc vide, le bouton restait
désactivé, et le corps de la requête aurait de toute façon été rejeté.

### Pourquoi le contournement était pire que le mal

La seule issue restante était de **resaisir les secrets** pour que `changed` ne
soit plus vide. Sur cTrader, réémettre un jeton invalide l'`ctidTraderAccountId`
que la connexion conserve — le seul remède disponible cassait donc autre chose.

**Production, 2026-09-07** : deux connexions cTrader bloquées en `ERROR` depuis
deux jours, sans aucun moyen de les relancer.

## Ce que fait ce correctif

La règle devient : **sur une connexion saine, un envoi sans modification reste
une erreur de saisie ; sur une connexion cassée, c'est l'action utile.**

```php
$isBroken = $connection['status'] !== ConnectionStatus::ACTIVE->value;
if (!$isBroken && !$this->mapper->hasAnyField($provider, $body)) {
    throw new ValidationException('broker.error.no_credential_change', 'credentials');
}
```

Le frontend suit la même coupure, sur `connection.status` que l'API expose déjà
(`BrokerConnectionPanel.vue:41`).

`updateCredentials()` faisait déjà tout le reste : il fusionne le corps sur les
identifiants stockés — donc un corps vide les garde à l'identique —, les
re-chiffre, remet `status = ACTIVE`, efface `last_sync_error` et remet
`consecutive_failures` à zéro. La ligne redevient éligible au planificateur.

Rien n'est réémis, rien n'est retapé, et l'`ctidTraderAccountId` ne bouge pas.

### Le garde-fou conservé

Le commentaire d'origine — « un envoi vide est une erreur de l'utilisateur, pas
une remise à zéro silencieuse du statut » — reste vrai, et le test qui le
protège reste en place. Il ne s'applique simplement plus à une connexion que le
broker a déjà rejetée, où remettre le statut à zéro est précisément l'intention.

Le critère est `status !== ACTIVE`, donc il couvre aussi `PENDING` et `REVOKED`.
C'est volontaire : dans les trois cas la connexion ne synchronise pas, et
revalider est sans effet de bord — les identifiants stockés sont réécrits à
l'identique.

## Couverture des tests

| Test | Scénario | Statut |
|---|---|---|
| `BrokerConnectionServiceTest::testUpdateRevalidatesABrokenConnectionWithoutAnyChange` | corps vide sur une connexion `ERROR` → `ACTIVE`, échecs à 0, identifiants inchangés | ✅ |
| `…::testUpdateRejectsBlankSubmissionOnAHealthyConnection` | corps vide sur une connexion saine → refusé | ✅ |
| `useBrokerCredentialForm > lets a broken connection be re-submitted without changing anything` | `changed` vide + `status = ERROR` → `canSubmit` vrai | ✅ |
| `… > still demands a change on a connection that works` | `status = ACTIVE` → il faut une modification | ✅ |

Backend : **2003 tests, 5085 assertions**. Frontend : **587 tests**. Tous au vert.

## Ce que ça ne fait pas

**Rien n'alerte l'utilisateur qu'une connexion est tombée.** Le panneau broker
affiche un badge rouge, et c'est tout : aucun mail, aucune notification. En
production, deux connexions sont restées mortes deux jours sans que leur
propriétaire le sache. Le correctif rend la réparation possible, pas visible.

**Le disjoncteur reste définitif.** Aucune reprise automatique n'a été ajoutée —
une connexion qui échoue pour une raison passagère demande toujours une action
manuelle. C'est un choix conservateur : retenter en boucle une connexion dont les
identifiants sont mauvais consomme le budget de requêtes du broker, et sur une
prop firm c'est un risque réel.

Les deux manques sont au backlog (`docs/evolutions.md`).

## Ce que ça change à l'écran

Sur une connexion en erreur, le bouton d'enregistrement de la boîte de dialogue
devient actif sans qu'il y ait rien à modifier. L'enregistrer relance la
connexion.

Le libellé du bouton ne change pas encore — « Revalider la connexion » serait
plus parlant que « Enregistrer » dans ce cas, mais c'est un autre lot.
