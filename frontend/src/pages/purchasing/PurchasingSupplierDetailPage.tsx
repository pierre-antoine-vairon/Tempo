import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { apiGet, apiPost } from "../../api";

const ORG_ID = 1;

type Supplier = { id: number; name: string };

type SupplierCategory = {
  id: number;
  parent_id: number | null;
  name: string;
  source_path: string;
  is_active: boolean;
};

type SupplierCatalog = {
  id: number;
  name: string;
  effective_from: string;
  source_filename: string | null;
};

type MeasureUnit = {
  id: number;
  code: string;
  name: string;
  symbol: string;
  dimension: string;
  to_base_factor: string;
};

type SupplierItem = {
  id: number;
  supplier: Supplier;
  category: Omit<SupplierCategory, "is_active"> | null;
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

type CategoriesResponse = {
  ok: true;
  org_id: number;
  supplier: Supplier;
  categories: SupplierCategory[];
};

type CatalogsResponse = {
  ok: true;
  org_id: number;
  supplier: Supplier;
  catalogs: SupplierCatalog[];
};

type ItemsResponse = {
  ok: true;
  org_id: number;
  supplier: Supplier;
  items: SupplierItem[];
};

type CreateCategoryResponse = { ok: true; category: SupplierCategory; message: string };
type CreateCatalogResponse = { ok: true; catalog: SupplierCatalog; message: string };
type CreateItemResponse = { ok: true; item: SupplierItem; message: string };

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

function nullableText(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

export default function PurchasingSupplierDetailPage() {
  const { supplierId: supplierIdParam } = useParams();
  const supplierId = Number(supplierIdParam);
  const validSupplierId = Number.isInteger(supplierId) && supplierId > 0;

  const [supplier, setSupplier] = useState<Supplier | null>(null);
  const [categories, setCategories] = useState<SupplierCategory[]>([]);
  const [catalogs, setCatalogs] = useState<SupplierCatalog[]>([]);
  const [items, setItems] = useState<SupplierItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const [categoryName, setCategoryName] = useState("");
  const [categorySourcePath, setCategorySourcePath] = useState("");
  const [categoryParentId, setCategoryParentId] = useState<number | null>(null);
  const [savingCategory, setSavingCategory] = useState(false);

  const [catalogName, setCatalogName] = useState("");
  const [catalogEffectiveFrom, setCatalogEffectiveFrom] = useState("");
  const [catalogSourceFilename, setCatalogSourceFilename] = useState("");
  const [savingCatalog, setSavingCatalog] = useState(false);

  const [itemCategoryId, setItemCategoryId] = useState<number | null>(null);
  const [itemSupplierSku, setItemSupplierSku] = useState("");
  const [itemName, setItemName] = useState("");
  const [itemBrand, setItemBrand] = useState("");
  const [itemPurchaseUnitLabel, setItemPurchaseUnitLabel] = useState("");
  const [itemPackLabel, setItemPackLabel] = useState("");
  const [savingItem, setSavingItem] = useState(false);

  const loadSupplier = useCallback(async () => {
    if (!validSupplierId) {
      setSupplier(null);
      setCategories([]);
      setCatalogs([]);
      setItems([]);
      setError("Identifiant de fournisseur invalide.");
      setLoading(false);
      return;
    }

    try {
      setLoading(true);
      setError(null);

      const [categoriesResponse, catalogsResponse, itemsResponse] =
        await Promise.all([
          apiGet<CategoriesResponse>(
            `/purchasing/suppliers/${supplierId}/categories?org_id=${ORG_ID}`,
          ),
          apiGet<CatalogsResponse>(
            `/purchasing/suppliers/${supplierId}/catalogs?org_id=${ORG_ID}`,
          ),
          apiGet<ItemsResponse>(
            `/purchasing/suppliers/${supplierId}/items?org_id=${ORG_ID}`,
          ),
        ]);

      setSupplier(categoriesResponse.supplier);
      setCategories(categoriesResponse.categories);
      setCatalogs(catalogsResponse.catalogs);
      setItems(itemsResponse.items);
    } catch (err) {
      setSupplier(null);
      setCategories([]);
      setCatalogs([]);
      setItems([]);
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de charger le fournisseur.",
      );
    } finally {
      setLoading(false);
    }
  }, [supplierId, validSupplierId]);

  useEffect(() => {
    void loadSupplier();
  }, [loadSupplier]);

  const categoriesById = useMemo(
    () => new Map(categories.map((category) => [category.id, category])),
    [categories],
  );

  async function createCategory() {
    const name = categoryName.trim();

    if (name === "" || categorySourcePath.trim() === "") {
      setError("Le nom et le chemin source de la catégorie sont obligatoires.");
      return;
    }

    try {
      setSavingCategory(true);
      setError(null);
      setSuccessMessage(null);

      const response = await apiPost<
        CreateCategoryResponse,
        { name: string; parent_id: number | null; source_path: string }
      >(`/purchasing/suppliers/${supplierId}/categories?org_id=${ORG_ID}`, {
        name,
        parent_id: categoryParentId,
        source_path: categorySourcePath,
      });

      setCategoryName("");
      setCategorySourcePath("");
      setCategoryParentId(null);
      setSuccessMessage(response.message);
      await loadSupplier();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de créer la catégorie fournisseur.",
      );
    } finally {
      setSavingCategory(false);
    }
  }

  async function createCatalog() {
    const name = catalogName.trim();

    if (name === "" || catalogEffectiveFrom === "") {
      setError("Le nom et la date d’effet du catalogue sont obligatoires.");
      return;
    }

    try {
      setSavingCatalog(true);
      setError(null);
      setSuccessMessage(null);

      const response = await apiPost<
        CreateCatalogResponse,
        { name: string; effective_from: string; source_filename: string | null }
      >(`/purchasing/suppliers/${supplierId}/catalogs?org_id=${ORG_ID}`, {
        name,
        effective_from: catalogEffectiveFrom,
        source_filename: nullableText(catalogSourceFilename),
      });

      setCatalogName("");
      setCatalogEffectiveFrom("");
      setCatalogSourceFilename("");
      setSuccessMessage(response.message);
      await loadSupplier();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de créer le catalogue fournisseur.",
      );
    } finally {
      setSavingCatalog(false);
    }
  }

  async function createItem() {
    const name = itemName.trim();

    if (name === "") {
      setError("Le nom de l’article fournisseur est obligatoire.");
      return;
    }

    try {
      setSavingItem(true);
      setError(null);
      setSuccessMessage(null);

      const response = await apiPost<
        CreateItemResponse,
        {
          supplier_category_id: number | null;
          supplier_sku: string | null;
          name: string;
          brand: string | null;
          purchase_unit_label: string | null;
          pack_label: string | null;
          material_id: null;
          quantity_in_reference_unit: null;
        }
      >(`/purchasing/suppliers/${supplierId}/items?org_id=${ORG_ID}`, {
        supplier_category_id: itemCategoryId,
        supplier_sku: nullableText(itemSupplierSku),
        name,
        brand: nullableText(itemBrand),
        purchase_unit_label: nullableText(itemPurchaseUnitLabel),
        pack_label: nullableText(itemPackLabel),
        material_id: null,
        quantity_in_reference_unit: null,
      });

      setItemCategoryId(null);
      setItemSupplierSku("");
      setItemName("");
      setItemBrand("");
      setItemPurchaseUnitLabel("");
      setItemPackLabel("");
      setSuccessMessage(response.message);
      await loadSupplier();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de créer l’article fournisseur.",
      );
    } finally {
      setSavingItem(false);
    }
  }

  return (
    <div style={{ padding: 24, maxWidth: 1280 }}>
      <Link to="/purchasing" style={backLinkStyle}>
        ← Retour aux fournisseurs
      </Link>

      <header style={{ margin: "20px 0 28px" }}>
        <h1 style={{ margin: 0 }}>Fournisseur</h1>
        <p style={{ margin: "7px 0 0", color: "#64748b" }}>
          {supplier ? supplier.name : validSupplierId ? "Chargement..." : "Fournisseur invalide"}
        </p>
      </header>

      {error && <MessageBox kind="error">{error}</MessageBox>}
      {successMessage && <MessageBox kind="success">{successMessage}</MessageBox>}

      {loading ? (
        <p>Chargement...</p>
      ) : !supplier ? null : (
        <>
          <section style={sectionStyle}>
            <h2 style={sectionTitleStyle}>Catégories</h2>
            <div style={formGridStyle}>
              <Field label="Nom">
                <input type="text" value={categoryName} onChange={(event) => setCategoryName(event.target.value)} maxLength={160} style={inputStyle} />
              </Field>
              <Field label="Chemin source">
                <input type="text" value={categorySourcePath} onChange={(event) => setCategorySourcePath(event.target.value)} maxLength={500} placeholder="FOURRAGES" style={inputStyle} />
              </Field>
              <Field label="Parent optionnel">
                <select value={categoryParentId ?? ""} onChange={(event) => setCategoryParentId(event.target.value === "" ? null : Number(event.target.value))} style={inputStyle}>
                  <option value="">Sans parent</option>
                  {categories.map((category) => <option key={category.id} value={category.id}>{category.source_path}</option>)}
                </select>
              </Field>
            </div>
            <button type="button" onClick={() => void createCategory()} disabled={savingCategory} style={primaryButtonStyle}>
              {savingCategory ? "Création..." : "Créer la catégorie"}
            </button>

            <div style={{ marginTop: 22 }}>
              {categories.length === 0 ? <EmptyState>Aucune catégorie enregistrée.</EmptyState> : (
                <div style={{ overflowX: "auto" }}>
                  <table style={tableStyle}>
                    <thead><tr><th style={headerCellStyle}>Nom</th><th style={headerCellStyle}>Chemin source</th><th style={headerCellStyle}>Parent</th><th style={headerCellStyle}>Statut</th></tr></thead>
                    <tbody>{categories.map((category) => (
                      <tr key={category.id}>
                        <td style={cellStyle}>{category.name}</td>
                        <td style={cellStyle}>{category.source_path}</td>
                        <td style={cellStyle}>{category.parent_id === null ? "—" : categoriesById.get(category.parent_id)?.name ?? "—"}</td>
                        <td style={cellStyle}><StatusLabel isActive={category.is_active} /></td>
                      </tr>
                    ))}</tbody>
                  </table>
                </div>
              )}
            </div>
          </section>

          <section style={sectionStyle}>
            <h2 style={sectionTitleStyle}>Catalogues</h2>
            <div style={formGridStyle}>
              <Field label="Nom du catalogue"><input type="text" value={catalogName} onChange={(event) => setCatalogName(event.target.value)} maxLength={160} style={inputStyle} /></Field>
              <Field label="Date d’effet"><input type="date" value={catalogEffectiveFrom} onChange={(event) => setCatalogEffectiveFrom(event.target.value)} style={inputStyle} /></Field>
              <Field label="Nom du fichier source optionnel"><input type="text" value={catalogSourceFilename} onChange={(event) => setCatalogSourceFilename(event.target.value)} maxLength={255} style={inputStyle} /></Field>
            </div>
            <button type="button" onClick={() => void createCatalog()} disabled={savingCatalog} style={primaryButtonStyle}>
              {savingCatalog ? "Création..." : "Créer le catalogue"}
            </button>

            <div style={{ marginTop: 22 }}>
              {catalogs.length === 0 ? <EmptyState>Aucun catalogue enregistré.</EmptyState> : (
                <div style={{ overflowX: "auto" }}>
                  <table style={tableStyle}>
                    <thead><tr><th style={headerCellStyle}>Nom</th><th style={headerCellStyle}>Date d’effet</th><th style={headerCellStyle}>Fichier source</th></tr></thead>
                    <tbody>{catalogs.map((catalog) => <tr key={catalog.id}><td style={cellStyle}>{catalog.name}</td><td style={cellStyle}>{formatDate(catalog.effective_from)}</td><td style={cellStyle}>{catalog.source_filename ?? "—"}</td></tr>)}</tbody>
                  </table>
                </div>
              )}
            </div>
          </section>

          <section style={sectionStyle}>
            <h2 style={sectionTitleStyle}>Articles</h2>
            <div style={itemFormGridStyle}>
              <Field label="Catégorie optionnelle">
                <select value={itemCategoryId ?? ""} onChange={(event) => setItemCategoryId(event.target.value === "" ? null : Number(event.target.value))} style={inputStyle}>
                  <option value="">Sans catégorie</option>
                  {categories.map((category) => <option key={category.id} value={category.id}>{category.source_path}</option>)}
                </select>
              </Field>
              <Field label="Référence fournisseur optionnelle"><input type="text" value={itemSupplierSku} onChange={(event) => setItemSupplierSku(event.target.value)} maxLength={100} style={inputStyle} /></Field>
              <Field label="Nom"><input type="text" value={itemName} onChange={(event) => setItemName(event.target.value)} maxLength={160} style={inputStyle} /></Field>
              <Field label="Marque optionnelle"><input type="text" value={itemBrand} onChange={(event) => setItemBrand(event.target.value)} maxLength={160} style={inputStyle} /></Field>
              <Field label="Unité d’achat / libellé optionnel"><input type="text" value={itemPurchaseUnitLabel} onChange={(event) => setItemPurchaseUnitLabel(event.target.value)} maxLength={50} style={inputStyle} /></Field>
              <Field label="Conditionnement optionnel"><input type="text" value={itemPackLabel} onChange={(event) => setItemPackLabel(event.target.value)} maxLength={160} style={inputStyle} /></Field>
            </div>
            <button type="button" onClick={() => void createItem()} disabled={savingItem} style={primaryButtonStyle}>
              {savingItem ? "Création..." : "Créer l’article"}
            </button>

            <div style={{ marginTop: 22 }}>
              {items.length === 0 ? <EmptyState>Aucun article enregistré.</EmptyState> : (
                <div style={{ overflowX: "auto" }}>
                  <table style={{ ...tableStyle, minWidth: 1120 }}>
                    <thead><tr><th style={headerCellStyle}>Référence fournisseur</th><th style={headerCellStyle}>Nom</th><th style={headerCellStyle}>Marque</th><th style={headerCellStyle}>Catégorie</th><th style={headerCellStyle}>Conditionnement</th><th style={headerCellStyle}>Matière Tempo</th><th style={headerCellStyle}>Quantité de référence</th><th style={headerCellStyle}>Statut</th></tr></thead>
                    <tbody>{items.map((item) => (
                      <tr key={item.id}>
                        <td style={cellStyle}>{item.supplier_sku ?? "—"}</td>
                        <td style={cellStyle}>{item.name}</td>
                        <td style={cellStyle}>{item.brand ?? "—"}</td>
                        <td style={cellStyle}>{item.category?.name ?? "Sans catégorie"}</td>
                        <td style={cellStyle}>{item.pack_label ?? item.purchase_unit_label ?? "—"}</td>
                        <td style={cellStyle}>{item.material?.name ?? <span style={{ color: "#a16207", fontWeight: 600 }}>Non rapprochée</span>}</td>
                        <td style={cellStyle}>{item.quantity_in_reference_unit === null || item.material === null ? "—" : `${formatReferenceQuantity(item.quantity_in_reference_unit)} ${item.material.reference_measure_unit.symbol}`}</td>
                        <td style={cellStyle}><StatusLabel isActive={item.is_active} /></td>
                      </tr>
                    ))}</tbody>
                  </table>
                </div>
              )}
            </div>
          </section>
        </>
      )}
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) { return <label style={labelStyle}>{label}{children}</label>; }
function EmptyState({ children }: { children: React.ReactNode }) { return <p style={{ margin: 0, color: "#64748b" }}>{children}</p>; }
function StatusLabel({ isActive }: { isActive: boolean }) { return <span style={{ color: isActive ? "#166534" : "#64748b", fontWeight: 600 }}>{isActive ? "Actif" : "Inactif"}</span>; }
function MessageBox({ kind, children }: { kind: "error" | "success"; children: React.ReactNode }) { const success = kind === "success"; return <div style={{ marginBottom: 20, padding: 14, border: `1px solid ${success ? "#bbf7d0" : "#fecaca"}`, background: success ? "#f0fdf4" : "#fef2f2", borderRadius: 8, color: success ? "#166534" : "#991b1b" }}>{children}</div>; }

const backLinkStyle: React.CSSProperties = { color: "#475569", textDecoration: "none", fontWeight: 600 };
const sectionStyle: React.CSSProperties = { marginBottom: 22, padding: 18, border: "1px solid #e2e8f0", borderRadius: 10, background: "white" };
const sectionTitleStyle: React.CSSProperties = { marginTop: 0, marginBottom: 14, fontSize: 19 };
const formGridStyle: React.CSSProperties = { display: "grid", gridTemplateColumns: "repeat(3, minmax(180px, 1fr))", gap: 12, marginBottom: 14 };
const itemFormGridStyle: React.CSSProperties = { display: "grid", gridTemplateColumns: "repeat(3, minmax(180px, 1fr))", gap: 12, marginBottom: 14 };
const labelStyle: React.CSSProperties = { display: "grid", gap: 5, color: "#475569", fontSize: 13 };
const inputStyle: React.CSSProperties = { width: "100%", padding: "9px 10px", border: "1px solid #cbd5e1", borderRadius: 8, background: "white" };
const primaryButtonStyle: React.CSSProperties = { padding: "9px 14px", border: 0, borderRadius: 8, background: "#0f172a", color: "white", fontWeight: 600, cursor: "pointer" };
const tableStyle: React.CSSProperties = { width: "100%", borderCollapse: "collapse", minWidth: 680 };
const headerCellStyle: React.CSSProperties = { textAlign: "left", padding: 10, borderBottom: "1px solid #cbd5e1", color: "#475569", fontSize: 13, verticalAlign: "bottom" };
const cellStyle: React.CSSProperties = { padding: 10, borderBottom: "1px solid #e2e8f0", verticalAlign: "top" };
