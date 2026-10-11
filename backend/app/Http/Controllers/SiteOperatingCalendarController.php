<?php

namespace App\Http\Controllers;

use App\Services\SiteOperatingCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class SiteOperatingCalendarController extends Controller
{
    public function __construct(
        private readonly SiteOperatingCalendarService $calendar
    ) {
    }

    public function show(Request $request, int $siteId): JsonResponse
    {
        $orgId = $this->orgId($request);

        try {
            $firstEffectiveFrom = $this->calendar->getFirstEffectiveFrom(
                $orgId,
                $siteId
            );

            $weeklyConfigurations = DB::table('y_site_operating_weekly_rules')
                ->where('org_id', $orgId)
                ->where('site_id', $siteId)
                ->orderBy('effective_from')
                ->orderBy('weekday')
                ->get()
                ->groupBy('effective_from')
                ->map(function ($rules, $effectiveFrom) {
                    return [
                        'effective_from' => $effectiveFrom,
                        'days' => $rules->map(function ($rule) {
                            return [
                                'id' => (int) $rule->id,
                                'weekday' => (int) $rule->weekday,
                                'is_open' => (bool) $rule->is_open,
                            ];
                        })->values(),
                    ];
                })
                ->values();

            $exceptions = DB::table('y_site_operating_date_exceptions')
                ->where('org_id', $orgId)
                ->where('site_id', $siteId)
                ->orderBy('exception_date')
                ->get()
                ->map(function ($exception) {
                    return [
                        'id' => (int) $exception->id,
                        'date' => $exception->exception_date,
                        'is_open' => (bool) $exception->is_open,
                        'reason' => $exception->reason,
                    ];
                })
                ->values();

            return response()->json([
                'ok' => true,
                'org_id' => $orgId,
                'site_id' => $siteId,
                'first_effective_from' => $firstEffectiveFrom,
                'weekly_configurations' => $weeklyConfigurations,
                'exceptions' => $exceptions,
            ]);
        } catch (ModelNotFoundException) {
            return $this->siteNotFoundResponse();
        } catch (RuntimeException) {
            return $this->calendarDataInconsistentResponse();
        }
    }

    public function days(Request $request, int $siteId): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        $from = $validated['from'];
        $to = $validated['to'];

        if ($from > $to) {
            return response()->json([
                'error' => 'invalid_date_range',
                'message' => 'La date de début doit être antérieure ou égale à la date de fin.',
            ], 422);
        }

        $fromDate = CarbonImmutable::createFromFormat('!Y-m-d', $from);
        $toDate = CarbonImmutable::createFromFormat('!Y-m-d', $to);

        if ($fromDate->diffInDays($toDate) + 1 > 366) {
            return response()->json([
                'error' => 'date_range_too_large',
                'message' => 'La plage de dates ne peut pas dépasser 366 jours.',
            ], 422);
        }

        try {
            $orgId = $this->orgId($request);

            return response()->json([
                'ok' => true,
                'org_id' => $orgId,
                'site_id' => $siteId,
                'from' => $from,
                'to' => $to,
                'days' => $this->calendar->resolveDateRange(
                    $orgId,
                    $siteId,
                    $from,
                    $to
                ),
            ]);
        } catch (ModelNotFoundException) {
            return $this->siteNotFoundResponse();
        } catch (InvalidArgumentException $exception) {
            return $this->validationErrorResponse($exception);
        } catch (RuntimeException) {
            return $this->calendarDataInconsistentResponse();
        }
    }

    public function createWeeklyConfiguration(
        Request $request,
        int $siteId
    ): JsonResponse {
        $validated = $request->validate([
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'days' => ['required', 'array'],
            'days.*.weekday' => ['required', 'integer'],
            'days.*.is_open' => ['required', 'boolean'],
        ]);

        try {
            $orgId = $this->orgId($request);

            return response()->json([
                'ok' => true,
                'configuration' => $this->calendar->createWeeklyConfiguration(
                    $orgId,
                    $siteId,
                    $validated['effective_from'],
                    $validated['days']
                ),
            ], 201);
        } catch (ModelNotFoundException) {
            return $this->siteNotFoundResponse();
        } catch (InvalidArgumentException $exception) {
            return $this->validationErrorResponse($exception);
        } catch (RuntimeException) {
            return $this->calendarDataInconsistentResponse();
        }
    }

    public function upsertException(
        Request $request,
        int $siteId,
        string $date
    ): JsonResponse {
        $date = $this->validateDate($date);

        $validated = $request->validate([
            'is_open' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $orgId = $this->orgId($request);

            return response()->json([
                'ok' => true,
                'exception' => $this->calendar->upsertDateException(
                    $orgId,
                    $siteId,
                    $date,
                    (bool) $validated['is_open'],
                    $validated['reason'] ?? null
                ),
            ]);
        } catch (ModelNotFoundException) {
            return $this->siteNotFoundResponse();
        } catch (InvalidArgumentException $exception) {
            return $this->validationErrorResponse($exception);
        } catch (RuntimeException) {
            return $this->calendarDataInconsistentResponse();
        }
    }

    public function deleteException(int $siteId, string $date, Request $request): JsonResponse
    {
        $date = $this->validateDate($date);

        try {
            $this->calendar->deleteDateException(
                $this->orgId($request),
                $siteId,
                $date
            );

            return response()->json([
                'ok' => true,
            ]);
        } catch (ModelNotFoundException) {
            return $this->siteNotFoundResponse();
        } catch (InvalidArgumentException $exception) {
            return $this->validationErrorResponse($exception);
        } catch (RuntimeException) {
            return $this->calendarDataInconsistentResponse();
        }
    }

    private function orgId(Request $request): int
    {
        return (int) $request->query(
            'org_id',
            (int) config('tempo.default_org_id')
        );
    }

    private function validateDate(string $date): string
    {
        return Validator::make(
            ['date' => $date],
            ['date' => ['required', 'date_format:Y-m-d']]
        )->validate()['date'];
    }

    private function siteNotFoundResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'site_not_found',
            'message' => 'Le site demandé est introuvable pour cette organisation.',
        ], 404);
    }

    private function validationErrorResponse(
        InvalidArgumentException $exception
    ): JsonResponse {
        return response()->json([
            'error' => 'calendar_validation_failed',
            'message' => $exception->getMessage(),
        ], 422);
    }

    private function calendarDataInconsistentResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'calendar_data_inconsistent',
            'message' => 'Les données du calendrier sont incohérentes.',
        ], 500);
    }
}
