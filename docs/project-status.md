# Tempo — État du projet

Dernière mise à jour : **27/08/2026**

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
- Stock J-1 pour les produits dont la conservation le permet ;
- aucun Stock J-1 pour les produits `J` ;
- vérification / actualisation explicite du Stock J-1 ;
- catalogue Famille → Catégorie → Produit ;
- activation, désactivation, création, modification et ordre ;
- alertes sur les journées anciennes encore `in_progress`.

Limites connues :
- le Stock J-1 utilise encore la veille calendaire ;
- Tempo ne connaît pas encore les jours d'ouverture / fermeture du site ;
- un jour sans feuille est actuellement `À faire`, même si le site devrait être fermé ;
- la continuité entre journées n'est pas encore imposée ;
- pas encore de FIFO / âge du stock ;
- pas encore d'alertes automatiques de fin de conservation ;
- pas encore de recommandation automatique de production.

## Prochaine brique : calendrier d'exploitation du site

Objectif : donner à Tempo une source de vérité sur les jours où un site fonctionne réellement.

V1 envisagée :
- jours habituels ouverts / fermés ;
- horaires commerciaux simples ;
- ouverture exceptionnelle ;
- fermeture exceptionnelle.

Cas métier :

```text
Jour ouvert + aucune feuille
→ À faire / potentiellement oubliée

Jour ouvert + feuille existante
→ fonctionnement normal

Jour fermé + aucune feuille
→ normal, aucune production attendue

Jour fermé + feuille ouverte
→ ouverture exceptionnelle ou changement durable
```

À terme, les changements durables devront pouvoir être datés pour ne pas réécrire l'historique.

## Suite prévue pour Production

1. calendrier d'exploitation du site ;
2. continuité des journées de production ;
3. récupération du stock depuis la dernière journée d'exploitation pertinente ;
4. suivi d'âge du stock / FIFO ;
5. produits et quantités à surveiller en fin de conservation ;
6. contrôles de cohérence de stock ;
7. recommandations de production ;
8. statistiques et optimisation.

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
