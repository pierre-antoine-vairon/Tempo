# Tempo — État du projet

Dernière mise à jour : **12/10/2026**

Ce document est le point de référence rapide pour savoir où en est Tempo aujourd'hui.

## Production

**V1 terminée et validée.**

Frontend :
- Vue d'ensemble / pilotage ;
- Production du jour ;
- Historique ;
- Produits.

Backend / logique métier :
- création explicite d'une journée ;
- cycle `not_started → in_progress → finished → closed` ;
- réouverture d'une journée clôturée ;
- snapshot historique des produits ;
- saisie partielle pendant `in_progress` ;
- distinction `NULL` / `0` ;
- confirmation avant remplacement des valeurs manquantes par `0` à la fin ;
- Stock précédent pour les produits dont la conservation le permet ;
- lorsque le calendrier est configuré, stock précédent issu du dernier jour d'exploitation attendu ;
- avant l'activation du calendrier, compatibilité historique avec le J-1 calendaire ;
- aucun stock précédent pour les produits `J` ;
- `stock_end = NULL` reste `stock_previous = NULL` ;
- prévisualisation et actualisation explicites du stock précédent ;
- catalogue Famille → Catégorie → Produit ;
- activation, désactivation, création, modification et ordre ;
- alertes sur les journées anciennes encore `in_progress`.

Limites connues :
- la continuité de stock ne bloque pas la création de la journée courante ; si la journée d'exploitation précédente attendue est absente, encore en cours ou invalide, la feuille courante peut être créée mais aucun stock précédent n'est repris ;
- pas encore de FIFO / âge du stock ;
- pas encore d'alertes automatiques de fin de conservation ;
- pas encore de recommandation automatique de production.

## Calendrier d'exploitation du site ✅

Tempo possède une source de vérité sur les jours où un site fonctionne réellement.

Fonctionnalité livrée :
- configuration hebdomadaire complète de 7 jours, versionnée par `effective_from` ;
- configurations hebdomadaires append-only ;
- ouvertures et fermetures exceptionnelles par date ;
- priorité de résolution : exception, dernière configuration hebdomadaire applicable, puis calendrier non configuré ;
- première `effective_from` comme frontière d'activation ;
- avant cette frontière, comportement Production V1 conservé.

Cas métier :

```text
Jour ouvert + aucune feuille
→ À faire / potentiellement oubliée

Jour ouvert + feuille existante
→ fonctionnement normal

Jour fermé + aucune feuille
→ normal, aucune production attendue

Jour fermé configuré + feuille historique existante
→ feuille lisible avec son statut Production réel
```

Une nouvelle feuille Production est refusée sur un jour configuré fermé. Une ouverture exceptionnelle doit d'abord être enregistrée dans le calendrier. Les horaires commerciaux restent hors V1.

## Suite prévue pour Production

1. suivi d'âge du stock / FIFO ;
2. produits et quantités à surveiller en fin de conservation ;
3. contrôles de cohérence de stock ;
4. inventaires ;
5. recommandations de production ;
6. statistiques et optimisation.

## Direction produit après les fondations Production

Ordre envisagé :

```text
Production réelle
→ Inventaires
→ Fournisseurs
→ Commandes / réceptions
→ Matières premières / conditionnements / mouvements de stock
→ Fiches techniques / rendements
→ Consommation théorique vs réelle
→ Pertes / écarts / coûts / food cost
→ Recommandations de production et de commande
→ première couche HACCP
→ reprise Planning / heures / RH
```

La boucle centrale à construire est :

```text
Production réelle → consommation / pertes → inventaire → besoins → commande → réception → coûts / marges → recommandations
```

## Planning

Le socle Planning historique existe toujours : organisations, sites, workers, skills, demands, rosters, shifts et assignments. Le développement récent s'est concentré sur Production.

## Authentification / rôles

JWT / RBAC ont été étudiés mais restent **reportés** dans le MVP actuel. Les permissions métier visées ne sont pas encore sécurisées par une authentification réelle.

## Architecture actuelle

- Backend : Laravel / API ;
- Frontend : React + TypeScript + Vite ;
- DB : MariaDB ;
- multi-tenant via `org_id` ;
- multi-site via `site_id` ;
- scripts SQL versionnés dans `db/schema/` ;
- pilote actuel : `org_id = 1`, `site_id = 1`.

## Dépôt de travail

Depuis le 27/08/2026, le dépôt **Tempo** doit réunir le code, la documentation actuelle et les scripts SQL utiles au développement. L'ancien dépôt `AppPlanning-docs` reste conservé comme historique / archive et ne doit plus être la source de vérité quotidienne.
