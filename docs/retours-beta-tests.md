# Retours bêta-testeurs

Liste structurée des remarques remontées par les utilisateurs ayant accès aux environnements de test/prod. Chaque item est un mini-ticket avec un ID stable pour référence (commits, branches, conversations).

**Légende statut** :
- 🟢 à traiter
- 🟡 à clarifier / discuter
- 🚫 ne sera pas traité
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

### ✅ DONE

Tickets résolus (code mergé sur `develop` + doc rédigée, ou réponse formulée pour les questions).

| ID | Titre | Type | Livraison |
|----|-------|------|-----------|
| [E-01](#e-01) | Édition des setups (au lieu de supprimer/recréer) | Évol | `docs/53-setup-inline-edit.md` |
| [E-05](#e-05) | DD proposé sur compte non-propfirm (UX) | Évol | `docs/54-account-risk-params-toggle.md` |
| [D-02](#d-02) | Renommer "Zone dangereuse" sur Profil | Doc/UX | MAJ `docs/27-account-danger-zone.md` |
| [D-07](#d-07) | Liste des comptes : badge "étape" fusionné dans la colonne "type" | Doc/UX | inline (cf. `AccountsView.vue`) |
| [Q-01](#q-01) | Comment est calculé le R:R ? | Assistance | Réponse formulée (2026-05-04) |
| [Q-02](#q-02) | Une alerte est-elle déclenchée si le DD est dépassé ? | Assistance | Réponse formulée (2026-05-04) |
| [Q-03](#q-03) | Trade BE ouvert en parallèle compté dans les stats _(ex-B-01)_ | Assistance | Réponse formulée (2026-05-04) |
| [Q-04](#q-04) | "Taux de réussite" — BE compté comme non-réussite ? _(ex-B-02)_ | Assistance | Réponse formulée (2026-05-04) |
| [E-08](#e-08) | Alerte si DD dépassé (notification utilisateur) | Évol | `docs/56-dd-approach-alert.md` |

### 🟡 TODO

Tickets restants à traiter / arbitrer / clarifier.

| ID | Titre | Type | Priorité | Effort | Statut |
|----|-------|------|----------|--------|--------|
| [B-03](#b-03) | Champ "prix d'entrée" obligatoire en import custom mais non utilisé | Bug | — | — | 🚫 |
| [E-02](#e-02) | Source d'import : Ouinex | Évol | Moyenne | Moyen | 🟡 |
| [E-03](#e-03) | Espace "questions / remarques" pour utilisateurs | Évol | Moyenne | Moyen | 🟡 |
| [E-04](#e-04) | Imports scindés / sélection des données à analyser | Évol | À arbitrer | Élevé | 🟡 |
| [E-06](#e-06) | Winrate par combinaison de setups (groupes) | Évol | À arbitrer | Élevé | 🟡 |
| [E-07](#e-07) | Suppression en lot via l'historique (multi-select + filtres dates) | Évol | Haute | Moyen | 🟢 |
| [E-09](#e-09) | Source d'import : IG | Évol | Moyenne | Moyen | 🟡 |
| [D-01](#d-01) | Tooltip "valeur du point" sur Mes Actifs (cryptos) | Doc/UX | Haute | Faible | 🟢 |
| [D-03](#d-03) | Rappel "renseigne tes setups" dans l'import FTMO | Doc/UX | Moyenne | Faible | 🟢 |
| [D-04](#d-04) | Rappel "seuil BE" sur le graphe gains/pertes/BE | Doc/UX | Haute | Faible | 🟢 |
| [D-05](#d-05) | Onboarding 2 use cases : actif vs passif | Doc/UX | Moyenne | Moyen | 🟡 |
| [D-06](#d-06) | "Custom import" — mode d'emploi & valorisation produit | Doc/UX | Moyenne | Moyen | 🟡 |
| [S-01](#s-01) | Présenter à "rod" / proposition à IVT | Stratégie | — | — | 💡 |
| [S-02](#s-02) | Compliments puissance/ergonomie — réutiliser en com | Stratégie | — | — | 💡 |
| [S-03](#s-03) | Maintenir la valeur ajoutée 2A vs FTMO natif | Stratégie | — | — | 💡 |

---

## Source 1 — Retours Robin (01/05/2026)

### Bugs / comportements à investiguer

<a id="b-03"></a>
#### B-03. Champ "prix d'entrée" obligatoire en import custom mais non utilisé — 🚫

> "Dans import 'custom' le champ 'prix d'entrée' est obligatoire. Dans mes statistiques je ne le prends pas en compte… à voir si obligatoire pour l'analyse ?"

- **Verdict** (2026-05-04) : **ne sera pas traité**.
- **Analyse** :
  - Côté stats agrégées (winrate, P&L absolu), `entry_price` n'est en effet pas utilisé sur les imports custom (`sl_points` reste `null` post-import → pas de R:R dérivé). Le beta-testeur a raison sur son cas d'usage.
  - Côté modèle, `positions.entry_price` est `NOT NULL` (cf. `api/database/schema.sql:119`) : c'est une donnée d'identité de la position (détail trade, partage, dérivation R:R future si l'utilisateur ajoute son SL a posteriori). Le rendre optionnel impose une migration de schéma + audit complet de tous les consommateurs (affichage, edit, share-position, etc.) — blast radius disproportionné pour le bénéfice.
- **Conclusion** : on garde `entry_price` requis. Pas de tooltip ajouté pour l'instant ; si la friction remonte sur d'autres beta-testeurs, on pourra ajouter un helper `Utilisé pour le détail trade et le R:R futur — tes stats agrégées P&L ne dépendent pas de ce champ.`

---

### Évolutions fonctionnelles

<a id="e-01"></a>
#### E-01. Édition des setups (au lieu de supprimer/recréer) — ✅

> "Donner la possibilité de modifier les setups créés ? Au lieu de les supprimer et refaire ?"

- **Effort** : faible (CRUD existant à compléter).
- **Priorité** : haute (quick win).
- **Livré** : édition inline du label **et** de la catégorie dans la grid (icône crayon → `<InputText>` + `<Select>` + ✓/✗, patch minimal). Cf. `docs/53-setup-inline-edit.md`.

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
#### E-05. DD proposé sur compte non-propfirm (UX) — ✅

> "Quand tu renseignes un compte qui n'est pas propfirm on te propose quand même de renseigner le DD alors que t'es pas censé être concerné. Mais ça peut être pas mal de le proposer pour éviter de tout cramer."

- **Type** : clarification UX du formulaire de création/édition de compte.
- **Livré** : sur compte non-PF, les 4 champs DD/objectif/partage de profits sont masqués par défaut derrière une checkbox « Paramètres de gestion du risque » (champs optionnels ; auto-affichés si le compte a déjà au moins une valeur saisie). Sur compte PF, comportement inchangé : champs toujours visibles. Cf. `docs/54-account-risk-params-toggle.md`.
- **Lien** : voir [E-08](#e-08) pour la partie alerte/notification (séparée).

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
#### E-08. Alerte si DD dépassé (notification utilisateur) — ✅

> "Comment ça se passe derrière ? Ça alerte si ça dépasse ?"

- **Livré** (2026-05-04) : alerte d'**approche** du DD (avant breach), pas alerte de dépassement strict.
  - **Canaux** : bandeau rouge en haut du Dashboard + email (1 par compte par type de DD par jour, dédup au reset journalier en TZ user).
  - **Seuils** : déclenchement quand le compte a consommé `(100 − seuil_user)%` ou plus de son DD. Seuil paramétrable dans les préférences user (défaut 5 %, plage 1–50 %).
  - **Calcul** : à chaque close de trade (hook fire-and-forget dans `TradeService::close`) + à chaque chargement du dashboard via `GET /accounts/dd-status` (lecture seule, n'envoie pas de mail).
  - **Couverture** : max DD + daily DD. Réalisé compté nativement, unrealized branché en stub (retournera 0 jusqu'à l'intégration cours live brokers — point d'extension unique dans `DrawdownService::computeUnrealizedPnl`).
  - **Doc** : `docs/56-dd-approach-alert.md`.
- **Lien** : issu du même retour que [E-05](#e-05), traité séparément (UX form ≠ moteur d'alertes). Réponse à [Q-02](#q-02) mise à jour pour pointer ici.

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
#### D-02. Renommer "Zone dangereuse" sur Profil — ✅

> "Onglet Profil : pour changer ses mdp c'est marqué 'zone dangereuse'… à terme ce sera différent ? Plutôt mettre 'Mes données' ou un truc dans le genre ?"

- **Livré** : split en deux composants distincts. `SecuritySection.vue` (bordure neutre) accueille le changement de mot de passe ; `DangerZone.vue` (bordure rouge) ne contient plus que la suppression de compte. Doc 27 mise à jour avec un encart en tête expliquant l'évolution.

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

<a id="d-07"></a>
#### D-07. Liste des comptes — badge "étape" fusionné dans la colonne "type" — ✅

Polish UX repéré en revue. Sur la liste des comptes, le badge **étape** (Challenge / Vérification / Funded) avait sa propre colonne et affichait `-` pour les comptes non-PF, créant du bruit visuel. Le badge est désormais affiché **dans la même cellule** que le badge "type de compte", à droite, et n'apparaît que pour les PF.

- **Livré** : `frontend/src/views/AccountsView.vue` — colonne `account_type` rendu en `<div class="flex items-center gap-1 flex-wrap">` qui agrège les deux `<Tag>`. La colonne `stage` autonome a été supprimée.
- **Effort** : faible.

---

### Questions / assistance (réponses à donner, pas du dev)

<a id="q-01"></a>
#### Q-01. Comment est calculé le R:R ? — ✅

> "Comment il calcule les RR ? Car pour ça il faut avoir le niveau de stop et des TP non ?"

- **Réponse formulée à transmettre** (2026-05-04) :
  - Formule par trade : `R:R = totalPnl / (size × slPoints)`.
  - Concrètement : P&L net du trade (somme signée de toutes les sorties partielles) divisé par le risque initial (taille × distance entrée→SL).
  - Nécessite donc un **SL renseigné** sur le trade pour être calculable.
- ⚠️ **Gotcha relevé puis corrigé** : si SL absent, R:R était stocké à `0` (et non `NULL`) → tirait la moyenne `AVG(t.risk_reward)` vers le bas. Fix livré → cf. `docs/55-rr-null-when-no-sl.md`.
- **Source code** : `TradeService.php:799` (`riskAmount > 0 ? totalPnl/riskAmount : 0`), `StatsRepository.php:143` (`ROUND(AVG(t.risk_reward), 2) AS avg_rr`).

<a id="q-02"></a>
#### Q-02. Une alerte est-elle déclenchée si le DD est dépassé ? — ✅

> "Comment ça se passe derrière ? Ça alerte si ça dépasse ?"

- **Réponse formulée à transmettre** (2026-05-04, MAJ après livraison) :
  - **Au moment du retour de Robin** : aucune alerte n'était déclenchée. `max_drawdown` / `daily_drawdown` étaient des paramètres de référence statiques.
  - **Depuis 2026-05-04** : **alerte d'approche du DD livrée** (cf. [E-08](#e-08) / `docs/56-dd-approach-alert.md`). Bandeau dashboard + email quand un compte propfirm consomme `(100 − seuil)%` ou plus de son DD max ou journalier. Seuil paramétrable par l'utilisateur (défaut 5 %, plage 1–50 %).
- **Source code** : `DrawdownService.php` (compute + checkAndNotify), hook dans `TradeService::close`, endpoint `GET /accounts/dd-status`.

<a id="q-03"></a>
#### Q-03. Trade BE ouvert en parallèle compté dans les stats — ✅ _(ex-B-01)_

> "La 2ᵉ ligne correspond à la ligne qui a été BE (ouverte presque en même temps que l'autre, le temps du clic) mais ça me compte comme un trade alors que ça a été BE : elle ne devrait pas être comptée dans les stats je pense."

- **Verdict** (2026-05-04) : **comportement assumé**, pas un bug — initialement classé `B-01`, reclassé `Q-03` car c'est une question sur le fonctionnement, pas un bug.
- **Réponse formulée à transmettre** :
  - Le 2ᵉ trade BE est compté volontairement dans le **nombre total de trades** (denominator), mais **pas dans le winrate** (numerator). Il rejoint une catégorie "BE" séparée, visible à part dans les stats.
  - Logique produit : conserver une trace de toutes les décisions de trading (fréquence d'activité). Exclure les BE rendrait le compte de trades trompeur.
  - Si masquer les BE devient un besoin récurrent, on créera un filtre dédié — pas un changement de statut.
- **Source code** : `StatsRepository.php:44, 123-132` (denominator = `pnl IS NOT NULL`, numerator = `pnl_percent > be_threshold`).

<a id="q-04"></a>
#### Q-04. "Taux de réussite" — BE compté comme non-réussite ? — ✅ _(ex-B-02)_

> "J'imagine qu'il considère un BE comme une non-réussite. D'où le 56%. Or t'as forcément un BE à minima sur un solde qui court… donc un trade où tu fais TP1 TP2 TP3 et BE sur le solde t'amènerait une non-réussite."

- **Verdict** (2026-05-04) : **inquiétude infondée pour le cas TP1+TP2+TP3+BE-solde**, comportement correct — initialement classé `B-02`, reclassé `Q-04` car c'est une question sur le fonctionnement, pas un bug.
- **Réponse formulée à transmettre** :
  - Le winrate ne regarde pas le chemin parcouru, il regarde le **P&L net réalisé du trade entier**. TP1+TP2+TP3 encaissés puis solde sorti à BE → P&L net largement positif → **classé en victoire**.
  - Un trade tombe en BE uniquement si son P&L final agrégé est dans la **zone seuil BE configurable** (défaut 0%, jusqu'à 5% selon préférences utilisateur).
  - Donc le 56% de Robin ne vient pas de ce cas. Plus probablement : pertes nettes, ou trades dans la bande de tolérance BE selon son seuil configuré. Demander un exemple concret pour valider.
- **Source code** : `TradeService.php:332-342` (`calculateRealizedMetrics` agrège tout le réalisé sur chaque sortie partielle, `pnl` et `pnl_percent` sont mis à jour à chaque exit).

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
