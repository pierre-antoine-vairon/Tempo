import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { apiGet, apiPost } from "../../api";

const ORG_ID = 1;

type MeasureUnit = {
  id: number;
  code: string;
  name: string;
  symbol: string;
  dimension: string;
  to_base_factor: string;
  is_active: boolean;
};

type Material = {
  id: number;
  org_id: number;
  name: string;
  reference_unit_label: string | null;
  is_active: boolean;
  reference_measure_unit: MeasureUnit;
};

type MaterialsResponse = { ok: true; org_id: number; materials: Material[] };
type MeasureUnitsResponse = { ok: true; measure_units: MeasureUnit[] };
type CreateMaterialResponse = { ok: true; material: Material; message: string };

export default function PurchasingMaterialsPage() {
  const [materials, setMaterials] = useState<Material[]>([]);
  const [measureUnits, setMeasureUnits] = useState<MeasureUnit[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [measureUnitId, setMeasureUnitId] = useState<number | null>(null);
  const [referenceUnitLabel, setReferenceUnitLabel] = useState("");
  const [saving, setSaving] = useState(false);

  const loadMaterials = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);

      const [materialsResponse, measureUnitsResponse] = await Promise.all([
        apiGet<MaterialsResponse>(`/purchasing/materials?org_id=${ORG_ID}`),
        apiGet<MeasureUnitsResponse>("/purchasing/measure-units"),
      ]);

      setMaterials(materialsResponse.materials);
      setMeasureUnits(measureUnitsResponse.measure_units);
      setMeasureUnitId((currentId) =>
        currentId ?? measureUnitsResponse.measure_units[0]?.id ?? null,
      );
    } catch (err) {
      setMaterials([]);
      setMeasureUnits([]);
      setError(
        err instanceof Error ? err.message : "Impossible de charger les matières.",
      );
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadMaterials();
  }, [loadMaterials]);

  async function createMaterial() {
    const trimmedName = name.trim();

    if (trimmedName === "") {
      setError("Le nom de la matière est obligatoire.");
      return;
    }

    if (measureUnitId === null) {
      setError("Veuillez choisir une unité de référence.");
      return;
    }

    try {
      setSaving(true);
      setError(null);
      setSuccessMessage(null);

      const response = await apiPost<
        CreateMaterialResponse,
        { name: string; reference_measure_unit_id: number; reference_unit_label: string | null }
      >(`/purchasing/materials?org_id=${ORG_ID}`, {
        name: trimmedName,
        reference_measure_unit_id: measureUnitId,
        reference_unit_label:
          referenceUnitLabel.trim() === "" ? null : referenceUnitLabel.trim(),
      });

      setName("");
      setReferenceUnitLabel("");
      setSuccessMessage(response.message);
      await loadMaterials();
    } catch (err) {
      setError(
        err instanceof Error ? err.message : "Impossible de créer la matière.",
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <div style={{ padding: 24, maxWidth: 1080 }}>
      <header style={{ marginBottom: 28 }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 16, flexWrap: "wrap" }}>
          <div>
            <h1 style={{ margin: 0 }}>Achats & Matières</h1>
            <p style={{ margin: "7px 0 0", color: "#64748b" }}>
              Référentiel des matières utilisées par Tempo.
            </p>
          </div>
          <div style={{ display: "flex", gap: 8 }}>
            <Link to="/purchasing" style={navLinkStyle}>Fournisseurs</Link>
            <Link to="/purchasing/materials" style={activeLinkStyle}>Matières</Link>
          </div>
        </div>
      </header>

      {error && <MessageBox kind="error">{error}</MessageBox>}
      {successMessage && <MessageBox kind="success">{successMessage}</MessageBox>}

      <section style={sectionStyle}>
        <h2 style={sectionTitleStyle}>Nouvelle matière</h2>
        <div style={{ display: "grid", gridTemplateColumns: "minmax(200px, 1fr) minmax(180px, 240px) minmax(180px, 240px)", gap: 12, marginBottom: 14 }}>
          <label style={labelStyle}>
            Nom
            <input type="text" value={name} onChange={(event) => setName(event.target.value)} maxLength={160} style={inputStyle} />
          </label>
          <label style={labelStyle}>
            Unité de référence
            <select value={measureUnitId ?? ""} onChange={(event) => setMeasureUnitId(Number(event.target.value))} style={inputStyle}>
              {measureUnits.map((unit) => <option key={unit.id} value={unit.id}>{unit.name} ({unit.symbol})</option>)}
            </select>
          </label>
          <label style={labelStyle}>
            Label métier optionnel
            <input type="text" value={referenceUnitLabel} onChange={(event) => setReferenceUnitLabel(event.target.value)} maxLength={50} style={inputStyle} />
          </label>
        </div>
        <button type="button" onClick={() => void createMaterial()} disabled={saving || measureUnits.length === 0} style={primaryButtonStyle}>
          {saving ? "Création..." : "Créer la matière"}
        </button>
      </section>

      <section style={sectionStyle}>
        <h2 style={sectionTitleStyle}>Matières</h2>
        {loading ? <p style={{ margin: 0 }}>Chargement...</p> : materials.length === 0 ? <p style={{ margin: 0, color: "#64748b" }}>Aucune matière enregistrée.</p> : (
          <div style={{ overflowX: "auto" }}>
            <table style={tableStyle}>
              <thead><tr><th style={headerCellStyle}>Nom</th><th style={headerCellStyle}>Unité de référence</th><th style={headerCellStyle}>Label métier</th><th style={headerCellStyle}>Statut</th></tr></thead>
              <tbody>
                {materials.map((material) => (
                  <tr key={material.id}>
                    <td style={cellStyle}>{material.name}</td>
                    <td style={cellStyle}>{material.reference_measure_unit.symbol}</td>
                    <td style={cellStyle}>{material.reference_unit_label ?? "—"}</td>
                    <td style={cellStyle}><StatusLabel isActive={material.is_active} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}

function StatusLabel({ isActive }: { isActive: boolean }) { return <span style={{ color: isActive ? "#166534" : "#64748b", fontWeight: 600 }}>{isActive ? "Actif" : "Inactif"}</span>; }
function MessageBox({ kind, children }: { kind: "error" | "success"; children: React.ReactNode }) { const success = kind === "success"; return <div style={{ marginBottom: 20, padding: 14, border: `1px solid ${success ? "#bbf7d0" : "#fecaca"}`, background: success ? "#f0fdf4" : "#fef2f2", borderRadius: 8, color: success ? "#166534" : "#991b1b" }}>{children}</div>; }
const sectionStyle: React.CSSProperties = { marginBottom: 22, padding: 18, border: "1px solid #e2e8f0", borderRadius: 10, background: "white" };
const sectionTitleStyle: React.CSSProperties = { marginTop: 0, marginBottom: 14, fontSize: 19 };
const labelStyle: React.CSSProperties = { display: "grid", gap: 5, color: "#475569", fontSize: 13 };
const inputStyle: React.CSSProperties = { width: "100%", padding: "9px 10px", border: "1px solid #cbd5e1", borderRadius: 8, background: "white" };
const primaryButtonStyle: React.CSSProperties = { padding: "9px 14px", border: 0, borderRadius: 8, background: "#0f172a", color: "white", fontWeight: 600, cursor: "pointer" };
const navLinkStyle: React.CSSProperties = { padding: "8px 12px", borderRadius: 8, textDecoration: "none", color: "#475569", fontWeight: 600 };
const activeLinkStyle: React.CSSProperties = { ...navLinkStyle, background: "#e2e8f0", color: "#0f172a" };
const tableStyle: React.CSSProperties = { width: "100%", borderCollapse: "collapse", minWidth: 650 };
const headerCellStyle: React.CSSProperties = { textAlign: "left", padding: 10, borderBottom: "1px solid #cbd5e1", color: "#475569", fontSize: 13 };
const cellStyle: React.CSSProperties = { padding: 10, borderBottom: "1px solid #e2e8f0" };
