import { useEffect, useState } from "react";
import type { Assignment, Shift, Worker } from "../types/planning";

type ShiftCardProps = {
  shift: Shift;
  assignments: Assignment[];
  workers: Worker[];
  onSaveAssignments: (shiftId: number, workerIds: number[]) => Promise<void>;
};

function parseSqlDateTime(dateTime: string) {
  return new Date(dateTime.replace(" ", "T"));
}

function formatTime(dateTime: string) {
  return new Intl.DateTimeFormat("fr-FR", {
    hour: "2-digit",
    minute: "2-digit",
  }).format(parseSqlDateTime(dateTime));
}

export default function ShiftCard({
  shift,
  assignments,
  workers,
  onSaveAssignments,
}: ShiftCardProps) {
  const [isEditing, setIsEditing] = useState(false);
  const [selectedWorkerIds, setSelectedWorkerIds] = useState<number[]>([]);
  const [isSaving, setIsSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  const assignedCount = assignments.length;
  const isFullyStaffed = assignedCount >= shift.required_count;

  useEffect(() => {
    if (!isEditing) {
      setSelectedWorkerIds(
        assignments.map((assignment) => assignment.worker_id),
      );
    }
  }, [assignments, isEditing]);

  function toggleWorker(workerId: number) {
    setSelectedWorkerIds((currentIds) => {
      if (currentIds.includes(workerId)) {
        return currentIds.filter((id) => id !== workerId);
      }

      return [...currentIds, workerId];
    });
  }

  async function handleSave() {
    try {
      setIsSaving(true);
      setSaveError(null);

      await onSaveAssignments(shift.id, selectedWorkerIds);

      setIsEditing(false);
    } catch (e: unknown) {
      const message = e instanceof Error ? e.message : "Erreur sauvegarde";
      setSaveError(message);
    } finally {
      setIsSaving(false);
    }
  }

  function handleCancel() {
    setSelectedWorkerIds(assignments.map((assignment) => assignment.worker_id));
    setSaveError(null);
    setIsEditing(false);
  }

  return (
    <div
      style={{
        padding: 12,
        border: "1px solid #e5e7eb",
        borderRadius: 8,
        background: "white",
      }}
    >
      <div>
        <strong>
          {formatTime(shift.starts_at)} → {formatTime(shift.ends_at)}
        </strong>
      </div>

      <div>
        {assignedCount}/{shift.required_count} assignés — status: {shift.status}
      </div>

      {/* MVP visual helper only: real staffing validation must stay backend-side later. */}
      <div
        style={{
          marginTop: 4,
          fontSize: 14,
          color: isFullyStaffed ? "#166534" : "#b45309",
        }}
      >
        {isFullyStaffed ? "Couverture OK" : "Couverture incomplète"}
      </div>

      <div style={{ marginTop: 8 }}>
        <strong>Assignés :</strong>

        {assignments.length === 0 ? (
          <span> Aucun employé assigné</span>
        ) : (
          <ul style={{ marginTop: 4, marginBottom: 0 }}>
            {assignments.map((assignment) => (
              <li key={assignment.id}>
                {assignment.worker_first_name} {assignment.worker_last_name} —{" "}
                {assignment.role} — {assignment.status}
              </li>
            ))}
          </ul>
        )}
      </div>

      {!isEditing && (
        <button
          type="button"
          onClick={() => setIsEditing(true)}
          style={{ marginTop: 8 }}
        >
          Modifier assignés
        </button>
      )}

      {isEditing && (
        <div
          style={{
            marginTop: 12,
            padding: 12,
            border: "1px solid #e5e7eb",
            borderRadius: 8,
            background: "#f8fafc",
          }}
        >
          <strong>Choisir les employés assignés :</strong>

          <div style={{ display: "grid", gap: 6, marginTop: 8 }}>
            {workers.map((worker) => (
              <label key={worker.id}>
                <input
                  type="checkbox"
                  checked={selectedWorkerIds.includes(worker.id)}
                  onChange={() => toggleWorker(worker.id)}
                />{" "}
                {worker.first_name} {worker.last_name}
              </label>
            ))}
          </div>

          {saveError && (
            <p style={{ color: "red", marginTop: 8 }}>Erreur : {saveError}</p>
          )}

          <div style={{ display: "flex", gap: 8, marginTop: 12 }}>
            <button type="button" onClick={handleSave} disabled={isSaving}>
              {isSaving ? "Enregistrement..." : "Enregistrer"}
            </button>

            <button type="button" onClick={handleCancel} disabled={isSaving}>
              Annuler
            </button>
          </div>
        </div>
      )}

      {shift.notes && (
        <div style={{ marginTop: 6, fontSize: 14, color: "#64748b" }}>
          {shift.notes}
        </div>
      )}
    </div>
  );
}
