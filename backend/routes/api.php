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

// donne-moi les shifts du roster X
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