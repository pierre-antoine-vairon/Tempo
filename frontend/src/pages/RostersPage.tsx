import { useEffect, useState } from "react";
import { apiGet } from "../api";
import { Link } from "react-router-dom";

type Roster = {
  id: number;
  org_id: number;
  site_id: number;
  site_name: string;
  period_start: string;
  period_end: string;
  status: string;
  notes: string | null;
};

export default function RostersPage() {
  const [rosters, setRosters] = useState<Roster[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    async function loadRosters() {
      try {
        setError(null);
        const data = await apiGet<Roster[]>("/rosters");
        setRosters(data);
      } catch (e: unknown) {
        const message = e instanceof Error ? e.message : "Erreur API";
        setError(message);
      }
    }

    loadRosters();
  }, []);

  return (
    <div>
      <h1>Rosters</h1>
      <p>Liste des plannings existants.</p>

      {error && <p style={{ color: "red" }}>Erreur : {error}</p>}

      <ul>
        {rosters.map((roster) => (
          <li key={roster.id}>
            #{roster.id} — {roster.site_name} — du {roster.period_start} au{" "}
            {roster.period_end} — status: {roster.status}
            {roster.notes ? ` — ${roster.notes}` : ""}{" "}
            <Link to={`/rosters/${roster.id}`}>Voir shifts</Link>
          </li>
        ))}
      </ul>
    </div>
  );
}
