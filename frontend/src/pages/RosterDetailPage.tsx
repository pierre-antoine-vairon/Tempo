import { useEffect, useMemo, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { apiGet, apiPut } from "../api";
import ShiftCard from "../components/ShiftCard";
import type { Assignment, Roster, Shift, Worker } from "../types/planning";

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

function formatShortDate(date: string) {
  return new Intl.DateTimeFormat("fr-FR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  }).format(new Date(date));
}

export default function RosterDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [roster, setRoster] = useState<Roster | null>(null);
  const [shifts, setShifts] = useState<Shift[]>([]);
  const [assignments, setAssignments] = useState<Assignment[]>([]);
  const [workers, setWorkers] = useState<Worker[]>([]);
  const [error, setError] = useState<string | null>(null);

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

  const shiftsByDate = useMemo(() => {
    const grouped: Record<string, Shift[]> = {};

    for (const shift of shifts) {
      const dateKey = getDateKey(shift.starts_at);

      if (!grouped[dateKey]) {
        grouped[dateKey] = [];
      }

      grouped[dateKey].push(shift);
    }

    return Object.entries(grouped).sort(([dateA], [dateB]) =>
      dateA.localeCompare(dateB),
    );
  }, [shifts]);

  useEffect(() => {
    async function loadRosterDetails() {
      try {
        setError(null);

        if (!id) {
          setError("Roster introuvable");
          return;
        }

        const [rosterData, shiftsData, assignmentsData, workersData] =
          await Promise.all([
            apiGet<Roster>(`/rosters/${id}`),
            apiGet<Shift[]>(`/rosters/${id}/shifts`),
            apiGet<Assignment[]>(`/rosters/${id}/assignments`),
            apiGet<Worker[]>(`/workers`),
          ]);

        setRoster(rosterData);
        setShifts(shiftsData);
        setAssignments(assignmentsData);
        setWorkers(workersData);
      } catch (e: unknown) {
        const message = e instanceof Error ? e.message : "Erreur API";
        setError(message);
      }
    }

    loadRosterDetails();
  }, [id]);

  async function handleSaveShiftAssignments(
    shiftId: number,
    workerIds: number[],
  ) {
    const updatedAssignments = await apiPut<
      Assignment[],
      { worker_ids: number[] }
    >(`/shifts/${shiftId}/assignments`, {
      worker_ids: workerIds,
    });

    setAssignments((currentAssignments) => [
      ...currentAssignments.filter(
        (assignment) => assignment.shift_id !== shiftId,
      ),
      ...updatedAssignments,
    ]);
  }

  return (
    <div>
      <Link to="/rosters">← Retour aux rosters</Link>

      <h1>Planning — {roster ? roster.site_name : `Roster #${id}`}</h1>

      {roster && (
        <p>
          Semaine du {formatShortDate(roster.period_start)} au{" "}
          {formatShortDate(roster.period_end)} — status : {roster.status}
        </p>
      )}

      {error && <p style={{ color: "red" }}>Erreur : {error}</p>}

      {shifts.length === 0 && !error && <p>Aucun shift trouvé.</p>}

      <div style={{ display: "grid", gap: 16 }}>
        {shiftsByDate.map(([dateKey, dayShifts]) => (
          <section key={dateKey}>
            <h2 style={{ marginBottom: 8 }}>
              {formatDate(dayShifts[0].starts_at)}
            </h2>

            <div style={{ display: "grid", gap: 8 }}>
              {dayShifts.map((shift) => (
                <ShiftCard
                  key={shift.id}
                  shift={shift}
                  assignments={assignmentsByShiftId[shift.id] ?? []}
                  workers={workers}
                  onSaveAssignments={handleSaveShiftAssignments}
                />
              ))}
            </div>
          </section>
        ))}
      </div>
    </div>
  );
}
