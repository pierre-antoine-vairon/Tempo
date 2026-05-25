import { useEffect, useState } from "react";
import { apiGet } from "../api";

type Worker = { id: number; org_id: number; site_id: number | null; first_name: string; last_name: string };

export default function WorkersPage() {
  const [workers, setWorkers] = useState<Worker[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      try {
        setError(null);
        setWorkers(await apiGet<Worker[]>("/workers"));
      } catch (e: unknown) {
        setError(e instanceof Error ? e.message : "Erreur API");
      }
    })();
  }, []);

  return (
    <div>
      <h1>Workers</h1>
      {error && <p style={{ color: "red" }}>{error}</p>}
      <ul>
        {workers.map((w) => (
          <li key={w.id}>
            #{w.id} — {w.first_name} {w.last_name} (site_id={w.site_id ?? "null"})
          </li>
        ))}
      </ul>
    </div>
  );
}