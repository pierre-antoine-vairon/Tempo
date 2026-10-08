# Tempo — Instructions pour les agents de développement

## 1. Priorité des sources

Avant toute modification, considérer les sources de vérité dans cet ordre :

1. working tree local (`git status`, `git diff`) ;
2. code réellement présent dans le dépôt ;
3. `docs/project-status.md` ;
4. `docs/production_rules.md` et `docs/decisions.md` ;
5. `db/README.md` et les scripts de `db/schema/` ;
6. ADR et documentation historique.

En cas de contradiction, la signaler avant toute modification structurante.

---

## 2. Architecture

Tempo utilise :

- backend Laravel / PHP ;
- frontend React + TypeScript + Vite ;
- MariaDB / MySQL ;
- architecture multi-organisation via `org_id` ;
- architecture multi-site via `site_id`.

Le pilote actuel utilise temporairement :

- `org_id = 1`
- `site_id = 1`

Ne jamais supprimer le scoping multi-tenant sous prétexte que le pilote utilise ces valeurs.

---

## 3. Base de données — règle critique

La base de développement est distante et partagée.

Ne jamais exécuter automatiquement :

- `DROP TABLE` ;
- `TRUNCATE` ;
- reset de base ;
- seed ;
- migration Laravel ;
- `DELETE` massif ;
- script `db/schema/master.sql` ;
- ancien script SQL potentiellement destructeur ;
- modification de schéma non validée.

Les scripts de `db/schema/` ne constituent pas une suite de migrations automatiquement exécutable.

Toute évolution de schéma doit :

1. être discutée et validée ;
2. être écrite dans un nouveau script SQL versionné ;
3. être relue ;
4. être appliquée volontairement dans MySQL Workbench par l'utilisateur.

Lire `db/README.md` avant tout travail SQL.

---

## 4. Règles métier importantes

Le backend Laravel est la source de vérité métier.

Toujours préserver :

- `org_id` ;
- `site_id` ;
- les données historiques ;
- les snapshots ;
- les contraintes et triggers existants.

Dans Production :

- `NULL` signifie « non renseigné » ;
- `0` signifie « zéro explicitement renseigné » ;
- ne jamais convertir silencieusement `NULL` en `0`.

Le passage des valeurs manquantes à zéro lors de la fin d'une journée nécessite une confirmation explicite.

Ne jamais inventer une règle métier manquante. Demander une décision avant de l'implémenter.

---

## 5. Production

La Production V1 est considérée comme terminée et fonctionnelle.

Elle comprend notamment :

- Vue d'ensemble ;
- Production du jour ;
- Historique ;
- Produits ;
- cycle `not_started → in_progress → finished → closed` ;
- réouverture ;
- snapshots produits ;
- Stock J-1 ;
- actualisation explicite du Stock J-1.

Ne pas réécrire ou refactorer massivement cette V1 sans raison fonctionnelle explicite.

La prochaine fondation métier est le calendrier d'exploitation du site.

---

## 6. Méthode de développement

Pour une nouvelle fonctionnalité :

1. lire le code et la documentation concernés ;
2. reformuler la règle métier comprise ;
3. proposer un plan technique limité ;
4. signaler les décisions structurantes avant de coder ;
5. modifier uniquement les fichiers nécessaires ;
6. exécuter les vérifications appropriées ;
7. résumer précisément les modifications ;
8. fournir un protocole de test manuel ;
9. examiner le diff ;
10. ne commit que lorsque l'utilisateur valide la fonctionnalité.

Privilégier les petites fonctionnalités cohérentes.

Éviter les refactors massifs non nécessaires.

---

## 7. Vérifications

Après modification, utiliser selon le périmètre :

### Backend

- Laravel Pint ;
- tests Laravel pertinents ;
- vérification des routes si nécessaire.

### Frontend

- vérification TypeScript ;
- lint si configuré ;
- build Vite.

Ne pas installer ou mettre à jour des dépendances sans nécessité explicite.

---

## 8. Git

Avant de modifier :

- vérifier `git status` ;
- prendre en compte les modifications locales non commitées.

Après modification :

- présenter les fichiers touchés ;
- présenter le diff ou son résumé ;
- ne jamais écraser une modification utilisateur ;
- ne jamais commit automatiquement sans validation explicite.

---

## 9. Principe général

Tempo doit rester compréhensible et contrôlable par son développeur.

L'agent est un collaborateur technique, pas une boîte noire.

Toujours expliquer :

- ce qui est modifié ;
- pourquoi ;
- comment les données circulent ;
- comment vérifier le résultat.
