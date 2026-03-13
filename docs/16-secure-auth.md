# 16 - Sécurisation de l'authentification

## Fonctionnalités

### Vérification d'email à l'inscription
- À l'inscription, un token de vérification est généré et un email de confirmation est envoyé
- L'utilisateur peut se connecter sans vérification mais voit une bannière d'avertissement sur le dashboard
- Endpoint `GET /auth/verify-email?token=xxx` pour valider l'email (met à jour `email_verified_at`)
- Endpoint `POST /auth/resend-verification` (auth requis) pour renvoyer le mail
- Le champ `email_verified` (booléen) est inclus dans les réponses `/auth/me`, register et login
- **Toggle de configuration** : `EMAIL_VERIFICATION_ENABLED` dans `.env` (défaut `true`). Si `false`, `email_verified_at` est auto-rempli à l'inscription et aucun mail n'est envoyé — utile pour le développement local

### Verrouillage de compte anti-bruteforce
- Compteur de tentatives échouées **par compte** (`failed_login_attempts` dans `users`)
- Après 5 tentatives échouées consécutives (configurable dans `security.php`), le compte est verrouillé pour 15 minutes
- Champ `locked_until` (timestamp) dans `users`
- Le compteur se remet à 0 après un login réussi
- Un email de notification est envoyé en cas de verrouillage (si l'envoi d'email est activé)
- Réponse HTTP 423 avec code `ACCOUNT_LOCKED` quand le compte est verrouillé
- Le rate limiting par IP existant reste en place (double protection)

### Mot de passe oublié / réinitialisation
- `POST /auth/forgot-password` : génère un token, envoie un email avec lien de reset
- `POST /auth/reset-password` : valide le token, change le mot de passe
- Table `password_reset_tokens` pour stocker les tokens (usage unique, expiration 1h)
- Toujours retourne 200 sur forgot-password pour éviter l'énumération d'emails
- La réinitialisation remet aussi à zéro le compteur de tentatives et déverrouille le compte
- Rate limiting sur forgot-password (3 tentatives par 15 minutes)
- Le nouveau mot de passe doit respecter les mêmes règles de complexité

### Service d'envoi d'email
- `EmailService` basé sur la fonction `mail()` native PHP
- Configuration dans `api/config/mail.php` : `from_address`, `from_name`, `enabled`
- Si `MAIL_ENABLED=false`, les emails sont juste loggés (utile pour le développement)
- Templates HTML pour : vérification email, reset password, compte verrouillé
- Les emails sont envoyés dans la langue de l'utilisateur (fr/en)

## Choix d'implémentation

### Architecture
- **Repositories dédiés** : `EmailVerificationTokenRepository` et `PasswordResetTokenRepository` pour la séparation des responsabilités
- **AuthService étendu** : les nouvelles méthodes (`verifyEmail`, `resendVerification`, `forgotPassword`, `resetPassword`) sont ajoutées au service existant
- **Injection de dépendances optionnelle** : les nouveaux repos et services sont injectés avec des paramètres nullable pour la compatibilité ascendante avec les tests unitaires existants

### Sécurité
- Tokens de 64 caractères (32 bytes hex) pour la vérification email et le reset password
- Tokens à usage unique (supprimés après utilisation)
- Expiration configurable (24h pour vérification, 1h pour reset)
- Le `forgot-password` ne révèle jamais si l'email existe (protection contre l'énumération)
- Le lockout protège contre le bruteforce ciblé par compte, en complément du rate limiting par IP
- La réinitialisation du mot de passe invalide tous les refresh tokens (force la re-connexion)

### Frontend
- 3 nouvelles vues : `ForgotPasswordView`, `ResetPasswordView`, `VerifyEmailView`
- Composant `EmailVerificationBanner` affiché sur le dashboard si l'email n'est pas vérifié
- Lien "Mot de passe oublié ?" ajouté sur la page de connexion
- Routes guest pour forgot/reset password, route publique pour verify-email

### Configuration
| Variable d'environnement | Défaut | Description |
|---|---|---|
| `EMAIL_VERIFICATION_ENABLED` | `true` | Active/désactive la vérification d'email |
| `MAIL_ENABLED` | `false` | Active/désactive l'envoi réel d'emails |
| `MAIL_FROM_ADDRESS` | `noreply@2a-journal.local` | Adresse d'expédition |
| `MAIL_FROM_NAME` | `Trading Journal` | Nom d'expédition |
| `FRONTEND_URL` | `http://localhost:5173` | URL du frontend (pour les liens dans les emails) |

### Tables ajoutées
- `email_verification_tokens` : `user_id`, `token` (unique), `expires_at`, `created_at`
- `password_reset_tokens` : `user_id`, `token` (unique), `expires_at`, `created_at`

### Colonnes ajoutées à `users`
- `failed_login_attempts` : INT UNSIGNED DEFAULT 0
- `locked_until` : TIMESTAMP NULL

## Couverture des tests

### Backend (34 nouveaux tests)

| Fichier | Scénario | Statut |
|---|---|---|
| `AccountLockoutTest` | Incrémentation des tentatives échouées | OK |
| `AccountLockoutTest` | Verrouillage après max tentatives | OK |
| `AccountLockoutTest` | Reset du compteur après login réussi | OK |
| `AccountLockoutTest` | Déverrouillage automatique après timeout | OK |
| `AccountLockoutTest` | Compte verrouillé ne peut pas se connecter | OK |
| `AccountLockoutTest` | Pas de crash sur email inconnu | OK |
| `EmailVerificationTest` | Inscription crée un token de vérification | OK |
| `EmailVerificationTest` | Inscription retourne email_verified=false | OK |
| `EmailVerificationTest` | Vérification d'email réussie | OK |
| `EmailVerificationTest` | email_verified_at est défini après vérification | OK |
| `EmailVerificationTest` | Token invalide retourne 400 | OK |
| `EmailVerificationTest` | Token expiré retourne 400 | OK |
| `EmailVerificationTest` | Token supprimé après utilisation | OK |
| `EmailVerificationTest` | Token manquant retourne 422 | OK |
| `EmailVerificationTest` | Renvoi de vérification réussi | OK |
| `EmailVerificationTest` | Renvoi remplace l'ancien token | OK |
| `EmailVerificationTest` | Renvoi impossible si déjà vérifié | OK |
| `EmailVerificationTest` | Renvoi impossible sans auth | OK |
| `EmailVerificationTest` | /me retourne email_verified=false | OK |
| `EmailVerificationTest` | /me retourne email_verified=true | OK |
| `EmailVerificationTest` | Auto-vérification si config désactivée | OK |
| `PasswordResetTest` | Forgot password réussi | OK |
| `PasswordResetTest` | Forgot password crée un token | OK |
| `PasswordResetTest` | Email inconnu retourne quand même 200 | OK |
| `PasswordResetTest` | Email manquant retourne 422 | OK |
| `PasswordResetTest` | Nouveau forgot remplace l'ancien token | OK |
| `PasswordResetTest` | Reset password réussi | OK |
| `PasswordResetTest` | Login avec nouveau mot de passe | OK |
| `PasswordResetTest` | Token supprimé après utilisation | OK |
| `PasswordResetTest` | Token expiré retourne 400 | OK |
| `PasswordResetTest` | Token invalide retourne 400 | OK |
| `PasswordResetTest` | Mot de passe faible retourne 422 | OK |
| `PasswordResetTest` | Champs manquants retourne 422 | OK |
| `PasswordResetTest` | Reset déverrouille le compte | OK |

### Frontend
- Build OK, 123 tests existants toujours verts
- Nouvelles vues testées via le build (compilation sans erreur)
