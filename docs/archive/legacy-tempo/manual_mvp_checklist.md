# Manual MVP Checklist — Tempo

## Objectif

Cette checklist sert à vérifier manuellement que le MVP Tempo fonctionne après une modification importante.

Elle doit être lancée avant un commit important, un push ou une session de refactor.

---

## Pré-requis

### Backend Laravel lancé

```bash
cd backend
php artisan serve --host=127.0.0.1 --port=8000
```

### Frontend React/Vite lancé

```bash
cd frontend
npm run dev
```

### URLs locales

- Frontend : `http://localhost:5173`
- Backend API : `http://127.0.0.1:8000/api`

---

# 1. API Health

## URL API

```txt
GET http://127.0.0.1:8000/api/health
```

## Commande

```bash
curl -i http://127.0.0.1:8000/api/health
```

## Résultat attendu

- HTTP `200 OK`
- JSON :

```json
{
  "ok": true,
  "db": "up"
}
```

## Validation

- [ ] L’API répond.
- [ ] La DB répond.
- [ ] Aucun message d’erreur Laravel.

---

# 2. Sites

## URL API

```txt
GET http://127.0.0.1:8000/api/sites
```

## URL Front

```txt
http://localhost:5173/sites
```

## Commande API

```bash
curl -i http://127.0.0.1:8000/api/sites
```

## Résultat attendu API

La réponse doit contenir les sites seedés, par exemple :

```txt
Site A - Centre Ville
Site B - Quartier Est
```

## Résultat attendu Front

La page `/sites` affiche les sites disponibles.

## Validation

- [ ] L’API `/sites` répond.
- [ ] Les sites s’affichent côté front.
- [ ] Les noms des sites sont lisibles.
- [ ] Aucun crash React.

---

# 3. Workers

## URL API

```txt
GET http://127.0.0.1:8000/api/workers
```

## URL Front

```txt
http://localhost:5173/workers
```

## Commande API

```bash
curl -i http://127.0.0.1:8000/api/workers
```

## Résultat attendu API

La réponse doit contenir les workers seedés, par exemple :

```txt
John Doe
Maria Luna
Antoine Rey
Amel Benali
Karim Haddad
Sarah Nguyen
```

## Résultat attendu Front

La page `/workers` affiche les employés.

## Validation

- [ ] L’API `/workers` répond.
- [ ] Les workers s’affichent côté front.
- [ ] Les workers peuvent avoir `site_id = null`.
- [ ] Aucun crash React.

## Note MVP

`worker.site_id` ne représente pas forcément le site réellement travaillé.

Le vrai site de travail vient du planning :

```txt
roster.site_id
shift.site_id
assignment.shift_id → shift.site_id
```

---

# 4. Rosters

## URL API

```txt
GET http://127.0.0.1:8000/api/rosters
```

## URL Front

```txt
http://localhost:5173/rosters
```

## Commande API

```bash
curl -i http://127.0.0.1:8000/api/rosters
```

## Résultat attendu API

La réponse doit contenir les rosters existants avec :

- `id`
- `site_id`
- `site_name`
- `period_start`
- `period_end`
- `status`
- `notes`

Exemple :

```txt
Roster Semaine 41 – Site A
Roster Semaine 41 – Site B
```

## Résultat attendu Front

La page `/rosters` affiche :

- les rosters ;
- le nom du site ;
- la période ;
- le statut ;
- un lien vers le détail.

## Validation

- [ ] L’API `/rosters` répond.
- [ ] Le champ `site_name` est présent.
- [ ] La page `/rosters` affiche les rosters.
- [ ] Le lien vers le détail fonctionne.

---

# 5. Détail roster — shifts + assignés

## URL API roster

```txt
GET http://127.0.0.1:8000/api/rosters/1
```

## URL API shifts

```txt
GET http://127.0.0.1:8000/api/rosters/1/shifts
```

## URL API assignments

```txt
GET http://127.0.0.1:8000/api/rosters/1/assignments
```

## URL Front

```txt
http://localhost:5173/rosters/1
```

## Commandes API

```bash
curl -i http://127.0.0.1:8000/api/rosters/1
curl -i http://127.0.0.1:8000/api/rosters/1/shifts
curl -i http://127.0.0.1:8000/api/rosters/1/assignments
```

## Résultat attendu Front

La page `/rosters/1` affiche :

- le nom du site ;
- la période ;
- les shifts groupés par jour ;
- les horaires ;
- le nombre d’assignés ;
- les workers assignés ;
- le statut de couverture.

## Validation

- [ ] Le titre affiche le site.
- [ ] La période du roster est affichée.
- [ ] Les shifts sont groupés par jour.
- [ ] Les assignés sont affichés sous chaque shift.
- [ ] Les statuts de couverture sont visibles.
- [ ] Le lien “Retour aux rosters” fonctionne.

---

# 6. Vue planning par employé

## URL Front

```txt
http://localhost:5173/planning
```

## APIs utilisées

```txt
GET /api/rosters
GET /api/workers
GET /api/rosters/{id}/shifts
GET /api/rosters/{id}/assignments
```

## Résultat attendu

La page `/planning` affiche un tableau avec :

- une ligne par employé ;
- une colonne par jour ;
- les horaires assignés dans les cellules ;
- les jours non assignés indiqués comme repos / non assigné ;
- un sélecteur de roster.

## Validation

- [ ] La page `/planning` charge sans erreur.
- [ ] Le sélecteur de roster fonctionne.
- [ ] Les employés sont affichés en lignes.
- [ ] Les jours sont affichés en colonnes.
- [ ] Les horaires apparaissent dans les bonnes cellules.
- [ ] Les employés sans shift affichent “Repos / non assigné”.

---

# 7. Modifier les assignés d’un shift

## URL Front

```txt
http://localhost:5173/rosters/1
```

## URL API PUT

```txt
PUT http://127.0.0.1:8000/api/shifts/{shiftId}/assignments
```

## Exemple commande API

```bash
curl -i -X PUT http://127.0.0.1:8000/api/shifts/2/assignments \
  -H "Content-Type: application/json" \
  -d '{"worker_ids":[1,2]}'
```

## Résultat attendu API

La réponse renvoie les assignments mises à jour :

```txt
shift_id = 2
worker_id = 1
worker_id = 2
```

avec les noms des workers.

## Résultat attendu Front

Sur une carte shift :

1. Cliquer sur `Modifier assignés`.
2. Cocher ou décocher des workers.
3. Cliquer sur `Enregistrer`.
4. La carte se met à jour.
5. Après refresh de la page, les assignés restent modifiés.

## Validation

- [ ] Le bouton `Modifier assignés` s’affiche.
- [ ] Les workers s’affichent avec des checkboxes.
- [ ] Enregistrer appelle bien le backend.
- [ ] Les assignés affichés changent après sauvegarde.
- [ ] Après refresh, les changements persistent.
- [ ] Annuler ferme l’édition sans modifier les données.

---

# 8. Cas 0/2 assignés autorisé

## URL Front

```txt
http://localhost:5173/rosters/1
```

## Test

Sur un shift avec `required_count = 2` :

1. Cliquer sur `Modifier assignés`.
2. Décocher tous les workers.
3. Cliquer sur `Enregistrer`.

## Résultat attendu

La carte affiche :

```txt
0/2 assignés
Couverture incomplète
Aucun employé assigné
```

## Validation

- [ ] Le backend accepte `worker_ids: []`.
- [ ] Le shift peut être vidé.
- [ ] L’interface affiche “Aucun employé assigné”.
- [ ] La couverture est indiquée comme incomplète.
- [ ] Aucun blocage métier n’empêche l’action.

---

# 9. Cas 3/2 assignés autorisé

## URL Front

```txt
http://localhost:5173/rosters/1
```

## Test

Sur un shift avec `required_count = 2` :

1. Cliquer sur `Modifier assignés`.
2. Sélectionner 3 workers.
3. Cliquer sur `Enregistrer`.

## Résultat attendu

La carte affiche :

```txt
3/2 assignés
Sur-couvert
```

## Validation

- [ ] Le backend accepte plus de workers que `required_count`.
- [ ] Le front affiche `3/2 assignés`.
- [ ] Le front affiche “Sur-couvert”.
- [ ] L’action n’est pas bloquée.

---

# 10. `required_count` non bloquant

## Règle produit

`required_count` est un objectif de couverture, pas une limite stricte.

## Cas attendus

```txt
0/2 → Couverture incomplète
1/2 → Couverture incomplète
2/2 → Couverture OK
3/2 → Sur-couvert
```

## Validation

- [ ] `required_count` sert à informer.
- [ ] `required_count` ne bloque pas l’enregistrement.
- [ ] Le manager peut sous-couvrir volontairement.
- [ ] Le manager peut sur-couvrir volontairement.
- [ ] L’interface signale clairement l’état de couverture.

---

# 11. Tests techniques rapides avant commit

## Frontend build

```bash
cd frontend
npm run build
```

## Git status

```bash
cd ..
git status
```

## Validation

- [ ] Le build frontend passe.
- [ ] Aucun `.env` n’apparaît dans `git status`.
- [ ] Aucun fichier sensible n’est prêt à être commit.
- [ ] Les changements sont commités après validation manuelle.

---

# Règle générale MVP

Pour le MVP :

```txt
Tempo signale.
Le manager décide.
```

Les règles métier suivantes sont des warnings, pas des blocages :

- repos insuffisant ;
- compétence manquante ;
- heures contractuelles dépassées ;
- heures contractuelles insuffisantes ;
- sous-couverture ;
- sur-couverture.

Les erreurs d’intégrité restent bloquantes :

- worker inexistant ;
- shift inexistant ;
- org_id incohérent ;
- payload invalide ;
- doublon incohérent ;
- violation multi-tenant.
