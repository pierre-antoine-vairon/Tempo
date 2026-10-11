import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { apiGet } from "../api";

type Site = { id: number; org_id: number; name: string };

export default function SitesPage() {
  const [sites, setSites] = useState<Site[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      try {
        setError(null);
        setSites(await apiGet<Site[]>("/sites"));
      } catch (e: unknown) {
        setError(e instanceof Error ? e.message : "Erreur API");
      }
    })();
  }, []);

  return (
    <div>
      <h1>Sites</h1>
      {error && <p style={{ color: "red" }}>{error}</p>}
      <ul>
        {sites.map((s) => (
          <li key={s.id}>
            #{s.id} — {s.name}{" "}
            <Link to={`/sites/${s.id}/operating-calendar`}>
              Calendrier d’exploitation
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}
