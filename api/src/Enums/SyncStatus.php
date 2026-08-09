<?php

namespace App\Enums;

enum SyncStatus: string
{
    case STARTED = 'STARTED';
    case SUCCESS = 'SUCCESS';
    case PARTIAL = 'PARTIAL';
    case FAILED = 'FAILED';

    /**
     * Une synchro qui n'a pas eu lieu parce qu'une autre tenait déjà la
     * réservation de la connexion. Issue de run uniquement : la réservation est
     * prise avant la création du sync_log, donc ce statut n'est jamais persisté
     * — ne pas l'écrire en base sans étendre l'ENUM de `sync_logs.status`.
     */
    case SKIPPED = 'SKIPPED';

    /**
     * Une synchro demandée depuis l'IHM et pas encore exécutée : le scheduler
     * la reprend au prochain tick. Comme SKIPPED, issue d'appel uniquement,
     * jamais persistée.
     */
    case QUEUED = 'QUEUED';
}
