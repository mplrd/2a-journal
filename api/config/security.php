<?php

return [
    // Hops autorisés à nous dire pour le compte de qui ils parlent.
    //
    // Le trafic arrive par Cloudflare puis l'edge Railway, donc l'adresse que
    // voit PHP est TOUJOURS une adresse interne Railway — 100.64.0.2 à
    // 100.64.0.22 sur 1000 lignes de logs de production. C'est la seule plage
    // à déclarer, et elle est livrée par défaut : le correctif s'applique au
    // déploiement, sans variable à créer ni à oublier.
    //
    // Les plages publiées par Cloudflare sont volontairement absentes : PHP ne
    // voit jamais une adresse Cloudflare dans REMOTE_ADDR. Les embarquer serait
    // une liste à tenir à jour pour rien, et chaque plage de confiance en plus
    // élargit ce qu'on accepte de croire.
    //
    // Sans liste blanche du tout, n'importe qui forgerait `CF-Connecting-IP`,
    // choisirait son seau de rate limit et deviendrait intraçable — pire que le
    // seau partagé que tout ceci corrige. C'est pourquoi une plage inconnue ne
    // fait jamais confiance par défaut : voir App\Core\ClientIpResolver.
    //
    // TRUSTED_PROXIES (plages CIDR ou adresses nues, séparées par des virgules)
    // remplace ce défaut le jour où l'infrastructure change.
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) (getenv('TRUSTED_PROXIES') ?: '100.64.0.0/10')),
    ), fn(string $range): bool => $range !== '')),
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
    ],
    'rate_limits' => [
        'login' => ['max_attempts' => 10, 'window_seconds' => 900],
        'register' => ['max_attempts' => 5, 'window_seconds' => 900],
        'refresh' => ['max_attempts' => 10, 'window_seconds' => 900],
        'forgot_password' => ['max_attempts' => 3, 'window_seconds' => 900],
        // SSO: prevent flood-of-codes DoS on issuance and brute-force probing
        // on exchange. Per-IP, both share the standard middleware.
        'sso_issue' => ['max_attempts' => 30, 'window_seconds' => 300],
        'sso_exchange' => ['max_attempts' => 30, 'window_seconds' => 300],
    ],
    'lockout' => [
        'max_attempts' => 5,
        'lockout_seconds' => 900, // 15 minutes
    ],
];
