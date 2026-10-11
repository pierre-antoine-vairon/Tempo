import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { apiDelete, apiGet, apiPost, apiPut } from "../api";

const ORG_ID = 1;

const WEEKDAYS = [
  { weekday: 1, label: "Lundi" },
  { weekday: 2, label: "Mardi" },
  { weekday: 3, label: "Mercredi" },
  { weekday: 4, label: "Jeudi" },
  { weekday: 5, label: "Vendredi" },
  { weekday: 6, label: "Samedi" },
  { weekday: 7, label: "Dimanche" },
] as const;

type Site = {
  id: number;
  org_id: number;
  name: string;
};

type WeeklyDay = {
  id?: number;
  weekday: number;
  is_open: boolean;
};

type WeeklyDayDraft = {
  weekday: number;
  is_open: boolean | null;
};

type WeeklyConfiguration = {
  effective_from: string;
  days: WeeklyDay[];
};

type DateException = {
  id: number;
  date: string;
  is_open: boolean;
  reason: string | null;
};

type CalendarResponse = {
  ok: true;
  org_id: number;
  site_id: number;
  first_effective_from: string | null;
  weekly_configurations: WeeklyConfiguration[];
  exceptions: DateException[];
};

type CreateWeeklyConfigurationResponse = {
  ok: true;
  configuration: {
    effective_from: string;
    days: WeeklyDay[];
  };
};

type UpsertExceptionResponse = {
  ok: true;
  exception: DateException;
};

function createEmptyWeeklyDays(): WeeklyDayDraft[] {
  return WEEKDAYS.map(({ weekday }) => ({
    weekday,
    is_open: null,
  }));
}

function weeklyDaysFromConfiguration(
  configuration: WeeklyConfiguration,
): WeeklyDayDraft[] {
  const daysByWeekday = new Map(
    configuration.days.map((day) => [day.weekday, day.is_open]),
  );

  return WEEKDAYS.map(({ weekday }) => ({
    weekday,
    is_open: daysByWeekday.get(weekday) ?? null,
  }));
}

function formatDate(date: string): string {
  const [year, month, day] = date.split("-");

  return `${day}/${month}/${year}`;
}

function getTodayLocal(): string {
  const now = new Date();
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");

  return `${year}-${month}-${day}`;
}

export default function SiteOperatingCalendarPage() {
  const { siteId: siteIdParam } = useParams();
  const siteId = Number(siteIdParam);
  const validSiteId = Number.isInteger(siteId) && siteId > 0;

  const [site, setSite] = useState<Site | null>(null);
  const [calendar, setCalendar] = useState<CalendarResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const [effectiveFrom, setEffectiveFrom] = useState("");
  const [weeklyDays, setWeeklyDays] = useState<WeeklyDayDraft[]>(
    createEmptyWeeklyDays,
  );
  const [savingWeeklyConfiguration, setSavingWeeklyConfiguration] =
    useState(false);

  const [exceptionDate, setExceptionDate] = useState("");
  const [exceptionIsOpen, setExceptionIsOpen] = useState(true);
  const [exceptionReason, setExceptionReason] = useState("");
  const [savingException, setSavingException] = useState(false);
  const [deletingExceptionId, setDeletingExceptionId] = useState<number | null>(
    null,
  );

  const today = useMemo(() => getTodayLocal(), []);

  const sortedConfigurations = useMemo(
    () =>
      [...(calendar?.weekly_configurations ?? [])].sort((left, right) =>
        left.effective_from.localeCompare(right.effective_from),
      ),
    [calendar],
  );

  const currentConfiguration = useMemo(
    () =>
      sortedConfigurations
        .filter((configuration) => configuration.effective_from <= today)
        .at(-1) ?? null,
    [sortedConfigurations, today],
  );

  const nextConfiguration = useMemo(
    () =>
      sortedConfigurations.find(
        (configuration) => configuration.effective_from > today,
      ) ?? null,
    [sortedConfigurations, today],
  );

  const weeklyConfigurationIsComplete = weeklyDays.every(
    (day) => day.is_open !== null,
  );

  const weeklyConfigurationDisabled =
    savingWeeklyConfiguration ||
    effectiveFrom === "" ||
    !weeklyConfigurationIsComplete;

  const loadCalendar = useCallback(async () => {
    if (!validSiteId) {
      setSite(null);
      setCalendar(null);
      setError("Identifiant de site invalide.");
      setLoading(false);
      return;
    }

    try {
      setLoading(true);
      setError(null);

      const [sites, calendarResponse] = await Promise.all([
        apiGet<Site[]>(`/sites?org_id=${ORG_ID}`),
        apiGet<CalendarResponse>(
          `/sites/${siteId}/operating-calendar?org_id=${ORG_ID}`,
        ),
      ]);

      setSite(sites.find((currentSite) => currentSite.id === siteId) ?? null);
      setCalendar(calendarResponse);

      const latestConfiguration = [...calendarResponse.weekly_configurations]
        .sort((left, right) =>
          left.effective_from.localeCompare(right.effective_from),
        )
        .at(-1);

      setWeeklyDays(
        latestConfiguration
          ? weeklyDaysFromConfiguration(latestConfiguration)
          : createEmptyWeeklyDays(),
      );
    } catch (err) {
      setSite(null);
      setCalendar(null);
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de charger le calendrier d’exploitation.",
      );
    } finally {
      setLoading(false);
    }
  }, [siteId, validSiteId]);

  useEffect(() => {
    void loadCalendar();
  }, [loadCalendar]);

  function setWeeklyDayOpen(weekday: number, isOpen: boolean | null) {
    setWeeklyDays((currentDays) =>
      currentDays.map((day) =>
        day.weekday === weekday ? { ...day, is_open: isOpen } : day,
      ),
    );
  }

  async function createWeeklyConfiguration() {
    if (!validSiteId) {
      return;
    }

    if (effectiveFrom === "" || !weeklyConfigurationIsComplete) {
      return;
    }

    try {
      setSavingWeeklyConfiguration(true);
      setError(null);
      setSuccessMessage(null);

      const days: WeeklyDay[] = weeklyDays.map((day) => {
        if (day.is_open === null) {
          throw new Error("Chaque jour doit être défini avant la création.");
        }

        return {
          weekday: day.weekday,
          is_open: day.is_open,
        };
      });

      await apiPost<
        CreateWeeklyConfigurationResponse,
        { effective_from: string; days: WeeklyDay[] }
      >(`/sites/${siteId}/operating-calendar/weekly-rules?org_id=${ORG_ID}`, {
        effective_from: effectiveFrom,
        days,
      });

      setEffectiveFrom("");
      setSuccessMessage("Nouvelle configuration hebdomadaire créée.");

      await loadCalendar();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de créer la configuration hebdomadaire.",
      );
    } finally {
      setSavingWeeklyConfiguration(false);
    }
  }

  async function saveException() {
    if (!validSiteId) {
      return;
    }

    try {
      setSavingException(true);
      setError(null);
      setSuccessMessage(null);

      await apiPut<
        UpsertExceptionResponse,
        { is_open: boolean; reason: string | null }
      >(
        `/sites/${siteId}/operating-calendar/exceptions/${exceptionDate}?org_id=${ORG_ID}`,
        {
          is_open: exceptionIsOpen,
          reason: exceptionReason.trim() === "" ? null : exceptionReason.trim(),
        },
      );

      setExceptionDate("");
      setExceptionIsOpen(true);
      setExceptionReason("");
      setSuccessMessage("Exception enregistrée.");

      await loadCalendar();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Impossible d’enregistrer l’exception.",
      );
    } finally {
      setSavingException(false);
    }
  }

  async function deleteException(exceptionItem: DateException) {
    if (!validSiteId) {
      return;
    }

    const confirmed = window.confirm(
      `Supprimer l’exception du ${formatDate(exceptionItem.date)} ?`,
    );

    if (!confirmed) {
      return;
    }

    try {
      setDeletingExceptionId(exceptionItem.id);
      setError(null);
      setSuccessMessage(null);

      await apiDelete<{ ok: true }>(
        `/sites/${siteId}/operating-calendar/exceptions/${exceptionItem.date}?org_id=${ORG_ID}`,
      );

      setSuccessMessage("Exception supprimée.");

      await loadCalendar();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de supprimer l’exception.",
      );
    } finally {
      setDeletingExceptionId(null);
    }
  }

  if (loading) {
    return (
      <div style={{ padding: 24 }}>
        <h1>Calendrier d’exploitation</h1>
        <p>Chargement...</p>
      </div>
    );
  }

  return (
    <div style={{ padding: 24, maxWidth: 960 }}>
      <Link
        to="/sites"
        style={{ color: "#475569", textDecoration: "none", fontWeight: 600 }}
      >
        ← Retour aux sites
      </Link>

      <div style={{ marginTop: 20, marginBottom: 28 }}>
        <h1 style={{ margin: 0 }}>Calendrier d’exploitation</h1>
        <p style={{ margin: "7px 0 0", color: "#64748b" }}>
          {site ? site.name : validSiteId ? `Site #${siteId}` : "Site invalide"}
        </p>
      </div>

      {error && (
        <div
          style={{
            marginBottom: 20,
            padding: 14,
            border: "1px solid #fecaca",
            background: "#fef2f2",
            borderRadius: 8,
            color: "#991b1b",
          }}
        >
          {error}
        </div>
      )}

      {successMessage && (
        <div
          style={{
            marginBottom: 20,
            padding: 14,
            border: "1px solid #bbf7d0",
            background: "#f0fdf4",
            borderRadius: 8,
            color: "#166534",
          }}
        >
          {successMessage}
        </div>
      )}

      {!calendar ? null : (
        <>
          <section style={sectionStyle}>
            <h2 style={sectionTitleStyle}>
              Configuration hebdomadaire actuelle
            </h2>

            {!currentConfiguration ? (
              <p style={{ margin: 0, color: "#64748b" }}>
                {sortedConfigurations.length === 0
                  ? "Calendrier non configuré"
                  : "Calendrier pas encore actif"}
              </p>
            ) : (
              <>
                <p style={{ marginTop: 0, color: "#64748b" }}>
                  En vigueur depuis le{" "}
                  {formatDate(currentConfiguration.effective_from)}
                </p>
                <WeeklyConfigurationList days={currentConfiguration.days} />
              </>
            )}
          </section>

          {nextConfiguration && (
            <section style={sectionStyle}>
              <h2 style={sectionTitleStyle}>Prochaine configuration prévue</h2>
              <p style={{ marginTop: 0, color: "#64748b" }}>
                À partir du {formatDate(nextConfiguration.effective_from)}
              </p>
              <WeeklyConfigurationList days={nextConfiguration.days} />
            </section>
          )}

          <section style={sectionStyle}>
            <h2 style={sectionTitleStyle}>
              Nouvelle configuration hebdomadaire
            </h2>
            <p style={{ marginTop: 0, color: "#64748b" }}>
              Cette action crée une nouvelle version durable. Les versions
              précédentes restent inchangées.
            </p>

            <label style={labelStyle}>
              Date d’effet
              <input
                type="date"
                value={effectiveFrom}
                onChange={(event) => setEffectiveFrom(event.target.value)}
                style={inputStyle}
              />
            </label>

            <WeeklyConfigurationForm
              days={weeklyDays}
              onChange={setWeeklyDayOpen}
            />

            <button
              type="button"
              onClick={() => void createWeeklyConfiguration()}
              disabled={weeklyConfigurationDisabled}
              style={{
                ...primaryButtonStyle,
                opacity: weeklyConfigurationDisabled ? 0.45 : 1,
                cursor: weeklyConfigurationDisabled ? "not-allowed" : "pointer",
              }}
            >
              {savingWeeklyConfiguration
                ? "Création..."
                : "Créer la nouvelle configuration"}
            </button>
          </section>

          <section style={sectionStyle}>
            <h2 style={sectionTitleStyle}>Versions enregistrées</h2>

            {calendar.weekly_configurations.length === 0 ? (
              <p style={{ margin: 0, color: "#64748b" }}>
                Aucune configuration enregistrée.
              </p>
            ) : (
              <div style={{ display: "grid", gap: 14 }}>
                {calendar.weekly_configurations.map((configuration) => (
                  <div
                    key={configuration.effective_from}
                    style={{
                      border: "1px solid #e2e8f0",
                      borderRadius: 8,
                      padding: 14,
                    }}
                  >
                    <strong>
                      À partir du {formatDate(configuration.effective_from)}
                    </strong>
                    <div style={{ marginTop: 10 }}>
                      <WeeklyConfigurationList
                        days={configuration.days}
                        compact
                      />
                    </div>
                  </div>
                ))}
              </div>
            )}
          </section>

          <section style={sectionStyle}>
            <h2 style={sectionTitleStyle}>Exceptions</h2>
            <p style={{ marginTop: 0, color: "#64748b" }}>
              Si une exception existe déjà à cette date, elle sera remplacée.
            </p>

            <div style={{ display: "grid", gap: 14, maxWidth: 520 }}>
              <label style={labelStyle}>
                Date
                <input
                  type="date"
                  value={exceptionDate}
                  onChange={(event) => setExceptionDate(event.target.value)}
                  style={inputStyle}
                />
              </label>

              <label style={labelStyle}>
                Statut
                <select
                  value={exceptionIsOpen ? "open" : "closed"}
                  onChange={(event) =>
                    setExceptionIsOpen(event.target.value === "open")
                  }
                  style={inputStyle}
                >
                  <option value="open">Ouvert</option>
                  <option value="closed">Fermé</option>
                </select>
              </label>

              <label style={labelStyle}>
                Raison facultative
                <input
                  type="text"
                  maxLength={255}
                  value={exceptionReason}
                  onChange={(event) => setExceptionReason(event.target.value)}
                  style={inputStyle}
                />
              </label>

              <button
                type="button"
                onClick={() => void saveException()}
                disabled={savingException || exceptionDate === ""}
                style={primaryButtonStyle}
              >
                {savingException
                  ? "Enregistrement..."
                  : "Enregistrer l’exception"}
              </button>
            </div>

            <div style={{ marginTop: 24 }}>
              {calendar.exceptions.length === 0 ? (
                <p style={{ margin: 0, color: "#64748b" }}>
                  Aucune exception enregistrée.
                </p>
              ) : (
                <div style={{ display: "grid", gap: 10 }}>
                  {calendar.exceptions.map((exceptionItem) => (
                    <div
                      key={exceptionItem.id}
                      style={{
                        display: "flex",
                        justifyContent: "space-between",
                        alignItems: "center",
                        gap: 16,
                        padding: 14,
                        border: "1px solid #e2e8f0",
                        borderRadius: 8,
                      }}
                    >
                      <div>
                        <strong>{formatDate(exceptionItem.date)}</strong>
                        <span
                          style={{
                            display: "inline-block",
                            marginLeft: 10,
                            color: exceptionItem.is_open
                              ? "#166534"
                              : "#991b1b",
                            fontWeight: 600,
                          }}
                        >
                          {exceptionItem.is_open ? "Ouvert" : "Fermé"}
                        </span>
                        {exceptionItem.reason && (
                          <div style={{ marginTop: 5, color: "#64748b" }}>
                            {exceptionItem.reason}
                          </div>
                        )}
                      </div>

                      <button
                        type="button"
                        onClick={() => void deleteException(exceptionItem)}
                        disabled={deletingExceptionId === exceptionItem.id}
                        style={secondaryButtonStyle}
                      >
                        {deletingExceptionId === exceptionItem.id
                          ? "Suppression..."
                          : "Supprimer"}
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </section>
        </>
      )}
    </div>
  );
}

function WeeklyConfigurationList({
  days,
  compact = false,
}: {
  days: WeeklyDay[];
  compact?: boolean;
}) {
  const daysByWeekday = new Map(days.map((day) => [day.weekday, day]));

  return (
    <div style={{ display: "grid", gap: compact ? 4 : 8 }}>
      {WEEKDAYS.map(({ weekday, label }) => {
        const day = daysByWeekday.get(weekday);

        return (
          <div
            key={weekday}
            style={{
              display: "flex",
              justifyContent: "space-between",
              gap: 16,
            }}
          >
            <span>{label}</span>
            <strong style={{ color: day?.is_open ? "#166534" : "#991b1b" }}>
              {day?.is_open ? "Ouvert" : "Fermé"}
            </strong>
          </div>
        );
      })}
    </div>
  );
}

function WeeklyConfigurationForm({
  days,
  onChange,
}: {
  days: WeeklyDayDraft[];
  onChange: (weekday: number, isOpen: boolean | null) => void;
}) {
  const daysByWeekday = new Map(days.map((day) => [day.weekday, day]));

  return (
    <div
      style={{
        display: "grid",
        gap: 8,
        maxWidth: 520,
        marginBottom: 18,
      }}
    >
      {WEEKDAYS.map(({ weekday, label }) => {
        const isOpen = daysByWeekday.get(weekday)?.is_open ?? null;

        return (
          <label
            key={weekday}
            style={{
              display: "flex",
              justifyContent: "space-between",
              alignItems: "center",
              gap: 16,
              padding: "9px 0",
              borderBottom: "1px solid #e2e8f0",
            }}
          >
            <span>{label}</span>
            <select
              value={isOpen === null ? "undefined" : isOpen ? "open" : "closed"}
              onChange={(event) => {
                const value = event.target.value;
                onChange(
                  weekday,
                  value === "undefined" ? null : value === "open",
                );
              }}
              style={{ ...inputStyle, width: 130, margin: 0 }}
            >
              <option value="undefined">À définir</option>
              <option value="open">Ouvert</option>
              <option value="closed">Fermé</option>
            </select>
          </label>
        );
      })}
    </div>
  );
}

const sectionStyle: React.CSSProperties = {
  marginBottom: 24,
  padding: 22,
  border: "1px solid #e2e8f0",
  borderRadius: 12,
  background: "white",
};

const sectionTitleStyle: React.CSSProperties = {
  marginTop: 0,
  marginBottom: 14,
  fontSize: 20,
};

const labelStyle: React.CSSProperties = {
  display: "grid",
  gap: 7,
  color: "#334155",
  fontWeight: 600,
};

const inputStyle: React.CSSProperties = {
  width: "100%",
  boxSizing: "border-box",
  padding: "9px 10px",
  border: "1px solid #cbd5e1",
  borderRadius: 7,
  color: "#0f172a",
  background: "white",
};

const primaryButtonStyle: React.CSSProperties = {
  width: "fit-content",
  padding: "10px 14px",
  border: 0,
  borderRadius: 8,
  background: "#0f172a",
  color: "white",
  fontWeight: 600,
  cursor: "pointer",
};

const secondaryButtonStyle: React.CSSProperties = {
  padding: "8px 11px",
  border: "1px solid #cbd5e1",
  borderRadius: 7,
  background: "white",
  color: "#334155",
  fontWeight: 600,
  cursor: "pointer",
};
