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

    public function openDay(Request $request)
    {
        /*
        |   --------------------------------------------------------------------------
        | Validation
        |   --------------------------------------------------------------------------
        */

        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $orgId = (int) config('tempo.default_org_id');
        $siteId = (int) $validated['site_id'];
        $date = $validated['date'];

        /*
        |   -----------------------------------------------------  ---------------------
        | Vérification du site
        |   -----------------------------------------------------  ---------------------
        |
        | Le site doit appartenir à l'organisation courante.
        |
        */

        $siteExists = DB::table('y_sites')
            ->where('id', $siteId)
            ->where('org_id', $orgId)
            ->exists();

        if (!$siteExists) {
            return response()->json([
                'error' => 'site_not_found',
                'message' => 'Le site demandé est   introuvable pour cette organisation.',
            ], 404);
        }

        /*
        |   -----------------------------------------------------  ---------------------
        | Transaction
        |   -----------------------------------------------------  ---------------------
        */

        return DB::transaction(function () use (
            $orgId,
            $siteId,
            $date
        ) {

        /*
        |--------------------------------------------------------------------------
        | 1. Vérifier si la journée existe déjà
        |--------------------------------------------------------------------------
        |
        | L'ouverture est idempotente :
        | ouvrir deux fois la même date ne crée jamais deux journées.
        |
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
        | 4. Produits actifs du catalogue
        |--------------------------------------------------------------------------
        |
        | On récupère uniquement :
        |
        | - les produits actifs ;
        | - appartenant au bon site / à la bonne organisation ;
        | - dont la catégorie est active ;
        | - dont la famille est active.
        |
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

            /*
             * Produit
             */
            ->where('p.org_id', $orgId)
            ->where('p.site_id', $siteId)
            ->where('p.is_active', 1)

            /*
             * Catégorie
             */
            ->where('c.org_id', $orgId)
            ->where('c.site_id', $siteId)
            ->where('c.is_active', 1)

            /*
             * Famille
             */
            ->where('f.org_id', $orgId)
            ->where('f.site_id', $siteId)
            ->where('f.is_active', 1)

            ->select([
                /*
                 * Produit
                 */
                'p.id as product_id',
                'p.name as product_name',
                'p.conservation',
                'p.display_order as product_order',

                /*
                 * Catégorie
                 */
                'c.id as category_id',
                'c.name as category_name',
                'c.display_order as category_order',

                /*
                 * Famille
                 */
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
        | 5. Date précédente
        |--------------------------------------------------------------------------
        |
        | Pour la V1 :
        |
        | Stock fin de J-1
        |          ↓
        | Stock J-1 de J
        |
        | On recherche donc uniquement la veille calendaire.
        |
        */

        $previousDate = \Illuminate\Support\Carbon::createFromFormat(
            'Y-m-d',
            $date
        )
            ->subDay()
            ->toDateString();

        /*
        |--------------------------------------------------------------------------
        | 6. Récupération des stocks de J-1
        |--------------------------------------------------------------------------
        */

        $productIds = $products
            ->pluck('product_id')
            ->all();

        $previousEntries = collect();

        if (count($productIds) > 0) {
            $previousEntries = DB::table('y_production_entries as e')

                /*
                 * Nouvelle architecture :
                 *
                 * entry
                 *   ↓
                 * produit de journée
                 *   ↓
                 * journée
                 */

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

                /*
                 * On cherche uniquement J-1.
                 */

                ->where(
                    'e.production_date',
                    '=',
                    $previousDate
                )

                ->select([
                    'e.product_id',
                    'e.stock_end',
                    'e.production_day_product_id',

                    /*
                     * Le produit faisait-il réellement partie
                     * de la feuille précédente ?
                     */

                    'dp.is_included as was_included',

                    /*
                     * Statut de la journée précédente.
                     */

                    'd.status as day_status',
                ])

                ->get()

                /*
                 * Une seule entrée possible par produit/date.
                 *
                 * Cela permet ensuite :
                 *
                 * $previousEntries->get($productId)
                 */

                ->keyBy('product_id');
        }

        /*
        |--------------------------------------------------------------------------
        | 7. Création du snapshot de chaque produit
        |--------------------------------------------------------------------------
        */

        foreach ($products as $product) {

            /*
            |--------------------------------------------------------------------------
            | Calcul du Stock J-1
            |--------------------------------------------------------------------------
            */

            $stockPrevious = null;

            /*
             * Conservation J :
             *
             * le produit ne peut jamais conserver un stock
             * provenant de la veille.
             */

            if ($product->conservation !== 'J') {

                $previousEntry = $previousEntries->get(
                    $product->product_id
                );

                if ($previousEntry) {

                    /*
                     * Anciennes données :
                     *
                     * Les anciennes lignes de production n'étaient
                     * pas encore reliées à y_production_day_products.
                     *
                     * On les accepte temporairement pendant
                     * la migration vers le nouveau modèle.
                     */

                    $isLegacyEntry =
                        $previousEntry->production_day_product_id === null;

                    /*
                     * Nouvelle architecture :
                     *
                     * Pour utiliser le stock final précédent :
                     *
                     * - le produit devait être inclus ;
                     * - la feuille devait être terminée ou clôturée.
                     */

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

                    /*
                     * Si la donnée précédente est valable,
                     * son stock final devient le stock J-1.
                     */

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

            /*
            |--------------------------------------------------------------------------
            | 8. Snapshot du produit pour cette journée
            |--------------------------------------------------------------------------
            |
            | Cette photographie protège l'historique.
            |
            | Si demain le produit, sa catégorie ou sa famille
            | sont renommés, cette journée ne changera pas.
            |
            */

            $productionDayProductId = DB::table(
                'y_production_day_products'
            )->insertGetId([
                'org_id' => $orgId,
                'site_id' => $siteId,

                'production_day_id' => $productionDayId,

                /*
                 * Références actuelles
                 */

                'product_id' => $product->product_id,
                'category_id' => $product->category_id,
                'family_id' => $product->family_id,

                /*
                 * Snapshot historique
                 */

                'product_name_snapshot' =>
                    $product->product_name,

                'category_name_snapshot' =>
                    $product->category_name,

                'family_name_snapshot' =>
                    $product->family_name,

                'conservation_snapshot' =>
                    $product->conservation,

                /*
                 * Ordre d'affichage historique
                 */

                'product_order_snapshot' =>
                    $product->product_order,

                'category_order_snapshot' =>
                    $product->category_order,

                'family_order_snapshot' =>
                    $product->family_order,

                /*
                 * Le produit est présent dans cette feuille.
                 */

                'is_included' => 1,

                'excluded_at' => null,
                'excluded_by' => null,

                /*
                 * Audit
                 */

                'created_by' => null,
                'created_at' => $now,

                'updated_by' => null,
                'updated_at' => $now,
            ]);

            /*
            |--------------------------------------------------------------------------
            | 9. Création de la ligne de saisie
            |--------------------------------------------------------------------------
            |
            | NULL = pas encore renseigné.
            |
            | stock_previous peut déjà être prérempli automatiquement
            | depuis le stock_end de J-1.
            |
            */

            DB::table('y_production_entries')
                ->insert([
                    'org_id' => $orgId,
                    'site_id' => $siteId,

                    'product_id' => $product->product_id,

                    'production_day_product_id' =>
                        $productionDayProductId,

                    'production_date' => $date,

                    /*
                     * Prérempli automatiquement si applicable.
                     */

                    'stock_previous' => $stockPrevious,

                    /*
                     * Saisie de la journée.
                     */

                    'production' => null,
                    'reproduction' => null,
                    'losses' => null,
                    'sales' => null,
                    'stock_end' => null,

                    /*
                     * Audit
                     */

                    'created_by' => null,
                    'created_at' => $now,

                    'updated_by' => null,
                    'updated_at' => $now,
                ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 10. Réponse
        |--------------------------------------------------------------------------
        */

        $day = DB::table('y_production_days')
            ->where('id', $productionDayId)
            ->first();

        return response()->json([
            'created' => true,
            'day' => $day,
            'products_count' => $products->count(),
        ], 201);
    });
    }
}