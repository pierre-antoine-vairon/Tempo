<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductRecipeController extends Controller
{
    public function recipes(Request $request, int $productId): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $product = $this->findScopedProduct($orgId, $productId);

        if (!$product) {
            return $this->productNotFoundResponse();
        }

        $recipes = DB::table('y_product_recipes as r')
            ->leftJoin('y_product_recipe_lines as l', function ($join) {
                $join->on('l.recipe_id', '=', 'r.id')
                    ->on('l.org_id', '=', 'r.org_id')
                    ->on('l.site_id', '=', 'r.site_id');
            })
            ->where('r.org_id', $orgId)
            ->where('r.site_id', (int) $product->site_id)
            ->where('r.product_id', $productId)
            ->select([
                'r.id',
                'r.effective_from',
                DB::raw('COUNT(l.id) as lines_count'),
            ])
            ->groupBy('r.id', 'r.effective_from')
            ->orderByDesc('r.effective_from')
            ->orderByDesc('r.id')
            ->get()
            ->map(fn ($recipe) => [
                'id' => (int) $recipe->id,
                'effective_from' => $recipe->effective_from,
                'lines_count' => (int) $recipe->lines_count,
            ])
            ->values();

        return response()->json([
            'ok' => true,
            'org_id' => $orgId,
            'site_id' => (int) $product->site_id,
            'product' => $this->productResponse($product),
            'recipes' => $recipes,
        ]);
    }

    public function show(Request $request, int $productId, int $recipeId): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $product = $this->findScopedProduct($orgId, $productId);

        if (!$product) {
            return $this->productNotFoundResponse();
        }

        $recipe = $this->findScopedRecipe($orgId, $product, $recipeId);

        if (!$recipe) {
            return $this->recipeNotFoundResponse();
        }

        return response()->json([
            'ok' => true,
            'recipe' => $this->recipeResponse($recipe, $product),
        ]);
    }

    public function recipeAtDate(Request $request, int $productId): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $product = $this->findScopedProduct($orgId, $productId);

        if (!$product) {
            return $this->productNotFoundResponse();
        }

        $date = $request->query('date');

        if (!is_string($date) || !$this->isStrictDate($date)) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_date',
                'message' => 'La date doit respecter strictement le format YYYY-MM-DD.',
            ], 422);
        }

        $recipe = DB::table('y_product_recipes')
            ->where('org_id', $orgId)
            ->where('site_id', (int) $product->site_id)
            ->where('product_id', $productId)
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'ok' => true,
            'product' => $this->productResponse($product),
            'date' => $date,
            'recipe' => $recipe === null
                ? null
                : $this->recipeResponse($recipe, $product),
        ]);
    }

    public function create(Request $request, int $productId): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $product = $this->findScopedProduct($orgId, $productId);

        if (!$product) {
            return $this->productNotFoundResponse();
        }

        $validated = $request->validate([
            'effective_from' => ['required', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.material_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity_in_reference_unit' => ['required', 'string'],
        ]);

        $effectiveFrom = $validated['effective_from'];

        if (!$this->isStrictDate($effectiveFrom)) {
            return response()->json([
                'ok' => false,
                'error' => 'invalid_date',
                'message' => 'La date doit respecter strictement le format YYYY-MM-DD.',
            ], 422);
        }

        $lines = $this->validateRecipeLines($validated['lines'], $orgId);

        if ($lines instanceof JsonResponse) {
            return $lines;
        }

        try {
            $creation = DB::transaction(function () use (
                $orgId,
                $productId,
                $effectiveFrom,
                $lines
            ) {
                $lockedProduct = $this->findScopedProduct(
                    $orgId,
                    $productId,
                    true
                );

                if (!$lockedProduct) {
                    return [
                        'product' => null,
                        'recipe_id' => null,
                        'latest_effective_from' => null,
                    ];
                }

                $siteId = (int) $lockedProduct->site_id;
                $latestEffectiveFrom = DB::table('y_product_recipes')
                    ->where('org_id', $orgId)
                    ->where('site_id', $siteId)
                    ->where('product_id', $productId)
                    ->max('effective_from');

                if ($latestEffectiveFrom !== null
                    && $effectiveFrom <= $latestEffectiveFrom) {
                    return [
                        'product' => $lockedProduct,
                        'recipe_id' => null,
                        'latest_effective_from' => $latestEffectiveFrom,
                    ];
                }

                $now = now('UTC');
                $recipeId = DB::table('y_product_recipes')->insertGetId([
                    'org_id' => $orgId,
                    'site_id' => $siteId,
                    'product_id' => $productId,
                    'effective_from' => $effectiveFrom,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_by' => null,
                    'updated_at' => $now,
                ]);

                $lineRows = array_map(
                    fn (array $line) => [
                        'org_id' => $orgId,
                        'site_id' => $siteId,
                        'recipe_id' => $recipeId,
                        'material_id' => $line['material_id'],
                        'quantity_in_reference_unit' => $line['quantity_in_reference_unit'],
                        'created_by' => null,
                        'created_at' => $now,
                        'updated_by' => null,
                        'updated_at' => $now,
                    ],
                    $lines
                );

                DB::table('y_product_recipe_lines')->insert($lineRows);

                return [
                    'product' => $lockedProduct,
                    'recipe_id' => (int) $recipeId,
                    'latest_effective_from' => null,
                ];
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateEntry($exception)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'recipe_effective_from_already_exists',
                    'message' => 'Une fiche technique existe déjà pour cette date d’effet.',
                ], 422);
            }

            throw $exception;
        }

        if ($creation['product'] === null) {
            return $this->productNotFoundResponse();
        }

        if ($creation['latest_effective_from'] !== null) {
            return response()->json([
                'ok' => false,
                'error' => 'recipe_effective_from_not_append_only',
                'message' => 'La date d’effet doit être postérieure à la dernière version de la fiche technique.',
                'latest_effective_from' => $creation['latest_effective_from'],
            ], 422);
        }

        $recipe = $this->findScopedRecipe(
            $orgId,
            $creation['product'],
            $creation['recipe_id']
        );

        return response()->json([
            'ok' => true,
            'recipe' => $this->recipeResponse($recipe, $creation['product']),
            'message' => 'Fiche technique créée.',
        ], 201);
    }

    public function correct(
        Request $request,
        int $productId,
        int $recipeId
    ): JsonResponse {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $product = $this->findScopedProduct($orgId, $productId);

        if (!$product) {
            return $this->productNotFoundResponse();
        }

        $validated = $request->validate([
            'correction_reason' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.material_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity_in_reference_unit' => ['required', 'string'],
        ]);

        $correctionReason = trim($validated['correction_reason'] ?? '');

        if ($correctionReason === '') {
            return response()->json([
                'ok' => false,
                'error' => 'correction_reason_empty',
                'message' => 'Le motif de correction est obligatoire.',
            ], 422);
        }

        $lines = $this->validateRecipeLines($validated['lines'], $orgId);

        if ($lines instanceof JsonResponse) {
            return $lines;
        }

        $correction = DB::transaction(function () use (
            $orgId,
            $productId,
            $recipeId,
            $correctionReason,
            $lines
        ) {
            // The product lock serializes corrections with append-only recipe creation.
            $lockedProduct = $this->findScopedProduct($orgId, $productId, true);

            if (!$lockedProduct) {
                return ['status' => 'product_not_found'];
            }

            $recipe = $this->findScopedRecipe(
                $orgId,
                $lockedProduct,
                $recipeId,
                true
            );

            if (!$recipe) {
                return ['status' => 'recipe_not_found'];
            }

            $latestRecipe = DB::table('y_product_recipes')
                ->where('org_id', $orgId)
                ->where('site_id', (int) $lockedProduct->site_id)
                ->where('product_id', $productId)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();

            if ((int) $latestRecipe->id !== $recipeId) {
                return ['status' => 'not_latest'];
            }

            $beforeLines = $this->recipeLineSnapshots($recipe);
            $now = now('UTC');

            DB::table('y_product_recipe_lines')
                ->where('recipe_id', $recipeId)
                ->where('org_id', $orgId)
                ->where('site_id', (int) $lockedProduct->site_id)
                ->delete();

            $lineRows = array_map(
                fn (array $line) => [
                    'org_id' => $orgId,
                    'site_id' => (int) $lockedProduct->site_id,
                    'recipe_id' => $recipeId,
                    'material_id' => $line['material_id'],
                    'quantity_in_reference_unit' => $line['quantity_in_reference_unit'],
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_by' => null,
                    'updated_at' => $now,
                ],
                $lines
            );

            DB::table('y_product_recipe_lines')->insert($lineRows);

            $afterLines = $this->recipeLineSnapshots($recipe);

            DB::table('y_product_recipes')
                ->where('id', $recipeId)
                ->where('org_id', $orgId)
                ->where('site_id', (int) $lockedProduct->site_id)
                ->where('product_id', $productId)
                ->update([
                    'updated_by' => null,
                    'updated_at' => $now,
                ]);

            $correctionId = DB::table('y_product_recipe_corrections')->insertGetId([
                'org_id' => $orgId,
                'site_id' => (int) $lockedProduct->site_id,
                'recipe_id' => $recipeId,
                'correction_reason' => $correctionReason,
                'before_lines_json' => json_encode($beforeLines),
                'after_lines_json' => json_encode($afterLines),
                'corrected_by' => null,
                'corrected_at' => $now,
            ]);

            return [
                'status' => 'corrected',
                'product' => $lockedProduct,
                'recipe_id' => $recipeId,
                'correction' => [
                    'id' => (int) $correctionId,
                    'reason' => $correctionReason,
                    'corrected_at' => $now->toDateTimeString(),
                ],
            ];
        });

        if ($correction['status'] === 'product_not_found') {
            return $this->productNotFoundResponse();
        }

        if ($correction['status'] === 'recipe_not_found') {
            return $this->recipeNotFoundResponse();
        }

        if ($correction['status'] === 'not_latest') {
            return response()->json([
                'ok' => false,
                'error' => 'recipe_correction_not_latest',
                'message' => 'Seule la dernière version de la fiche technique peut être corrigée.',
            ], 409);
        }

        $recipe = $this->findScopedRecipe(
            $orgId,
            $correction['product'],
            $correction['recipe_id']
        );

        return response()->json([
            'ok' => true,
            'recipe' => $this->recipeResponse($recipe, $correction['product']),
            'correction' => $correction['correction'],
            'message' => 'Fiche technique corrigée.',
        ]);
    }

    public function corrections(
        Request $request,
        int $productId,
        int $recipeId
    ): JsonResponse {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $product = $this->findScopedProduct($orgId, $productId);

        if (!$product) {
            return $this->productNotFoundResponse();
        }

        $recipe = $this->findScopedRecipe($orgId, $product, $recipeId);

        if (!$recipe) {
            return $this->recipeNotFoundResponse();
        }

        $corrections = DB::table('y_product_recipe_corrections')
            ->where('org_id', $orgId)
            ->where('site_id', (int) $product->site_id)
            ->where('recipe_id', $recipeId)
            ->orderByDesc('corrected_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($correction) => [
                'id' => (int) $correction->id,
                'reason' => $correction->correction_reason,
                'before_lines' => json_decode(
                    $correction->before_lines_json,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                ),
                'after_lines' => json_decode(
                    $correction->after_lines_json,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                ),
                'corrected_at' => $correction->corrected_at,
            ])
            ->values();

        return response()->json([
            'ok' => true,
            'recipe_id' => $recipeId,
            'corrections' => $corrections,
        ]);
    }

    private function findScopedProduct(
        int $orgId,
        int $productId,
        bool $lockForUpdate = false
    ): ?object {
        $query = DB::table('y_products')
            ->select(['id', 'org_id', 'site_id', 'name', 'is_active'])
            ->where('id', $productId)
            ->where('org_id', $orgId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function findScopedRecipe(
        int $orgId,
        object $product,
        int $recipeId,
        bool $lockForUpdate = false
    ): ?object {
        $query = DB::table('y_product_recipes')
            ->where('id', $recipeId)
            ->where('org_id', $orgId)
            ->where('site_id', (int) $product->site_id)
            ->where('product_id', (int) $product->id);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @param array<int, array{material_id: int, quantity_in_reference_unit: string}> $lines
     * @return array<int, array{material_id: int, quantity_in_reference_unit: string}>|JsonResponse
     */
    private function validateRecipeLines(array $lines, int $orgId): array|JsonResponse
    {
        $validatedLines = [];
        $materialIds = [];

        foreach ($lines as $line) {
            $materialId = (int) $line['material_id'];

            if (array_key_exists($materialId, $materialIds)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'duplicate_material',
                    'message' => 'Une matière ne peut apparaître qu’une seule fois dans une fiche technique.',
                ], 422);
            }

            $quantity = $line['quantity_in_reference_unit'];

            if (!$this->isPositiveRecipeQuantity($quantity)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'invalid_quantity_in_reference_unit',
                    'message' => 'La quantité doit être une chaîne décimale strictement positive, avec au plus huit chiffres entiers et six décimales.',
                ], 422);
            }

            $materialIds[$materialId] = true;
            $validatedLines[] = [
                'material_id' => $materialId,
                'quantity_in_reference_unit' => $quantity,
            ];
        }

        $existingMaterialsCount = DB::table('y_materials')
            ->where('org_id', $orgId)
            ->whereIn('id', array_keys($materialIds))
            ->count();

        if ($existingMaterialsCount !== count($materialIds)) {
            return response()->json([
                'ok' => false,
                'error' => 'material_not_found',
                'message' => 'Une ou plusieurs matières sont introuvables pour cette organisation.',
            ], 422);
        }

        return $validatedLines;
    }

    /**
     * @return array<int, array{material_id: int, quantity_in_reference_unit: string}>
     */
    private function recipeLineSnapshots(object $recipe): array
    {
        $lines = DB::table('y_product_recipe_lines')
            ->where('recipe_id', (int) $recipe->id)
            ->where('org_id', (int) $recipe->org_id)
            ->where('site_id', (int) $recipe->site_id)
            ->select(['material_id', 'quantity_in_reference_unit'])
            ->orderBy('material_id')
            ->get()
            ->map(fn ($line) => [
                'material_id' => (int) $line->material_id,
                'quantity_in_reference_unit' => (string) $line->quantity_in_reference_unit,
            ])
            ->all();

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function recipeResponse(object $recipe, object $product): array
    {
        $lines = DB::table('y_product_recipe_lines as l')
            ->join('y_materials as m', function ($join) {
                $join->on('m.id', '=', 'l.material_id')
                    ->on('m.org_id', '=', 'l.org_id');
            })
            ->join(
                'y_measure_units as u',
                'u.id',
                '=',
                'm.reference_measure_unit_id'
            )
            ->where('l.recipe_id', (int) $recipe->id)
            ->where('l.org_id', (int) $recipe->org_id)
            ->where('l.site_id', (int) $recipe->site_id)
            ->select([
                'l.id as line_id',
                'l.quantity_in_reference_unit',
                'm.id as material_id',
                'm.name as material_name',
                'm.reference_unit_label',
                'u.id as measure_unit_id',
                'u.code as measure_unit_code',
                'u.name as measure_unit_name',
                'u.symbol as measure_unit_symbol',
            ])
            ->orderBy('l.id')
            ->get()
            ->map(fn ($line) => [
                'id' => (int) $line->line_id,
                'material' => [
                    'id' => (int) $line->material_id,
                    'name' => $line->material_name,
                    'reference_unit_label' => $line->reference_unit_label,
                    'reference_measure_unit' => [
                        'id' => (int) $line->measure_unit_id,
                        'code' => $line->measure_unit_code,
                        'name' => $line->measure_unit_name,
                        'symbol' => $line->measure_unit_symbol,
                    ],
                ],
                'quantity_in_reference_unit' => $line->quantity_in_reference_unit,
            ])
            ->values();

        return [
            'id' => (int) $recipe->id,
            'product' => $this->productResponse($product),
            'effective_from' => $recipe->effective_from,
            'lines' => $lines,
        ];
    }

    /**
     * @return array{id: int, name: string}
     */
    private function productResponse(object $product): array
    {
        return [
            'id' => (int) $product->id,
            'name' => $product->name,
        ];
    }

    private function orgId(Request $request): int
    {
        return (int) $request->query(
            'org_id',
            config('tempo.default_org_id')
        );
    }

    private function organizationExists(int $orgId): bool
    {
        return DB::table('y_orgs')
            ->where('id', $orgId)
            ->exists();
    }

    private function organizationNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => 'organization_not_found',
            'message' => 'Organisation introuvable.',
        ], 404);
    }

    private function productNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => 'product_not_found',
            'message' => 'Produit introuvable.',
        ], 404);
    }

    private function recipeNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => 'recipe_not_found',
            'message' => 'Fiche technique introuvable.',
        ], 404);
    }

    private function isStrictDate(string $date): bool
    {
        try {
            $parsedDate = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            return false;
        }

        return $parsedDate !== false && $parsedDate->toDateString() === $date;
    }

    private function isPositiveRecipeQuantity(string $quantity): bool
    {
        if (!preg_match('/^\d{1,8}(?:\.\d{1,6})?$/', $quantity)) {
            return false;
        }

        return str_replace(['0', '.'], '', $quantity) !== '';
    }

    private function isDuplicateEntry(QueryException $exception): bool
    {
        return isset($exception->errorInfo[1])
            && (int) $exception->errorInfo[1] === 1062;
    }
}
