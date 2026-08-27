# Tempo — Roadmap actuelle

Dernière mise à jour : **27/08/2026**

Cette roadmap est la référence courante. Les anciens ateliers et roadmaps restent dans l'historique du projet.

## 1 — Production V1 ✅

Livré : Vue d'ensemble, Production du jour, Historique, Produits, cycle de vie, snapshots historiques, Stock J-1, contrôles de fin de journée, catalogue Famille → Catégorie → Produit et alertes de pilotage.

## 2 — Calendrier d'exploitation du site

Objectif : permettre à Tempo de connaître les vrais jours d'exploitation.

À construire :
- jours habituels ouverts / fermés ;
- horaires commerciaux simples ;
- ouvertures exceptionnelles ;
- fermetures exceptionnelles.

À prévoir sans sur-construire la V1 :
- changements durables avec date d'effet ;
- distinction future horaires commerciaux / horaires de production.

## 3 — Continuité des journées Production

Règles cibles :
- un jour fermé ne demande aucune feuille ;
- un jour ouvert doit pouvoir demander une feuille ;
- une journée précédente pertinente encore `in_progress` bloque le démarrage suivant ;
- `finished` suffit pour continuer l'exploitation ;
- `closed` reste une validation / verrouillage manager.

## 4 — Stock entre journées d'exploitation

Remplacer la veille calendaire par la dernière journée d'exploitation pertinente et finalisée.

```text
Samedi : stock fin = 8
Dimanche : fermé
Lundi : stock_previous = 8
```

Les produits `J` restent exclus du report.

## 5 — Âge du stock / FIFO

- lots virtuels datés ;
- consommation du plus ancien en premier ;
- stock de fin comme contrôle de réalité ;
- vieillissement calendaire même les jours fermés.

## 6 — Produits à surveiller / pertes

À partir du FIFO et des règles `J`, `J+1`, `J+2`, `J+3` :
- quantités arrivant en fin de conservation ;
- quantités à surveiller demain ;
- alertes de pertes probables.

## 7 — Contrôles de cohérence

Comparer notamment :

```text
stock_previous
+ production
+ reproduction
- sales
- losses
```

avec `stock_end` réellement compté. Les écarts doivent être visibles sans bloquer inutilement l'exploitation.

## 8 — Inventaires

Créer une vision fiable du stock réel et relier les inventaires à l'activité Production.

## 9 — Fournisseurs, commandes et réceptions

- fournisseurs ;
- articles achetés ;
- commandes ;
- réceptions ;
- écarts de réception.

## 10 — Matières premières, conditionnements et mouvements

Construire le stock matière nécessaire pour relier achats, inventaires et production.

## 11 — Fiches techniques et rendements

Relier les produits finis à leurs matières, quantités, rendements et coûts.

## 12 — Consommation théorique vs réelle / food cost

Calculer progressivement :
- consommation théorique ;
- consommation réelle ;
- pertes ;
- écarts ;
- coûts ;
- marge / food cost.

## 13 — Recommandations de production et de commande

Tempo doit aider le responsable à savoir **quoi produire, quoi commander et pourquoi**, à partir de l'activité réelle du point de vente.

Les recommandations utilisent progressivement : ventes historiques, jour de semaine, stock, âge, pertes, ruptures, exposition souhaitée, besoins matières et commandes en cours. L'utilisateur garde la décision finale.

## 14 — Première couche HACCP

Après la boucle opérationnelle centrale : températures, nettoyage, anomalies / actions correctives, contrôles de réception, photos, signatures, traçabilité, étiquettes et DLC.

HACCP ne doit pas retarder la boucle Production–Inventaire–Commandes–Coûts.

## 15 — Planning / heures / RH

Le module Planning historique reste un axe majeur. Rosters, shifts, assignments, besoins, couverture et IA ne sont pas abandonnés ; ils sont simplement en pause pendant la consolidation de la chaîne opérationnelle.

L'IA reste une couche d'assistance au-dessus de règles métier déterministes et d'un mode manuel fonctionnel.
