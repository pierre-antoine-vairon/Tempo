# Tempo — Règles métier Production

Dernière mise à jour : **12/10/2026**

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

Lorsqu'aucune feuille n'existe, l'état Production technique reste `not_started`. Côté métier :

- si le calendrier est non configuré ou si le jour est ouvert : la production est À faire ;
- si le jour est configuré fermé : aucune production n'est attendue et l'interface présente le site comme fermé.

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

## 7. Stock précédent — comportement actuel

### Mode historique / calendrier non configuré

Pour une date antérieure à l'activation du calendrier, Tempo conserve le comportement V1 : la veille calendaire est recherchée.

### Mode calendrier d'exploitation

Pour une date courante configurée, Tempo remonte jusqu'au premier jour d'exploitation attendu ouvert. Les jours fermés sont ignorés et les exceptions d'ouverture ou de fermeture sont respectées.

Dès que ce premier jour ouvert attendu est trouvé :

- feuille absente → continuité indisponible, arrêt ;
- feuille `in_progress` → continuité indisponible, arrêt ;
- statut inattendu → continuité indisponible, arrêt ;
- feuille `finished` ou `closed` → stock exploitable.

Tempo ne recherche jamais une journée plus ancienne lorsqu'une journée ouverte attendue pose problème. La nouvelle journée peut être créée, mais `stock_previous` reste `NULL` lorsque la continuité est indisponible.

La première `effective_from` constitue le début de cette continuité pilotée par calendrier. Si aucun jour ouvert précédent n'existe entre la date courante et cette frontière, `previous_date = NULL` et Tempo ne bascule pas vers une feuille antérieure à l'activation.

Règles produits :

- conservation `J` → `stock_previous = NULL` ;
- `stock_end = NULL` → `stock_previous` reste `NULL` ;
- produit absent de la feuille précédente → `stock_previous` suggéré à `NULL`.

## 8. Vérification / actualisation explicite du stock précédent

Une feuille ouverte conserve un snapshot de son stock précédent. Une modification ultérieure d'une journée précédente ne doit pas réécrire silencieusement une journée déjà ouverte.

`openDay()`, `previewPreviousStock()` et `refreshPreviousStock()` utilisent la même résolution métier de la journée précédente attendue. Tempo propose une vérification explicite puis, si nécessaire, une actualisation explicite du stock précédent après action de l'utilisateur.

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

# Règles métier Production actuelles

## 13. Calendrier d'exploitation du site

Tempo sait si un site est configuré ouvert ou fermé à une date donnée.

```text
Jour ouvert + aucune feuille
→ production à faire / potentiellement oubliée

Jour ouvert + feuille
→ fonctionnement normal

Jour fermé + aucune feuille
→ normal

Jour fermé + feuille existante
→ feuille historique lisible avec son statut Production réel
```

Une configuration hebdomadaire comporte exactement 7 jours et est versionnée par `effective_from`. Les configurations sont append-only. Une exception datée gagne sur la dernière règle hebdomadaire applicable. La première `effective_from` est la frontière d'activation ; avant cette date, Production conserve son comportement V1.

Un jour fermé configuré sans feuille est normal. Une nouvelle feuille est refusée sur un jour fermé ; une ouverture exceptionnelle doit être enregistrée dans le calendrier avant la création. Une feuille historique existante reste toujours lisible, même si le calendrier indique désormais fermé.

## 14. Continuité des journées

Une nouvelle journée peut être créée si la continuité est indisponible, mais aucun stock précédent n'est alors repris. Une journée ouverte attendue absente, `in_progress` ou portant un statut invalide interrompt la continuité ; Tempo ne la saute jamais pour rechercher une date plus ancienne.

`finished` et `closed` sont exploitables pour le report de stock. Un jour fermé sans feuille ne casse pas la chaîne.

## 15. Dernière journée d'exploitation pertinente

La logique de stock utilise la dernière journée d'exploitation attendue et exploitable avant la date courante lorsque le calendrier est configuré. Sinon, elle conserve la veille calendaire historique.

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
