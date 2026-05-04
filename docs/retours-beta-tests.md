# Retours bêta-testeurs

Liste structurée des remarques remontées par les utilisateurs ayant accès aux environnements de test/prod. Chaque item est un mini-ticket avec un ID stable pour référence (commits, branches, conversations).

**Légende statut** :
- 🟢 à traiter
- 🟡 à clarifier / discuter
- 🔴 ne sera pas traité
- ✅ déjà fait / déjà couvert
- ⏳ en cours

**Conventions ID** :
- `B-xx` bug / comportement à investiguer
- `E-xx` évolution fonctionnelle
- `D-xx` documentation / accompagnement utilisateur
- `Q-xx` question / assistance (réponse à formuler, pas du dev)
- `S-xx` stratégie / long terme (info, pas d'action)

---

## Index

| ID | Titre | Type | Priorité | Effort | Statut |
|----|-------|------|----------|--------|--------|
| [B-01](#b-01) | Trade BE ouvert en parallèle compté dans les stats | Bug | Moyenne | ? | 🟡 |
| [B-02](#b-02) | "Taux de réussite" — BE compté comme non-réussite ? | Bug | Moyenne | ? | 🟡 |
| [B-03](#b-03) | Champ "prix d'entrée" obligatoire en import custom mais non utilisé | Bug | Haute | Faible | 🟢 |
| [E-01](#e-01) | Édition des setups (au lieu de supprimer/recréer) | Évol | Haute | Faible | ✅ |
| [E-02](#e-02) | Source d'import : Ouinex | Évol | Moyenne | Moyen | 🟡 |
| [E-03](#e-03) | Espace "questions / remarques" pour utilisateurs | Évol | Moyenne | Moyen | 🟡 |
| [E-04](#e-04) | Imports scindés / sélection des données à analyser | Évol | À arbitrer | Élevé | 🟡 |
| [E-05](#e-05) | DD proposé sur compte non-propfirm (UX) | Évol | Moyenne | Faible | 🟡 |
| [E-06](#e-06) | Winrate par combinaison de setups (groupes) | Évol | À arbitrer | Élevé | 🟡 |
| [E-07](#e-07) | Suppression en lot via l'historique (multi-select + filtres dates) | Évol | Haute | Moyen | 🟢 |
| [E-08](#e-08) | Alerte si DD dépassé (notification utilisateur) | Évol | Moyenne | Moyen | 🟡 |
| [E-09](#e-09) | Source d'import : IG | Évol | Moyenne | Moyen | 🟡 |
| [D-01](#d-01) | Tooltip "valeur du point" sur Mes Actifs (cryptos) | Doc/UX | Haute | Faible | 🟢 |
| [D-02](#d-02) | Renommer "Zone dangereuse" sur Profil | Doc/UX | Haute | Faible | 🟢 |
| [D-03](#d-03) | Rappel "renseigne tes setups" dans l'import FTMO | Doc/UX | Moyenne | Faible | 🟢 |
| [D-04](#d-04) | Rappel "seuil BE" sur le graphe gains/pertes/BE | Doc/UX | Haute | Faible | 🟢 |
| [D-05](#d-05) | Onboarding 2 use cases : actif vs passif | Doc/UX | Moyenne | Moyen | 🟡 |
| [D-06](#d-06) | "Custom import" — mode d'emploi & valorisation produit | Doc/UX | Moyenne | Moyen | 🟡 |
| [Q-01](#q-01) | Comment est calculé le R:R ? | Assistance | — | — | 🟡 |
| [Q-02](#q-02) | Une alerte est-elle déclenchée si le DD est dépassé ? | Assistance | — | — | 🟡 |
| [S-01](#s-01) | Présenter à "rod" / proposition à IVT | Stratégie | — | — | 💡 |
| [S-02](#s-02) | Compliments puissance/ergonomie — réutiliser en com | Stratégie | — | — | 💡 |
| [S-03](#s-03) | Maintenir la valeur ajoutée 2A vs FTMO natif | Stratégie | — | — | 💡 |

---

## Source 1 — Retours Robin (01/05/2026)

### Bugs / comportements à investiguer

<a id="b-01"></a>
#### B-01. Trade BE ouvert en parallèle compté dans les stats — 🟡

> "La 2ᵉ ligne correspond à la ligne qui a été BE (ouverte presque en même temps que l'autre, le temps du clic) mais ça me compte comme un trade alors que ça a été BE : elle ne devrait pas être comptée dans les stats je pense."

- **Type** : possiblement un bug, possiblement un choix produit assumé.
- **À faire** : vérifier comment les trades BE sont comptés dans `StatsRepository` / `StatsService` (taux de réussite, nombre de trades). Définir si BE = trade neutre à exclure des perdants mais compté, ou exclu complètement.
- **Priorité** : moyenne.

<a id="b-02"></a>
#### B-02. "Taux de réussite" — BE compté comme non-réussite ? — 🟡

> "J'imagine qu'il considère un BE comme une non-réussite. D'où le 56%. Or t'as forcément un BE à minima sur un solde qui court… donc un trade où tu fais TP1 TP2 TP3 et BE sur le solde t'amènerait une non-réussite."

- **Type** : règle de calcul à clarifier.
- **À faire** : confirmer la formule actuelle du winrate (TradeStatus = WIN seulement ? WIN + SECURED ? BE inclus ?). Cf. récents commits `feat(trades): include SECURED in stats`. Possiblement déjà adressé partiellement, à confirmer avec un cas concret de son compte.
- **Priorité** : moyenne.

<a id="b-03"></a>
#### B-03. Champ "prix d'entrée" obligatoire en import custom mais non utilisé — 🟢

> "Dans import 'custom' le champ 'prix d'entrée' est obligatoire. Dans mes statistiques je ne le prends pas en compte… à voir si obligatoire pour l'analyse ?"

- **Type** : friction d'import — contrainte à relâcher si non nécessaire.
- **À faire** : vérifier dans `ImportCustomService` / mapping si `entry_price` est vraiment nécessaire pour le calcul des stats (R:R, P&L). Si non → rendre optionnel.
- **Priorité** : haute (quick win).

---

### Évolutions fonctionnelles

<a id="e-01"></a>
#### E-01. Édition des setups (au lieu de supprimer/recréer) — ✅

> "Donner la possibilité de modifier les setups créés ? Au lieu de les supprimer et refaire ?"

- **Effort** : faible (CRUD existant à compléter).
- **Priorité** : haute (quick win).
- **Livré** : édition inline du label dans la grid (icône crayon → `<InputText>` + ✓/✗). Cf. `docs/53-setup-inline-edit.md`.

<a id="e-02"></a>
#### E-02. Source d'import : Ouinex — 🟡

> "Faudrait peut-être proposer en sources d'import 'ouinex' (…) non ?"

- **Type** : nouvelle intégration broker.
- **Effort** : moyen (pattern : cf. `46-import-multi-format.md`).
- **Priorité** : à arbitrer selon demande utilisateurs.
- **Lien** : voir aussi [E-09](#e-09) (IG, autre intégration mentionnée dans le même retour).

<a id="e-03"></a>
#### E-03. Espace "questions / remarques" pour utilisateurs — 🟡

> "Faire une catégorie 'questions réponses' ou juste 'question remarque' pour les utilisateurs ?"

- **Type** : feedback in-app (form + table admin BO).
- **Effort** : moyen.

<a id="e-04"></a>
#### E-04. Imports scindés / sélection des données à analyser — 🟡

> "Donner la possibilité de scinder les imports ? Ex : le mec veut importer son dernier challenge ET les comparer à sa perf depuis qu'il a commencé OU avant. Faudrait pouvoir faire plusieurs imports et dans l'analyse, choisir les données à analyser."

- **Sous-besoins** :
  - Lister les imports passés
  - Pouvoir supprimer un import (et tous ses trades)
  - Filtrer les stats par "lot d'import"
- **Effort** : élevé (modèle + stats + UI).
- **Différenciant produit potentiel** : ce qu'aucun broker ne propose.
- **Priorité** : à arbitrer — gros chantier mais valeur perçue forte.
- **Lien** : se recoupe partiellement avec [E-07](#e-07) (suppression en lot ≠ suppression par lot d'import, mais même esprit).

<a id="e-05"></a>
#### E-05. DD proposé sur compte non-propfirm (UX) — 🟡

> "Quand tu renseignes un compte qui n'est pas propfirm on te propose quand même de renseigner le DD alors que t'es pas censé être concerné. Mais ça peut être pas mal de le proposer pour éviter de tout cramer."

- **Type** : clarification UX du formulaire de création/édition de compte.
- **À faire** : statuer — DD optionnel pour tous comptes (et conserver le champ visible), ou DD réservé aux propfirms (et le masquer sinon). Le retour suggère de le **garder optionnel pour tous** (utile même hors propfirm pour limiter la casse).
- **Effort** : faible (form + libellé + helper text).
- **Priorité** : moyenne.
- **Lien** : voir [E-08](#e-08) pour la partie alerte/notification.

<a id="e-06"></a>
#### E-06. Winrate par combinaison de setups (groupes) — 🟡

> "Pour le calcul des winrate par setup, je pense pas qu'il faille diviser par setup mais davantage garder les setups ensemble et les trier par groupe. Ex : bbmagique5cash + break2 + zone de prix = 1 gros setup et non 3. Tu pourras dire 'si les 3 conditions sont réunies alors ce setup A me donne raison dans 90% des cas'."

- **Type** : nouvelle dimension d'analyse (combinaisons).
- **Effort** : élevé (nouveau calcul de stats sur combinaisons).
- **Priorité** : à arbitrer après les bases. Très intéressant produit.

<a id="e-07"></a>
#### E-07. Suppression en lot via l'historique (multi-select + filtres dates) — 🟢

> Évoqué oralement : pouvoir supprimer un lot de trades via l'historique, en ajoutant la possibilité de filtrer par plages de dates dans l'historique + checkboxes de sélection multiple avec action dessus (et demande de confirmation avec récap en modale).

- **Sous-besoins** :
  - Filtre date range dans la vue historique (from / to).
  - Colonne checkbox + checkbox "tout sélectionner" (sur la page courante et/ou l'ensemble du résultat filtré).
  - Action groupée : "Supprimer la sélection".
  - Modale de confirmation avec récap : nb de trades, plage de dates, comptes concernés, P&L cumulé impacté.
  - Idéalement : suppression en transaction (rollback si échec partiel).
- **Effort** : moyen (UI + endpoint bulk delete + tests).
- **Priorité** : haute — outil de nettoyage essentiel pour qui s'est trompé d'import ou veut repartir d'une base saine.
- **Lien** : se combine bien avec [E-04](#e-04) (un autre angle : supprimer "par lot d'import" plutôt que par filtre date).

<a id="e-08"></a>
#### E-08. Alerte si DD dépassé (notification utilisateur) — 🟡

> "Comment ça se passe derrière ? Ça alerte si ça dépasse ?"

- **Type** : nouveau mécanisme d'alerte.
- **Pré-requis** : confirmer l'état actuel (cf. [Q-02](#q-02)) — si rien n'existe aujourd'hui, on part de zéro.
- **À faire (à scoper)** :
  - Définir le canal : badge in-app, bandeau, e-mail, push ?
  - Définir les seuils : 80% / 100% du DD ? Configurable ?
  - Calcul à la volée à chaque trade clos / partial exit, ou job périodique ?
- **Effort** : moyen.
- **Priorité** : moyenne (filet de sécurité fort pour l'utilisateur).
- **Lien** : issu du même retour que [E-05](#e-05), mais traité séparément (UX form ≠ moteur d'alertes).

<a id="e-09"></a>
#### E-09. Source d'import : IG — 🟡

> "Faudrait peut-être proposer en sources d'import (…) 'ig' non ?"

- **Type** : nouvelle intégration broker.
- **Effort** : moyen (pattern : cf. `46-import-multi-format.md`).
- **Priorité** : à arbitrer selon demande utilisateurs.
- **Lien** : voir aussi [E-02](#e-02) (Ouinex, mentionné dans le même retour).

---

### Documentation / accompagnement utilisateur

<a id="d-01"></a>
#### D-01. Tooltip "valeur du point" sur Mes Actifs (cryptos) — 🟢

> "Pour les cryptos, difficile pour notre gestion… Mettre à côté un petit 'i' d'information ou un paragraphe en dessous rappelant le calcul de la valeur du point comme sur IVT ?"

- **Effort** : faible.
- **Priorité** : haute (gros gain UX).

<a id="d-02"></a>
#### D-02. Renommer "Zone dangereuse" sur Profil — 🟢

> "Onglet Profil : pour changer ses mdp c'est marqué 'zone dangereuse'… à terme ce sera différent ? Plutôt mettre 'Mes données' ou un truc dans le genre ?"

- **Note** : cf. `27-account-danger-zone.md`. Sur le profil utilisateur (changement mdp), le terme est trop anxiogène pour une action banale. Garder "Zone dangereuse" sur les comptes/propfirm (suppression, reset).
- **Effort** : faible.
- **Priorité** : haute.

<a id="d-03"></a>
#### D-03. Rappel "renseigne tes setups" dans l'import FTMO — 🟢

> "FTMO ne propose pas d'ajouter le setup… ça oblige les gens à avoir un template. Pour une bonne analyse il faudrait que les gens ajoutent leur setup à chaque fois. Pourquoi pas mettre une info comme ça : 'pense à renseigner les setups de tes trades pour une analyse encore plus approfondie'."

- **Effort** : faible.

<a id="d-04"></a>
#### D-04. Rappel "seuil BE" sur le graphe gains/pertes/BE — 🟢

> "Dans le graph des gains/pertes/BE, pourquoi ne pas mettre un commentaire 'pense à renseigner ton seuil de BE, onglet mon compte préférence'."

- **Type** : empty/info state contextuel.
- **Effort** : faible.

<a id="d-05"></a>
#### D-05. Onboarding 2 use cases : actif vs passif — 🟡

> "Faudrait presque, pour faire comprendre aux gens : 'tu as des données passées que tu veux analyser ? Renseigne ce template ou importe les données' / 'tu veux analyser tes données à partir de maintenant ? Renseigne directement tes trades dès que tu les prends'."

- **Type** : onboarding / page d'aide qui distingue les deux parcours.
- **À intégrer** dans l'onboarding existant (cf. `11-onboarding.md`).

<a id="d-06"></a>
#### D-06. "Custom import" — mode d'emploi & valorisation produit — 🟡

> "Pense à personnaliser tes setups d'entrées… Se mettre à la place de celui qui ne connait pas et qui puisse se dire 'ah mais je peux vraiment tout personnaliser c'est top'."

- **Type** : doc/onboarding mettant en avant la flexibilité du custom import.

---

### Questions / assistance (réponses à donner, pas du dev)

<a id="q-01"></a>
#### Q-01. Comment est calculé le R:R ? — 🟡

> "Comment il calcule les RR ? Car pour ça il faut avoir le niveau de stop et des TP non ?"

- **Réponse à formuler** : `rr = totalPnl / (size × slPoints)` (cf. `TradeService::calculateFinalMetrics`). Donc oui, nécessite un SL renseigné. Si SL absent → R:R non calculable.

<a id="q-02"></a>
#### Q-02. Une alerte est-elle déclenchée si le DD est dépassé ? — 🟡

> "Comment ça se passe derrière ? Ça alerte si ça dépasse ?"

- **Réponse à formuler** : à vérifier. Si non → cf. [E-05](#e-05) (b).

---

### Stratégie / long terme (info, pas d'action immédiate)

<a id="s-01"></a>
#### S-01. Présenter à "rod" / proposition à IVT — 💡
Décision business hors scope dev.

<a id="s-02"></a>
#### S-02. Compliments puissance/ergonomie — réutiliser en com — 💡
À conserver pour la com / page d'accueil si témoignage utilisable.

<a id="s-03"></a>
#### S-03. Maintenir la valeur ajoutée 2A vs FTMO natif — 💡
Vérifier en continu (le diff actuel = setups + heures, à entretenir).

---

*Source brute : `Remarques Journal 2A au 01 05 26.docx` (hors repo) + retours oraux complémentaires.*
*À mettre à jour quand un item est traité (statut + lien vers commit/doc).*
