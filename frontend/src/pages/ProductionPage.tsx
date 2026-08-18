import { useCallback, useEffect, useMemo, useState } from "react";
import { apiGet, apiPut } from "../api";

type NullableNumber = number | null;

type ProductionProduct = {
  product_id: number;
  name: string;

  // Grande famille : Salé / Sucré
  family: string;

  // Sous-catégorie : Panini, Pizza, Muffin, etc.
  category: string;

  conservation: string | null;

  entry_id: number | null;
  production_date: string | null;

  stock_previous: NullableNumber;
  production: NullableNumber;
  reproduction: NullableNumber;
  losses: NullableNumber;
  sales: NullableNumber;
  stock_end: NullableNumber;
};

type ProductionResponse = {
  org_id: number;
  site_id: number;
  date: string;
  products: ProductionProduct[];
};

type ProductionEntryPayload = {
  product_id: number;
  stock_previous: NullableNumber;
  production: NullableNumber;
  reproduction: NullableNumber;
  losses: NullableNumber;
  sales: NullableNumber;
  stock_end: NullableNumber;
};

type SaveProductionResponse = {
  ok: boolean;
  org_id: number;
  site_id: number;
  date: string;
  saved_entries: number;
};

type EditableField =
  | "stock_previous"
  | "production"
  | "reproduction"
  | "losses"
  | "sales"
  | "stock_end";

/*
|--------------------------------------------------------------------------
| Date locale du jour
|--------------------------------------------------------------------------
*/

function getTodayLocal(): string {
  const now = new Date();

  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");

  return `${year}-${month}-${day}`;
}

/*
|--------------------------------------------------------------------------
| Page Production
|--------------------------------------------------------------------------
*/

export default function ProductionPage() {
  const [date, setDate] = useState(getTodayLocal());
  const [products, setProducts] = useState<ProductionProduct[]>([]);

  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const [error, setError] = useState<string | null>(null);
  const [savedMessage, setSavedMessage] = useState<string | null>(null);

  /*
  |--------------------------------------------------------------------------
  | Site
  |--------------------------------------------------------------------------
  |
  | Pour le MVP, le site est encore fixé à 1.
  |
  */

  const siteId = 1;

  /*
  |--------------------------------------------------------------------------
  | Chargement de la feuille
  |--------------------------------------------------------------------------
  */

  const loadProduction = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);

      const data = await apiGet<ProductionResponse>(
        `/production?site_id=${siteId}&date=${date}`,
      );

      setProducts(data.products);
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de charger la production.",
      );
    } finally {
      setLoading(false);
    }
  }, [date, siteId]);

  /*
  |--------------------------------------------------------------------------
  | Rechargement lors du changement de date
  |--------------------------------------------------------------------------
  */

  useEffect(() => {
    setSavedMessage(null);
    void loadProduction();
  }, [loadProduction]);

  /*
  |--------------------------------------------------------------------------
  | Modification d'une valeur
  |--------------------------------------------------------------------------
  */

  function updateProduct(
    productId: number,
    field: EditableField,
    value: string,
  ) {
    setSavedMessage(null);

    setProducts((currentProducts) =>
      currentProducts.map((product) => {
        if (product.product_id !== productId) {
          return product;
        }

        return {
          ...product,

          // Champ vide = NULL
          // "0" = vrai zéro
          [field]: value === "" ? null : Number(value),
        };
      }),
    );
  }

  /*
  |--------------------------------------------------------------------------
  | Enregistrement
  |--------------------------------------------------------------------------
  */

  async function saveProduction() {
    try {
      setSaving(true);
      setError(null);
      setSavedMessage(null);

      const entries: ProductionEntryPayload[] = products.map((product) => ({
        product_id: product.product_id,

        /*
         * Protection frontend :
         * un produit avec conservation J
         * ne peut jamais avoir de Stock J-1.
         */
        stock_previous:
          product.conservation === "J" ? null : product.stock_previous,

        production: product.production,
        reproduction: product.reproduction,
        losses: product.losses,
        sales: product.sales,
        stock_end: product.stock_end,
      }));

      const response = await apiPut<
        SaveProductionResponse,
        {
          site_id: number;
          date: string;
          entries: ProductionEntryPayload[];
        }
      >("/production", {
        site_id: siteId,
        date,
        entries,
      });

      setSavedMessage(
        `Production enregistrée (${response.saved_entries} lignes enregistrées).`,
      );

      /*
       * Recharge les données réellement enregistrées en DB.
       */
      await loadProduction();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Impossible d'enregistrer la production.",
      );
    } finally {
      setSaving(false);
    }
  }

  /*
  |--------------------------------------------------------------------------
  | Regroupement Famille -> Catégorie -> Produits
  |--------------------------------------------------------------------------
  |
  | Exemple :
  |
  | Salé
  |   Panini
  |   Pizza
  |   Plat
  |
  | Sucré
  |   Cookie
  |   Donut
  |   Muffin
  |
  */

  const families = useMemo(() => {
    const grouped = new Map<string, Map<string, ProductionProduct[]>>();

    for (const product of products) {
      /*
       * Création de la famille si elle n'existe pas encore.
       */
      if (!grouped.has(product.family)) {
        grouped.set(product.family, new Map());
      }

      const familyCategories = grouped.get(product.family)!;

      /*
       * Création de la catégorie dans la famille.
       */
      if (!familyCategories.has(product.category)) {
        familyCategories.set(product.category, []);
      }

      /*
       * Ajout du produit dans sa catégorie.
       */
      familyCategories.get(product.category)!.push(product);
    }

    /*
     * Ordre métier imposé :
     *
     * 1. Salé
     * 2. Sucré
     */
    const familyOrder = ["Salé", "Sucré"];

    return Array.from(grouped.entries()).sort(([familyA], [familyB]) => {
      const indexA = familyOrder.indexOf(familyA);
      const indexB = familyOrder.indexOf(familyB);

      const orderA = indexA === -1 ? familyOrder.length : indexA;
      const orderB = indexB === -1 ? familyOrder.length : indexB;

      return orderA - orderB;
    });
  }, [products]);

  /*
  |--------------------------------------------------------------------------
  | Chargement
  |--------------------------------------------------------------------------
  */

  if (loading) {
    return (
      <div style={{ padding: 24 }}>
        <h1>Production</h1>
        <p>Chargement...</p>
      </div>
    );
  }

  /*
  |--------------------------------------------------------------------------
  | Affichage
  |--------------------------------------------------------------------------
  */

  return (
    <div style={{ padding: 24 }}>
      {/* ================================================================
          EN-TÊTE
      ================================================================= */}

      <div
        style={{
          display: "flex",
          justifyContent: "space-between",
          alignItems: "center",
          gap: 16,
          marginBottom: 32,
          flexWrap: "wrap",
        }}
      >
        <div>
          <h1
            style={{
              margin: 0,
            }}
          >
            Production
          </h1>

          <p
            style={{
              marginTop: 6,
              marginBottom: 0,
              color: "#64748b",
            }}
          >
            Saisie quotidienne sucrée / salée
          </p>
        </div>

        <div
          style={{
            display: "flex",
            gap: 12,
            alignItems: "center",
          }}
        >
          <input
            type="date"
            value={date}
            onChange={(event) => setDate(event.target.value)}
            style={{
              padding: "9px 12px",
              border: "1px solid #cbd5e1",
              borderRadius: 8,
            }}
          />

          <button
            type="button"
            onClick={saveProduction}
            disabled={saving}
            style={{
              padding: "10px 16px",
              border: 0,
              borderRadius: 8,
              cursor: saving ? "not-allowed" : "pointer",
              background: "#0f172a",
              color: "white",
              fontWeight: 600,
            }}
          >
            {saving ? "Enregistrement..." : "Enregistrer"}
          </button>
        </div>
      </div>

      {/* ================================================================
          MESSAGE D'ERREUR
      ================================================================= */}

      {error && (
        <div
          style={{
            padding: 12,
            marginBottom: 20,
            border: "1px solid #fecaca",
            background: "#fef2f2",
            borderRadius: 8,
          }}
        >
          {error}
        </div>
      )}

      {/* ================================================================
          MESSAGE DE SUCCÈS
      ================================================================= */}

      {savedMessage && (
        <div
          style={{
            padding: 12,
            marginBottom: 20,
            border: "1px solid #bbf7d0",
            background: "#f0fdf4",
            borderRadius: 8,
          }}
        >
          {savedMessage}
        </div>
      )}

      {/* ================================================================
          FAMILLES : SALÉ / SUCRÉ
      ================================================================= */}

      <div
        style={{
          display: "flex",
          flexDirection: "column",
          gap: 48,
        }}
      >
        {families.map(([family, categories]) => {
          /*
           * Nombre total de produits de la famille.
           */
          const familyProductCount = Array.from(categories.values()).reduce(
            (total, categoryProducts) => total + categoryProducts.length,
            0,
          );

          return (
            <section key={family}>
              {/* ========================================================
                  TITRE DE LA FAMILLE
              ========================================================= */}

              <div
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: 10,
                  marginBottom: 18,
                  paddingBottom: 10,
                  borderBottom: "2px solid #cbd5e1",
                }}
              >
                <h2
                  style={{
                    margin: 0,
                    fontSize: 24,
                    color: "#0f172a",
                  }}
                >
                  {family}
                </h2>

                <span
                  style={{
                    color: "#64748b",
                    fontSize: 14,
                  }}
                >
                  {familyProductCount} produits
                </span>
              </div>

              {/* ========================================================
                  CATÉGORIES DE LA FAMILLE
              ========================================================= */}

              <div
                style={{
                  display: "flex",
                  flexDirection: "column",
                  gap: 24,
                }}
              >
                {Array.from(categories.entries()).map(
                  ([category, categoryProducts]) => (
                    <section
                      key={`${family}-${category}`}
                      style={{
                        background: "white",
                        border: "1px solid #e2e8f0",
                        borderRadius: 12,
                        overflow: "hidden",
                      }}
                    >
                      {/* ==================================================
                          TITRE DE LA CATÉGORIE
                      =================================================== */}

                      <div
                        style={{
                          padding: "12px 16px",
                          background: "#f1f5f9",
                          borderBottom: "1px solid #e2e8f0",
                        }}
                      >
                        <strong>{category}</strong>

                        <span
                          style={{
                            marginLeft: 8,
                            color: "#64748b",
                            fontSize: 13,
                          }}
                        >
                          {categoryProducts.length}{" "}
                          {categoryProducts.length > 1 ? "produits" : "produit"}
                        </span>
                      </div>

                      {/* ==================================================
                          TABLEAU
                      =================================================== */}

                      <div
                        style={{
                          overflowX: "auto",
                        }}
                      >
                        <table
                          style={{
                            width: "100%",
                            borderCollapse: "collapse",
                            minWidth: 1100,

                            /*
                             * Important :
                             * garantit exactement les mêmes largeurs
                             * de colonnes pour toutes les catégories.
                             */
                            tableLayout: "fixed",
                          }}
                        >
                          {/* ==============================================
                              LARGEURS FIXES DES COLONNES
                          =============================================== */}

                          <colgroup>
                            <col style={{ width: "22%" }} />
                            <col style={{ width: "13%" }} />
                            <col style={{ width: "11%" }} />
                            <col style={{ width: "11%" }} />
                            <col style={{ width: "11%" }} />
                            <col style={{ width: "10%" }} />
                            <col style={{ width: "11%" }} />
                            <col style={{ width: "11%" }} />
                          </colgroup>

                          {/* ==============================================
                              EN-TÊTES
                          =============================================== */}

                          <thead>
                            <tr>
                              <th style={headerCellStyle}>Produit</th>

                              <th style={headerCellStyle}>Conservation</th>

                              <th style={headerCellStyle}>Stock J-1</th>

                              <th style={headerCellStyle}>Production</th>

                              <th style={headerCellStyle}>Reproduction</th>

                              <th style={headerCellStyle}>Pertes</th>

                              <th style={headerCellStyle}>Ventes</th>

                              <th style={headerCellStyle}>Stock fin</th>
                            </tr>
                          </thead>

                          {/* ==============================================
                              PRODUITS
                          =============================================== */}

                          <tbody>
                            {categoryProducts.map((product) => (
                              <tr key={product.product_id}>
                                {/* Produit */}

                                <td style={bodyCellStyle}>
                                  <strong>{product.name}</strong>
                                </td>

                                {/* Conservation */}

                                <td style={bodyCellStyle}>
                                  {product.conservation ?? "—"}
                                </td>

                                {/* Stock J-1 */}

                                <ProductionInput
                                  value={
                                    product.conservation === "J"
                                      ? null
                                      : product.stock_previous
                                  }
                                  disabled={product.conservation === "J"}
                                  onChange={(value) =>
                                    updateProduct(
                                      product.product_id,
                                      "stock_previous",
                                      value,
                                    )
                                  }
                                />

                                {/* Production */}

                                <ProductionInput
                                  value={product.production}
                                  onChange={(value) =>
                                    updateProduct(
                                      product.product_id,
                                      "production",
                                      value,
                                    )
                                  }
                                />

                                {/* Reproduction */}

                                <ProductionInput
                                  value={product.reproduction}
                                  onChange={(value) =>
                                    updateProduct(
                                      product.product_id,
                                      "reproduction",
                                      value,
                                    )
                                  }
                                />

                                {/* Pertes */}

                                <ProductionInput
                                  value={product.losses}
                                  onChange={(value) =>
                                    updateProduct(
                                      product.product_id,
                                      "losses",
                                      value,
                                    )
                                  }
                                />

                                {/* Ventes */}

                                <ProductionInput
                                  value={product.sales}
                                  onChange={(value) =>
                                    updateProduct(
                                      product.product_id,
                                      "sales",
                                      value,
                                    )
                                  }
                                />

                                {/* Stock fin */}

                                <ProductionInput
                                  value={product.stock_end}
                                  onChange={(value) =>
                                    updateProduct(
                                      product.product_id,
                                      "stock_end",
                                      value,
                                    )
                                  }
                                />
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </section>
                  ),
                )}
              </div>
            </section>
          );
        })}
      </div>
    </div>
  );
}

/*
|--------------------------------------------------------------------------
| Input numérique de production
|--------------------------------------------------------------------------
*/

function ProductionInput({
  value,
  onChange,
  disabled = false,
}: {
  value: NullableNumber;
  onChange: (value: string) => void;
  disabled?: boolean;
}) {
  return (
    <td style={bodyCellStyle}>
      <input
        type="number"
        min="0"
        step="1"
        value={value ?? ""}
        disabled={disabled}
        onChange={(event) => onChange(event.target.value)}
        style={{
          width: 80,
          padding: "7px 8px",
          border: "1px solid #cbd5e1",
          borderRadius: 6,
          textAlign: "center",

          /*
           * Visuel spécifique aux champs impossibles.
           */
          background: disabled ? "#e2e8f0" : "white",
          cursor: disabled ? "not-allowed" : "text",
          color: disabled ? "#94a3b8" : "#0f172a",
        }}
      />
    </td>
  );
}

/*
|--------------------------------------------------------------------------
| Style des en-têtes
|--------------------------------------------------------------------------
*/

const headerCellStyle: React.CSSProperties = {
  textAlign: "left",
  padding: "10px 12px",
  borderBottom: "1px solid #e2e8f0",
  background: "#f8fafc",
  whiteSpace: "nowrap",
  fontSize: 13,
};

/*
|--------------------------------------------------------------------------
| Style des cellules
|--------------------------------------------------------------------------
*/

const bodyCellStyle: React.CSSProperties = {
  padding: "9px 12px",
  borderBottom: "1px solid #f1f5f9",
  whiteSpace: "nowrap",
};
