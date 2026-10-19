import { useCallback, useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { apiGet, apiPatch } from "../../api";

const ORG_ID = 1;

type Supplier = {
  id: number;
  name: string;
};

type MeasureUnit = {
  id: number;
  code: string;
  name: string;
  symbol: string;
  dimension: string;
  to_base_factor: string;
  is_active?: boolean;
};

type Material = {
  id: number;
  org_id: number;
  name: string;
  reference_unit_label: string | null;
  is_active: boolean;
  reference_measure_unit: MeasureUnit;
};

type SupplierItem = {
  id: number;
  supplier: Supplier;
  category: {
    id: number;
    parent_id: number | null;
    name: string;
    source_path: string;
  } | null;
  supplier_sku: string | null;
  name: string;
  brand: string | null;
  purchase_unit_label: string | null;
  pack_label: string | null;
  material: {
    id: number;
    name: string;
    reference_unit_label: string | null;
    reference_measure_unit: MeasureUnit;
  } | null;
  quantity_in_reference_unit: string | null;
  is_active: boolean;
};

type SupplierItemResponse = {
  ok: true;
  item: SupplierItem;
};

type SupplierItemPrice = {
  id: number;
  site_id: number | null;
  price_excl_tax: string;
  currency_code: string;
  effective_from: string;
  supplier_catalog: {
    id: number;
    name: string;
    effective_from: string;
    source_filename: string | null;
  } | null;
};

type SupplierItemPricesResponse = {
  ok: true;
  item_id: number;
  prices: SupplierItemPrice[];
};

type MaterialsResponse = {
  ok: true;
  org_id: number;
  materials: Material[];
};

type LinkMaterialResponse = {
  ok: true;
  item: SupplierItem;
  message: string;
};

function formatDate(date: string): string {
  const [year, month, day] = date.split("-");
  return `${day}/${month}/${year}`;
}

function formatReferenceQuantity(quantity: string): string {
  const [integerPart, decimalPart = ""] = quantity.split(".");
  const normalizedInteger = integerPart.replace(/^0+(?=\d)/, "") || "0";
  const groupedInteger = normalizedInteger.replace(
    /\B(?=(\d{3})+(?!\d))/g,
    " ",
  );
  const significantDecimals = decimalPart.replace(/0+$/, "");

  return significantDecimals === ""
    ? groupedInteger
    : `${groupedInteger},${significantDecimals}`;
}

function formatPrice(price: string, currencyCode: string): string {
  return `${new Intl.NumberFormat("fr-FR", {
    style: "currency",
    currency: currencyCode,
  }).format(Number(price))} HT`;
}

export default function SupplierItemDetailPage() {
  const { itemId: itemIdParam } = useParams();
  const itemId = Number(itemIdParam);
  const validItemId = Number.isInteger(itemId) && itemId > 0;

  const [item, setItem] = useState<SupplierItem | null>(null);
  const [prices, setPrices] = useState<SupplierItemPrice[]>([]);
  const [materials, setMaterials] = useState<Material[]>([]);
  const [selectedMaterialId, setSelectedMaterialId] = useState<number | null>(
    null,
  );
  const [quantityInReferenceUnit, setQuantityInReferenceUnit] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const loadItem = useCallback(async () => {
    if (!validItemId) {
      setItem(null);
      setPrices([]);
      setMaterials([]);
      setError("Identifiant d’article fournisseur invalide.");
      setLoading(false);
      return;
    }

    try {
      setLoading(true);
      setError(null);

      const [itemResponse, pricesResponse, materialsResponse] =
        await Promise.all([
          apiGet<SupplierItemResponse>(
            `/purchasing/supplier-items/${itemId}?org_id=${ORG_ID}`,
          ),
          apiGet<SupplierItemPricesResponse>(
            `/purchasing/supplier-items/${itemId}/prices?org_id=${ORG_ID}`,
          ),
          apiGet<MaterialsResponse>(`/purchasing/materials?org_id=${ORG_ID}`),
        ]);

      setItem(itemResponse.item);
      setPrices(pricesResponse.prices);
      setMaterials(materialsResponse.materials);
      setSelectedMaterialId(itemResponse.item.material?.id ?? null);
      setQuantityInReferenceUnit(
        itemResponse.item.quantity_in_reference_unit ?? "",
      );
    } catch (err) {
      setItem(null);
      setPrices([]);
      setMaterials([]);
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de charger l’article fournisseur.",
      );
    } finally {
      setLoading(false);
    }
  }, [itemId, validItemId]);

  useEffect(() => {
    void loadItem();
  }, [loadItem]);

  const selectedMaterial = materials.find(
    (material) => material.id === selectedMaterialId,
  );

  async function linkMaterial() {
    if (selectedMaterialId === null) {
      setError("Veuillez choisir une matière Tempo.");
      return;
    }

    try {
      setSaving(true);
      setError(null);
      setSuccessMessage(null);

      const response = await apiPatch<
        LinkMaterialResponse,
        {
          material_id: number;
          quantity_in_reference_unit: string | null;
        }
      >(`/purchasing/supplier-items/${itemId}/material-link?org_id=${ORG_ID}`, {
        material_id: selectedMaterialId,
        quantity_in_reference_unit:
          quantityInReferenceUnit.trim() === ""
            ? null
            : quantityInReferenceUnit.trim(),
      });

      setSuccessMessage(response.message);
      await loadItem();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de mettre à jour le rapprochement matière.",
      );
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return (
      <div style={{ padding: 24 }}>
        <h1>Article fournisseur</h1>
        <p>Chargement...</p>
      </div>
    );
  }

  if (!item) {
    return (
      <div style={{ padding: 24 }}>
        <Link to="/purchasing" style={backLinkStyle}>
          ← Retour aux fournisseurs
        </Link>
        {error && <MessageBox kind="error">{error}</MessageBox>}
      </div>
    );
  }

  const packaging = item.pack_label ?? item.purchase_unit_label ?? "—";

  return (
    <div style={{ padding: 24, maxWidth: 1080 }}>
      <Link
        to={`/purchasing/suppliers/${item.supplier.id}`}
        style={backLinkStyle}
      >
        ← Retour à {item.supplier.name}
      </Link>

      <header style={{ margin: "20px 0 28px" }}>
        <h1 style={{ margin: 0 }}>Article fournisseur</h1>
        <p style={{ margin: "7px 0 0", color: "#64748b" }}>
          {item.name}
        </p>
      </header>

      {error && <MessageBox kind="error">{error}</MessageBox>}
      {successMessage && <MessageBox kind="success">{successMessage}</MessageBox>}

      <section style={sectionStyle}>
        <h2 style={sectionTitleStyle}>Informations article</h2>
        <dl style={detailsGridStyle}>
          <Detail label="Fournisseur" value={item.supplier.name} />
          <Detail label="Référence fournisseur" value={item.supplier_sku ?? "—"} />
          <Detail label="Nom" value={item.name} />
          <Detail label="Marque" value={item.brand ?? "—"} />
          <Detail label="Catégorie" value={item.category?.name ?? "Sans catégorie"} />
          <Detail label="Conditionnement" value={packaging} />
          <Detail label="Statut" value={item.is_active ? "Actif" : "Inactif"} />
        </dl>
      </section>

      <section style={sectionStyle}>
        <h2 style={sectionTitleStyle}>Rapprochement matière</h2>
        <dl style={detailsGridStyle}>
          <Detail
            label="Matière Tempo"
            value={
              item.material?.name ?? (
                <span style={{ color: "#a16207", fontWeight: 600 }}>
                  Non rapprochée
                </span>
              )
            }
          />
          <Detail
            label="Quantité de référence"
            value={
              item.material === null || item.quantity_in_reference_unit === null
                ? "—"
                : `${formatReferenceQuantity(item.quantity_in_reference_unit)} ${item.material.reference_measure_unit.symbol}`
            }
          />
        </dl>

        <div style={formGridStyle}>
          <label style={labelStyle}>
            Matière Tempo
            <select
              value={selectedMaterialId ?? ""}
              onChange={(event) =>
                setSelectedMaterialId(
                  event.target.value === "" ? null : Number(event.target.value),
                )
              }
              style={inputStyle}
            >
              <option value="">Choisir une matière</option>
              {materials.map((material) => (
                <option key={material.id} value={material.id}>
                  {material.name}
                </option>
              ))}
            </select>
          </label>

          <label style={labelStyle}>
            Quantité dans l’unité de référence
            <input
              type="number"
              inputMode="decimal"
              min="0"
              step="any"
              value={quantityInReferenceUnit}
              onChange={(event) => setQuantityInReferenceUnit(event.target.value)}
              style={inputStyle}
            />
          </label>

          <div style={unitInfoStyle}>
            <span style={{ color: "#64748b", fontSize: 13 }}>Unité</span>
            <strong>{selectedMaterial?.reference_measure_unit.symbol ?? "—"}</strong>
          </div>
        </div>

        <button
          type="button"
          onClick={() => void linkMaterial()}
          disabled={saving || selectedMaterialId === null}
          style={primaryButtonStyle}
        >
          {saving ? "Enregistrement..." : "Enregistrer le rapprochement"}
        </button>
      </section>

      <section style={sectionStyle}>
        <h2 style={sectionTitleStyle}>Historique des prix</h2>
        {prices.length === 0 ? (
          <p style={{ margin: 0, color: "#64748b" }}>
            Aucun tarif enregistré.
          </p>
        ) : (
          <div style={{ overflowX: "auto" }}>
            <table style={tableStyle}>
              <thead>
                <tr>
                  <th style={headerCellStyle}>Date d’effet</th>
                  <th style={headerCellStyle}>Prix HT</th>
                  <th style={headerCellStyle}>Devise</th>
                  <th style={headerCellStyle}>Catalogue</th>
                  <th style={headerCellStyle}>Portée</th>
                </tr>
              </thead>
              <tbody>
                {prices.map((price) => (
                  <tr key={price.id}>
                    <td style={cellStyle}>{formatDate(price.effective_from)}</td>
                    <td style={cellStyle}>
                      {formatPrice(price.price_excl_tax, price.currency_code)}
                    </td>
                    <td style={cellStyle}>{price.currency_code}</td>
                    <td style={cellStyle}>{price.supplier_catalog?.name ?? "—"}</td>
                    <td style={cellStyle}>
                      {price.site_id === null
                        ? "Organisation"
                        : `Site #${price.site_id}`}
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

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <dt style={{ color: "#64748b", fontSize: 13 }}>{label}</dt>
      <dd style={{ margin: "4px 0 0", fontWeight: 600 }}>{value}</dd>
    </div>
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

const backLinkStyle: React.CSSProperties = {
  color: "#475569",
  textDecoration: "none",
  fontWeight: 600,
};

const sectionStyle: React.CSSProperties = {
  marginBottom: 22,
  padding: 18,
  border: "1px solid #e2e8f0",
  borderRadius: 10,
  background: "white",
};

const sectionTitleStyle: React.CSSProperties = {
  marginTop: 0,
  marginBottom: 14,
  fontSize: 19,
};

const detailsGridStyle: React.CSSProperties = {
  display: "grid",
  gridTemplateColumns: "repeat(auto-fit, minmax(180px, 1fr))",
  gap: 18,
  margin: 0,
};

const formGridStyle: React.CSSProperties = {
  display: "grid",
  gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))",
  gap: 12,
  marginTop: 22,
  marginBottom: 14,
};

const labelStyle: React.CSSProperties = {
  display: "grid",
  gap: 5,
  color: "#475569",
  fontSize: 13,
};

const inputStyle: React.CSSProperties = {
  width: "100%",
  padding: "9px 10px",
  border: "1px solid #cbd5e1",
  borderRadius: 8,
  background: "white",
};

const unitInfoStyle: React.CSSProperties = {
  display: "grid",
  alignContent: "end",
  gap: 5,
  paddingBottom: 10,
};

const primaryButtonStyle: React.CSSProperties = {
  padding: "9px 14px",
  border: 0,
  borderRadius: 8,
  background: "#0f172a",
  color: "white",
  fontWeight: 600,
  cursor: "pointer",
};

const tableStyle: React.CSSProperties = {
  width: "100%",
  borderCollapse: "collapse",
  minWidth: 700,
};

const headerCellStyle: React.CSSProperties = {
  textAlign: "left",
  padding: 10,
  borderBottom: "1px solid #cbd5e1",
  color: "#475569",
  fontSize: 13,
};

const cellStyle: React.CSSProperties = {
  padding: 10,
  borderBottom: "1px solid #e2e8f0",
};
