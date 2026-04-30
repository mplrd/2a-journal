# Étape 51 — Header email plus marqué et sujet hors de la card

## Résumé

Itération sur le rendu des emails transactionnels (step 49). Le header est désormais **un bandeau navy plein** placé **au-dessus** de la card de contenu, avec le logo (variante claire) et le sujet du mail en blanc. La card blanche ne contient plus que le corps du message — le titre/sujet n'y est plus dupliqué.

Le filet vert reste comme accent entre le bandeau navy et la card. Le footer (`{{app_name}}`) sort de la card et passe en bas, sur le fond cream.

## Avant / après

```
AVANT (step 49)                       APRÈS (step 51)
┌──────────────────────────┐         ┌──────────────────────────┐
│ [card blanche]           │         │  [bandeau navy]          │
│  [logo navy]             │         │   [logo cream/clair]     │
│  ──── filet vert ────    │         │                          │
│  Sujet en navy           │         │   Sujet en blanc         │
│  Body…                   │         ├══════ filet vert ═══════ │
│  Footer                  │         │ [card blanche]           │
└──────────────────────────┘         │  Body…                   │
                                     └──────────────────────────┘
                                              {{app_name}}  ← footer
```

## Fonctionnalités

### Logo dans la variante "dark" pour fond navy

`docs/charte-graphique/images/2a-trading-tools_logo-dark.png` (encre cream prévue pour fond sombre) est copié dans `frontend/public/email-logo-dark.png`. Le logo "light" (`email-logo.png`, encre navy) reste en place mais n'est plus référencé par le layout — il reste dispo pour un éventuel email sur fond clair futur.

### Layout `api/templates/emails/layout.html`

Trois zones empilées dans la table `width=600` :

1. **Header navy** (`#1f2a3c`), `border-radius:8px 8px 0 0`, padding `36px/32px/28px/32px`, contient :
   - logo cream centré, 96px de large ;
   - `<h1>` 22px / 600 / blanc avec `{{title}}`.
2. **Filet brand-green-700** (`#2d7952`), 4px de hauteur. Sépare visuellement le bandeau navy de la card.
3. **Card blanche**, `border-radius:0 0 8px 8px`, bordures gris-200 charte, padding 32px, contient `{{content}}`.
4. **Footer** hors de la card, padding `20px 32px 0 32px`, texte `{{app_name}}` 12px gray-charte, centré sur le cream du body.

### Templates body inchangés

Les 6 templates (`verification`, `password-reset`, `account-locked` × `fr`/`en`) ne sont pas modifiés : ils ne dupliquaient déjà pas le titre, ils contiennent uniquement le corps + le CTA. Le fait que le titre soit désormais dans le bandeau navy plutôt qu'en `<h1>` dans la card est entièrement géré par le layout.

### `EmailService` inchangé

`wrapLayout()` continue de remplacer `{{title}}` / `{{content}}` / `{{app_name}}` / `{{frontend_url}}`. Pas de changement de signature, pas de nouveau placeholder.

## Choix d'implémentation

### Pourquoi revenir sur la décision "pas de fond plein" du step 49

Step 49 avait choisi un header sobre (logo navy sur fond blanc + filet vert) pour éviter un look "marketing newsletter" sur des emails transactionnels. Test utilisateur réel : sur Gmail web, le rendu donnait un email **fade**, le sujet n'était pas immédiatement repérable, le logo se confondait avec la card blanche. Le compromis "sobriété maximale" pénalisait la lisibilité.

Le bandeau navy plein corrige ça : le logo + sujet ressortent immédiatement à l'ouverture, sans pour autant tomber dans le visuel "promo" — c'est une couleur de marque (navy 900 charte), pas une couleur d'attention agressive type rouge / orange.

### Pourquoi le sujet dans le bandeau plutôt que dans la card

Le sujet (`{{title}}`) reprend le `subject` de l'email envoyé (« Vérifiez votre adresse email », « Réinitialisation de mot de passe », …). Le voir en gros et en clair dans le bandeau, juste sous le logo, donne immédiatement le contexte sans avoir à descendre dans le body. Effet collatéral : on peut épurer le body — le « Cliquez sur le bouton ci-dessous pour … » sert maintenant uniquement de phrase d'accompagnement, pas de redite du sujet.

### Pourquoi `border-radius` éclatés (`8px 8px 0 0` puis `0 0 8px 8px`)

Le filet vert est une `<tr>` rectangulaire 100%-large entre les deux blocs arrondis. Si on arrondissait aussi le filet, on créerait un défaut visuel à la jonction. En arrondissant uniquement le haut du bandeau navy et le bas de la card blanche, et en gardant le filet rectangulaire entre les deux, on obtient un bloc visuel cohérent qui se lit comme **une seule shape arrondie traversée par un filet**.

### Pourquoi le footer hors de la card

Garder le footer dans la card l'enferme dans le visuel principal (avec bordure et fond gris) — il devient une « zone du message ». En le sortant sur le cream avec juste du texte 12px, il devient ce qu'il est : une légende discrète, hors-flux.

## Couverture des tests

| Surface | Tests |
|---|---|
| Backend — `EmailServiceTest` | 6/6 ✓ |
| Build / lint | OK (HTML statique, pas de compilation) |
| Rendu visuel | À valider manuellement (envoi réel via Resend en env de test) |

## Test manuel post-deploy

Identique au step 49 — déclencher un email de vérification ou de reset password en env de test. Vérifier que :
- le bandeau navy occupe bien toute la largeur du conteneur 600px ;
- le logo cream est lisible sur le navy ;
- le sujet en blanc ressort clairement ;
- le filet vert est visible entre les deux blocs ;
- la card blanche ne contient que le body + CTA, pas de titre dupliqué ;
- le footer en bas (sur fond cream) reste discret.

Cibles de validation : Gmail web, Gmail mobile, Outlook web (3 cibles principales).

## Évolutions retirées de `docs/evolutions.md`

- (rien — itération directe sur retour utilisateur post-step 49)
