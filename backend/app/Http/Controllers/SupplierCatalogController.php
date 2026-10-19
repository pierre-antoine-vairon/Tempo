<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierCatalogController extends Controller
{
    public function categories(Request $request, int $supplierId): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $supplier = $this->findSupplier($orgId, $supplierId);

        if (!$supplier) {
            return $this->supplierNotFoundResponse();
        }

        $categories = DB::table('y_supplier_categories')
            ->select(['id', 'parent_id', 'name', 'source_path', 'is_active'])
            ->where('org_id', $orgId)
            ->where('supplier_id', $supplierId)
            ->orderBy('source_path')
            ->orderBy('id')
            ->get()
            ->map(fn ($category) => $this->categoryResponse($category))
            ->values();

        return response()->json([
            'ok' => true,
            'org_id' => $orgId,
            'supplier' => $this->supplierResponse($supplier),
            'categories' => $categories,
        ]);
    }

    public function createCategory(
        Request $request,
        int $supplierId
    ): JsonResponse {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $supplier = $this->findSupplier($orgId, $supplierId);

        if (!$supplier) {
            return $this->supplierNotFoundResponse();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'source_path' => ['required', 'string', 'max:500'],
        ]);

        $name = trim($validated['name']);

        if ($name === '') {
            return response()->json([
                'error' => 'supplier_category_name_empty',
                'message' => 'Le nom de la catégorie fournisseur est obligatoire.',
            ], 422);
        }

        $sourcePath = $validated['source_path'];

        if (trim($sourcePath) === '') {
            return response()->json([
                'error' => 'supplier_category_source_path_empty',
                'message' => 'Le chemin source de la catégorie fournisseur est obligatoire.',
            ], 422);
        }

        $parentId = $validated['parent_id'] ?? null;

        if ($parentId !== null && !DB::table('y_supplier_categories')
            ->where('id', (int) $parentId)
            ->where('org_id', $orgId)
            ->where('supplier_id', $supplierId)
            ->exists()) {
            return response()->json([
                'error' => 'supplier_category_parent_invalid',
                'message' => 'La catégorie parent est invalide pour ce fournisseur.',
            ], 422);
        }

        if (DB::table('y_supplier_categories')
            ->where('supplier_id', $supplierId)
            ->where('source_path', $sourcePath)
            ->exists()) {
            return $this->supplierCategoryAlreadyExistsResponse();
        }

        $now = now('UTC');

        try {
            $categoryId = DB::table('y_supplier_categories')->insertGetId([
                'org_id' => $orgId,
                'supplier_id' => $supplierId,
                'parent_id' => $parentId,
                'name' => $name,
                'source_path' => $sourcePath,
                'is_active' => 1,
                'created_by' => null,
                'created_at' => $now,
                'updated_by' => null,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            if ($this->isDuplicateEntry($exception)) {
                return $this->supplierCategoryAlreadyExistsResponse();
            }

            throw $exception;
        }

        $category = DB::table('y_supplier_categories')
            ->select(['id', 'parent_id', 'name', 'source_path', 'is_active'])
            ->where('id', $categoryId)
            ->where('org_id', $orgId)
            ->where('supplier_id', $supplierId)
            ->first();

        return response()->json([
            'ok' => true,
            'category' => $this->categoryResponse($category),
            'message' => 'Catégorie fournisseur créée.',
        ], 201);
    }

    public function catalogs(Request $request, int $supplierId): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $supplier = $this->findSupplier($orgId, $supplierId);

        if (!$supplier) {
            return $this->supplierNotFoundResponse();
        }

        $catalogs = DB::table('y_supplier_catalogs')
            ->select(['id', 'name', 'effective_from', 'source_filename'])
            ->where('org_id', $orgId)
            ->where('supplier_id', $supplierId)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($catalog) => $this->catalogResponse($catalog))
            ->values();

        return response()->json([
            'ok' => true,
            'org_id' => $orgId,
            'supplier' => $this->supplierResponse($supplier),
            'catalogs' => $catalogs,
        ]);
    }

    public function createCatalog(
        Request $request,
        int $supplierId
    ): JsonResponse {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $supplier = $this->findSupplier($orgId, $supplierId);

        if (!$supplier) {
            return $this->supplierNotFoundResponse();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'source_filename' => ['nullable', 'string', 'max:255'],
        ]);

        $name = trim($validated['name']);

        if ($name === '') {
            return response()->json([
                'error' => 'supplier_catalog_name_empty',
                'message' => 'Le nom du catalogue fournisseur est obligatoire.',
            ], 422);
        }

        $effectiveFrom = $validated['effective_from'];
        $sourceFilename = $this->normalizeNullableString(
            $validated['source_filename'] ?? null
        );

        if (DB::table('y_supplier_catalogs')
            ->where('supplier_id', $supplierId)
            ->where('name', $name)
            ->where('effective_from', $effectiveFrom)
            ->exists()) {
            return $this->supplierCatalogAlreadyExistsResponse();
        }

        $now = now('UTC');

        try {
            $catalogId = DB::table('y_supplier_catalogs')->insertGetId([
                'org_id' => $orgId,
                'supplier_id' => $supplierId,
                'name' => $name,
                'effective_from' => $effectiveFrom,
                'source_filename' => $sourceFilename,
                'created_by' => null,
                'created_at' => $now,
                'updated_by' => null,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            if ($this->isDuplicateEntry($exception)) {
                return $this->supplierCatalogAlreadyExistsResponse();
            }

            throw $exception;
        }

        $catalog = DB::table('y_supplier_catalogs')
            ->select(['id', 'name', 'effective_from', 'source_filename'])
            ->where('id', $catalogId)
            ->where('org_id', $orgId)
            ->where('supplier_id', $supplierId)
            ->first();

        return response()->json([
            'ok' => true,
            'catalog' => $this->catalogResponse($catalog),
            'message' => 'Catalogue fournisseur créé.',
        ], 201);
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

    private function supplierCategoryAlreadyExistsResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'supplier_category_source_path_already_exists',
            'message' => 'Une catégorie fournisseur possède déjà ce chemin source.',
        ], 422);
    }

    private function supplierCatalogAlreadyExistsResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'supplier_catalog_already_exists',
            'message' => 'Un catalogue fournisseur identique existe déjà.',
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

    private function categoryResponse(object $category): array
    {
        return [
            'id' => (int) $category->id,
            'parent_id' => $category->parent_id === null
                ? null
                : (int) $category->parent_id,
            'name' => $category->name,
            'source_path' => $category->source_path,
            'is_active' => (bool) $category->is_active,
        ];
    }

    private function catalogResponse(object $catalog): array
    {
        return [
            'id' => (int) $catalog->id,
            'name' => $catalog->name,
            'effective_from' => $catalog->effective_from,
            'source_filename' => $catalog->source_filename,
        ];
    }
}
