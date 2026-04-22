# 26 — Onglet "Préférences" (scission d'avec Profil)

## Contexte

L'onglet **Profil** mélangeait aujourd'hui deux natures de réglages :

- des champs d'**identité** (prénom, nom, email, photo),
- et des **préférences applicatives** (langue, thème, fuseau horaire, devise par défaut, et depuis [25-stats-be-threshold.md](25-stats-be-threshold.md), le seuil BE).

Avec l'ajout du seuil BE, puis l'arrivée prévue d'autres réglages (tarification Stripe, auto-sync brokers gated par flag, frais de courtage, etc.), le formulaire Profil devenait un sac fourre-tout. La sémantique UX n'est plus claire pour l'utilisateur.

## Demande

Scinder en deux onglets distincts :

- **Profil** : qui tu es.
- **Préférences** : comment l'app se comporte pour toi.

## Scope

### Dans le scope

- Nouveau composant `PreferencesTab.vue` regroupant : langue, thème, fuseau horaire, devise par défaut, seuil BE.
- Allègement de `ProfileTab.vue` qui ne garde que : prénom, nom, email (lecture seule), photo de profil.
- Nouvel onglet `"preferences"` dans `AccountView.vue`, inséré **après** `profile`.
- i18n : clé `account.tabs.preferences` ajoutée. Les clés de champs (`account.timezone`, `account.default_currency`, `account.theme`, `account.locale`, `account.be_threshold`, `account.be_threshold_hint`, etc.) restent inchangées — elles vivent simplement dans un autre composant.
- Tests Vitest : `preferences-tab.spec.js` créé (reprend notamment la couverture du seuil BE), `profile-tab.spec.js` trimé à ce que Profil expose encore, `account-view.spec.js` mis à jour pour le nouveau panel.
- Route : `?tab=preferences` reconnue côté `AccountView`.

### Hors scope

- **Aucun changement backend** : l'endpoint `PUT /auth/profile` accepte déjà tous ces champs via un whitelist (`UserRepository::PROFILE_FIELDS`). On ne touche ni `AuthService`, ni `UserRepository`, ni les validations côté PHP.
- **Aucune migration DB** : pas de nouvelle colonne. Le split est purement UI.
- Pas de réorganisation des autres onglets (`Actifs`, `Setups`, `Champs perso`).
- Pas de changement de comportement fonctionnel : tous les champs fonctionnent exactement comme avant, ils sont juste répartis différemment.

## Conception

### Répartition finale

| Onglet | Champs |
|---|---|
| **Profil** | Prénom, Nom, Email (read-only), Photo de profil |
| **Préférences** | Langue, Thème, Fuseau horaire, Devise par défaut, Seuil BE (%) |

### Choix sur la langue

La langue va en Préférences malgré son usage pour les emails transactionnels (vérification, reset mot de passe). Argument UX : de l'utilisateur, c'est un réglage d'affichage comme le thème, pas une propriété identitaire. L'effet technique (langue des emails) reste le même puisque la persistance en base ne change pas.

### Choix sur le fuseau horaire

Le fuseau pilote le rendu temporel des stats (conversion UTC → local, heatmap) — c'est un réglage d'affichage, pas d'identité. Il va en Préférences.

### Payload envoyé lors du save

Chaque onglet construit son propre payload partiel et appelle `authStore.updateProfile(data)`. Le backend merge dans le même endpoint via whitelist ; pas de risque d'écraser les champs de l'autre onglet puisque `UserRepository::updateProfile` n'updatent que les clés présentes dans `$data`.

## TDD

- `preferences-tab.spec.js` : prefill depuis `authStore.user`, rendu de chaque champ, save qui envoie les 5 champs attendus (dont `be_threshold_percent`).
- `profile-tab.spec.js` : réduit aux seuls champs restants (prénom, nom, email read-only, photo).
- `account-view.spec.js` : 5 panels rendus (`profile`, `preferences`, `assets`, `setups`, `custom-fields`), `?tab=preferences` active le bon onglet, `?tab=unknown` retombe sur `profile`.

## Livrables

- `frontend/src/components/account/PreferencesTab.vue` (nouveau)
- `frontend/src/components/account/ProfileTab.vue` (réduit)
- `frontend/src/views/AccountView.vue` (nouvel onglet)
- `frontend/src/locales/{fr,en}.json` : clé `account.tabs.preferences`
- `frontend/src/__tests__/preferences-tab.spec.js` (nouveau)
- `frontend/src/__tests__/profile-tab.spec.js` (reduit)
- `frontend/src/__tests__/account-view.spec.js` (updated)
- Mise à jour de [10-account-profile.md](10-account-profile.md) pour refléter la nouvelle organisation.
