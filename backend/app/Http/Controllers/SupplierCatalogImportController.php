<?php

namespace App\Http\Controllers;

use App\Services\Purchasing\CatalogParsingProfileRegistry;
use App\Services\Purchasing\PdfTextExtractor;
use App\Services\Purchasing\SupplierCatalogParser;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class SupplierCatalogImportController extends Controller
{
    public function previewPdf(
        Request $request,
        int $supplierId,
        PdfTextExtractor $extractor,
        CatalogParsingProfileRegistry $profiles,
        SupplierCatalogParser $parser
    ): JsonResponse {
        $orgId = (int) $request->query(
            'org_id',
            config('tempo.default_org_id')
        );

        if (! DB::table('y_orgs')->where('id', $orgId)->exists()) {
            return response()->json([
                'error' => 'organization_not_found',
                'message' => 'Organisation introuvable.',
            ], 404);
        }

        $supplier = DB::table('y_suppliers')
            ->select(['id', 'name'])
            ->where('id', $supplierId)
            ->where('org_id', $orgId)
            ->first();

        if (! $supplier) {
            return response()->json([
                'error' => 'supplier_not_found',
                'message' => 'Fournisseur introuvable.',
            ], 404);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
            'profile_id' => ['nullable', 'string', 'max:100'],
            'catalog_name' => ['required', 'string', 'max:160'],
        ]);

        $file = $validated['file'];
        try {
            $profile = $profiles->resolve($validated['profile_id'] ?? null);
        } catch (InvalidArgumentException) {
            return response()->json([
                'ok' => false,
                'error' => 'catalog_parsing_profile_not_found',
                'message' => 'Le profil de lecture du catalogue est introuvable.',
            ], 422);
        }

        try {
            $pages = $extractor->extract($file->getRealPath());
        } catch (RuntimeException) {
            return response()->json([
                'ok' => false,
                'error' => 'pdf_extraction_failed',
                'message' => 'Le PDF n’a pas pu être lu.',
            ], 422);
        }

        try {
            $parsed = $parser->parse($pages, $file->getClientOriginalName(), $profile);
        } catch (RuntimeException) {
            return response()->json([
                'ok' => false,
                'error' => 'catalog_parsing_profile_invalid',
                'message' => 'Le profil de lecture du catalogue est invalide.',
            ], 422);
        }

        $payload = [
            'schema_version' => 1,
            'catalog' => [
                'name' => $validated['catalog_name'],
                'effective_from' => $parsed['document']['effective_from'] ?? null,
                'source_filename' => $parsed['document']['source_filename']
                    ?? $file->getClientOriginalName(),
            ],
            'categories' => $parsed['categories'] ?? [],
            'items' => $parsed['items'] ?? [],
        ];
        $analysis = $this->analyzeImport($payload, $orgId, $supplierId);

        return response()->json([
            'ok' => true,
            'schema_version' => 1,
            'supplier' => $this->supplierResponse($supplier),
            'source_document' => $parsed['document'] ?? [],
            'parser_summary' => $parsed['parser_summary'] ?? [],
            'parser_warnings' => $parsed['parser_warnings'] ?? [],
            'unparsed_rows' => $parsed['unparsed_rows'] ?? [],
            ...$analysis,
        ]);
    }

    public function preview(Request $request, int $supplierId): JsonResponse
    {
        $orgId = (int) $request->query(
            'org_id',
            config('tempo.default_org_id')
        );

        if (! DB::table('y_orgs')->where('id', $orgId)->exists()) {
            return response()->json([
                'error' => 'organization_not_found',
                'message' => 'Organisation introuvable.',
            ], 404);
        }

        $supplier = DB::table('y_suppliers')
            ->select(['id', 'name'])
            ->where('id', $supplierId)
            ->where('org_id', $orgId)
            ->first();

        if (! $supplier) {
            return response()->json([
                'error' => 'supplier_not_found',
                'message' => 'Fournisseur introuvable.',
            ], 404);
        }

        $analysis = $this->analyzeImport($request->json()->all(), $orgId, $supplierId);

        return response()->json([
            'ok' => true,
            'schema_version' => 1,
            'supplier' => $this->supplierResponse($supplier),
            ...$analysis,
        ]);
    }

    public function commit(Request $request, int $supplierId): JsonResponse
    {
        $orgId = (int) $request->query('org_id', config('tempo.default_org_id'));

        if (! DB::table('y_orgs')->where('id', $orgId)->exists()) {
            return response()->json([
                'error' => 'organization_not_found',
                'message' => 'Organisation introuvable.',
            ], 404);
        }

        $supplier = DB::table('y_suppliers')
            ->select(['id', 'name'])
            ->where('id', $supplierId)
            ->where('org_id', $orgId)
            ->first();

        if (! $supplier) {
            return response()->json([
                'error' => 'supplier_not_found',
                'message' => 'Fournisseur introuvable.',
            ], 404);
        }

        $payload = $request->json()->all();
        $confirmWarnings = ($payload['confirm_warnings'] ?? false) === true;
        $analysis = $this->analyzeImport($payload, $orgId, $supplierId);

        if ($this->analysisHasErrors($analysis)) {
            return $this->analysisFailure('catalog_import_invalid', 'Le catalogue contient des erreurs bloquantes.', $analysis, $supplier);
        }

        if (! $confirmWarnings && $this->analysisHasWarnings($analysis)) {
            return $this->analysisFailure('warnings_confirmation_required', 'Les warnings doivent être confirmés avant l’import.', $analysis, $supplier);
        }

        try {
            $result = DB::transaction(function () use (
                $payload,
                $orgId,
                $supplierId,
                $supplier,
                $confirmWarnings
            ) {
                $analysis = $this->analyzeImport($payload, $orgId, $supplierId);
                if ($this->analysisHasErrors($analysis)) {
                    return ['blocked' => true, 'analysis' => $analysis, 'supplier' => $supplier];
                }
                if (! $confirmWarnings && $this->analysisHasWarnings($analysis)) {
                    return ['warnings_blocked' => true, 'analysis' => $analysis, 'supplier' => $supplier];
                }

                return $this->persistImport($analysis, $orgId, $supplierId, $supplier);
            });
        } catch (QueryException $exception) {
            if (isset($exception->errorInfo[1]) && (int) $exception->errorInfo[1] === 1062) {
                return response()->json([
                    'error' => 'catalog_import_concurrent_change',
                    'message' => 'Les données ont changé pendant l’import. Relancez la prévisualisation.',
                ], 409);
            }

            throw $exception;
        }

        if (isset($result['blocked'])) {
            return $this->analysisFailure('catalog_import_invalid', 'Le catalogue contient des erreurs bloquantes.', $result['analysis'], $supplier);
        }
        if (isset($result['warnings_blocked'])) {
            return $this->analysisFailure('warnings_confirmation_required', 'Les warnings doivent être confirmés avant l’import.', $result['analysis'], $supplier);
        }

        return response()->json($result, 201);
    }

    /** @return array<string, mixed> */
    private function analyzeImport(array $payload, int $orgId, int $supplierId): array
    {
        $errors = [];

        if (($payload['schema_version'] ?? null) !== 1) {
            $errors[] = $this->issue(
                'schema_version_invalid',
                'La version de schéma doit être 1.',
                'schema_version'
            );
        }

        $catalog = $this->previewCatalog(
            is_array($payload['catalog'] ?? null) ? $payload['catalog'] : [],
            $orgId,
            $supplierId
        );
        $errors = [...$errors, ...$catalog['errors']];

        $rawCategories = $payload['categories'] ?? [];
        if (! is_array($rawCategories)) {
            $errors[] = $this->issue(
                'categories_invalid',
                'Les catégories doivent être une liste.',
                'categories'
            );
            $rawCategories = [];
        }

        $existingCategories = $this->existingCategories($orgId, $supplierId);
        $categories = $this->previewCategories($rawCategories, $existingCategories);
        $categories = $this->resolveCategoryParents(
            $categories,
            $existingCategories
        );

        $rawItems = $payload['items'] ?? [];
        if (! is_array($rawItems)) {
            $errors[] = $this->issue(
                'items_invalid',
                'Les articles doivent être une liste.',
                'items'
            );
            $rawItems = [];
        }

        $items = $this->previewItems(
            $rawItems,
            $categories,
            $existingCategories,
            $catalog['effective_from'],
            $orgId,
            $supplierId
        );

        $categorySummary = collect($categories);
        $itemSummary = collect($items);

        return [
            'catalog' => $catalog,
            'categories' => $categories,
            'items' => $items,
            'summary' => [
                'categories_to_create' => $categorySummary
                    ->where('operation', 'create_category')->count(),
                'existing_categories' => $categorySummary
                    ->where('operation', 'use_existing_category')->count(),
                'items_to_create' => $itemSummary
                    ->where('operation', 'create_item')->count(),
                'existing_items' => $itemSummary
                    ->where('operation', 'use_existing_item')->count(),
                'catalog_operation' => $catalog['operation'],
                'prices_to_create' => $itemSummary
                    ->where('price_operation', 'create_price')->count(),
                'existing_prices' => $itemSummary
                    ->where('price_operation', 'use_existing_price')->count(),
                'price_conflicts' => $itemSummary
                    ->filter(fn (array $item) => $this->hasIssue(
                        $item['errors'],
                        'supplier_item_price_conflict'
                    ))
                    ->count(),
                'already_known_items' => $itemSummary
                    ->filter(fn (array $item) => $item['operation'] === 'use_existing_item'
                        && $item['price_operation'] === 'use_existing_price')
                    ->count(),
                'valid_items' => $itemSummary->where('status', 'valid')->count(),
                'warning_items' => $itemSummary->where('status', 'warning')->count(),
                'invalid_items' => $itemSummary->where('status', 'invalid')->count(),
                'warning_count' => count($catalog['warnings'])
                    + $categorySummary->sum(fn (array $category) => count($category['warnings']))
                    + $itemSummary->sum(fn (array $item) => count($item['warnings'])),
                'error_count' => count($errors)
                    + $categorySummary->sum(fn (array $category) => count($category['errors']))
                    + $itemSummary->sum(fn (array $item) => count($item['errors'])),
            ],
            'warnings' => [],
            'errors' => $errors,
        ];
    }

    /** @param array<string, mixed> $analysis */
    private function persistImport(
        array $analysis,
        int $orgId,
        int $supplierId,
        object $supplier
    ): array {
        $now = now('UTC');
        $catalog = $analysis['catalog'];
        $catalogId = $catalog['resolved_catalog_id'];
        $catalogsCreated = 0;

        if ($catalog['operation'] === 'create_catalog') {
            $catalogId = DB::table('y_supplier_catalogs')->insertGetId([
                'org_id' => $orgId,
                'supplier_id' => $supplierId,
                'name' => $catalog['name'],
                'effective_from' => $catalog['effective_from'],
                'source_filename' => $catalog['source_filename'],
                'created_by' => null,
                'created_at' => $now,
                'updated_by' => null,
                'updated_at' => $now,
            ]);
            $catalogsCreated = 1;
        }

        $categoryIds = $this->existingCategories($orgId, $supplierId);
        $pendingCategories = [];
        $categoryMappings = [];
        $categoriesCreated = 0;
        $categoriesReused = 0;

        foreach ($analysis['categories'] as $category) {
            $pathKey = $this->key($category['source_path']);
            if ($category['operation'] === 'use_existing_category') {
                $categoryIds[$pathKey] = $category['resolved_category_id'];
                $categoriesReused++;
                $categoryMappings[] = [
                    'client_key' => $category['client_key'],
                    'id' => $category['resolved_category_id'],
                    'operation' => 'use_existing_category',
                ];
            } else {
                $pendingCategories[$pathKey] = $category;
            }
        }

        while ($pendingCategories !== []) {
            $createdInPass = false;
            foreach ($pendingCategories as $pathKey => $category) {
                $parentPath = $category['parent_source_path'];
                $parentId = $parentPath === null
                    ? null
                    : ($categoryIds[$this->key($parentPath)] ?? null);

                if ($parentPath !== null && $parentId === null) {
                    continue;
                }

                $categoryId = DB::table('y_supplier_categories')->insertGetId([
                    'org_id' => $orgId,
                    'supplier_id' => $supplierId,
                    'parent_id' => $parentId,
                    'name' => $category['name'],
                    'source_path' => $category['source_path'],
                    'is_active' => 1,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_by' => null,
                    'updated_at' => $now,
                ]);
                $categoryIds[$pathKey] = (int) $categoryId;
                $categoryMappings[] = [
                    'client_key' => $category['client_key'],
                    'id' => (int) $categoryId,
                    'operation' => 'create_category',
                ];
                unset($pendingCategories[$pathKey]);
                $categoriesCreated++;
                $createdInPass = true;
            }

            if (! $createdInPass) {
                throw new \LogicException('Les catégories de l’import ne peuvent pas être résolues.');
            }
        }

        $itemsCreated = 0;
        $itemsReused = 0;
        $pricesCreated = 0;
        $pricesReused = 0;
        $itemMappings = [];

        foreach ($analysis['items'] as $item) {
            $itemId = $item['resolved_supplier_item_id'];
            if ($item['operation'] === 'create_item') {
                $categoryId = $item['category_source_path'] === null
                    ? null
                    : $categoryIds[$this->key($item['category_source_path'])];
                $itemId = DB::table('y_supplier_items')->insertGetId([
                    'org_id' => $orgId,
                    'supplier_id' => $supplierId,
                    'material_id' => null,
                    'supplier_category_id' => $categoryId,
                    'name' => $item['name'],
                    'supplier_sku' => $item['supplier_sku'],
                    'brand' => $item['brand'],
                    'purchase_unit_label' => $item['purchase_unit_label'],
                    'pack_label' => $item['pack_label'],
                    'quantity_in_reference_unit' => null,
                    'is_active' => 1,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_by' => null,
                    'updated_at' => $now,
                ]);
                $itemsCreated++;
            } else {
                $itemsReused++;
            }

            $priceId = $item['resolved_supplier_item_price_id'];
            if ($item['price_operation'] === 'create_price') {
                $priceId = DB::table('y_supplier_item_prices')->insertGetId([
                    'org_id' => $orgId,
                    'supplier_item_id' => $itemId,
                    'site_id' => null,
                    'supplier_catalog_id' => $catalogId,
                    'price_excl_tax' => $item['price_excl_tax'],
                    'currency_code' => $item['currency_code'],
                    'effective_from' => $catalog['effective_from'],
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_by' => null,
                    'updated_at' => $now,
                ]);
                $pricesCreated++;
            } else {
                $pricesReused++;
            }

            $itemMappings[] = [
                'client_key' => $item['client_key'],
                'id' => (int) $itemId,
                'operation' => $item['operation'],
                'price_id' => $priceId === null ? null : (int) $priceId,
                'price_operation' => $item['price_operation'],
            ];
        }

        return [
            'ok' => true,
            'supplier' => $this->supplierResponse($supplier),
            'catalog' => [
                'id' => (int) $catalogId,
                'operation' => $catalog['operation'],
            ],
            'summary' => [
                'catalogs_created' => $catalogsCreated,
                'categories_created' => $categoriesCreated,
                'categories_reused' => $categoriesReused,
                'items_created' => $itemsCreated,
                'items_reused' => $itemsReused,
                'prices_created' => $pricesCreated,
                'prices_reused' => $pricesReused,
            ],
            'categories' => $categoryMappings,
            'items' => $itemMappings,
            'message' => 'Catalogue fournisseur importé.',
        ];
    }

    /** @param array<string, mixed> $analysis */
    private function analysisHasErrors(array $analysis): bool
    {
        return $analysis['errors'] !== [] || $analysis['catalog']['status'] === 'invalid'
            || collect($analysis['categories'])->contains(
                fn (array $category) => $category['status'] === 'invalid'
            ) || collect($analysis['items'])->contains(
                fn (array $item) => $item['status'] === 'invalid'
            );
    }

    /** @param array<string, mixed> $analysis */
    private function analysisHasWarnings(array $analysis): bool
    {
        return $analysis['warnings'] !== [] || $analysis['catalog']['warnings'] !== []
            || collect($analysis['categories'])->contains(
                fn (array $category) => $category['warnings'] !== []
            ) || collect($analysis['items'])->contains(
                fn (array $item) => $item['warnings'] !== []
            );
    }

    /** @param array<string, mixed> $analysis */
    private function analysisFailure(
        string $error,
        string $message,
        array $analysis,
        object $supplier
    ): JsonResponse {
        return response()->json([
            'ok' => false,
            'error' => $error,
            'message' => $message,
            'supplier' => $this->supplierResponse($supplier),
            'analysis' => $analysis,
        ], 422);
    }

    /** @return array{id: int, name: string} */
    private function supplierResponse(object $supplier): array
    {
        return ['id' => (int) $supplier->id, 'name' => $supplier->name];
    }

    /** @return array<string, mixed> */
    private function previewCatalog(array $input, int $orgId, int $supplierId): array
    {
        $errors = [];
        $name = $this->requiredString($input['name'] ?? null, 160, 'name', $errors);
        $effectiveFrom = $this->requiredDate(
            $input['effective_from'] ?? null,
            'effective_from',
            $errors
        );
        $sourceFilename = $this->nullableString(
            $input['source_filename'] ?? null,
            255,
            'source_filename',
            $errors
        );

        $existingCatalog = null;
        if ($name !== null && $effectiveFrom !== null) {
            $existingCatalog = DB::table('y_supplier_catalogs')
                ->select('id')
                ->where('org_id', $orgId)
                ->where('supplier_id', $supplierId)
                ->where('name', $name)
                ->where('effective_from', $effectiveFrom)
                ->first();
        }

        return [
            'name' => $name,
            'effective_from' => $effectiveFrom,
            'source_filename' => $sourceFilename,
            'status' => $this->status($errors, []),
            'operation' => $existingCatalog === null
                ? 'create_catalog'
                : 'use_existing_catalog',
            'resolved_catalog_id' => $existingCatalog === null
                ? null
                : (int) $existingCatalog->id,
            'warnings' => [],
            'errors' => $errors,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function previewCategories(array $input, array $existingCategories): array
    {
        $categories = [];
        $clientKeys = [];
        $sourcePaths = [];

        foreach ($input as $index => $row) {
            $errors = [];
            $row = is_array($row) ? $row : [];
            if (! is_array($input[$index])) {
                $errors[] = $this->issue('category_invalid', 'La catégorie doit être un objet.');
            }

            $clientKey = $this->requiredString(
                $row['client_key'] ?? null,
                null,
                'client_key',
                $errors
            );
            $name = $this->requiredString($row['name'] ?? null, 160, 'name', $errors);
            $sourcePath = $this->requiredString(
                $row['source_path'] ?? null,
                500,
                'source_path',
                $errors
            );
            $parentSourcePath = $this->nullableString(
                $row['parent_source_path'] ?? null,
                500,
                'parent_source_path',
                $errors
            );

            $categories[] = [
                'client_key' => $clientKey,
                'name' => $name,
                'source_path' => $sourcePath,
                'parent_source_path' => $parentSourcePath,
                'status' => 'valid',
                'operation' => 'skip',
                'resolved_category_id' => null,
                'warnings' => [],
                'errors' => $errors,
            ];
        }

        foreach ($categories as $index => &$category) {
            if ($category['client_key'] !== null) {
                if (isset($clientKeys[$category['client_key']])) {
                    $category['errors'][] = $this->issue(
                        'duplicate_category_client_key_in_import',
                        'La clé client de catégorie est dupliquée.',
                        'client_key'
                    );
                    $categories[$clientKeys[$category['client_key']]]['errors'][] = $this->issue(
                        'duplicate_category_client_key_in_import',
                        'La clé client de catégorie est dupliquée.',
                        'client_key'
                    );
                } else {
                    $clientKeys[$category['client_key']] = $index;
                }
            }

            if ($category['source_path'] !== null) {
                $sourcePathKey = $this->key($category['source_path']);
                if (isset($sourcePaths[$sourcePathKey])) {
                    $category['errors'][] = $this->issue(
                        'duplicate_category_source_path_in_import',
                        'Le chemin source de catégorie est dupliqué.',
                        'source_path'
                    );
                    $categories[$sourcePaths[$sourcePathKey]]['errors'][] = $this->issue(
                        'duplicate_category_source_path_in_import',
                        'Le chemin source de catégorie est dupliqué.',
                        'source_path'
                    );
                } else {
                    $sourcePaths[$sourcePathKey] = $index;
                }
            }
        }
        unset($category);

        foreach ($categories as &$category) {
            if ($category['source_path'] !== null && $category['errors'] === []) {
                $existingCategoryId = $existingCategories[
                    $this->key($category['source_path'])
                ] ?? null;

                if ($existingCategoryId !== null) {
                    $category['operation'] = 'use_existing_category';
                    $category['resolved_category_id'] = $existingCategoryId;
                } else {
                    $category['operation'] = 'create_category';
                }
            }
        }
        unset($category);

        return $categories;
    }

    /** @return array<string, int> */
    private function existingCategories(int $orgId, int $supplierId): array
    {
        return DB::table('y_supplier_categories')
            ->select(['id', 'source_path'])
            ->where('org_id', $orgId)
            ->where('supplier_id', $supplierId)
            ->get()
            ->mapWithKeys(fn ($category) => [
                $this->key($category->source_path) => (int) $category->id,
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     * @param  array<string, int>  $byPath
     * @return array<int, string>
     */
    private function categoryCyclePaths(array $categories, array $byPath): array
    {
        $parents = [];
        foreach ($byPath as $sourcePath => $index) {
            $category = $categories[$index];
            $parentPath = $category['parent_source_path'];
            if ($category['errors'] === [] && $parentPath !== null
                && isset($byPath[$this->key($parentPath)])) {
                $parents[$sourcePath] = $this->key($parentPath);
            }
        }

        $cycles = [];
        foreach (array_keys($parents) as $startPath) {
            $positions = [];
            $path = [];
            $currentPath = $startPath;

            while (isset($parents[$currentPath])) {
                if (isset($positions[$currentPath])) {
                    foreach (array_slice($path, $positions[$currentPath]) as $cyclePath) {
                        $cycles[$cyclePath] = true;
                    }
                    break;
                }

                $positions[$currentPath] = count($path);
                $path[] = $currentPath;
                $currentPath = $parents[$currentPath];
            }
        }

        return array_keys($cycles);
    }

    /** @return array<int, array<string, mixed>> */
    private function resolveCategoryParents(
        array $categories,
        array $existingCategories
    ): array {
        $byPath = [];
        foreach ($categories as $index => $category) {
            if ($category['source_path'] !== null && $category['errors'] === []) {
                $byPath[$this->key($category['source_path'])] = $index;
            }
        }

        foreach ($categories as &$category) {
            $parentPath = $category['parent_source_path'];
            if ($parentPath === null || $category['errors'] !== []) {
                continue;
            }

            if ($this->key($parentPath) === $this->key($category['source_path'])) {
                $category['errors'][] = $this->issue(
                    'supplier_category_parent_invalid',
                    'Une catégorie ne peut pas être son propre parent.',
                    'parent_source_path'
                );

                continue;
            }

            if (isset($byPath[$this->key($parentPath)])) {
                continue;
            }

            if (! isset($existingCategories[$this->key($parentPath)])) {
                $category['errors'][] = $this->issue(
                    'supplier_category_parent_invalid',
                    'La catégorie parent est introuvable pour ce fournisseur.',
                    'parent_source_path'
                );
            }
        }
        unset($category);

        foreach ($this->categoryCyclePaths($categories, $byPath) as $path) {
            $category = &$categories[$byPath[$path]];
            $category['errors'][] = $this->issue(
                'supplier_category_parent_cycle',
                'Les catégories parentes ne peuvent pas former un cycle.',
                'parent_source_path'
            );
            unset($category);
        }

        foreach ($categories as &$category) {
            $category['status'] = $this->status(
                $category['errors'],
                $category['warnings']
            );
            if ($category['status'] === 'invalid') {
                $category['operation'] = 'skip';
                $category['resolved_category_id'] = null;
            }
        }
        unset($category);

        do {
            $changed = false;

            foreach ($categories as &$category) {
                $parentPath = $category['parent_source_path'];
                if ($category['status'] === 'invalid' || $parentPath === null
                    || ! isset($byPath[$this->key($parentPath)])) {
                    continue;
                }

                $parent = $categories[$byPath[$this->key($parentPath)]];
                if ($parent['status'] !== 'invalid') {
                    continue;
                }

                $category['errors'][] = $this->issue(
                    'supplier_category_parent_invalid',
                    'La catégorie parent présente dans l’import est invalide.',
                    'parent_source_path'
                );
                $category['status'] = 'invalid';
                $category['operation'] = 'skip';
                $category['resolved_category_id'] = null;
                $changed = true;
            }
            unset($category);
        } while ($changed);

        return $categories;
    }

    /** @return array<int, array<string, mixed>> */
    private function previewItems(
        array $input,
        array $categories,
        array $existingCategories,
        ?string $effectiveFrom,
        int $orgId,
        int $supplierId
    ): array {
        $items = [];
        $clientKeys = [];
        $skus = [];
        $validCategoryPaths = [];

        foreach ($categories as $category) {
            if ($category['status'] !== 'invalid' && $category['source_path'] !== null) {
                $validCategoryPaths[$this->key($category['source_path'])] = true;
            }
        }

        foreach ($input as $index => $row) {
            $errors = [];
            $warnings = [];
            $row = is_array($row) ? $row : [];
            if (! is_array($input[$index])) {
                $errors[] = $this->issue('item_invalid', 'L’article doit être un objet.');
            }

            $clientKey = $this->requiredString(
                $row['client_key'] ?? null,
                null,
                'client_key',
                $errors
            );
            $supplierSku = $this->requiredString(
                $row['supplier_sku'] ?? null,
                100,
                'supplier_sku',
                $errors
            );
            $name = $this->requiredString($row['name'] ?? null, 160, 'name', $errors);
            $brand = $this->nullableString($row['brand'] ?? null, 160, 'brand', $errors);
            $purchaseUnitLabel = $this->nullableString(
                $row['purchase_unit_label'] ?? null,
                50,
                'purchase_unit_label',
                $errors
            );
            $packLabel = $this->nullableString(
                $row['pack_label'] ?? null,
                160,
                'pack_label',
                $errors
            );
            $categoryPath = $this->nullableString(
                $row['category_source_path'] ?? null,
                500,
                'category_source_path',
                $errors
            );
            $price = $this->price($row['price_excl_tax'] ?? null, $errors);
            $currencyCode = $this->currency(
                $row['currency_code'] ?? null,
                $errors
            );

            if ($price !== null && preg_match('/^0+(?:\.0+)?$/', $price)) {
                $warnings[] = $this->issue(
                    'price_is_zero',
                    'Le prix HT est égal à zéro et devra être confirmé lors de l’import.',
                    'price_excl_tax'
                );
            }

            $source = is_array($row['source'] ?? null) ? $row['source'] : [];
            $items[] = [
                'client_key' => $clientKey,
                'source' => [
                    'row' => $source['row'] ?? null,
                    'page' => $source['page'] ?? null,
                    'sheet' => $source['sheet'] ?? null,
                    'raw_label' => $source['raw_label'] ?? null,
                ],
                'category_source_path' => $categoryPath,
                'supplier_sku' => $supplierSku,
                'name' => $name,
                'brand' => $brand,
                'purchase_unit_label' => $purchaseUnitLabel,
                'pack_label' => $packLabel,
                'price_excl_tax' => $price,
                'currency_code' => $currencyCode,
                'status' => 'valid',
                'operation' => 'skip',
                'resolved_supplier_item_id' => null,
                'price_operation' => 'skip',
                'resolved_supplier_item_price_id' => null,
                'warnings' => $warnings,
                'errors' => $errors,
            ];
        }

        foreach ($items as $index => &$item) {
            if ($item['client_key'] !== null) {
                if (isset($clientKeys[$item['client_key']])) {
                    $this->markDuplicate(
                        $items,
                        $index,
                        $clientKeys[$item['client_key']],
                        'duplicate_item_client_key_in_import',
                        'La clé client d’article est dupliquée.',
                        'client_key'
                    );
                } else {
                    $clientKeys[$item['client_key']] = $index;
                }
            }

            if ($item['supplier_sku'] !== null) {
                $skuKey = $this->key($item['supplier_sku']);
                if (isset($skus[$skuKey])) {
                    $this->markDuplicate(
                        $items,
                        $index,
                        $skus[$skuKey],
                        'duplicate_supplier_sku_in_import',
                        'La référence fournisseur est dupliquée dans l’import.',
                        'supplier_sku'
                    );
                } else {
                    $skus[$skuKey] = $index;
                }
            }
        }
        unset($item);

        $existingItems = $this->existingItems(
            $items,
            $orgId,
            $supplierId
        );
        $existingPrices = $this->existingOrganizationWidePrices(
            $existingItems,
            $effectiveFrom,
            $orgId
        );

        foreach ($items as &$item) {
            if ($item['category_source_path'] !== null
                && ! isset($validCategoryPaths[$this->key($item['category_source_path'])])) {
                if (! isset($existingCategories[$this->key($item['category_source_path'])])) {
                    $item['errors'][] = $this->issue(
                        'supplier_category_invalid',
                        'La catégorie de l’article est introuvable pour ce fournisseur.',
                        'category_source_path'
                    );
                }
            }

            if ($item['supplier_sku'] !== null && $item['errors'] === []) {
                $existing = $existingItems[$this->key($item['supplier_sku'])] ?? null;

                if ($existing) {
                    $item['operation'] = 'use_existing_item';
                    $item['resolved_supplier_item_id'] = (int) $existing->id;
                    $this->metadataWarnings($item, $existing);

                    $existingPrice = $existingPrices[(int) $existing->id] ?? null;
                    if ($existingPrice === null) {
                        $item['price_operation'] = 'create_price';
                    } elseif ($this->decimalKey($existingPrice->price_excl_tax)
                        === $this->decimalKey($item['price_excl_tax'])
                        && strtoupper($existingPrice->currency_code) === $item['currency_code']) {
                        $item['price_operation'] = 'use_existing_price';
                        $item['resolved_supplier_item_price_id'] = (int) $existingPrice->id;
                    } else {
                        $item['price_operation'] = 'skip';
                        $item['resolved_supplier_item_price_id'] = (int) $existingPrice->id;
                        $item['errors'][] = $this->issue(
                            'supplier_item_price_conflict',
                            'Un tarif organisation existe déjà pour cet article et cette date, avec un montant ou une devise différente.',
                            null,
                            [
                                'existing_price_excl_tax' => $existingPrice->price_excl_tax,
                                'imported_price_excl_tax' => $item['price_excl_tax'],
                                'existing_currency_code' => $existingPrice->currency_code,
                                'imported_currency_code' => $item['currency_code'],
                            ]
                        );
                    }
                } else {
                    $item['operation'] = 'create_item';
                    $item['price_operation'] = 'create_price';
                }
            }

            $item['status'] = $this->status($item['errors'], $item['warnings']);
            if ($item['status'] === 'invalid') {
                $item['operation'] = 'skip';
                $item['resolved_supplier_item_id'] = null;
            }
        }
        unset($item);

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, object>
     */
    private function existingItems(array $items, int $orgId, int $supplierId): array
    {
        $skus = collect($items)
            ->pluck('supplier_sku')
            ->filter()
            ->unique(fn (string $sku) => $this->key($sku))
            ->values()
            ->all();

        if ($skus === []) {
            return [];
        }

        return DB::table('y_supplier_items')
            ->select(['id', 'supplier_sku', 'name', 'brand', 'purchase_unit_label', 'pack_label'])
            ->where('org_id', $orgId)
            ->where('supplier_id', $supplierId)
            ->whereIn('supplier_sku', $skus)
            ->get()
            ->mapWithKeys(fn ($item) => [$this->key($item->supplier_sku) => $item])
            ->all();
    }

    /**
     * @param  array<string, object>  $existingItems
     * @return array<int, object>
     */
    private function existingOrganizationWidePrices(
        array $existingItems,
        ?string $effectiveFrom,
        int $orgId
    ): array {
        if ($effectiveFrom === null || $existingItems === []) {
            return [];
        }

        return DB::table('y_supplier_item_prices')
            ->select(['id', 'supplier_item_id', 'price_excl_tax', 'currency_code'])
            ->where('org_id', $orgId)
            ->whereIn('supplier_item_id', array_map(
                fn (object $item) => (int) $item->id,
                $existingItems
            ))
            ->whereNull('site_id')
            ->where('effective_from', $effectiveFrom)
            ->get()
            ->mapWithKeys(fn ($price) => [(int) $price->supplier_item_id => $price])
            ->all();
    }

    /** @param array<int, array<string, mixed>> $items */
    private function markDuplicate(
        array &$items,
        int $index,
        int $originalIndex,
        string $code,
        string $message,
        string $field
    ): void {
        $issue = $this->issue($code, $message, $field);
        $items[$index]['errors'][] = $issue;
        $items[$originalIndex]['errors'][] = $issue;
    }

    /** @param array<string, mixed> $item */
    private function metadataWarnings(array &$item, object $existing): void
    {
        foreach ([
            'name' => $existing->name,
            'brand' => $existing->brand,
            'purchase_unit_label' => $existing->purchase_unit_label,
            'pack_label' => $existing->pack_label,
        ] as $field => $existingValue) {
            $existingValue = $this->normalizedNullableString($existingValue);
            if ($item[$field] !== $existingValue) {
                $item['warnings'][] = [
                    'code' => 'supplier_item_metadata_differs',
                    'message' => 'Une métadonnée diffère de l’article fournisseur existant.',
                    'field' => $field,
                    'existing_value' => $existingValue,
                    'imported_value' => $item[$field],
                ];
            }
        }
    }

    /** @param array<int, array<string, mixed>> $errors */
    private function requiredString(
        mixed $value,
        ?int $maxLength,
        string $field,
        array &$errors
    ): ?string {
        if (! is_string($value)) {
            $errors[] = $this->issue('required', 'Ce champ est obligatoire.', $field);

            return null;
        }

        $value = trim($value);
        if ($value === '') {
            $errors[] = $this->issue('required', 'Ce champ est obligatoire.', $field);

            return null;
        }

        if ($maxLength !== null && mb_strlen($value) > $maxLength) {
            $errors[] = $this->issue('max_length', 'Ce champ est trop long.', $field);

            return null;
        }

        return $value;
    }

    /** @param array<int, array<string, mixed>> $errors */
    private function nullableString(
        mixed $value,
        int $maxLength,
        string $field,
        array &$errors
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            $errors[] = $this->issue('invalid_string', 'Ce champ doit être une chaîne.', $field);

            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            $errors[] = $this->issue('max_length', 'Ce champ est trop long.', $field);

            return null;
        }

        return $value;
    }

    /** @param array<int, array<string, mixed>> $errors */
    private function requiredDate(mixed $value, string $field, array &$errors): ?string
    {
        if (! is_string($value)) {
            $errors[] = $this->issue('date_invalid', 'La date doit respecter le format YYYY-MM-DD.', $field);

            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date || $date->format('Y-m-d') !== $value) {
            $errors[] = $this->issue('date_invalid', 'La date doit respecter le format YYYY-MM-DD.', $field);

            return null;
        }

        return $value;
    }

    /** @param array<int, array<string, mixed>> $errors */
    private function price(mixed $value, array &$errors): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{1,8}(?:\.\d{1,6})?$/', $value)) {
            $errors[] = $this->issue(
                'price_excl_tax_invalid',
                'Le prix HT doit être une chaîne décimale compatible avec DECIMAL(14,6).',
                'price_excl_tax'
            );

            return null;
        }

        return $value;
    }

    /** @param array<int, array<string, mixed>> $errors */
    private function currency(mixed $value, array &$errors): ?string
    {
        if (! is_string($value)) {
            $errors[] = $this->issue('currency_code_invalid', 'La devise doit contenir exactement trois lettres.', 'currency_code');

            return null;
        }

        $value = strtoupper(trim($value));
        if (! preg_match('/^[A-Z]{3}$/', $value)) {
            $errors[] = $this->issue('currency_code_invalid', 'La devise doit contenir exactement trois lettres.', 'currency_code');

            return null;
        }

        return $value;
    }

    private function normalizedNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function key(string $value): string
    {
        return mb_strtolower($value);
    }

    /** @return array<string, mixed> */
    private function issue(
        string $code,
        string $message,
        ?string $field = null,
        array $context = []
    ): array {
        return array_filter([
            'code' => $code,
            'message' => $message,
            'field' => $field,
            ...$context,
        ], fn ($value) => $value !== null);
    }

    /** @param array<int, array<string, mixed>> $issues */
    private function hasIssue(array $issues, string $code): bool
    {
        return collect($issues)->contains(
            fn (array $issue) => $issue['code'] === $code
        );
    }

    private function decimalKey(string $value): string
    {
        $parts = explode('.', $value, 2);
        $integer = $parts[0];
        $decimal = $parts[1] ?? '';
        $integer = ltrim($integer, '0') ?: '0';
        $decimal = rtrim($decimal, '0');

        return $decimal === '' ? $integer : "{$integer}.{$decimal}";
    }

    /** @param array<int, array<string, mixed>> $errors
     *  @param array<int, array<string, mixed>> $warnings */
    private function status(array $errors, array $warnings): string
    {
        return $errors !== [] ? 'invalid' : ($warnings !== [] ? 'warning' : 'valid');
    }
}
