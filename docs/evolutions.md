# Évolutions à prévoir

Liste des améliorations identifiées en cours de route mais sortant du scope d'une feature en cours. À planifier / prioriser quand les branches concernées seront mergées.

## UX / UI

### Remplacer les `window.confirm()` natifs par des Dialog PrimeVue

**Contexte** : repéré sur l'onglet **Mes actifs** — le bouton "Supprimer" déclenche un `window.confirm()` du navigateur (alerte OS-native "OK / Annuler") au lieu d'une modale cohérente avec le reste de l'app.

**Occurrence certaine** :
- `frontend/src/components/account/AssetsTab.vue:49` — `if (confirm(t('symbols.confirm_delete'))) { ... }`

**Problèmes** :
- Incohérence UX avec le reste de l'app qui utilise `<Dialog>` PrimeVue (ex. suppression de compte, résiliation d'abonnement, change password…)
- Pas de dark mode, pas de translations consistantes avec les libellés app
- Bloque le thread JS (modale modale-bloquante)

**À faire** :
1. `grep -rn "window\.confirm\|\\bconfirm(\\|\\balert(" frontend/src/` pour lister toutes les occurrences
2. Branche dédiée `chore/replace-native-confirms`
3. Pour chacune : remplacer par un composant `<Dialog>` PrimeVue, pattern de référence : `DeleteAccountDialog.vue` ou `BillingTab.vue` (dialog de résiliation d'abonnement)

**Priorité** : basse (fonctionnel OK, juste incohérent). À traiter après merge de `feature/stripe-billing` sur `develop`.

---

*À chaque nouvelle évolution repérée mais non traitée immédiatement : l'ajouter ici avec contexte + fichiers + à-faire + priorité.*
