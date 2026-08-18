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
}