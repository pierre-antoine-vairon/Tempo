import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { apiGet, apiPost } from "../../api";

const ORG_ID = 1;

type Supplier = {
  id: number;
  name: string;
  is_active: boolean;
};

type SuppliersResponse = {
  ok: true;
  org_id: number;
  suppliers: Supplier[];
};

type CreateSupplierResponse = {
  ok: true;
  supplier: Supplier;
  message: string;
};

export default function PurchasingSuppliersPage() {
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [saving, setSaving] = useState(false);

  const loadSuppliers = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);

      const response = await apiGet<SuppliersResponse>(
        `/purchasing/suppliers?org_id=${ORG_ID}`,
      );

      setSuppliers(response.suppliers);
    } catch (err) {
      setSuppliers([]);
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de charger les fournisseurs.",
      );
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadSuppliers();
  }, [loadSuppliers]);

  async function createSupplier() {
    const trimmedName = name.trim();

    if (trimmedName === "") {
      setError("Le nom du fournisseur est obligatoire.");
      return;
    }

    try {
      setSaving(true);
      setError(null);
      setSuccessMessage(null);

      const response = await apiPost<CreateSupplierResponse, { name: string }>(
        `/purchasing/suppliers?org_id=${ORG_ID}`,
        { name: trimmedName },
      );

      setName("");
      setSuccessMessage(response.message);
      await loadSuppliers();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de créer le fournisseur.",
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <div style={{ padding: 24, maxWidth: 1080 }}>
      <header style={{ marginBottom: 28 }}>
        <div
          style={{
            display: "flex",
            justifyContent: "space-between",
            alignItems: "center",
            gap: 16,
            flexWrap: "wrap",
          }}
        >
          <div>
            <h1 style={{ margin: 0 }}>Achats & Matières</h1>
            <p style={{ margin: "7px 0 0", color: "#64748b" }}>
              Gérez les fournisseurs et les matières de référence.
            </p>
          </div>
          <div style={{ display: "flex", gap: 8 }}>
            <Link to="/purchasing" style={activeLinkStyle}>
              Fournisseurs
            </Link>
            <Link to="/purchasing/materials" style={navLinkStyle}>
              Matières
            </Link>
          </div>
        </div>
      </header>

      {error && <MessageBox kind="error">{error}</MessageBox>}
      {successMessage && <MessageBox kind="success">{successMessage}</MessageBox>}

      <section style={sectionStyle}>
        <h2 style={sectionTitleStyle}>Nouveau fournisseur</h2>
        <div style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
          <input
            type="text"
            value={name}
            onChange={(event) => setName(event.target.value)}
            placeholder="Nom du fournisseur"
            maxLength={160}
            style={{ ...inputStyle, flex: "1 1 300px" }}
          />
          <button
            type="button"
            onClick={() => void createSupplier()}
            disabled={saving}
            style={primaryButtonStyle}
          >
            {saving ? "Création..." : "Créer le fournisseur"}
          </button>
        </div>
      </section>

      <section style={sectionStyle}>
        <h2 style={sectionTitleStyle}>Fournisseurs</h2>
        {loading ? (
          <p style={{ margin: 0 }}>Chargement...</p>
        ) : suppliers.length === 0 ? (
          <p style={{ margin: 0, color: "#64748b" }}>
            Aucun fournisseur enregistré.
          </p>
        ) : (
          <div style={{ overflowX: "auto" }}>
            <table style={tableStyle}>
              <thead>
                <tr>
                  <th style={headerCellStyle}>Nom</th>
                  <th style={headerCellStyle}>Statut</th>
                  <th style={headerCellStyle}>Action</th>
                </tr>
              </thead>
              <tbody>
                {suppliers.map((supplier) => (
                  <tr key={supplier.id}>
                    <td style={cellStyle}>{supplier.name}</td>
                    <td style={cellStyle}>
                      <StatusLabel isActive={supplier.is_active} />
                    </td>
                    <td style={cellStyle}>
                      <Link
                        to={`/purchasing/suppliers/${supplier.id}`}
                        style={openLinkStyle}
                      >
                        Ouvrir
                      </Link>
                    </td>
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

function StatusLabel({ isActive }: { isActive: boolean }) {
  return (
    <span style={{ color: isActive ? "#166534" : "#64748b", fontWeight: 600 }}>
      {isActive ? "Actif" : "Inactif"}
    </span>
  );
}

function MessageBox({
  kind,
  children,
}: {
  kind: "error" | "success";
  children: React.ReactNode;
}) {
  const success = kind === "success";

  return (
    <div
      style={{
        marginBottom: 20,
        padding: 14,
        border: `1px solid ${success ? "#bbf7d0" : "#fecaca"}`,
        background: success ? "#f0fdf4" : "#fef2f2",
        borderRadius: 8,
        color: success ? "#166534" : "#991b1b",
      }}
    >
      {children}
    </div>
  );
}

const sectionStyle: React.CSSProperties = {
  marginBottom: 22,
  padding: 18,
  border: "1px solid #e2e8f0",
  borderRadius: 10,
  background: "white",
};

const sectionTitleStyle: React.CSSProperties = { marginTop: 0, marginBottom: 14, fontSize: 19 };
const inputStyle: React.CSSProperties = { padding: "9px 10px", border: "1px solid #cbd5e1", borderRadius: 8 };
const primaryButtonStyle: React.CSSProperties = { padding: "9px 14px", border: 0, borderRadius: 8, background: "#0f172a", color: "white", fontWeight: 600, cursor: "pointer" };
const navLinkStyle: React.CSSProperties = { padding: "8px 12px", borderRadius: 8, textDecoration: "none", color: "#475569", fontWeight: 600 };
const activeLinkStyle: React.CSSProperties = { ...navLinkStyle, background: "#e2e8f0", color: "#0f172a" };
const openLinkStyle: React.CSSProperties = { color: "#2563eb", fontWeight: 600, textDecoration: "none" };
const tableStyle: React.CSSProperties = { width: "100%", borderCollapse: "collapse", minWidth: 500 };
const headerCellStyle: React.CSSProperties = { textAlign: "left", padding: 10, borderBottom: "1px solid #cbd5e1", color: "#475569", fontSize: 13 };
const cellStyle: React.CSSProperties = { padding: 10, borderBottom: "1px solid #e2e8f0" };
