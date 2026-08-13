# Étape 96 — L'adresse IP réelle du visiteur

## Résumé

L'API compte ses limitations de débit par adresse IP. Derrière Cloudflare puis l'edge Railway, elle ne voyait que **21 adresses internes** — jamais celle du visiteur. Les quotas étaient donc mutualisés entre tous les utilisateurs au lieu d'être individuels.

`App\Core\ClientIpResolver` lit l'adresse transmise par le proxy, **mais uniquement quand la requête vient d'un hop de confiance**. La plage Railway est livrée par défaut : **aucune variable à créer**.

## Le problème

Vérifié en production sur 1000 lignes de logs : PHP ne voyait que `100.64.0.2` à `100.64.0.22`, soit 21 adresses internes Railway attribuées au hasard.

| Endpoint | Quota annoncé | Quota réel avant |
|---|---|---|
| `/auth/login` | 10 / 15 min / IP | ~210 / 15 min, partagés |
| `/auth/refresh` | 10 / 15 min / IP | ~210 / 15 min, partagés |
| `/auth/register` | 5 / 15 min / IP | ~105 / 15 min, partagés |
| `/auth/forgot-password` | 3 / 15 min / IP | ~63 / 15 min, partagés |

Le vrai danger n'était pas le bourrage de mot de passe — le verrouillage de compte après 5 échecs est indexé par utilisateur et fonctionne. C'était le **déni de service** : environ 210 appels à `/auth/refresh` en un quart d'heure vidaient le seau commun et **déconnectaient tout le monde**. Quelques dizaines suffisaient à bloquer `forgot-password` ou `register` pour toute la plateforme. Sans compte, sans outil.

Aucune exploitation constatée : zéro réponse 429 sur 59 h de logs de production.

## La règle

Les en-têtes transmis sont à la fois le correctif et le danger, puisque n'importe qui peut les envoyer. Ils ne sont lus **que si la requête nous parvient d'un hop déclaré de confiance**.

1. `REMOTE_ADDR` hors des plages de confiance → on le garde tel quel, les en-têtes sont ignorés.
2. `REMOTE_ADDR` dans une plage de confiance → `CF-Connecting-IP` d'abord (Cloudflare le pose lui-même et écrase celui du client), sinon `X-Forwarded-For`.
3. Quoi que ce soit d'inutilisable → repli sur `REMOTE_ADDR`.

### `X-Forwarded-For` se lit de droite à gauche

L'en-tête dit `client, proxy1, proxy2`. Seules les entrées de **droite** ont été ajoutées par une infrastructure qu'on contrôle. L'implémentation évidente — prendre la valeur la plus à gauche — prend exactement ce que l'appelant a écrit lui-même, c'est-à-dire la falsification que tout ceci sert à empêcher. On parcourt donc depuis la droite et on s'arrête au premier hop non fiable.

### Une plage mal écrite ne fait confiance à rien

Une faute de frappe dans la configuration échoue **fermé**, jamais ouvert : une plage qui ferait accidentellement confiance à tout livrerait le limiteur à quiconque envoie un en-tête. Comparaison en binaire (`inet_pton`), donc IPv4 et IPv6 passent par le même chemin sans jamais se croiser.

## Une seule plage, et pourquoi

**`100.64.0.0/10`**, le réseau interne Railway, livrée en valeur par défaut dans `config/security.php`.

Les plages publiées par Cloudflare sont **volontairement absentes**. PHP ne voit jamais une adresse Cloudflare dans `REMOTE_ADDR` : le dernier hop est toujours Railway. Les embarquer serait une liste à tenir à jour pour rien, et chaque plage de confiance supplémentaire élargit ce qu'on accepte de croire.

### Le contournement, vérifié

Faire confiance à `CF-Connecting-IP` ne vaut que si Cloudflare est le **seul** chemin d'accès : sinon on envoie l'en-tête forgé directement à Railway et on est cru sur parole. Vérifié le 2026-08-13 :

| Env | Domaine public | Devant |
|---|---|---|
| test | `test-api.2a-trading-tools.com` | Cloudflare + edge Railway |
| prod | `api.2a-trading-tools.com` | Cloudflare + edge Railway |

Aucun `*.up.railway.app` exposé en parallèle sur l'un ou l'autre : `RAILWAY_PUBLIC_DOMAIN` porte le domaine personnalisé dans les deux environnements. La porte dérobée n'existe pas.

`TRUSTED_PROXIES` (plages CIDR ou adresses nues, séparées par des virgules) remplace ce défaut le jour où l'infrastructure change. Si le journal sortait de derrière Cloudflare, le défaut resterait sans danger : `REMOTE_ADDR` ne tomberait plus dans cette plage, donc plus rien ne serait cru.

## Vérifier après le déploiement

Les adresses atterrissent dans la table `rate_limits`. C'est le témoin le plus direct :

```sql
SELECT ip, endpoint, attempts, window_start
FROM rate_limits
ORDER BY window_start DESC
LIMIT 20;
```

- **Avant** : uniquement des `100.64.0.x`.
- **Après** : de vraies adresses publiques, différentes d'un visiteur à l'autre.

La table ne se remplit que lorsqu'un endpoint limité est appelé (connexion, inscription, rafraîchissement de jeton, mot de passe oublié). Une simple connexion à l'application suffit à produire une ligne.

Si vous ne voyez encore que des `100.64.0.x` après un déploiement, c'est que la configuration n'est pas arrivée : vérifier qu'aucun `TRUSTED_PROXIES` vide ou erroné ne traîne sur le service.

## Le seul effet visible pour les utilisateurs

C'est le point à connaître : **les quotas deviennent réellement individuels, donc plus stricts qu'avant**. Avant, chacun puisait dans un seau commun de ~210 tentatives ; désormais chacun a le sien, aux valeurs annoncées.

Un utilisateur qui enchaîne des connexions ratées touchera donc une limite qu'il ne touchait pas auparavant. Les valeurs restent larges pour un humain — 10 connexions par quart d'heure — mais le cas peut se produire.

**Si quelqu'un se retrouve bloqué** : la limite est une fenêtre glissante de 15 minutes, elle se libère seule. Pour débloquer immédiatement, supprimer la ligne correspondante :

```sql
DELETE FROM rate_limits WHERE ip = '<son adresse>' AND endpoint = 'login';
```

Les quotas eux-mêmes se règlent dans `api/config/security.php`, section `rate_limits`.

## Tests

| Fichier | Portée |
|---|---|
| `tests/Unit/Core/ClientIpResolverTest.php` | 16 tests : repli sans proxy de confiance, en-tête forgé par un hop non fiable ignoré, priorité de `CF-Connecting-IP`, parcours de `X-Forwarded-For` **de droite à gauche** avec entrée forgée en tête de chaîne, plages IPv4 et IPv6, non-mélange v4/v6, plage malformée sans effet |
| `tests/Unit/Core/RequestTest.php` | 2 tests de câblage sur `Request::capture()` |
| `tests/Unit/Config/SecurityConfigTest.php` | 4 tests : plage Railway par défaut, absence assumée de Cloudflare, surcharge par `TRUSTED_PROXIES`, tolérance aux espaces et virgules vides |

Suites complètes : **1125 unitaires**, **748 intégration**.
