import type { Assignment, Shift } from "../types/planning";

type ShiftCardProps = {
  shift: Shift;
  assignments: Assignment[];
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

export default function ShiftCard({ shift, assignments }: ShiftCardProps) {
  const assignedCount = assignments.length;
  const isFullyStaffed = assignedCount >= shift.required_count;

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

      {shift.notes && (
        <div style={{ marginTop: 6, fontSize: 14, color: "#64748b" }}>
          {shift.notes}
        </div>
      )}
    </div>
  );
}
