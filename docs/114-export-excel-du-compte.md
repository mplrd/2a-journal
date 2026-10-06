# 114 — Export Excel d'un compte

## Fonctionnalités

### Le besoin

Pour faire un retour d'expérience sur un compte (le sien, ou celui d'un trader
qu'on accompagne), il faut pouvoir **sortir toute la donnée du compte** et la
donner à une IA (ChatGPT, Claude…) ou à un tableur. Jusqu'ici, rien ne le
permettait : on importait des historiques, on partageait une position, mais on
ne pouvait rien exporter.

### Ce que fait l'export

Depuis la page **Comptes**, chaque compte a une action **« Exporter (Excel) »**,
avec une icône de téléchargement :

- sur ordinateur, dans la rangée d'actions du compte, à côté de l'import ;
- sur mobile, dans le menu « … » de la tuile.

Le navigateur télécharge `<nom-du-compte>-<AAAA-MM-JJ>.xlsx` (par ex.
`a-pf-ftmo-2026-10-06.xlsx`). Le point de départ est **le compte** : le fichier
contient le compte et son historique, rien d'autre.

Toutes les colonnes sont **fixes**. Les valeurs sont celles stockées, **rien
n'est calculé** par l'export, et les valeurs codées sont **traduites** dans le
vocabulaire de l'application (Achat, Fermé, Take Profit, Hors plan…).

### Onglet « Compte »

| Champ | Contenu |
|---|---|
| Nom, Type, Phase, Broker, Devise | Identité du compte (Type : Prop Firm, Démo, Compte propre ; Phase : Challenge, Vérification, Funded) |
| Capital initial | Tel que saisi |
| Solde | Le même que dans l'application (solde broker s'il est synchronisé) |
| Drawdown max, Drawdown journalier, Objectif de profit, Partage des profits (%) | Les limites du compte. Vides si non renseignées |
| Exporté le | Date et heure de l'export |

Sous ces champs, si le compte en a, viennent les **ajustements de solde**, un
par ligne, du plus ancien au plus récent : date, montant, motif.

### Onglet « Trades » : une ligne par jambe

Un trade peut avoir **N objectifs et N sorties**. Pour garder des colonnes
fixes, l'onglet a **une ligne par jambe du trade** :

1. une ligne par **objectif**, avec la sortie qui l'a touché si elle existe (un
   objectif touché par plusieurs sorties donne une ligne par sortie) ;
2. puis une ligne par **sortie rattachée à aucun objectif** (break-even, stop,
   clôture manuelle du reste) ;
3. un trade sans objectif ni sortie (trade ouvert) tient sur une seule ligne.

Les colonnes du trade se **répètent** sur chacune de ses lignes.

| Groupe | Colonnes |
|---|---|
| Trade | N° trade, Statut (Ouvert, Sécurisé, Fermé), Ordre placé le (vide si pris au marché), Ouverture, Sécurisé le (passage au BE, tiré de l'historique des statuts), Clôture |
| Position | Symbole, Sens, Taille, Taille restante, Prix d'entrée, SL (prix), BE (prix), BE (taille) |
| Jambe : objectif | Objectif (TP1, TP2…), Objectif (prix), Objectif (taille) |
| Jambe : sortie | Sortie (date), Sortie (prix), Sortie (taille), Sortie (type), Sortie (P&L) |
| Résultat du trade | Prix de sortie moyen, Type de sortie, R:R, P&L du trade |
| Plan | Plan, Respect du plan (Dans le plan, Hors plan), Raison |
| Setup | Setup : Timeframe, Setup : Pattern, Setup : Contexte, Setup : non catégorisé |
| Contexte | Champs personnalisés, Notes |

**Pour additionner, il faut sommer « Sortie (P&L) »**, qui n'apparaît qu'une fois
par jambe. Le « P&L du trade » est répété sur chaque ligne du trade.

Exemple : un trade à trois objectifs, sorti en partie au TP1 et au TP2, au BE,
puis clôturé à la main deux jours plus tard.

| N° trade | Objectif | Objectif (prix) | Objectif (taille) | Sortie (date) | Sortie (prix) | Sortie (taille) | Sortie (type) | Sortie (P&L) |
|---|---|---|---|---|---|---|---|---|
| 128123 | TP1 | 18460 | 0,1 | 10/03 09:40 | 18460 | 0,1 | Take Profit | 6 |
| 128123 | TP2 | 18520 | 0,1 | 10/03 09:55 | 18520 | 0,1 | Take Profit | 12 |
| 128123 | TP3 | 18700 | 0,1 | | | | | |
| 128123 | | | | 10/03 10:20 | 18430 | 0,6 | Breakeven | 18 |
| 128123 | | | | 12/03 16:00 | 18580 | 0,2 | Manuel | 36 |

**Setups** : chaque tag va dans la colonne de sa catégorie (Timeframe, Pattern,
Contexte), d'après le catalogue de setups de l'utilisateur. Un tag sans
catégorie, ou qui n'est plus dans le catalogue, va dans « non catégorisé ».

**Champs personnalisés** : une seule cellule, « Nom : valeur ; Nom : valeur »,
dans l'ordre défini par l'utilisateur. Un booléen s'écrit Oui / Non.

**Notes** : le champ « Notes » de la position, c'est-à-dire le commentaire du
trader sur son trade.

### Onglet « Ordres non exécutés »

Les ordres en attente, annulés ou expirés. Un ordre exécuté est devenu un trade
et se trouve dans l'onglet Trades, avec sa date de placement.

Sur le même principe, il y a **une ligne par objectif** de l'ordre (une seule
s'il n'en a pas) : N° ordre, Statut (En attente, Annulé, Expiré), Placé le,
Expiration, Symbole, Sens, Taille, Prix d'entrée, SL (prix), BE (prix),
BE (taille), Objectif, Objectif (prix), Objectif (taille), Plan, Respect du
plan, Raison, les quatre colonnes Setup, Notes.

### Ce qui n'est pas exporté

Les colonnes qui ne font que reformuler une autre :

- les équivalents en points (SL, BE, objectifs), le prix suffit ;
- « BE atteint », doublon de « Sécurisé le », qu'elle peut même contredire ;
- la valeur du point ;
- le P&L en %, calculé sur la valeur de la position et non sur le compte.

### Accès

- **Un compte supprimé** : l'export est refusé (404), comme toute autre lecture
  de ce compte.
- **Le compte d'un autre utilisateur** : refusé (403).

### Langue

Les libellés et les valeurs traduites suivent la **langue du profil** de
l'utilisateur (français ou anglais, anglais par défaut), avec les mêmes termes
que l'interface. Les noms de setups, de plans et de champs personnalisés sont
ceux que l'utilisateur leur a donnés.

### Jeu de démo

Le compte démo « FTMO Challenge » (`demo@2a.journal`) contient trois trades
fermés découpés en plusieurs sorties et un ordre en attente à deux objectifs :

- objectifs touchés en partie, BE, puis clôture manuelle (l'exemple ci-dessus) ;
- tous les objectifs touchés ;
- TP1 touché, puis stop sur le reste.

C'est ce qui permet de voir l'export, et le détail d'un trade, sur autre chose
que des données de production. Un test du seeder échoue si ces cas
disparaissent.

## Choix d'implémentation

### Excel, une ligne par jambe

- **CSV** : un seul tableau. Les informations du compte n'y ont pas de place
  propre.
- **PDF** : une IA en extrait mal les tableaux.
- **Une ligne par position**, avec des groupes de colonnes « TP1… TPn » et
  « Sortie 1… Sortie n » : écarté. Le nombre de colonnes dépend des données, et
  un trade à une seule sortie répète sa sortie finale dans « Sortie 1 ».
- **Une ligne par jambe** : les colonnes sont fixes, le fichier se trie et se
  filtre directement, et un trade se relit en regroupant ses lignes par N°.

PhpSpreadsheet était déjà une dépendance (import, modèle d'import).

### Découpage

| Élément | Rôle |
|---|---|
| `GET /accounts/{id}/export` (`AccountController::export`) | Contrôleur fin, auth + abonnement comme les autres routes de compte |
| `AccountExportService` | Construit les trois onglets comme des **lignes de valeurs typées** (texte, nombre, date, vide), sans toucher à Excel. Tout le contenu du fichier se teste donc sans ouvrir de tableur |
| `AccountExportXlsxWriter` | Transforme ces lignes en octets `.xlsx` : un onglet par feuille, lignes d'en-tête en gras, première figée, colonnes ajustées |
| `AccountExportLabels` | Libellés et valeurs traduites (fr/en), dans les termes des locales du front |
| `PositionRepository::findForAccountExport` | Toutes les positions du compte avec leur ordre, leur trade, leur date de sécurisation et leur plan, en une requête |
| `PartialExitRepository::findByTradeIds` | Les sorties de tous les trades du compte, en une requête |
| `CustomFieldValueRepository::findByTradeIds`, `CustomFieldDefinitionRepository::findAllByUserId`, `SetupRepository::findAllByUserId` | Existants : champs personnalisés, leur ordre, catégories des setups |
| `Response::download` | Nouvelle réponse « fichier » du Core |

**Ordre exécuté** : quand un ordre s'exécute, sa position devient un trade
(même `position_id`). Une seule jointure sur `orders` donne donc la date de
placement de l'ordre, aussi bien pour un ordre que pour le trade qui en est issu.

**Rattachement des sorties** : une sortie partielle désigne l'objectif touché
par `target_id`, qui correspond à l'`id` de l'objectif dans `positions.targets`.
Une sortie sans `target_id`, ou dont l'objectif n'existe plus, devient une
jambe sans objectif.

**Propriété et suppression** : le service passe d'abord par
`AccountService::get`, qui refuse un compte supprimé (404) ou étranger (403)
avant toute lecture. La requête du repository porte en plus sa propre garde
(`user_id` + compte non supprimé).

**`Response::download`** : les téléchargements existants
(`ImportController::downloadTemplate`) font `header()` puis `exit` dans le
contrôleur, ce qui ne se teste pas en intégration. La nouvelle réponse porte les
octets et les en-têtes, et `send()` les écrit à la place de l'enveloppe JSON. Le
nom de fichier est nettoyé des guillemets et retours à la ligne avant d'entrer
dans `Content-Disposition` (injection d'en-tête).

**Libellés côté serveur** : un fichier n'a pas de client pour résoudre des clés
i18n. Comme pour les e-mails, le texte est choisi côté serveur d'après
`users.locale`. L'API ne renvoie toujours que des `message_key` pour ses
erreurs.

**Dates telles qu'en base** : l'application affiche les dates telles que
stockées. Le fichier fait pareil, pour qu'une date lue dans Excel soit celle vue
à l'écran.

### Injection de formule

Les notes, setups et champs personnalisés sont du texte libre. Une cellule qui
commence par `=`, `+`, `-` ou `@` peut être exécutée comme une formule par Excel
à l'ouverture. Le writer écrit **chaque cellule avec un type explicite** : une
chaîne reste une chaîne, quel que soit son premier caractère. Le texte est
conservé à l'identique et n'est jamais évalué.

### Nom du fichier côté front

`api.getBlob` ne rend que le contenu, pas les en-têtes. `exportFileName` (dans
`services/accounts.js`) applique donc la même règle que le serveur : nom du
compte en minuscules, tout caractère hors `a-z0-9` remplacé par `-`, puis la date
du jour. Sans lettre ni chiffre, le fichier s'appelle `account-<date>.xlsx`.

### Limite connue

Le fichier est construit en mémoire. Mesuré sur le writer : environ 99 Mo pour
5 000 lignes. L'export casse donc vers 7 500 lignes sur un même compte (limite
PHP de 128 Mo). Le plus gros compte en prod a 147 trades (2026-10-06), soit
quelques centaines de lignes. Le sujet est suivi dans `docs/evolutions.md`.

## Couverture des tests

### Backend

| Test | Scénario | Statut |
|---|---|---|
| `ResponseTest` (3 tests) | Octets bruts et en-têtes de fichier ; garde anti-injection d'en-tête ; pas de corps brut sur une réponse JSON | ✅ |
| `PositionRepositoryExportTest` (8 tests) | Ordres et trades de tous statuts ; ordre chronologique (ouverture, sinon placement) ; trade issu d'un ordre avec sa date de placement ; date de sécurisation ; tous les champs de la position, du trade et du plan ; autres comptes, compte d'un autre utilisateur et compte supprimé exclus | ✅ |
| `PartialExitRepositoryTest` (+2 tests) | Sorties groupées par trade dans l'ordre chronologique ; liste vide | ✅ |
| `AccountExportServiceTest` (26 tests) | Trois onglets ; colonnes identiques quelles que soient les données ; compte traduit ; limites vides ; ajustements du plus ancien au plus récent ; pas de tableau sans ajustement ; une ligne par objectif puis par sortie libre ; trade répété sur chaque ligne ; trade sans objectif à une sortie ; trade ouvert sans rien ; plusieurs sorties sur un objectif ; sortie sur un objectif disparu ; champs personnalisés en une cellule ; setups par typologie ; setup sorti du catalogue ; setup en texte libre ; colonnes retirées absentes ; trade issu d'un ordre ; ordres hors de l'onglet Trades ; ordre : une ligne par objectif ; ordre sans objectif ; anglais ; repli sur l'anglais ; nom du fichier et repli ; erreur d'accès sans autre lecture | ✅ |
| `AccountExportXlsxWriterTest` (8 tests) | Paquet xlsx ; un onglet par feuille dans l'ordre ; en-tête gras et figé ; plusieurs lignes d'en-tête ; nombres ; vraies dates Excel ; cellules vides ; **injection de formule** (`=`, `+`, `-`, `@` restent du texte) | ✅ |
| `AccountExportFlowTest` (5 tests) | Bout en bout : fichier relu avec ses trois onglets, valeurs traduites, note `=1+1` restée texte ; anglais selon le profil ; compte d'un autre → 403 ; compte supprimé → 404 ; sans jeton → 401 | ✅ |
| `SeedDemoTest::testSeedsTradesScaledOutOverSeveralTargets` | Le jeu de démo contient des trades à plusieurs objectifs, dont un découpé en plusieurs sorties et sécurisé au BE, et un ordre en attente à plusieurs objectifs | ✅ |

### Frontend

| Test | Scénario | Statut |
|---|---|---|
| `accounts-export-service.spec.js` (4 tests) | Appel `GET /accounts/{id}/export` en blob ; nom `<compte>-<date>.xlsx` puis libération de l'URL ; échec propagé sans téléchargement ; repli `account` | ✅ |
| `accounts-view-export.spec.js` (3 tests) | Bouton de la rangée (bureau) ; toast d'erreur avec la `message_key` ; entrée dans le menu mobile | ✅ |
