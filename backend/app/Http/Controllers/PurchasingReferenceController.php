<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchasingReferenceController extends Controller
{
    public function measureUnits(): JsonResponse
    {
        $measureUnits = DB::table('y_measure_units')
            ->select([
                'id',
                'code',
                'name',
                'symbol',
                'dimension',
                'to_base_factor',
                'is_active',
            ])
            ->where('is_active', 1)
            ->orderBy('dimension')
            ->orderBy('to_base_factor')
            ->orderBy('id')
            ->get()
            ->map(function ($measureUnit) {
                return [
                    'id' => (int) $measureUnit->id,
                    'code' => $measureUnit->code,
                    'name' => $measureUnit->name,
                    'symbol' => $measureUnit->symbol,
                    'dimension' => $measureUnit->dimension,
                    'to_base_factor' => $measureUnit->to_base_factor,
                    'is_active' => (bool) $measureUnit->is_active,
                ];
            })
            ->values();

        return response()->json([
            'ok' => true,
            'measure_units' => $measureUnits,
        ]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $suppliers = DB::table('y_suppliers')
            ->select(['id', 'name', 'is_active'])
            ->where('org_id', $orgId)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(function ($supplier) {
                return [
                    'id' => (int) $supplier->id,
                    'name' => $supplier->name,
                    'is_active' => (bool) $supplier->is_active,
                ];
            })
            ->values();

        return response()->json([
            'ok' => true,
            'org_id' => $orgId,
            'suppliers' => $suppliers,
        ]);
    }

    public function createSupplier(Request $request): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
        ]);

        $name = trim($validated['name']);

        if ($name === '') {
            return response()->json([
                'error' => 'supplier_name_empty',
                'message' => 'Le nom du fournisseur est obligatoire.',
            ], 422);
        }

        if (DB::table('y_suppliers')
            ->where('org_id', $orgId)
            ->where('name', $name)
            ->exists()) {
            return $this->supplierNameAlreadyExistsResponse();
        }

        $now = now('UTC');

        try {
            $supplierId = DB::table('y_suppliers')->insertGetId([
                'org_id' => $orgId,
                'name' => $name,
                'is_active' => 1,
                'created_by' => null,
                'created_at' => $now,
                'updated_by' => null,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            if ($this->isDuplicateEntry($exception)) {
                return $this->supplierNameAlreadyExistsResponse();
            }

            throw $exception;
        }

        return response()->json([
            'ok' => true,
            'supplier' => [
                'id' => (int) $supplierId,
                'org_id' => $orgId,
                'name' => $name,
                'is_active' => true,
            ],
            'message' => 'Fournisseur créé.',
        ], 201);
    }

    public function materials(Request $request): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $materials = DB::table('y_materials as m')
            ->join(
                'y_measure_units as u',
                'u.id',
                '=',
                'm.reference_measure_unit_id'
            )
            ->where('m.org_id', $orgId)
            ->select($this->materialColumns())
            ->orderBy('m.name')
            ->orderBy('m.id')
            ->get()
            ->map(fn ($material) => $this->materialResponse($material))
            ->values();

        return response()->json([
            'ok' => true,
            'org_id' => $orgId,
            'materials' => $materials,
        ]);
    }

    public function createMaterial(Request $request): JsonResponse
    {
        $orgId = $this->orgId($request);

        if (!$this->organizationExists($orgId)) {
            return $this->organizationNotFoundResponse();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'reference_measure_unit_id' => ['required', 'integer', 'min:1'],
            'reference_unit_label' => ['nullable', 'string', 'max:50'],
        ]);

        $name = trim($validated['name']);

        if ($name === '') {
            return response()->json([
                'error' => 'material_name_empty',
                'message' => 'Le nom de la matière est obligatoire.',
            ], 422);
        }

        $referenceMeasureUnitId = (int) $validated['reference_measure_unit_id'];

        $measureUnit = DB::table('y_measure_units')
            ->where('id', $referenceMeasureUnitId)
            ->where('is_active', 1)
            ->first();

        if (!$measureUnit) {
            return response()->json([
                'error' => 'reference_measure_unit_invalid',
                'message' => 'L’unité de référence est invalide ou inactive.',
            ], 422);
        }

        $referenceUnitLabel = $this->normalizeReferenceUnitLabel(
            $validated['reference_unit_label'] ?? null
        );

        if (DB::table('y_materials')
            ->where('org_id', $orgId)
            ->where('name', $name)
            ->exists()) {
            return $this->materialNameAlreadyExistsResponse();
        }

        $now = now('UTC');

        try {
            $materialId = DB::table('y_materials')->insertGetId([
                'org_id' => $orgId,
                'name' => $name,
                'reference_measure_unit_id' => $referenceMeasureUnitId,
                'reference_unit_label' => $referenceUnitLabel,
                'is_active' => 1,
                'created_by' => null,
                'created_at' => $now,
                'updated_by' => null,
                'updated_at' => $now,
            ]);
        } catch (QueryException $exception) {
            if ($this->isDuplicateEntry($exception)) {
                return $this->materialNameAlreadyExistsResponse();
            }

            throw $exception;
        }

        $material = DB::table('y_materials as m')
            ->join(
                'y_measure_units as u',
                'u.id',
                '=',
                'm.reference_measure_unit_id'
            )
            ->where('m.id', $materialId)
            ->where('m.org_id', $orgId)
            ->select($this->materialColumns())
            ->first();

        return response()->json([
            'ok' => true,
            'material' => $this->materialResponse($material),
            'message' => 'Matière créée.',
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

    private function organizationNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'organization_not_found',
            'message' => 'Organisation introuvable.',
        ], 404);
    }

    private function supplierNameAlreadyExistsResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'supplier_name_already_exists',
            'message' => 'Un fournisseur avec ce nom existe déjà.',
        ], 422);
    }

    private function materialNameAlreadyExistsResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'material_name_already_exists',
            'message' => 'Une matière avec ce nom existe déjà.',
        ], 422);
    }

    private function isDuplicateEntry(QueryException $exception): bool
    {
        return isset($exception->errorInfo[1])
            && (int) $exception->errorInfo[1] === 1062;
    }

    private function normalizeReferenceUnitLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = trim($label);

        return $label === '' ? null : $label;
    }

    /**
     * @return array<int, string>
     */
    private function materialColumns(): array
    {
        return [
            'm.id as material_id',
            'm.org_id',
            'm.name as material_name',
            'm.reference_unit_label',
            'm.is_active as material_is_active',
            'u.id as measure_unit_id',
            'u.code as measure_unit_code',
            'u.name as measure_unit_name',
            'u.symbol as measure_unit_symbol',
            'u.dimension as measure_unit_dimension',
            'u.to_base_factor as measure_unit_to_base_factor',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function materialResponse(object $material): array
    {
        return [
            'id' => (int) $material->material_id,
            'org_id' => (int) $material->org_id,
            'name' => $material->material_name,
            'reference_unit_label' => $material->reference_unit_label,
            'is_active' => (bool) $material->material_is_active,
            'reference_measure_unit' => [
                'id' => (int) $material->measure_unit_id,
                'code' => $material->measure_unit_code,
                'name' => $material->measure_unit_name,
                'symbol' => $material->measure_unit_symbol,
                'dimension' => $material->measure_unit_dimension,
                'to_base_factor' => $material->measure_unit_to_base_factor,
            ],
        ];
    }
}
