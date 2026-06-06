import { useEffect, useMemo, useState } from "react";
import { apiGet } from "../api";
import type { Assignment, Roster, Shift } from "../types/planning";

type CoverageInterval = {
  dateKey: string;
  startsAt: string;
  endsAt: string;
  requiredCount: number;
  assignedCount: number;
  delta: number;
  statusLabel: string;
  statusColor: string;
  activeShiftIds: number[];
};

function parseSqlDateTime(dateTime: string) {
  return new Date(dateTime.replace(" ", "T"));
}

function getTimeValue(dateTime: string) {
  return parseSqlDateTime(dateTime).getTime();
}

function getDateKey(dateTime: string) {
  return dateTime.slice(0, 10);
}

function formatDate(dateTime: string) {
  return new Intl.DateTimeFormat("fr-FR", {
    weekday: "long",
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  }).format(parseSqlDateTime(dateTime));
}

function formatTime(dateTime: string) {
  return new Intl.DateTimeFormat("fr-FR", {
    hour: "2-digit",
    minute: "2-digit",
  }).format(parseSqlDateTime(dateTime));
}

function getCoverageStatus(delta: number) {
  if (delta < 0) {
    return {
      label: "Sous-couvert",
      color: "#b45309",
    };
  }

  if (delta > 0) {
    return {
      label: "Sur-couvert",
      color: "#2563eb",
    };
  }

  return {
    label: "Couverture OK",
    color: "#166534",
  };
}

function getAssignmentsByShiftId(assignments: Assignment[]) {
  const grouped: Record<number, Assignment[]> = {};

  for (const assignment of assignments) {
    if (!grouped[assignment.shift_id]) {
      grouped[assignment.shift_id] = [];
    }

    grouped[assignment.shift_id].push(assignment);
  }

  return grouped;
}

function buildCoverageIntervals(
  shifts: Shift[],
  assignments: Assignment[],
): CoverageInterval[] {
  const assignmentsByShiftId = getAssignmentsByShiftId(assignments);
  const shiftsByDate: Record<string, Shift[]> = {};

  for (const shift of shifts) {
    const dateKey = getDateKey(shift.starts_at);

    if (!shiftsByDate[dateKey]) {
      shiftsByDate[dateKey] = [];
    }

    shiftsByDate[dateKey].push(shift);
  }

  const intervals: CoverageInterval[] = [];

  for (const [dateKey, dayShifts] of Object.entries(shiftsByDate)) {
    const boundaries = Array.from(
      new Set(
        dayShifts.flatMap((shift) => [
          getTimeValue(shift.starts_at),
          getTimeValue(shift.ends_at),
        ]),
      ),
    ).sort((a, b) => a - b);

    for (let index = 0; index < boundaries.length - 1; index += 1) {
      const intervalStart = boundaries[index];
      const intervalEnd = boundaries[index + 1];

      const activeShifts = dayShifts.filter((shift) => {
        const shiftStart = getTimeValue(shift.starts_at);
        const shiftEnd = getTimeValue(shift.ends_at);

        return shiftStart <= intervalStart && shiftEnd >= intervalEnd;
      });

      if (activeShifts.length === 0) {
        continue;
      }

      const requiredCount = activeShifts.reduce(
        (sum, shift) => sum + shift.required_count,
        0,
      );

      // MVP rule:
      // If the same worker appears on multiple active shifts during the same interval,
      // count the worker only once for this interval.
      const assignedWorkerIds = new Set<number>();

      for (const shift of activeShifts) {
        const shiftAssignments = assignmentsByShiftId[shift.id] ?? [];

        for (const assignment of shiftAssignments) {
          assignedWorkerIds.add(assignment.worker_id);
        }
      }

      const assignedCount = assignedWorkerIds.size;
      const delta = assignedCount - requiredCount;
      const status = getCoverageStatus(delta);

      intervals.push({
        dateKey,
        startsAt: new Date(intervalStart).toISOString(),
        endsAt: new Date(intervalEnd).toISOString(),
        requiredCount,
        assignedCount,
        delta,
        statusLabel: status.label,
        statusColor: status.color,
        activeShiftIds: activeShifts.map((shift) => shift.id),
      });
    }
  }

  return intervals.sort((a, b) => {
    if (a.dateKey !== b.dateKey) {
      return a.dateKey.localeCompare(b.dateKey);
    }

    return getTimeValue(a.startsAt) - getTimeValue(b.startsAt);
  });
}

export default function CoveragePage() {
  const [rosters, setRosters] = useState<Roster[]>([]);
  const [selectedRosterId, setSelectedRosterId] = useState<number | null>(null);
  const [shifts, setShifts] = useState<Shift[]>([]);
  const [assignments, setAssignments] = useState<Assignment[]>([]);
  const [error, setError] = useState<string | null>(null);

  const selectedRoster =
    rosters.find((roster) => roster.id === selectedRosterId) ?? null;

  const coverageIntervalsByDate = useMemo(() => {
    const intervals = buildCoverageIntervals(shifts, assignments);
    const grouped: Record<string, CoverageInterval[]> = {};

    for (const interval of intervals) {
      if (!grouped[interval.dateKey]) {
        grouped[interval.dateKey] = [];
      }

      grouped[interval.dateKey].push(interval);
    }

    return Object.entries(grouped).sort(([dateA], [dateB]) =>
      dateA.localeCompare(dateB),
    );
  }, [assignments, shifts]);

  useEffect(() => {
    async function loadRosters() {
      try {
        setError(null);

        const rostersData = await apiGet<Roster[]>("/rosters");

        setRosters(rostersData);

        if (rostersData.length > 0) {
          setSelectedRosterId(rostersData[0].id);
        }
      } catch (e: unknown) {
        const message = e instanceof Error ? e.message : "Erreur API";
        setError(message);
      }
    }

    loadRosters();
  }, []);

  useEffect(() => {
    async function loadCoverageData() {
      try {
        setError(null);

        if (!selectedRosterId) {
          return;
        }

        const [shiftsData, assignmentsData] = await Promise.all([
          apiGet<Shift[]>(`/rosters/${selectedRosterId}/shifts`),
          apiGet<Assignment[]>(`/rosters/${selectedRosterId}/assignments`),
        ]);

        setShifts(shiftsData);
        setAssignments(assignmentsData);
      } catch (e: unknown) {
        const message = e instanceof Error ? e.message : "Erreur API";
        setError(message);
      }
    }

    loadCoverageData();
  }, [selectedRosterId]);

  return (
    <div>
      <h1>Couverture</h1>

      <p>
        Vue analytique des besoins et assignations. Cette version calcule des
        tranches horaires à partir des shifts du roster.
      </p>

      {error && <p style={{ color: "red" }}>Erreur : {error}</p>}

      <div style={{ marginBottom: 16 }}>
        <label htmlFor="roster-select">
          <strong>Roster : </strong>
        </label>

        <select
          id="roster-select"
          value={selectedRosterId ?? ""}
          onChange={(event) => setSelectedRosterId(Number(event.target.value))}
        >
          {rosters.map((roster) => (
            <option key={roster.id} value={roster.id}>
              {roster.site_name} — {roster.period_start} au {roster.period_end}
            </option>
          ))}
        </select>
      </div>

      {selectedRoster && (
        <p>
          Site : <strong>{selectedRoster.site_name}</strong> — status :{" "}
          {selectedRoster.status}
        </p>
      )}

      {coverageIntervalsByDate.length === 0 && !error && (
        <p>Aucune donnée de couverture trouvée.</p>
      )}

      <div style={{ display: "grid", gap: 20 }}>
        {coverageIntervalsByDate.map(([dateKey, intervals]) => (
          <section key={dateKey}>
            <h2>{formatDate(intervals[0].startsAt)}</h2>

            <div style={{ overflowX: "auto" }}>
              <table
                style={{
                  width: "100%",
                  borderCollapse: "collapse",
                  background: "white",
                }}
              >
                <thead>
                  <tr>
                    <th style={thStyle}>Tranche calculée</th>
                    <th style={thStyle}>Besoin</th>
                    <th style={thStyle}>Assignés uniques</th>
                    <th style={thStyle}>Écart</th>
                    <th style={thStyle}>Statut</th>
                    <th style={thStyle}>Shifts actifs</th>
                  </tr>
                </thead>

                <tbody>
                  {intervals.map((interval) => (
                    <tr
                      key={`${interval.dateKey}-${interval.startsAt}-${interval.endsAt}`}
                    >
                      <td style={tdStyle}>
                        {formatTime(interval.startsAt)} →{" "}
                        {formatTime(interval.endsAt)}
                      </td>

                      <td style={tdStyle}>{interval.requiredCount}</td>

                      <td style={tdStyle}>{interval.assignedCount}</td>

                      <td style={tdStyle}>
                        {interval.delta > 0
                          ? `+${interval.delta}`
                          : interval.delta}
                      </td>

                      <td
                        style={{
                          ...tdStyle,
                          color: interval.statusColor,
                          fontWeight: 600,
                        }}
                      >
                        {interval.statusLabel}
                      </td>

                      <td style={tdStyle}>
                        #{interval.activeShiftIds.join(", #")}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        ))}
      </div>

      {/* TODO:
       * Next iterations:
       * - compare calculated need against a future individual employee planning model
       * - show worker names per interval
       * - add daily totals and weekly volume
       * - move this calculation backend-side when it becomes a core business rule
       */}
    </div>
  );
}

const thStyle = {
  textAlign: "left",
  border: "1px solid #e5e7eb",
  padding: 8,
} satisfies React.CSSProperties;

const tdStyle = {
  border: "1px solid #e5e7eb",
  padding: 8,
} satisfies React.CSSProperties;
