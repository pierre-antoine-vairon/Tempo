import { useEffect, useMemo, useState } from "react";
import { apiGet } from "../api";
import type { Assignment, Roster, Shift } from "../types/planning";

type CoverageRow = {
  shift: Shift;
  assignedCount: number;
  delta: number;
  statusLabel: string;
  statusColor: string;
};

function parseSqlDateTime(dateTime: string) {
  return new Date(dateTime.replace(" ", "T"));
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

export default function CoveragePage() {
  const [rosters, setRosters] = useState<Roster[]>([]);
  const [selectedRosterId, setSelectedRosterId] = useState<number | null>(null);
  const [shifts, setShifts] = useState<Shift[]>([]);
  const [assignments, setAssignments] = useState<Assignment[]>([]);
  const [error, setError] = useState<string | null>(null);

  const selectedRoster =
    rosters.find((roster) => roster.id === selectedRosterId) ?? null;

  const assignmentsByShiftId = useMemo(() => {
    const grouped: Record<number, Assignment[]> = {};

    for (const assignment of assignments) {
      if (!grouped[assignment.shift_id]) {
        grouped[assignment.shift_id] = [];
      }

      grouped[assignment.shift_id].push(assignment);
    }

    return grouped;
  }, [assignments]);

  const coverageRowsByDate = useMemo(() => {
    const grouped: Record<string, CoverageRow[]> = {};

    for (const shift of shifts) {
      const assignedCount = assignmentsByShiftId[shift.id]?.length ?? 0;
      const delta = assignedCount - shift.required_count;
      const status = getCoverageStatus(delta);
      const dateKey = getDateKey(shift.starts_at);

      if (!grouped[dateKey]) {
        grouped[dateKey] = [];
      }

      grouped[dateKey].push({
        shift,
        assignedCount,
        delta,
        statusLabel: status.label,
        statusColor: status.color,
      });
    }

    return Object.entries(grouped).sort(([dateA], [dateB]) =>
      dateA.localeCompare(dateB),
    );
  }, [assignmentsByShiftId, shifts]);

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
        Vue analytique des besoins et assignations. Cette première version
        compare chaque shift avec le nombre d’employés assignés.
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

      {coverageRowsByDate.length === 0 && !error && (
        <p>Aucune donnée de couverture trouvée.</p>
      )}

      <div style={{ display: "grid", gap: 20 }}>
        {coverageRowsByDate.map(([dateKey, rows]) => (
          <section key={dateKey}>
            <h2>{formatDate(rows[0].shift.starts_at)}</h2>

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
                    <th style={thStyle}>Tranche / shift</th>
                    <th style={thStyle}>Besoin</th>
                    <th style={thStyle}>Assignés</th>
                    <th style={thStyle}>Écart</th>
                    <th style={thStyle}>Statut</th>
                  </tr>
                </thead>

                <tbody>
                  {rows.map((row) => (
                    <tr key={row.shift.id}>
                      <td style={tdStyle}>
                        {formatTime(row.shift.starts_at)} →{" "}
                        {formatTime(row.shift.ends_at)}
                      </td>

                      <td style={tdStyle}>{row.shift.required_count}</td>

                      <td style={tdStyle}>{row.assignedCount}</td>

                      <td style={tdStyle}>
                        {row.delta > 0 ? `+${row.delta}` : row.delta}
                      </td>

                      <td
                        style={{
                          ...tdStyle,
                          color: row.statusColor,
                          fontWeight: 600,
                        }}
                      >
                        {row.statusLabel}
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
       * Future version:
       * - split overlapping shifts into calculated coverage intervals
       * - compare theoretical need vs real employee planning
       * - show under-covered and over-covered periods independently from shifts
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
