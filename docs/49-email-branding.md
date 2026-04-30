# Étape 49 — Charte graphique appliquée aux emails transactionnels

## Résumé

Application de la charte graphique 2A Trading Tools sur les 3 emails transactionnels du backend (`verification`, `password-reset`, `account-locked`) en français et anglais. Le layout commun `layout.html` est entièrement réécrit en HTML email-safe (table-based, styles inline) avec logo de marque, palette navy/green/cream et typographie cohérente avec l'application.

## Fonctionnalités

### Logo email

Le logo `2a-trading-tools_logo-light.png` (référentiel `docs/charte-graphique/images/`) est copié dans `frontend/public/email-logo.png` afin d'être servi à une URL stable depuis le frontend (à charge des MUA de le télécharger et l'afficher). Le PNG fait 35 KB, dimensions 1024×1024 (carré, fond transparent), affiché en 120×120 dans l'email.

### Layout `api/templates/emails/layout.html`

Réécrit de zéro selon les conventions HTML email :
- Wrapper `<table role="presentation">` 600px max, fond `--brand-cream` (`#e8e8e6`).
- Carte interne fond blanc, bord arrondi, bordure `gray-200` charte (`#d8d8d4`).
- Header : logo centré + filet horizontal `brand-green-700` (`#2d7952`) en bas.
- Titre `<h1>` 22px en `brand-navy-900` (`#1f2a3c`), corps en `gray-700` charte (`#3a3d44`) à 15px / 1.6.
- Footer : zone `gray-50` charte (`#f7f7f5`) + libellé `app_name` centré en 12px.
- Police : pile système (`-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, …`). Inter n'est pas utilisé : la majorité des MUA bloque les Google Fonts et fallback en Times silencieusement.

Le layout reçoit 4 placeholders : `{{title}}`, `{{content}}`, `{{app_name}}`, et **un nouveau** `{{frontend_url}}` (utilisé pour le `src` du logo).

### `EmailService::wrapLayout()`

Étendu pour injecter `frontend_url` dans le layout (déjà présent dans la config). Le slash final est strippé (`rtrim`) pour éviter `//email-logo.png` dans le rendu.

```php
$frontendUrl = htmlspecialchars(
    rtrim((string) ($this->config['frontend_url'] ?? ''), '/'),
    ENT_QUOTES, 'UTF-8'
);
```

### Templates body (6 fichiers)

Les 3 emails × 2 langues sont harmonisés :
- Bouton CTA passé de `bg #3b82f6` (blue-500 hors charte) à `#2d7952` (brand-green-700) avec `font-weight:600`, padding `12px 28px`.
- Marges des `<p>` explicitées (`margin:0 0 20px 0`) pour rendu prévisible (Outlook ajoute des marges par défaut sinon).
- Couleur secondaire passée de `#666` à `#6b6e75` (gray neutre charte) et `font-size` de 12px → 13px pour lisibilité.

## Choix d'implémentation

### Pourquoi PNG plutôt que SVG ou data-URI

- **SVG** : Outlook desktop Windows (Word rendering engine) ignore complètement le SVG ; Gmail web le rend mal sur certains paths complexes.
- **data-URI inline** : Outlook desktop ignore les `src="data:..."` dans `<img>` ; Gmail bloque les emails avec un payload data trop lourd.
- **PNG hébergé** : universel, mis en cache par les MUA, taille raisonnable (35 KB). C'est l'approche standard (Stripe, GitHub, Linear emails).

### Pourquoi copier le logo dans `frontend/public/` plutôt que `api/public/`

Le frontend a déjà une URL publique stable (`FRONTEND_URL`) qui est passée au backend pour les liens d'action (`/verify-email`, `/reset-password`). Réutiliser ce host pour le logo évite d'introduire une seconde URL publique à configurer (`API_PUBLIC_URL` ou équivalent). Côté Vite, tout fichier dans `frontend/public/` est servi tel quel à la racine.

### Pourquoi pile de polices système et pas Inter

La charte utilise Inter dans l'app (chargé via Google Fonts). Mais dans les emails :
- Outlook desktop ignore `<link>` et `@import` (pas de Web Font).
- Gmail strippe les `<link>` aux Google Fonts.
- Charger Inter via `@font-face` data-URI gonfle l'email de 100+ KB et n'est pas garanti.

Le fallback Times Roman (vu si Inter n'est pas chargé) est laid et casse la charte. La pile système (`-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial`) garantit une typographie sans serif moderne sur 100% des MUA, proche d'Inter visuellement.

### Pourquoi style inline et pas `<style>` dans `<head>`

Gmail strippe entièrement la balise `<style>` dans `<head>` lors du rendu inbox web. Tous les styles doivent donc être dupliqués en `style="..."` inline sur chaque élément. C'est verbeux mais c'est la convention email depuis 15 ans.

### Pourquoi un filet vert sous le logo plutôt qu'un fond plein

Un fond `brand-green` plein sur le header est trop "marketing newsletter" — les emails transactionnels (verification, password reset) doivent rester sobres pour passer les filtres anti-phishing visuels et inspirer confiance. Un filet 3px de séparation est suffisant pour ancrer la marque.

## Couverture des tests

| Surface | Tests |
|---|---|
| Backend — `EmailServiceTest` | 6/6 ✓ (driver config, payload Resend) |
| Backend — suite globale | 1050/1050 ✓ |
| Rendu visuel | À valider manuellement (envoi réel via le mode `resend` sur l'env de test) |

Les tests `EmailService` ne valident pas le contenu HTML rendu — ils vérifient la config de driver et la structure du payload Resend. Le rendu visuel est validé par envoi manuel sur l'env de test.

## Prérequis de déploiement

- `FRONTEND_URL` (env Railway) doit pointer vers une URL publique servant `/email-logo.png` (typiquement l'URL du service frontend Railway). Sans ça, les MUA afficheront un placeholder image cassée.
- Aucune migration SQL.
- Le PNG du logo est livré via le déploiement frontend (image Docker), donc dispo dès le redeploy.

## Test manuel post-deploy

1. Déclencher un email de vérification (création de compte) ou de reset password en env de test.
2. Vérifier dans Gmail web + Gmail mobile + Outlook web (3 cibles principales) :
   - Logo bien chargé et centré
   - Filet vert visible
   - Bouton CTA en brand-green
   - Footer avec app name lisible
3. Valider la lisibilité en dark mode (le client peut inverser les couleurs — la carte blanche restera blanche, on n'a pas implémenté de dark mode email pour l'instant).

## Évolutions retirées de `docs/evolutions.md`

- (rien — cette feature vient d'une demande utilisateur post-merge step 48)
