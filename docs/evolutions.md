# Évolutions à prévoir

Liste des améliorations identifiées en cours de route mais sortant du scope d'une feature en cours. À planifier / prioriser quand les branches concernées seront mergées.

## Statut / calculs

### RR négatif sur un compte 100% shorts en profit

**Contexte** : sur un compte qui n'a que des trades SHORT et qui est globalement profitable, le R:R affiché ressort négatif. Symptôme probable : un signe non inversé quelque part pour les SHORT dans le calcul du R:R agrégé (StatsRepository ou StatsService).

**À faire** :
- Reproduire sur un seed dédié (plusieurs SHORTs gagnants).
- Diag : `StatsRepository::getOverview` + `dimensionStatsSelect` — comparer le traitement du P&L / R:R entre BUY et SHORT.
- Vérifier si le souci vient du calcul du R:R par trade (et donc stocké en base) ou de l'agrégation.
- Couvrir avec un test intégration dédié.

**Repéré le** : 2026-04-23.
**Priorité** : haute (fausse les stats, donc la prise de décision).

---

*À chaque nouvelle évolution repérée mais non traitée immédiatement : l'ajouter ici avec contexte + fichiers + à-faire + priorité.*
