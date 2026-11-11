<?php

namespace App\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierCatalogImportController extends Controller
{
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

        $payload = $request->json()->all();
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

        return response()->json([
            'ok' => true,
            'schema_version' => 1,
            'supplier' => [
                'id' => (int) $supplier->id,
                'name' => $supplier->name,
            ],
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
        ]);
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

        if ($name !== null && $effectiveFrom !== null && DB::table('y_supplier_catalogs')
            ->where('org_id', $orgId)
            ->where('supplier_id', $supplierId)
            ->where('name', $name)
            ->where('effective_from', $effectiveFrom)
            ->exists()) {
            $errors[] = $this->issue(
                'catalog_already_exists',
                'Un catalogue fournisseur identique existe déjà.'
            );
        }

        return [
            'name' => $name,
            'effective_from' => $effectiveFrom,
            'source_filename' => $sourceFilename,
            'status' => $this->status($errors, []),
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

                    if (isset($existingPrices[(int) $existing->id])) {
                        $item['errors'][] = $this->issue(
                            'supplier_item_price_already_exists',
                            'Un tarif organisation existe déjà pour cet article et cette date.'
                        );
                    }
                } else {
                    $item['operation'] = 'create_item';
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
     * @return array<int, true>
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
            ->where('org_id', $orgId)
            ->whereIn('supplier_item_id', array_map(
                fn (object $item) => (int) $item->id,
                $existingItems
            ))
            ->whereNull('site_id')
            ->where('effective_from', $effectiveFrom)
            ->pluck('supplier_item_id')
            ->mapWithKeys(fn ($itemId) => [(int) $itemId => true])
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
        if (! is_string($value) || ! preg_match('/^\d{1,8}(?:\.\d{1,4})?$/', $value)) {
            $errors[] = $this->issue(
                'price_excl_tax_invalid',
                'Le prix HT doit être une chaîne décimale compatible avec DECIMAL(12,4).',
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

    /** @return array<string, string> */
    private function issue(string $code, string $message, ?string $field = null): array
    {
        return array_filter([
            'code' => $code,
            'message' => $message,
            'field' => $field,
        ], fn ($value) => $value !== null);
    }

    /** @param array<int, array<string, mixed>> $errors
     *  @param array<int, array<string, mixed>> $warnings */
    private function status(array $errors, array $warnings): string
    {
        return $errors !== [] ? 'invalid' : ($warnings !== [] ? 'warning' : 'valid');
    }
}
