# Base de données — scripts SQL Tempo

Dernière mise à jour : **27/08/2026**

Ce dossier contient les scripts SQL versionnés hérités du dépôt `AppPlanning-docs` et les évolutions Production récentes.

## Règle de sécurité absolue

La base de développement actuelle est **distante et partagée**.

Les fichiers de ce dossier sont de la documentation exécutable, **pas une suite de migrations à lancer automatiquement**.

Ne jamais exécuter automatiquement :

- `DROP TABLE` ;
- `TRUNCATE` ;
- reset de base ;
- migrations Laravel ;
- seeds ;
- `DELETE` massif ;
- `ALTER TABLE` non validé ;
- `master.sql`.

Toute évolution de schéma doit être :

1. définie et validée ;
2. écrite dans un nouveau script SQL versionné ;
3. relue ;
4. appliquée volontairement dans MySQL Workbench ;
5. vérifiée après exécution.

## Important : les scripts historiques ne sont pas tous reproductibles

Le dossier a été construit au fil du projet par SQL direct. Il ne constitue **pas encore** une migration complète permettant de recréer la base réelle depuis zéro.

Incohérences connues :

- les scripts `000` à `050` contiennent historiquement des `DROP TABLE IF EXISTS` et ne doivent donc jamais être exécutés sur la base partagée ;
- `060_production_products.sql` crée une version ancienne de `y_products` sans tous les champs apparus ensuite ;
- `062_product_families.sql` et `063_product_categories.sql` supposent notamment l'existence de données / colonnes legacy telles que `family` et `category` ;
- `068_production_entries_day_product.sql` est une évolution par `ALTER TABLE`, pas une création complète ;
- `master.sql` ne couvre que les anciens batches Planning et **ne doit pas être considéré comme un installateur actuel**.

## Usage actuel

Pour une nouvelle fonctionnalité nécessitant du SQL :

- ne pas modifier arbitrairement un ancien script pour faire croire que l'historique a toujours été ainsi ;
- préférer un nouveau numéro de script ;
- documenter ce qu'il suppose déjà présent dans la base ;
- éviter toute opération destructive ;
- respecter `org_id`, `site_id`, les FK, index, contraintes et conventions `y_`.

## Audit à faire

Avant de considérer `db/schema/` comme une source de reconstruction complète, il faudra comparer les scripts avec le schéma réel de la base : colonnes, types, index, FK, contraintes, triggers et valeurs par défaut.

Jusqu'à cet audit, **la présence d'un script dans ce dossier n'autorise jamais son exécution automatique**.
