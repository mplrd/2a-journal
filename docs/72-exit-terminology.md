# 72 — Harmonisation du vocabulaire des sorties

Ticket #28. Clarifie et unifie la terminologie des « quantités à sortir » dans
les formulaires et la modale de clôture.

## Problème

Le concept « quantité à sortir à un niveau donné » (la part clôturée quand
SL/BE/TP est touché) était nommé de trois façons, et « Taille » était partagé
avec la taille de position :

| Concept | clé | Avant (FR) |
|---------|-----|-----------|
| Taille de position | `positions.size` | Taille |
| Part sortie au BE | `positions.be_size` | Taille BE |
| Part sortie à chaque objectif | `positions.target_size` | Taille |
| Part sortie à la clôture | `trades.exit_size` | Taille de sortie |

Conséquence : ambiguïté sur « Taille BE » (taille restante ? part à sortir ?) et
recouvrement entre la taille de position et la part d'un objectif.

## Solution

« Taille / Size » est réservé à la **taille de position**. Toute **part à
sortir** adopte le vocabulaire « **Quantité sortie** » :

| clé | FR | EN |
|-----|----|----|
| `positions.be_size` | Quantité sortie au BE | Qty exited at BE |
| `positions.target_size` | Quantité | Qty |
| `trades.exit_size` | Quantité sortie | Exited quantity |

Une icône d'aide ℹ + tooltip sur le champ BE (3 formulaires : Trade, Position,
Order) explicite l'intention et rappelle le comportement « protéger sans
alléger » livré au ticket #30 :

- FR : « Quantité à sortir lors de la mise à BE. Vide ou 0 = on protège sans
  alléger la position. »
- EN : « Quantity to exit when moving the stop to BE. Empty or 0 = protect
  without lightening the position. »

## Portée

i18n uniquement (clés partagées → les 3 formulaires et la modale de clôture
corrigés d'un coup) + l'icône d'aide. Pas de backend, pas de migration, pas de
changement de logique. Couvert par les specs de formulaires existantes ; parité
fr/en vérifiée (940/940).
