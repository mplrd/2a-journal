-- Migration 038 — Marquer l'origine des objectifs déjà synchronisés
--
-- `positions.targets` porte désormais un `source` par niveau. La synchro
-- estampille `"source": "broker"` sur ce qu'elle écrit, et ne réécrit que les
-- ensembles dont TOUS les niveaux portent ce marqueur. Un objectif saisi au
-- formulaire n'en a pas : il reste intouchable.
--
-- Pourquoi : la règle d'avant était « un TP broker ne remplit qu'un
-- emplacement vide ». Elle ne distinguait pas les deux origines, donc elle
-- gelait aussi les objectifs venus de la synchro. Déplacer un take profit sur
-- la plateforme n'atteignait jamais le journal, et en retirer un laissait
-- l'ancien niveau en place indéfiniment.
--
-- Sans cette migration, les lignes déjà en base n'auraient aucun marqueur et
-- seraient donc traitées comme saisies à la main : gelées pour toujours, alors
-- qu'elles viennent toutes de la synchro.
--
-- Le périmètre est restreint à ce qu'on peut PROUVER : `external_id` et
-- `import_batch_id` tous deux non nuls. Sur ce profil, seuls
-- BrokerOpenSyncService et BrokerOrderSyncService écrivent `targets` —
-- l'import de fichier n'en écrit pas, et une position saisie à la main n'a ni
-- external_id ni import_batch_id. Une position synchronisée dont l'utilisateur
-- aurait retouché les objectifs se retrouve donc marquée « broker » à tort et
-- se réalignera à la passe suivante : c'est le choix assumé, l'alignement avec
-- le broker prime, et il suffit de les ressaisir pour en reprendre possession.
--
-- Idempotente : la clause NOT JSON_CONTAINS_PATH écarte ce qui est déjà marqué.
-- Additive : aucune colonne, aucune suppression, on ajoute une clé au JSON.
--
-- Huit indices couvrent largement le maximum observé (cTrader étage jusqu'à
-- cinq paliers, plus le takeProfit de la position). JSON_SET ignore un chemin
-- dont le parent n'existe pas, donc les indices au-delà du tableau sont sans
-- effet et n'ajoutent aucun élément fantôme.

UPDATE positions
SET targets = JSON_SET(
        targets,
        '$[0].source', 'broker',
        '$[1].source', 'broker',
        '$[2].source', 'broker',
        '$[3].source', 'broker',
        '$[4].source', 'broker',
        '$[5].source', 'broker',
        '$[6].source', 'broker',
        '$[7].source', 'broker'
    )
WHERE external_id IS NOT NULL
  AND import_batch_id IS NOT NULL
  AND targets IS NOT NULL
  AND JSON_LENGTH(targets) > 0
  AND NOT JSON_CONTAINS_PATH(targets, 'one', '$[0].source');
