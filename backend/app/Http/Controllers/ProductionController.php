<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductionController extends Controller
{
    /**
     * Retourne la feuille de production d'un site pour une date.
     *
     * Tous les produits actifs sont retournés, même si aucune saisie
     * n'existe encore dans y_production_entries.
     */
    public function index(Request $request)
    {
    $orgId = (int) $request->query(
        'org_id',
        (int) config('tempo.default_org_id')
    );

    $validated = $request->validate([
        'site_id' => ['required', 'integer', 'min:1'],
        'date' => ['required', 'date_format:Y-m-d'],
    ]);

    $siteId = (int) $validated['site_id'];
    $date = $validated['date'];

    /*
    |--------------------------------------------------------------------------
    | Vérification du site
    |--------------------------------------------------------------------------
    |
    | Le site doit exister ET appartenir à l'organisation courante.
    |
    */

    $siteExists = DB::table('y_sites')
        ->where('id', $siteId)
        ->where('org_id', $orgId)
        ->exists();

    if (!$siteExists) {
        return response()->json([
            'error' => 'site_not_found',
            'message' => 'Site introuvable pour cette organisation.',
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Lecture de la feuille de production
    |--------------------------------------------------------------------------
    |
    | LEFT JOIN :
    | on retourne tous les produits actifs, même lorsqu'aucune saisie
    | de production n'existe encore pour la date.
    |
    */

    $rows = DB::table('y_products as p')
        ->leftJoin('y_production_entries as e', function ($join) use (
            $orgId,
            $siteId,
            $date
        ) {
            $join->on('e.product_id', '=', 'p.id')
                ->where('e.org_id', '=', $orgId)
                ->where('e.site_id', '=', $siteId)
                ->where('e.production_date', '=', $date);
        })
        ->select([
            'p.id as product_id',
            'p.name',
            'p.family',
            'p.category',
            'p.conservation',

            'e.id as entry_id',
            'e.production_date',

            /*
             * Un produit avec une DLC J ne peut pas avoir
             * de stock provenant de la veille.
             *
             * Même si une ancienne mauvaise valeur existe en DB,
             * l'API retourne donc NULL.
             */
            DB::raw("
                CASE
                    WHEN p.conservation = 'J' THEN NULL
                    ELSE CAST(e.stock_previous AS UNSIGNED)
                END AS stock_previous
            "),

            'e.production',
            'e.reproduction',
            'e.losses',
            'e.sales',
            'e.stock_end',
        ])
        ->where('p.org_id', $orgId)
        ->where('p.site_id', $siteId)
        ->where('p.is_active', 1)

        /*
         * Ordre métier :
         * Salé d'abord, Sucré ensuite.
         */
        ->orderByRaw("
            CASE
                WHEN p.family = 'Salé' THEN 1
                WHEN p.family = 'Sucré' THEN 2
                ELSE 3
            END
        ")

        ->orderBy('p.category')
        ->orderBy('p.name')
        ->get()
        ->map(function ($row) {
            $row->stock_previous = $row->stock_previous === null
            ? null
            : (int) $row->stock_previous;

            return $row;
        });

    return response()->json([
        'org_id' => $orgId,
        'site_id' => $siteId,
        'date' => $date,
        'products' => $rows,
    ]);
    }

    /**
     * Enregistre ou met à jour la production d'un site pour une date.
     *
     * Les champs métier sont volontairement nullable :
     * NULL = non renseigné
     * 0    = zéro explicitement renseigné
     *
     * Une donnée manquante ne bloque donc pas l'enregistrement.
     */
    public function update(Request $request)
    {
    $orgId = (int) $request->query(
        'org_id',
        (int) config('tempo.default_org_id')
    );

    /*
    |--------------------------------------------------------------------------
    | Validation technique du payload
    |--------------------------------------------------------------------------
    |
    | Les valeurs de production peuvent être NULL.
    |
    | NULL = donnée non renseignée
    | 0    = zéro réellement renseigné
    |
    */

    $validated = $request->validate([
        'site_id' => [
            'required',
            'integer',
            'min:1',
        ],

        'date' => [
            'required',
            'date_format:Y-m-d',
        ],

        'entries' => [
            'present',
            'array',
        ],

        'entries.*.product_id' => [
            'required',
            'integer',
            'min:1',
            'distinct',
        ],

        'entries.*.stock_previous' => [
            'nullable',
            'integer',
            'min:0',
        ],

        'entries.*.production' => [
            'nullable',
            'integer',
            'min:0',
        ],

        'entries.*.reproduction' => [
            'nullable',
            'integer',
            'min:0',
        ],

        'entries.*.losses' => [
            'nullable',
            'integer',
            'min:0',
        ],

        'entries.*.sales' => [
            'nullable',
            'integer',
            'min:0',
        ],

        'entries.*.stock_end' => [
            'nullable',
            'integer',
            'min:0',
        ],
    ]);

    $siteId = (int) $validated['site_id'];
    $date = $validated['date'];
    $entries = $validated['entries'];

    /*
    |--------------------------------------------------------------------------
    | Vérification du site
    |--------------------------------------------------------------------------
    |
    | Le site doit exister et appartenir à l'organisation courante.
    |
    */

    $siteExists = DB::table('y_sites')
        ->where('id', $siteId)
        ->where('org_id', $orgId)
        ->exists();

    if (!$siteExists) {
        return response()->json([
            'error' => 'site_not_found',
            'message' => 'Site introuvable pour cette organisation.',
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Récupération des produits
    |--------------------------------------------------------------------------
    |
    | On récupère également "conservation" car elle sert maintenant
    | à appliquer une règle métier :
    |
    | conservation = J
    | => aucun stock J-1 possible.
    |
    */

    $productIds = collect($entries)
        ->pluck('product_id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();

    $productsById = collect();

    if (count($productIds) > 0) {
        $productsById = DB::table('y_products')
            ->select([
                'id',
                'conservation',
            ])
            ->where('org_id', $orgId)
            ->where('site_id', $siteId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        /*
        |--------------------------------------------------------------------------
        | Vérification des produits
        |--------------------------------------------------------------------------
        |
        | Tous les produits reçus doivent appartenir :
        | - à l'organisation courante ;
        | - au site courant.
        |
        */

        if ($productsById->count() !== count($productIds)) {
            return response()->json([
                'error' => 'product_not_found',
                'message' => 'Un ou plusieurs produits sont invalides pour ce site.',
            ], 422);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Enregistrement transactionnel
    |--------------------------------------------------------------------------
    |
    | Si une erreur technique survient pendant l'enregistrement,
    | toute la transaction est annulée.
    |
    */

    $savedEntries = 0;

    DB::transaction(function () use (
        $orgId,
        $siteId,
        $date,
        $entries,
        $productsById,
        &$savedEntries
    ) {
        foreach ($entries as $entry) {
            $productId = (int) $entry['product_id'];

            /*
            |--------------------------------------------------------------------------
            | Informations du produit
            |--------------------------------------------------------------------------
            */

            $product = $productsById->get($productId);

            /*
            |--------------------------------------------------------------------------
            | Règle DLC J
            |--------------------------------------------------------------------------
            |
            | Un produit qui ne se conserve que le jour même
            | ne peut jamais avoir de stock provenant de J-1.
            |
            | Même si le frontend envoie accidentellement une valeur,
            | le backend la remplace par NULL.
            |
            */

            $isSameDayOnly = $product->conservation === 'J';

            /*
            |--------------------------------------------------------------------------
            | Valeurs métier
            |--------------------------------------------------------------------------
            |
            | Une clé absente ou explicitement NULL reste NULL.
            |
            | Attention :
            | 0 doit absolument rester 0.
            |
            */

            $values = [
                'stock_previous' => $isSameDayOnly
                    ? null
                    : ($entry['stock_previous'] ?? null),

                'production' => $entry['production'] ?? null,
                'reproduction' => $entry['reproduction'] ?? null,
                'losses' => $entry['losses'] ?? null,
                'sales' => $entry['sales'] ?? null,
                'stock_end' => $entry['stock_end'] ?? null,
            ];

            /*
            |--------------------------------------------------------------------------
            | Recherche d'une éventuelle ligne existante
            |--------------------------------------------------------------------------
            */

            $existingEntry = DB::table('y_production_entries')
                ->where('org_id', $orgId)
                ->where('site_id', $siteId)
                ->where('product_id', $productId)
                ->where('production_date', $date)
                ->first();

            /*
            |--------------------------------------------------------------------------
            | Ligne entièrement vide
            |--------------------------------------------------------------------------
            |
            | Si aucune donnée n'est renseignée ET qu'aucune ligne
            | n'existe déjà, on ne crée pas de ligne vide en base.
            |
            */

            $hasValue = count(
                array_filter(
                    $values,
                    fn ($value) => $value !== null
                )
            ) > 0;

            if (!$hasValue && !$existingEntry) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Mise à jour d'une ligne existante
            |--------------------------------------------------------------------------
            */

            if ($existingEntry) {
                DB::table('y_production_entries')
                    ->where('id', $existingEntry->id)
                    ->where('org_id', $orgId)
                    ->update([
                        'stock_previous' => $values['stock_previous'],
                        'production' => $values['production'],
                        'reproduction' => $values['reproduction'],
                        'losses' => $values['losses'],
                        'sales' => $values['sales'],
                        'stock_end' => $values['stock_end'],
                        'updated_at' => now(),
                    ]);

                $savedEntries++;

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Création d'une nouvelle ligne
            |--------------------------------------------------------------------------
            */

            DB::table('y_production_entries')
                ->insert([
                    'org_id' => $orgId,
                    'site_id' => $siteId,
                    'product_id' => $productId,
                    'production_date' => $date,

                    'stock_previous' => $values['stock_previous'],
                    'production' => $values['production'],
                    'reproduction' => $values['reproduction'],
                    'losses' => $values['losses'],
                    'sales' => $values['sales'],
                    'stock_end' => $values['stock_end'],

                    /*
                     * Pas encore d'authentification utilisateur
                     * dans le MVP actuel.
                     *
                     * created_by / updated_by restent donc NULL.
                     */

                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $savedEntries++;
        }
    });

    /*
    |--------------------------------------------------------------------------
    | Réponse
    |--------------------------------------------------------------------------
    */

    return response()->json([
        'ok' => true,
        'org_id' => $orgId,
        'site_id' => $siteId,
        'date' => $date,
        'saved_entries' => $savedEntries,
    ]);
    }

    public function showDay(Request $request)
    {
    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    $validated = $request->validate([
        'site_id' => ['required', 'integer', 'min:1'],
        'date' => ['required', 'date_format:Y-m-d'],
    ]);

    $orgId = (int) config('tempo.default_org_id');
    $siteId = (int) $validated['site_id'];
    $date = $validated['date'];

    /*
    |--------------------------------------------------------------------------
    | Vérification du site
    |--------------------------------------------------------------------------
    */

    $siteExists = DB::table('y_sites')
        ->where('id', $siteId)
        ->where('org_id', $orgId)
        ->exists();

    if (!$siteExists) {
        return response()->json([
            'error' => 'site_not_found',
            'message' => 'Le site demandé est introuvable pour cette organisation.',
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Recherche de la journée
    |--------------------------------------------------------------------------
    |
    | IMPORTANT :
    | GET ne crée aucune donnée.
    |
    | Si aucune journée n'existe :
    | l'état fonctionnel est "À faire".
    |
    */

    $day = DB::table('y_production_days')
        ->where('org_id', $orgId)
        ->where('site_id', $siteId)
        ->where('production_date', $date)
        ->first();

    if (!$day) {
        return response()->json([
            'exists' => false,
            'org_id' => $orgId,
            'site_id' => $siteId,
            'date' => $date,
            'status' => 'not_started',
            'products_count' => 0,
            'products' => [],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Lecture des produits de CETTE journée
    |--------------------------------------------------------------------------
    |
    | On ne lit PAS y_products pour construire la feuille.
    |
    | La source historique est :
    |
    | y_production_day_products
    |
    | Les noms, catégories, familles, conservation et ordres
    | viennent donc du snapshot de la journée.
    |
    */

    $products = DB::table('y_production_day_products as dp')

        ->leftJoin(
            'y_production_entries as e',
            'e.production_day_product_id',
            '=',
            'dp.id'
        )

        ->where('dp.org_id', $orgId)
        ->where('dp.site_id', $siteId)
        ->where('dp.production_day_id', $day->id)

        ->select([
            /*
             * Identifiants
             */
            'dp.id as production_day_product_id',
            'dp.product_id',
            'dp.category_id',
            'dp.family_id',

            /*
             * Snapshot historique
             */
            'dp.product_name_snapshot as name',
            'dp.category_name_snapshot as category',
            'dp.family_name_snapshot as family',
            'dp.conservation_snapshot as conservation',

            /*
             * Présence dans la feuille
             */
            'dp.is_included',
            'dp.excluded_at',
            'dp.excluded_by',

            /*
             * Saisie
             */
            'e.id as entry_id',
            'e.stock_previous',
            'e.production',
            'e.reproduction',
            'e.losses',
            'e.sales',
            'e.stock_end',

            /*
             * Ordre historique
             */
            'dp.family_order_snapshot',
            'dp.category_order_snapshot',
            'dp.product_order_snapshot',
        ])

        ->orderBy('dp.family_order_snapshot')
        ->orderBy('dp.family_name_snapshot')

        ->orderBy('dp.category_order_snapshot')
        ->orderBy('dp.category_name_snapshot')

        ->orderBy('dp.product_order_snapshot')
        ->orderBy('dp.product_name_snapshot')

        ->get()

        /*
         * On stabilise les types numériques retournés à React.
         */
        ->map(function ($row) {
            $row->production_day_product_id =
                (int) $row->production_day_product_id;

            $row->product_id =
                (int) $row->product_id;

            $row->category_id =
                (int) $row->category_id;

            $row->family_id =
                (int) $row->family_id;

            $row->is_included =
                (bool) $row->is_included;

            $row->entry_id =
                $row->entry_id === null
                    ? null
                    : (int) $row->entry_id;

            foreach ([
                'stock_previous',
                'production',
                'reproduction',
                'losses',
                'sales',
                'stock_end',
            ] as $field) {
                $row->{$field} =
                    $row->{$field} === null
                        ? null
                        : (int) $row->{$field};
            }

            return $row;
        });

    /*
    |--------------------------------------------------------------------------
    | Réponse
    |--------------------------------------------------------------------------
    */

    return response()->json([
        'exists' => true,

        'day' => [
            'id' => (int) $day->id,
            'org_id' => (int) $day->org_id,
            'site_id' => (int) $day->site_id,

            'production_date' => $day->production_date,
            'status' => $day->status,

            'started_at' => $day->started_at,
            'started_by' => $day->started_by === null
                ? null
                : (int) $day->started_by,

            'finished_at' => $day->finished_at,
            'finished_by' => $day->finished_by === null
                ? null
                : (int) $day->finished_by,

            'closed_at' => $day->closed_at,
            'closed_by' => $day->closed_by === null
                ? null
                : (int) $day->closed_by,
        ],

        'products_count' => $products
            ->where('is_included', true)
            ->count(),

        'products' => $products,
    ]);
    }

    public function updateDay(Request $request)
    {
    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    |
    | IMPORTANT :
    |
    | stock_previous n'est volontairement PAS modifiable ici.
    |
    | Il est initialisé automatiquement par Tempo lors de
    | l'ouverture de la journée.
    |
    */

    $validated = $request->validate([
        'site_id' => [
            'required',
            'integer',
            'min:1',
        ],

        'date' => [
            'required',
            'date_format:Y-m-d',
        ],

        'entries' => [
            'present',
            'array',
        ],

        'entries.*.production_day_product_id' => [
            'required',
            'integer',
            'min:1',
            'distinct',
        ],

        'entries.*.production' => [
            'present',
            'nullable',
            'integer',
            'min:0',
        ],

        'entries.*.reproduction' => [
            'present',
            'nullable',
            'integer',
            'min:0',
        ],

        'entries.*.losses' => [
            'present',
            'nullable',
            'integer',
            'min:0',
        ],

        'entries.*.sales' => [
            'present',
            'nullable',
            'integer',
            'min:0',
        ],

        'entries.*.stock_end' => [
            'present',
            'nullable',
            'integer',
            'min:0',
        ],
    ]);

    $orgId = (int) config('tempo.default_org_id');

    $siteId = (int) $validated['site_id'];
    $date = $validated['date'];
    $entries = $validated['entries'];

    /*
    |--------------------------------------------------------------------------
    | 1. Vérification du site
    |--------------------------------------------------------------------------
    */

    $siteExists = DB::table('y_sites')
        ->where('id', $siteId)
        ->where('org_id', $orgId)
        ->exists();

    if (!$siteExists) {
        return response()->json([
            'error' => 'site_not_found',
            'message' => 'Le site demandé est introuvable pour cette organisation.',
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Recherche de la journée
    |--------------------------------------------------------------------------
    */

    $day = DB::table('y_production_days')
        ->where('org_id', $orgId)
        ->where('site_id', $siteId)
        ->where('production_date', $date)
        ->first();

    if (!$day) {
        return response()->json([
            'error' => 'production_day_not_found',
            'message' => 'La feuille de production demandée n’existe pas.',
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Vérification du statut
    |--------------------------------------------------------------------------
    |
    | Une feuille n'est modifiable normalement que lorsqu'elle
    | est en cours.
    |
    | Plus tard :
    | - finished = terminée par l'équipe
    | - closed   = clôturée par le manager
    |
    | Une réouverture manager remettra la feuille dans un état
    | permettant à nouveau sa modification.
    |
    */

    if ($day->status !== 'in_progress') {
        return response()->json([
            'error' => 'production_day_not_editable',
            'message' => 'Cette feuille de production n’est plus modifiable.',
            'status' => $day->status,
        ], 409);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. IDs des produits de journée reçus
    |--------------------------------------------------------------------------
    */

    $dayProductIds = collect($entries)
        ->pluck('production_day_product_id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();

    /*
    |--------------------------------------------------------------------------
    | 5. Vérification des produits de la journée
    |--------------------------------------------------------------------------
    |
    | Chaque ID reçu doit :
    |
    | - appartenir à l'organisation ;
    | - appartenir au site ;
    | - appartenir à CETTE journée ;
    | - être inclus dans la feuille.
    |
    */

    $dayProductsById = collect();

    if (count($dayProductIds) > 0) {
        $dayProductsById = DB::table(
            'y_production_day_products as dp'
        )

            ->leftJoin(
                'y_production_entries as e',
                'e.production_day_product_id',
                '=',
                'dp.id'
            )

            ->where('dp.org_id', $orgId)
            ->where('dp.site_id', $siteId)
            ->where('dp.production_day_id', $day->id)

            ->where('dp.is_included', 1)

            ->whereIn(
                'dp.id',
                $dayProductIds
            )

            ->select([
                'dp.id as production_day_product_id',
                'dp.product_id',
                'dp.conservation_snapshot',

                'e.id as entry_id',
            ])

            ->get()

            ->keyBy('production_day_product_id');

        /*
         * Tous les IDs envoyés doivent avoir été retrouvés.
         */

        if (
            $dayProductsById->count()
            !== count($dayProductIds)
        ) {
            return response()->json([
                'error' => 'invalid_production_day_product',
                'message' =>
                    'Un ou plusieurs produits ne sont pas valides pour cette feuille.',
            ], 422);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 6. Vérification de l'intégrité des entries
    |--------------------------------------------------------------------------
    |
    | openDay() crée normalement toujours une entry pour chaque
    | produit de journée.
    |
    | Si elle manque, ce n'est pas une erreur utilisateur :
    | c'est une incohérence technique.
    |
    */

    foreach ($dayProductsById as $dayProduct) {
        if ($dayProduct->entry_id === null) {
            return response()->json([
                'error' => 'production_entry_missing',
                'message' =>
                    'Une ligne de production attendue est absente.',
                'production_day_product_id' =>
                    (int) $dayProduct->production_day_product_id,
            ], 409);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 7. Enregistrement transactionnel
    |--------------------------------------------------------------------------
    */

    $savedEntries = 0;
    $now = now('UTC');

    DB::transaction(function () use (
        $entries,
        $dayProductsById,
        $orgId,
        $now,
        &$savedEntries
    ) {
        foreach ($entries as $entry) {

            $productionDayProductId =
                (int) $entry['production_day_product_id'];

            $dayProduct = $dayProductsById->get(
                $productionDayProductId
            );

            /*
            |--------------------------------------------------------------------------
            | Valeurs métier
            |--------------------------------------------------------------------------
            |
            | Pendant in_progress :
            |
            | NULL = pas encore renseigné
            | 0    = zéro explicitement renseigné
            |
            */

            $values = [
                'production' =>
                    $entry['production'],

                'reproduction' =>
                    $entry['reproduction'],

                'losses' =>
                    $entry['losses'],

                'sales' =>
                    $entry['sales'],

                'stock_end' =>
                    $entry['stock_end'],
            ];

            /*
            |--------------------------------------------------------------------------
            | Mise à jour
            |--------------------------------------------------------------------------
            |
            | stock_previous n'est volontairement PAS touché.
            |
            */

            DB::table('y_production_entries')
                ->where('id', $dayProduct->entry_id)
                ->where('org_id', $orgId)
                ->where(
                    'production_day_product_id',
                    $productionDayProductId
                )
                ->update([
                    'production' =>
                        $values['production'],

                    'reproduction' =>
                        $values['reproduction'],

                    'losses' =>
                        $values['losses'],

                    'sales' =>
                        $values['sales'],

                    'stock_end' =>
                        $values['stock_end'],

                    'updated_by' => null,
                    'updated_at' => $now,
                ]);

            $savedEntries++;
        }
    });

    /*
    |--------------------------------------------------------------------------
    | 8. Réponse
    |--------------------------------------------------------------------------
    */

    return response()->json([
        'ok' => true,

        'day' => [
            'id' => (int) $day->id,
            'date' => $day->production_date,
            'status' => $day->status,
        ],

        'saved_entries' => $savedEntries,
    ]);
    }

    public function openDay(Request $request)
    {
    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    $validated = $request->validate([
        'site_id' => ['required', 'integer', 'min:1'],
        'date' => ['required', 'date_format:Y-m-d'],
    ]);

    $orgId = (int) config('tempo.default_org_id');
    $siteId = (int) $validated['site_id'];
    $date = $validated['date'];

    /*
    |--------------------------------------------------------------------------
    | Vérification du site
    |--------------------------------------------------------------------------
    */

    $siteExists = DB::table('y_sites')
        ->where('id', $siteId)
        ->where('org_id', $orgId)
        ->exists();

    if (!$siteExists) {
        return response()->json([
            'error' => 'site_not_found',
            'message' =>
                'Le site demandé est introuvable pour cette organisation.',
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Transaction
    |--------------------------------------------------------------------------
    */

    return DB::transaction(function () use (
        $orgId,
        $siteId,
        $date
    ) {

        /*
        |--------------------------------------------------------------------------
        | 1. Journée déjà existante
        |--------------------------------------------------------------------------
        */

        $existingDay = DB::table('y_production_days')
            ->where('org_id', $orgId)
            ->where('site_id', $siteId)
            ->where('production_date', $date)
            ->first();

        if ($existingDay) {
            $productsCount = DB::table('y_production_day_products')
                ->where('production_day_id', $existingDay->id)
                ->where('is_included', 1)
                ->count();

            return response()->json([
                'created' => false,
                'day' => $existingDay,
                'products_count' => $productsCount,
            ]);
        }

        $now = now('UTC');

        /*
        |--------------------------------------------------------------------------
        | 2. Création de la journée
        |--------------------------------------------------------------------------
        */

        $productionDayId = DB::table('y_production_days')
            ->insertGetId([
                'org_id' => $orgId,
                'site_id' => $siteId,

                'production_date' => $date,

                'status' => 'in_progress',

                'started_at' => $now,
                'started_by' => null,

                'finished_at' => null,
                'finished_by' => null,

                'closed_at' => null,
                'closed_by' => null,

                'created_by' => null,
                'created_at' => $now,

                'updated_by' => null,
                'updated_at' => $now,
            ]);

        /*
        |--------------------------------------------------------------------------
        | 3. Historique du premier statut
        |--------------------------------------------------------------------------
        */

        DB::table('y_production_day_status_history')
            ->insert([
                'org_id' => $orgId,
                'site_id' => $siteId,

                'production_day_id' => $productionDayId,

                'from_status' => null,
                'to_status' => 'in_progress',

                'reason' => null,

                'created_by' => null,
                'created_at' => $now,
            ]);

        /*
        |--------------------------------------------------------------------------
        | 4. Produits actifs
        |--------------------------------------------------------------------------
        */

        $products = DB::table('y_products as p')

            ->join(
                'y_product_categories as c',
                'c.id',
                '=',
                'p.category_id'
            )

            ->join(
                'y_product_families as f',
                'f.id',
                '=',
                'c.family_id'
            )

            ->where('p.org_id', $orgId)
            ->where('p.site_id', $siteId)
            ->where('p.is_active', 1)

            ->where('c.org_id', $orgId)
            ->where('c.site_id', $siteId)
            ->where('c.is_active', 1)

            ->where('f.org_id', $orgId)
            ->where('f.site_id', $siteId)
            ->where('f.is_active', 1)

            ->select([
                'p.id as product_id',
                'p.name as product_name',
                'p.conservation',
                'p.display_order as product_order',

                'c.id as category_id',
                'c.name as category_name',
                'c.display_order as category_order',

                'f.id as family_id',
                'f.name as family_name',
                'f.display_order as family_order',
            ])

            ->orderBy('f.display_order')
            ->orderBy('c.display_order')
            ->orderBy('p.display_order')
            ->orderBy('p.name')

            ->get();

        /*
        |--------------------------------------------------------------------------
        | Aucun produit
        |--------------------------------------------------------------------------
        */

        if ($products->isEmpty()) {
            $day = DB::table('y_production_days')
                ->where('id', $productionDayId)
                ->first();

            return response()->json([
                'created' => true,
                'day' => $day,
                'products_count' => 0,
            ], 201);
        }

        /*
        |--------------------------------------------------------------------------
        | 5. Veille calendaire
        |--------------------------------------------------------------------------
        */

        $previousDate =
            \Illuminate\Support\Carbon::createFromFormat(
                'Y-m-d',
                $date
            )
                ->subDay()
                ->toDateString();

        $productIds = $products
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        /*
        |--------------------------------------------------------------------------
        | 6. Stocks de J-1
        |--------------------------------------------------------------------------
        */

        $previousEntries = DB::table('y_production_entries as e')

            ->leftJoin(
                'y_production_day_products as dp',
                'dp.id',
                '=',
                'e.production_day_product_id'
            )

            ->leftJoin(
                'y_production_days as d',
                'd.id',
                '=',
                'dp.production_day_id'
            )

            ->where('e.org_id', $orgId)
            ->where('e.site_id', $siteId)

            ->whereIn(
                'e.product_id',
                $productIds
            )

            ->where(
                'e.production_date',
                $previousDate
            )

            ->select([
                'e.product_id',
                'e.stock_end',
                'e.production_day_product_id',

                'dp.is_included as was_included',
                'd.status as day_status',
            ])

            ->get()

            ->keyBy('product_id');

        /*
        |--------------------------------------------------------------------------
        | 7. Préparation des snapshots
        |--------------------------------------------------------------------------
        |
        | IMPORTANT :
        |
        | On ne fait plus un INSERT par produit.
        | On prépare toutes les lignes en mémoire.
        |
        */

        $snapshotRows = [];

        /*
         * On conserve également le Stock J-1 calculé
         * pour construire ensuite les entries.
         */
        $stockPreviousByProductId = [];

        foreach ($products as $product) {

            $productId = (int) $product->product_id;

            /*
            |--------------------------------------------------------------------------
            | Stock J-1
            |--------------------------------------------------------------------------
            */

            $stockPrevious = null;

            if ($product->conservation !== 'J') {

                $previousEntry =
                    $previousEntries->get($productId);

                if ($previousEntry) {

                    $isLegacyEntry =
                        $previousEntry
                            ->production_day_product_id
                        === null;

                    $isValidNewEntry =
                        (int) $previousEntry->was_included === 1
                        && in_array(
                            $previousEntry->day_status,
                            [
                                'finished',
                                'closed',
                            ],
                            true
                        );

                    if (
                        $isLegacyEntry
                        || $isValidNewEntry
                    ) {
                        $stockPrevious =
                            $previousEntry->stock_end === null
                                ? null
                                : (int) $previousEntry->stock_end;
                    }
                }
            }

            $stockPreviousByProductId[$productId] =
                $stockPrevious;

            /*
            |--------------------------------------------------------------------------
            | Snapshot
            |--------------------------------------------------------------------------
            */

            $snapshotRows[] = [
                'org_id' => $orgId,
                'site_id' => $siteId,

                'production_day_id' =>
                    $productionDayId,

                'product_id' =>
                    $productId,

                'category_id' =>
                    (int) $product->category_id,

                'family_id' =>
                    (int) $product->family_id,

                'product_name_snapshot' =>
                    $product->product_name,

                'category_name_snapshot' =>
                    $product->category_name,

                'family_name_snapshot' =>
                    $product->family_name,

                'conservation_snapshot' =>
                    $product->conservation,

                'product_order_snapshot' =>
                    $product->product_order,

                'category_order_snapshot' =>
                    $product->category_order,

                'family_order_snapshot' =>
                    $product->family_order,

                'is_included' => 1,

                'excluded_at' => null,
                'excluded_by' => null,

                'created_by' => null,
                'created_at' => $now,

                'updated_by' => null,
                'updated_at' => $now,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 8. Création de TOUS les snapshots en une requête
        |--------------------------------------------------------------------------
        */

        DB::table('y_production_day_products')
            ->insert($snapshotRows);

        /*
        |--------------------------------------------------------------------------
        | 9. Récupération des IDs générés
        |--------------------------------------------------------------------------
        |
        | Après l'INSERT groupé, on récupère les IDs des snapshots
        | afin de relier correctement y_production_entries.
        |
        */

        $dayProductsByProductId =
            DB::table('y_production_day_products')

                ->where(
                    'production_day_id',
                    $productionDayId
                )

                ->where('org_id', $orgId)
                ->where('site_id', $siteId)

                ->whereIn(
                    'product_id',
                    $productIds
                )

                ->select([
                    'id',
                    'product_id',
                ])

                ->get()

                ->keyBy('product_id');

        /*
        |--------------------------------------------------------------------------
        | 10. Préparation des lignes de saisie
        |--------------------------------------------------------------------------
        */

        $entryRows = [];

        foreach ($products as $product) {

            $productId =
                (int) $product->product_id;

            $dayProduct =
                $dayProductsByProductId->get(
                    $productId
                );

            /*
             * Sécurité technique :
             * chaque snapshot doit avoir été retrouvé.
             */
            if (!$dayProduct) {
                throw new \RuntimeException(
                    'Snapshot de produit introuvable après création.'
                );
            }

            $entryRows[] = [
                'org_id' => $orgId,
                'site_id' => $siteId,

                'product_id' =>
                    $productId,

                'production_day_product_id' =>
                    (int) $dayProduct->id,

                'production_date' =>
                    $date,

                'stock_previous' =>
                    $stockPreviousByProductId[$productId]
                    ?? null,

                'production' => null,
                'reproduction' => null,
                'losses' => null,
                'sales' => null,
                'stock_end' => null,

                'created_by' => null,
                'created_at' => $now,

                'updated_by' => null,
                'updated_at' => $now,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 11. Création de TOUTES les entries en une requête
        |--------------------------------------------------------------------------
        */

        DB::table('y_production_entries')
            ->insert($entryRows);

        /*
        |--------------------------------------------------------------------------
        | 12. Réponse
        |--------------------------------------------------------------------------
        */

        $day = DB::table('y_production_days')
            ->where('id', $productionDayId)
            ->first();

        return response()->json([
            'created' => true,
            'day' => $day,
            'products_count' =>
                $products->count(),
        ], 201);
    });
    }

    public function finishDay(Request $request)
    {
    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    |
    | confirm_zero_fill = false
    | → Tempo contrôle et retourne les valeurs manquantes.
    |
    | confirm_zero_fill = true
    | → les valeurs applicables encore NULL passent à 0,
    |   puis la feuille passe à finished.
    |
    */

    $validated = $request->validate([
        'site_id' => [
            'required',
            'integer',
            'min:1',
        ],

        'date' => [
            'required',
            'date_format:Y-m-d',
        ],

        'confirm_zero_fill' => [
            'sometimes',
            'boolean',
        ],
    ]);

    $orgId = (int) config('tempo.default_org_id');
    $siteId = (int) $validated['site_id'];
    $date = $validated['date'];

    $confirmZeroFill =
        (bool) ($validated['confirm_zero_fill'] ?? false);

    /*
    |--------------------------------------------------------------------------
    | Vérification du site
    |--------------------------------------------------------------------------
    */

    $siteExists = DB::table('y_sites')
        ->where('id', $siteId)
        ->where('org_id', $orgId)
        ->exists();

    if (!$siteExists) {
        return response()->json([
            'error' => 'site_not_found',
            'message' =>
                'Le site demandé est introuvable pour cette organisation.',
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Transaction
    |--------------------------------------------------------------------------
    */

    return DB::transaction(function () use (
        $orgId,
        $siteId,
        $date,
        $confirmZeroFill
    ) {

        /*
        |--------------------------------------------------------------------------
        | 1. Recherche et verrouillage de la journée
        |--------------------------------------------------------------------------
        */

        $day = DB::table('y_production_days')
            ->where('org_id', $orgId)
            ->where('site_id', $siteId)
            ->where('production_date', $date)
            ->lockForUpdate()
            ->first();

        if (!$day) {
            return response()->json([
                'error' => 'production_day_not_found',
                'message' =>
                    'La feuille de production demandée n’existe pas.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Idempotence
        |--------------------------------------------------------------------------
        */

        if ($day->status === 'finished') {
            return response()->json([
                'ok' => true,
                'already_finished' => true,

                'day' => [
                    'id' => (int) $day->id,
                    'date' => $day->production_date,
                    'status' => $day->status,
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Une journée clôturée ne peut pas être terminée
        |--------------------------------------------------------------------------
        */

        if ($day->status === 'closed') {
            return response()->json([
                'error' => 'production_day_closed',
                'message' =>
                    'Cette feuille est clôturée et doit être réouverte par un manager avant modification.',
            ], 409);
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Seule une journée en cours peut être terminée
        |--------------------------------------------------------------------------
        */

        if ($day->status !== 'in_progress') {
            return response()->json([
                'error' => 'invalid_production_day_status',
                'message' =>
                    'Cette feuille ne peut pas être terminée dans son état actuel.',
                'status' => $day->status,
            ], 409);
        }

        /*
        |--------------------------------------------------------------------------
        | 5. Lecture des produits inclus + entries
        |--------------------------------------------------------------------------
        */

        $rows = DB::table('y_production_day_products as dp')

            ->leftJoin(
                'y_production_entries as e',
                'e.production_day_product_id',
                '=',
                'dp.id'
            )

            ->where('dp.org_id', $orgId)
            ->where('dp.site_id', $siteId)
            ->where('dp.production_day_id', $day->id)
            ->where('dp.is_included', 1)

            ->select([
                'dp.id as production_day_product_id',
                'dp.product_name_snapshot as product_name',
                'dp.conservation_snapshot as conservation',

                'e.id as entry_id',

                'e.production',
                'e.reproduction',
                'e.losses',
                'e.sales',
                'e.stock_end',
            ])

            ->get();

        /*
        |--------------------------------------------------------------------------
        | 6. Vérification technique : toutes les entries doivent exister
        |--------------------------------------------------------------------------
        */

        foreach ($rows as $row) {
            if ($row->entry_id === null) {
                return response()->json([
                    'error' => 'production_entry_missing',
                    'message' =>
                        'Une ligne de production attendue est absente.',

                    'production_day_product_id' =>
                        (int) $row->production_day_product_id,

                    'product_name' =>
                        $row->product_name,
                ], 409);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 7. Détection des valeurs manquantes
        |--------------------------------------------------------------------------
        */

        $editableFields = [
            'production',
            'reproduction',
            'losses',
            'sales',
            'stock_end',
        ];

        $missingValuesCount = 0;

        $missingProducts = [];

        $warnings = [];

        foreach ($rows as $row) {

            $productMissingFields = [];

            foreach ($editableFields as $field) {
                if ($row->{$field} === null) {
                    $missingValuesCount++;
                    $productMissingFields[] = $field;
                }
            }

            /*
             * On conserve le détail des champs manquants
             * pour chaque produit.
             */
            if (count($productMissingFields) > 0) {
                $missingProducts[] = [
                    'production_day_product_id' =>
                        (int) $row->production_day_product_id,

                    'product_name' =>
                        $row->product_name,

                    'fields' =>
                        $productMissingFields,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Warning Stock fin
            |--------------------------------------------------------------------------
            |
            | Si production ou reproduction > 0
            | ET stock_end = NULL
            | → on attire l'attention.
            |
            */

            $hasProductionActivity =
                ((int) ($row->production ?? 0) > 0)
                ||
                ((int) ($row->reproduction ?? 0) > 0);

            if (
                $hasProductionActivity
                && $row->stock_end === null
            ) {
                $warnings[] = [
                    'code' =>
                        'stock_end_missing_after_activity',

                    'production_day_product_id' =>
                        (int) $row->production_day_product_id,

                    'product_name' =>
                        $row->product_name,

                    'message' =>
                        'Le stock de fin doit être vérifié pour ce produit.',
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 8. Confirmation nécessaire
        |--------------------------------------------------------------------------
        |
        | Tant que l'utilisateur n'a pas confirmé,
        | aucun NULL n'est transformé silencieusement en 0.
        |
        */

        if (
            $missingValuesCount > 0
            && !$confirmZeroFill
        ) {
            return response()->json([
                'ok' => false,

                'requires_confirmation' => true,

                'message' =>
                    $missingValuesCount
                    . ' valeur(s) ne sont pas renseignée(s).',

                'missing_values_count' =>
                    $missingValuesCount,

                'missing_products' =>
                    $missingProducts,

                'warnings' =>
                    $warnings,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 9. Si confirmation : NULL applicables → 0
        |--------------------------------------------------------------------------
        */

        $now = now('UTC');

        $zeroFilledValuesCount = 0;

        if (
            $missingValuesCount > 0
            && $confirmZeroFill
        ) {

            /*
             * On récupère toutes les entries de cette feuille.
             */
            $entryIds = $rows
                ->pluck('entry_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            /*
             * Une seule requête SQL.
             *
             * COALESCE :
             *
             * NULL → 0
             * valeur existante → conservée
             */
            DB::table('y_production_entries')
                ->where('org_id', $orgId)
                ->whereIn('id', $entryIds)
                ->update([
                    'production' =>
                        DB::raw('COALESCE(production, 0)'),

                    'reproduction' =>
                        DB::raw('COALESCE(reproduction, 0)'),

                    'losses' =>
                        DB::raw('COALESCE(losses, 0)'),

                    'sales' =>
                        DB::raw('COALESCE(sales, 0)'),

                    'stock_end' =>
                        DB::raw('COALESCE(stock_end, 0)'),

                    'updated_by' => null,
                    'updated_at' => $now,
                ]);

            /*
             * Le nombre avait déjà été calculé
             * pendant le contrôle.
             */
            $zeroFilledValuesCount =
                $missingValuesCount;
        }

        /*
        |--------------------------------------------------------------------------
        | 10. Passage à finished
        |--------------------------------------------------------------------------
        */

        DB::table('y_production_days')
            ->where('id', $day->id)
            ->where('org_id', $orgId)
            ->update([
                'status' => 'finished',

                'finished_at' => $now,

                /*
                 * Pas encore d'authentification utilisateur.
                 */
                'finished_by' => null,

                'updated_by' => null,
                'updated_at' => $now,
            ]);

        /*
        |--------------------------------------------------------------------------
        | 11. Historique du changement de statut
        |--------------------------------------------------------------------------
        */

        DB::table('y_production_day_status_history')
            ->insert([
                'org_id' => $orgId,
                'site_id' => $siteId,

                'production_day_id' =>
                    $day->id,

                'from_status' =>
                    'in_progress',

                'to_status' =>
                    'finished',

                'reason' =>
                    null,

                /*
                 * Pas encore d'authentification utilisateur.
                 */
                'created_by' =>
                    null,

                'created_at' =>
                    $now,
            ]);

        /*
        |--------------------------------------------------------------------------
        | 12. Réponse
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'ok' => true,

            'requires_confirmation' => false,

            'day' => [
                'id' => (int) $day->id,
                'date' => $day->production_date,
                'status' => 'finished',
            ],

            'zero_filled_values_count' =>
                $zeroFilledValuesCount,

            'warnings' =>
                $warnings,
        ]);
    });
    }

    public function closeDay(Request $request)
    {
    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    $validated = $request->validate([
        'site_id' => [
            'required',
            'integer',
            'min:1',
        ],

        'date' => [
            'required',
            'date_format:Y-m-d',
        ],
    ]);

    $orgId = (int) config('tempo.default_org_id');
    $siteId = (int) $validated['site_id'];
    $date = $validated['date'];

    /*
    |--------------------------------------------------------------------------
    | Vérification du site
    |--------------------------------------------------------------------------
    */

    $siteExists = DB::table('y_sites')
        ->where('id', $siteId)
        ->where('org_id', $orgId)
        ->exists();

    if (!$siteExists) {
        return response()->json([
            'error' => 'site_not_found',
            'message' =>
                'Le site demandé est introuvable pour cette organisation.',
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Transaction
    |--------------------------------------------------------------------------
    */

    return DB::transaction(function () use (
        $orgId,
        $siteId,
        $date
    ) {

        /*
        |--------------------------------------------------------------------------
        | 1. Recherche et verrouillage de la journée
        |--------------------------------------------------------------------------
        */

        $day = DB::table('y_production_days')
            ->where('org_id', $orgId)
            ->where('site_id', $siteId)
            ->where('production_date', $date)
            ->lockForUpdate()
            ->first();

        if (!$day) {
            return response()->json([
                'error' => 'production_day_not_found',
                'message' =>
                    'La feuille de production demandée n’existe pas.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Idempotence
        |--------------------------------------------------------------------------
        |
        | Si la journée est déjà clôturée,
        | un nouvel appel ne fait aucune modification.
        |
        */

        if ($day->status === 'closed') {
            return response()->json([
                'ok' => true,
                'already_closed' => true,

                'day' => [
                    'id' => (int) $day->id,
                    'date' => $day->production_date,
                    'status' => $day->status,
                    'closed_at' => $day->closed_at,
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Seule une journée finished peut être clôturée
        |--------------------------------------------------------------------------
        |
        | Une journée encore in_progress doit d'abord être
        | terminée par l'équipe.
        |
        */

        if ($day->status !== 'finished') {
            return response()->json([
                'error' => 'production_day_not_ready_to_close',

                'message' =>
                    'La feuille doit être terminée avant de pouvoir être clôturée.',

                'status' => $day->status,
            ], 409);
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Clôture
        |--------------------------------------------------------------------------
        */

        $now = now('UTC');

        DB::table('y_production_days')
            ->where('id', $day->id)
            ->where('org_id', $orgId)
            ->where('site_id', $siteId)
            ->update([
                'status' => 'closed',

                'closed_at' => $now,

                /*
                 * L'utilisateur sera renseigné lorsque
                 * l'authentification / RBAC sera branchée.
                 */
                'closed_by' => null,

                'updated_by' => null,
                'updated_at' => $now,
            ]);

        /*
        |--------------------------------------------------------------------------
        | 5. Historique
        |--------------------------------------------------------------------------
        */

        DB::table('y_production_day_status_history')
            ->insert([
                'org_id' => $orgId,
                'site_id' => $siteId,

                'production_day_id' =>
                    $day->id,

                'from_status' =>
                    'finished',

                'to_status' =>
                    'closed',

                'reason' =>
                    null,

                /*
                 * Pas encore d'authentification.
                 */
                'created_by' =>
                    null,

                'created_at' =>
                    $now,
            ]);

        /*
        |--------------------------------------------------------------------------
        | 6. Réponse
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'ok' => true,

            'day' => [
                'id' => (int) $day->id,
                'date' => $day->production_date,
                'status' => 'closed',
                'closed_at' => $now->toDateTimeString(),
            ],
        ]);
    });
    }

    public function reopenDay(Request $request)
    {
    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    |
    | La raison est facultative pour le MVP.
    | Plus tard, on pourra décider de la rendre obligatoire.
    |
    */

    $validated = $request->validate([
        'site_id' => [
            'required',
            'integer',
            'min:1',
        ],

        'date' => [
            'required',
            'date_format:Y-m-d',
        ],

        'reason' => [
            'nullable',
            'string',
            'max:500',
        ],
    ]);

    $orgId = (int) config('tempo.default_org_id');
    $siteId = (int) $validated['site_id'];
    $date = $validated['date'];
    $reason = $validated['reason'] ?? null;

    /*
    |--------------------------------------------------------------------------
    | Vérification du site
    |--------------------------------------------------------------------------
    */

    $siteExists = DB::table('y_sites')
        ->where('id', $siteId)
        ->where('org_id', $orgId)
        ->exists();

    if (!$siteExists) {
        return response()->json([
            'error' => 'site_not_found',
            'message' =>
                'Le site demandé est introuvable pour cette organisation.',
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Transaction
    |--------------------------------------------------------------------------
    */

    return DB::transaction(function () use (
        $orgId,
        $siteId,
        $date,
        $reason
    ) {

        /*
        |--------------------------------------------------------------------------
        | 1. Recherche et verrouillage de la journée
        |--------------------------------------------------------------------------
        */

        $day = DB::table('y_production_days')
            ->where('org_id', $orgId)
            ->where('site_id', $siteId)
            ->where('production_date', $date)
            ->lockForUpdate()
            ->first();

        if (!$day) {
            return response()->json([
                'error' => 'production_day_not_found',
                'message' =>
                    'La feuille de production demandée n’existe pas.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Seule une journée closed peut être réouverte
        |--------------------------------------------------------------------------
        */

        if ($day->status !== 'closed') {
            return response()->json([
                'error' => 'production_day_not_closed',

                'message' =>
                    'Seule une feuille clôturée peut être réouverte.',

                'status' => $day->status,
            ], 409);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Réouverture
        |--------------------------------------------------------------------------
        |
        | La feuille repasse en in_progress.
        |
        | Les données de production existantes sont conservées.
        |
        | finished_at / finished_by sont remis à NULL car la feuille
        | n'est plus considérée comme terminée.
        |
        | closed_at / closed_by sont également remis à NULL.
        |
        | L'historique conserve néanmoins la trace des anciens statuts.
        |
        */

        $now = now('UTC');

        DB::table('y_production_days')
            ->where('id', $day->id)
            ->where('org_id', $orgId)
            ->where('site_id', $siteId)
            ->update([
                'status' => 'in_progress',

                'finished_at' => null,
                'finished_by' => null,

                'closed_at' => null,
                'closed_by' => null,

                /*
                 * Pas encore d'authentification.
                 */
                'updated_by' => null,
                'updated_at' => $now,
            ]);

        /*
        |--------------------------------------------------------------------------
        | 4. Historique
        |--------------------------------------------------------------------------
        */

        DB::table('y_production_day_status_history')
            ->insert([
                'org_id' => $orgId,
                'site_id' => $siteId,

                'production_day_id' =>
                    $day->id,

                'from_status' =>
                    'closed',

                'to_status' =>
                    'in_progress',

                'reason' =>
                    $reason,

                /*
                 * Plus tard :
                 * utilisateur manager authentifié.
                 */
                'created_by' =>
                    null,

                'created_at' =>
                    $now,
            ]);

        /*
        |--------------------------------------------------------------------------
        | 5. Réponse
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'ok' => true,

            'day' => [
                'id' => (int) $day->id,
                'date' => $day->production_date,
                'status' => 'in_progress',
            ],

            'reason' => $reason,
        ]);
    });
    }
}