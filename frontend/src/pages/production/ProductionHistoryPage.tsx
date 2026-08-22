import { useCallback, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { apiGet } from "../../api";

type ProductionStatus = "in_progress" | "finished" | "closed";

type HistoryDay = {
  id: number;
  date: string;
  status: ProductionStatus;
  products_count: number;

  started_at: string | null;
  finished_at: string | null;
  closed_at: string | null;

  created_at: string | null;
  updated_at: string | null;
};

type HistoryResponse = {
  ok: true;
  org_id: number;
  site_id: number;
  count: number;
  days: HistoryDay[];
};

function getStatusLabel(status: ProductionStatus): string {
  switch (status) {
    case "in_progress":
      return "En cours";

    case "finished":
      return "Terminée";

    case "closed":
      return "Clôturée";

    default:
      return status;
  }
}

function formatDate(date: string): string {
  const [year, month, day] = date.split("-");

  return `${day}/${month}/${year}`;
}

export default function ProductionHistoryPage() {
  const navigate = useNavigate();

  const [days, setDays] = useState<HistoryDay[]>([]);

  const [loading, setLoading] = useState(true);

  const [error, setError] = useState<string | null>(null);

  /*
  |--------------------------------------------------------------------------
  | Site MVP
  |--------------------------------------------------------------------------
  */

  const siteId = 1;

  /*
  |--------------------------------------------------------------------------
  | Chargement de l'historique
  |--------------------------------------------------------------------------
  */

  const loadHistory = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);

      const response = await apiGet<HistoryResponse>(
        `/production/history?site_id=${siteId}`,
      );

      setDays(response.days);
    } catch (err) {
      setDays([]);

      setError(
        err instanceof Error
          ? err.message
          : "Impossible de charger l'historique.",
      );
    } finally {
      setLoading(false);
    }
  }, [siteId]);

  useEffect(() => {
    void loadHistory();
  }, [loadHistory]);

  /*
  |--------------------------------------------------------------------------
  | Ouverture d'une journée
  |--------------------------------------------------------------------------
  */

  function openDay(date: string) {
    navigate(`/production/day?date=${date}`);
  }

  /*
  |--------------------------------------------------------------------------
  | Chargement
  |--------------------------------------------------------------------------
  */

  if (loading) {
    return (
      <div style={{ padding: 24 }}>
        <h2>Historique</h2>
        <p>Chargement...</p>
      </div>
    );
  }

  /*
  |--------------------------------------------------------------------------
  | Affichage
  |--------------------------------------------------------------------------
  */

  return (
    <div style={{ padding: 24 }}>
      <div
        style={{
          marginBottom: 28,
        }}
      >
        <h2
          style={{
            margin: 0,
          }}
        >
          Historique des productions
        </h2>

        <p
          style={{
            marginTop: 6,
            marginBottom: 0,
            color: "#64748b",
          }}
        >
          Consultez les journées de production précédentes.
        </p>
      </div>

      {error && (
        <div
          style={{
            padding: 14,
            marginBottom: 20,
            border: "1px solid #fecaca",
            background: "#fef2f2",
            borderRadius: 8,
            color: "#991b1b",
          }}
        >
          {error}
        </div>
      )}

      {days.length === 0 && !error && (
        <div
          style={{
            padding: 24,
            border: "1px solid #e2e8f0",
            borderRadius: 10,
            background: "white",
          }}
        >
          Aucune journée de production enregistrée.
        </div>
      )}

      {days.length > 0 && (
        <div
          style={{
            border: "1px solid #e2e8f0",
            borderRadius: 10,
            overflow: "hidden",
            background: "white",
          }}
        >
          <table
            style={{
              width: "100%",
              borderCollapse: "collapse",
            }}
          >
            <thead>
              <tr
                style={{
                  background: "#f8fafc",
                  textAlign: "left",
                }}
              >
                <th
                  style={{
                    padding: "14px 16px",
                    borderBottom: "1px solid #e2e8f0",
                  }}
                >
                  Date
                </th>

                <th
                  style={{
                    padding: "14px 16px",
                    borderBottom: "1px solid #e2e8f0",
                  }}
                >
                  Statut
                </th>

                <th
                  style={{
                    padding: "14px 16px",
                    borderBottom: "1px solid #e2e8f0",
                  }}
                >
                  Produits
                </th>

                <th
                  style={{
                    padding: "14px 16px",
                    borderBottom: "1px solid #e2e8f0",
                    width: 120,
                  }}
                >
                  Action
                </th>
              </tr>
            </thead>

            <tbody>
              {days.map((day) => (
                <tr key={day.id}>
                  <td
                    style={{
                      padding: "15px 16px",
                      borderBottom: "1px solid #e2e8f0",
                      fontWeight: 600,
                    }}
                  >
                    {formatDate(day.date)}
                  </td>

                  <td
                    style={{
                      padding: "15px 16px",
                      borderBottom: "1px solid #e2e8f0",
                    }}
                  >
                    <span
                      style={{
                        display: "inline-block",
                        padding: "6px 10px",
                        borderRadius: 8,
                        background: "#f1f5f9",
                        color: "#475569",
                        fontSize: 13,
                        fontWeight: 600,
                      }}
                    >
                      {getStatusLabel(day.status)}
                    </span>
                  </td>

                  <td
                    style={{
                      padding: "15px 16px",
                      borderBottom: "1px solid #e2e8f0",
                    }}
                  >
                    {day.products_count}
                  </td>

                  <td
                    style={{
                      padding: "15px 16px",
                      borderBottom: "1px solid #e2e8f0",
                    }}
                  >
                    <button
                      type="button"
                      onClick={() => openDay(day.date)}
                      style={{
                        padding: "8px 13px",
                        border: "1px solid #cbd5e1",
                        borderRadius: 8,
                        background: "white",
                        color: "#0f172a",
                        fontWeight: 600,
                        cursor: "pointer",
                      }}
                    >
                      Voir
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
