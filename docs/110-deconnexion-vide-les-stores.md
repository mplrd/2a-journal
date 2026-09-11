# 110 — La déconnexion vide tous les stores de données utilisateur

## Fonctionnalités

### Le défaut

La déconnexion ramène à la page de login par une **navigation interne**, pas par
un rechargement (`AppLayout.vue`). Tout ce que les stores Pinia contiennent reste
donc en mémoire dans l'onglet.

`logout()` et `deleteAccount()` ne vidaient que six stores : comptes, symboles,
positions, ordres, trades, facturation. Constaté le 2026-09-11 sur le build local
(compte démo, plusieurs pages visitées, puis déconnexion), **ce qui restait en
mémoire après la déconnexion** :

| Store | Contenu resté | Conséquence |
|---|---|---|
| `stats` | vue d'ensemble, graphiques, ventilations | chiffres du précédent affichés le temps du rechargement |
| `setups` | 9 setups, `loaded = true` | **jamais rechargés** : l'utilisateur suivant voit ceux du précédent |
| `customFields` | 1 définition, `loaded = true` | **jamais rechargés** |
| `notebook` | 5 notes | notes du précédent en mémoire |
| `symbolAccountSettings` | 15 valeurs de point, `loaded = true` | **jamais rechargées** : les plans du suivant chiffrent leur risque avec les valeurs de point du précédent |
| `support` | tickets (aucun sur la démo) | tickets du précédent en mémoire |

Les trois stores marqués « jamais rechargés » protègent leur chargement par un
drapeau `loaded` (`if (loaded.value && !force) return`) : tant qu'il reste vrai,
ils ne rappellent pas l'API. D'où la gravité — le problème ne se limitait pas à un
affichage fugace, les données d'un utilisateur étaient **servies et utilisées**
pour le suivant.

### Ce qui change

À la déconnexion comme à la suppression de compte, **tous les stores portant des
données utilisateur sont vidés**, drapeaux `loaded` compris. Revérifié après
correctif, dans les mêmes conditions : tous vides, tous `loaded = false`. Le
prochain utilisateur recharge tout depuis l'API.

### Hors périmètre

- **`features`** n'est pas vidé : ses drapeaux (plans, robots, synchro broker)
  sont communs à la plateforme, identiques pour tout utilisateur.
- **Les autres onglets** ouverts sur la même session gardent leur mémoire et leur
  token d'accès (15 min). Leur prochain renouvellement échoue — la déconnexion a
  supprimé tous les refresh tokens côté serveur — et les renvoie au login par un
  vrai rechargement, qui vide la mémoire. Une synchronisation de la déconnexion
  entre onglets serait un autre chantier.
- **La session expirée** (`api.js`) passe déjà par `window.location.href`, donc
  un rechargement complet : rien à corriger.

## Choix d'implémentation

### Une liste unique dans le store `auth`

`stores/auth.js` déclare `USER_STORES`, la liste des stores de données
utilisateur, et `resetUserStores()` qui appelle leur `$reset()`. `logout()` et
`deleteAccount()` l'appellent tous les deux, au lieu de recopier chacun sa propre
liste. Les deux copies étaient identiques, mais figées : la doc 09 en décrivait
cinq, `billing` s'y était ajouté, et les stores créés ensuite n'y étaient jamais
entrés.

Dans `logout()`, l'utilisateur et les tokens sont effacés **avant** le reset des
stores : si un reset levait une erreur, la session serait déjà coupée. Dans
`deleteAccount()`, rien n'est vidé si l'API refuse la suppression — le compte
existe toujours.

### Un garde-fou plutôt qu'une mémoire

Une liste manuelle se périme au prochain store ajouté. Le test
`logout-resets-user-stores.spec.js` charge **tous les fichiers de `stores/`**
(`import.meta.glob`), instancie chaque store sauf `auth` et `features`, et échoue
si l'un d'eux n'est pas vidé à la déconnexion ou à la suppression de compte — avec
le nom du store en cause. Vérifié en retirant `stats` de la liste : le test échoue
sur `store "stats" is not reset on logout`.

### `$reset()` partout

`billing` et `symbolAccountSettings` exposaient `reset()`, les dix autres
`$reset()`. Les deux ont été renommés : une seule convention, et le garde-fou peut
appeler la même méthode sur tous. Il vérifie aussi que chaque store implémente
**son propre** `$reset()` : un store en syntaxe setup qui ne le définit pas hérite
de celui de Pinia, qui lève une erreur.

## Couverture des tests

`frontend/src/__tests__/logout-resets-user-stores.spec.js`

| Test | Scénario | Statut |
|---|---|---|
| `finds the user stores to guard` | le glob trouve bien les stores (plancher : accounts, stats, setups, symbolAccountSettings), sans `auth` ni `features` | ✅ |
| `on logout › calls the own $reset of every user store` | chaque store de `stores/` hors exclusions implémente `$reset()` et le voit appelé | ✅ |
| `on logout › leaves nothing of the previous user in the stores that were left behind` | stats, setups, customFields, notebook, support, symbolAccountSettings et billing remplis → vides après déconnexion, `loaded` à faux, `getPointValue` nul | ✅ |
| `on deleteAccount › calls the own $reset of every user store` | idem, à la suppression de compte | ✅ |
| `on deleteAccount › leaves nothing of the previous user in the stores that were left behind` | idem, à la suppression de compte | ✅ |

`frontend/src/__tests__/billing.spec.js` : `$reset clears the status` (renommage).

Suite frontend complète : **65 fichiers, 607 tests**, au vert.

**Vérifié en navigateur** (Edge headless, build local, compte démo) : état de
chaque store relevé avant et après déconnexion, avant puis après correctif.

## Origine

Remarque notée pendant l'audit confidentialité du ticket #40
([109](109-gains-pertes-moyens-et-max.md)) : seul `stats` avait été repéré.
L'inventaire complet a fait apparaître les stores mis en cache derrière `loaded`,
plus exposés.
