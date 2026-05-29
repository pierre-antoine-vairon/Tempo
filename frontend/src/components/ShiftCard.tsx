import { useEffect, useState } from "react";
import type { Assignment, Shift, Worker } from "../types/planning";

type ShiftCardProps = {
  shift: Shift;
  assignments: Assignment[];
  workers: Worker[];
  onSaveAssignments: (shiftId: number, workerIds: number[]) => Promise<void>;
};

type CoverageStatus = {
  label: string;
  color: string;
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

function getCoverageStatus(
  assignedCount: number,
  requiredCount: number,
): CoverageStatus {
  if (assignedCount < requiredCount) {
    return {
      label: "Couverture incomplète",
      color: "#b45309",
    };
  }

  if (assignedCount > requiredCount) {
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
  const coverageStatus = getCoverageStatus(assignedCount, shift.required_count);

  const selectedCoverageStatus = getCoverageStatus(
    selectedWorkerIds.length,
    shift.required_count,
  );

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
          color: coverageStatus.color,
          fontWeight: 600,
        }}
      >
        {coverageStatus.label}
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
          style={{
            marginTop: 10,
            padding: "6px 10px",
            cursor: "pointer",
          }}
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
          <div>
            <strong>Modifier les assignés</strong>
          </div>

          <div
            style={{
              marginTop: 4,
              fontSize: 14,
              color: selectedCoverageStatus.color,
              fontWeight: 600,
            }}
          >
            {selectedWorkerIds.length} sélectionné(s) / objectif{" "}
            {shift.required_count} — {selectedCoverageStatus.label}
          </div>

          <div style={{ display: "grid", gap: 6, marginTop: 10 }}>
            {workers.map((worker) => (
              <label key={worker.id} style={{ cursor: "pointer" }}>
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
