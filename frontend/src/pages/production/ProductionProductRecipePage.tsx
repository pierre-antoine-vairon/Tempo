import { useCallback, useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { apiGet } from "../../api";

const ORG_ID = 1;

type ProductSummary = {
  id: number;
  name: string;
};

type ReferenceMeasureUnit = {
  id: number;
  code: string;
  name: string;
  symbol: string;
};

type RecipeLine = {
  id: number;
  material: {
    id: number;
    name: string;
    reference_unit_label: string | null;
    reference_measure_unit: ReferenceMeasureUnit;
  };
  quantity_in_reference_unit: string;
};

type Recipe = {
  id: number;
  product: ProductSummary;
  effective_from: string;
  lines: RecipeLine[];
};

type RecipeVersion = {
  id: number;
  effective_from: string;
  lines_count: number;
};

type RecipesResponse = {
  ok: true;
  org_id: number;
  site_id: number;
  product: ProductSummary;
  recipes: RecipeVersion[];
};

type RecipeAtDateResponse = {
  ok: true;
  product: ProductSummary;
  date: string;
  recipe: Recipe | null;
};

function localDateString(): string {
  const today = new Date();
  const year = today.getFullYear();
  const month = String(today.getMonth() + 1).padStart(2, "0");
  const day = String(today.getDate()).padStart(2, "0");

  return `${year}-${month}-${day}`;
}

function formatDate(date: string): string {
  const [year, month, day] = date.split("-");

  return `${day}/${month}/${year}`;
}

function formatQuantity(quantity: string): string {
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

export default function ProductionProductRecipePage() {
  const { productId: productIdParam } = useParams();
  const productId = Number(productIdParam);
  const validProductId = Number.isInteger(productId) && productId > 0;

  const [product, setProduct] = useState<ProductSummary | null>(null);
  const [activeRecipe, setActiveRecipe] = useState<Recipe | null>(null);
  const [versions, setVersions] = useState<RecipeVersion[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [referenceDate, setReferenceDate] = useState("");

  const loadRecipe = useCallback(async () => {
    if (!validProductId) {
      setProduct(null);
      setActiveRecipe(null);
      setVersions([]);
      setError("Identifiant de produit invalide.");
      setLoading(false);
      return;
    }

    const date = localDateString();

    try {
      setLoading(true);
      setError(null);

      const [versionsResponse, activeRecipeResponse] = await Promise.all([
        apiGet<RecipesResponse>(
          `/production/products/${productId}/recipes?org_id=${ORG_ID}`,
        ),
        apiGet<RecipeAtDateResponse>(
          `/production/products/${productId}/recipe-at-date?org_id=${ORG_ID}&date=${date}`,
        ),
      ]);

      setProduct(activeRecipeResponse.product);
      setActiveRecipe(activeRecipeResponse.recipe);
      setVersions(versionsResponse.recipes);
      setReferenceDate(activeRecipeResponse.date);
    } catch (err) {
      setProduct(null);
      setActiveRecipe(null);
      setVersions([]);
      setReferenceDate("");
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de charger la fiche technique.",
      );
    } finally {
      setLoading(false);
    }
  }, [productId, validProductId]);

  useEffect(() => {
    void loadRecipe();
  }, [loadRecipe]);

  if (loading) {
    return (
      <div style={{ padding: 24 }}>
        <h2>Fiche technique</h2>
        <p>Chargement...</p>
      </div>
    );
  }

  if (!product) {
    return (
      <div style={{ padding: 24 }}>
        <Link to="/production/products" style={backLinkStyle}>
          ← Retour aux produits
        </Link>
        {error && <MessageBox>{error}</MessageBox>}
      </div>
    );
  }

  return (
    <div style={{ padding: 24, maxWidth: 1000 }}>
      <Link to="/production/products" style={backLinkStyle}>
        ← Retour aux produits
      </Link>

      <header style={{ margin: "20px 0 28px" }}>
        <h2 style={{ margin: 0 }}>Fiche technique</h2>
        <p style={{ margin: "7px 0 0", color: "#64748b" }}>{product.name}</p>
      </header>

      {error && <MessageBox>{error}</MessageBox>}

      <section style={sectionStyle}>
        <h3 style={sectionTitleStyle}>Fiche technique active</h3>

        {activeRecipe === null ? (
          <p style={{ margin: 0, color: "#64748b" }}>
            Aucune fiche technique applicable à cette date.
          </p>
        ) : (
          <>
            <p style={{ margin: "0 0 16px", color: "#64748b" }}>
              Applicable à partir du {formatDate(activeRecipe.effective_from)}
              {referenceDate !== "" ? ` (au ${formatDate(referenceDate)})` : ""}.
            </p>

            <div style={{ overflowX: "auto" }}>
              <table style={tableStyle}>
                <thead>
                  <tr>
                    <th style={headerCellStyle}>Matière</th>
                    <th style={headerCellStyle}>Quantité</th>
                    <th style={headerCellStyle}>Unité</th>
                  </tr>
                </thead>
                <tbody>
                  {activeRecipe.lines.map((line) => (
                    <tr key={line.id}>
                      <td style={cellStyle}>{line.material.name}</td>
                      <td style={cellStyle}>
                        {formatQuantity(line.quantity_in_reference_unit)}
                      </td>
                      <td style={cellStyle}>
                        {line.material.reference_measure_unit.symbol}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </section>

      <section style={sectionStyle}>
        <h3 style={sectionTitleStyle}>Historique des versions</h3>

        {versions.length === 0 ? (
          <p style={{ margin: 0, color: "#64748b" }}>
            Aucune version de fiche technique enregistrée.
          </p>
        ) : (
          <div style={{ overflowX: "auto" }}>
            <table style={tableStyle}>
              <thead>
                <tr>
                  <th style={headerCellStyle}>Date d’effet</th>
                  <th style={headerCellStyle}>Matières</th>
                </tr>
              </thead>
              <tbody>
                {versions.map((version) => (
                  <tr key={version.id}>
                    <td style={cellStyle}>{formatDate(version.effective_from)}</td>
                    <td style={cellStyle}>{version.lines_count}</td>
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

function MessageBox({ children }: { children: React.ReactNode }) {
  return (
    <div
      style={{
        marginTop: 20,
        marginBottom: 20,
        padding: 14,
        border: "1px solid #fecaca",
        background: "#fef2f2",
        borderRadius: 8,
        color: "#991b1b",
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

const tableStyle: React.CSSProperties = {
  width: "100%",
  borderCollapse: "collapse",
};

const headerCellStyle: React.CSSProperties = {
  padding: "11px 12px",
  borderBottom: "1px solid #e2e8f0",
  background: "#f8fafc",
  color: "#475569",
  fontSize: 13,
  textAlign: "left",
};

const cellStyle: React.CSSProperties = {
  padding: "12px",
  borderBottom: "1px solid #e2e8f0",
};
