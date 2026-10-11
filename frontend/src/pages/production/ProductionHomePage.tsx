import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { apiGet, apiPost } from "../../api";

type ProductionStatus = "not_started" | "in_progress" | "finished" | "closed";

type OperatingCalendar = {
  is_configured: boolean;
  is_open: boolean | null;
  source: "unconfigured" | "weekly_rule" | "exception";
  reason: string | null;
};

type OverviewDay = {
  date: string;
  status: ProductionStatus;
  production_day_id: number | null;
  calendar: OperatingCalendar;
};

type OverviewAlert = {
  code: string;
  level: "info" | "warning";
  message: string;
  dates?: string[];
};

type OverviewResponse = {
  ok: true;

  org_id: number;

  site: {
    id: number;
    name: string;
  };

  date: string;

  today: {
    exists: boolean;
    production_day_id: number | null;
    status: ProductionStatus;
    products_count: number;
    calendar: OperatingCalendar;
  };

  week: OverviewDay[];

  products: {
    total: number;
    active: number;
    inactive: number;
  };

  alerts: OverviewAlert[];
};

function getTodayLocal(): string {
  const now = new Date();

  const year = now.getFullYear();

  const month = String(now.getMonth() + 1).padStart(2, "0");

  const day = String(now.getDate()).padStart(2, "0");

  return `${year}-${month}-${day}`;
}

function formatDate(date: string): string {
  const [year, month, day] = date.split("-");

  return `${day}/${month}/${year}`;
}

function getDayName(date: string): string {
  const parsedDate = new Date(`${date}T12:00:00`);

  return new Intl.DateTimeFormat("fr-FR", {
    weekday: "short",
  })
    .format(parsedDate)
    .replace(".", "");
}

function getStatusLabel(status: ProductionStatus): string {
  switch (status) {
    case "not_started":
      return "À faire";

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

export default function ProductionHomePage() {
  const navigate = useNavigate();

  const [overview, setOverview] = useState<OverviewResponse | null>(null);

  const [loading, setLoading] = useState(true);

  const [error, setError] = useState<string | null>(null);

  const [startingToday, setStartingToday] = useState(false);

  const siteId = 1;

  /*
  |--------------------------------------------------------------------------
  | Date locale
  |--------------------------------------------------------------------------
  */

  const today = useMemo(() => getTodayLocal(), []);

  /*
  |--------------------------------------------------------------------------
  | Chargement
  |--------------------------------------------------------------------------
  */

  const loadOverview = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);

      const response = await apiGet<OverviewResponse>(
        `/production/overview?site_id=${siteId}&date=${today}`,
      );

      setOverview(response);
    } catch (err) {
      setOverview(null);

      setError(
        err instanceof Error
          ? err.message
          : "Impossible de charger la vue d'ensemble.",
      );
    } finally {
      setLoading(false);
    }
  }, [siteId, today]);

  useEffect(() => {
    void loadOverview();
  }, [loadOverview]);

  /*
  |--------------------------------------------------------------------------
  | Navigation
  |--------------------------------------------------------------------------
  */

  function openProductionDay(date: string) {
    navigate(`/production/day?date=${date}`);
  }

  async function startTodayProduction() {
    /*
     * Si la journée existe déjà,
     * le bouton agit simplement comme "Ouvrir".
     */
    if (overview?.today.status !== "not_started") {
      openProductionDay(today);
      return;
    }

    try {
      setStartingToday(true);
      setError(null);

      await apiPost<
        unknown,
        {
          site_id: number;
          date: string;
        }
      >("/production/day/open", {
        site_id: siteId,
        date: today,
      });

      /*
       * La journée vient d'être créée.
       * On ouvre directement sa feuille.
       */
      navigate(`/production/day?date=${today}`);
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de démarrer la production.",
      );
    } finally {
      setStartingToday(false);
    }
  }

  /*
  |--------------------------------------------------------------------------
  | Chargement
  |--------------------------------------------------------------------------
  */

  if (loading) {
    return (
      <div style={{ padding: 24 }}>
        <h2>Vue d'ensemble</h2>

        <p>Chargement...</p>
      </div>
    );
  }

  /*
  |--------------------------------------------------------------------------
  | Erreur
  |--------------------------------------------------------------------------
  */

  if (error || !overview) {
    return (
      <div style={{ padding: 24 }}>
        <h2>Vue d'ensemble</h2>

        <div
          style={{
            padding: 14,
            marginTop: 18,
            border: "1px solid #fecaca",
            background: "#fef2f2",
            borderRadius: 8,
            color: "#991b1b",
          }}
        >
          {error ?? "Impossible de charger la vue d'ensemble."}
        </div>
      </div>
    );
  }

  const todayIsConfiguredClosed =
    !overview.today.exists &&
    overview.today.calendar.is_configured &&
    overview.today.calendar.is_open === false;

  return (
    <div style={{ padding: 24 }}>
      {/* ================================================================
          EN-TÊTE
      ================================================================= */}

      <div
        style={{
          display: "flex",
          justifyContent: "space-between",
          alignItems: "flex-start",
          gap: 20,
          marginBottom: 30,
        }}
      >
        <div>
          <h2
            style={{
              margin: 0,
            }}
          >
            Vue d'ensemble
          </h2>

          <p
            style={{
              marginTop: 6,
              marginBottom: 0,
              color: "#64748b",
            }}
          >
            Pilotage de la production et accès aux actions principales.
          </p>
        </div>

        <div
          style={{
            textAlign: "right",
          }}
        >
          <strong>{overview.site.name}</strong>

          <div
            style={{
              marginTop: 4,
              color: "#64748b",
              fontSize: 14,
            }}
          >
            {formatDate(overview.date)}
          </div>
        </div>
      </div>

      {/* ================================================================
          PRODUCTION DU JOUR
      ================================================================= */}

      <section
        style={{
          padding: 22,
          marginBottom: 24,
          border: "1px solid #e2e8f0",
          borderRadius: 12,
          background: "white",
        }}
      >
        <div
          style={{
            display: "flex",
            justifyContent: "space-between",
            alignItems: "center",
            gap: 20,
            flexWrap: "wrap",
          }}
        >
          <div>
            <div
              style={{
                color: "#64748b",
                fontSize: 14,
                marginBottom: 5,
              }}
            >
              Aujourd'hui
            </div>

            <div
              style={{
                display: "flex",
                alignItems: "center",
                gap: 12,
                flexWrap: "wrap",
              }}
            >
              <strong
                style={{
                  fontSize: 24,
                }}
              >
                {formatDate(overview.date)}
              </strong>

              {todayIsConfiguredClosed ? (
                <CalendarStatusBadge label="Fermé" />
              ) : (
                <StatusBadge status={overview.today.status} />
              )}
            </div>

            <div
              style={{
                marginTop: 9,
                color: "#64748b",
              }}
            >
              {overview.today.products_count} produits concernés
            </div>
          </div>

          {!todayIsConfiguredClosed && (
            <button
              type="button"
              onClick={() => void startTodayProduction()}
              disabled={startingToday}
              style={{
                padding: "11px 17px",
                border: 0,
                borderRadius: 8,
                background: "#0f172a",
                color: "white",
                fontWeight: 600,
                cursor: startingToday ? "not-allowed" : "pointer",
              }}
            >
              {startingToday
                ? "Démarrage..."
                : overview.today.status === "not_started"
                  ? "Démarrer la production"
                  : "Ouvrir la production"}
            </button>
          )}
        </div>
      </section>

      {/* ================================================================
          SEMAINE
      ================================================================= */}

      <section
        style={{
          marginBottom: 26,
        }}
      >
        <div
          style={{
            display: "flex",
            justifyContent: "space-between",
            alignItems: "baseline",
            marginBottom: 12,
          }}
        >
          <h3
            style={{
              margin: 0,
              fontSize: 20,
            }}
          >
            Cette semaine
          </h3>

          <button
            type="button"
            onClick={() => navigate("/production/history")}
            style={{
              border: 0,
              background: "transparent",
              color: "#475569",
              fontWeight: 600,
              cursor: "pointer",
            }}
          >
            Voir l'historique
          </button>
        </div>

        <div
          style={{
            display: "grid",
            gridTemplateColumns: "repeat(7, minmax(110px, 1fr))",
            gap: 10,
          }}
        >
          {overview.week.map((day) => {
            const isToday = day.date === overview.date;
            const isConfiguredClosed =
              day.production_day_id === null &&
              day.calendar.is_configured &&
              day.calendar.is_open === false;
            const isMissingProductionDay =
              day.production_day_id === null &&
              !isConfiguredClosed &&
              day.calendar.is_configured &&
              day.calendar.is_open === true &&
              day.date < overview.date;

            return (
              <button
                type="button"
                key={day.date}
                onClick={() => openProductionDay(day.date)}
                style={{
                  padding: 14,
                  minHeight: 105,
                  textAlign: "left",

                  border: isToday ? "2px solid #0f172a" : "1px solid #e2e8f0",

                  borderRadius: 10,
                  background: "white",

                  cursor: "pointer",
                }}
              >
                <div
                  style={{
                    textTransform: "capitalize",
                    color: "#64748b",
                    fontSize: 13,
                    marginBottom: 5,
                  }}
                >
                  {getDayName(day.date)}
                </div>

                <strong
                  style={{
                    display: "block",
                    marginBottom: 10,
                  }}
                >
                  {day.date.split("-")[2]}
                </strong>

                {isConfiguredClosed ? (
                  <CalendarStatusBadge label="Fermé" compact />
                ) : isMissingProductionDay ? (
                  <CalendarStatusBadge label="Journée manquante" compact warning />
                ) : (
                  <StatusBadge status={day.status} compact />
                )}
              </button>
            );
          })}
        </div>
      </section>

      {/* ================================================================
          BAS DE PAGE : ALERTES + ACCÈS RAPIDES
      ================================================================= */}

      <div
        style={{
          display: "grid",
          gridTemplateColumns: "minmax(0, 1.4fr) minmax(320px, 1fr)",
          gap: 20,
        }}
      >
        {/* --------------------------------------------------------------
            À surveiller
        --------------------------------------------------------------- */}

        <section>
          <h3
            style={{
              marginTop: 0,
              marginBottom: 12,
              fontSize: 20,
            }}
          >
            À surveiller
          </h3>

          {overview.alerts.length === 0 ? (
            <div
              style={{
                padding: 18,
                border: "1px solid #bbf7d0",
                borderRadius: 10,
                background: "#f0fdf4",
                color: "#166534",
              }}
            >
              ✓ Aucun point d'attention.
            </div>
          ) : (
            <div
              style={{
                display: "grid",
                gap: 10,
              }}
            >
              {overview.alerts.map((alert) => (
                <AlertCard key={alert.code} alert={alert} />
              ))}
            </div>
          )}
        </section>

        {/* --------------------------------------------------------------
            Accès rapides
        --------------------------------------------------------------- */}

        <section>
          <h3
            style={{
              marginTop: 0,
              marginBottom: 12,
              fontSize: 20,
            }}
          >
            Accès rapides
          </h3>

          <div
            style={{
              border: "1px solid #e2e8f0",
              borderRadius: 10,
              background: "white",
              overflow: "hidden",
            }}
          >
            <QuickLink
              label="Production du jour"
              description={`${overview.today.products_count} produits`}
              onClick={() => openProductionDay(overview.date)}
            />

            <QuickLink
              label="Historique"
              description="Consulter les journées précédentes"
              onClick={() => navigate("/production/history")}
            />

            <QuickLink
              label="Produits"
              description={`${overview.products.active} actifs · ${overview.products.inactive} inactifs`}
              onClick={() => navigate("/production/products")}
              last
            />
          </div>
        </section>
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| Badge statut
|--------------------------------------------------------------------------
*/

function StatusBadge({
  status,
  compact = false,
}: {
  status: ProductionStatus;
  compact?: boolean;
}) {
  return (
    <span
      style={{
        display: "inline-block",

        padding: compact ? "5px 8px" : "6px 10px",

        borderRadius: 8,

        background:
          status === "closed"
            ? "#e2e8f0"
            : status === "finished"
              ? "#ecfdf5"
              : status === "in_progress"
                ? "#eff6ff"
                : "#fff7ed",

        color:
          status === "closed"
            ? "#475569"
            : status === "finished"
              ? "#166534"
              : status === "in_progress"
                ? "#1d4ed8"
                : "#9a3412",

        fontSize: compact ? 12 : 13,

        fontWeight: 600,
      }}
    >
      {getStatusLabel(status)}
    </span>
  );
}

function CalendarStatusBadge({
  label,
  compact = false,
  warning = false,
}: {
  label: string;
  compact?: boolean;
  warning?: boolean;
}) {
  return (
    <span
      style={{
        display: "inline-block",

        padding: compact ? "5px 8px" : "6px 10px",

        borderRadius: 8,

        background: warning ? "#fffbeb" : "#e2e8f0",

        color: warning ? "#92400e" : "#475569",

        fontSize: compact ? 12 : 13,

        fontWeight: 600,
      }}
    >
      {label}
    </span>
  );
}

/*
|--------------------------------------------------------------------------
| Alerte
|--------------------------------------------------------------------------
*/

function AlertCard({ alert }: { alert: OverviewAlert }) {
  const isWarning = alert.level === "warning";

  return (
    <div
      style={{
        padding: 16,

        border: isWarning ? "1px solid #fcd34d" : "1px solid #bfdbfe",

        borderRadius: 10,

        background: isWarning ? "#fffbeb" : "#eff6ff",

        color: isWarning ? "#92400e" : "#1e40af",
      }}
    >
      <strong
        style={{
          marginRight: 8,
        }}
      >
        {isWarning ? "⚠" : "ℹ"}
      </strong>

      {alert.message}
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| Accès rapide
|--------------------------------------------------------------------------
*/

function QuickLink({
  label,
  description,
  onClick,
  last = false,
}: {
  label: string;
  description: string;
  onClick: () => void;
  last?: boolean;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      style={{
        width: "100%",

        display: "flex",
        justifyContent: "space-between",
        alignItems: "center",
        gap: 15,

        padding: "15px 16px",

        border: 0,

        borderBottom: last ? "none" : "1px solid #e2e8f0",

        background: "white",

        textAlign: "left",
        cursor: "pointer",
      }}
    >
      <div>
        <strong
          style={{
            display: "block",
            marginBottom: 3,
          }}
        >
          {label}
        </strong>

        <span
          style={{
            color: "#64748b",
            fontSize: 13,
          }}
        >
          {description}
        </span>
      </div>

      <span
        style={{
          color: "#64748b",
          fontSize: 20,
        }}
      >
        →
      </span>
    </button>
  );
}
