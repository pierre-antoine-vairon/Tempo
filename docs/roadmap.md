# Tempo — Roadmap actuelle

Dernière mise à jour : **12/10/2026**

Cette roadmap est la référence courante. Les anciens ateliers et roadmaps restent dans l'historique du projet.

## 1 — Production V1 ✅

Livré : Vue d'ensemble, Production du jour, Historique, Produits, cycle de vie, snapshots historiques, Stock précédent, contrôles de fin de journée, catalogue Famille → Catégorie → Produit et alertes de pilotage.

## 2 — Fondations Production post-V1 ✅

### Calendrier d'exploitation du site ✅

Livré :
- versions hebdomadaires complètes de 7 jours ;
- `effective_from` et configurations append-only ;
- exceptions datées d'ouverture ou de fermeture ;
- frontière d'activation du calendrier ;
- compatibilité historique avant activation ;
- pas d'horaires commerciaux dans cette version.

### Continuité des journées Production ✅

La journée courante peut être créée si la continuité de stock est indisponible. La recherche s'arrête sur la première journée d'exploitation précédente attendue qui n'est pas exploitable ; aucune journée plus ancienne n'est recherchée et `stock_previous` reste `NULL`.

Une continuité bloquante de création n'est pas une règle livrée.

### Stock entre journées d'exploitation ✅

Le stock précédent vient du dernier jour d'exploitation attendu avant la date courante. Les jours fermés sont ignorés et les exceptions calendrier sont respectées. La continuité s'arrête si la feuille attendue est absente, `in_progress` ou porte un statut invalide. Lorsque la résolution fonctionne en mode calendrier, Tempo ne franchit pas la frontière d'activation pour rechercher une feuille historique plus ancienne. Le J-1 calendaire reste utilisé uniquement lorsque le calendrier n'est pas configuré pour la date concernée.

```text
Samedi : stock fin = 8
Dimanche : fermé
Lundi : stock_previous = 8
```

Les produits `J` restent exclus du report.

## 3 — Âge du stock / FIFO

- lots virtuels datés ;
- consommation du plus ancien en premier ;
- stock de fin comme contrôle de réalité ;
- vieillissement calendaire même les jours fermés.

## 4 — Produits à surveiller / pertes

À partir du FIFO et des règles `J`, `J+1`, `J+2`, `J+3` :
- quantités arrivant en fin de conservation ;
- quantités à surveiller demain ;
- alertes de pertes probables.

## 5 — Contrôles de cohérence

Comparer notamment :

```text
stock_previous
+ production
+ reproduction
- sales
- losses
```

avec `stock_end` réellement compté. Les écarts doivent être visibles sans bloquer inutilement l'exploitation.

## 6 — Inventaires

Créer une vision fiable du stock réel et relier les inventaires à l'activité Production.

## 7 — Fournisseurs, commandes et réceptions

- fournisseurs ;
- articles achetés ;
- commandes ;
- réceptions ;
- écarts de réception.

## 8 — Matières premières, conditionnements et mouvements

Construire le stock matière nécessaire pour relier achats, inventaires et production.

## 9 — Fiches techniques et rendements

Relier les produits finis à leurs matières, quantités, rendements et coûts.

## 10 — Consommation théorique vs réelle / food cost

Calculer progressivement :
- consommation théorique ;
- consommation réelle ;
- pertes ;
- écarts ;
- coûts ;
- marge / food cost.

## 11 — Recommandations de production et de commande

Tempo doit aider le responsable à savoir **quoi produire, quoi commander et pourquoi**, à partir de l'activité réelle du point de vente.

Les recommandations utilisent progressivement : ventes historiques, jour de semaine, stock, âge, pertes, ruptures, exposition souhaitée, besoins matières et commandes en cours. L'utilisateur garde la décision finale.

## 12 — Première couche HACCP

Après la boucle opérationnelle centrale : températures, nettoyage, anomalies / actions correctives, contrôles de réception, photos, signatures, traçabilité, étiquettes et DLC.

HACCP ne doit pas retarder la boucle Production–Inventaire–Commandes–Coûts.

## 13 — Planning / heures / RH

Le module Planning historique reste un axe majeur. Rosters, shifts, assignments, besoins, couverture et IA ne sont pas abandonnés ; ils sont simplement en pause pendant la consolidation de la chaîne opérationnelle.

L'IA reste une couche d'assistance au-dessus de règles métier déterministes et d'un mode manuel fonctionnel.
