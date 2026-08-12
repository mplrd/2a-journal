-- Migration 036 — Identifiants d'application partagés par provider
--
-- Un utilisateur ayant deux comptes cTrader saisit aujourd'hui deux fois
-- client_id, client_secret, access_token et refresh_token : seul le
-- ctidTraderAccountId diffère entre les deux connexions. Au-delà de la double
-- saisie, cela impose de répercuter à la main toute rotation de secret, et fait
-- un refresh OAuth par connexion là où un seul suffirait — ce qui pèse
-- directement sur le budget de requêtes (évol #22).
--
-- `broker_credentials` porte donc ce qui appartient à l'utilisateur, une ligne
-- par provider ; `broker_connections` ne garde que ce qui identifie le compte
-- broker. Les deux blobs sont fusionnés au moment de servir un connecteur, donc
-- ConnectorInterface est inchangée.
--
-- Même chiffrement que broker_connections (CredentialEncryptionService, AES-256
-- avec BROKER_ENCRYPTION_KEY) : mêmes colonnes ciphertext + IV, même format.
--
-- PURGE ASSUMÉE — décision du 2026-08-09. Les connexions existantes stockent
-- tout dans leur propre blob ; plutôt que de deviner quelle partie est
-- partageable, on les supprime : personne n'utilise le broker en dehors de
-- l'env de test, et la reconnexion prend trente secondes. Conséquence acceptée :
-- `sync_logs` est en ON DELETE CASCADE sur broker_connections, l'historique des
-- passes de synchro part avec. Les trades et positions sont rattachés aux
-- COMPTES, pas aux connexions : ils survivent intégralement. Le curseur de
-- synchro étant perdu, la passe suivante rebalaie l'historique sans réimporter
-- quoi que ce soit (déduplication sur external_id).
--
-- Le runner (database/migrate.php) trace les migrations par nom de fichier :
-- ce DELETE ne s'exécute qu'une fois, jamais à chaque boot.

CREATE TABLE IF NOT EXISTS broker_credentials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    provider ENUM('CTRADER','METAAPI','OUINEX','BINGX') NOT NULL,
    credentials_encrypted TEXT NOT NULL,
    credentials_iv VARCHAR(32) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Une seule ligne d'identifiants d'application par utilisateur et par
    -- provider : c'est la contrainte qui matérialise le partage.
    UNIQUE KEY uk_broker_credentials_user_provider (user_id, provider),
    CONSTRAINT fk_broker_credentials_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM broker_connections;
