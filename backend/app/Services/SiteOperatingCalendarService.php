<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class SiteOperatingCalendarService
{
    /**
     * Resolve the operating-calendar state for one explicit date.
     *
     * @return array{
     *     date: string,
     *     is_configured: bool,
     *     is_open: bool|null,
     *     source: 'exception'|'weekly_rule'|'unconfigured',
     *     reason: string|null,
     *     weekly_rule_id: int|null,
     *     exception_id: int|null
     * }
     */
    public function resolveDate(int $orgId, int $siteId, string $date): array
    {
        $date = $this->normalizeDate($date);

        $this->findScopedSite($orgId, $siteId);

        return $this->resolveDateForScopedSite($orgId, $siteId, $date);
    }

    /**
     * Resolve every date in an inclusive explicit date range.
     *
     * @return array<int, array{
     *     date: string,
     *     is_configured: bool,
     *     is_open: bool|null,
     *     source: 'exception'|'weekly_rule'|'unconfigured',
     *     reason: string|null,
     *     weekly_rule_id: int|null,
     *     exception_id: int|null
     * }>
     */
    public function resolveDateRange(
        int $orgId,
        int $siteId,
        string $from,
        string $to
    ): array {
        $from = $this->normalizeDate($from);
        $to = $this->normalizeDate($to);

        if ($from > $to) {
            throw new InvalidArgumentException(
                'La date de début doit être antérieure ou égale à la date de fin.'
            );
        }

        $this->findScopedSite($orgId, $siteId);

        $resolvedDates = [];
        $currentDate = CarbonImmutable::createFromFormat('!Y-m-d', $from);
        $endDate = CarbonImmutable::createFromFormat('!Y-m-d', $to);

        while ($currentDate->lessThanOrEqualTo($endDate)) {
            $resolvedDates[] = $this->resolveDateForScopedSite(
                $orgId,
                $siteId,
                $currentDate->toDateString()
            );

            $currentDate = $currentDate->addDay();
        }

        return $resolvedDates;
    }

    public function getFirstEffectiveFrom(int $orgId, int $siteId): ?string
    {
        $this->findScopedSite($orgId, $siteId);

        return $this->getFirstEffectiveFromForScopedSite($orgId, $siteId);
    }

    /**
     * Create one complete, append-only weekly configuration.
     *
     * @param array<int, array{weekday: mixed, is_open: mixed}> $days
     * @return array{
     *     org_id: int,
     *     site_id: int,
     *     effective_from: string,
     *     days: array<int, array{weekday: int, is_open: bool}>
     * }
     */
    public function createWeeklyConfiguration(
        int $orgId,
        int $siteId,
        string $effectiveFrom,
        array $days
    ): array {
        $effectiveFrom = $this->normalizeDate($effectiveFrom);
        $days = $this->validateWeeklyDays($days);

        return DB::transaction(function () use (
            $orgId,
            $siteId,
            $effectiveFrom,
            $days
        ) {
            $this->findScopedSite($orgId, $siteId, true);

            $lastEffectiveFrom = DB::table('y_site_operating_weekly_rules')
                ->where('org_id', $orgId)
                ->where('site_id', $siteId)
                ->max('effective_from');

            if ($lastEffectiveFrom !== null && $effectiveFrom <= $lastEffectiveFrom) {
                throw new InvalidArgumentException(
                    'La date d’effet doit être strictement postérieure à la dernière configuration hebdomadaire.'
                );
            }

            $now = now('UTC');

            $rows = array_map(
                fn (array $day) => [
                    'org_id' => $orgId,
                    'site_id' => $siteId,
                    'weekday' => $day['weekday'],
                    'effective_from' => $effectiveFrom,
                    'is_open' => $day['is_open'] ? 1 : 0,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_by' => null,
                    'updated_at' => $now,
                ],
                $days
            );

            DB::table('y_site_operating_weekly_rules')->insert($rows);

            return [
                'org_id' => $orgId,
                'site_id' => $siteId,
                'effective_from' => $effectiveFrom,
                'days' => $days,
            ];
        });
    }

    /**
     * Create or update one dated exception after calendar activation.
     *
     * @return array{
     *     date: string,
     *     is_configured: true,
     *     is_open: bool,
     *     source: 'exception',
     *     reason: string|null,
     *     weekly_rule_id: null,
     *     exception_id: int
     * }
     */
    public function upsertDateException(
        int $orgId,
        int $siteId,
        string $date,
        bool $isOpen,
        ?string $reason
    ): array {
        $date = $this->normalizeDate($date);

        return DB::transaction(function () use (
            $orgId,
            $siteId,
            $date,
            $isOpen,
            $reason
        ) {
            $this->findScopedSite($orgId, $siteId, true);

            $firstEffectiveFrom = $this->getFirstEffectiveFromForScopedSite(
                $orgId,
                $siteId
            );

            $this->assertDateIsWithinCalendarActivation(
                $date,
                $firstEffectiveFrom
            );

            $existingException = DB::table('y_site_operating_date_exceptions')
                ->where('org_id', $orgId)
                ->where('site_id', $siteId)
                ->where('exception_date', $date)
                ->first();

            $now = now('UTC');

            if ($existingException) {
                DB::table('y_site_operating_date_exceptions')
                    ->where('id', $existingException->id)
                    ->where('org_id', $orgId)
                    ->where('site_id', $siteId)
                    ->update([
                        'is_open' => $isOpen ? 1 : 0,
                        'reason' => $reason,
                        'updated_by' => null,
                        'updated_at' => $now,
                    ]);

                $exceptionId = (int) $existingException->id;
            } else {
                $exceptionId = DB::table('y_site_operating_date_exceptions')
                    ->insertGetId([
                        'org_id' => $orgId,
                        'site_id' => $siteId,
                        'exception_date' => $date,
                        'is_open' => $isOpen ? 1 : 0,
                        'reason' => $reason,
                        'created_by' => null,
                        'created_at' => $now,
                        'updated_by' => null,
                        'updated_at' => $now,
                    ]);
            }

            return [
                'date' => $date,
                'is_configured' => true,
                'is_open' => $isOpen,
                'source' => 'exception',
                'reason' => $reason,
                'weekly_rule_id' => null,
                'exception_id' => (int) $exceptionId,
            ];
        });
    }

    /**
     * Delete one dated exception after calendar activation.
     */
    public function deleteDateException(
        int $orgId,
        int $siteId,
        string $date
    ): void {
        $date = $this->normalizeDate($date);

        DB::transaction(function () use ($orgId, $siteId, $date) {
            $this->findScopedSite($orgId, $siteId, true);

            $firstEffectiveFrom = $this->getFirstEffectiveFromForScopedSite(
                $orgId,
                $siteId
            );

            $this->assertDateIsWithinCalendarActivation(
                $date,
                $firstEffectiveFrom
            );

            DB::table('y_site_operating_date_exceptions')
                ->where('org_id', $orgId)
                ->where('site_id', $siteId)
                ->where('exception_date', $date)
                ->delete();
        });
    }

    /**
     * @return array{
     *     date: string,
     *     is_configured: bool,
     *     is_open: bool|null,
     *     source: 'exception'|'weekly_rule'|'unconfigured',
     *     reason: string|null,
     *     weekly_rule_id: int|null,
     *     exception_id: int|null
     * }
     */
    private function resolveDateForScopedSite(
        int $orgId,
        int $siteId,
        string $date
    ): array {
        $firstEffectiveFrom = $this->getFirstEffectiveFromForScopedSite(
            $orgId,
            $siteId
        );

        if ($firstEffectiveFrom === null || $date < $firstEffectiveFrom) {
            return $this->unconfiguredDate($date);
        }

        $exception = DB::table('y_site_operating_date_exceptions')
            ->where('org_id', $orgId)
            ->where('site_id', $siteId)
            ->where('exception_date', $date)
            ->first();

        if ($exception) {
            return [
                'date' => $date,
                'is_configured' => true,
                'is_open' => (bool) $exception->is_open,
                'source' => 'exception',
                'reason' => $exception->reason,
                'weekly_rule_id' => null,
                'exception_id' => (int) $exception->id,
            ];
        }

        $effectiveFrom = DB::table('y_site_operating_weekly_rules')
            ->where('org_id', $orgId)
            ->where('site_id', $siteId)
            ->where('effective_from', '<=', $date)
            ->max('effective_from');

        if ($effectiveFrom === null) {
            throw new RuntimeException(
                'Configuration hebdomadaire introuvable après activation du calendrier.'
            );
        }

        $rules = DB::table('y_site_operating_weekly_rules')
            ->where('org_id', $orgId)
            ->where('site_id', $siteId)
            ->where('effective_from', $effectiveFrom)
            ->orderBy('weekday')
            ->get();

        $rulesByWeekday = $this->validateCompleteWeeklyConfiguration(
            $rules->all(),
            $effectiveFrom
        );

        $weekday = CarbonImmutable::createFromFormat('!Y-m-d', $date)->dayOfWeekIso;
        $rule = $rulesByWeekday[$weekday];

        return [
            'date' => $date,
            'is_configured' => true,
            'is_open' => (bool) $rule->is_open,
            'source' => 'weekly_rule',
            'reason' => null,
            'weekly_rule_id' => (int) $rule->id,
            'exception_id' => null,
        ];
    }

    private function getFirstEffectiveFromForScopedSite(
        int $orgId,
        int $siteId
    ): ?string {
        return DB::table('y_site_operating_weekly_rules')
            ->where('org_id', $orgId)
            ->where('site_id', $siteId)
            ->min('effective_from');
    }

    private function assertDateIsWithinCalendarActivation(
        string $date,
        ?string $firstEffectiveFrom
    ): void {
        if ($firstEffectiveFrom === null) {
            throw new InvalidArgumentException(
                'Aucune configuration hebdomadaire n’existe encore pour ce site.'
            );
        }

        if ($date < $firstEffectiveFrom) {
            throw new InvalidArgumentException(
                'La date de l’exception ne peut pas précéder la première date d’effet du calendrier.'
            );
        }
    }

    /**
     * @param array<int, mixed> $days
     * @return array<int, array{weekday: int, is_open: bool}>
     */
    private function validateWeeklyDays(array $days): array
    {
        if (count($days) !== 7) {
            throw new InvalidArgumentException(
                'Une configuration hebdomadaire doit contenir exactement sept jours.'
            );
        }

        $daysByWeekday = [];

        foreach ($days as $day) {
            if (!is_array($day) || !array_key_exists('weekday', $day)
                || !array_key_exists('is_open', $day)) {
                throw new InvalidArgumentException(
                    'Chaque jour doit contenir weekday et is_open.'
                );
            }

            if (!is_int($day['weekday']) || $day['weekday'] < 1 || $day['weekday'] > 7) {
                throw new InvalidArgumentException(
                    'Chaque weekday doit être un entier compris entre 1 et 7.'
                );
            }

            if (!is_bool($day['is_open'])) {
                throw new InvalidArgumentException(
                    'La valeur is_open doit être booléenne.'
                );
            }

            if (array_key_exists($day['weekday'], $daysByWeekday)) {
                throw new InvalidArgumentException(
                    'Un weekday ne peut apparaître qu’une seule fois.'
                );
            }

            $daysByWeekday[$day['weekday']] = [
                'weekday' => $day['weekday'],
                'is_open' => $day['is_open'],
            ];
        }

        ksort($daysByWeekday);

        if (array_keys($daysByWeekday) !== [1, 2, 3, 4, 5, 6, 7]) {
            throw new InvalidArgumentException(
                'Les weekdays 1 à 7 doivent être présents exactement une fois.'
            );
        }

        return array_values($daysByWeekday);
    }

    /**
     * @param array<int, object> $rules
     * @return array<int, object>
     */
    private function validateCompleteWeeklyConfiguration(
        array $rules,
        string $effectiveFrom
    ): array {
        if (count($rules) !== 7) {
            throw new RuntimeException(
                "La configuration hebdomadaire du {$effectiveFrom} est incomplète ou incohérente."
            );
        }

        $rulesByWeekday = [];

        foreach ($rules as $rule) {
            $weekday = (int) $rule->weekday;

            if ($weekday < 1 || $weekday > 7 || array_key_exists($weekday, $rulesByWeekday)) {
                throw new RuntimeException(
                    "La configuration hebdomadaire du {$effectiveFrom} est incomplète ou incohérente."
                );
            }

            $rulesByWeekday[$weekday] = $rule;
        }

        if (array_keys($rulesByWeekday) !== [1, 2, 3, 4, 5, 6, 7]) {
            throw new RuntimeException(
                "La configuration hebdomadaire du {$effectiveFrom} est incomplète ou incohérente."
            );
        }

        return $rulesByWeekday;
    }

    /**
     * @return array{
     *     date: string,
     *     is_configured: false,
     *     is_open: null,
     *     source: 'unconfigured',
     *     reason: null,
     *     weekly_rule_id: null,
     *     exception_id: null
     * }
     */
    private function unconfiguredDate(string $date): array
    {
        return [
            'date' => $date,
            'is_configured' => false,
            'is_open' => null,
            'source' => 'unconfigured',
            'reason' => null,
            'weekly_rule_id' => null,
            'exception_id' => null,
        ];
    }

    private function findScopedSite(
        int $orgId,
        int $siteId,
        bool $lockForUpdate = false
    ): object {
        $query = DB::table('y_sites')
            ->where('id', $siteId)
            ->where('org_id', $orgId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $site = $query->first();

        if (!$site) {
            $exception = new ModelNotFoundException();
            $exception->setModel('y_sites', [$siteId]);

            throw $exception;
        }

        return $site;
    }

    private function normalizeDate(string $date): string
    {
        try {
            $parsedDate = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            throw new InvalidArgumentException(
                'La date doit respecter strictement le format YYYY-MM-DD.'
            );
        }

        if ($parsedDate === false || $parsedDate->toDateString() !== $date) {
            throw new InvalidArgumentException(
                'La date doit respecter strictement le format YYYY-MM-DD.'
            );
        }

        return $parsedDate->toDateString();
    }
}
