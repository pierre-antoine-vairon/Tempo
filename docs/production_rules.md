# Tempo — Règles métier Production

Dernière mise à jour : **27/08/2026**

Ce document est la référence métier actuelle du module Production. Il décrit les règles validées en V1 et les prochaines règles métier déjà décidées ou en cours de conception.

## 1. Objectif du module

Le module Production doit permettre à un site de suivre quotidiennement :
- le stock provenant de la journée précédente pertinente ;
- la production ;
- la reproduction ;
- les pertes ;
- les ventes ;
- le stock de fin ;
- l'historique des journées ;
- le catalogue de produits.

À terme, Tempo doit aussi aider à suivre l'âge du stock, détecter les produits en fin de conservation, identifier les écarts et recommander les quantités à produire.

## 2. Cycle de vie d'une feuille

Une journée inexistante est représentée fonctionnellement comme `not_started` → À faire.

Une journée créée peut avoir les statuts :
- `in_progress` → En cours ;
- `finished` → Terminée ;
- `closed` → Clôturée.

Cycle normal :

```text
À faire → En cours → Terminée → Clôturée
```

Réouverture :

```text
Clôturée → En cours
```

### Sens métier

`in_progress` : saisie encore modifiable ; la journée ne doit pas alimenter automatiquement la suivante.

`finished` : journée opérationnelle terminée ; ventes, pertes et stocks de fin sont suffisamment finalisés pour la suite de l'exploitation.

`closed` : validation / verrouillage manager ; la clôture n'est pas obligatoire pour continuer le lendemain.

### Permissions cibles

- un équipier peut saisir et terminer une feuille ;
- un manager peut clôturer et réouvrir.

L'authentification / RBAC réelle n'est pas encore implémentée.

## 3. GET ne crée jamais de journée

La consultation d'une date ne crée aucune donnée.

Si aucune journée n'existe :

```text
exists = false
status = not_started
```

La création est une action explicite de démarrage.

## 4. NULL et zéro

Pendant `in_progress` :
- `NULL` = valeur non encore renseignée ;
- `0` = zéro explicitement renseigné.

Au clic sur « Terminer » :
- Tempo détecte les champs applicables encore `NULL` ;
- l'utilisateur peut revenir corriger ;
- ou confirmer explicitement leur remplacement par `0`.

Cette conversion n'est jamais silencieuse.

Les champs non applicables restent `NULL`.

Exemple :

```text
Produit conservation J
→ stock_previous non applicable
→ NULL
```

## 5. Champs suivis quotidiennement

```text
stock_previous
production
reproduction
losses
sales
stock_end
```

`stock_previous` est calculé / initialisé par Tempo et n'est pas une saisie normale utilisateur.

Les cinq champs éditables sont : `production`, `reproduction`, `losses`, `sales`, `stock_end`.

## 6. Produits de conservation J

Un produit `J` ne peut pas reporter de stock vers une autre journée.

```text
stock_previous = NULL
```

Les produits `J+1`, `J+2`, `J+3` peuvent être reportés selon les règles de continuité de stock.

## 7. Stock J-1 — comportement V1 actuel

La V1 regarde encore la **veille calendaire exacte**.

Si cette veille possède une journée `finished` ou `closed` :

```text
stock_end veille → stock_previous aujourd'hui
```

Sinon `stock_previous = NULL`.

Limite connue : cette logique est insuffisante lorsqu'un site est fermé un jour. Après ajout du calendrier d'exploitation, elle devra utiliser la dernière journée d'exploitation pertinente.

## 8. Vérification / actualisation explicite du Stock J-1

Une feuille ouverte conserve un snapshot de son Stock J-1. Une modification ultérieure d'une journée précédente ne doit pas réécrire silencieusement une journée déjà ouverte.

Tempo propose donc une vérification explicite puis, si nécessaire, une actualisation explicite du Stock J-1.

## 9. Composition historique d'une journée

Une feuille doit rester fidèle à ce qu'elle contenait au moment de son ouverture.

```text
Produits de la journée ≠ catalogue actuel
```

Les produits sont snapshotés dans `y_production_day_products` avec notamment produit, famille, catégorie, conservation, ordre et présence dans la journée.

Modifier ou désactiver un produit aujourd'hui ne modifie jamais une ancienne feuille.

## 10. Catalogue

Structure :

```text
Famille → Catégorie → Produit
```

Les produits peuvent être créés, modifiés, activés, désactivés et ordonnés.

La suppression physique n'est pas le fonctionnement normal lorsqu'un produit possède un historique.

`is_active = 0` signifie absent des futures feuilles mais toujours visible dans les anciennes feuilles où le produit a été snapshoté.

## 11. Fin de journée

Une journée `in_progress` peut être enregistrée partiellement.

Lors du passage à `finished`, Tempo vérifie les cinq champs éditables. Si certains sont encore `NULL`, Tempo demande confirmation ; après confirmation, les valeurs applicables restantes deviennent `0`, puis la journée passe à `finished`.

Une journée `finished` ou `closed` est en lecture seule.

## 12. Warnings

Principe général :

```text
intégrité technique → blocage
oubli / incohérence métier récupérable → warning ou confirmation lorsque possible
```

Exemples : stock de fin à vérifier, ancienne journée encore `in_progress`, incohérence mathématique, perte inhabituelle, quantité en fin de conservation.

Les warnings doivent être calculés lorsqu'ils peuvent être déduits de l'état réel, plutôt que stockés comme un état fixe.

# V2 — règles métier déjà identifiées

## 13. Calendrier d'exploitation du site

Tempo doit savoir si un site est censé être ouvert ou fermé à une date donnée.

```text
Jour ouvert + aucune feuille
→ production à faire / potentiellement oubliée

Jour ouvert + feuille
→ fonctionnement normal

Jour fermé + aucune feuille
→ normal

Jour fermé + feuille
→ ouverture exceptionnelle ou changement durable
```

Un site possède des jours habituels d'ouverture / fermeture. Deux types d'exceptions doivent être possibles : ouverture exceptionnelle et fermeture exceptionnelle. Une exception datée gagne sur la règle habituelle.

Si un site change durablement de fonctionnement, cela ne doit pas être traité comme une suite d'exceptions. À terme, le planning habituel devra pouvoir avoir une date d'effet afin de préserver l'interprétation du passé.

## 14. Continuité des journées

Une nouvelle journée ne doit pas pouvoir être démarrée si une journée d'exploitation précédente pertinente est toujours `in_progress`.

```text
précédente pertinente = in_progress → blocage
précédente pertinente = finished → autorisé
précédente pertinente = closed → autorisé
```

`finished` suffit opérationnellement ; `closed` n'est pas obligatoire pour continuer.

Un jour fermé sans feuille ne casse pas la chaîne.

Un jour qui devait être ouvert mais qui n'a aucune feuille doit être traité comme une anomalie à résoudre (feuille manquante ou fermeture exceptionnelle) plutôt que sauté silencieusement.

## 15. Dernière journée d'exploitation pertinente

Après le calendrier du site, la logique de stock doit évoluer de :

```text
veille calendaire
```

vers :

```text
dernière journée d'exploitation pertinente et finalisée
```

Exemple :

```text
Samedi : stock fin = 8
Dimanche : fermé
Lundi : stock_previous = 8
```

Le dimanche compte néanmoins dans l'âge calendaire du produit.

## 16. Âge du stock / FIFO

Pour les produits conservables plusieurs jours, Tempo doit reconstruire l'âge du stock sans imposer de saisie manuelle de lots.

Principe :
- chaque production crée un lot virtuel daté ;
- les ventes / consommations sont imputées au lot le plus ancien en premier ;
- le stock de fin sert de contrôle de réalité.

La définition précise de `J+1`, `J+2`, `J+3` doit être figée avant l'implémentation du moteur FIFO.

## 17. Produits à surveiller / fin de conservation

Une fois l'âge du stock calculé, Tempo doit signaler les quantités arrivant en fin de conservation aujourd'hui ou prochainement afin d'éviter une reconstitution manuelle à partir de plusieurs feuilles.

## 18. Contrôle de cohérence du stock

Tempo devra comparer :

```text
stock_previous
+ production
+ reproduction
- sales
- losses
```

avec `stock_end` réellement compté.

Un écart doit être visible sans nécessairement bloquer l'utilisateur.

## 19. Production conseillée — cible future

Après stabilisation des stocks, de l'âge et des pertes, Tempo pourra proposer une quantité à produire à partir notamment des ventes historiques, du jour de semaine, du stock restant, de son âge, des pertes, ruptures et du niveau d'exposition souhaité.

La recommandation reste une aide : la décision finale appartient au responsable.
