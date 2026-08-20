import { useCallback, useEffect, useMemo, useState } from "react";
import { apiGet, apiPost, apiPut } from "../../api";

type NullableNumber = number | null;

type ProductionStatus = "in_progress" | "finished" | "closed";

type ProductionDay = {
  id: number;
  org_id: number;
  site_id: number;
  production_date: string;
  status: ProductionStatus;

  started_at: string | null;
  started_by: number | null;

  finished_at: string | null;
  finished_by: number | null;

  closed_at: string | null;
  closed_by: number | null;
};

type ProductionProduct = {
  production_day_product_id: number;
  product_id: number;
  category_id: number;
  family_id: number;

  name: string;
  family: string;
  category: string;
  conservation: string | null;

  is_included: boolean;

  entry_id: number | null;

  stock_previous: NullableNumber;
  production: NullableNumber;
  reproduction: NullableNumber;
  losses: NullableNumber;
  sales: NullableNumber;
  stock_end: NullableNumber;
};

type ProductionDayNotStartedResponse = {
  exists: false;
  org_id: number;
  site_id: number;
  date: string;
  status: "not_started";
  products_count: number;
  products: [];
};

type ProductionDayExistingResponse = {
  exists: true;
  day: ProductionDay;
  products_count: number;
  products: ProductionProduct[];
};

type ProductionDayResponse =
  | ProductionDayNotStartedResponse
  | ProductionDayExistingResponse;

type OpenProductionDayResponse = {
  created: boolean;
  day: ProductionDay;
  products_count: number;
};

type ProductionEntryPayload = {
  production_day_product_id: number;
  production: NullableNumber;
  reproduction: NullableNumber;
  losses: NullableNumber;
  sales: NullableNumber;
  stock_end: NullableNumber;
};

type SaveProductionResponse = {
  ok: boolean;
  day: {
    id: number;
    date: string;
    status: ProductionStatus;
  };
  saved_entries: number;
};

type FinishWarning = {
  code: string;
  production_day_product_id: number;
  product_name: string;
  message: string;
};

type MissingProduct = {
  production_day_product_id: number;
  product_name: string;
  fields: string[];
};

type FinishNeedsConfirmationResponse = {
  ok: false;
  requires_confirmation: true;
  message: string;
  missing_values_count: number;
  missing_products: MissingProduct[];
  warnings: FinishWarning[];
};

type FinishSuccessResponse = {
  ok: true;
  requires_confirmation?: false;
  already_finished?: boolean;

  day: {
    id: number;
    date: string;
    status: "finished";
  };

  zero_filled_values_count?: number;
  warnings?: FinishWarning[];
};

type FinishProductionResponse =
  | FinishNeedsConfirmationResponse
  | FinishSuccessResponse;

type EditableField =
  | "production"
  | "reproduction"
  | "losses"
  | "sales"
  | "stock_end";

function getTodayLocal(): string {
  const now = new Date();

  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  const day = String(now.getDate()).padStart(2, "0");

  return `${year}-${month}-${day}`;
}

export default function ProductionDayPage() {
  const [date, setDate] = useState(getTodayLocal());

  const [day, setDay] = useState<ProductionDay | null>(null);
  const [products, setProducts] = useState<ProductionProduct[]>([]);
  const [dirtyProductIds, setDirtyProductIds] = useState<Set<number>>(
    () => new Set(),
  );

  const [loading, setLoading] = useState(true);
  const [opening, setOpening] = useState(false);
  const [saving, setSaving] = useState(false);

  const [finishing, setFinishing] = useState(false);

  const [finishConfirmation, setFinishConfirmation] =
    useState<FinishNeedsConfirmationResponse | null>(null);

  const [error, setError] = useState<string | null>(null);
  const [savedMessage, setSavedMessage] = useState<string | null>(null);

  /*
  |--------------------------------------------------------------------------
  | Site MVP
  |--------------------------------------------------------------------------
  */

  const siteId = 1;

  /*
  |--------------------------------------------------------------------------
  | Lecture de la journée
  |--------------------------------------------------------------------------
  */

  const loadProduction = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);

      const data = await apiGet<ProductionDayResponse>(
        `/production/day?site_id=${siteId}&date=${date}`,
      );

      if (!data.exists) {
        setDay(null);
        setProducts([]);
        return;
      }

      setDay(data.day);

      /*
       * Les produits exclus restent dans l'historique côté backend,
       * mais ne sont pas affichés dans la feuille active.
       */
      setProducts(data.products.filter((product) => product.is_included));
      setDirtyProductIds(new Set());
    } catch (err) {
      setDay(null);
      setProducts([]);

      setError(
        err instanceof Error
          ? err.message
          : "Impossible de charger la production.",
      );
    } finally {
      setLoading(false);
    }
  }, [date, siteId]);

  useEffect(() => {
    setSavedMessage(null);
    void loadProduction();
  }, [loadProduction]);

  /*
  |--------------------------------------------------------------------------
  | Ouverture d'une nouvelle journée
  |--------------------------------------------------------------------------
  */

  async function openProductionDay() {
    try {
      setOpening(true);
      setError(null);
      setSavedMessage(null);

      await apiPost<
        OpenProductionDayResponse,
        {
          site_id: number;
          date: string;
        }
      >("/production/day/open", {
        site_id: siteId,
        date,
      });

      await loadProduction();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de démarrer la production.",
      );
    } finally {
      setOpening(false);
    }
  }

  /*
  |--------------------------------------------------------------------------
  | Modification locale
  |--------------------------------------------------------------------------
  */

  function updateProduct(
    productionDayProductId: number,
    field: EditableField,
    value: string,
  ) {
    setSavedMessage(null);

    setProducts((currentProducts) =>
      currentProducts.map((product) => {
        if (product.production_day_product_id !== productionDayProductId) {
          return product;
        }

        return {
          ...product,
          [field]: value === "" ? null : Number(value),
        };
      }),
    );

    /*
     * On mémorise que cette ligne a été modifiée.
     *
     * Set empêche naturellement les doublons :
     * si on modifie 5 fois Oriental,
     * son identifiant n'apparaît qu'une seule fois.
     */
    setDirtyProductIds((currentIds) => {
      const nextIds = new Set(currentIds);

      nextIds.add(productionDayProductId);

      return nextIds;
    });
  }

  /*
  |--------------------------------------------------------------------------
  | Enregistrement
  |--------------------------------------------------------------------------
  */

  async function saveProduction() {
    if (!day || day.status !== "in_progress") {
      return;
    }

    /*
     * Rien n'a changé :
     * aucune requête inutile vers le backend.
     */
    if (dirtyProductIds.size === 0) {
      setSavedMessage("Aucune modification à enregistrer.");
      return;
    }

    try {
      setSaving(true);
      setError(null);
      setSavedMessage(null);

      /*
       * On n'envoie maintenant QUE les produits réellement modifiés.
       */
      const modifiedProducts = products.filter((product) =>
        dirtyProductIds.has(product.production_day_product_id),
      );

      const entries: ProductionEntryPayload[] = modifiedProducts.map(
        (product) => ({
          production_day_product_id: product.production_day_product_id,

          production: product.production,
          reproduction: product.reproduction,
          losses: product.losses,
          sales: product.sales,
          stock_end: product.stock_end,
        }),
      );

      const response = await apiPut<
        SaveProductionResponse,
        {
          site_id: number;
          date: string;
          entries: ProductionEntryPayload[];
        }
      >("/production/day", {
        site_id: siteId,
        date,
        entries,
      });

      setSavedMessage(
        `Production enregistrée (${response.saved_entries} ${
          response.saved_entries > 1
            ? "lignes enregistrées"
            : "ligne enregistrée"
        }).`,
      );

      /*
       * On recharge les valeurs venant réellement de la DB.
       *
       * Pour l'instant on conserve cette sécurité.
       * On pourra mesurer ensuite si ce GET ajoute une latence importante.
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

  async function finishProduction(confirmZeroFill = false) {
    if (!day || day.status !== "in_progress") {
      return;
    }

    /*
     * Pour le moment, on oblige l'utilisateur à enregistrer
     * ses modifications avant de terminer.
     *
     * Cela évite qu'une valeur visible dans le navigateur
     * ne soit pas encore présente en base au moment du contrôle.
     */
    if (dirtyProductIds.size > 0) {
      setError(
        "Des modifications ne sont pas encore enregistrées. Enregistrez-les avant de terminer la production.",
      );

      return;
    }

    try {
      setFinishing(true);
      setError(null);
      setSavedMessage(null);

      const response = await apiPost<
        FinishProductionResponse,
        {
          site_id: number;
          date: string;
          confirm_zero_fill: boolean;
        }
      >("/production/day/finish", {
        site_id: siteId,
        date,
        confirm_zero_fill: confirmZeroFill,
      });

      /*
       * Des valeurs NULL restent présentes.
       *
       * Le backend ne modifie encore rien.
       * On demande explicitement confirmation à l'utilisateur.
       */
      if (!response.ok && response.requires_confirmation) {
        setFinishConfirmation(response);
        return;
      }

      /*
       * La journée est maintenant terminée.
       */
      setFinishConfirmation(null);

      const zeroFilled = response.zero_filled_values_count ?? 0;

      setSavedMessage(
        zeroFilled > 0
          ? `Production terminée. ${zeroFilled} valeur(s) manquante(s) ont été mises à 0.`
          : "Production terminée.",
      );

      await loadProduction();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Impossible de terminer la production.",
      );
    } finally {
      setFinishing(false);
    }
  }

  /*
  |--------------------------------------------------------------------------
  | Regroupement Famille -> Catégorie -> Produits
  |--------------------------------------------------------------------------
  */

  const families = useMemo(() => {
    const grouped = new Map<string, Map<string, ProductionProduct[]>>();

    for (const product of products) {
      if (!grouped.has(product.family)) {
        grouped.set(product.family, new Map());
      }

      const familyCategories = grouped.get(product.family)!;

      if (!familyCategories.has(product.category)) {
        familyCategories.set(product.category, []);
      }

      familyCategories.get(product.category)!.push(product);
    }

    const familyOrder = ["Salé", "Sucré"];

    return Array.from(grouped.entries()).sort(([familyA], [familyB]) => {
      const indexA = familyOrder.indexOf(familyA);
      const indexB = familyOrder.indexOf(familyB);

      const orderA = indexA === -1 ? familyOrder.length : indexA;

      const orderB = indexB === -1 ? familyOrder.length : indexB;

      return orderA - orderB;
    });
  }, [products]);

  const isEditable = day?.status === "in_progress";

  if (loading) {
    return (
      <div style={{ padding: 24 }}>
        <h2>Production du jour</h2>
        <p>Chargement...</p>
      </div>
    );
  }

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
          <h2 style={{ margin: 0 }}>Production du jour</h2>

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

          {day && (
            <span
              style={{
                padding: "7px 10px",
                borderRadius: 8,
                background: "#f1f5f9",
                color: "#475569",
                fontSize: 13,
                fontWeight: 600,
              }}
            >
              {getStatusLabel(day.status)}
            </span>
          )}

          {isEditable && (
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
          )}

          {isEditable && (
            <button
              type="button"
              onClick={() => void finishProduction(false)}
              disabled={finishing || saving}
              style={{
                padding: "10px 16px",
                border: "1px solid #0f172a",
                borderRadius: 8,
                cursor: finishing || saving ? "not-allowed" : "pointer",
                background: "white",
                color: "#0f172a",
                fontWeight: 600,
              }}
            >
              {finishing ? "Finalisation..." : "Terminer"}
            </button>
          )}
        </div>
      </div>

      {/* ================================================================
          ERREUR
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
          SUCCÈS
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

      {finishConfirmation && (
        <div
          style={{
            padding: 18,
            marginBottom: 20,
            border: "1px solid #fbbf24",
            background: "#fffbeb",
            borderRadius: 10,
          }}
        >
          <strong>Production incomplète</strong>

          <p
            style={{
              marginTop: 8,
              marginBottom: 8,
            }}
          >
            {finishConfirmation.missing_values_count} valeur(s) ne sont pas
            renseignée(s).
          </p>

          {finishConfirmation.warnings.length > 0 && (
            <div
              style={{
                marginBottom: 16,
                color: "#92400e",
              }}
            >
              {finishConfirmation.warnings.map((warning) => (
                <div
                  key={`${warning.code}-${warning.production_day_product_id}`}
                >
                  <strong>{warning.product_name}</strong>
                  {" : "}
                  {warning.message}
                </div>
              ))}
            </div>
          )}

          <div
            style={{
              display: "flex",
              gap: 10,
              flexWrap: "wrap",
            }}
          >
            <button
              type="button"
              onClick={() => setFinishConfirmation(null)}
              disabled={finishing}
              style={{
                padding: "9px 14px",
                border: "1px solid #cbd5e1",
                borderRadius: 8,
                background: "white",
                cursor: "pointer",
              }}
            >
              Revenir vérifier
            </button>

            <button
              type="button"
              onClick={() => void finishProduction(true)}
              disabled={finishing}
              style={{
                padding: "9px 14px",
                border: 0,
                borderRadius: 8,
                background: "#0f172a",
                color: "white",
                fontWeight: 600,
                cursor: finishing ? "not-allowed" : "pointer",
              }}
            >
              {finishing
                ? "Finalisation..."
                : "Mettre les valeurs restantes à 0 et terminer"}
            </button>
          </div>
        </div>
      )}

      {/* ================================================================
          JOURNÉE NON DÉMARRÉE
      ================================================================= */}

      {!day && (
        <div
          style={{
            padding: 32,
            border: "1px solid #e2e8f0",
            borderRadius: 12,
            background: "white",
            textAlign: "center",
          }}
        >
          <h3
            style={{
              marginTop: 0,
              marginBottom: 8,
            }}
          >
            Production non démarrée
          </h3>

          <p
            style={{
              marginTop: 0,
              marginBottom: 20,
              color: "#64748b",
            }}
          >
            Aucune feuille de production n'existe encore pour cette date.
          </p>

          <button
            type="button"
            onClick={openProductionDay}
            disabled={opening}
            style={{
              padding: "10px 16px",
              border: 0,
              borderRadius: 8,
              cursor: opening ? "not-allowed" : "pointer",
              background: "#0f172a",
              color: "white",
              fontWeight: 600,
            }}
          >
            {opening ? "Démarrage..." : "Démarrer la production"}
          </button>
        </div>
      )}

      {/* ================================================================
          FEUILLE
      ================================================================= */}

      {day && (
        <div
          style={{
            display: "flex",
            flexDirection: "column",
            gap: 48,
          }}
        >
          {families.map(([family, categories]) => {
            const familyProductCount = Array.from(categories.values()).reduce(
              (total, categoryProducts) => total + categoryProducts.length,
              0,
            );

            return (
              <section key={family}>
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
                            {categoryProducts.length > 1
                              ? "produits"
                              : "produit"}
                          </span>
                        </div>

                        <div style={{ overflowX: "auto" }}>
                          <table
                            style={{
                              width: "100%",
                              borderCollapse: "collapse",
                              minWidth: 1100,
                              tableLayout: "fixed",
                            }}
                          >
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

                            <tbody>
                              {categoryProducts.map((product) => (
                                <tr key={product.production_day_product_id}>
                                  <td style={bodyCellStyle}>
                                    <strong>{product.name}</strong>
                                  </td>

                                  <td style={bodyCellStyle}>
                                    {product.conservation ?? "—"}
                                  </td>

                                  {/* Stock J-1 :
                                        toujours calculé par le backend */}

                                  <ProductionInput
                                    value={
                                      product.conservation === "J"
                                        ? null
                                        : product.stock_previous
                                    }
                                    disabled
                                    onChange={() => {}}
                                  />

                                  <ProductionInput
                                    value={product.production}
                                    disabled={!isEditable}
                                    onChange={(value) =>
                                      updateProduct(
                                        product.production_day_product_id,
                                        "production",
                                        value,
                                      )
                                    }
                                  />

                                  <ProductionInput
                                    value={product.reproduction}
                                    disabled={!isEditable}
                                    onChange={(value) =>
                                      updateProduct(
                                        product.production_day_product_id,
                                        "reproduction",
                                        value,
                                      )
                                    }
                                  />

                                  <ProductionInput
                                    value={product.losses}
                                    disabled={!isEditable}
                                    onChange={(value) =>
                                      updateProduct(
                                        product.production_day_product_id,
                                        "losses",
                                        value,
                                      )
                                    }
                                  />

                                  <ProductionInput
                                    value={product.sales}
                                    disabled={!isEditable}
                                    onChange={(value) =>
                                      updateProduct(
                                        product.production_day_product_id,
                                        "sales",
                                        value,
                                      )
                                    }
                                  />

                                  <ProductionInput
                                    value={product.stock_end}
                                    disabled={!isEditable}
                                    onChange={(value) =>
                                      updateProduct(
                                        product.production_day_product_id,
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
      )}
    </div>
  );
}

function getStatusLabel(status: ProductionStatus): string {
  switch (status) {
    case "in_progress":
      return "En cours";

    case "finished":
      return "Terminée";

    case "closed":
      return "Clôturée";
  }
}

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
          background: disabled ? "#e2e8f0" : "white",
          cursor: disabled ? "not-allowed" : "text",
          color: disabled ? "#94a3b8" : "#0f172a",
        }}
      />
    </td>
  );
}

const headerCellStyle: React.CSSProperties = {
  textAlign: "left",
  padding: "10px 12px",
  borderBottom: "1px solid #e2e8f0",
  background: "#f8fafc",
  whiteSpace: "nowrap",
  fontSize: 13,
};

const bodyCellStyle: React.CSSProperties = {
  padding: "9px 12px",
  borderBottom: "1px solid #f1f5f9",
  whiteSpace: "nowrap",
};
