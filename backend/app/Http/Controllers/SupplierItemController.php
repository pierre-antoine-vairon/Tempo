<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierItemController extends Controller
{
    public function items(Request $request, int $supplierId): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $supplier = $this->findSupplier($orgId, $supplierId);

        if (!$supplier) {
            return $this->supplierNotFoundResponse();
        }

        $items = $this->itemQuery()
            ->where('i.org_id', $orgId)
            ->where('i.supplier_id', $supplierId)
            ->orderBy('i.name')
            ->orderBy('i.id')
            ->get()
            ->map(fn ($item) => $this->itemResponse($item))
            ->values();

        return response()->json([
            'ok' => true,
            'org_id' => $orgId,
            'supplier' => $this->supplierResponse($supplier),
            'items' => $items,
        ]);
    }

    public function createItem(Request $request, int $supplierId): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $supplier = $this->findSupplier($orgId, $supplierId);

        if (!$supplier) {
            return $this->supplierNotFoundResponse();
        }

        $validated = $request->validate([
            'supplier_category_id' => ['nullable', 'integer', 'min:1'],
            'supplier_sku' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:160'],
            'brand' => ['nullable', 'string', 'max:160'],
            'purchase_unit_label' => ['nullable', 'string', 'max:50'],
            'pack_label' => ['nullable', 'string', 'max:160'],
            'material_id' => ['nullable', 'integer', 'min:1'],
            'quantity_in_reference_unit' => ['nullable', 'numeric'],
        ]);

        $name = trim($validated['name']);

        if ($name === '') {
            return response()->json([
                'error' => 'supplier_item_name_empty',
                'message' => 'Le nom de l’article fournisseur est obligatoire.',
            ], 422);
        }

        $categoryId = $validated['supplier_category_id'] ?? null;

        if ($categoryId !== null && !DB::table('y_supplier_categories')
            ->where('id', (int) $categoryId)
            ->where('org_id', $orgId)
            ->where('supplier_id', $supplierId)
            ->exists()) {
            return $this->supplierCategoryInvalidResponse();
        }

        $materialId = $validated['material_id'] ?? null;

        if ($materialId !== null && !DB::table('y_materials')
            ->where('id', (int) $materialId)
            ->where('org_id', $orgId)
            ->exists()) {
            return $this->materialInvalidResponse();
        }

        $quantityInReferenceUnit = $validated['quantity_in_reference_unit'] ?? null;

        if ($quantityInReferenceUnit !== null && $quantityInReferenceUnit <= 0) {
            return $this->quantityInvalidResponse();
        }

        if ($quantityInReferenceUnit !== null && $materialId === null) {
            return $this->quantityRequiresMaterialResponse();
        }

        $supplierSku = $this->normalizeNullableString(
            $validated['supplier_sku'] ?? null
        );

        if ($supplierSku !== null && DB::table('y_supplier_items')
            ->where('supplier_id', $supplierId)
            ->where('supplier_sku', $supplierSku)
            ->exists()) {
            return $this->supplierItemSkuAlreadyExistsResponse();
        }

        $now = now('UTC');

        try {
            $itemId = DB::table('y_supplier_items')->insertGetId([
                'org_id' => $orgId,
                'supplier_id' => $supplierId,
                'material_id' => $materialId,
                'supplier_category_id' => $categoryId,
                'name' => $name,
                'supplier_sku' => $supplierSku,
                'brand' => $this->normalizeNullableString(
                    $validated['brand'] ?? null
                ),
                'purchase_unit_label' => $this->normalizeNullableString(
                    $validated['purchase_unit_label'] ?? null
                ),
                'pack_label' => $this->normalizeNullableString(
                    $validated['pack_label'] ?? null
                ),
                'quantity_in_reference_unit' => $quantityInReferenceUnit,
                'is_active' => 1,
                'created_by' => null,
                'created_at' => $now,
                'updated_by' => null,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            if ($this->isDuplicateEntry($exception)) {
                return $this->supplierItemSkuAlreadyExistsResponse();
            }

            throw $exception;
        }

        $item = $this->findItem($orgId, $itemId);

        return response()->json([
            'ok' => true,
            'item' => $this->itemResponse($item),
            'message' => 'Article fournisseur créé.',
        ], 201);
    }

    public function showItem(Request $request, int $itemId): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $item = $this->findItem($orgId, $itemId);

        if (!$item) {
            return $this->supplierItemNotFoundResponse();
        }

        return response()->json([
            'ok' => true,
            'item' => $this->itemResponse($item),
        ]);
    }

    public function linkMaterial(Request $request, int $itemId): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $item = $this->findItem($orgId, $itemId);

        if (!$item) {
            return $this->supplierItemNotFoundResponse();
        }

        $validated = $request->validate([
            'material_id' => ['required', 'integer', 'min:1'],
            'quantity_in_reference_unit' => ['nullable', 'numeric'],
        ]);

        $materialId = (int) $validated['material_id'];

        if (!DB::table('y_materials')
            ->where('id', $materialId)
            ->where('org_id', $orgId)
            ->exists()) {
            return $this->materialInvalidResponse();
        }

        $quantityInReferenceUnit = $validated['quantity_in_reference_unit'] ?? null;

        if ($quantityInReferenceUnit !== null && $quantityInReferenceUnit <= 0) {
            return $this->quantityInvalidResponse();
        }

        DB::table('y_supplier_items')
            ->where('id', $itemId)
            ->where('org_id', $orgId)
            ->update([
                'material_id' => $materialId,
                'quantity_in_reference_unit' => $quantityInReferenceUnit,
                'updated_by' => null,
                'updated_at' => now('UTC'),
            ]);

        $updatedItem = $this->findItem($orgId, $itemId);

        return response()->json([
            'ok' => true,
            'item' => $this->itemResponse($updatedItem),
            'message' => 'Rapprochement matière mis à jour.',
        ]);
    }

    public function prices(Request $request, int $itemId): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $item = $this->findItem($orgId, $itemId);

        if (!$item) {
            return $this->supplierItemNotFoundResponse();
        }

        $prices = DB::table('y_supplier_item_prices as p')
            ->leftJoin('y_supplier_catalogs as c', function ($join) {
                $join->on('c.id', '=', 'p.supplier_catalog_id')
                    ->on('c.org_id', '=', 'p.org_id');
            })
            ->select([
                'p.id',
                'p.site_id',
                'p.price_excl_tax',
                'p.currency_code',
                'p.effective_from',
                'c.id as catalog_id',
                'c.name as catalog_name',
                'c.effective_from as catalog_effective_from',
                'c.source_filename as catalog_source_filename',
            ])
            ->where('p.org_id', $orgId)
            ->where('p.supplier_item_id', $itemId)
            ->orderByDesc('p.effective_from')
            ->orderByDesc('p.id')
            ->get()
            ->map(fn ($price) => $this->priceResponse($price))
            ->values();

        return response()->json([
            'ok' => true,
            'item_id' => $itemId,
            'prices' => $prices,
        ]);
    }

    public function createPrice(Request $request, int $itemId): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $item = $this->findItem($orgId, $itemId);

        if (!$item) {
            return $this->supplierItemNotFoundResponse();
        }

        $validated = $request->validate([
            'site_id' => ['nullable', 'integer', 'min:1'],
            'supplier_catalog_id' => ['nullable', 'integer', 'min:1'],
            'price_excl_tax' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);

        $siteId = $validated['site_id'] ?? null;

        if ($siteId !== null && !DB::table('y_sites')
            ->where('id', (int) $siteId)
            ->where('org_id', $orgId)
            ->exists()) {
            return response()->json([
                'error' => 'site_not_found',
                'message' => 'Site introuvable pour cette organisation.',
            ], 422);
        }

        $catalogId = $validated['supplier_catalog_id'] ?? null;

        if ($catalogId !== null && !DB::table('y_supplier_catalogs')
            ->where('id', (int) $catalogId)
            ->where('org_id', $orgId)
            ->where('supplier_id', (int) $item->supplier_id)
            ->exists()) {
            return $this->supplierCatalogInvalidResponse();
        }

        $effectiveFrom = $validated['effective_from'];

        $duplicatePriceQuery = DB::table('y_supplier_item_prices')
            ->where('supplier_item_id', $itemId)
            ->where('effective_from', $effectiveFrom);

        if ($siteId === null) {
            $duplicatePriceQuery->whereNull('site_id');
        } else {
            $duplicatePriceQuery->where('site_id', (int) $siteId);
        }

        if ($duplicatePriceQuery->exists()) {
            return $this->supplierItemPriceAlreadyExistsResponse();
        }

        $now = now('UTC');

        try {
            $priceId = DB::table('y_supplier_item_prices')->insertGetId([
                'org_id' => $orgId,
                'supplier_item_id' => $itemId,
                'site_id' => $siteId,
                'supplier_catalog_id' => $catalogId,
                'price_excl_tax' => $validated['price_excl_tax'],
                'currency_code' => strtoupper(trim($validated['currency_code'])),
                'effective_from' => $effectiveFrom,
                'created_by' => null,
                'created_at' => $now,
                'updated_by' => null,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            if ($this->isDuplicateEntry($exception)) {
                return $this->supplierItemPriceAlreadyExistsResponse();
            }

            throw $exception;
        }

        $price = $this->findPrice($orgId, $itemId, $priceId);

        return response()->json([
            'ok' => true,
            'price' => $this->priceResponse($price),
            'message' => 'Tarif fournisseur créé.',
        ], 201);
    }

    private function itemQuery()
    {
        return DB::table('y_supplier_items as i')
            ->join('y_suppliers as s', function ($join) {
                $join->on('s.id', '=', 'i.supplier_id')
                    ->on('s.org_id', '=', 'i.org_id');
            })
            ->leftJoin('y_supplier_categories as c', function ($join) {
                $join->on('c.id', '=', 'i.supplier_category_id')
                    ->on('c.org_id', '=', 'i.org_id')
                    ->on('c.supplier_id', '=', 'i.supplier_id');
            })
            ->leftJoin('y_materials as m', function ($join) {
                $join->on('m.id', '=', 'i.material_id')
                    ->on('m.org_id', '=', 'i.org_id');
            })
            ->leftJoin(
                'y_measure_units as u',
                'u.id',
                '=',
                'm.reference_measure_unit_id'
            )
            ->select([
                'i.id as item_id',
                'i.org_id as item_org_id',
                'i.supplier_id',
                'i.supplier_category_id',
                'i.supplier_sku',
                'i.name as item_name',
                'i.brand',
                'i.purchase_unit_label',
                'i.pack_label',
                'i.material_id',
                'i.quantity_in_reference_unit',
                'i.is_active as item_is_active',
                's.id as supplier_id_response',
                's.name as supplier_name',
                'c.id as category_id',
                'c.parent_id as category_parent_id',
                'c.name as category_name',
                'c.source_path as category_source_path',
                'm.id as material_id_response',
                'm.name as material_name',
                'm.reference_unit_label as material_reference_unit_label',
                'u.id as measure_unit_id',
                'u.code as measure_unit_code',
                'u.name as measure_unit_name',
                'u.symbol as measure_unit_symbol',
                'u.dimension as measure_unit_dimension',
                'u.to_base_factor as measure_unit_to_base_factor',
            ]);
    }

    private function findItem(int $orgId, int $itemId): ?object
    {
        return $this->itemQuery()
            ->where('i.id', $itemId)
            ->where('i.org_id', $orgId)
            ->first();
    }

    private function findPrice(int $orgId, int $itemId, int $priceId): ?object
    {
        return DB::table('y_supplier_item_prices as p')
            ->leftJoin('y_supplier_catalogs as c', function ($join) {
                $join->on('c.id', '=', 'p.supplier_catalog_id')
                    ->on('c.org_id', '=', 'p.org_id');
            })
            ->select([
                'p.id',
                'p.site_id',
                'p.price_excl_tax',
                'p.currency_code',
                'p.effective_from',
                'c.id as catalog_id',
                'c.name as catalog_name',
                'c.effective_from as catalog_effective_from',
                'c.source_filename as catalog_source_filename',
            ])
            ->where('p.id', $priceId)
            ->where('p.org_id', $orgId)
            ->where('p.supplier_item_id', $itemId)
            ->first();
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

    private function findSupplier(int $orgId, int $supplierId): ?object
    {
        return DB::table('y_suppliers')
            ->select(['id', 'name'])
            ->where('id', $supplierId)
            ->where('org_id', $orgId)
            ->first();
    }

    private function organizationNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'organization_not_found',
            'message' => 'Organisation introuvable.',
        ], 404);
    }

    private function supplierNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'supplier_not_found',
            'message' => 'Fournisseur introuvable.',
        ], 404);
    }

    private function supplierItemNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'supplier_item_not_found',
            'message' => 'Article fournisseur introuvable.',
        ], 404);
    }

    private function supplierCategoryInvalidResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'supplier_category_invalid',
            'message' => 'La catégorie fournisseur est invalide.',
        ], 422);
    }

    private function materialInvalidResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'material_invalid',
            'message' => 'La matière est invalide pour cette organisation.',
        ], 422);
    }

    private function quantityInvalidResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'quantity_in_reference_unit_invalid',
            'message' => 'La quantité de référence doit être strictement positive.',
        ], 422);
    }

    private function quantityRequiresMaterialResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'quantity_requires_material',
            'message' => 'Une matière est nécessaire pour définir la quantité de référence.',
        ], 422);
    }

    private function supplierItemSkuAlreadyExistsResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'supplier_item_sku_already_exists',
            'message' => 'Un article fournisseur possède déjà cette référence.',
        ], 422);
    }

    private function supplierCatalogInvalidResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'supplier_catalog_invalid',
            'message' => 'Le catalogue fournisseur est invalide pour cet article.',
        ], 422);
    }

    private function supplierItemPriceAlreadyExistsResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'supplier_item_price_already_exists',
            'message' => 'Un tarif existe déjà pour cet article, ce scope et cette date.',
        ], 422);
    }

    private function isDuplicateEntry(QueryException $exception): bool
    {
        return isset($exception->errorInfo[1])
            && (int) $exception->errorInfo[1] === 1062;
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function supplierResponse(object $supplier): array
    {
        return [
            'id' => (int) $supplier->id,
            'name' => $supplier->name,
        ];
    }

    private function itemResponse(object $item): array
    {
        return [
            'id' => (int) $item->item_id,
            'supplier' => [
                'id' => (int) $item->supplier_id_response,
                'name' => $item->supplier_name,
            ],
            'category' => $item->category_id === null ? null : [
                'id' => (int) $item->category_id,
                'parent_id' => $item->category_parent_id === null
                    ? null
                    : (int) $item->category_parent_id,
                'name' => $item->category_name,
                'source_path' => $item->category_source_path,
            ],
            'supplier_sku' => $item->supplier_sku,
            'name' => $item->item_name,
            'brand' => $item->brand,
            'purchase_unit_label' => $item->purchase_unit_label,
            'pack_label' => $item->pack_label,
            'material' => $item->material_id_response === null ? null : [
                'id' => (int) $item->material_id_response,
                'name' => $item->material_name,
                'reference_unit_label' => $item->material_reference_unit_label,
                'reference_measure_unit' => [
                    'id' => (int) $item->measure_unit_id,
                    'code' => $item->measure_unit_code,
                    'name' => $item->measure_unit_name,
                    'symbol' => $item->measure_unit_symbol,
                    'dimension' => $item->measure_unit_dimension,
                    'to_base_factor' => $item->measure_unit_to_base_factor,
                ],
            ],
            'quantity_in_reference_unit' => $item->quantity_in_reference_unit,
            'is_active' => (bool) $item->item_is_active,
        ];
    }

    private function priceResponse(object $price): array
    {
        return [
            'id' => (int) $price->id,
            'site_id' => $price->site_id === null ? null : (int) $price->site_id,
            'price_excl_tax' => $price->price_excl_tax,
            'currency_code' => $price->currency_code,
            'effective_from' => $price->effective_from,
            'supplier_catalog' => $price->catalog_id === null ? null : [
                'id' => (int) $price->catalog_id,
                'name' => $price->catalog_name,
                'effective_from' => $price->catalog_effective_from,
                'source_filename' => $price->catalog_source_filename,
            ],
        ];
    }
}
