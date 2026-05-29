import { useEffect, useMemo, useState } from "react";
import { apiGet } from "../api";
import type { Assignment, Roster, Shift } from "../types/planning";

type Worker = {
  id: number;
  org_id: number;
  site_id: number | null;
  first_name: string;
  last_name: string;
};

function parseSqlDateTime(dateTime: string) {
  return new Date(dateTime.replace(" ", "T"));
}

function getDateKey(dateTime: string) {
  return dateTime.slice(0, 10);
}

function formatDayLabel(dateKey: string) {
  return new Intl.DateTimeFormat("fr-FR", {
    weekday: "short",
    day: "2-digit",
    month: "2-digit",
  }).format(new Date(dateKey));
}

function formatTime(dateTime: string) {
  return new Intl.DateTimeFormat("fr-FR", {
    hour: "2-digit",
    minute: "2-digit",
  }).format(parseSqlDateTime(dateTime));
}

export default function PlanningPage() {
  const [rosters, setRosters] = useState<Roster[]>([]);
  const [workers, setWorkers] = useState<Worker[]>([]);
  const [shifts, setShifts] = useState<Shift[]>([]);
  const [assignments, setAssignments] = useState<Assignment[]>([]);
  const [selectedRosterId, setSelectedRosterId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const selectedRoster =
    rosters.find((roster) => roster.id === selectedRosterId) ?? null;

  const shiftsById = useMemo(() => {
    const map: Record<number, Shift> = {};

    for (const shift of shifts) {
      map[shift.id] = shift;
    }

    return map;
  }, [shifts]);

  const dateKeys = useMemo(() => {
    const uniqueDates = new Set<string>();

    for (const shift of shifts) {
      uniqueDates.add(getDateKey(shift.starts_at));
    }

    return Array.from(uniqueDates).sort();
  }, [shifts]);

  const assignmentsByWorkerAndDate = useMemo(() => {
    const grouped: Record<number, Record<string, Assignment[]>> = {};

    for (const assignment of assignments) {
      const shift = shiftsById[assignment.shift_id];

      if (!shift) {
        continue;
      }

      const dateKey = getDateKey(shift.starts_at);

      if (!grouped[assignment.worker_id]) {
        grouped[assignment.worker_id] = {};
      }

      if (!grouped[assignment.worker_id][dateKey]) {
        grouped[assignment.worker_id][dateKey] = [];
      }

      grouped[assignment.worker_id][dateKey].push(assignment);
    }

    return grouped;
  }, [assignments, shiftsById]);

  useEffect(() => {
    async function loadInitialData() {
      try {
        setError(null);

        const [rostersData, workersData] = await Promise.all([
          apiGet<Roster[]>("/rosters"),
          apiGet<Worker[]>("/workers"),
        ]);

        setRosters(rostersData);
        setWorkers(workersData);

        if (rostersData.length > 0) {
          setSelectedRosterId(rostersData[0].id);
        }
      } catch (e: unknown) {
        const message = e instanceof Error ? e.message : "Erreur API";
        setError(message);
      }
    }

    loadInitialData();
  }, []);

  useEffect(() => {
    async function loadRosterPlanning() {
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

    loadRosterPlanning();
  }, [selectedRosterId]);

  return (
    <div>
      <h1>Planning par employé</h1>

      <p>
        Vue lecture seule construite à partir des rosters, shifts, assignments
        et workers existants.
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
              <th
                style={{
                  textAlign: "left",
                  border: "1px solid #e5e7eb",
                  padding: 8,
                  minWidth: 180,
                }}
              >
                Employé
              </th>

              {dateKeys.map((dateKey) => (
                <th
                  key={dateKey}
                  style={{
                    textAlign: "left",
                    border: "1px solid #e5e7eb",
                    padding: 8,
                    minWidth: 140,
                  }}
                >
                  {formatDayLabel(dateKey)}
                </th>
              ))}
            </tr>
          </thead>

          <tbody>
            {workers.map((worker) => (
              <tr key={worker.id}>
                <td
                  style={{
                    border: "1px solid #e5e7eb",
                    padding: 8,
                    fontWeight: 600,
                  }}
                >
                  {worker.first_name} {worker.last_name}
                </td>

                {dateKeys.map((dateKey) => {
                  const workerAssignments =
                    assignmentsByWorkerAndDate[worker.id]?.[dateKey] ?? [];

                  return (
                    <td
                      key={dateKey}
                      style={{
                        border: "1px solid #e5e7eb",
                        padding: 8,
                        verticalAlign: "top",
                      }}
                    >
                      {workerAssignments.length === 0 ? (
                        <span style={{ color: "#94a3b8" }}>
                          Repos / non assigné
                        </span>
                      ) : (
                        <div style={{ display: "grid", gap: 4 }}>
                          {workerAssignments.map((assignment) => {
                            const shift = shiftsById[assignment.shift_id];

                            if (!shift) {
                              return null;
                            }

                            return (
                              <div key={assignment.id}>
                                {formatTime(shift.starts_at)} →{" "}
                                {formatTime(shift.ends_at)}
                              </div>
                            );
                          })}
                        </div>
                      )}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
