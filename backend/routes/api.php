<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    DB::selectOne('SELECT 1 as ok');
    return response()->json(['ok' => true, 'db' => 'up']);
});


Route::get('/sites', function (Request $request) {
    $orgId = (int) $request->query('org_id', (int) config('tempo.default_org_id'));

    return DB::table('y_sites')
        ->select(['id', 'org_id', 'name'])
        ->where('org_id', $orgId)
        ->orderBy('id')
        ->get();
});

// NOTE MVP:
// `y_workers.site_id` est optionnel et ne représente pas forcément le site réel travaillé.
// Un worker peut travailler sur plusieurs sites selon les rosters, shifts et assignments.
// Pour les flux planning/assignation, le vrai site vient de :
// roster.site_id / shift.site_id, pas forcément de worker.site_id.
//
// Conséquence :
// `/workers?site_id=...` filtre seulement un éventuel site principal / administratif.
// Cette route ne doit pas être utilisée comme seule source des workers disponibles pour un shift.
Route::get('/workers', function (Request $request) {
    $orgId  = (int) $request->query('org_id', (int) config('tempo.default_org_id'));
    $siteId = $request->query('site_id'); // optionnel

    $q = DB::table('y_workers')
        ->select(['id', 'org_id', 'site_id', 'first_name', 'last_name'])
        ->where('org_id', $orgId);

    if ($siteId !== null && $siteId !== '') {
        $q->where('site_id', (int) $siteId);
    }

    return $q->orderBy('id')->get();
});

Route::get('/rosters', function (Request $request) {
    $orgId = (int) $request->query('org_id', (int) config('tempo.default_org_id'));
    $siteId = $request->query('site_id');

    $q = DB::table('y_rosters')
        ->join('y_sites', 'y_rosters.site_id', '=', 'y_sites.id')
        ->select([
            'y_rosters.id',
            'y_rosters.org_id',
            'y_rosters.site_id',
            'y_sites.name as site_name',
            'y_rosters.period_start',
            'y_rosters.period_end',
            'y_rosters.status',
            'y_rosters.notes',
        ])
        ->where('y_rosters.org_id', $orgId)
        ->where('y_sites.org_id', $orgId);

    if ($siteId !== null && $siteId !== '') {
        $q->where('y_rosters.site_id', (int) $siteId);
    }

    return $q
        ->orderBy('y_rosters.period_start')
        ->orderBy('y_rosters.site_id')
        ->get();
});

Route::get('/rosters/{rosterId}', function (Request $request, int $rosterId) {
    $orgId = (int) $request->query('org_id', (int) config('tempo.default_org_id'));

    $roster = DB::table('y_rosters')
        ->join('y_sites', 'y_rosters.site_id', '=', 'y_sites.id')
        ->select([
            'y_rosters.id',
            'y_rosters.org_id',
            'y_rosters.site_id',
            'y_sites.name as site_name',
            'y_rosters.period_start',
            'y_rosters.period_end',
            'y_rosters.status',
            'y_rosters.notes',
        ])
        ->where('y_rosters.org_id', $orgId)
        ->where('y_sites.org_id', $orgId)
        ->where('y_rosters.id', $rosterId)
        ->first();

    if (!$roster) {
        return response()->json([
            'error' => 'roster_not_found',
            'message' => 'Roster introuvable',
        ], 404);
    }

    return response()->json($roster);
});

// MVP read endpoint: returns shifts for a given roster, scoped by org_id.
// A prevoir : vérifier que le roster existe ; renvoyer 404 roster_not_found.
Route::get('/rosters/{rosterId}/shifts', function (Request $request, int $rosterId) {
    $orgId = (int) $request->query('org_id', (int) config('tempo.default_org_id'));

    return DB::table('y_shifts')
        ->select([
            'id',
            'org_id',
            'site_id',
            'roster_id',
            'demand_id',
            'starts_at',
            'ends_at',
            'required_count',
            'status',
            'notes',
        ])
        ->where('org_id', $orgId)
        ->where('roster_id', $rosterId)
        ->orderBy('starts_at')
        ->get();
});

// Harmoniser plus tard avec :
//return response()->json([
//    'error' => 'roster_not_found',
//    'message' => 'Roster introuvable',
//], 404);
Route::get('/rosters/{rosterId}/assignments', function (Request $request, int $rosterId) {
    $orgId = (int) $request->query('org_id', (int) config('tempo.default_org_id'));

    return DB::table('y_assignments')
        ->join('y_shifts', 'y_assignments.shift_id', '=', 'y_shifts.id')
        ->join('y_workers', 'y_assignments.worker_id', '=', 'y_workers.id')
        ->select([
            'y_assignments.id',
            'y_assignments.org_id',
            'y_assignments.shift_id',
            'y_assignments.worker_id',
            'y_workers.first_name as worker_first_name',
            'y_workers.last_name as worker_last_name',
            'y_assignments.role',
            'y_assignments.status',
        ])
        ->where('y_assignments.org_id', $orgId)
        ->where('y_shifts.org_id', $orgId)
        ->where('y_workers.org_id', $orgId)
        ->where('y_shifts.roster_id', $rosterId)
        ->orderBy('y_assignments.shift_id')
        ->orderBy('y_assignments.id')
        ->get();
});

// ROUTE PUT

Route::put('/shifts/{shiftId}/assignments', function (Request $request, int $shiftId) {
    // 1. worker_ids existe et est un tableau
    // 2. chaque worker_id est un entier positif
    // 3. il n’y a pas de doublons
    // 4. le shift existe dans l’org courante
    // 5. tous les workers existent dans l’org courante
    // 6. supprimer les anciennes assignations du shift
    // 7. créer les nouvelles assignations
    // 8. renvoyer les assignations mises à jour avec les noms des workers
    $orgId = (int) $request->query('org_id', (int) config('tempo.default_org_id'));

    $workerIds = $request->input('worker_ids');

    if (!is_array($workerIds)) {
        return response()->json([
            'error' => 'invalid_payload',
            'message' => 'worker_ids doit être un tableau.',
        ], 422);
    }

    $normalizedWorkerIds = [];

    foreach ($workerIds as $workerId) {
        if (!is_int($workerId) && !ctype_digit((string) $workerId)) {
            return response()->json([
                'error' => 'invalid_worker_id',
                'message' => 'Chaque worker_id doit être un entier positif.',
            ], 422);
        }

        $workerId = (int) $workerId;

        if ($workerId <= 0) {
            return response()->json([
                'error' => 'invalid_worker_id',
                'message' => 'Chaque worker_id doit être un entier positif.',
            ], 422);
        }

        $normalizedWorkerIds[] = $workerId;
    }

    $uniqueWorkerIds = array_values(array_unique($normalizedWorkerIds));

    if (count($uniqueWorkerIds) !== count($normalizedWorkerIds)) {
        return response()->json([
            'error' => 'duplicate_worker_ids',
            'message' => 'worker_ids ne doit pas contenir de doublons.',
        ], 422);
    }

    $shift = DB::table('y_shifts')
        ->where('id', $shiftId)
        ->where('org_id', $orgId)
        ->first();

    if (!$shift) {
        return response()->json([
            'error' => 'shift_not_found',
            'message' => 'Shift introuvable.',
        ], 404);
    }

    if (count($uniqueWorkerIds) > 0) {
        $existingWorkersCount = DB::table('y_workers')
            ->where('org_id', $orgId)
            ->whereIn('id', $uniqueWorkerIds)
            ->count();

        if ($existingWorkersCount !== count($uniqueWorkerIds)) {
            return response()->json([
                'error' => 'worker_not_found',
                'message' => 'Un ou plusieurs workers sont introuvables pour cette organisation.',
            ], 422);
        }
    }

    DB::transaction(function () use ($orgId, $shiftId, $uniqueWorkerIds) {
        DB::table('y_assignments')
            ->where('org_id', $orgId)
            ->where('shift_id', $shiftId)
            ->delete();

        if (count($uniqueWorkerIds) === 0) {
            return;
        }

        $now = now();

        $rows = array_map(function (int $workerId) use ($orgId, $shiftId, $now) {
            return [
                'org_id' => $orgId,
                'shift_id' => $shiftId,
                'worker_id' => $workerId,
                'role' => 'AGENT',
                'status' => 'planned',
                'created_by' => 1,
                'created_at' => $now,
                'updated_by' => 1,
                'updated_at' => $now,
            ];
        }, $uniqueWorkerIds);

        DB::table('y_assignments')->insert($rows);
    });

    $assignments = DB::table('y_assignments')
        ->join('y_workers', 'y_assignments.worker_id', '=', 'y_workers.id')
        ->select([
            'y_assignments.id',
            'y_assignments.org_id',
            'y_assignments.shift_id',
            'y_assignments.worker_id',
            'y_workers.first_name as worker_first_name',
            'y_workers.last_name as worker_last_name',
            'y_assignments.role',
            'y_assignments.status',
        ])
        ->where('y_assignments.org_id', $orgId)
        ->where('y_workers.org_id', $orgId)
        ->where('y_assignments.shift_id', $shiftId)
        ->orderBy('y_assignments.id')
        ->get();

    return response()->json($assignments);
});