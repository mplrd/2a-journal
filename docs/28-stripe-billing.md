# 28 — Abonnement Stripe (pay-only 5€/mois)

## Contexte & modèle

Passage de l'app sous **abonnement obligatoire** :

- **5 €/mois**, modèle pay-only (pas de free tier, pas d'essai gratuit sauf via grâce initiale).
- **Période de grâce** de 14 jours à l'inscription pour laisser le temps de découvrir / décider.
- **Coupons "forever"** supportés nativement (codes promo qui s'appliquent sur toute la vie de la souscription).
- **Stripe Checkout** (page hébergée) + **Billing Portal** pour la gestion de l'abonnement.

## Scope

### Dans le scope

- Paywall : middleware backend qui bloque les endpoints métier si pas d'abo actif (ni grâce, ni bypass).
- Page `/subscribe` côté front, intégrée au flow post-register.
- Endpoints `/billing/checkout`, `/billing/portal`, `/billing/webhook`.
- Table `subscriptions` + colonnes user (`bypass_subscription`, `grace_period_end`, `stripe_customer_id`).
- Webhooks Stripe pour synchroniser l'état (5 events).
- Exemption demo via `bypass_subscription = 1`.
- Migration qui backfill `grace_period_end = NOW() + 14 days` pour les users existants.
- i18n fr + en.
- Tests unit + integration + frontend.

### Hors scope

- **Plusieurs tiers** (tout le monde à 5 €/mois pour l'instant).
- **Changement de plan** (pas besoin, un seul plan).
- **Facturation annuelle** (ajoutable plus tard via un second Price).
- **Refunds automatiques** : l'utilisateur va sur le Billing Portal / Stripe s'il a un souci.
- **Email marketing / onboarding payment** : le mail de confirmation Stripe suffit pour l'instant.
- **Pro-rations** : pas pertinent avec un seul plan.

## Architecture

### Base de données (migration `004_stripe_billing.sql`)

```sql
-- Colonnes ajoutées sur users
ALTER TABLE users
    ADD COLUMN bypass_subscription TINYINT(1) NOT NULL DEFAULT 0 AFTER be_threshold_percent,
    ADD COLUMN grace_period_end TIMESTAMP NULL DEFAULT NULL AFTER bypass_subscription,
    ADD COLUMN stripe_customer_id VARCHAR(255) NULL DEFAULT NULL AFTER grace_period_end,
    ADD UNIQUE KEY uk_users_stripe_customer (stripe_customer_id);

-- Backfill : 14 jours de grâce pour les users existants au déploiement
UPDATE users
SET grace_period_end = DATE_ADD(NOW(), INTERVAL 14 DAY)
WHERE grace_period_end IS NULL AND deleted_at IS NULL;

-- Table subscriptions (1-1 avec users, mais extraite pour clarté)
CREATE TABLE IF NOT EXISTS subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    stripe_subscription_id VARCHAR(255) NOT NULL,
    status ENUM('incomplete','incomplete_expired','trialing','active','past_due','canceled','unpaid','paused') NOT NULL,
    current_period_end TIMESTAMP NULL,
    cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_subscriptions_user (user_id),
    UNIQUE KEY uk_subscriptions_stripe (stripe_subscription_id),
    CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table webhook_events pour idempotence (un event Stripe peut arriver plusieurs fois)
CREATE TABLE IF NOT EXISTS stripe_webhook_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_event_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_webhook_stripe_event (stripe_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Configuration

**Composer** : `composer require stripe/stripe-php`.

**Env vars** (séparées test/prod via les env Railway) :

```
STRIPE_SECRET_KEY=sk_test_...      # ou sk_live_... en prod
STRIPE_WEBHOOK_SECRET=whsec_...    # issu du dashboard Stripe
STRIPE_PRICE_ID=price_...          # Price ID du plan 5€/mois
BILLING_GRACE_DAYS=14              # modifiable sans déployer
FRONTEND_URL=http://2a.journal.local  # pour construire les return_url Checkout
```

Dans Stripe Dashboard (deux fois, une en Test, une en Live) :
1. Créer un **Product** : "Trading Journal".
2. Créer un **Price** : 5 €/mois récurrent. Noter le `price_...` id.
3. Créer le **Promotion Code personnel** : 100 %, `duration: forever`, usage limité à 1, restreint à l'email du compte perso.
4. Créer un **Webhook Endpoint** pointant vers `https://2a-journal.prod/api/billing/webhook`, écoutant 5 events (cf. plus bas). Noter le `whsec_...`.
5. Activer **Billing Portal** (one-click dans Settings → Billing → Customer portal).

### Backend — couches

**`StripeClient`** (wrapper léger) :
- Construit `\Stripe\StripeClient` depuis `STRIPE_SECRET_KEY`.
- Testable : injecté dans `BillingService`, mockable dans les tests unitaires.

**`SubscriptionRepository`** :
- `findByUserId(int $userId): ?array`
- `upsertFromStripe(int $userId, array $stripeSubscription): void` (INSERT ... ON DUPLICATE KEY UPDATE)
- `deleteByStripeId(string $stripeSubId): void`

**`WebhookEventRepository`** :
- `existsByStripeId(string $eventId): bool`
- `markProcessed(string $eventId, string $eventType): void`

**`BillingService`** :
- `createCheckoutSession(int $userId): string` → retourne l'URL Stripe Checkout. Crée le Stripe Customer si absent.
- `createBillingPortalSession(int $userId): string` → retourne l'URL du portal.
- `handleWebhook(string $payload, string $signature): void` → vérifie la signature, dispatch par `event.type`, idempotence via `stripe_webhook_events`.
- `hasActiveAccess(int $userId): bool` → utilisé par le middleware.

**`BillingController`** :
- `POST /billing/checkout` (auth requis) → renvoie `{ url: string }`.
- `POST /billing/portal` (auth requis, abo existant requis) → renvoie `{ url: string }`.
- `POST /billing/webhook` (pas d'auth, signature Stripe fait foi) → renvoie 200 ou 4xx selon la vérif.
- `GET /billing/status` (auth requis) → renvoie `{ has_access, reason, grace_period_end, subscription: {...} }` pour le front.

**`RequireActiveSubscriptionMiddleware`** :
- Logique : passe si `user.bypass_subscription = 1` OU `user.grace_period_end > NOW()` OU `subscription.status ∈ {active, trialing}`.
- Sinon : 402 Payment Required avec `message_key: 'billing.error.subscription_required'`.
- **Appliqué à tous les endpoints métier** (accounts, trades, positions, stats, import, broker, custom-fields, symbols, setups). **Pas appliqué** aux endpoints `/auth/*` et `/billing/*`.

### Frontend — couches

**`billingService`** (dans `frontend/src/services/billing.js`) :
- `createCheckoutSession()`, `createPortalSession()`, `getStatus()`.

**`useBilling` composable** :
- Expose `hasAccess`, `status`, `subscription`, `gracePeriodEnd`.
- Fetch au mount, refresh après retour de Stripe.

**`SubscribeView.vue`** (route `/subscribe`) :
- Affichée automatiquement si le middleware backend renvoie 402.
- Bouton "S'abonner 5€/mois" → appelle `/billing/checkout` → redirect vers URL Stripe.
- Affiche le status courant (grâce jusqu'au X, abo annulé, etc.).
- Lien vers Billing Portal si déjà abonné.

**`SubscribeSuccessView.vue`** (route `/subscribe/success`) :
- Stripe redirige ici après paiement OK.
- Affiche un spinner, fetch `/billing/status` jusqu'à `has_access = true` (max 10s), puis redirect dashboard.
- Couvre le cas où le webhook arrive après le retour utilisateur.

**Intercepteur API** :
- Dans `api.js`, si la réponse renvoie 402 → `router.push('/subscribe')`.

**Router guard** :
- En complément de l'intercepteur, un guard `beforeEach` qui bloque les routes métier si `useBilling().hasAccess` est false. Redirige `/subscribe`.
- Exclut `/subscribe`, `/subscribe/success`, `/login`, `/register`, `/verify-email`, `/forgot-password`.

### Webhooks écoutés

| Event | Action |
|---|---|
| `checkout.session.completed` | Premier paiement. Stocke `stripe_customer_id` sur user, crée la row `subscriptions` via le subscription object retourné. |
| `customer.subscription.updated` | Met à jour status, `current_period_end`, `cancel_at_period_end`. Couvre renouvellements, annulations programmées, changements de méthode de paiement, etc. |
| `customer.subscription.deleted` | Status → `canceled`. Le user est désormais bloqué par le middleware. |
| `invoice.payment_succeeded` | Prolongation normale, sync `current_period_end`. |
| `invoice.payment_failed` | Log uniquement. Stripe gère les retries automatiquement via Smart Retries. Le user passera en `past_due` → puis `unpaid` / `canceled` selon config, ce qui est capté par `customer.subscription.updated/deleted`. |

### Idempotence des webhooks

Stripe peut redélivrer un event. On stocke chaque `stripe_event_id` traité dans `stripe_webhook_events` ; si on reçoit deux fois le même, on répond 200 immédiatement sans retraiter.

### Vérification de signature

`\Stripe\Webhook::constructEvent($payload, $sig, $secret)` — lance une exception si signature invalide. Le controller renvoie 400 dans ce cas. **Aucune confiance accordée au payload tant que la signature n'est pas validée.**

## Flows

### Flow nouveau user

1. `POST /auth/register` → user créé avec `grace_period_end = NOW() + 14 days`.
2. Email de vérif envoyé (flow inchangé).
3. Access token retourné, user accède à l'app (grâce active, le middleware laisse passer).
4. Bandeau persistent "Essai 14 jours — [S'abonner]" visible sur toutes les pages.
5. Au bout de 14 jours : intercepteur 402 → redirect `/subscribe`.

### Flow souscription

1. User sur `/subscribe` clique "S'abonner 5€/mois".
2. Front appelle `POST /billing/checkout`.
3. Backend : crée le Stripe Customer (si pas encore) → crée une Checkout Session avec `allow_promotion_codes: true`, `mode: subscription`, `customer: <id>`, `line_items: [{ price: STRIPE_PRICE_ID, quantity: 1 }]`, `success_url: FRONTEND_URL/subscribe/success?session_id={CHECKOUT_SESSION_ID}`, `cancel_url: FRONTEND_URL/subscribe`.
4. Retour `{ url }` → front `window.location = url`.
5. User paye sur Stripe (peut saisir un promo code).
6. Stripe envoie webhook `checkout.session.completed` → on persiste subscription.
7. Stripe redirige `/subscribe/success?session_id=...` → poll `/billing/status` jusqu'à `has_access` → redirect dashboard.

### Flow annulation

1. User clique "Gérer mon abonnement" sur `/subscribe` (visible si abo existe) → `POST /billing/portal` → redirect URL portal.
2. User annule dans le portal.
3. Stripe envoie webhook `customer.subscription.updated` avec `cancel_at_period_end: true`.
4. On met à jour la DB.
5. User garde accès jusqu'à `current_period_end`.
6. À l'échéance, Stripe envoie `customer.subscription.deleted`.
7. On met status = `canceled`. Middleware bloque désormais → redirect `/subscribe` au prochain appel API.

### Flow bypass (demo)

- `users.bypass_subscription = 1` → middleware passe inconditionnellement.
- Le seeder `seed-demo.php` set le flag à 1 sur le user demo.

### Flow compte perso (Maxime)

- Souscription normale via Checkout.
- Saisie d'un Promo Code 100 % `forever` créé manuellement dans le dashboard Stripe (restreint à ton email).
- Stripe encaisse 0 € chaque mois, abo reste `active`, accès complet.

## TDD

### Backend — Unit

**`BillingServiceTest`** :
- `createCheckoutSession` crée un Customer Stripe si absent, en réutilise un sinon.
- `handleWebhook` vérifie la signature (throw si invalide).
- `handleWebhook` est idempotent (même event_id traité deux fois → second appel no-op).
- `handleWebhook` dispatch chaque event type vers le bon handler.
- `hasActiveAccess` : bypass → true, grâce active → true, subscription active → true, rien → false.

**`RequireActiveSubscriptionMiddlewareTest`** :
- 4 scénarios : bypass, grâce active, abo actif, bloqué (402).

### Backend — Integration

**`BillingFlowTest`** :
- `POST /billing/checkout` sans auth → 401.
- `POST /billing/checkout` avec auth → 200 + URL.
- `POST /billing/webhook` sans signature → 400.
- `POST /billing/webhook` avec signature valide + event `checkout.session.completed` → row `subscriptions` créée.
- `POST /billing/webhook` même event reçu deux fois → traité une seule fois.
- Endpoint métier (`GET /accounts`) sans abo ni grâce → 402.
- Endpoint métier avec grâce active → 200.

### Frontend — Vitest

**`subscribe-view.spec.js`** :
- Affichage du CTA "S'abonner" quand pas d'abo.
- Affichage du bouton "Gérer" + état quand abo existe.
- Clic sur CTA appelle `billingService.createCheckoutSession`.

**`api-interceptor.spec.js`** : (nouveau ou extension de `api.spec.js`)
- 402 → redirect `/subscribe`.

## Livrables

**Backend** :
- `api/database/migrations/004_stripe_billing.sql`
- `api/database/schema.sql` (mise à jour)
- `api/src/Repositories/SubscriptionRepository.php`
- `api/src/Repositories/WebhookEventRepository.php`
- `api/src/Services/BillingService.php`
- `api/src/Controllers/BillingController.php`
- `api/src/Middlewares/RequireActiveSubscriptionMiddleware.php`
- `api/config/routes.php` (4 routes + middleware appliqué sur les routes métier)
- `api/src/Repositories/UserRepository.php` (inclure les nouvelles colonnes dans `findById`/`findByEmail`, setter pour `stripe_customer_id`)
- `api/src/Services/AuthService.php` (set `grace_period_end` à la création)
- `api/database/seed-demo.php` (set `bypass_subscription = 1` sur demo)
- `composer.json` (+ stripe/stripe-php)

**Frontend** :
- `frontend/src/services/billing.js`
- `frontend/src/composables/useBilling.js`
- `frontend/src/stores/billing.js` (optionnel, peut se passer du store)
- `frontend/src/views/SubscribeView.vue`
- `frontend/src/views/SubscribeSuccessView.vue`
- `frontend/src/router/index.js` (+ routes + guard)
- `frontend/src/services/api.js` (intercept 402)
- `frontend/src/components/layout/AppLayout.vue` (bandeau "grâce jusqu'au X" ou équivalent)
- `frontend/src/locales/{fr,en}.json` (bloc `billing.*`)

**Tests** :
- `api/tests/Unit/Services/BillingServiceTest.php`
- `api/tests/Unit/Middlewares/RequireActiveSubscriptionMiddlewareTest.php`
- `api/tests/Integration/Billing/BillingFlowTest.php`
- `frontend/src/__tests__/subscribe-view.spec.js`

**Docs** :
- `docs/28-stripe-billing.md` (ce fichier)

## Variables d'environnement à configurer

| Variable | Local (test) | Prod (live) |
|---|---|---|
| `STRIPE_SECRET_KEY` | `sk_test_...` | `sk_live_...` |
| `STRIPE_WEBHOOK_SECRET` | `whsec_...` (local via Stripe CLI) | `whsec_...` (endpoint prod) |
| `STRIPE_PRICE_ID` | `price_test_...` | `price_live_...` |
| `BILLING_GRACE_DAYS` | `14` | `14` |
| `FRONTEND_URL` | `http://2a.journal.local` | `https://...` |

**Note sur les webhooks en local** : pour tester localement, utiliser `stripe listen --forward-to http://2a.journal.local/api/billing/webhook` (Stripe CLI), qui génère un `whsec_...` temporaire à mettre dans `.env`.
