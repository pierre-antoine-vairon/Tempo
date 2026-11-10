import { useCallback, useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { apiGet, apiPatch, apiPost } from "../../api";

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

type Material = {
  id: number;
  name: string;
  is_active: boolean;
  reference_measure_unit: ReferenceMeasureUnit;
};

type MaterialsResponse = {
  ok: true;
  org_id: number;
  materials: Material[];
};

type RecipeDetailResponse = {
  ok: true;
  recipe: Recipe;
};

type CreateRecipeResponse = {
  ok: true;
  recipe: Recipe;
  message: string;
};

type CorrectRecipeResponse = {
  ok: true;
  message: string;
};

type RecipeFormLine = {
  materialId: number | null;
  quantity: string;
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

function dayAfter(date: string): string {
  const [year, month, day] = date.split("-").map(Number);
  const nextDay = new Date(Date.UTC(year, month - 1, day + 1));

  return [
    nextDay.getUTCFullYear(),
    String(nextDay.getUTCMonth() + 1).padStart(2, "0"),
    String(nextDay.getUTCDate()).padStart(2, "0"),
  ].join("-");
}

function normalizedPositiveQuantity(quantity: string): string | null {
  const normalized = quantity.trim().replace(",", ".");

  if (!/^\d{1,8}(?:\.\d{1,6})?$/.test(normalized)) {
    return null;
  }

  return /^0+(?:\.0+)?$/.test(normalized) ? null : normalized;
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
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [referenceDate, setReferenceDate] = useState("");
  const [showVersionForm, setShowVersionForm] = useState(false);
  const [formLoadingAction, setFormLoadingAction] = useState<
    "version" | "correction" | null
  >(null);
  const [creating, setCreating] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [materials, setMaterials] = useState<Material[]>([]);
  const [effectiveFrom, setEffectiveFrom] = useState("");
  const [formLines, setFormLines] = useState<RecipeFormLine[]>([]);
  const [showCorrectionForm, setShowCorrectionForm] = useState(false);
  const [correcting, setCorrecting] = useState(false);
  const [correctionError, setCorrectionError] = useState<string | null>(null);
  const [correctionReason, setCorrectionReason] = useState("");
  const [correctionMaterials, setCorrectionMaterials] = useState<Material[]>([]);
  const [correctionLines, setCorrectionLines] = useState<RecipeFormLine[]>([]);

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

  async function openVersionForm() {
    const latestVersion = versions[0];

    if (!latestVersion || !validProductId) {
      return;
    }

    try {
      cancelCorrectionForm();
      setFormLoadingAction("version");
      setFormError(null);
      setSuccessMessage(null);

      const [recipeResponse, materialsResponse] = await Promise.all([
        apiGet<RecipeDetailResponse>(
          `/production/products/${productId}/recipes/${latestVersion.id}?org_id=${ORG_ID}`,
        ),
        apiGet<MaterialsResponse>(`/purchasing/materials?org_id=${ORG_ID}`),
      ]);

      setMaterials(materialsResponse.materials);
      setEffectiveFrom(dayAfter(latestVersion.effective_from));
      setFormLines(
        recipeResponse.recipe.lines.map((line) => ({
          materialId: line.material.id,
          quantity: line.quantity_in_reference_unit,
        })),
      );
      setShowVersionForm(true);
    } catch (err) {
      setFormError(
        err instanceof Error
          ? err.message
          : "Impossible de préparer la nouvelle version.",
      );
    } finally {
      setFormLoadingAction(null);
    }
  }

  function cancelVersionForm() {
    setShowVersionForm(false);
    setFormError(null);
    setMaterials([]);
    setEffectiveFrom("");
    setFormLines([]);
  }

  async function openCorrectionForm() {
    const latestVersion = versions[0];

    if (!latestVersion || !validProductId) {
      return;
    }

    try {
      cancelVersionForm();
      setFormLoadingAction("correction");
      setCorrectionError(null);
      setSuccessMessage(null);

      const [recipeResponse, materialsResponse] = await Promise.all([
        apiGet<RecipeDetailResponse>(
          `/production/products/${productId}/recipes/${latestVersion.id}?org_id=${ORG_ID}`,
        ),
        apiGet<MaterialsResponse>(`/purchasing/materials?org_id=${ORG_ID}`),
      ]);

      setCorrectionMaterials(materialsResponse.materials);
      setCorrectionLines(
        recipeResponse.recipe.lines.map((line) => ({
          materialId: line.material.id,
          quantity: line.quantity_in_reference_unit,
        })),
      );
      setShowCorrectionForm(true);
    } catch (err) {
      setCorrectionError(
        err instanceof Error
          ? err.message
          : "Impossible de préparer la correction.",
      );
    } finally {
      setFormLoadingAction(null);
    }
  }

  function cancelCorrectionForm() {
    setShowCorrectionForm(false);
    setCorrectionError(null);
    setCorrectionReason("");
    setCorrectionMaterials([]);
    setCorrectionLines([]);
  }

  function updateFormLine(index: number, changes: Partial<RecipeFormLine>) {
    setFormLines((currentLines) =>
      currentLines.map((line, lineIndex) =>
        lineIndex === index ? { ...line, ...changes } : line,
      ),
    );
  }

  function updateCorrectionLine(index: number, changes: Partial<RecipeFormLine>) {
    setCorrectionLines((currentLines) =>
      currentLines.map((line, lineIndex) =>
        lineIndex === index ? { ...line, ...changes } : line,
      ),
    );
  }

  async function createVersion() {
    const latestVersion = versions[0];

    if (!latestVersion) {
      return;
    }

    if (effectiveFrom === "") {
      setFormError("La date d’effet est obligatoire.");
      return;
    }

    if (effectiveFrom <= latestVersion.effective_from) {
      setFormError("La date d’effet doit être postérieure à la dernière version.");
      return;
    }

    if (formLines.length === 0) {
      setFormError("Ajoutez au moins une matière.");
      return;
    }

    const materialIds = formLines.map((line) => line.materialId);
    if (materialIds.some((materialId) => materialId === null)) {
      setFormError("Chaque ligne doit contenir une matière.");
      return;
    }

    if (new Set(materialIds).size !== materialIds.length) {
      setFormError("Une matière ne peut apparaître qu’une seule fois.");
      return;
    }

    const lines = formLines.map((line) => ({
      material_id: line.materialId as number,
      quantity_in_reference_unit: normalizedPositiveQuantity(line.quantity),
    }));

    if (lines.some((line) => line.quantity_in_reference_unit === null)) {
      setFormError(
        "Chaque quantité doit être positive, avec au plus 8 chiffres entiers et 6 décimales.",
      );
      return;
    }

    try {
      setCreating(true);
      setFormError(null);

      const response = await apiPost<
        CreateRecipeResponse,
        {
          effective_from: string;
          lines: Array<{
            material_id: number;
            quantity_in_reference_unit: string;
          }>;
        }
      >(`/production/products/${productId}/recipes?org_id=${ORG_ID}`, {
        effective_from: effectiveFrom,
        lines: lines as Array<{
          material_id: number;
          quantity_in_reference_unit: string;
        }>,
      });

      cancelVersionForm();
      setSuccessMessage(response.message);
      await loadRecipe();
    } catch (err) {
      setFormError(
        err instanceof Error
          ? err.message
          : "Impossible de créer la nouvelle version.",
      );
    } finally {
      setCreating(false);
    }
  }

  async function correctLatestVersion() {
    const latestVersion = versions[0];
    const trimmedReason = correctionReason.trim();

    if (!latestVersion) {
      return;
    }

    if (trimmedReason === "") {
      setCorrectionError("Le motif de la correction est obligatoire.");
      return;
    }

    if (trimmedReason.length > 500) {
      setCorrectionError("Le motif de la correction ne peut pas dépasser 500 caractères.");
      return;
    }

    if (correctionLines.length === 0) {
      setCorrectionError("Ajoutez au moins une matière.");
      return;
    }

    const materialIds = correctionLines.map((line) => line.materialId);
    if (materialIds.some((materialId) => materialId === null)) {
      setCorrectionError("Chaque ligne doit contenir une matière.");
      return;
    }

    if (new Set(materialIds).size !== materialIds.length) {
      setCorrectionError("Une matière ne peut apparaître qu’une seule fois.");
      return;
    }

    const lines = correctionLines.map((line) => ({
      material_id: line.materialId as number,
      quantity_in_reference_unit: normalizedPositiveQuantity(line.quantity),
    }));

    if (lines.some((line) => line.quantity_in_reference_unit === null)) {
      setCorrectionError(
        "Chaque quantité doit être positive, avec au plus 8 chiffres entiers et 6 décimales.",
      );
      return;
    }

    try {
      setCorrecting(true);
      setCorrectionError(null);

      const response = await apiPatch<
        CorrectRecipeResponse,
        {
          correction_reason: string;
          lines: Array<{
            material_id: number;
            quantity_in_reference_unit: string;
          }>;
        }
      >(
        `/production/products/${productId}/recipes/${latestVersion.id}/correction?org_id=${ORG_ID}`,
        {
          correction_reason: trimmedReason,
          lines: lines as Array<{
            material_id: number;
            quantity_in_reference_unit: string;
          }>,
        },
      );

      cancelCorrectionForm();
      setSuccessMessage(response.message);
      await loadRecipe();
    } catch (err) {
      setCorrectionError(
        err instanceof Error
          ? err.message
          : "Impossible de corriger la fiche technique.",
      );
    } finally {
      setCorrecting(false);
    }
  }

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
      {successMessage && <SuccessMessageBox>{successMessage}</SuccessMessageBox>}

      <section style={sectionStyle}>
        <div style={sectionHeaderStyle}>
          <h3 style={sectionTitleStyle}>Fiche technique active</h3>
          {versions.length > 0 && (
            <div style={headerActionsStyle}>
              <button
                type="button"
                onClick={() => void openVersionForm()}
                disabled={formLoadingAction !== null || creating || correcting}
                style={primaryButtonStyle}
              >
                {formLoadingAction === "version"
                  ? "Préparation..."
                  : "Nouvelle version"}
              </button>
              <button
                type="button"
                onClick={() => void openCorrectionForm()}
                disabled={formLoadingAction !== null || creating || correcting}
                style={secondaryButtonStyle}
              >
                {formLoadingAction === "correction" ? "Préparation..." : "Corriger"}
              </button>
            </div>
          )}
        </div>

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

      {showVersionForm && versions[0] && (
        <section style={sectionStyle}>
          <h3 style={sectionTitleStyle}>Nouvelle version</h3>
          <p style={{ margin: "0 0 16px", color: "#64748b" }}>
            Basée sur la version du {formatDate(versions[0].effective_from)}.
          </p>

          {formError && <MessageBox>{formError}</MessageBox>}

          <label style={labelStyle}>
            Date d’effet
            <input
              type="date"
              value={effectiveFrom}
              min={dayAfter(versions[0].effective_from)}
              onChange={(event) => setEffectiveFrom(event.target.value)}
              disabled={creating}
              style={inputStyle}
            />
          </label>

          <div style={{ overflowX: "auto", marginTop: 18 }}>
            <table style={tableStyle}>
              <thead>
                <tr>
                  <th style={headerCellStyle}>Matière</th>
                  <th style={headerCellStyle}>Quantité</th>
                  <th style={headerCellStyle}>Unité</th>
                  <th style={headerCellStyle} aria-label="Actions" />
                </tr>
              </thead>
              <tbody>
                {formLines.map((line, index) => {
                  const selectedMaterial = materials.find(
                    (material) => material.id === line.materialId,
                  );

                  return (
                    <tr key={index}>
                      <td style={cellStyle}>
                        <select
                          value={line.materialId ?? ""}
                          onChange={(event) =>
                            updateFormLine(index, {
                              materialId:
                                event.target.value === ""
                                  ? null
                                  : Number(event.target.value),
                            })
                          }
                          disabled={creating}
                          style={inputStyle}
                        >
                          <option value="">Choisir une matière</option>
                          {materials
                            .filter(
                              (material) =>
                                material.is_active || material.id === line.materialId,
                            )
                            .map((material) => (
                              <option key={material.id} value={material.id}>
                                {material.name}
                                {material.is_active ? "" : " (inactive)"}
                              </option>
                            ))}
                        </select>
                      </td>
                      <td style={cellStyle}>
                        <input
                          type="text"
                          inputMode="decimal"
                          value={line.quantity}
                          onChange={(event) =>
                            updateFormLine(index, { quantity: event.target.value })
                          }
                          disabled={creating}
                          style={inputStyle}
                        />
                      </td>
                      <td style={cellStyle}>
                        {selectedMaterial?.reference_measure_unit.symbol ?? "—"}
                      </td>
                      <td style={cellStyle}>
                        <button
                          type="button"
                          onClick={() =>
                            setFormLines((currentLines) =>
                              currentLines.filter((_, lineIndex) => lineIndex !== index),
                            )
                          }
                          disabled={creating}
                          style={secondaryButtonStyle}
                        >
                          Retirer
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <button
            type="button"
            onClick={() =>
              setFormLines((currentLines) => [
                ...currentLines,
                { materialId: null, quantity: "" },
              ])
            }
            disabled={creating}
            style={{ ...secondaryButtonStyle, marginTop: 14 }}
          >
            + Ajouter une matière
          </button>

          <div style={formActionsStyle}>
            <button
              type="button"
              onClick={() => void createVersion()}
              disabled={creating}
              style={primaryButtonStyle}
            >
              {creating ? "Création..." : "Créer la version"}
            </button>
            <button
              type="button"
              onClick={cancelVersionForm}
              disabled={creating}
              style={secondaryButtonStyle}
            >
              Annuler
            </button>
          </div>
        </section>
      )}

      {showCorrectionForm && versions[0] && (
        <section style={sectionStyle}>
          <h3 style={sectionTitleStyle}>
            Correction de la version du {formatDate(versions[0].effective_from)}
          </h3>
          <p style={{ margin: "0 0 16px", color: "#64748b" }}>
            Utilisez cette action uniquement pour corriger une erreur de saisie.
            Pour un changement réel de recette, créez une nouvelle version.
          </p>

          {correctionError && <MessageBox>{correctionError}</MessageBox>}

          <p style={{ margin: "0 0 16px", color: "#475569" }}>
            Date d’effet : <strong>{formatDate(versions[0].effective_from)}</strong>
          </p>

          <label style={textareaLabelStyle}>
            Motif de la correction
            <textarea
              value={correctionReason}
              onChange={(event) => setCorrectionReason(event.target.value)}
              maxLength={500}
              placeholder={"Mauvaise matière saisie\nQuantité incorrecte sur la fiche"}
              disabled={correcting}
              style={textareaStyle}
            />
          </label>

          <div style={{ overflowX: "auto", marginTop: 18 }}>
            <table style={tableStyle}>
              <thead>
                <tr>
                  <th style={headerCellStyle}>Matière</th>
                  <th style={headerCellStyle}>Quantité</th>
                  <th style={headerCellStyle}>Unité</th>
                  <th style={headerCellStyle} aria-label="Actions" />
                </tr>
              </thead>
              <tbody>
                {correctionLines.map((line, index) => {
                  const selectedMaterial = correctionMaterials.find(
                    (material) => material.id === line.materialId,
                  );

                  return (
                    <tr key={index}>
                      <td style={cellStyle}>
                        <select
                          value={line.materialId ?? ""}
                          onChange={(event) =>
                            updateCorrectionLine(index, {
                              materialId:
                                event.target.value === ""
                                  ? null
                                  : Number(event.target.value),
                            })
                          }
                          disabled={correcting}
                          style={inputStyle}
                        >
                          <option value="">Choisir une matière</option>
                          {correctionMaterials
                            .filter(
                              (material) =>
                                material.is_active || material.id === line.materialId,
                            )
                            .map((material) => (
                              <option key={material.id} value={material.id}>
                                {material.name}
                                {material.is_active ? "" : " (inactive)"}
                              </option>
                            ))}
                        </select>
                      </td>
                      <td style={cellStyle}>
                        <input
                          type="text"
                          inputMode="decimal"
                          value={line.quantity}
                          onChange={(event) =>
                            updateCorrectionLine(index, { quantity: event.target.value })
                          }
                          disabled={correcting}
                          style={inputStyle}
                        />
                      </td>
                      <td style={cellStyle}>
                        {selectedMaterial?.reference_measure_unit.symbol ?? "—"}
                      </td>
                      <td style={cellStyle}>
                        <button
                          type="button"
                          onClick={() =>
                            setCorrectionLines((currentLines) =>
                              currentLines.filter((_, lineIndex) => lineIndex !== index),
                            )
                          }
                          disabled={correcting}
                          style={secondaryButtonStyle}
                        >
                          Retirer
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <button
            type="button"
            onClick={() =>
              setCorrectionLines((currentLines) => [
                ...currentLines,
                { materialId: null, quantity: "" },
              ])
            }
            disabled={correcting}
            style={{ ...secondaryButtonStyle, marginTop: 14 }}
          >
            + Ajouter une matière
          </button>

          <div style={formActionsStyle}>
            <button
              type="button"
              onClick={() => void correctLatestVersion()}
              disabled={correcting}
              style={primaryButtonStyle}
            >
              {correcting ? "Correction..." : "Corriger la version"}
            </button>
            <button
              type="button"
              onClick={cancelCorrectionForm}
              disabled={correcting}
              style={secondaryButtonStyle}
            >
              Annuler
            </button>
          </div>
        </section>
      )}

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

function SuccessMessageBox({ children }: { children: React.ReactNode }) {
  return (
    <div
      style={{
        marginBottom: 20,
        padding: 14,
        border: "1px solid #bbf7d0",
        background: "#f0fdf4",
        borderRadius: 8,
        color: "#166534",
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

const sectionHeaderStyle: React.CSSProperties = {
  display: "flex",
  justifyContent: "space-between",
  alignItems: "center",
  gap: 12,
  flexWrap: "wrap",
};

const headerActionsStyle: React.CSSProperties = {
  display: "flex",
  gap: 8,
  flexWrap: "wrap",
};

const labelStyle: React.CSSProperties = {
  display: "grid",
  gap: 5,
  color: "#475569",
  fontSize: 13,
  maxWidth: 250,
};

const textareaLabelStyle: React.CSSProperties = {
  ...labelStyle,
  maxWidth: 600,
};

const inputStyle: React.CSSProperties = {
  width: "100%",
  padding: "9px 10px",
  border: "1px solid #cbd5e1",
  borderRadius: 8,
  background: "white",
  boxSizing: "border-box",
};

const textareaStyle: React.CSSProperties = {
  ...inputStyle,
  minHeight: 90,
  resize: "vertical",
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

const secondaryButtonStyle: React.CSSProperties = {
  padding: "9px 14px",
  border: "1px solid #cbd5e1",
  borderRadius: 8,
  background: "white",
  color: "#334155",
  fontWeight: 600,
  cursor: "pointer",
};

const formActionsStyle: React.CSSProperties = {
  display: "flex",
  gap: 10,
  marginTop: 20,
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
